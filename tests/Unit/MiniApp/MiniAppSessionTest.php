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

assertSame('2026-09-08T05:30:00+00:00', $session->expiresAt()->format(DATE_ATOM));
assertTrue($session->isActiveAt($issuedAt->modify('+1799 seconds')));
assertTrue(!$session->isActiveAt($issuedAt->modify('+1800 seconds')));
assertSame('ciphertext', $session->protectedSessionKey()->ciphertext());
assertSame('key-v1', $session->protectedSessionKey()->keyVersion());

$revoked = $session->revoke($issuedAt->modify('+60 seconds'));
assertTrue(!$revoked->isActiveAt($issuedAt->modify('+61 seconds')));
assertSame('2026-09-08T05:01:00+00:00', $revoked->revokedAt()?->format(DATE_ATOM));

assertThrows(
    fn () => MiniAppSession::issue('session-2', 'tenant-1', 'account-1', 'member-1', 'external-1', 'raw-token', $protected, $issuedAt),
    InvalidArgumentException::class,
);
