<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$file = $root . '/app/iam/infrastructure/ThinkPhpAdminSessionRepository.php';

expectTrue(is_file($file), 'ThinkPhpAdminSessionRepository production adapter must exist');

$source = (string) file_get_contents($file);
expectTrue(str_contains($source, 'implements AdminSessionRepository'), 'adapter implements AdminSessionRepository');
expectTrue(str_contains($source, "Db::table('admin_sessions')"), 'adapter reads admin_sessions');
expectTrue(str_contains($source, "->join('admin_users"), 'adapter joins admin_users');
expectTrue(str_contains($source, "admin_users.status"), 'adapter filters administrator status');
expectTrue(str_contains($source, "'active'"), 'only active administrators are restorable');
expectTrue(str_contains($source, "whereNull('admin_users.expires_at')"), 'administrator without expiry remains restorable');
expectTrue(str_contains($source, "whereOr('admin_users.expires_at', '>',"), 'expired administrator is rejected at session lookup');
expectTrue(str_contains($source, "new DateTimeZone('UTC')"), 'administrator expiry comparison uses UTC');
foreach (['id', 'admin_user_id', 'token_hash', 'issued_at', 'expires_at'] as $field) {
    expectTrue(str_contains($source, $field), 'adapter maps required session field: ' . $field);
}
