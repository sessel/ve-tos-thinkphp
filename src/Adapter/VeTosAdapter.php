<?php

namespace Sessel\VeTosThinkphp\Adapter;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
use Tos\Exception\TosClientException;
use Tos\Exception\TosServerException;
use Tos\Model\ListObjectsInput;
use Tos\Model\HeadObjectInput;
use Tos\Model\Object\ListObjectsV2Output;
use Tos\Model\PreSignedURLInput;
use Tos\TosClient;
use League\Flysystem\Exception;
use think\exception\InvalidArgumentException;
use Tos\Model\CopyObjectInput;
use Tos\Model\DeleteObjectInput;
use Tos\Model\Enum;
use Tos\Model\PutObjectInput;

class VeTosAdapter implements AdapterInterface
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
     * 处理TOS异常
     */
    protected function handleException(\Exception $e, string $path = ''): void
    {
        if ($e instanceof TosServerException) {
            if ($e->getStatusCode() === 404) {
                throw new \League\Flysystem\FileNotFoundException($path, $e->getStatusCode(), $e);
            }
            if ($e->getStatusCode() === 403) {
                throw new \League\Flysystem\Exception($path, $e->getStatusCode(), $e);
            }
        }
        
        throw new \League\Flysystem\Exception($e->getMessage(), 0, $e);
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, ?Config $config = null)
    {
        $config = $config ?: new Config();
        $key = $this->applyPathPrefix($path);

        try {
            $response = $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $location,
                'Body' => $contents,
            ]);

            return [
                'path' => $path,
                'size' => strlen($contents),
                'type' => 'file',
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
    public function writeStream($path, $resource, ?Config $config = null)
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

            return [
                'path' => $path,
                'type' => 'file',
                'etag' => $response->getETag(),
            ];
        } catch (\Exception $e) {
            halt($e);
            $this->handleException($e, $path);
            return false;
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
    public function copy($path, $newpath, $newBucket = null)
    {
        try {
            $key = $this->applyPathPrefix($newpath);
            $srcKey = $this->applyPathPrefix($path);
            $bucket = $newBucket ?: $this->bucket;
            $input = new CopyObjectInput($bucket, $key, $this->bucket, $srcKey);
            $this->client->copyObject($input);
            return true;
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path, $verionId = '')
    {
        $key = $this->applyPathPrefix($path);
        try {
            $input = new DeleteObjectInput($this->bucket, $key, $verionId);
            $this->client->deleteObject($input);
            return true;
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            return false;
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
    public function createDir($dirname, Config $config = null)
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
    public function has($path)
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
            return false;
        } catch (\Exception $e) {
            $this->handleException($e, $path);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        try {
            $response = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
            ]);

            return [
                'path' => $path,
                'contents' => (string)$response->getBody(),
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
    public function listContents($directory = '', $recursive = false)
    {
        $result = [];
        $nextMarker = '';
        $basePrefix = $this->applyPathPrefix($directory);
        
        if (!$recursive && $basePrefix !== '' && !str_ends_with($basePrefix, '/')) {
            $basePrefix .= '/';
        }

        try {
            do {
                $listInput = new ListObjectsInput($this->bucket);
                $listInput->setMaxKeys(1000);
                $listInput->setPrefix($basePrefix);
                $listInput->setMarker($nextMarker);
                if (!$recursive) {
                    $listInput->setDelimiter('/');
                }
                $output = $this->client->listObjects($listInput);

                foreach ($output->getCommonPrefixes() as $commonPrefix) {
                    $fullPrefixPath = $commonPrefix->getPrefix();
                    $relativePath = $this->removePathPrefix($fullPrefixPath);
                    $dirPath = rtrim($relativePath, '/');

                    $result[] = [
                        'path' => $dirPath,
                        'type' => 'dir',
                    ];
                }

                foreach ($output->getContents() as $content) {
                    $fullObjectKey = $content->getKey();
                    if ($content->getSize() === 0 && str_ends_with($fullObjectKey, '/')) {
                        continue;
                    }

                    $relativePath = $this->removePathPrefix($fullObjectKey);

                    $result[] = [
                        'path' => $relativePath,
                        'type' => 'file',
                        'size' => $content->getSize(),
                        'timestamp' => strtotime($content->getLastModified()),
                        'etag' => $content->getETag(),
                    ];
                }

                $nextMarker = $output->getNextMarker();
            } while (!empty($nextMarker));

            return $result;

        } catch (TosServerException $e) {
            $errorMsg = "TOS服务端错误: [{$e->getStatusCode()}] {$e->getErrorCode()} - {$e->getMessage()}";
            throw new Exception($errorMsg, $e->getStatusCode(), $e);
        } catch (TosClientException $e) {
            throw new Exception("TOS客户端错误: {$e->getMessage()}", 0, $e);
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
    public function setVisibility($path, $visibility)
    {
        $acl = $visibility === 'public' ? 'public-read' : 'private';

        try {
            $this->client->putObjectAcl([
                'Bucket' => $this->bucket,
                'Key' => $this->applyPathPrefix($path),
                'ACL' => $acl,
            ]);

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

    public function url(string $path): string
    {
        return $this->getUrl($path);
    }
}
