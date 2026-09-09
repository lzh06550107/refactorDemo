<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\contract\TransactionManager;
use app\openplatform\application\AuthorizationCallbackService;
use app\openplatform\application\AuthorizationCompletionService;
use app\openplatform\application\AuthorizationEventService;
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
use app\openplatform\domain\AuthenticatedComponentEvent;
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

$now = new DateTimeImmutable('2026-09-08T10:00:00Z');
$source = $now->modify('-10 seconds');
$statePlain = 'event-correlated-state';
$preAuthPlain = 'event-correlated-pre-auth';
$intent = AuthorizationIntent::pending(
    'intent-event-1', 'platform-1', 'tenant-1', AuthorizationIntentMode::BIND_EXISTING_ACCOUNT, 'account-1',
    hash('sha256', $statePlain), hash('sha256', $preAuthPlain), '1',
    $now->modify('-60 seconds'), $now->modify('+540 seconds'), $now->modify('+240 seconds'),
);
$intents = new class($intent) implements AuthorizationIntentRepository {
    public ?AuthorizationIntent $current;
    public function __construct(?AuthorizationIntent $intent) { $this->current = $intent; }
    public function insert(AuthorizationIntent $intent): void { $this->current = $intent; }
    public function findByStateHash(string $stateHash): ?AuthorizationIntent
    { return $this->current !== null && hash_equals($this->current->stateHash(), $stateHash) ? $this->current : null; }
    public function findByPreAuthCodeHash(string $componentPlatformId, string $preAuthCodeHash): ?AuthorizationIntent
    { return $this->current !== null && $this->current->componentPlatformId() === $componentPlatformId && hash_equals($this->current->preAuthCodeHash(), $preAuthCodeHash) ? $this->current : null; }
    public function tryClaim(string $intentId, string $holderId, DateTimeImmutable $now, int $leaseSeconds, int $expectedVersion): ?AuthorizationIntent
    {
        if ($this->current === null || $this->current->id() !== $intentId || $this->current->version() !== $expectedVersion || !$this->current->claimableAt($now)) { return null; }
        $this->current = $this->current->withClaim($holderId, $now->modify('+' . $leaseSeconds . ' seconds'));
        return $this->current;
    }
    public function releaseClaim(string $intentId, string $holderId): void {}
    public function complete(string $intentId, string $holderId, string $authorizerAppId, DateTimeImmutable $now, int $expectedVersion): bool
    {
        if ($this->current === null || $this->current->claimHolderId() !== $holderId || $this->current->version() !== $expectedVersion) { return false; }
        $this->current = $this->current->completedBy($authorizerAppId, $now);
        return true;
    }
};
$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform { return $componentPlatformId === 'platform-1' ? $this->platform : null; }
};
$componentToken = new ComponentAccessToken('platform-1', 'wx-component-1', 'component-access-secret', $now->modify('-60 seconds'), $now->modify('+3600 seconds'), 1);
$tokenRepo = new class($componentToken) implements ComponentTokenRepository {
    public function __construct(private ComponentAccessToken $token) {}
    public function current(string $componentPlatformId): ?ComponentAccessToken { return $componentPlatformId === 'platform-1' ? $this->token : null; }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool { return false; }
};
$tickets = new class implements ComponentTicketRepository {
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return null; }
    public function accept(ComponentVerifyTicket $incoming, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new RuntimeException('not used'); }
};
$leases = new class implements ComponentRefreshLeaseRepository {
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease { throw new RuntimeException('cached component token must not acquire lease'); }
    public function release(string $componentPlatformId, string $holderId): void {}
};
$credentials = new class implements ComponentCredentialProvider { public function secretFor(string $credentialRef): string { throw new RuntimeException('not used'); } };
$componentClient = new class implements ComponentTokenClient { public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse { throw new RuntimeException('not used'); } };
$audit = new class implements AuditLogger { public array $events = []; public function record(AuditEvent $event): void { $this->events[] = $event->toArray(); } };
$componentTokens = new ComponentAccessTokenService($platforms, $tickets, $tokenRepo, $leases, $credentials, $componentClient, $audit);

$authorizerClient = new class implements AuthorizerClient {
    public int $queryCalls = 0;
    public AuthorizerAuthorizationResponse $response;
    public function __construct() { $this->response = new AuthorizerAuthorizationResponse('wx-authorizer-1', 'access-1', 'refresh-1', 7200, ['17']); }
    public function createPreAuthCode(string $componentAppId, string $componentAccessToken): PreAuthCodeResponse { throw new RuntimeException('not used'); }
    public function queryAuthorization(string $componentAppId, string $componentAccessToken, string $authorizationCode): AuthorizerAuthorizationResponse { $this->queryCalls++; return $this->response; }
    public function refreshAuthorizerToken(string $componentAppId, string $componentAccessToken, string $authorizerAppId, string $authorizerRefreshToken): AuthorizerRefreshResponse { throw new RuntimeException('not used'); }
};
$authorizations = new class implements AuthorizerAuthorizationRepository {
    public array $rows = [];
    public array $saveCalls = [];
    public bool $tokenAvailable = false;
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAuthorization { return $this->rows[$componentPlatformId . ':' . $authorizerAppId] ?? null; }
    public function saveFromAuthorization(AuthorizerAuthorization $authorization, string $refreshToken, string $accessToken, DateTimeImmutable $accessTokenExpiresAt): bool
    { $this->rows[$authorization->componentPlatformId() . ':' . $authorization->authorizerAppId()] = $authorization; $this->saveCalls[] = [$authorization, $refreshToken, $accessToken, $accessTokenExpiresAt]; $this->tokenAvailable = true; return true; }
    public function markUnauthorized(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $sourceTimestamp, int $expectedVersion): bool
    {
        $key = $componentPlatformId . ':' . $authorizerAppId; $current = $this->rows[$key] ?? null;
        if (!$current instanceof AuthorizerAuthorization || $current->version() !== $expectedVersion) { return false; }
        $this->rows[$key] = $current->withUnauthorized($sourceTimestamp); $this->tokenAvailable = false; return true;
    }
    public function compareAndSetRefresh(AuthorizerAuthorization $authorization, AuthorizerAccessToken $token, string $refreshToken, string $holderId, int $expectedAuthorizationVersion, int $expectedTokenVersion, DateTimeImmutable $now): bool { throw new RuntimeException('not used'); }
};
$binding = new class implements AuthorizerAccountBinding { public array $calls = []; public function bindExistingAccount(string $tenantId, string $accountId, string $componentPlatformId, string $authorizerAppId): void { $this->calls[] = [$tenantId, $accountId, $componentPlatformId, $authorizerAppId]; } };
$transactions = new class implements TransactionManager { public function run(callable $callback): mixed { return $callback(); } };
$completion = new AuthorizationCompletionService($intents, $componentTokens, $authorizerClient, $authorizations, $binding, $transactions, $audit, 30);
$events = new AuthorizationEventService($intents, $completion, $componentTokens, $authorizerClient, $authorizations, $audit);

$makeEvent = static function (string $type, DateTimeImmutable $timestamp, string $authorizerAppId, ?string $code = null, ?string $preAuth = null): AuthenticatedComponentEvent {
    return new AuthenticatedComponentEvent(
        'platform-1', 'wx-component-1', $type, $timestamp,
        hash('sha256', $type . ':replay:' . $timestamp->format('U.u') . ':' . $authorizerAppId),
        hash('sha256', $type . ':payload:' . $timestamp->format('U.u') . ':' . $authorizerAppId),
        null, $authorizerAppId, $code, null, $preAuth,
    );
};

$events->handle($makeEvent('authorized', $source, 'wx-authorizer-1', 'event-auth-code-1', $preAuthPlain), $now, 'req-event-1', 'trace-event-1');
expectSame(1, $authorizerClient->queryCalls, 'correlated authorized event wins shared claim and exchanges exactly once');
expectTrue($intents->current?->completed() === true, 'correlated authorized event completes the same local intent');
expectSame([['tenant-1', 'account-1', 'platform-1', 'wx-authorizer-1']], $binding->calls, 'correlated authorized event performs existing Account binding through shared completion');
$stored = $authorizations->current('platform-1', 'wx-authorizer-1');
expectSame($source->getTimestamp(), $stored?->providerUpdatedAt()->getTimestamp(), 'event-driven completion uses authenticated source timestamp for provider ordering');

$callback = new AuthorizationCallbackService($intents, $completion);
$callback->handle($statePlain, 'late-browser-code', $now->modify('+1 second'), 'req-browser-late', 'trace-browser-late');
expectSame(1, $authorizerClient->queryCalls, 'browser callback after event completion does not exchange a second code');

$intents->current = null;
$authorizerClient->response = new AuthorizerAuthorizationResponse('wx-authorizer-2', 'access-2', 'refresh-2', 7200, ['18']);
$eventOnlySource = $source->modify('+20 seconds');
$events->handle($makeEvent('authorized', $eventOnlySource, 'wx-authorizer-2', 'event-only-code', null), $now, 'req-event-only', 'trace-event-only');
expectSame(2, $authorizerClient->queryCalls, 'event-only authorized exchanges its authenticated authorization code');
expectSame(1, count($binding->calls), 'event-only authorized never invents a Tenant/Account binding');
expectTrue($authorizations->current('platform-1', 'wx-authorizer-2')?->isActive() === true, 'event-only authorized creates platform-level authorization');

$authorizerClient->response = new AuthorizerAuthorizationResponse('wx-authorizer-2', 'access-2b', 'refresh-2b', 7200, ['17', '18']);
$updateSource = $eventOnlySource->modify('+10 seconds');
$events->handle($makeEvent('updateauthorized', $updateSource, 'wx-authorizer-2', 'update-code', null), $now, 'req-update', 'trace-update');
expectSame(3, $authorizerClient->queryCalls, 'updateauthorized exchanges code through authorizer client');
expectSame(1, count($binding->calls), 'updateauthorized never binds an Account');
expectSame(2, $authorizations->current('platform-1', 'wx-authorizer-2')?->version(), 'newer updateauthorized advances authorization version');

$unauthorizedSource = $updateSource->modify('+10 seconds');
$events->handle($makeEvent('unauthorized', $unauthorizedSource, 'wx-authorizer-2'), $now, 'req-unauth', 'trace-unauth');
expectSame(3, $authorizerClient->queryCalls, 'unauthorized never calls provider query API');
expectSame('unauthorized', $authorizations->current('platform-1', 'wx-authorizer-2')?->status(), 'unauthorized transitions platform grant to unauthorized');
expectTrue(!$authorizations->tokenAvailable, 'unauthorized repository mutation invalidates usable authorizer token state');

$versionAfterUnauthorized = $authorizations->current('platform-1', 'wx-authorizer-2')?->version();
$events->handle($makeEvent('unauthorized', $updateSource, 'wx-authorizer-2'), $now, 'req-old-unauth', 'trace-old-unauth');
expectSame($versionAfterUnauthorized, $authorizations->current('platform-1', 'wx-authorizer-2')?->version(), 'older unauthorized event is stale no-op');
