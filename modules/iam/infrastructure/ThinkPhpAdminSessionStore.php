<?php

declare(strict_types=1);

namespace modules\iam\infrastructure;

use DateTimeImmutable;
use modules\iam\contract\AdminSessionStore;
use modules\iam\domain\AdminSession;
use think\facade\Db;

final class ThinkPhpAdminSessionStore implements AdminSessionStore
{
    public function save(AdminSession $session): void
    {
        Db::table('admin_sessions')->insert([
            'id' => $session->id(),
            'admin_user_id' => $session->userId(),
            'token_hash' => $session->tokenHash(),
            'issued_at' => $this->date($session->issuedAt()),
            'expires_at' => $this->date($session->expiresAt()),
            'created_at' => $this->date($session->issuedAt()),
        ]);
    }

    public function deleteByTokenHash(string $tokenHash): void
    {
        Db::table('admin_sessions')
            ->where('token_hash', $tokenHash)
            ->delete();
    }

    private function date(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
