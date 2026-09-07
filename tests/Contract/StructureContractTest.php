<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$required = [
    'public/index.php',
    'think',
    'app/admin/route/app.php',
    'app/web/route/app.php',
    'app/api/route/app.php',
    'app/common/context/RequestContext.php',
    'app/AppService.php',
];
foreach ($required as $path) {
    expectTrue(is_file($root . '/' . $path), 'missing required foundation file: ' . $path);
}
expectTrue(!is_dir($root . '/app/worker/route'), 'worker must not expose HTTP routes');
expectTrue(!is_dir($root . '/app/common/route'), 'common must not expose HTTP routes');

$publicIndex = file_get_contents($root . '/public/index.php');
expectTrue(str_contains($publicIndex, "require __DIR__ . '/../vendor/autoload.php'"), 'public/index.php must be Composer/ThinkPHP entrypoint');

foreach (['admin', 'web', 'api'] as $app) {
    $route = file_get_contents($root . '/app/' . $app . '/route/app.php');
    expectTrue(str_contains($route, 'Route::'), 'route file must register ThinkPHP routes: ' . $app);
}

$middlewareContracts = [
    'admin' => 'AdminRequestContextMiddleware',
    'web' => 'WebRequestContextMiddleware',
    'api' => 'ApiRequestContextMiddleware',
];
foreach ($middlewareContracts as $app => $class) {
    expectTrue(is_file($root . '/app/' . $app . '/middleware/' . $class . '.php'), 'missing app-specific request context middleware: ' . $app);
    $middlewareConfig = file_get_contents($root . '/app/' . $app . '/middleware.php');
    expectTrue(str_contains($middlewareConfig, $class . '::class'), 'middleware.php must register concrete middleware class: ' . $app);
    expectTrue(!str_contains($middlewareConfig, "RequestContextMiddleware::class . ':'"), 'string middleware parameters are forbidden: ' . $app);
}

expectTrue(is_file($root . '/app/ExceptionHandle.php'), 'ThinkPHP application exception handler missing');
$exceptionHandle = file_get_contents($root . '/app/ExceptionHandle.php');
expectTrue(str_contains($exceptionHandle, 'instanceof AppException'), 'exception handler must adapt AppException');
expectTrue(str_contains($exceptionHandle, 'ErrorEnvelope::error'), 'exception handler must use stable error envelope');
expectTrue(!str_contains($exceptionHandle, 'getTraceAsString'), 'exception handler must not expose stack traces');

$phpunitConfig = file_get_contents($root . '/phpunit.xml');
expectTrue(str_contains($phpunitConfig, '<directory>tests/phpunit</directory>'), 'phpunit must scan only the PHPUnit bridge suite');
expectTrue(is_file($root . '/tests/phpunit/FoundationOfflineSuiteTest.php'), 'PHPUnit bridge test missing');
