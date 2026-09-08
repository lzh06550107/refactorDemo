<?php

declare(strict_types=1);

use app\common\contract\TransactionManager;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\member\application\MemberIdentityService;
use app\member\contract\MemberIdentityRepository;
use app\member\domain\ExternalIdentity;
use app\member\domain\Member;
use app\member\domain\MemberIdentityResult;
use app\member\domain\ProviderIdentity;
use app\oauth\application\OAuthOrchestrator;
use app\oauth\contract\OAuthBindingRepository;
use app\oauth\contract\OAuthProviderClient;
use app\oauth\contract\OAuthStateRepository;
use app\oauth\domain\OAuthBinding;
use app\oauth\domain\OAuthState;
use app\oauth\domain\ReturnUrlPolicy;

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

$memberRepo = new class($tx) implements MemberIdentityRepository {
    /** @var array<string, MemberIdentityResult> */
    private array $items = [];
    public function __construct(private object $tx) {}
    public function findByProviderIdentity(ProviderIdentity $identity): ?MemberIdentityResult
    {
        return $this->items[$identity->providerKey()] ?? null;
    }
    public function insertOrGet(Member $member, ExternalIdentity $identity): MemberIdentityResult
    {
        expectTrue($this->tx->active, 'OAuth identity persistence must execute inside finalization transaction');
        $key = $identity->providerIdentity()->providerKey();
        return $this->items[$key] ??= new MemberIdentityResult($member, $identity);
    }
};
$memberService = new MemberIdentityService($memberRepo, $tx);

$binding = new OAuthBinding('binding-1', 'tenant-1', 'business-account-1', 'wechat_official', 'provider-account-9', true);
$bindingRepo = new class($binding) implements OAuthBindingRepository {
    public function __construct(private OAuthBinding $binding) {}
    public function findEnabled(string $tenantId, string $businessAccountId, string $providerType): ?OAuthBinding
    {
        if ($tenantId === $this->binding->tenantId()
            && $businessAccountId === $this->binding->businessAccountId()
            && $providerType === $this->binding->providerType()
            && $this->binding->enabled()) {
            return $this->binding;
        }
        return null;
    }
};

$stateRepo = new class($tx) implements OAuthStateRepository {
    /** @var array<string, OAuthState> */
    public array $states = [];
    public function __construct(private object $tx) {}
    public function insert(OAuthState $state): void { $this->states[$state->nonceHash()] = $state; }
    public function findByNonceHash(string $nonceHash): ?OAuthState { return $this->states[$nonceHash] ?? null; }
    public function lockByNonceHash(string $nonceHash): ?OAuthState
    {
        expectTrue($this->tx->active, 'OAuthState lock must occur inside finalization transaction');
        return $this->states[$nonceHash] ?? null;
    }
    public function save(OAuthState $state): void
    {
        expectTrue($this->tx->active, 'OAuthState consumption must commit inside finalization transaction');
        $this->states[$state->nonceHash()] = $state;
    }
};

$provider = new class implements OAuthProviderClient {
    public int $exchangeCalls = 0;
    public function authorizationUrl(OAuthBinding $binding, string $stateToken): string
    {
        return 'https://provider.example/authorize?account=' . rawurlencode($binding->oauthProviderAccountId()) . '&state=' . rawurlencode($stateToken);
    }
    public function exchangeCode(OAuthState $state, string $code): ProviderIdentity
    {
        $this->exchangeCalls++;
        if ($code === 'wrong-provider') {
            return new ProviderIdentity($state->providerType(), 'provider-account-other', 'openid-wrong');
        }
        return new ProviderIdentity($state->providerType(), $state->oauthProviderAccountId(), 'openid-123', 'union-7');
    }
};

$orchestrator = new OAuthOrchestrator(
    $bindingRepo,
    $stateRepo,
    $provider,
    $memberService,
    $tx,
    new ReturnUrlPolicy(),
);

$now = new \DateTimeImmutable('2026-09-08T10:00:00+08:00');
$start = $orchestrator->start('tenant-1', 'business-account-1', 'wechat_official', '/member/home', $now);
expectTrue(strlen($start->stateToken()) >= 64, 'browser receives high-entropy opaque state token');
expectTrue(str_contains($start->authorizationUrl(), 'provider-account-9'), 'authorization URL uses OAuth provider account');
$stored = $stateRepo->findByNonceHash(hash('sha256', $start->stateToken()));
expectTrue($stored !== null, 'only state token hash is addressable from persistence');
expectSame('business-account-1', $stored->businessAccountId(), 'state preserves business account');
expectSame('provider-account-9', $stored->oauthProviderAccountId(), 'state preserves provider account separately');

$callback = $orchestrator->callback($start->stateToken(), 'ok-code', $now->modify('+1 minute'));
expectSame('business-account-1', $callback->businessAccountId(), 'borrowed OAuth callback returns business account context');
expectSame('provider-account-9', $callback->oauthProviderAccountId(), 'borrowed OAuth callback returns provider account context separately');
expectSame('/member/home', $callback->returnUrl(), 'callback returns validated business return URL');
expectTrue($callback->memberId() !== '', 'callback resolves member');
expectTrue($callback->externalIdentityId() !== '', 'callback resolves external identity');
expectSame(1, $tx->runs, 'successful OAuth callback uses one outer finalization transaction');
expectTrue($stateRepo->findByNonceHash(hash('sha256', $start->stateToken()))?->hasCompleteResult() === true, 'state is completed atomically with identity result');

$repeat = $orchestrator->callback($start->stateToken(), 'ignored-repeat-code', $now->modify('+2 minutes'));
expectSame($callback->memberId(), $repeat->memberId(), 'successful callback replay returns same semantic member result');
expectSame($callback->externalIdentityId(), $repeat->externalIdentityId(), 'successful callback replay returns same semantic identity result');
expectSame(1, $provider->exchangeCalls, 'completed callback replay does not exchange provider code again');
expectSame(1, $tx->runs, 'completed callback replay does not open another finalization transaction');

$wrongStart = $orchestrator->start('tenant-1', 'business-account-1', 'wechat_official', '/member/home', $now);
try {
    $orchestrator->callback($wrongStart->stateToken(), 'wrong-provider', $now->modify('+1 minute'));
    throw new RuntimeException('provider-account mismatch must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::FORBIDDEN, $e->errorCode(), 'provider-account mismatch uses FORBIDDEN');
    expectSame(403, $e->httpStatus(), 'provider-account mismatch uses HTTP 403');
}

$expiredStart = $orchestrator->start('tenant-1', 'business-account-1', 'wechat_official', '/member/home', $now);
$exchangeCallsBeforeExpired = $provider->exchangeCalls;
try {
    $orchestrator->callback($expiredStart->stateToken(), 'ok-code', $now->modify('+11 minutes'));
    throw new RuntimeException('expired OAuth state must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'expired state uses UNAUTHORIZED');
    expectSame(401, $e->httpStatus(), 'expired state uses HTTP 401');
}
expectSame($exchangeCallsBeforeExpired, $provider->exchangeCalls, 'expired state is rejected before provider code exchange');
