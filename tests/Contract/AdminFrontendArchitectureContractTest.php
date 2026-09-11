<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__, 2);
    $packageFile = $root . '/frontend/admin/package.json';
    if (!is_file($packageFile)) {
        throw new RuntimeException('frontend/admin/package.json is missing');
    }

    $package = json_decode((string) file_get_contents($packageFile), true, 512, JSON_THROW_ON_ERROR);
    foreach (['vue', 'vue-router', 'pinia', 'axios', 'element-plus'] as $dependency) {
        if (!isset($package['dependencies'][$dependency])) {
            throw new RuntimeException("Admin dependency missing: {$dependency}");
        }
    }
    foreach (['vite', 'typescript', 'vitest', 'vue-tsc', '@vitejs/plugin-vue'] as $dependency) {
        if (!isset($package['devDependencies'][$dependency])) {
            throw new RuntimeException("Admin dev dependency missing: {$dependency}");
        }
    }

    foreach (['src', 'src/router', 'src/stores', 'src/api', 'src/views'] as $relative) {
        if (!is_dir($root . '/frontend/admin/' . $relative)) {
            throw new RuntimeException("Admin source directory missing: {$relative}");
        }
    }

    /** @var array<string,mixed> $appConfig */
    $appConfig = require $root . '/config/app.php';
    $appMap = $appConfig['app_map'] ?? null;
    if (!is_array($appMap)) {
        throw new RuntimeException('config/app.php app_map must be an array');
    }

    $adminApiAlias = $appMap['admin-api'] ?? null;
    if (!is_callable($adminApiAlias) || $adminApiAlias(null) !== 'admin') {
        throw new RuntimeException('config/app.php must map admin-api to admin through a callable alias');
    }
    if (array_search('admin', $appMap, true) !== false) {
        throw new RuntimeException('admin must remain directly addressable; do not use admin as a scalar app_map value');
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/frontend/admin/src'));
    foreach ($iterator as $file) {
        if (!$file->isFile() || preg_match('/\.(ts|vue)$/', $file->getFilename()) !== 1) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        foreach (['localStorage.setItem', 'sessionStorage.setItem'] as $forbidden) {
            if (str_contains($source, $forbidden)) {
                throw new RuntimeException('Raw browser storage is forbidden: ' . $file->getPathname());
            }
        }
    }
})();
