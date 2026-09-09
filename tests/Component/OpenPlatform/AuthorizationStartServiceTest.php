<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\openplatform\application\AuthorizationStartService;
use app\openplatform\application\ComponentAccessTokenService;
use app\openplatform\contract\AuthorizationIntentRepository;
use app\openplatform\contract\AuthorizerAccountEligibility;
use app\openplatform\contract\AuthorizerClient;
use app\openplatform\contract\ComponentCredentialProvider;
use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\contract\ComponentRefreshLeaseRepository;
use app\openplatform\contract\ComponentTicketRepository;
use app\openplatform\contract\ComponentTokenClient;
use app\openplatform\contract\ComponentTokenRepository;
use app\openplatform\domain\AuthorizationIntent;
use app\openplatform\domain\AuthorizationIntentMode;
use app\openplatform\domain\AuthorizerAuthorizationResponse;
use app\openplatform\domain\AuthorizerInfoResponse;
use app\openplatform\domain\AuthorizerRefreshResponse;
use app\openplatform\domain\ComponentAccessToken;
use app\openplatform\domain\ComponentPlatform;
use app\openplatform\domain\ComponentTicketWriteResult;
use app\openplatform\domain\ComponentTokenRefreshLease;
use app\openplatform\domain\ComponentTokenResponse;
use app\openplatform\domain\ComponentVerifyTicket;
use app\openplatform\domain\PreAuthCodeResponse;

$now = new DateTimeImmutable('2026-09-08T09:30:00Z');
$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform { return $componentPlatformId === 'platform-1' ? $this->platform : null; }
};
$cachedToken = new ComponentAccessToken('platform-1', 'wx-component-1', 'component-token-secret', $now->modify('-60 seconds'), $now->modify('+3600 seconds'), 1);
$componentTokenRepo = new class($cachedToken) implements ComponentTokenRepository {
    public function __construct(private ComponentAccessToken $token) {}
    public function current(string $componentPlatformId): ?ComponentAccessToken { return $componentPlatformId === 'platform-1' ? $this->token : null; }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool { throw new RuntimeException('cached-token fast path must not CAS'); }
};
$ticketRepo = new class implements ComponentTicketRepository {
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return null; }
    public function accept(ComponentVerifyTicket $incoming, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new RuntimeException('start flow must not touch verify ticket when component token is cached'); }
};
$leaseRepo = new class implements ComponentRefreshLeaseRepository {
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease { throw new RuntimeException('cached component token must not acquire refresh lease'); }
    public function release(string $componentPlatformId, string $holderId): void {}
};
$credentials = new class implements ComponentCredentialProvider {
    public function secretFor(string $credentialRef): string { throw new RuntimeException('cached component token must not resolve component secret'); }
};
$componentClient = new class implements ComponentTokenClient {
    public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse { throw new RuntimeException('cached component token must not call component provider'); }
};
$audit = new class implements AuditLogger { public function record(AuditEvent $event): void {} };
$componentTokens = new ComponentAccessTokenService($platforms, $ticketRepo, $componentTokenRepo, $leaseRepo, $credentials, $componentClient, $audit);

$sequence = new ArrayObject();
$eligibility = new class($sequence) implements AuthorizerAccountEligibility {
    public function __construct(private ArrayObject $sequence) {}
    public function assertTenantEligible(string $tenantId, string $componentPlatformId): void
    {
        throw new RuntimeException('existing-account start must validate the target Account');
    }
    public function assertExistingAccountEligible(string $tenantId, string $accountId, string $componentPlatformId): AccountType
    {
        $this->sequence[] = ['eligibility', $tenantId, $accountId, $componentPlatformId];
        return AccountType::WECHAT_MINI_PROGRAM;
    }
};
$authorizerClient = new class($sequence) implements AuthorizerClient {
    public function __construct(private ArrayObject $sequence) {}
    public function createPreAuthCode(string $componentAppId, string $componentAccessToken): PreAuthCodeResponse
    {
        $this->sequence[] = ['provider', $componentAppId, $componentAccessToken];
        return new PreAuthCodeResponse('pre-auth-secret', 300);
    }
    public function queryAuthorization(string $componentAppId, string $componentAccessToken, string $authorizationCode): AuthorizerAuthorizationResponse { throw new RuntimeException('start flow must not query authorization'); }
    public function refreshAuthorizerToken(string $componentAppId, string $componentAccessToken, string $authorizerAppId, string $authorizerRefreshToken): AuthorizerRefreshResponse { throw new RuntimeException('start flow must not refresh authorizer token'); }
    public function getAuthorizerInfo(string $componentAppId, string $componentAccessToken, string $authorizerAppId): AuthorizerInfoResponse { throw new RuntimeException('start flow must not fetch authorizer metadata'); }
};
$intents = new class($sequence) implements AuthorizationIntentRepository {
    public ?AuthorizationIntent $inserted = null;
    public function __construct(private ArrayObject $sequence) {}
    public function insert(AuthorizationIntent $intent): void { $this->sequence[] = ['insert']; $this->inserted = $intent; }
    public function findByStateHash(string $stateHash): ?AuthorizationIntent { return null; }
    public function findByPreAuthCodeHash(string $componentPlatformId, string $preAuthCodeHash): ?AuthorizationIntent { return null; }
    public function tryClaim(string $intentId, string $holderId, DateTimeImmutable $now, int $leaseSeconds, int $expectedVersion): ?AuthorizationIntent { return null; }
    public function releaseClaim(string $intentId, string $holderId): void {}
    public function complete(string $intentId, string $holderId, string $authorizerAppId, DateTimeImmutable $now, int $expectedVersion): bool { return false; }
};

$callbackUri = 'https://example.test/api/v1/openplatform/authorization/callback';
$service = new AuthorizationStartService($componentTokens, $authorizerClient, $intents, $eligibility, $callbackUri, 600);
$result = $service->start(
    'platform-1',
    'tenant-1',
    AuthorizationIntentMode::BIND_EXISTING_ACCOUNT,
    'account-1',
    '1',
    $now,
);

expectSame(64, strlen($result->state()), 'authorization state encodes 32 random bytes as 64 hex characters');
expectTrue(ctype_xdigit($result->state()), 'authorization state is opaque hex text');
expectTrue($intents->inserted instanceof AuthorizationIntent, 'authorization intent is persisted only after provider pre-auth succeeds');
expectSame(AuthorizationIntentMode::BIND_EXISTING_ACCOUNT, $intents->inserted?->mode(), 'persisted intent carries explicit bind mode');
expectSame(hash('sha256', $result->state()), $intents->inserted?->stateHash(), 'repository receives only SHA-256 state hash');
expectSame(hash('sha256', 'pre-auth-secret'), $intents->inserted?->preAuthCodeHash(), 'repository receives only SHA-256 pre-auth-code hash');
expectSame($now->modify('+300 seconds')->getTimestamp(), $intents->inserted?->providerPreAuthExpiresAt()->getTimestamp(), 'provider pre-auth expiry is recorded');
expectSame($now->modify('+300 seconds')->getTimestamp(), $result->expiresAt()->getTimestamp(), 'provider expiry shortens 600-second local intent TTL');
expectSame('eligibility', $sequence[0][0] ?? null, 'local eligibility runs before any provider access');
expectSame(['eligibility', 'tenant-1', 'account-1', 'platform-1'], $sequence[0], 'eligibility receives trusted Tenant, Account and Component Platform');
expectSame('provider', $sequence[1][0] ?? null, 'provider pre-auth network call occurs after local eligibility');
expectSame('insert', $sequence[2][0] ?? null, 'intent persistence follows provider call');
expectSame('wx-component-1', $sequence[1][1] ?? null, 'start passes trusted component AppId from R8B component token');
expectSame('component-token-secret', $sequence[1][2] ?? null, 'start passes current component access token to provider client');

$url = parse_url($result->authorizationUrl());
expectSame('https', $url['scheme'] ?? null, 'authorization URL uses HTTPS');
expectSame('mp.weixin.qq.com', $url['host'] ?? null, 'authorization URL targets WeChat');
expectSame('/cgi-bin/componentloginpage', $url['path'] ?? null, 'authorization URL uses WeChat component login page');
$query = [];
parse_str($url['query'] ?? '', $query);
expectSame('wx-component-1', $query['component_appid'] ?? null, 'authorization URL contains component AppId');
expectSame('pre-auth-secret', $query['pre_auth_code'] ?? null, 'pre-auth code exists only in outgoing provider URL');
expectSame('1', (string) ($query['auth_type'] ?? ''), 'authorization URL preserves approved auth type');
expectSame($callbackUri . '?state=' . rawurlencode($result->state()), $query['redirect_uri'] ?? null, 'redirect URI is fixed server callback plus opaque state');
expectTrue(!method_exists($result, 'preAuthCode'), 'public start result does not expose standalone pre-auth credential');
