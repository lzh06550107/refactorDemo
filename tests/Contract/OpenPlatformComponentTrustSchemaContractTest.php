<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$upPath = $root . '/database/migrations/20260908_007_openplatform_component_trust_up.sql';
$downPath = $root . '/database/migrations/20260908_007_openplatform_component_trust_down.sql';

expectTrue(is_file($upPath), 'R8B OpenPlatform trust up migration must exist');
expectTrue(is_file($downPath), 'R8B OpenPlatform trust down migration must exist');
$up = (string) file_get_contents($upPath);
$down = (string) file_get_contents($downPath);

$tables = [
    'component_platforms',
    'component_ticket_inbox',
    'component_verify_tickets',
    'component_access_tokens',
    'component_token_refresh_leases',
];
foreach ($tables as $table) {
    expectTrue(str_contains($up, 'CREATE TABLE `' . $table . '`'), 'R8B migration creates ' . $table);
    expectTrue(str_contains($down, 'DROP TABLE IF EXISTS `' . $table . '`'), 'R8B rollback drops ' . $table);
}
expectSame(5, substr_count($up, 'ENGINE=InnoDB'), 'all five R8B tables use InnoDB');
expectSame(5, substr_count($up, 'DEFAULT CHARSET=utf8mb4'), 'all five R8B tables use utf8mb4');

$platformStart = strpos($up, 'CREATE TABLE `component_platforms`');
$platformEnd = strpos($up, ') ENGINE=InnoDB', $platformStart ?: 0);
expectTrue($platformStart !== false && $platformEnd !== false, 'component_platforms definition can be isolated');
$platformSql = substr($up, (int) $platformStart, (int) $platformEnd - (int) $platformStart);
expectTrue(!str_contains($platformSql, '`tenant_id`'), 'ComponentPlatform is not tenant-owned');
expectTrue(str_contains($platformSql, 'UNIQUE KEY `uk_component_platform_appid` (`component_app_id`)'), 'component AppId is globally unique');
expectTrue(str_contains($platformSql, '`app_secret_ref` varchar(255) NOT NULL'), 'AppSecret is stored as reference only');
expectTrue(str_contains($platformSql, '`verify_token_ref` varchar(255) NOT NULL'), 'verify token is stored as reference only');
expectTrue(str_contains($platformSql, '`encoding_aes_key_ref` varchar(255) NOT NULL'), 'EncodingAESKey is stored as reference only');

expectTrue(str_contains($up, 'UNIQUE KEY `uk_component_ticket_replay` (`component_platform_id`,`replay_key`)'), 'ticket replay identity is unique per platform');
expectTrue(str_contains($up, '`ticket_ciphertext` text NOT NULL'), 'verify ticket is persisted only as ciphertext');
expectTrue(str_contains($up, '`ticket_key_version` varchar(64) NOT NULL'), 'verify ticket records cipher key version');
expectTrue(str_contains($up, '`token_ciphertext` text NOT NULL'), 'component token is persisted only as ciphertext');
expectTrue(str_contains($up, '`token_key_version` varchar(64) NOT NULL'), 'component token records cipher key version');
expectTrue(!preg_match('/`component_verify_ticket`\s/', $up), 'plaintext verify ticket column must not exist');
expectTrue(!preg_match('/`component_access_token`\s/', $up), 'plaintext component access token column must not exist');

foreach (['component_ticket_inbox', 'component_verify_tickets', 'component_access_tokens', 'component_token_refresh_leases'] as $table) {
    expectTrue(str_contains($up, 'REFERENCES `component_platforms` (`id`)'), 'OpenPlatform state references component platform');
}
expectTrue(
    str_contains($up, 'ALTER TABLE `miniapp_provider_accounts`')
    && str_contains($up, 'FOREIGN KEY (`component_platform_id`) REFERENCES `component_platforms` (`id`)'),
    'R8A component provider binding references an existing ComponentPlatform',
);
expectTrue(str_contains($down, 'DROP FOREIGN KEY `fk_miniapp_provider_component_platform`'), 'rollback removes R8A provider ComponentPlatform FK before dropping R8B tables');
