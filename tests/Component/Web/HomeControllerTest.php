<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/vendor/topthink/framework/src/helper.php';

use app\web\controller\HomeController;
use app\web\support\WebAssetManifest;
use modules\theme\application\RenderThemePage;
use modules\theme\infrastructure\FilesystemThemePackageRepository;
use modules\theme\rendering\SafeThemeRenderer;
use modules\theme\rendering\ThemePageRenderer;

$root = dirname(__DIR__, 3);
$renderThemePage = new RenderThemePage(
    new FilesystemThemePackageRepository($root . '/themes'),
    new ThemePageRenderer(new SafeThemeRenderer()),
);
$assets = new WebAssetManifest(
    $root . '/public/build/web/manifest.json',
    true,
    'http://127.0.0.1:5174',
);

$response = (new HomeController($renderThemePage, $assets))->index();
expectSame(200, $response->getCode(), 'home page returns HTTP 200');
$html = $response->getContent();
expectTrue(is_string($html), 'home page response content is HTML string');
expectTrue(str_starts_with($html, '<!doctype html>'), 'home page is a complete server-rendered HTML document');
expectTrue(str_contains($html, '<h1>WePlatform</h1>'), 'hero content is present in initial server HTML');
expectTrue(str_contains($html, 'ThinkPHP 8 Web/H5 theme runtime'), 'page description is rendered server-side');
expectTrue(str_contains($html, 'http://127.0.0.1:5174/src/css/main.css'), 'home page includes resolved CSS asset URL');
expectTrue(str_contains($html, 'type="module" src="http://127.0.0.1:5174/src/js/main.js"'), 'home page includes resolved module JS asset URL');
expectTrue(!str_contains($html, '@@HEADER_HTML@@'), 'trusted header token is fully composed');
expectTrue(!str_contains($html, '@@CONTENT_HTML@@'), 'trusted content token is fully composed');
expectTrue(!str_contains($html, '@@FOOTER_HTML@@'), 'trusted footer token is fully composed');

$routeFile = $root . '/app/web/route/app.php';
$routes = (string) file_get_contents($routeFile);
expectTrue(str_contains($routes, "Route::get('/', 'HomeController/index')"), 'web root route is registered');
expectTrue(str_contains($routes, "Route::get('health', 'HealthController/index')"), 'existing web health route remains registered');
