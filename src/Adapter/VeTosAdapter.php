<?php

/**
 * @author     wangtao <wangtao861024@gmail.com>
 * @license    MIT License
 */

declare(strict_types=1);

namespace Sessel\VeTosThinkphp\Adapter;

use DateTimeInterface;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\Exception;
use League\Flysystem\FileAttributes;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\Visibility;
use Tos\TosClient;
use Tos\Exception\TosClientException;
use Tos\Exception\TosServerException;
use Tos\Model\CopyObjectInput;
use Tos\Model\DeleteMultiObjectsInput;
use Tos\Model\DeleteObjectInput;
use Tos\Model\Enum;
use Tos\Model\GetObjectInput;
use Tos\Model\PutObjectACLInput;
use Tos\Model\PutObjectInput;
use Tos\Model\ListObjectsInput;
use Tos\Model\HeadObjectInput;
use Tos\Model\Object\ListObjectsV2Output;
use Tos\Model\PreSignedURLInput;
use Sessel\VeTosThinkphp\VeTosFilesystemException;
use Tos\Model\AppendObjectInput;
use Tos\Model\CompleteMultipartUploadInput;
use Tos\Model\CreateMultipartUploadInput;
use Tos\Model\GetObjectACLInput;
use Tos\Model\UploadedPart;
use Tos\Model\UploadPartInput;

class VeTosAdapter implements FilesystemAdapter, PublicUrlGenerator
{
    /**
     * @var TosClient
     */
    protected $client;

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @var string
     */
    protected $prefix;

    /**
     * @var string|null
     */
    protected $domain;

    /**
     * 追加写入的最小长度128KB
     * @var int
     */
    const MIN_APPEND_SIZE = 131072;

    /**
     * 分片上传的大小5M(5 * 1024 * 1024)
     * @var int
     */
    const DEFAULT_PART_SIZE = 5242880;

    /**
     * 配置参数
     * @var array
     */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $this->mergeDefaultConfig($config);
        $this->bucket = $this->config['bucket'];
        $this->prefix = $this->config['prefix'] ?? '';
        $this->domain = $this->config['domain'] ?? '';

        // 初始化TOS客户端
        $this->client = new TosClient([
            'ak' => $this->config['ak'],
            'sk' => $this->config['sk'],
            'region' => $this->config['region'],
            'connectionTimeout' => $this->config['connection_timeout'] ?? 3000,
            'socketTimeout' => $this->config['socket_timeout'] ?? 3000,
            'maxRetries' => $this->config['max_retries'] ?? 3,
            'endpoint' => $this->config['endpoint'] ?? '',
        ]);
    }

    /**
     * 处理TOS异常
     */
    protected function handleException(\Exception $e, string $path = ''): void
    {
        if(!empty($this->config['error_handler']) && is_callable($this->config['error_handler'])){
            call_user_func($this->config['error_handler'], $e, $path);
        }
    }

    /**
     * 合并默认配置
     */
    protected function mergeDefaultConfig(array $config): array
    {
        return array_merge([
            'ak' => '',
            'sk' => '',
            'region' => 'cn-beijing',
            'bucket' => '',
            'prefix' => '',
            'domain' => '',
            'endpoint' => '',
        ], $config);
    }

    /**
     * 处理路径前缀
     */
    protected function applyPathPrefix(string $path): string
    {
        if($this->prefix){
            $this->prefix = rtrim($this->prefix, '/') . '/';
        }
        return $this->prefix . ltrim($path, '/');
    }

    /**
     * 移除路径前缀
     */
    protected function removePathPrefix(string $path): string
    {
        return ltrim(substr($path, strlen($this->prefix)), '/');
    }

    /**
     * 文件是否存在
     * @param string path
     * @return bool
     * @throws UnableToCheckExistence
     */
    public function fileExists(string $path): bool
    {
        try {
            $input = new HeadObjectInput($this->bucket, $this->applyPathPrefix($path));
            $this->client->headObject($input);
            return true;
        } catch (TosServerException $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }
            $this->handleException($e, $path);
            throw UnableToCheckExistence::forLocation($path, $e);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    /**
     * 目录是否存在
     * @param string path
     * @return bool
     * @throws UnableToCheckExistence
     */
    public function directoryExists(string $path): bool
    {
        $prefix = rtrim($this->applyPathPrefix($path), '/') . '/';

        try {
            $listInput = new ListObjectsInput($this->bucket);
            $listInput->setPrefix($prefix);
            $listInput->setMaxKeys(1);

            $output = $this->client->listObjects($listInput);
            return $output->getContents() !== [] || $output->getCommonPrefixes() !== [];
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    /**
     * 上传文本
     * @param string path
     * @param string content
     * @param Config config
     * @return void
     * @throws UnableToWriteFile
     */
    public function write(string $path, string $content, Config $config): void
    {
        try {
            $key = $this->applyPathPrefix($path);
            $input = new PutObjectInput($this->bucket, $key, $content);
            // 设置对象 ACL
            $input->setACL($config->get('acl', Enum::ACLPublicRead));
            // 设置对象 StorageClass
            $input->setStorageClass($config->get('storage_class', Enum::StorageClassStandard));
            if(($meta = $config->get('meta')) && is_array($meta)){
                // 设置对象自定义元数据
                $input->setMeta($meta);
            }
            // 设置对象 Content-Type
            $input->setContentType($config->get('content_type', 'text/plain'));
            $this->client->putObject($input);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToWriteFile($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 普通上传
     * 追加后的对象大小不能大于 5GiB。
     * 对于通过追加上传创建的对象，进行普通上传操作，对象被覆盖且对象类型会发生变化。
     * 通过普通上传创建的对象不支持追加上传。
     * 通过追加上传创建的对象不支持拷贝。
     * 如果您的桶处于开启或者暂停多版本功能的状态下，或存储桶的类型为低频存储，则无法通过追加上传创建对象。
     * @param string path
     * @param string content
     * @param Config config
     * @return int
     * @throws UnableToCheckExistence
     */
    public function appendWrite(string $path, string $content, Config $config): int
    {
        try {
            //每次追加上传的数据大小不能小于 128 KB
            if(strlen($content) < SELF::MIN_APPEND_SIZE){
                throw new UnableToWriteFile('append too short');
            }
            $key = $this->applyPathPrefix($path);
            if(!$this->fileExists($path)){
                $nextAppendOffset = 0;
            }else{
                $input = new HeadObjectInput($this->bucket, $key);
                $output = $this->client->headObject($input);
                $nextAppendOffset = $output->getContentLength();
            }
            $input = new AppendObjectInput($this->bucket, $key);
            $nextAppendOffset = $config->get('next_append_offset', 0);
            $input->setOffset($nextAppendOffset);
            // 设置对象 ACL
            $input->setACL($config->get('acl', Enum::ACLPublicRead));
            // 设置对象 StorageClass
            $input->setStorageClass($config->get('storage_class', Enum::StorageClassStandard));
            if(($meta = $config->get('meta')) && is_array($meta)){
                // 设置对象自定义元数据
                $input->setMeta($meta);
            }
            // 设置对象 Content-Type
            $input->setContentType($config->get('content_type', 'text/plain'));
            //设置内容
            $input->setContent($content);
            $output = $this->client->appendObject($input);
            // 下一次追加上传的起始位置
            $nextAppendOffset = $output->getNextAppendOffset();
            return $nextAppendOffset;
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToWriteFile($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 分片上传
     * @param string path
     * @param string localBigFilePath
     * @param Config config
     * @return void
     */
    public function multipartWrite(string $path, string $localBigFilePath, Config $config)
    {
        if(!file_exists($localBigFilePath)){
            throw new UnableToWriteFile('local big file not found');
        }

        $key = $this->applyPathPrefix($path);
        // 步骤一：创建分片上传任务
        $input = new CreateMultipartUploadInput($this->bucket, $key);
        // 设置对象 ACL
        $input->setACL($config->get('acl', Enum::ACLPublicRead));
        // 设置对象 StorageClass
        $input->setStorageClass($config->get('storage_class', Enum::StorageClassStandard));
        if(($meta = $config->get('meta')) && is_array($meta)){
            // 设置对象自定义元数据
            $input->setMeta($meta);
        }
        // 设置对象 Content-Type
        $input->setContentType($config->get('content_type', 'text/plain'));
        $output = $this->client->createMultipartUpload($input);

        // 获取 UploadID
        $uploadId = $output->getUploadID();

        // 步骤二：上传多个分片
        // 假设按照 20MB 切分大文件
        $partSize = $config->get('part_size', SELF::DEFAULT_PART_SIZE);
        $fileSize = filesize($localBigFilePath);

        $partCount = intval($fileSize / $partSize);
        if (($lastPartSize = $fileSize % $partSize) !== 0) {
            $partCount++;
        } else {
            $lastPartSize = $partSize;
        }

        $parts = [];
        try{
            for ($i = 0; $i < $partCount; $i++) {
                $partNumber = $i + 1;
                $file = fopen($localBigFilePath, 'r');
                // 设置当前上传的文件起始位置
                fseek($file, $partSize * $i, 0);
                $input = new UploadPartInput($this->bucket, $key, $uploadId, $partNumber);
                if ($i === $partCount - 1) {
                    // 处理最后一个分片
                    $input->setContentLength($lastPartSize);
                } else {
                    $input->setContentLength($partSize);
                }
                $input->setContent($file);
                $output = $this->client->uploadPart($input);
                if (is_resource($file)) {
                    fclose($file);
                }
                // 收集所有分片
                $parts[] = new UploadedPart($partNumber, $output->getETag());
            }
            // 步骤三：合并分片
            $input = new CompleteMultipartUploadInput($this->bucket, $key, $uploadId, $parts);
            $output = $this->client->completeMultipartUpload($input);
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDirectory(string $path): void
    {
        $prefix = rtrim($this->applyPathPrefix($path), '/') . '/';
        try {
            $objects = [];
            $nextMarker = '';

            do {
                $listInput = new ListObjectsInput($this->bucket);
                $listInput->setPrefix($prefix);
                $listInput->setMarker($nextMarker);

                $output = $this->client->listObjects($listInput);

                foreach ($output->getContents() as $object) {
                    $objects[] = ['Key' => $object->getKey()];
                }

                $nextMarker = $output->getNextMarker();
            } while (!empty($nextMarker));

            if (!empty($objects)) {
                $input = new DeleteMultiObjectsInput($this->bucket, $objects);
                $output = $this->client->deleteMultiObjects($input);
            }
        } catch (TosClientException|TosServerException $e) {
            $this->handleException($e, $path);
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createDirectory(string $path, ?Config $config = null): void
    {
        $config = $config ?? new Config();
        $key = rtrim($this->applyPathPrefix($path), '/') . '/';

        try {
            $input = new PutObjectInput($this->bucket, $key);
            $this->client->putObject($input);
        } catch (\Exception $e) {
            throw UnableToCreateDirectory::atLocation($key, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function visibility(string $path, string $versionId = ''): FileAttributes
    {
        $key = $this->applyPathPrefix($path);
        $input = new GetObjectACLInput($this->bucket, $key, $versionId);
        try {

            $output = $this->client->getObjectAcl($input);
            $visibility = Visibility::PRIVATE;
            foreach ($output->getGrants() as $grant) {
                if ($grant->getGrantee()->getCanned() === 'AllUsers') {
                    $visibility = Visibility::PUBLIC;
                    break;
                }
            }
            return new FileAttributes($path, null, $visibility);
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::visibility($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path, string $versionId = ''): FileAttributes
    {
        $key = $this->applyPathPrefix($path);
        $input = new HeadObjectInput($this->bucket, $key, $versionId);
        try {
            $output = $this->client->headObject($input);
            return new FileAttributes($path, null, null, null, $output->getContentType());
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path, string $versionId = ''): FileAttributes
    {
        $key = $this->applyPathPrefix($path);
        $input = new HeadObjectInput($this->bucket, $key, $versionId);
        try {
            $output = $this->client->headObject($input);
            return new FileAttributes($path, null, null, $output->getLastModified());
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function fileSize(string $path, string $versionId = ''): FileAttributes
    {
        $key = $this->applyPathPrefix($path);
        $input = new HeadObjectInput($this->bucket, $key, $versionId);
        try {
            $output = $this->client->headObject($input);
            return new FileAttributes($path, $output->getContentLength());
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $source, string $destination, ?Config $config = null, string $destinationBucket = ''): void
    {
        try {
            $key = $this->applyPathPrefix($destination);
            $srcKey = $this->applyPathPrefix($source);
            $bucket = $destinationBucket ?: $this->bucket;
            $input = new CopyObjectInput($bucket, $key, $this->bucket, $srcKey);
            // 复制到新路径
            $this->client->copyObject($input);
            // 删除原路径
            $this->delete($srcKey);
        } catch (\Exception $e) {
            $this->handleException($e, $source);
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * 文件流写入
     * @param string path
     * @param resource resource
     * @param Config config
     * @return void
     * @throws UnableToWriteFile
     */
    public function writeStream(string $path, $resource, Config $config): void
    {
        $key = $this->applyPathPrefix($path);
        try {
            $input = new PutObjectInput($this->bucket, $key);
            if($config && $meta = $config->get('meta')){
                $input->setMeta($meta);
            }
            $input->setContent($resource);
            $this->client->putObject($input);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToWriteFile($e->getMessage(), $e->getCode(), $e);
        }finally{
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, ?Config $config = null)
    {
        // TOS的write操作会覆盖现有文件，与update语义一致
        return $this->write($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, ?Config $config = null)
    {
        // TOS的writeStream操作会覆盖现有文件，与updateStream语义一致
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        try {
            // 复制到新路径
            $this->client->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($newpath),
                'CopySource' => "{$this->bucket}/{$this->applyPathPrefix($path)}",
            ]);

            // 删除原路径
            $this->delete($path);
            return true;
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $source, string $destination, Config $config, ?string $destinationBucket = null): void
    {
        try {
            $key = $this->applyPathPrefix($destination);
            $srcKey = $this->applyPathPrefix($source);
            $bucket = $destinationBucket ?: $this->bucket;
            $input = new CopyObjectInput($bucket, $key, $this->bucket, $srcKey);
            $this->client->copyObject($input);
        } catch (\Exception $e) {
            throw new UnableToCopyFile($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $path, string $verionId = ''): void
    {
        $key = $this->applyPathPrefix($path);
        try {
            $input = new DeleteObjectInput($this->bucket, $key, $verionId);
            $this->client->deleteObject($input);
        } catch (\Exception $e) {
            throw new UnableToDeleteFile($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        $prefix = rtrim($this->applyPathPrefix($dirname), '/') . '/';
        try {
            // 列出所有对象
            $objects = [];
            $continuationToken = '';

            do {
                $params = [
                    'Bucket' => $this->bucket,
                    'Prefix' => $prefix,
                    'ContinuationToken' => $continuationToken,
                ];

                /** @var ListObjectsV2Output $result */
                $result = $this->client->listObjectsV2($params);

                foreach ($result->getContents() as $object) {
                    $objects[] = ['Key' => $object->getKey()];
                }

                $continuationToken = $result->getContinuationToken();
            } while ($result->isTruncated());

            // 批量删除
            if (!empty($objects)) {
                $this->client->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => ['Objects' => $objects],
                ]);
            }

            return true;
        } catch (\Exception $e) {
            $this->handleException($e, $dirname);
            return false;
        }
    }

    /**
     * 读取文件(普通下载)
     * @param string path
     * @param string range
     * @param string versionId
     * @return \Tos\Helper\StreamReader
     * @throws UnableToReadFile
     */
    public function read(string $path, string $range = '', string $versionId = ''): string
    {
        $key = $this->applyPathPrefix($path);
        try {
            $input = new GetObjectInput($this->bucket, $key, $range, $versionId);
            $output = $this->client->getObject($input);
            return $output->getContent()->getContents();
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToReadFile($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * 读取文件流
     * @param string path
     * @param string range
     * @param string versionId
     * @return \Tos\Helper\StreamReader
     * @throws UnableToReadFile
     */
    public function readStream(string $path, string $range = '', string $versionId = '')
    {
        $key = $this->applyPathPrefix($path);
        $input = new GetObjectInput($this->bucket, $key, $range, $versionId);
        try {
            $output = $this->client->getObject($input);
            return $output->getContent();
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            throw new UnableToReadFile($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function listContents(string $path = '', bool $deep = false): iterable
    {
        $result = [];
        $nextMarker = '';
        $basePrefix = $this->applyPathPrefix($path);

        if (!$deep && $basePrefix !== '' && !str_ends_with($basePrefix, '/')) {
            $basePrefix .= '/';
        }

        try {
            do {
                $listInput = new ListObjectsInput($this->bucket);
                $listInput->setMaxKeys(1000);
                $listInput->setPrefix($basePrefix);
                $listInput->setMarker($nextMarker);
                if (!$deep) {
                    $listInput->setDelimiter('/');
                }

                $output = $this->client->listObjects($listInput);

                // 处理目录
                foreach ($output->getCommonPrefixes() as $commonPrefix) {
                    $fullPrefixPath = $commonPrefix->getPrefix();
                    $relativePath = $this->removePathPrefix($fullPrefixPath);
                    $dirPath = rtrim($relativePath, '/');

                    $result[] = new DirectoryAttributes($dirPath);
                }

                // 处理文件
                foreach ($output->getContents() as $content) {
                    $fullObjectKey = $content->getKey();
                    // 跳过模拟目录的空对象
                    if ($content->getSize() == 0 && str_ends_with($fullObjectKey, '/')) {
                        continue;
                    }

                    $relativePath = $this->removePathPrefix($fullObjectKey);

                    $result[] = new FileAttributes(
                        $relativePath,
                        $content->getSize(),
                        null,
                        $content->getLastModified(),
                        null,
                        [$content->getETag()]
                    );
                }

                $nextMarker = $output->getNextMarker();
            } while (!empty($nextMarker));

            return $result;
        } catch (\Exception $e) {
            throw new VeTosFilesystemException("Failed to list contents: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        try {
            $response = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]);

            return [
                'path' => $path,
                'type' => 'file',
                'size' => $response->getContentLength(),
                'timestamp' => $response->getLastModified(),
                'etag' => $response->getETag(),
            ];
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        $metadata = $this->getMetadata($path);
        return $metadata ? ['path' => $path, 'size' => $metadata['size']] : false;
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        try {
            $response = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]);

            return [
                'path' => $path,
                'mimetype' => $response->getContentType(),
            ];
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        $metadata = $this->getMetadata($path);
        return $metadata ? ['path' => $path, 'timestamp' => $metadata['timestamp']] : false;
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility(string $path, string $visibility, string $versionId = ''): void
    {
        $acl = $visibility === 'public' ? Enum::ACLPublicRead : Enum::ACLPrivate;
        $key = $this->applyPathPrefix($path);
        try {
            $input = new PutObjectACLInput($this->bucket, $key, $acl, $versionId);
            $this->client->putObjectAcl($input);
        } catch (\Exception $e) {
            throw new InvalidVisibilityProvided($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function publicUrl(string $path, Config $config): string
    {
        return $this->getUrl($path);
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        $expires = $expiresAt->getTimestamp() - time();
        return $this->getUrl($path, $expires);
    }

    /**
     * 获取文件URL
     * @param string $path
     * @param int $expires 过期时间(秒)，0为永久有效
     * @return string
     */
    public function getUrl(string $path, int $expires = 0): string
    {
        $key = $this->applyPathPrefix($path);
        // 自定义域名
        if (!empty($this->domain)) {
            $url = rtrim($this->domain, '/') . '/' . ltrim($key, '/');
            if ($expires > 0) {
                return $this->getPresignedUrl($path, 'GET', $expires);
            }
            return $url;
        }
        // 预签名URL
        if ($expires > 0) {
            return $this->getPresignedUrl($path, 'GET', $expires);
        }
        // 标准URL
        $protocol = $this->config['protocol'] ? (strpos($this->config['endpoint'], 'https') === 0 ? 'https' : 'http') : 'https';
        $domain = $this->config['domain'] ?: "{$this->bucket}.tos-{$this->config['region']}.volces.com";
        return "{$protocol}://{$domain}/" . ltrim($key, '/');
    }

    /**
     * 获取预签名URL
     * @param string $path
     * @param string $method
     * @param int $expires
     * @return string
     */
    public function getPresignedUrl(string $path, string $method = 'GET', int $expires = 3600): string
    {
        $input = new PreSignedURLInput(Enum::HttpMethodGet,  $this->bucket);
        $input->setHttpMethod($method);
        $input->setKey($this->applyPathPrefix($path));
        $input->setExpires($expires);
        $response = $this->client->preSignedURL($input);
        return $response->getSignedUrl();
    }
}
