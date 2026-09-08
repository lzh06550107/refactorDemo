<?php

declare(strict_types=1);

namespace app\miniapp\infrastructure;

use app\miniapp\contract\MiniAppSessionRepository;
use app\miniapp\domain\MiniAppSession;
use app\miniapp\domain\ProtectedSessionKey;
use DateTimeImmutable;
use think\facade\Db;

final class ThinkPhpMiniAppSessionRepository implements MiniAppSessionRepository
{
    public function insert(MiniAppSession $session): void
    {
        Db::table('miniapp_sessions')->insert([
            'id' => $session->id(),
            'tenant_id' => $session->tenantId(),
            'account_id' => $session->accountId(),
            'member_id' => $session->memberId(),
            'external_identity_id' => $session->externalIdentityId(),
            'token_hash' => $session->tokenHash(),
            'session_key_ciphertext' => $session->protectedSessionKey()->ciphertext(),
            'session_key_key_version' => $session->protectedSessionKey()->keyVersion(),
            'issued_at' => $session->issuedAt()->format('Y-m-d H:i:s.u'),
            'expires_at' => $session->expiresAt()->format('Y-m-d H:i:s.u'),
            'revoked_at' => $session->revokedAt()?->format('Y-m-d H:i:s.u'),
        ]);
    }

    public function findByTokenHash(string $tokenHash): ?MiniAppSession
    {
        $row = Db::table('miniapp_sessions')->where('token_hash', $tokenHash)->find();
        if ($row === null) {
            return null;
        }
        $row = (array) $row;

        return MiniAppSession::restore(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['account_id'],
            (string) $row['member_id'],
            (string) $row['external_identity_id'],
            (string) $row['token_hash'],
            new ProtectedSessionKey(
                (string) $row['session_key_ciphertext'],
                (string) $row['session_key_key_version'],
            ),
            new DateTimeImmutable((string) $row['issued_at']),
            new DateTimeImmutable((string) $row['expires_at']),
            $this->nullableDate($row['revoked_at'] ?? null),
        );
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        return new DateTimeImmutable((string) $value);
    }
}
