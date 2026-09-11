<?php

declare(strict_types=1);

use modules\iam\domain\AdminSession;

$issued = new DateTimeImmutable('2026-09-07T00:00:00+00:00');
$expires = new DateTimeImmutable('2026-09-07T01:00:00+00:00');
$session = new AdminSession('session-1', 'user-1', str_repeat('a', 64), $issued, $expires);
expectSame('session-1', $session->id(), 'session id');
expectSame('user-1', $session->userId(), 'session user');
expectSame(str_repeat('a', 64), $session->tokenHash(), 'session token hash');
expectTrue(!$session->isExpired(new DateTimeImmutable('2026-09-07T00:59:59+00:00')), 'active session');
expectTrue($session->isExpired($expires), 'expiry boundary is expired');

expectThrows(fn () => new AdminSession('', 'user-1', str_repeat('a', 64), $issued, $expires), InvalidArgumentException::class, 'session id required');
expectThrows(fn () => new AdminSession('session-1', 'user-1', 'raw-token', $issued, $expires), InvalidArgumentException::class, 'stored token must be sha256 hex');
expectThrows(fn () => new AdminSession('session-1', 'user-1', str_repeat('a', 64), $expires, $issued), InvalidArgumentException::class, 'expiry must follow issue time');
