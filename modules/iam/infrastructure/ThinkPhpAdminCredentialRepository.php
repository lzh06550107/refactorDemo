<?php

declare(strict_types=1);

namespace modules\iam\infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use modules\iam\contract\AdminCredentialRepository;
use modules\iam\domain\AdminCredential;
use modules\iam\domain\AdminUser;
use modules\iam\domain\AdminUserStatus;
use think\facade\Db;

final class ThinkPhpAdminCredentialRepository implements AdminCredentialRepository
{
    public function findByUsername(string $username): ?AdminCredential
    {
        $row = Db::table('admin_users')
            ->where('username', $username)
            ->field(['id', 'username', 'password_hash', 'status', 'expires_at'])
            ->find();

        if (!is_array($row)) {
            return null;
        }

        return new AdminCredential(
            $this->userFromRow($row),
            (string) $row['password_hash'],
        );
    }

    /** @param array<string,mixed> $row */
    private function userFromRow(array $row): AdminUser
    {
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
