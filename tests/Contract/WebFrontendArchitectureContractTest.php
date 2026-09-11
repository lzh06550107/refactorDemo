<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__, 2);

    foreach ([
        'frontend/web/package.json',
        'frontend/web/package-lock.json',
        'themes/corporate/theme.json',
        'themes/corporate/layouts/default.html',
        'themes/corporate/pages/index.html',
        'themes/corporate/components/header.html',
        'themes/corporate/components/footer.html',
    ] as $relative) {
        if (!is_file($root . '/' . $relative)) {
            throw new RuntimeException("Web frontend foundation missing: {$relative}");
        }
    }

    $package = json_decode(
        (string) file_get_contents($root . '/frontend/web/package.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    foreach (['vue', 'nuxt', 'react', 'next'] as $forbiddenDependency) {
        if (isset($package['dependencies'][$forbiddenDependency])) {
            throw new RuntimeException("Web foundation runtime dependency is forbidden: {$forbiddenDependency}");
        }
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/themes'));
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        foreach (['<?', 'think\\facade\\Db', 'Db::', 'Repository::class'] as $forbidden) {
            if (str_contains($source, $forbidden)) {
                throw new RuntimeException('Theme contains forbidden executable/persistence access: ' . $file->getPathname());
            }
        }
    }

    $ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
    foreach ([
        'frontend/admin/package-lock.json',
        'frontend/web/package-lock.json',
        'npm ci --prefix frontend/web',
        'npm test --prefix frontend/web',
        'npm run build --prefix frontend/web',
        'web_home_body=/tmp/web-home.html',
        'text/html',
        '<!doctype html>',
        '/build/web/assets/',
        'ThinkPHP 8 Web/H5 theme runtime',
    ] as $requiredCiFragment) {
        if (!str_contains($ci, $requiredCiFragment)) {
            throw new RuntimeException('Web CI gate is missing required fragment: ' . $requiredCiFragment);
        }
    }

    $webBuildPosition = strpos($ci, 'npm run build --prefix frontend/web');
    $httpSmokePosition = strpos($ci, 'Smoke multi-app HTTP routes');
    if ($webBuildPosition === false || $httpSmokePosition === false || $webBuildPosition > $httpSmokePosition) {
        throw new RuntimeException('Web production build must run before HTTP smoke.');
    }
})();
