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
    if (!str_contains($gitignore, 'public/admin/')) {
        throw new RuntimeException('Generated Admin production output must be ignored');
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
})();
