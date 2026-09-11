<?php

declare(strict_types=1);

return [
    'app_namespace' => '',
    'with_route' => true,
    'auto_multi_app' => true,
    'default_app' => 'web',
    'app_express' => true,
    'app_map' => [
        'admin' => static fn ($app): string => 'adminui',
        'admin-api' => static fn ($app): string => 'admin',
    ],
    'domain_bind' => [],
    'deny_app_list' => ['common', 'worker'],
    'default_timezone' => 'Asia/Shanghai',
    'show_error_msg' => false,
];
