<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$upPath = $root . '/database/migrations/20260908_008_openplatform_authorizer_lifecycle_up.sql';
$downPath = $root . '/database/migrations/20260908_008_openplatform_authorizer_lifecycle_down.sql';

expectTrue(is_file($upPath), 'R8C authorizer lifecycle up migration must exist');
expectTrue(is_file($downPath), 'R8C authorizer lifecycle down migration must exist');

$up = is_file($upPath) ? (string) file_get_contents($upPath) : '';
$down = is_file($downPath) ? (string) file_get_contents($downPath) : '';

$tables = [
    'openplatform_authorization_intents',
    'authorizer_authorizations',
    'authorizer_access_tokens',
    'authorizer_token_refresh_leases',
];
foreach ($tables as $table) {
    expectTrue(str_contains($up, 'CREATE TABLE `' . $table . '`'), 'R8C migration creates ' . $table);
    expectTrue(str_contains($down, 'DROP TABLE IF EXISTS `' . $table . '`'), 'R8C rollback drops ' . $table);
}

expectSame(4, substr_count($up, 'ENGINE=InnoDB'), 'all four R8C tables use InnoDB');
expectTrue(str_contains($up, '`state_hash` char(64) NOT NULL'), 'authorization state is stored only as SHA-256 hash');
expectTrue(str_contains($up, '`pre_auth_code_hash` char(64) NOT NULL'), 'pre-auth code is stored only as SHA-256 hash');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_openplatform_intent_state_hash` (`state_hash`)'), 'state hash is globally unique');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_openplatform_intent_pre_auth` (`component_platform_id`,`pre_auth_code_hash`)'), 'pre-auth correlation is unique per platform');
expectTrue(str_contains($up, '`claim_holder_id` varchar(128) DEFAULT NULL'), 'intent completion claim holder is persisted');
expectTrue(str_contains($up, '`claim_expires_at` datetime(6) DEFAULT NULL'), 'intent completion claim expiry is persisted');
expectTrue(str_contains($up, '`completed_authorizer_app_id` varchar(128) DEFAULT NULL'), 'intent stores only safe completed authorizer id');

expectTrue(str_contains($up, 'PRIMARY KEY (`component_platform_id`,`authorizer_app_id`)'), 'authorizer credential state uses composite platform/AppId identity');
expectTrue(str_contains($up, '`refresh_token_ciphertext` text DEFAULT NULL'), 'authorizer refresh token is ciphertext only');
expectTrue(str_contains($up, '`refresh_token_key_version` varchar(64) DEFAULT NULL'), 'authorizer refresh token records key version');
expectTrue(str_contains($up, '`refresh_token_hash` char(64) DEFAULT NULL'), 'authorizer refresh token stores non-secret hash metadata');
expectTrue(str_contains($up, '`token_ciphertext` text NOT NULL'), 'authorizer access token is ciphertext only');
expectTrue(str_contains($up, '`token_key_version` varchar(64) NOT NULL'), 'authorizer access token records key version');
expectTrue(str_contains($up, '`holder_id` varchar(128) DEFAULT NULL'), 'authorizer refresh lease stores holder id');
expectTrue(str_contains($up, '`lease_expires_at` datetime(6) DEFAULT NULL'), 'authorizer refresh lease stores expiry');

foreach (['`state` ', '`pre_auth_code` ', '`authorization_code` ', '`authorizer_refresh_token` ', '`authorizer_access_token` '] as $plaintextColumn) {
    expectTrue(!str_contains($up, $plaintextColumn), 'plaintext secret column forbidden: ' . trim($plaintextColumn));
}

expectTrue(str_contains($up, 'REFERENCES `component_platforms` (`id`)'), 'R8C state references ComponentPlatform');
expectTrue(str_contains($up, 'REFERENCES `tenants` (`id`)'), 'authorization intent references existing Tenant');
expectTrue(str_contains($up, 'REFERENCES `accounts` (`id`)'), 'authorization intent references existing Account');
