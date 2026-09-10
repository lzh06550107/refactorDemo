<?php

declare(strict_types=1);

use app\ExceptionHandle;
use think\exception\Handle;

return [
    Handle::class => ExceptionHandle::class,
];
