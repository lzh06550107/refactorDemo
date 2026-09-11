<?php

declare(strict_types=1);

use app\common\security\SecretValue;
use modules\iam\application\CreateAdminSession;
use modules\iam\contract\AdminSessionStore;
use modules\iam\contract\SessionIdGenerator;
use modules\iam\contract\SessionTokenGenerator;
use modules\iam\domain\AdminSession;
use modules\iam\domain\AdminUser;
use modules\iam\domain\AdminUserStatus;
use modules\iam\security\SessionTokenHasher;

$store = new class implements AdminSessionStore {
    public ?AdminSession $saved = null;

    public function save(AdminSession $session): void
    {
        $this->saved = $session;
    }

    public function deleteByTokenHash(string $tokenHash): void
    {
        throw new RuntimeException('issuance test must not revoke a session');
    }
};

$tokenGenerator = new class implements SessionTokenGenerator {
    public function generate(): string
    {
        return 'raw-test-token';
    }
};
$idGenerator = new class implements SessionIdGenerator {
    public function generate(): string
    {
        return 'session-1';
    }
};
$hasher = new SessionTokenHasher(new SecretValue('test-session-pepper'));
$service = new CreateAdminSession($store, $tokenGenerator, $idGenerator, $hasher);
$user = new AdminUser('admin-1', 'root', AdminUserStatus::ACTIVE, null);
$now = new DateTimeImmutable('2026-09-11T00:00:00+00:00');
$ttlSeconds = 3600;

$issued = $service->execute($user, $now, $ttlSeconds);

expectSame('raw-test-token', $issued->rawToken(), 'raw token is returned exactly once to the caller');
expectTrue($store->saved instanceof AdminSession, 'session is persisted');
expectSame($store->saved, $issued->session(), 'issued result carries the persisted session');
expectSame('session-1', $store->saved->id(), 'generated session id is persisted');
expectSame('admin-1', $store->saved->userId(), 'administrator id is persisted');
expectSame($now, $store->saved->issuedAt(), 'issued_at equals requested issuance time');
expectSame(
    $now->modify('+3600 seconds')->format(DATE_ATOM),
    $store->saved->expiresAt()->format(DATE_ATOM),
    'expiry equals now plus TTL',
);
expectSame($hasher->hash('raw-test-token'), $store->saved->tokenHash(), 'only hashed token is persisted');
expectTrue($store->saved->tokenHash() !== 'raw-test-token', 'raw token must never be persisted');
