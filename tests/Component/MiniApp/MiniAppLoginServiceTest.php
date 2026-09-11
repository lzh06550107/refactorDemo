<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\context\RequestContext;
use app\common\context\RuntimeType;
use app\common\contract\AuditLogger;
use app\common\contract\TransactionManager;
use modules\member\application\MemberIdentityService;
use modules\member\contract\MemberIdentityRepository;
use modules\member\domain\ExternalIdentity;
use modules\member\domain\Member;
use modules\member\domain\MemberIdentityResult;
use modules\member\domain\ProviderIdentity;
use modules\miniapp\application\MiniAppLoginService;
use modules\miniapp\contract\MiniAppCodeExchangeClient;
use modules\miniapp\contract\MiniAppProviderAccountRepository;
use modules\miniapp\contract\MiniAppSessionRepository;
use modules\miniapp\contract\SessionKeyCipher;
use modules\miniapp\domain\MiniAppCodeSession;
use modules\miniapp\domain\MiniAppConnectionMode;
use modules\miniapp\domain\MiniAppProviderAccount;
use modules\miniapp\domain\MiniAppSession;
use modules\miniapp\domain\ProtectedSessionKey;

$events = [];
$tx = new class($events) implements TransactionManager {
    public bool $active = false;
    public int $runs = 0;
    /** @var list<string> */
    public array $events = [];
    public function __construct(array $unused) {}
    public function run(callable $callback): mixed
    {
        $this->runs++;
        $this->events[] = 'tx.begin';
        $this->active = true;
        try {
            return $callback();
        } finally {
            $this->active = false;
            $this->events[] = 'tx.end';
        }
    }
};

$memberRepo = new class($tx) implements MemberIdentityRepository {
    /** @var array<string, MemberIdentityResult> */
    public array $items = [];
    public function __construct(private object $tx) {}
    public function findByProviderIdentity(ProviderIdentity $identity): ?MemberIdentityResult
    {
        return $this->items[$identity->providerKey()] ?? null;
    }
    public function insertOrGet(Member $member, ExternalIdentity $identity): MemberIdentityResult
    {
        expectTrue($this->tx->active, 'member identity write occurs inside final transaction');
        $key = $identity->providerIdentity()->providerKey();
        return $this->items[$key] ??= new MemberIdentityResult($member, $identity);
    }
};
$memberService = new MemberIdentityService($memberRepo, $tx);

$provider = new MiniAppProviderAccount(
    'tenant-1',
    'account-1',
    'wx-app-1',
    MiniAppConnectionMode::MANUAL,
    'vault://miniapp/account-1',
    null,
);
$providerRepo = new class($provider) implements MiniAppProviderAccountRepository {
    public function __construct(private MiniAppProviderAccount $provider) {}
    public function findForTenantAccount(string $tenantId, string $accountId): ?MiniAppProviderAccount
    {
        if ($tenantId !== $this->provider->tenantId() || $accountId !== $this->provider->accountId()) {
            return null;
        }
        return $this->provider;
    }
};

$exchange = new class($tx) implements MiniAppCodeExchangeClient {
    public function __construct(private object $tx) {}
    public function exchange(MiniAppProviderAccount $provider, string $code): MiniAppCodeSession
    {
        expectTrue(!$this->tx->active, 'remote jscode2session happens before database transaction');
        expectSame('login-code-1', $code, 'login code forwarded exactly once to provider client');
        $this->tx->events[] = 'exchange';
        return new MiniAppCodeSession($provider->providerAppId(), 'openid-1', 'union-1', 'raw-session-key-1');
    }
};

$cipher = new class($tx) implements SessionKeyCipher {
    public function __construct(private object $tx) {}
    public function protect(string $sessionKey): ProtectedSessionKey
    {
        expectTrue($this->tx->active, 'session key protection occurs inside final transaction');
        expectSame('raw-session-key-1', $sessionKey, 'provider session key reaches only cipher boundary');
        $this->tx->events[] = 'cipher.protect';
        return new ProtectedSessionKey('ciphertext-only', 'k1');
    }
    public function reveal(ProtectedSessionKey $protected): string
    {
        return 'raw-session-key-1';
    }
};

$sessionRepo = new class($tx) implements MiniAppSessionRepository {
    /** @var array<string, MiniAppSession> */
    public array $items = [];
    public function __construct(private object $tx) {}
    public function insert(MiniAppSession $session): void
    {
        expectTrue($this->tx->active, 'MiniApp session insert occurs inside final transaction');
        $this->tx->events[] = 'session.insert';
        $this->items[$session->tokenHash()] = $session;
    }
    public function findByTokenHash(string $tokenHash): ?MiniAppSession
    {
        return $this->items[$tokenHash] ?? null;
    }
};

$audit = new class($tx) implements AuditLogger {
    /** @var list<array<string,mixed>> */
    public array $events = [];
    public function __construct(private object $tx) {}
    public function record(AuditEvent $event): void
    {
        expectTrue($this->tx->active, 'success audit is emitted inside final transaction');
        $this->tx->events[] = 'audit';
        $this->events[] = $event->toArray();
    }
};

$context = new RequestContext(
    requestId: 'req-r8a-login',
    traceId: 'trace-r8a-login',
    runtimeType: RuntimeType::API,
    tenantId: 'tenant-1',
    accountId: 'account-1',
    siteId: null,
    principal: null,
    locale: 'zh-CN',
    clientIp: '127.0.0.1',
);
$now = new DateTimeImmutable('2026-09-08T06:20:00+00:00');
$service = new MiniAppLoginService($providerRepo, $exchange, $memberService, $sessionRepo, $cipher, $tx, $audit);
$result = $service->login($context, 'login-code-1', $now);

expectSame(1, $tx->runs, 'login opens exactly one final transaction');
expectSame(['exchange', 'tx.begin', 'cipher.protect', 'session.insert', 'audit', 'tx.end'], $tx->events, 'remote exchange precedes atomic identity/session/audit boundary');
expectTrue(preg_match('/^[a-f0-9]{64}$/', $result->sessionToken()) === 1, 'client token contains 256 bits encoded as lowercase hex');
expectSame($now->modify('+1800 seconds')->format(DATE_ATOM), $result->expiresAt()->format(DATE_ATOM), 'MiniApp login session expires after exactly 1800 seconds');

$tokenHash = hash('sha256', $result->sessionToken());
$stored = $sessionRepo->findByTokenHash($tokenHash);
expectTrue($stored instanceof MiniAppSession, 'repository stores session by SHA-256 token hash');
expectSame($tokenHash, $stored->tokenHash(), 'only SHA-256 token hash is stored');
expectTrue($stored->tokenHash() !== $result->sessionToken(), 'plaintext opaque token is not stored');
expectSame('ciphertext-only', $stored->protectedSessionKey()->ciphertext(), 'repository sees protected session key only');
expectSame($result->memberId(), $stored->memberId(), 'session links canonical member');
expectSame($result->externalIdentityId(), $stored->externalIdentityId(), 'session links canonical external identity');

$identity = array_values($memberRepo->items)[0]->externalIdentity()->providerIdentity();
expectSame('wechat_mini_program', $identity->providerType(), 'MiniApp identity uses explicit provider type');
expectSame('account-1', $identity->providerAccountId(), 'provider identity is scoped by internal Account id');
expectSame('openid-1', $identity->externalSubject(), 'WeChat openid remains provider external subject');
expectSame('union-1', $identity->unionId(), 'optional unionid is preserved');

expectSame(1, count($audit->events), 'successful login emits exactly one audit event');
$auditEvent = $audit->events[0];
expectSame('miniapp.login', $auditEvent['action'], 'MiniApp login audit action');
expectSame('success', $auditEvent['result'], 'MiniApp login audit result');
expectSame(['provider_type' => 'wechat_mini_program', 'provider_app_id' => 'wx-app-1'], $auditEvent['metadata'], 'audit metadata is provider-only');

$public = json_encode([
    'token' => $result->sessionToken(),
    'member' => $result->memberId(),
    'identity' => $result->externalIdentityId(),
    'expires' => $result->expiresAt()->format(DATE_ATOM),
    'audit' => $auditEvent,
], JSON_THROW_ON_ERROR);
expectTrue(!str_contains($public, 'raw-session-key-1'), 'raw session key never leaks into result/audit');
expectTrue(!str_contains($public, 'ciphertext-only'), 'protected session-key ciphertext never leaks into result/audit');
expectTrue(!str_contains($public, 'vault://miniapp/account-1'), 'credential reference never leaks into result/audit');
