<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\common\security\SecretValue;
use modules\iam\application\RestoreAdminSession;
use modules\iam\contract\AdminSessionRepository;
use modules\iam\domain\AdminSession;
use modules\iam\security\SessionTokenHasher;

$hasher = new SessionTokenHasher(new SecretValue('pepper-123'));
$token = 'opaque-session-token';
$active = new AdminSession(
    'session-1',
    'user-1',
    $hasher->hash($token),
    new DateTimeImmutable('2026-09-07T00:00:00+00:00'),
    new DateTimeImmutable('2026-09-07T01:00:00+00:00'),
);
$repository = new class($active) implements AdminSessionRepository {
    public function __construct(private AdminSession $session) {}
    public function findByTokenHash(string $tokenHash): ?AdminSession
    {
        return hash_equals($this->session->tokenHash(), $tokenHash) ? $this->session : null;
    }
};
$restore = new RestoreAdminSession($repository, $hasher);

expectSame($active, $restore->execute($token, new DateTimeImmutable('2026-09-07T00:30:00+00:00')), 'active session restored');

foreach ([
    ['', new DateTimeImmutable('2026-09-07T00:30:00+00:00')],
    ['unknown-token', new DateTimeImmutable('2026-09-07T00:30:00+00:00')],
    [$token, new DateTimeImmutable('2026-09-07T01:00:00+00:00')],
] as [$candidate, $now]) {
    try {
        $restore->execute($candidate, $now);
        throw new RuntimeException('invalid session must fail');
    } catch (AppException $e) {
        expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'invalid session error code');
        expectSame(401, $e->httpStatus(), 'invalid session http status');
    }
}
