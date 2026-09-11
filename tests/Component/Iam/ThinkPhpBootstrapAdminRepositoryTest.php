<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

use modules\iam\contract\BootstrapAdminRepository;
use modules\iam\infrastructure\ThinkPhpBootstrapAdminRepository;
use modules\iam\security\SecureAdminIdGenerator;

$id = (new SecureAdminIdGenerator())->generate();
expectTrue(
    preg_match('/^[0-9a-f]{32}$/', $id) === 1,
    'SecureAdminIdGenerator returns a 32-character lowercase hexadecimal id',
);

expectTrue(
    is_a(ThinkPhpBootstrapAdminRepository::class, BootstrapAdminRepository::class, true),
    'ThinkPhpBootstrapAdminRepository implements BootstrapAdminRepository',
);

$root = dirname(__DIR__, 3);
$consoleConfig = (string) file_get_contents($root . '/config/console.php');
expectTrue(
    str_contains($consoleConfig, 'AdminBootstrapCommand::class'),
    'config/console.php registers AdminBootstrapCommand',
);

$appService = (string) file_get_contents($root . '/app/AppService.php');
expectTrue(
    str_contains($appService, 'BootstrapAdminRepository::class => ThinkPhpBootstrapAdminRepository::class'),
    'AppService binds BootstrapAdminRepository to ThinkPhpBootstrapAdminRepository',
);
expectTrue(
    str_contains($appService, 'AdminIdGenerator::class => SecureAdminIdGenerator::class'),
    'AppService binds AdminIdGenerator to SecureAdminIdGenerator',
);

$commandPath = $root . '/app/worker/command/AdminBootstrapCommand.php';
expectTrue(is_file($commandPath), 'AdminBootstrapCommand exists');
$command = (string) file_get_contents($commandPath);
expectTrue(str_contains($command, "setName('admin:bootstrap')"), 'AdminBootstrapCommand exposes admin:bootstrap');
expectTrue(str_contains($command, "addOption('username'"), 'AdminBootstrapCommand accepts --username');
expectTrue(!str_contains($command, "addOption('password'"), 'AdminBootstrapCommand must not accept --password');
expectTrue(
    str_contains($command, "getenv('WEPLATFORM_ADMIN_BOOTSTRAP_PASSWORD')"),
    'AdminBootstrapCommand reads CI password from environment',
);
expectTrue(str_contains($command, 'askHidden('), 'AdminBootstrapCommand uses hidden interactive password input');
