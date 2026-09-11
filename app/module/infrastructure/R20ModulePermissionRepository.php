<?php

declare(strict_types=1);

namespace app\module\infrastructure;

use modules\account\domain\LegacyAccountMapping;
use modules\iam\domain\LegacyPermissionAssignment;
use app\legacy\contract\LegacyDatabase;
use app\module\contract\ModulePermissionRepository;
use InvalidArgumentException;

final class R20ModulePermissionRepository implements ModulePermissionRepository
{
    public function __construct(private readonly LegacyDatabase $database)
    {
    }

    public function assignment(int $legacyUid, LegacyAccountMapping $account, string $moduleName): LegacyPermissionAssignment
    {
        $moduleName = trim($moduleName);
        if ($legacyUid <= 0 || $moduleName === '') {
            throw new InvalidArgumentException('Legacy uid and module name are required.');
        }

        $rows = $this->database->fetchAll('users_permission', [
            'uid' => $legacyUid,
            'uniacid' => $account->uniacid(),
        ]);
        if ($rows === []) {
            return LegacyPermissionAssignment::roleDefault();
        }

        foreach ($rows as $row) {
            if ((string) ($row['type'] ?? '') === 'modules' && $this->isAll($row['permission'] ?? null)) {
                return LegacyPermissionAssignment::all();
            }
        }

        foreach ($rows as $row) {
            if ((string) ($row['type'] ?? '') !== $moduleName) {
                continue;
            }
            $keys = $this->permissionKeys($row['permission'] ?? null);
            if ($keys === ['all']) {
                return LegacyPermissionAssignment::all();
            }
            return LegacyPermissionAssignment::explicit($keys);
        }

        return LegacyPermissionAssignment::explicit([]);
    }

    private function isAll(mixed $permission): bool
    {
        return $this->permissionKeys($permission) === ['all'];
    }

    private function permissionKeys(mixed $permission): array
    {
        if ($permission === null || $permission === '') {
            return [];
        }
        if (is_array($permission)) {
            $parts = $permission;
        } elseif (is_string($permission)) {
            $parts = explode('|', $permission);
        } else {
            throw new InvalidArgumentException('R20 users_permission.permission must be a pipe-delimited string or array.');
        }

        $keys = [];
        foreach ($parts as $part) {
            $key = trim((string) $part);
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        return $keys;
    }
}
