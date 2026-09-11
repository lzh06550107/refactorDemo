<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__, 2);

    foreach ([
        'frontend/web/package.json',
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
})();
