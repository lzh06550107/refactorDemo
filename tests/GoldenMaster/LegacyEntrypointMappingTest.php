<?php

declare(strict_types=1);

$fixture = dirname(__DIR__) . '/GoldenMaster/fixtures/entrypoints.json';
expectTrue(is_file($fixture), 'legacy entrypoint fixture missing');
$map = json_decode((string) file_get_contents($fixture), true, 512, JSON_THROW_ON_ERROR);
$expected = [
    '/index.php' => 'router/domain-resolver',
    '/web/index.php' => 'admin',
    '/app/index.php' => 'web',
    '/api.php' => 'api/webhook',
    '/payment/*' => 'api/payment-webhook',
    '/install.php' => 'deployment-only',
];
expectSame($expected, $map, 'legacy entrypoint map must match R20/V4 design');
