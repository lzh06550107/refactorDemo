<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__, 2);

    foreach ([
        'frontend/web/package.json',
        'frontend/web/package-lock.json',
        'frontend/web/playwright.config.js',
        'frontend/web/e2e/home.spec.js',
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

    if (($package['devDependencies']['@playwright/test'] ?? null) === null) {
        throw new RuntimeException('Web browser E2E gate must pin @playwright/test as a development dependency.');
    }
    if (($package['scripts']['test:e2e'] ?? null) !== 'playwright test') {
        throw new RuntimeException('Web browser E2E gate must expose npm run test:e2e.');
    }

    $playwrightConfig = (string) file_get_contents($root . '/frontend/web/playwright.config.js');
    foreach ([
        'http://127.0.0.1:18080',
        'php ../../think run -p 18080',
        'trace',
        'screenshot',
    ] as $requiredPlaywrightFragment) {
        if (!str_contains($playwrightConfig, $requiredPlaywrightFragment)) {
            throw new RuntimeException('Playwright config is missing required fragment: ' . $requiredPlaywrightFragment);
        }
    }

    $browserSpec = (string) file_get_contents($root . '/frontend/web/e2e/home.spec.js');
    foreach ([
        'javaScriptEnabled: false',
        'data-nav-toggle',
        'aria-expanded',
        'page.setViewportSize',
    ] as $requiredBrowserSpecFragment) {
        if (!str_contains($browserSpec, $requiredBrowserSpecFragment)) {
            throw new RuntimeException('Web browser E2E spec is missing required fragment: ' . $requiredBrowserSpecFragment);
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
        'npx playwright install --with-deps chromium',
        'npm run test:e2e --prefix frontend/web',
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
    $browserGatePosition = strpos($ci, 'npm run test:e2e --prefix frontend/web');
    $httpSmokePosition = strpos($ci, 'Smoke multi-app HTTP routes');
    if ($webBuildPosition === false || $httpSmokePosition === false || $webBuildPosition > $httpSmokePosition) {
        throw new RuntimeException('Web production build must run before HTTP smoke.');
    }
    if ($browserGatePosition === false || $browserGatePosition < $webBuildPosition || $browserGatePosition > $httpSmokePosition) {
        throw new RuntimeException('Web browser E2E gate must run after the Web production build and before HTTP smoke.');
    }
})();
