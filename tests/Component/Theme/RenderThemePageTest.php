<?php

declare(strict_types=1);

use modules\theme\application\RenderThemePage;
use modules\theme\contract\ThemePackageRepository;
use modules\theme\domain\ResolvedThemePage;
use modules\theme\domain\ThemeManifest;
use modules\theme\rendering\SafeThemeRenderer;
use modules\theme\rendering\ThemePageRenderer;

$manifest = ThemeManifest::fromArray('corporate', [
    'key' => 'corporate',
    'name' => 'Corporate',
    'version' => '1.0.0',
    'layout' => 'layouts/default.html',
    'pages' => ['index' => 'pages/index.html'],
    'components' => [
        'header' => 'components/header.html',
        'footer' => 'components/footer.html',
    ],
]);

$page = new ResolvedThemePage(
    $manifest,
    '<html><head><title>{{ page_title }}</title><meta name="description" content="{{ page_description }}"><link rel="stylesheet" href="{{ asset_css_url }}"></head><body>@@HEADER_HTML@@@@CONTENT_HTML@@@@FOOTER_HTML@@<script type="module" src="{{ asset_js_url }}"></script></body></html>',
    '<main><h1>{{ page_title }}</h1><p>{{ page_description }}</p><span>{{ site_title }}</span></main>',
    '<header>{{ site_title }}</header>',
    '<footer>{{ site_title }}</footer>',
);

$repository = new class($page) implements ThemePackageRepository {
    /** @var list<array{0:string,1:string}> */ public array $calls = [];
    public function __construct(private ResolvedThemePage $page) {}
    public function resolve(string $themeKey, string $pageKey): ResolvedThemePage
    {
        $this->calls[] = [$themeKey, $pageKey];
        return $this->page;
    }
};

$service = new RenderThemePage($repository, new ThemePageRenderer(new SafeThemeRenderer()));
$html = $service->execute('corporate', 'index', [
    'site_title' => 'WePlatform',
    'page_title' => 'Home',
    'page_description' => 'Server rendered home',
    'asset_css_url' => '/build/web/assets/main.css',
    'asset_js_url' => '/build/web/assets/main.js',
]);

expectSame([['corporate', 'index']], $repository->calls, 'render use case resolves requested theme page once');
expectTrue(str_contains($html, '<header>WePlatform</header>'), 'render use case composes trusted header');
expectTrue(str_contains($html, '<h1>Home</h1>'), 'render use case composes page body');
expectTrue(str_contains($html, '<footer>WePlatform</footer>'), 'render use case composes trusted footer');
expectTrue(str_contains($html, 'src="/build/web/assets/main.js"'), 'render use case includes prepared JS asset URL');
