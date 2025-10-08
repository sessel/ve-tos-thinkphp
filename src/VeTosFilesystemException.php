<?php

namespace Sessel\VeTosThinkphp;

use League\Flysystem\FilesystemException;
use RuntimeException;

class VeTosFilesystemException extends RuntimeException implements FilesystemException
{

}