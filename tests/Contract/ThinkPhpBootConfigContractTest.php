<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$cacheConfigPath = $root . '/config/cache.php';
$routerPath = $root . '/public/router.php';
$appConfigPath = $root . '/config/app.php';

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
