<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/AcceptanceHarnessContractTest.php';
require __DIR__ . '/FreshDatabaseMigrationTest.php';
require __DIR__ . '/IamRuntimeTest.php';

$runtime = null;
$exitCode = 0;
try {
    $config = AcceptanceConfig::load($root);

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
