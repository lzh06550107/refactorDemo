<?php

declare(strict_types=1);

(static function (): void {
    $root = dirname(__DIR__, 2);

    foreach ([
        '/app/adminui/controller/SpaController.php' => 'Admin UI SPA controller is required',
        '/app/adminui/route/app.php' => 'Admin UI SPA routes are required',
        '/app/worker/command/AdminBootstrapCommand.php' => 'Initial administrator bootstrap command is required',
        '/modules/iam/application/BootstrapFirstAdmin.php' => 'Initial administrator bootstrap application service is required',
        '/modules/iam/infrastructure/ThinkPhpBootstrapAdminRepository.php' => 'Initial administrator bootstrap MySQL repository is required',
        '/frontend/admin/e2e/package.json' => 'Admin browser E2E package is required',
        '/frontend/admin/e2e/package-lock.json' => 'Admin browser E2E lockfile is required',
        '/frontend/admin/e2e/playwright.config.js' => 'Admin Playwright configuration is required',
        '/frontend/admin/e2e/admin-login.e2e.js' => 'Admin production browser E2E specification is required',
    ] as $relative => $message) {
        if (!is_file($root . $relative)) {
            throw new RuntimeException($message . ': ' . $relative);
        }
    }

    /** @var array<string,mixed> $appConfig */
    $appConfig = require $root . '/config/app.php';
    $appMap = $appConfig['app_map'] ?? null;
    if (!is_array($appMap)) {
        throw new RuntimeException('config/app.php app_map must be an array');
    }

    $adminUiAlias = $appMap['admin'] ?? null;
    if (!is_callable($adminUiAlias) || $adminUiAlias(null) !== 'adminui') {
        throw new RuntimeException('config/app.php must map admin to adminui through a callable alias');
    }

    $adminApiAlias = $appMap['admin-api'] ?? null;
    if (!is_callable($adminApiAlias) || $adminApiAlias(null) !== 'admin') {
        throw new RuntimeException('config/app.php must keep admin-api mapped to admin through a callable alias');
    }

    $consoleConfig = (string) file_get_contents($root . '/config/console.php');
    if (!str_contains($consoleConfig, 'AdminBootstrapCommand::class')) {
        throw new RuntimeException('ThinkPHP console must register AdminBootstrapCommand');
    }

    $provider = (string) file_get_contents($root . '/app/provider.php');
    foreach ([
        'BootstrapAdminRepository::class => ThinkPhpBootstrapAdminRepository::class' => 'BootstrapAdminRepository binding is required',
        'AdminIdGenerator::class => SecureAdminIdGenerator::class' => 'AdminIdGenerator binding is required',
    ] as $needle => $message) {
        if (!str_contains($provider, $needle)) {
            throw new RuntimeException($message);
        }
    }

    $viteConfig = (string) file_get_contents($root . '/frontend/admin/vite.config.ts');
    foreach ([
        "base: '/admin/'" => 'Admin Vite base must remain /admin/',
        "outDir: '../../public/admin'" => 'Admin production build must target public/admin',
    ] as $needle => $message) {
        if (!str_contains($viteConfig, $needle)) {
            throw new RuntimeException($message);
        }
    }

    $gitignore = (string) file_get_contents($root . '/.gitignore');
    foreach ([
        'public/admin/' => 'Generated Admin production output must be ignored',
        'frontend/admin/e2e/node_modules/' => 'Admin E2E node_modules must be ignored',
        'frontend/admin/e2e/playwright-report/' => 'Admin E2E Playwright report must be ignored',
        'frontend/admin/e2e/test-results/' => 'Admin E2E test results must be ignored',
    ] as $needle => $message) {
        if (!str_contains($gitignore, $needle)) {
            throw new RuntimeException($message);
        }
    }

    $repository = (string) file_get_contents($root . '/modules/iam/infrastructure/ThinkPhpBootstrapAdminRepository.php');
    foreach ([
        "GET_LOCK('" => 'Bootstrap repository must acquire a MySQL advisory lock',
        'weplatform:admin-bootstrap' => 'Bootstrap repository must use the fixed advisory lock name',
        'RELEASE_LOCK(' => 'Bootstrap repository must release the MySQL advisory lock',
        "table('admin_users')->count()" => 'Bootstrap repository must check for an existing administrator while serialized',
    ] as $needle => $message) {
        if (!str_contains($repository, $needle)) {
            throw new RuntimeException($message);
        }
    }

    $command = (string) file_get_contents($root . '/app/worker/command/AdminBootstrapCommand.php');
    foreach ([
        "setName('admin:bootstrap')" => 'Admin bootstrap command name is required',
        "addOption('username'" => 'Admin bootstrap command must accept --username',
        "getenv('WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD')" => 'Admin bootstrap automation password must come from the environment',
        'askHidden(' => 'Admin bootstrap interactive password must be hidden',
    ] as $needle => $message) {
        if (!str_contains($command, $needle)) {
            throw new RuntimeException($message);
        }
    }
    if (str_contains($command, "addOption('password'")) {
        throw new RuntimeException('Admin bootstrap command must never expose a --password option');
    }

    $package = json_decode(
        (string) file_get_contents($root . '/frontend/admin/e2e/package.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    if (($package['devDependencies']['@playwright/test'] ?? null) !== '1.63.0') {
        throw new RuntimeException('Admin browser E2E must pin @playwright/test 1.63.0');
    }
    if (($package['scripts']['test'] ?? null) !== 'playwright test') {
        throw new RuntimeException('Admin browser E2E npm test must run playwright test');
    }

    $playwright = (string) file_get_contents($root . '/frontend/admin/e2e/playwright.config.js');
    foreach ([
        'admin-login.e2e.js' => 'Admin Playwright testMatch must select admin-login.e2e.js',
        'http://127.0.0.1:18080' => 'Admin Playwright must target the real ThinkPHP origin',
        'php ../../../think run -p 18080' => 'Admin Playwright must start the real ThinkPHP server',
        "trace: 'retain-on-failure'" => 'Admin Playwright must retain traces on failure',
        "screenshot: 'only-on-failure'" => 'Admin Playwright must capture screenshots on failure',
    ] as $needle => $message) {
        if (!str_contains($playwright, $needle)) {
            throw new RuntimeException($message);
        }
    }

    $browserSpec = (string) file_get_contents($root . '/frontend/admin/e2e/admin-login.e2e.js');
    foreach ([
        '/admin/login' => 'Admin browser E2E must open the production login route',
        'e2e-admin' => 'Admin browser E2E must use the bootstrapped administrator',
        'page.reload()' => 'Admin browser E2E must verify session restoration after reload',
        '退出登录' => 'Admin browser E2E must verify logout',
    ] as $needle => $message) {
        if (!str_contains($browserSpec, $needle)) {
            throw new RuntimeException($message);
        }
    }

    $ci = (string) file_get_contents($root . '/.github/workflows/ci.yml');
    if (!str_contains($ci, "'admin:/admin-api/health'")) {
        throw new RuntimeException('CI multi-app smoke must probe Admin API health through /admin-api/health');
    }
    if (str_contains($ci, "'admin:/admin/health'")) {
        throw new RuntimeException('CI multi-app smoke must not probe Admin API health through the /admin UI prefix');
    }
    foreach ([
        'frontend/admin/e2e/package-lock.json' => 'CI cache must include the Admin E2E lockfile',
        'npm ci --prefix frontend/admin/e2e' => 'CI must install locked Admin browser E2E dependencies',
        'Test Admin production browser E2E' => 'CI must contain the permanent Admin production browser gate',
        'WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD' => 'CI must bootstrap the browser-test administrator without a command-line password',
        'php think admin:bootstrap --username=e2e-admin' => 'CI must bootstrap a real administrator before Admin browser E2E',
        'mysql:8.4' => 'Admin production browser E2E must run with a real MySQL 8.4 service',
    ] as $needle => $message) {
        if (!str_contains($ci, $needle)) {
            throw new RuntimeException($message);
        }
    }
    if (substr_count($ci, 'weplatform_admin_browser_test') < 2) {
        throw new RuntimeException('Admin browser E2E must use the fail-closed test database name weplatform_admin_browser_test for both runtime and ThinkPHP environments');
    }
    if (str_contains($ci, 'weplatform_admin_browser_e2e')) {
        throw new RuntimeException('Admin browser E2E database name must satisfy the acceptance safety policy');
    }

    $buildAt = strpos($ci, 'npm run build --prefix frontend/admin');
    $browserAt = strpos($ci, 'Test Admin production browser E2E');
    if ($buildAt === false || $browserAt === false || $buildAt >= $browserAt) {
        throw new RuntimeException('Admin production build must run before Admin production browser E2E');
    }
})();
