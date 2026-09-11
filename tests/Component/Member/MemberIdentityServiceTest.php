<?php

declare(strict_types=1);

use app\common\contract\TransactionManager;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\member\application\MemberIdentityService;
use modules\member\contract\MemberIdentityRepository;
use modules\member\domain\ExternalIdentity;
use modules\member\domain\Member;
use modules\member\domain\MemberIdentityResult;
use modules\member\domain\ProviderIdentity;

$tx = new class implements TransactionManager {
    public int $runs = 0;
    public bool $active = false;
    public function run(callable $callback): mixed
    {
        $this->runs++;
        $this->active = true;
        try { return $callback(); } finally { $this->active = false; }
    }
};

$repo = new class($tx) implements MemberIdentityRepository {
    /** @var array<string, MemberIdentityResult> */
    public array $byProviderKey = [];
    public function __construct(private object $tx) {}
    public function findByProviderIdentity(ProviderIdentity $identity): ?MemberIdentityResult
    {
        return $this->byProviderKey[$identity->providerKey()] ?? null;
    }
    public function insertOrGet(Member $member, ExternalIdentity $identity): MemberIdentityResult
    {
        expectTrue($this->tx->active, 'member + external identity insert must occur inside transaction');
        $key = $identity->providerIdentity()->providerKey();
        if (isset($this->byProviderKey[$key])) {
            return $this->byProviderKey[$key];
        }
        return $this->byProviderKey[$key] = new MemberIdentityResult($member, $identity);
    }
};

$service = new MemberIdentityService($repo, $tx);
$identityA = new ProviderIdentity('wechat_official', 'provider-a', 'same-openid', 'union-1');
$first = $service->resolveOrCreate('tenant-1', $identityA);
$repeat = $service->resolveOrCreate('tenant-1', $identityA);
expectSame($first->member()->id(), $repeat->member()->id(), 'duplicate callback converges to one member');
expectSame($first->externalIdentity()->id(), $repeat->externalIdentity()->id(), 'duplicate callback converges to one external identity');

$identityB = new ProviderIdentity('wechat_official', 'provider-b', 'same-openid', 'union-1');
$otherProvider = $service->resolveOrCreate('tenant-1', $identityB);
expectTrue($first->member()->id() !== $otherProvider->member()->id(), 'same openid under another provider account is a distinct identity');

try {
    $service->resolveOrCreate('tenant-2', $identityA);
    throw new RuntimeException('cross-tenant provider identity collision must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'cross-tenant identity collision uses CONFLICT');
    expectSame(409, $e->httpStatus(), 'cross-tenant identity collision uses HTTP 409');
}

$before = $tx->runs;
$within = $service->resolveOrCreateWithinTransaction('tenant-1', $identityA);
expectSame($first->member()->id(), $within->member()->id(), 'within-transaction resolve returns canonical identity');
expectSame($before, $tx->runs, 'within-transaction resolver must not start a nested transaction');
