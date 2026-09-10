<?php

declare(strict_types=1);

function acceptanceHarnessContractTest(string $root): void
{
    $run = $root . '/tests/Acceptance/run.php';
    $bootstrap = $root . '/tests/Acceptance/bootstrap.php';
    $migration = $root . '/tests/Acceptance/FreshDatabaseMigrationTest.php';
    $iam = $root . '/tests/Acceptance/IamRuntimeTest.php';
    $quota = $root . '/tests/Acceptance/QuotaRuntimeTest.php';

    foreach ([$run, $bootstrap, $migration, $iam, $quota] as $file) {
        acceptanceAssert(is_file($file), 'acceptance runtime harness file must exist: ' . basename($file));
    }

    $runSource = (string) file_get_contents($run);
    $bootstrapSource = (string) file_get_contents($bootstrap);
    $quotaSource = (string) file_get_contents($quota);
    acceptanceAssert(str_contains($bootstrapSource, 'WEPLATFORM_ACCEPTANCE'), 'acceptance harness requires explicit opt-in');
    acceptanceAssert(str_contains($bootstrapSource, "['127.0.0.1', 'localhost', '::1']"), 'acceptance database is local-only');
    acceptanceAssert(str_contains($bootstrapSource, "str_contains(\$databaseLower, 'acceptance')"), 'acceptance database name is guarded');
    acceptanceAssert(str_contains($bootstrapSource, "str_ends_with(\$databaseLower, '_test')"), 'test database suffix is guarded');
    acceptanceAssert(str_contains($bootstrapSource, 'DROP DATABASE IF EXISTS'), 'fresh acceptance database is recreated explicitly');
    acceptanceAssert(str_contains($runSource, 'FreshDatabaseMigrationTest.php'), 'acceptance runner executes fresh migration gate');
    acceptanceAssert(str_contains($runSource, 'IamRuntimeTest.php'), 'acceptance runner executes IAM runtime gate');
    acceptanceAssert(str_contains($runSource, 'QuotaRuntimeTest.php'), 'acceptance runner executes quota runtime gate');
    acceptanceAssert(str_contains($runSource, 'acceptanceQuotaRuntimeTest($runtime)'), 'quota runtime gate is invoked explicitly');
    acceptanceAssert(!str_contains($runSource, 'WEPLATFORM_OPENPLATFORM_CREDENTIAL_SECRETS_JSON'), 'IAM/quota acceptance gate does not require provider credentials');
    acceptanceAssert(str_contains($runSource, '$exitCode = 0;'), 'acceptance runner tracks exit code without exiting before cleanup');
    $finallyPos = strpos($runSource, '} finally {');
    $exitPos = strrpos($runSource, 'exit($exitCode);');
    acceptanceAssert(is_int($finallyPos) && is_int($exitPos) && $exitPos > $finallyPos, 'acceptance runner exits only after finally cleanup');
    acceptanceAssert(str_contains($runSource, "[FAIL] Acceptance cleanup failed:"), 'cleanup failure makes the acceptance gate fail');

    foreach ([
        'PHP_ENV_NAME',
        'PHP_DATABASE_HOSTNAME',
        'PHP_DATABASE_DATABASE',
        'PHP_DATABASE_USERNAME',
        'PHP_DATABASE_PASSWORD',
        'PHP_DATABASE_HOSTPORT',
        'PHP_DATABASE_CHARSET',
        'PHP_WEPLATFORM_ADMIN_SESSION_PEPPER',
    ] as $name) {
        acceptanceAssert(str_contains($runSource, $name), 'ThinkPHP acceptance environment must explicitly isolate: ' . $name);
    }
    acceptanceAssert(str_contains($runSource, "\$_ENV['ENV_NAME'] = \$environmentName"), 'in-process ThinkPHP environment is isolated from project .env');
    $environmentPos = strpos($runSource, 'acceptanceApplyThinkPhpEnvironment($config);');
    $preflightPos = strpos($runSource, '$runtime->preflight();');
    acceptanceAssert(is_int($environmentPos) && is_int($preflightPos) && $environmentPos < $preflightPos, 'ThinkPHP acceptance environment is applied before runtime boot');

    acceptanceAssert(str_contains($quotaSource, 'QuotaService::class'), 'quota acceptance resolves the production QuotaService from ThinkPHP');
    acceptanceAssert(str_contains($quotaSource, '->grant('), 'quota acceptance exercises real grant behavior');
    acceptanceAssert(str_contains($quotaSource, '->consume('), 'quota acceptance exercises real consume behavior');
    acceptanceAssert(str_contains($quotaSource, '->release('), 'quota acceptance exercises real release behavior');
    acceptanceAssert(!str_contains($quotaSource, 'INSERT INTO quota_balances'), 'quota acceptance must not seed balance projection directly');
    acceptanceAssert(!str_contains($quotaSource, 'UPDATE quota_balances'), 'quota acceptance must not mutate balance projection directly');
    acceptanceAssert(!str_contains($quotaSource, 'INSERT INTO quota_ledger_entries'), 'quota acceptance must not seed ledger entries directly');
}
