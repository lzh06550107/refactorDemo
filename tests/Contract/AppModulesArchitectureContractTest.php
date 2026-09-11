<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$businessModules = [
    'account', 'entitlement', 'iam', 'member', 'miniapp', 'module', 'oauth',
    'openplatform', 'quota', 'site', 'tenant', 'theme', 'webhook',
];
$allowedAppDirectories = ['admin', 'api', 'web', 'worker', 'common'];

foreach (new DirectoryIterator($root . '/app') as $entry) {
    if ($entry->isDot() || !$entry->isDir()) {
        continue;
    }
    expectTrue(
        in_array($entry->getFilename(), $allowedAppDirectories, true),
        'unexpected first-level app directory: ' . $entry->getFilename(),
    );
}

foreach ($businessModules as $module) {
    expectTrue(!is_dir($root . '/app/' . $module), 'business module must not live under app/: ' . $module);
    expectTrue(is_dir($root . '/modules/' . $module), 'business module must live under modules/: ' . $module);
}

expectTrue(!is_dir($root . '/app/legacy'), 'legacy runtime must not live under app/');
expectTrue(is_dir($root . '/modules/integration/legacy'), 'legacy runtime must live under modules/integration/legacy');
expectTrue(!is_dir($root . '/app/command'), 'CLI adapter must not live in app/command');
expectTrue(
    is_file($root . '/app/worker/command/OpenPlatformProvisioningWorkerCommand.php'),
    'provisioning command must live under app/worker/command',
);

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$psr4 = $composer['autoload']['psr-4'] ?? [];
expectTrue(($psr4['app\\'] ?? null) === 'app/', 'Composer must map app\\ to app/');
expectTrue(($psr4['modules\\'] ?? null) === 'modules/', 'Composer must map modules\\ to modules/');

$moduleRoot = $root . '/modules';
if (is_dir($moduleRoot)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($moduleRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        foreach (['app\\admin\\', 'app\\api\\', 'app\\web\\', 'app\\worker\\'] as $prefix) {
            expectTrue(
                !str_contains($source, $prefix),
                'business module depends on delivery app: ' . $relative . ' -> ' . $prefix,
            );
        }

        if (preg_match('/^modules\/(.+)\/[^\/]+\.php$/', $relative, $matches) === 1) {
            $expectedNamespace = 'modules\\' . str_replace('/', '\\', $matches[1]);
            expectTrue(
                preg_match('/\bnamespace\s+' . preg_quote($expectedNamespace, '/') . '\s*;/', $source) === 1,
                'module namespace does not match physical path: ' . $relative . ' expected=' . $expectedNamespace,
            );
        }

        if (str_contains('/' . $relative, '/domain/')) {
            expectTrue(!str_contains($source, 'think\\'), 'domain must not depend on ThinkPHP: ' . $relative);
            expectTrue(
                !str_contains($source, 'app\\common\\infrastructure\\'),
                'domain must not depend on app\\common\\infrastructure: ' . $relative,
            );
        }
    }
}

$staleModules = array_merge($businessModules, ['legacy', 'command']);
foreach ([$root . '/app', $root . '/modules', $root . '/config', $root . '/tests'] as $activeRoot) {
    if (!is_dir($activeRoot)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($activeRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        if ($file->getFilename() === 'AppModulesArchitectureContractTest.php') {
            continue;
        }

        $source = (string) file_get_contents($file->getPathname());
        foreach ($staleModules as $module) {
            $prefix = 'app\\' . $module . '\\';
            expectTrue(
                !str_contains($source, $prefix),
                'stale business namespace remains: ' . $file->getPathname() . ' -> ' . $prefix,
            );
        }
    }
}

$readme = (string) file_get_contents($root . '/README.md');
foreach (['app/admin', 'app/api', 'app/web', 'app/worker', 'app/common', 'modules/*'] as $needle) {
    expectTrue(str_contains($readme, $needle), 'README must document current architecture token: ' . $needle);
}
