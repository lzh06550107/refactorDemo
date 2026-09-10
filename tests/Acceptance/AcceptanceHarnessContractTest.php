<?php

declare(strict_types=1);

function acceptanceHarnessContractTest(string $root): void
{
    $run = $root . '/tests/Acceptance/run.php';
    $bootstrap = $root . '/tests/Acceptance/bootstrap.php';
    $migration = $root . '/tests/Acceptance/FreshDatabaseMigrationTest.php';
    $iam = $root . '/tests/Acceptance/IamRuntimeTest.php';
    $quota = $root . '/tests/Acceptance/QuotaRuntimeTest.php';
    $worker = $root . '/tests/Acceptance/WorkerRuntimeTest.php';

    foreach ([$run, $bootstrap, $migration, $iam, $quota, $worker] as $file) {
        acceptanceAssert(is_file($file), 'acceptance runtime harness file must exist: ' . basename($file));
    }

    $runSource = (string) file_get_contents($run);
    $bootstrapSource = (string) file_get_contents($bootstrap);
    $quotaSource = (string) file_get_contents($quota);
    $workerSource = (string) file_get_contents($worker);
    acceptanceAssert(str_contains($bootstrapSource, 'WEPLATFORM_ACCEPTANCE'), 'acceptance harness requires explicit opt-in');
    acceptanceAssert(str_contains($bootstrapSource, "['127.0.0.1', 'localhost', '::1']"), 'acceptance database is local-only');
    acceptanceAssert(str_contains($bootstrapSource, "str_contains(\$databaseLower, 'acceptance')"), 'acceptance database name is guarded');
    acceptanceAssert(str_contains($bootstrapSource, "str_ends_with(\$databaseLower, '_test')"), 'test database suffix is guarded');
    acceptanceAssert(str_contains($bootstrapSource, 'DROP DATABASE IF EXISTS'), 'fresh acceptance database is recreated explicitly');
    acceptanceAssert(str_contains($runSource, 'FreshDatabaseMigrationTest.php'), 'acceptance runner executes fresh migration gate');
    acceptanceAssert(str_contains($runSource, 'IamRuntimeTest.php'), 'acceptance runner executes IAM runtime gate');
    acceptanceAssert(str_contains($runSource, 'QuotaRuntimeTest.php'), 'acceptance runner executes quota runtime gate');
    acceptanceAssert(str_contains($runSource, 'acceptanceQuotaRuntimeTest($runtime)'), 'quota runtime gate is invoked explicitly');
    acceptanceAssert(str_contains($runSource, 'WorkerRuntimeTest.php'), 'acceptance runner executes worker runtime gate');
    acceptanceAssert(str_contains($runSource, 'acceptanceWorkerRuntimeTest($runtime)'), 'worker runtime gate is invoked explicitly');
    acceptanceAssert(str_contains($runSource, 'WEPLATFORM_OPENPLATFORM_SECRET_KEY_BASE64'), 'worker acceptance receives isolated dummy secret key');
    acceptanceAssert(str_contains($runSource, 'WEPLATFORM_OPENPLATFORM_CREDENTIAL_SECRETS_JSON'), 'worker acceptance receives isolated dummy credential map');
    acceptanceAssert(str_contains($runSource, '$exitCode = 0;'), 'acceptance runner tracks exit code without exiting before cleanup');
    $finallyPos = strpos($runSource, '} finally {');
    $exitPos = strrpos($runSource, 'exit($exitCode);');
    acceptanceAssert(is_int($finallyPos) && is_int($exitPos) && $exitPos > $finallyPos, 'acceptance runner exits only after finally cleanup');
    acceptanceAssert(str_contains($runSource, "[FAIL] Acceptance cleanup failed:"), 'cleanup failure makes the acceptance gate fail');

    acceptanceAssert(str_contains($runSource, 'PHP_ENV_NAME'), 'ThinkPHP acceptance process uses an isolated environment name');
    foreach ([
        'DATABASE_HOSTNAME',
        'DATABASE_DATABASE',
        'DATABASE_USERNAME',
        'DATABASE_PASSWORD',
        'DATABASE_HOSTPORT',
        'DATABASE_CHARSET',
        'WEPLATFORM_ADMIN_SESSION_PEPPER',
    ] as $name) {
        acceptanceAssert(str_contains($runSource, "'" . $name . "' =>"), 'ThinkPHP acceptance environment supplies: ' . $name);
    }
    acceptanceAssert(str_contains($runSource, "putenv('PHP_' . \$name . '=' . \$value);"), 'ThinkPHP acceptance environment exports PHP_* overrides for child processes');
    acceptanceAssert(str_contains($runSource, "\$_ENV[\$name] = \$value;"), 'ThinkPHP acceptance environment supplies in-process overrides');
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

    acceptanceAssert(str_contains($workerSource, 'openplatform:provisioning-worker'), 'worker acceptance invokes the production worker command');
    acceptanceAssert(str_contains($workerSource, '--once'), 'worker acceptance uses bounded one-batch mode');
    acceptanceAssert(str_contains($workerSource, 'discovered=0 handled=0 failed=0'), 'worker acceptance asserts empty-queue result');
    acceptanceAssert(!str_contains($workerSource, 'INSERT INTO authorizer_provisioning_jobs'), 'empty-queue worker acceptance must not seed jobs');
    acceptanceAssert(!str_contains($workerSource, 'component_platforms'), 'empty-queue worker acceptance must not seed provider configuration');
}
