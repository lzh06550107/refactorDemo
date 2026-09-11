<?php

declare(strict_types=1);

namespace modules\iam\infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use modules\iam\contract\AdminUserRepository;
use modules\iam\domain\AdminUser;
use modules\iam\domain\AdminUserStatus;
use think\facade\Db;

final class ThinkPhpAdminUserRepository implements AdminUserRepository
{
    public function findById(string $id): ?AdminUser
    {
        $row = Db::table('admin_users')
            ->where('id', $id)
            ->field(['id', 'username', 'status', 'expires_at'])
            ->find();

        if (!is_array($row)) {
            return null;
        }

        return new AdminUser(
            (string) $row['id'],
            (string) $row['username'],
            AdminUserStatus::from((string) $row['status']),
            $this->nullableDate($row['expires_at'] ?? null),
        );
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
