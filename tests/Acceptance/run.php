<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/AcceptanceHarnessContractTest.php';
require __DIR__ . '/FreshDatabaseMigrationTest.php';
require __DIR__ . '/IamRuntimeTest.php';

function acceptanceApplyThinkPhpEnvironment(AcceptanceConfig $config): void
{
    $environmentName = 'weplatform_acceptance_' . bin2hex(random_bytes(8));
    $_ENV['ENV_NAME'] = $environmentName;
    putenv('PHP_ENV_NAME=' . $environmentName);

    $values = [
        'DATABASE_HOSTNAME' => $config->host,
        'DATABASE_DATABASE' => $config->database,
        'DATABASE_USERNAME' => $config->username,
        'DATABASE_PASSWORD' => $config->password,
        'DATABASE_HOSTPORT' => (string) $config->port,
        'DATABASE_CHARSET' => 'utf8mb4',
        'WEPLATFORM_ADMIN_SESSION_PEPPER' => $config->pepper,
    ];
    foreach ($values as $name => $value) {
        $_ENV[$name] = $value;
        putenv('PHP_' . $name . '=' . $value);
    }
}

$runtime = null;
$exitCode = 0;
try {
    $config = AcceptanceConfig::load($root);
    acceptanceApplyThinkPhpEnvironment($config);

    acceptanceHarnessContractTest($root);
    fwrite(STDOUT, "[PASS] AcceptanceHarnessContractTest\n");

    $runtime = new AcceptanceRuntime($config);
    $runtime->preflight();

    acceptanceFreshDatabaseMigrationTest($runtime);
    fwrite(STDOUT, "[PASS] FreshDatabaseMigrationTest\n");

    acceptanceIamRuntimeTest($runtime);
    fwrite(STDOUT, "[PASS] IamRuntimeTest\n");

    fwrite(STDOUT, "[PASS] Local acceptance runtime gate\n");
} catch (Throwable $e) {
    $exitCode = 1;
    fwrite(STDERR, '[FAIL] Local acceptance runtime gate: ' . $e->getMessage() . PHP_EOL);
} finally {
    if ($runtime instanceof AcceptanceRuntime) {
        try {
            $runtime->stopServer();
            $runtime->cleanupDatabase();
        } catch (Throwable $cleanupError) {
            $exitCode = 1;
            fwrite(STDERR, '[FAIL] Acceptance cleanup failed: ' . $cleanupError->getMessage() . PHP_EOL);
        }
    }
}

exit($exitCode);
