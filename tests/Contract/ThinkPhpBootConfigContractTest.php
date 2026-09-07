<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$cacheConfigPath = $root . '/config/cache.php';

expectTrue(is_file($cacheConfigPath), 'ThinkPHP cache config must exist so framework services can boot');
$cache = require $cacheConfigPath;
expectSame('file', $cache['default'] ?? null, 'ThinkPHP cache default driver must be file');
expectSame('File', $cache['stores']['file']['type'] ?? null, 'ThinkPHP file cache store must use File driver');
