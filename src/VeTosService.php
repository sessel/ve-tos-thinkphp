<?php
namespace Sessel\VeTosThinkphp;

use Sessel\VeTosThinkphp\Adapter\VeTosAdapter;
use think\Service;
use Sessel\VeTosThinkphp\Driver\VeTos;

class VeTosService extends Service
{
    public function register()
    {
         $this->app->bind('oss.vengine_tos', function($config){
            return new VeTosAdapter($config);
         });
    }
}
