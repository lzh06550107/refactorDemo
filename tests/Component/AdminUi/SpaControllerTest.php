<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use app\adminui\controller\SpaController;

$directory = sys_get_temp_dir() . '/weplatform-adminui-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create Admin UI test directory.');
}

$indexPath = $directory . '/index.html';
file_put_contents($indexPath, '<!doctype html><html><body><div id="app"></div></body></html>');

try {
    $response = (new SpaController($indexPath))->index();
    expectSame(200, $response->getCode(), 'Admin SPA returns HTTP 200');
    expectTrue(str_contains($response->getContent(), '<div id="app"></div>'), 'Admin SPA returns built shell');
    expectTrue(
        str_contains((string) $response->getHeader('Content-Type'), 'text/html'),
        'Admin SPA returns text/html content type',
    );

    $missing = (new SpaController($directory . '/missing-index.html'))->index();
    expectSame(503, $missing->getCode(), 'Missing Admin build returns HTTP 503');
    expectTrue(
        str_contains($missing->getContent(), 'Admin UI unavailable'),
        'Missing Admin build returns a controlled unavailable page',
    );
    expectTrue(
        !str_contains(strtolower($missing->getContent()), 'stack trace'),
        'Missing Admin build does not expose a stack trace',
    );
} finally {
    @unlink($indexPath);
    @rmdir($directory);
}
