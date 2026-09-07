<?php

declare(strict_types=1);

$path = dirname(__DIR__, 2) . '/database/migrations/20260907_002_iam_module_platform_up.sql';
expectTrue(is_file($path), 'R3 IAM/module migration must exist');
$sql = file_get_contents($path);
expectTrue(is_string($sql) && $sql !== '', 'R3 migration must not be empty');

$tables = [
    'roles', 'permissions', 'role_permissions', 'permission_assignments',
    'module_definitions', 'module_versions', 'module_bindings', 'module_capabilities',
    'tenant_modules', 'account_module_configs',
];
foreach ($tables as $table) {
    expectTrue(str_contains($sql, "CREATE TABLE `{$table}`"), 'missing R3 table ' . $table);
}
expectSame(count($tables), substr_count($sql, 'ENGINE=InnoDB'), 'every R3 table must use InnoDB');
expectSame(count($tables), substr_count($sql, 'DEFAULT CHARSET=utf8mb4'), 'every R3 table must use utf8mb4');

foreach ([
    'idx_roles_tenant',
    'idx_permission_assignments_tenant_account',
    'uk_module_definitions_name',
    'uk_module_versions_module_version',
    'uk_module_bindings_version_entry_do',
    'uk_tenant_modules_tenant_module',
    'idx_account_module_configs_tenant_account',
    'uk_account_module_configs_account_module',
] as $index) {
    expectTrue(str_contains($sql, $index), 'missing R3 index/constraint ' . $index);
}

$downPath = dirname(__DIR__, 2) . '/database/migrations/20260907_002_iam_module_platform_down.sql';
expectTrue(is_file($downPath), 'R3 rollback migration must exist');
$down = file_get_contents($downPath);
foreach (array_reverse($tables) as $table) {
    expectTrue(str_contains((string) $down, "DROP TABLE IF EXISTS `{$table}`"), 'rollback missing table ' . $table);
}
