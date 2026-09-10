<?php

declare(strict_types=1);

function acceptanceHarnessContractTest(string $root): void
{
    $run = $root . '/tests/Acceptance/run.php';
    $bootstrap = $root . '/tests/Acceptance/bootstrap.php';
    $migration = $root . '/tests/Acceptance/FreshDatabaseMigrationTest.php';
    $iam = $root . '/tests/Acceptance/IamRuntimeTest.php';

    foreach ([$run, $bootstrap, $migration, $iam] as $file) {
        acceptanceAssert(is_file($file), 'acceptance runtime harness file must exist: ' . basename($file));
    }

    $runSource = (string) file_get_contents($run);
    $bootstrapSource = (string) file_get_contents($bootstrap);
    acceptanceAssert(str_contains($bootstrapSource, 'WEPLATFORM_ACCEPTANCE'), 'acceptance harness requires explicit opt-in');
    acceptanceAssert(str_contains($bootstrapSource, "['127.0.0.1', 'localhost', '::1']"), 'acceptance database is local-only');
    acceptanceAssert(str_contains($bootstrapSource, "str_contains(\$databaseLower, 'acceptance')"), 'acceptance database name is guarded');
    acceptanceAssert(str_contains($bootstrapSource, "str_ends_with(\$databaseLower, '_test')"), 'test database suffix is guarded');
    acceptanceAssert(str_contains($bootstrapSource, 'DROP DATABASE IF EXISTS'), 'fresh acceptance database is recreated explicitly');
    acceptanceAssert(str_contains($runSource, 'FreshDatabaseMigrationTest.php'), 'acceptance runner executes fresh migration gate');
    acceptanceAssert(str_contains($runSource, 'IamRuntimeTest.php'), 'acceptance runner executes IAM runtime gate');
    acceptanceAssert(!str_contains($runSource, 'WEPLATFORM_OPENPLATFORM_CREDENTIAL_SECRETS_JSON'), 'IAM acceptance gate does not require provider credentials');
    acceptanceAssert(str_contains($runSource, '$exitCode = 0;'), 'acceptance runner tracks exit code without exiting before cleanup');
    $finallyPos = strpos($runSource, '} finally {');
    $exitPos = strrpos($runSource, 'exit($exitCode);');
    acceptanceAssert(is_int($finallyPos) && is_int($exitPos) && $exitPos > $finallyPos, 'acceptance runner exits only after finally cleanup');
    acceptanceAssert(str_contains($runSource, "[FAIL] Acceptance cleanup failed:"), 'cleanup failure makes the acceptance gate fail');
}
