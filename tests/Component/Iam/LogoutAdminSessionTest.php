<?php

declare(strict_types=1);

use app\common\security\SecretValue;
use modules\iam\application\LogoutAdminSession;
use modules\iam\contract\AdminSessionStore;
use modules\iam\domain\AdminSession;
use modules\iam\security\SessionTokenHasher;

$store = new class implements AdminSessionStore {
    /** @var list<string> */
    public array $deletedHashes = [];

    public function save(AdminSession $session): void
    {
        throw new RuntimeException('logout test must not create a session');
    }

    public function deleteByTokenHash(string $tokenHash): void
    {
        $this->deletedHashes[] = $tokenHash;
    }
};

$hasher = new SessionTokenHasher(new SecretValue('test-session-pepper'));
$service = new LogoutAdminSession($store, $hasher);

$service->execute('raw-test-token');
expectSame(1, count($store->deletedHashes), 'logout revokes exactly one matching session');
expectSame($hasher->hash('raw-test-token'), $store->deletedHashes[0], 'logout hashes raw token before persistence access');
expectTrue($store->deletedHashes[0] !== 'raw-test-token', 'raw token must not reach session store deletion');

$service->execute('');
$service->execute('   ');
expectSame(1, count($store->deletedHashes), 'blank logout token is an idempotent no-op');
