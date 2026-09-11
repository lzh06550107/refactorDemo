<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$upPath = $root . '/database/migrations/20260909_009_openplatform_authorizer_provisioning_up.sql';
$downPath = $root . '/database/migrations/20260909_009_openplatform_authorizer_provisioning_down.sql';

expectTrue(is_file($upPath), 'R8D provisioning up migration must exist');
expectTrue(is_file($downPath), 'R8D provisioning down migration must exist');

$up = is_file($upPath) ? (string) file_get_contents($upPath) : '';
$down = is_file($downPath) ? (string) file_get_contents($downPath) : '';

expectTrue(str_contains($up, 'ADD COLUMN `intent_mode`'), 'R8D adds explicit authorization intent mode');
expectTrue(str_contains($up, "'bind_existing_account'"), 'R8C intents are backfilled as bind-existing mode');
expectTrue(str_contains($up, 'MODIFY COLUMN `target_account_id` varchar(64) DEFAULT NULL'), 'R8D makes target Account nullable');
expectTrue(str_contains($up, 'CONSTRAINT `chk_openplatform_intent_mode_target` CHECK'), 'database enforces intent mode/target invariant');
expectTrue(str_contains($up, "`intent_mode` = 'bind_existing_account' AND `target_account_id` IS NOT NULL"), 'bind-existing mode requires a target Account');
expectTrue(str_contains($up, "`intent_mode` = 'auto_provision_account' AND `target_account_id` IS NULL"), 'auto-provision mode forbids a target Account');

$tables = [
    'authorizer_metadata_current',
    'authorizer_metadata_snapshots',
    'authorizer_account_ownerships',
    'official_account_provider_accounts',
    'authorizer_provisionings',
    'authorizer_provisioning_jobs',
];
foreach ($tables as $table) {
    expectTrue(str_contains($up, 'CREATE TABLE `' . $table . '`'), 'R8D migration creates ' . $table);
    expectTrue(str_contains($down, 'DROP TABLE IF EXISTS `' . $table . '`'), 'R8D rollback drops ' . $table);
}
expectSame(6, substr_count($up, 'ENGINE=InnoDB'), 'all six new R8D tables use InnoDB');

expectTrue(str_contains($up, 'PRIMARY KEY (`component_platform_id`,`authorizer_app_id`)'), 'current metadata/ownership use canonical authorizer identity');
expectTrue(str_contains($up, '`metadata_hash` char(64) NOT NULL'), 'trusted metadata stores semantic SHA-256 hash');
expectTrue(str_contains($up, '`normalized_metadata_json` json NOT NULL'), 'metadata persistence stores normalized whitelisted projection');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_authorizer_metadata_snapshot_version` (`component_platform_id`,`authorizer_app_id`,`version`)'), 'metadata snapshot version is unique per canonical authorizer');
expectTrue(!str_contains($up, 'UNIQUE KEY `uk_authorizer_metadata_snapshot_hash`'), 'metadata hash is not unique so A-B-A history remains representable');

expectTrue(str_contains($up, 'PRIMARY KEY (`component_platform_id`,`authorizer_app_id`)'), 'ownership is globally keyed by canonical authorizer');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_authorizer_ownership_account` (`account_id`)'), 'one Account cannot own multiple canonical OpenPlatform authorizers through ownership rows');
expectTrue(str_contains($up, '`account_type` varchar(64) NOT NULL'), 'ownership freezes the bound Account type');

expectTrue(str_contains($up, 'CREATE TABLE `official_account_provider_accounts`'), 'Official Account provider configuration is separate from Mini Program configuration');
expectTrue(str_contains($up, 'PRIMARY KEY (`account_id`)'), 'Official Account provider configuration is one-to-one with Account');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_official_account_provider_tenant_appid` (`tenant_id`,`provider_app_id`)'), 'Official Account AppId is unique inside a Tenant');
expectTrue(str_contains($up, 'CONSTRAINT `chk_official_account_connection_mode` CHECK'), 'Official Account binding enforces manual/component credential shape');
expectTrue(str_contains($up, 'REFERENCES `component_platforms` (`id`)'), 'R8D provider-owned records reference ComponentPlatform');

expectTrue(str_contains($up, 'UNIQUE KEY `uk_authorizer_provisioning_source_intent` (`source_intent_id`)'), 'one auto-provisioning workflow exists per source intent');
expectTrue(str_contains($up, 'KEY `idx_authorizer_provisioning_canonical_tenant` (`component_platform_id`,`authorizer_app_id`,`tenant_id`)'), 'provisioning supports canonical authorizer/Tenant lookup without forbidding historical attempts');
expectTrue(str_contains($up, '`account_type` varchar(64) DEFAULT NULL'), 'provisioning AccountType is nullable before trusted metadata is ready');
expectTrue(str_contains($up, '`quota_consume_entry_id` varchar(64) DEFAULT NULL'), 'provisioning persists quota consume recovery reference');
expectTrue(str_contains($up, '`quota_release_entry_id` varchar(64) DEFAULT NULL'), 'provisioning persists quota compensation reference');

expectTrue(str_contains($up, 'CREATE TABLE `authorizer_provisioning_jobs`'), 'R8D creates durable provisioning jobs');
expectTrue(str_contains($up, 'PRIMARY KEY (`provisioning_id`)'), 'there is exactly one durable job per provisioning');
expectTrue(str_contains($up, '`claim_holder_id` varchar(128) DEFAULT NULL'), 'job lease holder is durable');
expectTrue(str_contains($up, '`claim_expires_at` datetime(6) DEFAULT NULL'), 'job lease expiry is durable and recoverable');
expectTrue(str_contains($up, '`attempt_count` int unsigned NOT NULL DEFAULT 0'), 'job retry count is durable');
expectTrue(str_contains($up, '`next_attempt_at` datetime(6) NOT NULL'), 'job next retry time is durable');

$permissions = [
    'openplatform.authorizer.read',
    'openplatform.authorizer.start',
    'openplatform.authorizer.bind',
    'openplatform.authorizer.provision',
    'openplatform.authorizer.refresh_metadata',
    'openplatform.authorizer.retry_provision',
];
foreach ($permissions as $permission) {
    expectTrue(str_contains($up, "'" . $permission . "'"), 'R8D registers permission ' . $permission);
    expectTrue(str_contains($down, "'" . $permission . "'"), 'R8D rollback removes permission ' . $permission);
}
expectTrue(!str_contains($up, 'INSERT INTO `role_permissions`'), 'R8D migration never silently grants permissions to existing roles');

$expectedDropOrder = [
    'authorizer_provisioning_jobs',
    'authorizer_provisionings',
    'official_account_provider_accounts',
    'authorizer_account_ownerships',
    'authorizer_metadata_snapshots',
    'authorizer_metadata_current',
];
$previous = -1;
foreach ($expectedDropOrder as $table) {
    $position = strpos($down, 'DROP TABLE IF EXISTS `' . $table . '`');
    expectTrue($position !== false && $position > $previous, 'rollback drops ' . $table . ' in dependency-safe order');
    $previous = is_int($position) ? $position : $previous;
}
expectTrue(str_contains($down, 'DROP CHECK `chk_openplatform_intent_mode_target`'), 'rollback removes the intent mode/target check');
expectTrue(str_contains($down, 'DROP COLUMN `intent_mode`'), 'rollback removes R8D intent mode column');
