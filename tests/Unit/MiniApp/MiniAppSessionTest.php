<?php

declare(strict_types=1);

use app\miniapp\domain\MiniAppSession;
use app\miniapp\domain\ProtectedSessionKey;

$issuedAt = new DateTimeImmutable('2026-09-08T05:00:00Z');
$protected = new ProtectedSessionKey('ciphertext', 'key-v1');
$session = MiniAppSession::issue(
    'session-1',
    'tenant-1',
    'account-1',
    'member-1',
    'external-1',
    str_repeat('a', 64),
    $protected,
    $issuedAt,
);

expectSame('2026-09-08T05:30:00+00:00', $session->expiresAt()->format(DATE_ATOM), 'session TTL is exactly 1800 seconds');
expectTrue($session->isActiveAt($issuedAt->modify('+1799 seconds')), 'session active before expiry');
expectTrue(!$session->isActiveAt($issuedAt->modify('+1800 seconds')), 'session inactive at expiry boundary');
expectSame('ciphertext', $session->protectedSessionKey()->ciphertext(), 'session stores protected ciphertext');
expectSame('key-v1', $session->protectedSessionKey()->keyVersion(), 'session stores key version');

$revoked = $session->revoke($issuedAt->modify('+60 seconds'));
expectTrue(!$revoked->isActiveAt($issuedAt->modify('+61 seconds')), 'revoked session is inactive');
expectSame('2026-09-08T05:01:00+00:00', $revoked->revokedAt()?->format(DATE_ATOM), 'revocation timestamp preserved');

expectThrows(
    fn () => MiniAppSession::issue('session-2', 'tenant-1', 'account-1', 'member-1', 'external-1', 'raw-token', $protected, $issuedAt),
    InvalidArgumentException::class,
    'session requires SHA-256 token hash rather than raw token',
);
