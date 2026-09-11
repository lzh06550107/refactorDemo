<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$upPath = $root . '/database/schema/v1/20260907_003_entitlement_quota_up.sql';
$downPath = $root . '/database/schema/v1/20260907_003_entitlement_quota_down.sql';
$repoPath = $root . '/modules/quota/infrastructure/ThinkPhpQuotaLedgerRepository.php';

expectTrue(is_file($upPath), 'R5 entitlement/quota up migration must exist');
expectTrue(is_file($downPath), 'R5 entitlement/quota down migration must exist');
$up = file_get_contents($upPath);
$down = file_get_contents($downPath);
$repositorySource = file_get_contents($repoPath);
expectTrue(is_string($up) && is_string($down) && is_string($repositorySource), 'R5 migration and repository sources must be readable');

foreach ([
    'tenant_module_entitlements',
    'quota_parent_pools',
    'quota_grants',
    'quota_balances',
    'quota_ledger_entries',
] as $table) {
    expectTrue(str_contains($up, 'CREATE TABLE `' . $table . '`'), 'R5 up migration must create ' . $table);
    expectTrue(str_contains($down, 'DROP TABLE IF EXISTS `' . $table . '`'), 'R5 down migration must drop ' . $table);
}
expectSame(5, substr_count($up, 'ENGINE=InnoDB'), 'all R5 tables must use InnoDB');
expectSame(5, substr_count($up, 'DEFAULT CHARSET=utf8mb4'), 'all R5 tables must use utf8mb4');
expectTrue(str_contains($up, 'uk_quota_balances_tenant_resource'), 'quota balance concurrency anchor must be unique per tenant/resource');
expectTrue(str_contains($up, 'uk_quota_ledger_idempotency'), 'quota ledger must enforce database-level idempotency');
expectTrue(str_contains($up, '`purchase_granted_amount`'), 'quota balance must track purchased grants separately');
expectTrue(str_contains($up, '`parent_pool_charge`'), 'quota ledger must persist parent pool charge allocation');
expectTrue(str_contains($repositorySource, 'Db::transaction'), 'quota writes must execute inside transactions');
expectTrue(str_contains($repositorySource, '->lock(true)'), 'quota mutation path must use row locks');
expectTrue(str_contains($repositorySource, 'ON DUPLICATE KEY UPDATE'), 'first grant must upsert the unique balance lock anchor');
