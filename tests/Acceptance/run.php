<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/AcceptanceHarnessContractTest.php';
require __DIR__ . '/FinalConsistencyContractTest.php';
require __DIR__ . '/FreshDatabaseMigrationTest.php';
require __DIR__ . '/AdminBrowserAuthRuntimeTest.php';
require __DIR__ . '/AdminBootstrapRuntimeTest.php';
require __DIR__ . '/IamRuntimeTest.php';
require __DIR__ . '/QuotaRuntimeTest.php';
require __DIR__ . '/WorkerRuntimeTest.php';
require __DIR__ . '/RecoveryRuntimeTest.php';
require __DIR__ . '/RetryRuntimeTest.php';
require __DIR__ . '/FinalConsistencyRuntimeTest.php';

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
        'WEPLATFORM_OPENPLATFORM_SECRET_KEY_VERSION' => 'acceptance-v1',
        'WEPLATFORM_OPENPLATFORM_SECRET_KEY_BASE64' => 'Motnd6Xon2P68JACN38JeDOsNtK3NYGLShIqy1rYsOY=',
        'WEPLATFORM_OPENPLATFORM_CREDENTIAL_SECRETS_JSON' => '{"acceptance-secret-ref":"acceptance-dummy-secret"}',
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

    acceptanceFinalConsistencyContractTest($root);
    fwrite(STDOUT, "[PASS] FinalConsistencyContractTest\n");

    $runtime = new AcceptanceRuntime($config);
    $runtime->preflight();

    acceptanceFreshDatabaseMigrationTest($runtime);
    fwrite(STDOUT, "[PASS] FreshDatabaseMigrationTest\n");

    acceptanceAdminBrowserAuthRuntimeTest($runtime);
    fwrite(STDOUT, "[PASS] AdminBrowserAuthRuntimeTest\n");

    acceptanceAdminBootstrapRuntimeTest($runtime);
    fwrite(STDOUT, "[PASS] AdminBootstrapRuntimeTest\n");

    acceptanceWorkerRuntimeTest($runtime);
    fwrite(STDOUT, "[PASS] WorkerRuntimeTest\n");

    acceptanceQuotaRuntimeTest($runtime);
    fwrite(STDOUT, "[PASS] QuotaRuntimeTest\n");

    acceptanceRecoveryRuntimeTest($runtime);
    fwrite(STDOUT, "[PASS] RecoveryRuntimeTest\n");

    acceptanceRetryRuntimeTest($runtime);
    fwrite(STDOUT, "[PASS] RetryRuntimeTest\n");

    acceptanceIamRuntimeTest($runtime);
    fwrite(STDOUT, "[PASS] IamRuntimeTest\n");

    acceptanceFinalConsistencyRuntimeTest($runtime);
    fwrite(STDOUT, "[PASS] FinalConsistencyRuntimeTest\n");

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
