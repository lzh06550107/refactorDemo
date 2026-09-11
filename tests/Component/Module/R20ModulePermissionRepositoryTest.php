<?php

declare(strict_types=1);

use modules\account\domain\LegacyAccountMapping;
use modules\iam\domain\Permission;
use app\legacy\contract\LegacyDatabase;
use app\module\infrastructure\R20ModulePermissionRepository;

final class PermissionRepoFakeDb implements LegacyDatabase
{
    public function __construct(private array $rows) {}

    public function fetchOne(string $table, array $where): ?array
    {
        foreach ($this->fetchAll($table, $where) as $row) {
            return $row;
        }
        return null;
    }

    public function fetchAll(string $table, array $where): array
    {
        if ($table !== 'users_permission') {
            return [];
        }
        return array_values(array_filter($this->rows, static function (array $row) use ($where): bool {
            foreach ($where as $key => $value) {
                if (($row[$key] ?? null) !== $value) {
                    return false;
                }
            }
            return true;
        }));
    }
}

$account = new LegacyAccountMapping('account-10', 'tenant-1', 10, 11, 1);

$repo = new R20ModulePermissionRepository(new PermissionRepoFakeDb([]));
$assignment = $repo->assignment(7, $account, 'demo');
expectTrue($assignment->usesRoleDefault(), 'no permission rows uses role default');

$repo = new R20ModulePermissionRepository(new PermissionRepoFakeDb([
    ['uid'=>7, 'uniacid'=>10, 'type'=>'system', 'permission'=>'account*'],
]));
$assignment = $repo->assignment(7, $account, 'demo');
expectSame(false, $assignment->usesRoleDefault(), 'any row switches account to explicit ACL mode');
expectSame(false, $assignment->permissions()->contains(new Permission('demo_menu_orders')), 'missing module row is explicit empty');

$repo = new R20ModulePermissionRepository(new PermissionRepoFakeDb([
    ['uid'=>7, 'uniacid'=>10, 'type'=>'modules', 'permission'=>'all'],
]));
$assignment = $repo->assignment(7, $account, 'demo');
expectTrue($assignment->permissions()->isAll(), 'modules=all grants all module subpermissions');

$repo = new R20ModulePermissionRepository(new PermissionRepoFakeDb([
    ['uid'=>7, 'uniacid'=>10, 'type'=>'system', 'permission'=>'account*'],
    ['uid'=>7, 'uniacid'=>10, 'type'=>'demo', 'permission'=>'demo_menu_orders|demo_permission_export'],
]));
$assignment = $repo->assignment(7, $account, 'demo');
expectTrue($assignment->permissions()->contains(new Permission('demo_menu_orders')), 'pipe-delimited module permission mapped');
expectTrue($assignment->permissions()->contains(new Permission('demo_permission_export')), 'second module permission mapped');
