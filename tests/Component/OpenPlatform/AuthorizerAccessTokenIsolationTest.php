<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use modules\openplatform\application\AuthorizerAccessTokenService;
use modules\openplatform\application\ComponentAccessTokenService;
use modules\openplatform\contract\AuthorizerAuthorizationCredentialRepository;
use modules\openplatform\contract\AuthorizerRefreshLeaseRepository;
use modules\openplatform\contract\AuthorizerTokenRepository;
use modules\openplatform\contract\AuthorizerClient;
use modules\openplatform\contract\ComponentCredentialProvider;
use modules\openplatform\contract\ComponentPlatformRepository;
use modules\openplatform\contract\ComponentRefreshLeaseRepository;
use modules\openplatform\contract\ComponentTicketRepository;
use modules\openplatform\contract\ComponentTokenClient;
use modules\openplatform\contract\ComponentTokenRepository;
use modules\openplatform\domain\AuthorizerAccessToken;
use modules\openplatform\domain\AuthorizerAuthorization;
use modules\openplatform\domain\AuthorizerAuthorizationResponse;
use modules\openplatform\domain\AuthorizerInfoResponse;
use modules\openplatform\domain\AuthorizerRefreshResponse;
use modules\openplatform\domain\AuthorizerTokenRefreshLease;
use modules\openplatform\domain\ComponentAccessToken;
use modules\openplatform\domain\ComponentPlatform;
use modules\openplatform\domain\ComponentTicketWriteResult;
use modules\openplatform\domain\ComponentTokenRefreshLease;
use modules\openplatform\domain\ComponentTokenResponse;
use modules\openplatform\domain\ComponentVerifyTicket;
use modules\openplatform\domain\PreAuthCodeResponse;

$now = new DateTimeImmutable('2026-09-08T10:25:00Z');
$platformA = new ComponentPlatform('platform-A', 'wx-component-A', 'a/app', 'a/verify', 'a/aes', true);
$platformB = new ComponentPlatform('platform-B', 'wx-component-B', 'b/app', 'b/verify', 'b/aes', true);
$platforms = new class($platformA, $platformB) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $a, private ComponentPlatform $b) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform { return match ($componentPlatformId) { 'platform-A' => $this->a, 'platform-B' => $this->b, default => null }; }
};
$componentRepo = new class($now) implements ComponentTokenRepository {
    public function __construct(private DateTimeImmutable $now) {}
    public function current(string $componentPlatformId): ?ComponentAccessToken
    {
        return match ($componentPlatformId) {
            'platform-A' => new ComponentAccessToken('platform-A', 'wx-component-A', 'component-A-token', $this->now->modify('-1 second'), $this->now->modify('+3600 seconds'), 1),
            'platform-B' => new ComponentAccessToken('platform-B', 'wx-component-B', 'component-B-token', $this->now->modify('-1 second'), $this->now->modify('+3600 seconds'), 1),
            default => null,
        };
    }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool { return false; }
};
$tickets = new class implements ComponentTicketRepository { public function current(string $componentPlatformId): ?ComponentVerifyTicket { return null; } public function accept(ComponentVerifyTicket $incoming, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new RuntimeException('not used'); } };
$componentLeases = new class implements ComponentRefreshLeaseRepository { public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease { throw new RuntimeException('not used'); } public function release(string $componentPlatformId, string $holderId): void {} };
$credentials = new class implements ComponentCredentialProvider { public function secretFor(string $credentialRef): string { throw new RuntimeException('not used'); } };
$componentClient = new class implements ComponentTokenClient { public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse { throw new RuntimeException('not used'); } };
$audit = new class implements AuditLogger { public function record(AuditEvent $event): void {} };
$componentTokens = new ComponentAccessTokenService($platforms, $tickets, $componentRepo, $componentLeases, $credentials, $componentClient, $audit);

$tokenRepo = new class($now) implements AuthorizerTokenRepository {
    public array $tokens;
    public function __construct(DateTimeImmutable $now)
    {
        $this->tokens = [
            'platform-A:wx-shared' => new AuthorizerAccessToken('platform-A', 'wx-shared', 'A-shared-token', $now, $now->modify('+1000 seconds'), 1),
            'platform-B:wx-shared' => new AuthorizerAccessToken('platform-B', 'wx-shared', 'B-shared-token', $now, $now->modify('+1000 seconds'), 1),
            'platform-A:wx-other' => new AuthorizerAccessToken('platform-A', 'wx-other', 'A-other-token', $now, $now->modify('+1000 seconds'), 1),
        ];
    }
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccessToken { return $this->tokens[$componentPlatformId . ':' . $authorizerAppId] ?? null; }
};
$authorizations = new class($now) implements AuthorizerAuthorizationCredentialRepository {
    public array $rows;
    public function __construct(DateTimeImmutable $now)
    {
        $this->rows = [];
        foreach ([['platform-A','wx-shared'], ['platform-B','wx-shared'], ['platform-A','wx-other']] as [$platform, $authorizer]) {
            $this->rows[$platform . ':' . $authorizer] = AuthorizerAuthorization::active($platform, $authorizer, hash('sha256', 'refresh-' . $platform . '-' . $authorizer), ['17'], $now, $now, $now, 1);
        }
    }
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAuthorization { return $this->rows[$componentPlatformId . ':' . $authorizerAppId] ?? null; }
    public function currentRefreshToken(string $componentPlatformId, string $authorizerAppId): ?string { return isset($this->rows[$componentPlatformId . ':' . $authorizerAppId]) ? 'refresh-' . $componentPlatformId . '-' . $authorizerAppId : null; }
    public function saveFromAuthorization(AuthorizerAuthorization $authorization, string $refreshToken, string $accessToken, DateTimeImmutable $accessTokenExpiresAt): bool { return false; }
    public function markUnauthorized(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $sourceTimestamp, int $expectedVersion): bool { return false; }
    public function compareAndSetRefresh(AuthorizerAuthorization $authorization, AuthorizerAccessToken $token, string $refreshToken, string $holderId, int $expectedAuthorizationVersion, int $expectedTokenVersion, DateTimeImmutable $now): bool { return false; }
};
$leases = new class implements AuthorizerRefreshLeaseRepository { public int $calls = 0; public function tryAcquire(string $componentPlatformId, string $authorizerAppId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?AuthorizerTokenRefreshLease { $this->calls++; return null; } public function release(string $componentPlatformId, string $authorizerAppId, string $holderId): void {} };
$provider = new class implements AuthorizerClient {
    public int $refreshCalls = 0;
    public function createPreAuthCode(string $componentAppId, string $componentAccessToken): PreAuthCodeResponse { throw new RuntimeException('not used'); }
    public function queryAuthorization(string $componentAppId, string $componentAccessToken, string $authorizationCode): AuthorizerAuthorizationResponse { throw new RuntimeException('not used'); }
    public function refreshAuthorizerToken(string $componentAppId, string $componentAccessToken, string $authorizerAppId, string $authorizerRefreshToken): AuthorizerRefreshResponse { $this->refreshCalls++; throw new RuntimeException('fresh isolation tokens must not refresh'); }
    public function getAuthorizerInfo(string $componentAppId, string $componentAccessToken, string $authorizerAppId): AuthorizerInfoResponse { throw new RuntimeException('isolation token reads must not fetch authorizer metadata'); }
};
$service = new AuthorizerAccessTokenService($platforms, $authorizations, $tokenRepo, $leases, $componentTokens, $provider, $audit, 300, 30);

expectSame('A-shared-token', $service->forAuthorizer('platform-A', 'wx-shared', $now)->accessToken(), 'same authorizer AppId on platform A resolves only platform A token');
expectSame('B-shared-token', $service->forAuthorizer('platform-B', 'wx-shared', $now)->accessToken(), 'same authorizer AppId on platform B resolves only platform B token');
expectSame('A-other-token', $service->forAuthorizer('platform-A', 'wx-other', $now)->accessToken(), 'authorizer identity remains isolated inside one platform');
expectSame(0, $provider->refreshCalls, 'cross-platform/isolation reads do not leak into provider refresh path');
expectSame(0, $leases->calls, 'fresh isolated tokens do not contend on refresh leases');
