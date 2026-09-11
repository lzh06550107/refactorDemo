<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$phpFiles = static function (array $roots): array {
    $files = [];
    foreach ($roots as $directory) {
        expectTrue(is_dir($directory), 'R8C architecture scan directory missing: ' . $directory);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') { $files[] = $file->getPathname(); }
        }
    }
    sort($files);
    return $files;
};

foreach ($phpFiles([$root . '/modules/openplatform/domain', $root . '/modules/openplatform/application']) as $file) {
    $source = (string) file_get_contents($file);
    expectTrue(!str_contains($source, 'think\\facade\\'), 'R8C domain/application must not import ThinkPHP Facades: ' . $file);
    expectTrue(!str_contains($source, 'Db::'), 'R8C domain/application must not depend on ThinkPHP Db: ' . $file);
    expectTrue(!str_contains($source, '$_W'), 'R8C domain/application must not depend on legacy $_W: ' . $file);
    expectTrue(!str_contains($source, '$_GPC'), 'R8C domain/application must not depend on legacy $_GPC: ' . $file);
    expectTrue(!str_contains($source, 'pdo_'), 'R8C domain/application must not depend on legacy PDO helpers: ' . $file);
}

$route = (string) file_get_contents($root . '/app/api/route/app.php');
foreach ([
    "Route::post('v1/openplatform/components/:componentPlatformId/events'",
    "Route::post('v1/openplatform/components/:componentPlatformId/ticket'",
    "Route::post('v1/openplatform/components/:componentPlatformId/authorization-intents'",
    "Route::get('v1/openplatform/authorization/callback'",
] as $routeNeedle) {
    expectTrue(str_contains($route, $routeNeedle), 'R8C route missing: ' . $routeNeedle);
}

$controllers = [
    'event' => $root . '/app/api/controller/V1/OpenPlatformEventController.php',
    'start' => $root . '/app/api/controller/V1/OpenPlatformAuthorizationStartController.php',
    'callback' => $root . '/app/api/controller/V1/OpenPlatformAuthorizationCallbackController.php',
    'ticket' => $root . '/app/api/controller/V1/OpenPlatformTicketController.php',
];
foreach ($controllers as $name => $file) {
    expectTrue(is_file($file), 'R8C ' . $name . ' controller must exist');
    $source = (string) file_get_contents($file);
    expectTrue(!str_contains($source, 'Repository'), 'R8C controller must delegate to application services only: ' . $name);
}
$ticket = (string) file_get_contents($controllers['ticket']);
expectTrue(str_contains($ticket, 'OpenPlatformEventService'), 'legacy /ticket controller delegates through unified R8C event ingress');
$event = (string) file_get_contents($controllers['event']);
expectTrue(str_contains($event, 'OpenPlatformEventService'), '/events controller delegates to unified event service');
$start = (string) file_get_contents($controllers['start']);
expectTrue(str_contains($start, 'AuthorizationStartService'), 'authorization-intents controller delegates to start service');
$callback = (string) file_get_contents($controllers['callback']);
expectTrue(str_contains($callback, 'AuthorizationCallbackService'), 'browser callback controller delegates to callback service');

foreach ([
    $root . '/docs/migration/r20-openplatform-authorizer-lifecycle-r8c.md',
    $root . '/docs/verification/openplatform-authorizer-lifecycle-r8c.md',
] as $file) {
    expectTrue(is_file($file), 'R8C release document missing: ' . $file);
}
