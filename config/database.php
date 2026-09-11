<?php

declare(strict_types=1);

return [
    'default' => 'mysql',
    'time_query_rule' => [],
    'auto_timestamp' => true,
    'datetime_format' => 'Y-m-d H:i:s',
    'datetime_field' => '',
    'connections' => [
        'mysql' => [
            'type' => 'mysql',
            'hostname' => env('DATABASE_HOSTNAME', '127.0.0.1'),
            'database' => env('DATABASE_DATABASE', 'weplatform'),
            'username' => env('DATABASE_USERNAME', 'weplatform'),
            'password' => env('DATABASE_PASSWORD', ''),
            'hostport' => env('DATABASE_HOSTPORT', '3306'),
            'params' => [],
            'charset' => env('DATABASE_CHARSET', 'utf8mb4'),
            'prefix' => '',
            'deploy' => 0,
            'rw_separate' => false,
            'master_num' => 1,
            'slave_no' => '',
            'fields_strict' => true,
            'break_reconnect' => false,
            'trigger_sql' => env('APP_DEBUG', false),
            'fields_cache' => false,
        ],
    ],
];
