<?php

declare(strict_types=1);

use modules\theme\domain\InvalidThemePackage;
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

$makePage = static function (string $layout, ?string $pageTemplate = null) use ($manifest): ResolvedThemePage {
    return new ResolvedThemePage(
        $manifest,
        $layout,
        $pageTemplate ?? '<section><h1>{{ page_title }}</h1><p>{{ page_description }}</p><span>{{ site_title }}</span></section>',
        '<header>{{ site_title }}</header>',
        '<footer>{{ site_title }}</footer>',
    );
};

$layout = <<<'HTML'
<!doctype html>
<html>
<head>
<title>{{ page_title }}</title>
<meta name="description" content="{{ page_description }}">
<link rel="stylesheet" href="{{ asset_css_url }}">
</head>
<body>
@@HEADER_HTML@@
<main>@@CONTENT_HTML@@</main>
@@FOOTER_HTML@@
<script type="module" src="{{ asset_js_url }}"></script>
</body>
</html>
HTML;

$renderer = new ThemePageRenderer(new SafeThemeRenderer());
$attack = '<script>alert(1)</script>';
$html = $renderer->render($makePage($layout), [
    'site_title' => $attack,
    'page_title' => $attack,
    'page_description' => $attack,
    'asset_css_url' => $attack,
    'asset_js_url' => $attack,
]);

expectTrue(!str_contains($html, $attack), 'user-derived scalar HTML must never remain executable');
expectTrue(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'user-derived scalar HTML is escaped');
expectTrue(!str_contains($html, '@@HEADER_HTML@@'), 'header fragment token is resolved');
expectTrue(!str_contains($html, '@@CONTENT_HTML@@'), 'content fragment token is resolved');
expectTrue(!str_contains($html, '@@FOOTER_HTML@@'), 'footer fragment token is resolved');
expectTrue(str_contains($html, '<header>&lt;script&gt;alert(1)&lt;/script&gt;</header>'), 'trusted header markup contains only escaped scalar data');
expectTrue(str_contains($html, '<footer>&lt;script&gt;alert(1)&lt;/script&gt;</footer>'), 'trusted footer markup contains only escaped scalar data');

expectThrows(
    static fn () => $renderer->render($makePage(str_replace('@@HEADER_HTML@@', '', $layout)), [
        'site_title' => 'Site', 'page_title' => 'Page', 'page_description' => 'Description',
        'asset_css_url' => '/app.css', 'asset_js_url' => '/app.js',
    ]),
    InvalidThemePackage::class,
    'missing trusted header token must invalidate package',
);
expectThrows(
    static fn () => $renderer->render($makePage(str_replace('@@CONTENT_HTML@@', '@@CONTENT_HTML@@@@CONTENT_HTML@@', $layout)), [
        'site_title' => 'Site', 'page_title' => 'Page', 'page_description' => 'Description',
        'asset_css_url' => '/app.css', 'asset_js_url' => '/app.js',
    ]),
    InvalidThemePackage::class,
    'duplicate trusted content token must invalidate package',
);
expectThrows(
    static fn () => $renderer->render($makePage(str_replace('</body>', '@@SIDEBAR_HTML@@</body>', $layout)), [
        'site_title' => 'Site', 'page_title' => 'Page', 'page_description' => 'Description',
        'asset_css_url' => '/app.css', 'asset_js_url' => '/app.js',
    ]),
    InvalidThemePackage::class,
    'unknown trusted fragment token must invalidate package',
);
expectThrows(
    static fn () => $renderer->render($makePage($layout), [
        'site_title' => 'Site', 'page_title' => 'Page',
        'asset_css_url' => '/app.css', 'asset_js_url' => '/app.js',
    ]),
    InvalidArgumentException::class,
    'missing required scalar field must fail closed',
);

foreach (['<?', '{php', '{hook', '{template', '{data', '{if', '{elseif', '{else}', '{loop'] as $forbidden) {
    expectThrows(
        static fn () => $renderer->render($makePage($layout, '<section>' . $forbidden . '</section>'), [
            'site_title' => 'Site', 'page_title' => 'Page', 'page_description' => 'Description',
            'asset_css_url' => '/app.css', 'asset_js_url' => '/app.js',
        ]),
        InvalidArgumentException::class,
        'legacy server directive must remain forbidden: ' . $forbidden,
    );
}
