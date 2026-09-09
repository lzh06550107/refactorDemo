<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\application\AuthorizerMetadataNormalizer;
use app\openplatform\application\AuthorizerMetadataSyncService;
use app\openplatform\application\ComponentAccessTokenService;
use app\openplatform\contract\AuthorizerClient;
use app\openplatform\contract\AuthorizerMetadataRepository;
use app\openplatform\contract\ComponentCredentialProvider;
use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\contract\ComponentRefreshLeaseRepository;
use app\openplatform\contract\ComponentTicketRepository;
use app\openplatform\contract\ComponentTokenClient;
use app\openplatform\contract\ComponentTokenRepository;
use app\openplatform\domain\AuthorizerAuthorizationResponse;
use app\openplatform\domain\AuthorizerInfoResponse;
use app\openplatform\domain\AuthorizerMetadata;
use app\openplatform\domain\AuthorizerMetadataRecord;
use app\openplatform\domain\AuthorizerRefreshResponse;
use app\openplatform\domain\ComponentAccessToken;
use app\openplatform\domain\ComponentPlatform;
use app\openplatform\domain\ComponentTicketWriteResult;
use app\openplatform\domain\ComponentTokenRefreshLease;
use app\openplatform\domain\ComponentTokenResponse;
use app\openplatform\domain\ComponentVerifyTicket;
use app\openplatform\domain\PreAuthCodeResponse;

$now = new DateTimeImmutable('2026-09-09T04:40:00Z');
$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform
    { return $componentPlatformId === $this->platform->id() ? $this->platform : null; }
};
$componentToken = new ComponentAccessToken('platform-1', 'wx-component-1', 'component-token-secret', $now->modify('-60 seconds'), $now->modify('+3600 seconds'), 1);
$tokens = new class($componentToken) implements ComponentTokenRepository {
    public function __construct(private ComponentAccessToken $token) {}
    public function current(string $componentPlatformId): ?ComponentAccessToken
    { return $componentPlatformId === $this->token->componentPlatformId() ? $this->token : null; }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool
    { throw new RuntimeException('cached component token must not CAS'); }
};
$tickets = new class implements ComponentTicketRepository {
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return null; }
    public function accept(ComponentVerifyTicket $incoming, string $replayKey, string $payloadHash): ComponentTicketWriteResult
    { throw new RuntimeException('cached component token must not touch ticket'); }
};
$leases = new class implements ComponentRefreshLeaseRepository {
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease
    { throw new RuntimeException('cached component token must not acquire lease'); }
    public function release(string $componentPlatformId, string $holderId): void {}
};
$credentials = new class implements ComponentCredentialProvider {
    public function secretFor(string $credentialRef): string { throw new RuntimeException('cached component token must not resolve credential'); }
};
$componentClient = new class implements ComponentTokenClient {
    public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse
    { throw new RuntimeException('cached component token must not call provider'); }
};
$audit = new class implements AuditLogger { public function record(AuditEvent $event): void {} };
$componentTokens = new ComponentAccessTokenService($platforms, $tickets, $tokens, $leases, $credentials, $componentClient, $audit);

$provider = new class implements AuthorizerClient {
    public AuthorizerInfoResponse $info;
    public bool $throwInfo = false;
    public int $infoCalls = 0;
    public array $arguments = [];

    public function __construct()
    {
        $this->info = new AuthorizerInfoResponse(
            '名称-A',
            'https://example.test/a.png',
            'gh_metadata_1',
            '主体-A',
            'alias-a',
            2,
            0,
            ['open_pay' => 1],
            'https://example.test/a-qrcode.png',
            null,
        );
    }

    public function createPreAuthCode(string $componentAppId, string $componentAccessToken): PreAuthCodeResponse
    { throw new RuntimeException('metadata sync must not create pre-auth code'); }
    public function queryAuthorization(string $componentAppId, string $componentAccessToken, string $authorizationCode): AuthorizerAuthorizationResponse
    { throw new RuntimeException('metadata sync must not query authorization'); }
    public function refreshAuthorizerToken(string $componentAppId, string $componentAccessToken, string $authorizerAppId, string $authorizerRefreshToken): AuthorizerRefreshResponse
    { throw new RuntimeException('metadata sync must not refresh authorizer token'); }
    public function getAuthorizerInfo(string $componentAppId, string $componentAccessToken, string $authorizerAppId): AuthorizerInfoResponse
    {
        $this->infoCalls++;
        $this->arguments[] = [$componentAppId, $componentAccessToken, $authorizerAppId];
        if ($this->throwInfo) {
            throw new AppException(ErrorCode::BAD_GATEWAY, 'sanitized metadata provider failure', 502);
        }
        return $this->info;
    }
};

$repository = new class implements AuthorizerMetadataRepository {
    /** @var array<string,AuthorizerMetadataRecord> */
    public array $currentRows = [];
    /** @var array<string,list<AuthorizerMetadataRecord>> */
    public array $snapshots = [];
    public int $observeCalls = 0;

    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerMetadataRecord
    { return $this->currentRows[$componentPlatformId . ':' . $authorizerAppId] ?? null; }

    public function observe(AuthorizerMetadata $metadata, DateTimeImmutable $fetchedAt, string $source): AuthorizerMetadataRecord
    {
        $this->observeCalls++;
        $key = $metadata->componentPlatformId() . ':' . $metadata->authorizerAppId();
        $current = $this->currentRows[$key] ?? null;
        $version = $current?->version() ?? 0;
        if ($current === null || !hash_equals($current->metadataHash(), $metadata->metadataHash())) {
            $version++;
        }
        $next = AuthorizerMetadataRecord::fromMetadata($metadata, $fetchedAt, $version);
        $this->currentRows[$key] = $next;
        if ($current === null || !hash_equals($current->metadataHash(), $metadata->metadataHash())) {
            $this->snapshots[$key][] = $next;
        }
        return $next;
    }
};

$service = new AuthorizerMetadataSyncService(
    $componentTokens,
    $provider,
    new AuthorizerMetadataNormalizer(),
    $repository,
);

$first = $service->sync('platform-1', 'wx-authorizer-1', $now, 'authorization');
expectSame(1, $first->version(), 'first trusted metadata observation creates version 1');
expectSame(AccountType::OFFICIAL_ACCOUNT, $first->accountType(), 'first observation preserves trusted provider classification');
expectSame('名称-A', $first->nickName(), 'first observation persists trusted nickname');
expectSame(1, count($repository->snapshots['platform-1:wx-authorizer-1'] ?? []), 'first observation creates one immutable snapshot');
expectSame([['wx-component-1', 'component-token-secret', 'wx-authorizer-1']], $provider->arguments, 'metadata provider receives trusted component identity and access token');

$same = $service->sync('platform-1', 'wx-authorizer-1', $now->modify('+1 minute'), 'reconcile');
expectSame(1, $same->version(), 'identical semantic metadata does not increment version');
expectSame($now->modify('+1 minute')->getTimestamp(), $same->providerFetchedAt()->getTimestamp(), 'identical semantic metadata updates provider fetched time');
expectSame(1, count($repository->snapshots['platform-1:wx-authorizer-1'] ?? []), 'identical semantic metadata creates no extra snapshot');

$provider->info = new AuthorizerInfoResponse(
    '名称-B',
    'https://example.test/b.png',
    'gh_metadata_1',
    '主体-A',
    'alias-b',
    2,
    0,
    ['open_pay' => 1],
    'https://example.test/b-qrcode.png',
    null,
);
$second = $service->sync('platform-1', 'wx-authorizer-1', $now->modify('+2 minutes'), 'updateauthorized');
expectSame(2, $second->version(), 'semantic metadata change creates version 2');
expectSame(2, count($repository->snapshots['platform-1:wx-authorizer-1'] ?? []), 'semantic metadata change appends immutable snapshot');

$provider->info = new AuthorizerInfoResponse(
    '名称-A',
    'https://example.test/a.png',
    'gh_metadata_1',
    '主体-A',
    'alias-a',
    2,
    0,
    ['open_pay' => 1],
    'https://example.test/a-qrcode.png',
    null,
);
$third = $service->sync('platform-1', 'wx-authorizer-1', $now->modify('+3 minutes'), 'reconcile');
expectSame(3, $third->version(), 'A-B-A metadata history produces version 3 rather than deduplicating old hash');
expectSame(3, count($repository->snapshots['platform-1:wx-authorizer-1'] ?? []), 'A-B-A creates three immutable snapshots');

$beforeFailure = $repository->current('platform-1', 'wx-authorizer-1');
$observeCallsBeforeFailure = $repository->observeCalls;
$provider->throwInfo = true;
try {
    $service->sync('platform-1', 'wx-authorizer-1', $now->modify('+4 minutes'), 'manual_refresh');
    throw new RuntimeException('metadata provider failure must propagate');
} catch (AppException $e) {
    expectSame(ErrorCode::BAD_GATEWAY, $e->errorCode(), 'metadata provider failure remains BAD_GATEWAY');
}
expectSame($observeCallsBeforeFailure, $repository->observeCalls, 'provider failure performs no metadata persistence mutation');
expectSame($beforeFailure?->version(), $repository->current('platform-1', 'wx-authorizer-1')?->version(), 'provider failure leaves current metadata version unchanged');
expectSame($beforeFailure?->metadataHash(), $repository->current('platform-1', 'wx-authorizer-1')?->metadataHash(), 'provider failure leaves current metadata hash unchanged');
