<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$upPath = $root . '/database/migrations/20260908_006_miniapp_identity_session_up.sql';
$downPath = $root . '/database/migrations/20260908_006_miniapp_identity_session_down.sql';

expectTrue(is_file($upPath), 'R8A MiniApp identity/session up migration must exist');
expectTrue(is_file($downPath), 'R8A MiniApp identity/session down migration must exist');
$up = file_get_contents($upPath);
$down = file_get_contents($downPath);
expectTrue(is_string($up) && is_string($down), 'R8A schema sources must be readable');

foreach (['miniapp_provider_accounts', 'miniapp_sessions'] as $table) {
    expectTrue(str_contains($up, 'CREATE TABLE `' . $table . '`'), 'R8A migration creates ' . $table);
    expectTrue(str_contains($down, 'DROP TABLE IF EXISTS `' . $table . '`'), 'R8A rollback drops ' . $table);
}

expectSame(2, substr_count($up, 'ENGINE=InnoDB'), 'all R8A tables use InnoDB');
expectSame(2, substr_count($up, 'DEFAULT CHARSET=utf8mb4'), 'all R8A tables use utf8mb4');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_miniapp_provider_tenant_appid` (`tenant_id`,`provider_app_id`)'), 'provider appid uniqueness must be tenant-scoped');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_miniapp_sessions_token_hash` (`token_hash`)'), 'MiniApp session token hash must be unique');
expectTrue(!str_contains($up, '`session_token`'), 'raw MiniApp session token must not be persisted');
expectTrue(!str_contains($up, '`session_key` '), 'raw session_key column must not exist');
expectTrue(str_contains($up, '`session_key_ciphertext` text NOT NULL'), 'protected session key ciphertext must be stored');
expectTrue(str_contains($up, '`session_key_key_version` varchar(64) NOT NULL'), 'session key key version must be stored');
expectTrue(str_contains($up, 'CONSTRAINT `fk_miniapp_provider_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)'), 'provider config must reference account');
expectTrue(str_contains($up, 'CONSTRAINT `fk_miniapp_session_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)'), 'session must reference member');
expectTrue(str_contains($up, 'CONSTRAINT `fk_miniapp_session_external_identity` FOREIGN KEY (`external_identity_id`) REFERENCES `external_identities` (`id`)'), 'session must reference external identity');
