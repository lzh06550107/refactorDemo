<?php

declare(strict_types=1);

use app\ExceptionHandle;
use modules\iam\contract\AdminIdGenerator;
use modules\iam\contract\BootstrapAdminRepository;
use modules\iam\infrastructure\ThinkPhpBootstrapAdminRepository;
use modules\iam\security\SecureAdminIdGenerator;
use think\exception\Handle;

return [
    Handle::class => ExceptionHandle::class,
    BootstrapAdminRepository::class => ThinkPhpBootstrapAdminRepository::class,
    AdminIdGenerator::class => SecureAdminIdGenerator::class,
];
