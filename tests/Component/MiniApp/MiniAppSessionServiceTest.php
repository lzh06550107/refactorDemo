<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\miniapp\application\MiniAppSessionService;
use modules\miniapp\contract\MiniAppSessionRepository;
use modules\miniapp\domain\MiniAppSession;
use modules\miniapp\domain\ProtectedSessionKey;

$now = new DateTimeImmutable('2026-09-08T06:30:00+00:00');
$token = str_repeat('a', 64);
$hash = hash('sha256', $token);
$active = MiniAppSession::issue(
    'session-1',
    'tenant-1',
    'account-1',
    'member-1',
    'identity-1',
    $hash,
    new ProtectedSessionKey('ciphertext', 'k1'),
    $now->modify('-60 seconds'),
);

$repo = new class($active) implements MiniAppSessionRepository {
    /** @var array<string, MiniAppSession> */
    public array $items = [];
    public function __construct(MiniAppSession $session) { $this->items[$session->tokenHash()] = $session; }
    public function insert(MiniAppSession $session): void { $this->items[$session->tokenHash()] = $session; }
    public function findByTokenHash(string $tokenHash): ?MiniAppSession { return $this->items[$tokenHash] ?? null; }
};
$service = new MiniAppSessionService($repo);

$restored = $service->authenticate('tenant-1', 'account-1', $token, $now);
expectSame('session-1', $restored->id(), 'active opaque token restores MiniApp session');
expectSame('member-1', $restored->memberId(), 'restored session keeps member identity');

foreach ([
    ['tenant-2', 'account-1', $token, 'cross-tenant token use'],
    ['tenant-1', 'account-2', $token, 'cross-account token use'],
    ['tenant-1', 'account-1', str_repeat('b', 64), 'unknown token'],
] as [$tenantId, $accountId, $candidate, $message]) {
    try {
        $service->authenticate($tenantId, $accountId, $candidate, $now);
        throw new RuntimeException($message . ' must fail');
    } catch (AppException $e) {
        expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), $message . ' uses UNAUTHORIZED');
        expectSame(401, $e->httpStatus(), $message . ' uses HTTP 401');
    }
}

$expired = MiniAppSession::restore(
    'session-expired', 'tenant-1', 'account-1', 'member-1', 'identity-1', $hash,
    new ProtectedSessionKey('ciphertext', 'k1'),
    $now->modify('-3600 seconds'), $now->modify('-1 second'), null,
);
$repo->items[$hash] = $expired;
try {
    $service->authenticate('tenant-1', 'account-1', $token, $now);
    throw new RuntimeException('expired session must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'expired session uses UNAUTHORIZED');
}

$revoked = MiniAppSession::issue(
    'session-revoked', 'tenant-1', 'account-1', 'member-1', 'identity-1', $hash,
    new ProtectedSessionKey('ciphertext', 'k1'), $now->modify('-120 seconds'),
)->revoke($now->modify('-60 seconds'));
$repo->items[$hash] = $revoked;
try {
    $service->authenticate('tenant-1', 'account-1', $token, $now);
    throw new RuntimeException('revoked session must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'revoked session uses UNAUTHORIZED');
}

$method = new ReflectionMethod(MiniAppSessionService::class, 'authenticate');
$names = array_map(static fn (ReflectionParameter $parameter): string => strtolower($parameter->getName()), $method->getParameters());
expectTrue(!in_array('openid', $names, true), 'session authentication exposes no client-openid credential parameter');
