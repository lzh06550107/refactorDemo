<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/vendor/topthink/framework/src/helper.php';

use app\web\controller\HomeController;
use app\web\support\WebAssetManifest;
use app\web\support\WebErrorPage;
use modules\theme\application\RenderThemePage;
use modules\theme\contract\ThemePackageRepository;
use modules\theme\domain\InvalidThemePackage;
use modules\theme\domain\ResolvedThemePage;
use modules\theme\domain\ThemePageNotFound;
use modules\theme\rendering\SafeThemeRenderer;
use modules\theme\rendering\ThemePageRenderer;

$root = dirname(__DIR__, 3);
$assets = new WebAssetManifest(
    $root . '/public/build/web/manifest.json',
    true,
    'http://127.0.0.1:5174',
);
$renderer = new ThemePageRenderer(new SafeThemeRenderer());

$notFoundRepository = new class implements ThemePackageRepository {
    public function resolve(string $themeKey, string $pageKey): ResolvedThemePage
    {
        throw new ThemePageNotFound('fixture detail must never reach the client');
    }
};
$notFoundController = new HomeController(
    new RenderThemePage($notFoundRepository, $renderer),
    $assets,
);
$response = $notFoundController->index();
expectSame(404, $response->getCode(), 'missing theme/page maps to HTTP 404');
expectSame('text/html; charset=UTF-8', $response->getHeader('Content-Type'), 'web not-found response is HTML UTF-8');
expectSame(WebErrorPage::notFound(), $response->getContent(), 'web not-found response uses the static safe page');
expectTrue(!str_contains($response->getContent(), 'fixture detail'), 'not-found exception detail is never exposed');

$invalidRepository = new class implements ThemePackageRepository {
    public function resolve(string $themeKey, string $pageKey): ResolvedThemePage
    {
        throw new InvalidThemePackage('broken trusted theme fixture');
    }
};
$invalidController = new HomeController(
    new RenderThemePage($invalidRepository, $renderer),
    $assets,
);
expectThrows(
    fn () => $invalidController->index(),
    InvalidThemePackage::class,
    'invalid trusted theme package must remain a server error, not a 404',
);
