<?php

declare(strict_types=1);

namespace modules\iam\infrastructure;

use modules\iam\contract\AdminSessionRepository;
use modules\iam\domain\AdminSession;
use DateTimeImmutable;
use DateTimeZone;
use think\facade\Db;

final class ThinkPhpAdminSessionRepository implements AdminSessionRepository
{
    public function findByTokenHash(string $tokenHash): ?AdminSession
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $row = Db::table('admin_sessions')
            ->join('admin_users', 'admin_users.id = admin_sessions.admin_user_id')
            ->where('admin_sessions.token_hash', $tokenHash)
            ->where('admin_users.status', 'active')
            ->where(function ($query) use ($now): void {
                $query->whereNull('admin_users.expires_at')
                    ->whereOr('admin_users.expires_at', '>', $now->format('Y-m-d H:i:s.u'));
            })
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
