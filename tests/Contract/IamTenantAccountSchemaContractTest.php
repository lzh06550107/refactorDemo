<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$upPath = $root . '/database/migrations/20260907_001_iam_tenant_account_up.sql';
$downPath = $root . '/database/migrations/20260907_001_iam_tenant_account_down.sql';
expectTrue(is_file($upPath), 'IAM/Tenant/Account up migration must exist');
expectTrue(is_file($downPath), 'IAM/Tenant/Account down migration must exist');

$up = file_get_contents($upPath);
$down = file_get_contents($downPath);
expectTrue(is_string($up) && is_string($down), 'migration files readable');

$tables = ['admin_users', 'admin_sessions', 'tenants', 'tenant_memberships', 'accounts', 'account_capabilities', 'legacy_mappings'];
foreach ($tables as $table) {
    expectTrue(str_contains($up, 'CREATE TABLE `' . $table . '`'), 'up creates ' . $table);
    expectTrue(str_contains($down, 'DROP TABLE IF EXISTS `' . $table . '`'), 'down drops ' . $table);
}

expectTrue(substr_count($up, 'ENGINE=InnoDB') >= count($tables), 'all new tables use InnoDB');
expectTrue(substr_count($up, 'DEFAULT CHARSET=utf8mb4') >= count($tables), 'all new tables use utf8mb4');
expectTrue(str_contains($up, '`expires_at` datetime(6) DEFAULT NULL'), 'admin user expiry is represented explicitly');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_admin_sessions_token_hash` (`token_hash`)'), 'session token hash is unique');
expectTrue(str_contains($up, 'KEY `idx_tenant_memberships_tenant` (`tenant_id`)'), 'membership tenant index');
expectTrue(str_contains($up, 'KEY `idx_accounts_tenant` (`tenant_id`)'), 'account tenant index');
expectTrue(str_contains($up, 'KEY `idx_account_capabilities_tenant` (`tenant_id`)'), 'capability tenant index');
expectTrue(str_contains($up, 'KEY `idx_legacy_mappings_tenant` (`tenant_id`)'), 'legacy mapping tenant index');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_legacy_identity` (`entity_type`,`legacy_key`,`legacy_value`)'), 'legacy id tuple is unique');
expectTrue(str_contains($up, '`legacy_key` varchar(32) NOT NULL'), 'legacy key supports uid/uniacid/acid');
expectTrue(str_contains($up, '`legacy_value` varchar(128) NOT NULL'), 'legacy value is not reused as new id');
