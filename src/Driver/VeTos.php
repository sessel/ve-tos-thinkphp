<?php

namespace Sessel\VeTosThinkphp\Driver;

use DateTime;
use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Config;
use think\filesystem\Driver;
use Sessel\VeTosThinkphp\Adapter\VeTosAdapter;
use think\File;
use Tos\Exception\TosClientException;

class VeTos extends Driver
{
    /**
     * 配置参数
     * @var array
     */
    protected $config = [
        'ak' => '',
        'sk' => '',
        'region' => '',
        'bucket' => '',
        'prefix' => '',
        'domain' => '',
        'endpoint' => '',
        'connection_timeout' => 3000,
        'socket_timeout' => 3000,
        'max_retries' => 3,
        'error_handler' => null,
    ];

    public function __construct(array $config)
    {
        //禁用volcengine/ve-tos-php-sdk代码导致的E_DEPRECATED错误
        error_reporting(error_reporting() & ~E_DEPRECATED);

        $this->config = array_merge($this->config, $config);
        if(empty($this->config['ak']) || empty($this->config['sk']) || empty($this->config['region']) || empty($this->config['bucket'])){
            throw new TosClientException('missing config');
        }
        $adapter = $this->createAdapter();
        $this->filesystem = $this->createFilesystem($adapter);
    }

    /**
     * 创建文件系统
     * @return Filesystem
     */
    protected function createFilesystem(FilesystemAdapter $adapter): Filesystem
    {
        // 返回Filesystem实例
        return new Filesystem($adapter, $this->config);
    }

    /**
     * 创建文件系统
     * @return FilesystemAdapter
     */
    protected function createAdapter(): FilesystemAdapter
    {
        // 合并默认配置
        $adapter = new VeTosAdapter($this->config);
        return $adapter;
    }

    /**
     * 保存文件
     * @param string $path 路径
     * @param File $file 文件
     * @param null|string|\Closure $rule 文件名规则
     * @param array $options 参数
     * @return bool|string
     */
    public function putFile(string $path, File $file, $rule = null, array $options = [])
    {
        return $this->putFileAs($path, $file, $file->hashName($rule), $options);
    }

    /**
     * 指定文件名保存文件
     * @param string $path 路径
     * @param File $file 文件
     * @param string $name 文件名
     * @param array $options 参数
     * @return bool|string
     */
    public function putFileAs(string $path, File $file, string $name, array $options = [])
    {
        $stream = fopen($file->getRealPath(), 'r');
        $path   = trim($path . '/' . $name, '/');

        $result = $this->putStream($path, $stream, $options);

        if (is_resource($stream)) {
            fclose($stream);
        }
        return $result['path'] ?? false;
    }

    /**
     * @inheritdoc
     */
    public function putStream($path, $resource, array $config = [])
    {
        if ( ! is_resource($resource) || get_resource_type($resource) !== 'stream') {
            throw new InvalidArgumentException(__METHOD__ . ' expects argument #2 to be a valid resource.');
        }
        if ($this->filesystem->has($path)) {
            return $this->filesystem->writeStream($path, $resource, $config);
        }

        return $this->filesystem->writeStream($path, $resource, $config);
    }


    public function url(string $path, int $expires = 0, $config = []): string
    {
        if($expires > 0){
            $datetime = sprintf('+%d seconds');
            return $this->filesystem->temporaryUrl($path, new DateTime($datetime), $config);
        }
        return $this->filesystem->publicUrl($path);
    }

    public function __call($method, $parameters)
    {
        return $this->filesystem->$method(...$parameters);
    }
}
