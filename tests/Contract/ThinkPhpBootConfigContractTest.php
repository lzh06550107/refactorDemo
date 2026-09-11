<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$cacheConfigPath = $root . '/config/cache.php';
$routerPath = $root . '/public/router.php';
$appConfigPath = $root . '/config/app.php';
$databaseConfigPath = $root . '/config/database.php';
$envExamplePath = $root . '/.env.example';
$providerPath = $root . '/app/provider.php';

expectTrue(is_file($cacheConfigPath), 'ThinkPHP cache config must exist so framework services can boot');
$cache = require $cacheConfigPath;
expectSame('file', $cache['default'] ?? null, 'ThinkPHP cache default driver must be file');
expectSame('File', $cache['stores']['file']['type'] ?? null, 'ThinkPHP file cache store must use File driver');
expectTrue(is_file($routerPath), 'ThinkPHP development server requires public/router.php');
$router = file_get_contents($routerPath);
expectTrue(is_string($router) && str_contains($router, "require __DIR__ . '/index.php';"), 'ThinkPHP router must delegate dynamic requests to public/index.php');
$app = require $appConfigPath;
expectSame('web', $app['default_app'] ?? null, 'Web must remain the default application');
expectSame(true, $app['app_express'] ?? false, 'Multi-app express mode must preserve non-app paths for the default web application');

expectTrue(is_file($providerPath), 'ThinkPHP provider bindings must exist for application-level exception rendering');
$providerBindings = is_file($providerPath) ? require $providerPath : [];
expectSame(
    \app\ExceptionHandle::class,
    $providerBindings[\think\exception\Handle::class] ?? null,
    'ThinkPHP provider must route framework exceptions through app ExceptionHandle',
);

expectTrue(is_file($databaseConfigPath), 'ThinkPHP database config must exist so ORM repositories can boot');
expectTrue(is_file($envExamplePath), '.env.example must document database runtime settings');
$databaseConfig = (string) file_get_contents($databaseConfigPath);
$envExample = (string) file_get_contents($envExamplePath);
expectTrue(str_contains($databaseConfig, "'default' => 'mysql'"), 'ThinkPHP default database connection must be mysql');
expectTrue(str_contains($databaseConfig, "'mysql' => ["), 'ThinkPHP database config must define mysql connection');
expectTrue(str_contains($databaseConfig, "'type' => 'mysql'"), 'ThinkPHP mysql connection type must be explicit');
foreach ([
    'DATABASE_HOSTNAME',
    'DATABASE_DATABASE',
    'DATABASE_USERNAME',
    'DATABASE_PASSWORD',
    'DATABASE_HOSTPORT',
    'DATABASE_CHARSET',
] as $key) {
    expectTrue(str_contains($databaseConfig, "env('" . $key . "'"), 'database config must read ' . $key);
    expectTrue(str_contains($envExample, $key . '='), '.env.example must document ' . $key);
}
expectTrue(str_contains($databaseConfig, "env('DATABASE_CHARSET', 'utf8mb4')"), 'database charset must default to utf8mb4');
expectTrue(str_contains($databaseConfig, "'prefix' => ''"), 'platform database connection must not apply the legacy ims_ prefix');
expectTrue(!str_contains($databaseConfig, 'change-me'), 'database config must not hard-code example credentials');

require __DIR__ . '/DatabaseMigrationStandardizationContractTest.php';
