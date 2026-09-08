<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\application\AuthorizerAccessTokenService;
use app\openplatform\application\ComponentAccessTokenService;
use app\openplatform\contract\AuthorizerAuthorizationCredentialRepository;
use app\openplatform\contract\AuthorizerRefreshLeaseRepository;
use app\openplatform\contract\AuthorizerTokenRepository;
use app\openplatform\contract\AuthorizerClient;
use app\openplatform\contract\ComponentCredentialProvider;
use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\contract\ComponentRefreshLeaseRepository;
use app\openplatform\contract\ComponentTicketRepository;
use app\openplatform\contract\ComponentTokenClient;
use app\openplatform\contract\ComponentTokenRepository;
use app\openplatform\domain\AuthorizerAccessToken;
use app\openplatform\domain\AuthorizerAuthorization;
use app\openplatform\domain\AuthorizerAuthorizationResponse;
use app\openplatform\domain\AuthorizerRefreshResponse;
use app\openplatform\domain\AuthorizerTokenRefreshLease;
use app\openplatform\domain\ComponentAccessToken;
use app\openplatform\domain\ComponentPlatform;
use app\openplatform\domain\ComponentTicketWriteResult;
use app\openplatform\domain\ComponentTokenRefreshLease;
use app\openplatform\domain\ComponentTokenResponse;
use app\openplatform\domain\ComponentVerifyTicket;
use app\openplatform\domain\PreAuthCodeResponse;

$now = new DateTimeImmutable('2026-09-08T10:20:00Z');
$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform { return $componentPlatformId === 'platform-1' ? $this->platform : null; }
};
$componentCached = new ComponentAccessToken('platform-1', 'wx-component-1', 'component-access-secret', $now->modify('-60 seconds'), $now->modify('+3600 seconds'), 1);
$componentRepo = new class($componentCached) implements ComponentTokenRepository {
    public function __construct(private ComponentAccessToken $token) {}
    public function current(string $componentPlatformId): ?ComponentAccessToken { return $componentPlatformId === 'platform-1' ? $this->token : null; }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool { return false; }
};
$componentTickets = new class implements ComponentTicketRepository {
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return null; }
    public function accept(ComponentVerifyTicket $incoming, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new RuntimeException('not used'); }
};
$componentLeases = new class implements ComponentRefreshLeaseRepository {
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease { throw new RuntimeException('cached component token must not acquire lease'); }
    public function release(string $componentPlatformId, string $holderId): void {}
};
$componentCredentials = new class implements ComponentCredentialProvider { public function secretFor(string $credentialRef): string { throw new RuntimeException('not used'); } };
$componentClient = new class implements ComponentTokenClient { public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse { throw new RuntimeException('not used'); } };
$audit = new class implements AuditLogger { public array $events = []; public function record(AuditEvent $event): void { $this->events[] = $event->toArray(); } };
$componentTokens = new ComponentAccessTokenService($platforms, $componentTickets, $componentRepo, $componentLeases, $componentCredentials, $componentClient, $audit);

$tokenRepo = new class implements AuthorizerTokenRepository {
    public ?AuthorizerAccessToken $token = null;
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccessToken
    { return $this->token !== null && $this->token->componentPlatformId() === $componentPlatformId && $this->token->authorizerAppId() === $authorizerAppId ? $this->token : null; }
};
$leaseRepo = new class implements AuthorizerRefreshLeaseRepository {
    public bool $allowAcquire = true;
    public bool $throwRelease = false;
    public ?AuthorizerTokenRefreshLease $lease = null;
    public int $acquireCalls = 0;
    public int $releaseCalls = 0;
    public function tryAcquire(string $componentPlatformId, string $authorizerAppId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?AuthorizerTokenRefreshLease
    {
        $this->acquireCalls++;
        if (!$this->allowAcquire) { return null; }
        $this->lease = new AuthorizerTokenRefreshLease($componentPlatformId, $authorizerAppId, $holderId, $now->modify('+' . $leaseSeconds . ' seconds'), ($this->lease?->version() ?? 0) + 1);
        return $this->lease;
    }
    public function release(string $componentPlatformId, string $authorizerAppId, string $holderId): void
    {
        $this->releaseCalls++;
        if ($this->throwRelease) { throw new RuntimeException('release failed'); }
        if ($this->lease !== null && $this->lease->holderId() === $holderId) { $this->lease = null; }
    }
};
$active = static fn (string $refresh = 'refresh-old', int $version = 1): AuthorizerAuthorization => AuthorizerAuthorization::active(
    'platform-1', 'wx-authorizer-1', hash('sha256', $refresh), ['17'], $now->modify('-100 seconds'), $now->modify('-100 seconds'), $now->modify('-100 seconds'), $version,
);
$authorizations = new class($active(), $tokenRepo, $leaseRepo) implements AuthorizerAuthorizationCredentialRepository {
    public AuthorizerAuthorization $authorization;
    public string $refreshToken = 'refresh-old';
    public bool $forceCasFailure = false;
    public ?AuthorizerAccessToken $winnerTokenOnCasFailure = null;
    public function __construct(AuthorizerAuthorization $authorization, private object $tokens, private object $leases) { $this->authorization = $authorization; }
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAuthorization
    { return $this->authorization->componentPlatformId() === $componentPlatformId && $this->authorization->authorizerAppId() === $authorizerAppId ? $this->authorization : null; }
    public function currentRefreshToken(string $componentPlatformId, string $authorizerAppId): ?string
    { $auth = $this->current($componentPlatformId, $authorizerAppId); return $auth !== null && $auth->isActive() ? $this->refreshToken : null; }
    public function saveFromAuthorization(AuthorizerAuthorization $authorization, string $refreshToken, string $accessToken, DateTimeImmutable $accessTokenExpiresAt): bool { throw new RuntimeException('not used'); }
    public function markUnauthorized(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $sourceTimestamp, int $expectedVersion): bool { return false; }
    public function compareAndSetRefresh(AuthorizerAuthorization $authorization, AuthorizerAccessToken $token, string $refreshToken, string $holderId, int $expectedAuthorizationVersion, int $expectedTokenVersion, DateTimeImmutable $now): bool
    {
        if ($this->forceCasFailure) {
            if ($this->winnerTokenOnCasFailure !== null) { $this->tokens->token = $this->winnerTokenOnCasFailure; }
            return false;
        }
        $currentToken = $this->tokens->token;
        if ($this->authorization->version() !== $expectedAuthorizationVersion || ($currentToken?->version() ?? 0) !== $expectedTokenVersion || $this->leases->lease === null || !$this->leases->lease->heldBy($holderId, $now)) { return false; }
        $this->authorization = $authorization;
        $this->refreshToken = $refreshToken;
        $this->tokens->token = $token;
        return true;
    }
};
$provider = new class implements AuthorizerClient {
    public int $refreshCalls = 0;
    public bool $throwRefresh = false;
    public AuthorizerRefreshResponse $response;
    public function __construct() { $this->response = new AuthorizerRefreshResponse('access-new', 'refresh-rotated', 7200); }
    public function createPreAuthCode(string $componentAppId, string $componentAccessToken): PreAuthCodeResponse { throw new RuntimeException('not used'); }
    public function queryAuthorization(string $componentAppId, string $componentAccessToken, string $authorizationCode): AuthorizerAuthorizationResponse { throw new RuntimeException('not used'); }
    public function refreshAuthorizerToken(string $componentAppId, string $componentAccessToken, string $authorizerAppId, string $authorizerRefreshToken): AuthorizerRefreshResponse
    {
        $this->refreshCalls++;
        if ($this->throwRefresh) { throw new AppException(ErrorCode::BAD_GATEWAY, 'sanitized authorizer refresh failure', 502); }
        return $this->response;
    }
};
$service = new AuthorizerAccessTokenService($platforms, $authorizations, $tokenRepo, $leaseRepo, $componentTokens, $provider, $audit, 300, 30);

$tokenRepo->token = new AuthorizerAccessToken('platform-1', 'wx-authorizer-1', 'cached-fresh', $now->modify('-60 seconds'), $now->modify('+1000 seconds'), 1);
$result = $service->forAuthorizer('platform-1', 'wx-authorizer-1', $now);
expectSame('cached-fresh', $result->accessToken(), 'authorizer token outside refresh skew returns cached token');
expectSame(0, $leaseRepo->acquireCalls, 'fresh cached authorizer token does not acquire refresh lease');
expectSame(0, $provider->refreshCalls, 'fresh cached authorizer token does not call provider');

$tokenRepo->token = new AuthorizerAccessToken('platform-1', 'wx-authorizer-1', 'cached-near-expiry', $now->modify('-60 seconds'), $now->modify('+100 seconds'), 1);
$leaseRepo->allowAcquire = true;
$result = $service->forAuthorizer('platform-1', 'wx-authorizer-1', $now);
expectSame('access-new', $result->accessToken(), 'lease winner refreshes authorizer access token inside skew');
expectSame(2, $result->version(), 'authorizer access token version increments atomically');
expectSame('refresh-rotated', $authorizations->refreshToken, 'rotated refresh token is committed atomically with new access token');
expectSame(hash('sha256', 'refresh-rotated'), $authorizations->authorization->refreshTokenHash(), 'rotated refresh-token hash updates with protected credential');
expectSame(2, $authorizations->authorization->version(), 'refresh-token rotation bumps authorization version');
expectSame(1, $provider->refreshCalls, 'lease winner performs one provider refresh');

$tokenRepo->token = new AuthorizerAccessToken('platform-1', 'wx-authorizer-1', 'busy-old-valid', $now->modify('-60 seconds'), $now->modify('+100 seconds'), 3);
$leaseRepo->allowAcquire = false;
$result = $service->forAuthorizer('platform-1', 'wx-authorizer-1', $now);
expectSame('busy-old-valid', $result->accessToken(), 'lease loser returns still-unexpired old authorizer token');
expectSame(1, $provider->refreshCalls, 'lease loser never calls provider');

$tokenRepo->token = new AuthorizerAccessToken('platform-1', 'wx-authorizer-1', 'busy-expired', $now->modify('-1000 seconds'), $now->modify('-1 second'), 4);
try {
    $service->forAuthorizer('platform-1', 'wx-authorizer-1', $now);
    throw new RuntimeException('lease loser with no usable token must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::SERVICE_UNAVAILABLE, $e->errorCode(), 'busy authorizer refresh without usable token maps to SERVICE_UNAVAILABLE');
    expectSame(503, $e->httpStatus(), 'busy authorizer refresh without usable token maps to 503');
}

$leaseRepo->allowAcquire = true;
$tokenRepo->token = new AuthorizerAccessToken('platform-1', 'wx-authorizer-1', 'fallback-valid', $now->modify('-60 seconds'), $now->modify('+100 seconds'), 5);
$provider->throwRefresh = true;
$result = $service->forAuthorizer('platform-1', 'wx-authorizer-1', $now);
expectSame('fallback-valid', $result->accessToken(), 'provider failure degrades to still-unexpired authorizer token');

$tokenRepo->token = new AuthorizerAccessToken('platform-1', 'wx-authorizer-1', 'fallback-expired', $now->modify('-1000 seconds'), $now->modify('-1 second'), 6);
try {
    $service->forAuthorizer('platform-1', 'wx-authorizer-1', $now);
    throw new RuntimeException('provider failure with expired token must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::BAD_GATEWAY, $e->errorCode(), 'provider failure with expired authorizer token remains BAD_GATEWAY');
}
$provider->throwRefresh = false;

$authorizations->authorization = $authorizations->authorization->withUnauthorized($now->modify('+1 second'));
try {
    $service->forAuthorizer('platform-1', 'wx-authorizer-1', $now->modify('+2 seconds'));
    throw new RuntimeException('unauthorized grant must block authorizer token retrieval');
} catch (AppException $e) {
    expectSame(ErrorCode::FORBIDDEN, $e->errorCode(), 'unauthorized grant blocks token retrieval immediately');
    expectSame(403, $e->httpStatus(), 'unauthorized grant maps to 403');
}

$authorizations->authorization = $active('refresh-rotated', 2);
$authorizations->refreshToken = 'refresh-rotated';
$tokenRepo->token = new AuthorizerAccessToken('platform-1', 'wx-authorizer-1', 'stale-old', $now->modify('-60 seconds'), $now->modify('+100 seconds'), 7);
$leaseRepo->allowAcquire = true;
$authorizations->forceCasFailure = true;
$authorizations->winnerTokenOnCasFailure = new AuthorizerAccessToken('platform-1', 'wx-authorizer-1', 'winner-token', $now, $now->modify('+7200 seconds'), 8);
$result = $service->forAuthorizer('platform-1', 'wx-authorizer-1', $now);
expectSame('winner-token', $result->accessToken(), 'stale refresh worker rereads and returns winner token instead of overwriting');
$authorizations->forceCasFailure = false;
$authorizations->winnerTokenOnCasFailure = null;

$tokenRepo->token = new AuthorizerAccessToken('platform-1', 'wx-authorizer-1', 'release-old', $now->modify('-60 seconds'), $now->modify('+100 seconds'), 8);
$leaseRepo->throwRelease = true;
$provider->response = new AuthorizerRefreshResponse('release-new', null, 7200);
$result = $service->forAuthorizer('platform-1', 'wx-authorizer-1', $now);
expectSame('release-new', $result->accessToken(), 'lease release failure does not undo successful authorizer token CAS');
