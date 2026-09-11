<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$file = $root . '/modules/iam/infrastructure/ThinkPhpAdminUserRepository.php';

expectTrue(is_file($file), 'ThinkPhpAdminUserRepository production adapter must exist');

$source = (string) file_get_contents($file);
expectTrue(str_contains($source, 'implements AdminUserRepository'), 'adapter implements AdminUserRepository');
expectTrue(str_contains($source, "Db::table('admin_users')"), 'adapter reads admin_users');
expectTrue(str_contains($source, 'findById'), 'adapter exposes safe administrator profile lookup by id');
foreach (['id', 'username', 'status', 'expires_at'] as $field) {
    expectTrue(str_contains($source, $field), 'adapter maps safe administrator field: ' . $field);
}
expectTrue(!str_contains($source, 'password_hash'), 'safe administrator profile repository must not expose password_hash');
