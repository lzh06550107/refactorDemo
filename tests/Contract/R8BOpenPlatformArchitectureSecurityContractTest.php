<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$architectureRoots = [
    $root . '/app/openplatform/domain',
    $root . '/app/openplatform/application',
];
$phpFiles = static function (array $roots): array {
    $files = [];
    foreach ($roots as $directory) {
        expectTrue(is_dir($directory), 'R8B architecture scan directory missing: ' . $directory);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') { $files[] = $file->getPathname(); }
        }
    }
    sort($files);
    return $files;
};
foreach ($phpFiles($architectureRoots) as $file) {
    $source = (string) file_get_contents($file);
    expectTrue(!str_contains($source, 'think\\facade\\'), 'OpenPlatform domain/application must not import ThinkPHP Facades: ' . $file);
    expectTrue(!str_contains($source, 'Db::'), 'OpenPlatform domain/application must not depend on ThinkPHP Db: ' . $file);
    expectTrue(!str_contains($source, '$_W'), 'OpenPlatform domain/application must not depend on legacy $_W: ' . $file);
    expectTrue(!str_contains($source, '$_GPC'), 'OpenPlatform domain/application must not depend on legacy $_GPC: ' . $file);
    expectTrue(!str_contains($source, 'pdo_'), 'OpenPlatform domain/application must not depend on legacy pdo helpers: ' . $file);
}

$routePath = $root . '/app/api/route/app.php';
$route = (string) file_get_contents($routePath);
expectTrue(str_contains($route, "Route::post('v1/openplatform/components/:componentPlatformId/ticket'"), 'R8B exposes direct POST component ticket route');
$controllerPath = $root . '/app/api/controller/V1/OpenPlatformTicketController.php';
expectTrue(is_file($controllerPath), 'R8B component ticket controller exists');
$controller = (string) file_get_contents($controllerPath);
expectTrue(
    str_contains($controller, 'ComponentTicketService') || str_contains($controller, 'OpenPlatformEventService'),
    'ticket controller delegates to an OpenPlatform Application service',
);
expectTrue(!str_contains($controller, 'Repository'), 'ticket controller cannot access OpenPlatform repositories directly');

$adapterPath = $root . '/modules/miniapp/infrastructure/OpenPlatformComponentAccessTokenProvider.php';
expectTrue(is_file($adapterPath), 'R8A OpenPlatform component access token adapter exists');
$adapter = (string) file_get_contents($adapterPath);
expectTrue(str_contains($adapter, 'ComponentAccessTokenService'), 'R8A adapter delegates to R8B public Application service');
expectTrue(!str_contains($adapter, 'Repository'), 'R8A adapter cannot read R8B repositories directly');

foreach ([
    $root . '/docs/migration/r20-openplatform-component-trust-r8b.md',
    $root . '/docs/verification/openplatform-component-trust-r8b.md',
] as $file) {
    expectTrue(is_file($file), 'R8B release document missing: ' . $file);
}

$migration = (string) file_get_contents($root . '/database/migrations/20260908_007_openplatform_component_trust_up.sql');
expectTrue(!str_contains($migration, 'account_component_assesstoken'), 'R8B must not recreate legacy global component token cache key');
expectTrue(!preg_match('/`component_verify_ticket`\s/', $migration), 'R8B migration has no plaintext ticket column');
expectTrue(!preg_match('/`component_access_token`\s/', $migration), 'R8B migration has no plaintext token column');

$secretPatterns = [
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    '/\bghp_[A-Za-z0-9]{30,}\b/',
    '/\bgithub_pat_[A-Za-z0-9_]{30,}\b/',
    '/\bAKIA[0-9A-Z]{16}\b/',
];
$scanRoots = [$root . '/app/openplatform', $root . '/modules/miniapp/infrastructure'];
foreach (array_merge($phpFiles($scanRoots), [$root . '/database/migrations/20260908_007_openplatform_component_trust_up.sql']) as $file) {
    $source = (string) file_get_contents($file);
    foreach ($secretPatterns as $pattern) {
        expectTrue(preg_match($pattern, $source) !== 1, 'possible committed secret detected in R8B source: ' . $file);
    }
}
