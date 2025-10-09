# Volcengine Object Storage(TOS) filesystem for ThinkPHP
火山TOS对象存储Filesystem扩展, 基于 `topthink/think-filesystem` 和 `volcengine/ve-tos-php-sdk`

## 安装
```
# ThinkPHP 6.x
composer require sessel/ve-tos-thinkphp:^1.0

# ThinkPHP 8.x
composer require sessel/ve-tos-thinkphp:^2.0
```

## 配置
```
# config/filesystem.php
...
'oss' => [
    'type' => \Sessel\VeTosThinkphp\Driver\VeTos::class,
    'ak' => 'ak',
    'sk' => 'sk',
    'region' => 'region',
    'bucket' => 'bucket',
    'prefix' => 'prefix',
    'domain' => 'custom domain',
    'endpoint' => 'https://xxx/',
],
...
```

## 示例
```
use think\facade\Filesystem;
use GuzzleHttp\Psr7\Utils;

$disk = Filesystem::disk('oss');
// $gen = $disk->listContents('/');
// $files = [];
// foreach($gen as $file){
//     $files[] = $file;
// }
// halt($files);
// halt($disk->url('/php.png'));
// halt($disk->has('/php.png'));
// halt($disk->has('/php2.png'));
// halt($disk->copy('/php2.png', 'php3.png'));
// halt($disk->delete('/php3.png'));
// halt($disk->read('/test.txt'));
// halt($disk->createDirectory('test'));
// halt($disk->fileExists('php5.png'));
// halt($disk->write('test.txt', '222');
// $content = '';
// for ($i = 0; $i < 20000; $i++) {
//     $content .= uniqid();
// }
// $veTosAdapter = app('oss.vengine_tos', ['config' => config('filesystem.disks.oss')]);
// halt($veTosAdapter->appendWrite('test_append2.txt', $content, new Config(['next_append_offset' => 260000])));
// halt($disk->visibility('test.txt'));
// halt($disk->fileSize('test_append.txt'));
// halt($disk->mimeType('test_append.txt'));
// halt($disk->lastModified('test_append.txt'));
// halt($disk->read('test_append.txt'));
// $readStream = $disk->readStream('test_append.txt');
// $writeStream = fopen(public_path('static') . 'test_append.txt', 'w+');
// Utils::copyToStream(Utils::streamFor($readStream), Utils::streamFor($writeStream));
```
