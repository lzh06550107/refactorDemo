<?php

declare(strict_types=1);

use modules\theme\domain\InvalidThemePackage;
use modules\theme\domain\ThemePageNotFound;
use modules\theme\infrastructure\FilesystemThemePackageRepository;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weplatform-theme-' . bin2hex(random_bytes(6));
$themesRoot = $root . DIRECTORY_SEPARATOR . 'themes';
$themeRoot = $themesRoot . DIRECTORY_SEPARATOR . 'corporate';

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};

$writePackage = static function (string $themeRoot, array $manifest): void {
    foreach (['layouts', 'pages', 'components'] as $directory) {
        if (!is_dir($themeRoot . DIRECTORY_SEPARATOR . $directory)) {
            mkdir($themeRoot . DIRECTORY_SEPARATOR . $directory, 0777, true);
        }
    }
    file_put_contents(
        $themeRoot . DIRECTORY_SEPARATOR . 'theme.json',
        json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    );
    file_put_contents($themeRoot . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'default.html', '<html>@@CONTENT_HTML@@</html>');
    file_put_contents($themeRoot . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'index.html', '<main>{{ page_title }}</main>');
    file_put_contents($themeRoot . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'header.html', '<header>{{ site_title }}</header>');
    file_put_contents($themeRoot . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'footer.html', '<footer>{{ site_title }}</footer>');
};

$manifest = [
    'key' => 'corporate',
    'name' => 'Corporate',
    'version' => '1.0.0',
    'layout' => 'layouts/default.html',
    'pages' => ['index' => 'pages/index.html'],
    'components' => [
        'header' => 'components/header.html',
        'footer' => 'components/footer.html',
    ],
];

try {
    mkdir($themesRoot, 0777, true);
    $writePackage($themeRoot, $manifest);

    $repository = new FilesystemThemePackageRepository($themesRoot);
    $resolved = $repository->resolve('corporate', 'index');

    expectSame('corporate', $resolved->manifest()->key(), 'resolved page keeps parsed manifest');
    expectSame('<html>@@CONTENT_HTML@@</html>', $resolved->layoutTemplate(), 'resolved layout is loaded');
    expectSame('<main>{{ page_title }}</main>', $resolved->pageTemplate(), 'resolved page template is loaded');
    expectSame('<header>{{ site_title }}</header>', $resolved->headerTemplate(), 'resolved header template is loaded');
    expectSame('<footer>{{ site_title }}</footer>', $resolved->footerTemplate(), 'resolved footer template is loaded');

    expectThrows(
        static fn () => $repository->resolve('missing', 'index'),
        ThemePageNotFound::class,
        'unknown theme must be reported as not found',
    );
    expectThrows(
        static fn () => $repository->resolve('corporate', 'missing'),
        ThemePageNotFound::class,
        'unknown page must be reported as not found',
    );
    expectThrows(
        static fn () => $repository->resolve('../corporate', 'index'),
        ThemePageNotFound::class,
        'unsafe requested theme key must not escape themes root',
    );

    $missingRoot = $themesRoot . DIRECTORY_SEPARATOR . 'missing-file';
    $missingManifest = $manifest;
    $missingManifest['key'] = 'missing-file';
    $missingManifest['layout'] = 'layouts/not-there.html';
    $writePackage($missingRoot, $missingManifest);
    expectThrows(
        static fn () => $repository->resolve('missing-file', 'index'),
        InvalidThemePackage::class,
        'declared template file that is missing must invalidate package',
    );

    $outside = $root . DIRECTORY_SEPARATOR . 'outside-layout.html';
    file_put_contents($outside, '<html>outside</html>');
    $symlinkRoot = $themesRoot . DIRECTORY_SEPARATOR . 'symlink-theme';
    $symlinkManifest = $manifest;
    $symlinkManifest['key'] = 'symlink-theme';
    $writePackage($symlinkRoot, $symlinkManifest);
    $layoutPath = $symlinkRoot . DIRECTORY_SEPARATOR . 'layouts' . DIRECTORY_SEPARATOR . 'default.html';
    @unlink($layoutPath);
    if (function_exists('symlink') && @symlink($outside, $layoutPath)) {
        expectThrows(
            static fn () => $repository->resolve('symlink-theme', 'index'),
            InvalidThemePackage::class,
            'symlinked template escaping theme directory must be rejected',
        );
    }
} finally {
    $removeTree($root);
}
