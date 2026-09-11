<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$upPath = $root . '/database/schema/v1/20260908_005_member_oauth_webhook_up.sql';
$downPath = $root . '/database/schema/v1/20260908_005_member_oauth_webhook_down.sql';

expectTrue(is_file($upPath), 'R7 member/oauth/webhook up migration must exist');
expectTrue(is_file($downPath), 'R7 member/oauth/webhook down migration must exist');
$up = file_get_contents($upPath);
$down = file_get_contents($downPath);
expectTrue(is_string($up) && is_string($down), 'R7 schema sources must be readable');

foreach (['members','external_identities','oauth_bindings','oauth_states','webhook_inbox'] as $table) {
    expectTrue(str_contains($up, 'CREATE TABLE `' . $table . '`'), 'R7 migration creates ' . $table);
    expectTrue(str_contains($down, 'DROP TABLE IF EXISTS `' . $table . '`'), 'R7 rollback drops ' . $table);
}

expectSame(5, substr_count($up, 'ENGINE=InnoDB'), 'all R7 tables use InnoDB');
expectSame(5, substr_count($up, 'DEFAULT CHARSET=utf8mb4'), 'all R7 tables use utf8mb4');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_external_identities_provider_subject` (`provider_type`,`provider_account_id`,`external_subject`)'), 'external identity uniqueness must be provider-account scoped');
expectTrue(!str_contains($up, 'UNIQUE KEY `uk_external_identities_openid`'), 'R7 must not reintroduce global openid uniqueness');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_oauth_states_nonce_hash` (`nonce_hash`)'), 'oauth state nonce hash must be unique');
expectTrue(str_contains($up, 'UNIQUE KEY `uk_webhook_inbox_provider_event` (`provider_type`,`provider_account_id`,`provider_event_key`)'), 'webhook event uniqueness must be provider-account scoped');
expectTrue(str_contains($up, 'CONSTRAINT `fk_external_identities_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)'), 'external identities must reference members');
expectTrue(str_contains($up, 'CONSTRAINT `fk_external_identities_provider_account` FOREIGN KEY (`provider_account_id`) REFERENCES `accounts` (`id`)'), 'external identities must reference provider accounts');
expectTrue(str_contains($up, 'CONSTRAINT `fk_oauth_bindings_business_account` FOREIGN KEY (`business_account_id`) REFERENCES `accounts` (`id`)'), 'oauth binding must reference business account');
expectTrue(str_contains($up, 'CONSTRAINT `fk_oauth_bindings_provider_account` FOREIGN KEY (`oauth_provider_account_id`) REFERENCES `accounts` (`id`)'), 'oauth binding must reference provider account');
