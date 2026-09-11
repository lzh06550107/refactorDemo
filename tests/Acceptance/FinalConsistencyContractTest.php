<?php

declare(strict_types=1);

function acceptanceFinalConsistencyContractTest(string $root): void
{
    $runtimePath = $root . '/tests/Acceptance/FinalConsistencyRuntimeTest.php';
    $runPath = $root . '/tests/Acceptance/run.php';

    acceptanceAssert(is_file($runtimePath), 'Final consistency runtime gate must exist.');
    acceptanceAssert(is_file($runPath), 'Acceptance runner must exist.');

    $source = (string) file_get_contents($runtimePath);
    $runSource = (string) file_get_contents($runPath);

    acceptanceAssert(str_contains($source, 'authorizer_provisionings'), 'final consistency audits provisioning state');
    acceptanceAssert(str_contains($source, 'authorizer_provisioning_jobs'), 'final consistency audits durable jobs');
    acceptanceAssert(str_contains($source, 'authorizer_account_ownerships'), 'final consistency audits canonical ownership');
    acceptanceAssert(str_contains($source, 'miniapp_provider_accounts'), 'final consistency audits mini-program provider bindings');
    acceptanceAssert(str_contains($source, 'official_account_provider_accounts'), 'final consistency audits official-account provider bindings');
    acceptanceAssert(str_contains($source, 'quota_ledger_entries'), 'final consistency audits quota ledger references');
    acceptanceAssert(str_contains($source, 'quota_balances'), 'final consistency audits quota balance projection');

    foreach (['INSERT INTO', 'UPDATE ', 'DELETE FROM', 'DROP ', 'CREATE ', 'ALTER '] as $writeSql) {
        acceptanceAssert(!str_contains($source, $writeSql), 'final consistency gate must stay read-only: ' . $writeSql);
    }

    acceptanceAssert(str_contains($runSource, 'FinalConsistencyRuntimeTest.php'), 'acceptance runner loads final consistency runtime gate');
    acceptanceAssert(str_contains($runSource, 'acceptanceFinalConsistencyRuntimeTest($runtime)'), 'acceptance runner invokes final consistency runtime gate');
    $iamPos = strpos($runSource, 'acceptanceIamRuntimeTest($runtime);');
    $finalPos = strpos($runSource, 'acceptanceFinalConsistencyRuntimeTest($runtime);');
    $finallyPos = strpos($runSource, '} finally {');
    acceptanceAssert(
        is_int($iamPos) && is_int($finalPos) && is_int($finallyPos)
        && $iamPos < $finalPos && $finalPos < $finallyPos,
        'final consistency runtime gate must run after scenario tests and before cleanup',
    );
}
