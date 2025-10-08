<?php

namespace Sessel\VeTosThinkphp\Adapter;

use DateTimeInterface;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\Util;
use Tos\Exception\TosClientException;
use Tos\Exception\TosServerException;
use Tos\Model\ListObjectsInput;
use Tos\Model\HeadObjectInput;
use Tos\Model\Object\ListObjectsV2Output;
use Tos\Model\PreSignedURLInput;
use Tos\TosClient;
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
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\Visibility;
use Sessel\VeTosThinkphp\VeTosFilesystemException;
use Tos\Model\CopyObjectInput;
use Tos\Model\DeleteObjectInput;
use Tos\Model\Enum;
use Tos\Model\GetObjectInput;
use Tos\Model\PutObjectACLInput;
use Tos\Model\PutObjectInput;

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
     * {@inheritdoc}
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
            throw UnableToCheckExistence::forLocation($path, $e);
        } catch (\Exception $e) {
            throw UnableToCheckExistence::forLocation($path, $e);
        }
    }

    /**
     * {@inheritdoc}
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
            throw UnableToCheckExistence::forLocation($path, $e);
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
                $this->client->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => ['Objects' => $objects],
                ]);
            }
        } catch (\Exception $e) {
            throw UnableToDeleteDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createDirectory(string $path, ?Config $config = null): void
    {
        $config = $config ?? new Config();
        $location = rtrim($this->applyPathPrefix($path), '/') . '/';

        try {
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $location,
                'Body' => '',
                'ACL' => $this->client->getAclFromVisibility($config->get(Config::OPTION_VISIBILITY)),
            ]);
        } catch (\Exception $e) {
            throw UnableToCreateDirectory::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function visibility(string $path): FileAttributes
    {
        try {
            $response = $this->client->getObjectAcl([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]);

            $visibility = Visibility::PRIVATE;
            foreach ($response->getGrants() as $grant) {
                if ($grant->getGrantee()->getURI() === 'http://acs.amazonaws.com/groups/global/AllUsers') {
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
    public function mimeType(string $path): FileAttributes
    {
        try {
            $response = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]);

            return new FileAttributes($path, null, null, null, $response->getContentType());
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::mimeType($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        try {
            $response = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]);

            $timestamp = strtotime($response->getLastModified());
            return new FileAttributes($path, null, null, $timestamp);
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::lastModified($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        try {
            $response = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]);

            return new FileAttributes($path, $response->getContentLength());
        } catch (\Exception $e) {
            throw UnableToRetrieveMetadata::fileSize($path, $e->getMessage(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function move(string $source, string $destination, Config $config = null): void
    {
        try {
            // 复制到新路径
            $this->client->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($destination),
                'CopySource' => "{$this->bucket}/{$this->applyPathPrefix($source)}",
            ]);

            // 删除原路径
            $this->delete($source);
        } catch (\Exception $e) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * 处理TOS异常
     */
    protected function handleException(\Exception $e, string $path = ''): void
    {
        if ($e instanceof TosServerException) {
            if ($e->getStatusCode() === 404) {
                throw new UnableToReadFile($path, $e->getStatusCode(), $e);
            }
            if ($e->getStatusCode() === 403) {
                throw new UnableToReadFile($path, $e->getStatusCode(), $e);
            }
        }
        
        throw new UnableToReadFile($e->getMessage(), 0, $e);
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $config = $config ?: new Config();
        $key = $this->applyPathPrefix($path);

        try {
            $response = $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $contents,
            ]);
        } catch (\Exception $e) {
            $this->handleException($e, $path);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream(string $path, $resource, Config $config): void
    {
        $config = $config ?: new Config();
        $key = $this->applyPathPrefix($path);
        try {
            $input = new PutObjectInput($this->bucket, $key);
            if($config && $meta = $config->get('meta')){
                $input->setMeta($meta);
            }
            $input->setContent($resource);
            $response = $this->client->putObject($input);

            if (is_resource($resource)) {
                fclose($resource);
            }
        } catch (\Exception $e) {
            halt($e);
            $this->handleException($e, $path);
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
     * {@inheritdoc}
     */
    public function createDir($dirname, ?Config $config = null)
    {
        $config = $config ?: new Config();
        $path = rtrim($dirname, '/') . '/';
        $location = $this->applyPathPrefix($path);

        try {
            // 上传空对象模拟目录
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $location,
                'Body' => '',
            ]);

            return [
                'path' => $path,
                'type' => 'dir',
            ];
        } catch (\Exception $e) {
            $this->handleException($e, $dirname);
            return false;
        }
    }


    /**
     * {@inheritdoc}
     */
    public function read(string $path, string $range = '', string $versionId = ''): string
    {
        $key = $this->applyPathPrefix($path);
        try {
            $input = new GetObjectInput($this->bucket, $key, $range, $versionId);
            $output = $this->client->getObject($input);
            return $output->getContent()->getContents();
        } catch (\Exception $e) {
            throw new UnableToReadFile($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        try {
            $response = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]);

            return [
                'path' => $path,
                'stream' => $response->getBody()->detach(),
                'type' => 'file',
            ];
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            return false;
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
                        strtotime($content->getLastModified()),
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
                'timestamp' => strtotime($response->getLastModified()),
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
    public function getVisibility($path)
    {
        try {
            $response = $this->client->getObjectAcl([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]);

            $visibility = 'private';
            foreach ($response->getGrants() as $grant) {
                if ($grant->getGrantee()->getURI() === 'http://acs.amazonaws.com/groups/global/AllUsers') {
                    $visibility = 'public';
                    break;
                }
            }

            return [
                'path' => $path,
                'visibility' => $visibility,
            ];
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility(string $path, string $visibility, string $versionId = ''): void
    {
        $acl = $visibility === 'public' ? 'public-read' : 'private';
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
