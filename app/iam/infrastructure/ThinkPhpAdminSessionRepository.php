<?php

declare(strict_types=1);

namespace app\iam\infrastructure;

use app\iam\contract\AdminSessionRepository;
use app\iam\domain\AdminSession;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final class ThinkPhpAdminSessionRepository implements AdminSessionRepository
{
    public function findByTokenHash(string $tokenHash): ?AdminSession
    {
        $row = Db::table('admin_sessions')
            ->join('admin_users', 'admin_users.id = admin_sessions.admin_user_id')
            ->where('admin_sessions.token_hash', $tokenHash)
            ->where('admin_users.status', 'active')
            ->field([
                'admin_sessions.id',
                'admin_sessions.admin_user_id',
                'admin_sessions.token_hash',
                'admin_sessions.issued_at',
                'admin_sessions.expires_at',
            ])
            ->find();

        if (!is_array($row)) {
            return null;
        }

        return new AdminSession(
            (string) $row['id'],
            (string) $row['admin_user_id'],
            (string) $row['token_hash'],
            $this->date((string) $row['issued_at']),
            $this->date((string) $row['expires_at']),
        );
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
