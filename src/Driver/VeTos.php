<?php

namespace Sessel\VeTosThinkphp\Driver;

use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use think\filesystem\Driver;
use Sessel\VeTosThinkphp\Adapter\VeTosAdapter;
use think\File;

class VeTos extends Driver
{
    /**
     * 配置参数
     * @var array
     */
    protected $config = [
        'ak' => '',
        'sk' => '',
        'region' => 'cn-beijing',
        'bucket' => '',
        'prefix' => '',
        'domain' => '',
        'protocol' => 'https',
        'endpoint' => '',
        'connection_timeout' => 3000,
        'socket_timeout' => 3000,
        'max_retries' => 3,
    ];

    public function __construct(array $config)
    {
        $this->config = array_merge($this->config, $config);

        $adapter = $this->createAdapter();
        $this->filesystem = $this->createFilesystem($adapter);
    }

    /**
     * 创建文件系统
     * @return Filesystem
     */
    protected function createFilesystem(AdapterInterface $adapter): Filesystem
    {
        // 返回Filesystem实例
        return new Filesystem($adapter, $this->config);
    }

    /**
     * 创建文件系统
     * @return AdapterInterface
     */
    protected function createAdapter(): AdapterInterface
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
        $config = $config = new Config($config);
        if ($this->filesystem->getAdapter()->has($path)) {
            return $this->filesystem->getAdapter()->updateStream($path, $resource, $config);
        }

        return $this->filesystem->getAdapter()->writeStream($path, $resource, $config);
    }

    public function __call($method, $parameters)
    {
        return $this->filesystem->getAdapter()->$method(...$parameters);
    }
}
