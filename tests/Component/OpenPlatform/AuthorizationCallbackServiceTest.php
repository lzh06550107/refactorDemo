<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\contract\TransactionManager;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\application\AuthorizationCallbackService;
use app\openplatform\application\AuthorizationCompletionService;
use app\openplatform\application\ComponentAccessTokenService;
use app\openplatform\contract\AuthorizationIntentRepository;
use app\openplatform\contract\AuthorizerAccountBinding;
use app\openplatform\contract\AuthorizerAuthorizationRepository;
use app\openplatform\contract\AuthorizerClient;
use app\openplatform\contract\ComponentCredentialProvider;
use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\contract\ComponentRefreshLeaseRepository;
use app\openplatform\contract\ComponentTicketRepository;
use app\openplatform\contract\ComponentTokenClient;
use app\openplatform\contract\ComponentTokenRepository;
use app\openplatform\domain\AuthorizationIntent;
use app\openplatform\domain\AuthorizationIntentMode;
use app\openplatform\domain\AuthorizerAccessToken;
use app\openplatform\domain\AuthorizerAuthorization;
use app\openplatform\domain\AuthorizerAuthorizationResponse;
use app\openplatform\domain\AuthorizerRefreshResponse;
use app\openplatform\domain\ComponentAccessToken;
use app\openplatform\domain\ComponentPlatform;
use app\openplatform\domain\ComponentTicketWriteResult;
use app\openplatform\domain\ComponentTokenRefreshLease;
use app\openplatform\domain\ComponentTokenResponse;
use app\openplatform\domain\ComponentVerifyTicket;
use app\openplatform\domain\PreAuthCodeResponse;

$now = new DateTimeImmutable('2026-09-08T09:50:00Z');
$intent = AuthorizationIntent::pending(
    'intent-callback',
    'platform-1',
    'tenant-1',
    AuthorizationIntentMode::BIND_EXISTING_ACCOUNT,
    'account-1',
    hash('sha256', 'opaque-browser-state'),
    hash('sha256', 'pre-callback'),
    '1',
    $now,
    $now->modify('+600 seconds'),
    $now->modify('+300 seconds'),
);
$intents = new class($intent) implements AuthorizationIntentRepository {
    public AuthorizationIntent $current;
    public array $stateLookups = [];
    public function __construct(AuthorizationIntent $intent) { $this->current = $intent; }
    public function insert(AuthorizationIntent $intent): void { $this->current = $intent; }
    public function findByStateHash(string $stateHash): ?AuthorizationIntent
    {
        $this->stateLookups[] = $stateHash;
        return hash_equals($this->current->stateHash(), $stateHash) ? $this->current : null;
    }
    public function findByPreAuthCodeHash(string $componentPlatformId, string $preAuthCodeHash): ?AuthorizationIntent { return null; }
    public function tryClaim(string $intentId, string $holderId, DateTimeImmutable $now, int $leaseSeconds, int $expectedVersion): ?AuthorizationIntent
    {
        if ($this->current->id() !== $intentId || $this->current->version() !== $expectedVersion || !$this->current->claimableAt($now)) { return null; }
        $this->current = $this->current->withClaim($holderId, $now->modify('+' . $leaseSeconds . ' seconds'));
        return $this->current;
    }
    public function releaseClaim(string $intentId, string $holderId): void {}
    public function complete(string $intentId, string $holderId, string $authorizerAppId, DateTimeImmutable $now, int $expectedVersion): bool
    {
        if ($this->current->claimHolderId() !== $holderId || $this->current->version() !== $expectedVersion) { return false; }
        $this->current = $this->current->completedBy($authorizerAppId, $now);
        return true;
    }
};

$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform { return $componentPlatformId === 'platform-1' ? $this->platform : null; }
};
$token = new ComponentAccessToken('platform-1', 'wx-component-1', 'component-token-secret', $now->modify('-1 second'), $now->modify('+3600 seconds'), 1);
$tokenRepo = new class($token) implements ComponentTokenRepository {
    public function __construct(private ComponentAccessToken $token) {}
    public function current(string $componentPlatformId): ?ComponentAccessToken { return $this->token; }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool { return false; }
};
$tickets = new class implements ComponentTicketRepository {
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return null; }
    public function accept(ComponentVerifyTicket $incoming, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new RuntimeException('not used'); }
};
$leases = new class implements ComponentRefreshLeaseRepository {
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease { throw new RuntimeException('not used'); }
    public function release(string $componentPlatformId, string $holderId): void {}
};
$credentials = new class implements ComponentCredentialProvider { public function secretFor(string $credentialRef): string { throw new RuntimeException('not used'); } };
$componentClient = new class implements ComponentTokenClient { public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse { throw new RuntimeException('not used'); } };
$audit = new class implements AuditLogger { public function record(AuditEvent $event): void {} };
$componentTokens = new ComponentAccessTokenService($platforms, $tickets, $tokenRepo, $leases, $credentials, $componentClient, $audit);

$authorizerClient = new class implements AuthorizerClient {
    public int $queryCalls = 0;
    public function createPreAuthCode(string $componentAppId, string $componentAccessToken): PreAuthCodeResponse { throw new RuntimeException('not used'); }
    public function queryAuthorization(string $componentAppId, string $componentAccessToken, string $authorizationCode): AuthorizerAuthorizationResponse
    {
        $this->queryCalls++;
        return new AuthorizerAuthorizationResponse('wx-authorizer-callback', 'access-secret', 'refresh-secret', 7200, ['17']);
    }
    public function refreshAuthorizerToken(string $componentAppId, string $componentAccessToken, string $authorizerAppId, string $authorizerRefreshToken): AuthorizerRefreshResponse { throw new RuntimeException('not used'); }
};
$authorizations = new class implements AuthorizerAuthorizationRepository {
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAuthorization { return null; }
    public function saveFromAuthorization(AuthorizerAuthorization $authorization, string $refreshToken, string $accessToken, DateTimeImmutable $accessTokenExpiresAt): bool { return true; }
    public function markUnauthorized(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $sourceTimestamp, int $expectedVersion): bool { return false; }
    public function compareAndSetRefresh(AuthorizerAuthorization $authorization, AuthorizerAccessToken $token, string $refreshToken, string $holderId, int $expectedAuthorizationVersion, int $expectedTokenVersion, DateTimeImmutable $now): bool { return false; }
};
$binding = new class implements AuthorizerAccountBinding {
    public function bindExistingAccount(string $tenantId, string $accountId, string $componentPlatformId, string $authorizerAppId): void {}
};
$transactions = new class implements TransactionManager { public function run(callable $callback): mixed { return $callback(); } };
$completion = new AuthorizationCompletionService($intents, $componentTokens, $authorizerClient, $authorizations, $binding, $transactions, $audit, 30);
$callback = new AuthorizationCallbackService($intents, $completion);

$result = $callback->handle('opaque-browser-state', 'browser-auth-code', $now, 'req-callback', 'trace-callback');
expectSame(hash('sha256', 'opaque-browser-state'), $intents->stateLookups[0] ?? null, 'browser callback resolves intent only through SHA-256 of opaque state');
expectSame('completed', $result->status(), 'valid browser callback delegates to shared completion service');
expectSame('wx-authorizer-callback', $result->authorizerAppId(), 'browser callback returns safe authorizer result');
expectSame(1, $authorizerClient->queryCalls, 'valid callback performs one shared query-auth exchange');

try {
    $callback->handle('unknown-browser-state', 'must-not-be-used', $now, 'req-unknown', 'trace-unknown');
    throw new RuntimeException('unknown callback state must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'unknown callback state maps to UNAUTHORIZED');
    expectSame(401, $e->httpStatus(), 'unknown callback state maps to HTTP 401');
}
expectSame(1, $authorizerClient->queryCalls, 'unknown callback state never reaches provider exchange');
