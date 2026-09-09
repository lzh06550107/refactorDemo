<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\contract\TransactionManager;
use app\common\error\AppException;
use app\common\error\ErrorCode;
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
use app\openplatform\domain\AuthorizerInfoResponse;
use app\openplatform\domain\AuthorizerRefreshResponse;
use app\openplatform\domain\ComponentAccessToken;
use app\openplatform\domain\ComponentPlatform;
use app\openplatform\domain\ComponentTicketWriteResult;
use app\openplatform\domain\ComponentTokenRefreshLease;
use app\openplatform\domain\ComponentTokenResponse;
use app\openplatform\domain\ComponentVerifyTicket;
use app\openplatform\domain\PreAuthCodeResponse;

$now = new DateTimeImmutable('2026-09-08T09:45:00Z');
$pending = static function (string $id = 'intent-1', ?DateTimeImmutable $createdAt = null, ?DateTimeImmutable $expiresAt = null) use ($now): AuthorizationIntent {
    $createdAt ??= $now;
    $expiresAt ??= $createdAt->modify('+600 seconds');
    return AuthorizationIntent::pending(
        $id,
        'platform-1',
        'tenant-1',
        AuthorizationIntentMode::BIND_EXISTING_ACCOUNT,
        'account-1',
        hash('sha256', 'state-' . $id),
        hash('sha256', 'pre-' . $id),
        '1',
        $createdAt,
        $expiresAt,
        $expiresAt,
    );
};

$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform { return $componentPlatformId === 'platform-1' ? $this->platform : null; }
};
$cachedToken = new ComponentAccessToken('platform-1', 'wx-component-1', 'component-token-secret', $now->modify('-60 seconds'), $now->modify('+3600 seconds'), 1);
$componentTokenRepo = new class($cachedToken) implements ComponentTokenRepository {
    public function __construct(private ComponentAccessToken $token) {}
    public function current(string $componentPlatformId): ?ComponentAccessToken { return $componentPlatformId === 'platform-1' ? $this->token : null; }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool { throw new RuntimeException('cached component token must not CAS'); }
};
$ticketRepo = new class implements ComponentTicketRepository {
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return null; }
    public function accept(ComponentVerifyTicket $incoming, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new RuntimeException('completion fast path must not touch component ticket'); }
};
$leaseRepo = new class implements ComponentRefreshLeaseRepository {
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease { throw new RuntimeException('cached component token must not acquire lease'); }
    public function release(string $componentPlatformId, string $holderId): void {}
};
$credentials = new class implements ComponentCredentialProvider {
    public function secretFor(string $credentialRef): string { throw new RuntimeException('cached component token must not resolve component secret'); }
};
$componentClient = new class implements ComponentTokenClient {
    public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse { throw new RuntimeException('cached component token must not call provider'); }
};
$noopAudit = new class implements AuditLogger { public function record(AuditEvent $event): void {} };
$componentTokens = new ComponentAccessTokenService($platforms, $ticketRepo, $componentTokenRepo, $leaseRepo, $credentials, $componentClient, $noopAudit);

$intents = new class($pending()) implements AuthorizationIntentRepository {
    public AuthorizationIntent $current;
    public array $released = [];
    public int $tryClaimCalls = 0;
    public function __construct(AuthorizationIntent $intent) { $this->current = $intent; }
    public function insert(AuthorizationIntent $intent): void { $this->current = $intent; }
    public function findByStateHash(string $stateHash): ?AuthorizationIntent { return hash_equals($this->current->stateHash(), $stateHash) ? $this->current : null; }
    public function findByPreAuthCodeHash(string $componentPlatformId, string $preAuthCodeHash): ?AuthorizationIntent
    {
        return $componentPlatformId === $this->current->componentPlatformId() && hash_equals($this->current->preAuthCodeHash(), $preAuthCodeHash) ? $this->current : null;
    }
    public function tryClaim(string $intentId, string $holderId, DateTimeImmutable $now, int $leaseSeconds, int $expectedVersion): ?AuthorizationIntent
    {
        $this->tryClaimCalls++;
        if ($this->current->id() !== $intentId || $this->current->version() !== $expectedVersion || !$this->current->claimableAt($now)) {
            return null;
        }
        $this->current = $this->current->withClaim($holderId, $now->modify('+' . $leaseSeconds . ' seconds'));
        return $this->current;
    }
    public function releaseClaim(string $intentId, string $holderId): void { $this->released[] = [$intentId, $holderId]; }
    public function complete(string $intentId, string $holderId, string $authorizerAppId, DateTimeImmutable $now, int $expectedVersion): bool
    {
        if (
            $this->current->id() !== $intentId
            || $this->current->claimHolderId() !== $holderId
            || $this->current->version() !== $expectedVersion
            || $this->current->claimExpiresAt() === null
            || $this->current->claimExpiresAt() <= $now
        ) {
            return false;
        }
        $this->current = $this->current->completedBy($authorizerAppId, $now);
        return true;
    }
};

$transactionState = new ArrayObject(['in_transaction' => false]);
$transactions = new class($transactionState) implements TransactionManager {
    public ?Closure $beforeRun = null;
    public function __construct(private ArrayObject $state) {}
    public function run(callable $callback): mixed
    {
        if ($this->beforeRun !== null) { ($this->beforeRun)(); }
        $this->state['in_transaction'] = true;
        try { return $callback(); }
        finally { $this->state['in_transaction'] = false; }
    }
};

$authorizerClient = new class($transactionState) implements AuthorizerClient {
    public array $queryCalls = [];
    public bool $throwQuery = false;
    public AuthorizerAuthorizationResponse $response;
    public function __construct(private ArrayObject $txState)
    {
        $this->response = new AuthorizerAuthorizationResponse(
            'wx-authorizer-1',
            'initial-access-secret',
            'initial-refresh-secret',
            7200,
            ['18', '17'],
        );
    }
    public function createPreAuthCode(string $componentAppId, string $componentAccessToken): PreAuthCodeResponse { throw new RuntimeException('completion must not create pre-auth code'); }
    public function queryAuthorization(string $componentAppId, string $componentAccessToken, string $authorizationCode): AuthorizerAuthorizationResponse
    {
        if (($this->txState['in_transaction'] ?? false) === true) { throw new RuntimeException('provider query must run outside transaction'); }
        $this->queryCalls[] = [$componentAppId, $componentAccessToken, $authorizationCode];
        if ($this->throwQuery) { throw new AppException(ErrorCode::BAD_GATEWAY, 'sanitized provider failure', 502); }
        return $this->response;
    }
    public function refreshAuthorizerToken(string $componentAppId, string $componentAccessToken, string $authorizerAppId, string $authorizerRefreshToken): AuthorizerRefreshResponse { throw new RuntimeException('completion must not refresh authorizer token'); }
    public function getAuthorizerInfo(string $componentAppId, string $componentAccessToken, string $authorizerAppId): AuthorizerInfoResponse { throw new RuntimeException('completion must not fetch authorizer metadata'); }
};

$authorizations = new class implements AuthorizerAuthorizationRepository {
    public ?AuthorizerAuthorization $currentAuthorization = null;
    public array $saved = [];
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAuthorization
    {
        return $this->currentAuthorization !== null
            && $this->currentAuthorization->componentPlatformId() === $componentPlatformId
            && $this->currentAuthorization->authorizerAppId() === $authorizerAppId
            ? $this->currentAuthorization : null;
    }
    public function saveFromAuthorization(AuthorizerAuthorization $authorization, string $refreshToken, string $accessToken, DateTimeImmutable $accessTokenExpiresAt): bool
    {
        $this->saved[] = [$authorization, $refreshToken, $accessToken, $accessTokenExpiresAt];
        $this->currentAuthorization = $authorization;
        return true;
    }
    public function markUnauthorized(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $sourceTimestamp, int $expectedVersion): bool { throw new RuntimeException('not used in completion'); }
    public function compareAndSetRefresh(AuthorizerAuthorization $authorization, AuthorizerAccessToken $token, string $refreshToken, string $holderId, int $expectedAuthorizationVersion, int $expectedTokenVersion, DateTimeImmutable $now): bool { throw new RuntimeException('not used in completion'); }
};
$binding = new class implements AuthorizerAccountBinding {
    public array $calls = [];
    public function bindExistingAccount(string $tenantId, string $accountId, string $componentPlatformId, string $authorizerAppId): void
    {
        $this->calls[] = [$tenantId, $accountId, $componentPlatformId, $authorizerAppId];
    }
};
$audit = new class implements AuditLogger {
    public array $events = [];
    public function record(AuditEvent $event): void { $this->events[] = $event->toArray(); }
};

$service = new AuthorizationCompletionService(
    $intents,
    $componentTokens,
    $authorizerClient,
    $authorizations,
    $binding,
    $transactions,
    $audit,
    30,
);

$result = $service->completeIntent($pending(), 'authorization-code-secret', $now, 'req-1', 'trace-1');
expectSame('completed', $result->status(), 'claim winner completes authorization');
expectSame('wx-authorizer-1', $result->authorizerAppId(), 'safe result exposes only authorizer AppId');
expectSame(1, count($authorizerClient->queryCalls), 'claim winner performs exactly one query-auth exchange');
expectSame(['wx-component-1', 'component-token-secret', 'authorization-code-secret'], $authorizerClient->queryCalls[0], 'query-auth receives trusted component identity and ephemeral code');
expectSame(hash('sha256', 'initial-refresh-secret'), $authorizations->saved[0][0]->refreshTokenHash(), 'authorization stores only refresh-token hash in domain metadata');
expectSame(['17', '18'], $authorizations->saved[0][0]->scopeSet(), 'authorization persists normalized safe scopes');
expectSame('initial-refresh-secret', $authorizations->saved[0][1], 'plaintext refresh token crosses only repository protection boundary');
expectSame('initial-access-secret', $authorizations->saved[0][2], 'plaintext access token crosses only repository protection boundary');
expectSame($now->modify('+7200 seconds')->getTimestamp(), $authorizations->saved[0][3]->getTimestamp(), 'provider expiry defines initial authorizer token expiry');
expectSame([['tenant-1', 'account-1', 'platform-1', 'wx-authorizer-1']], $binding->calls, 'existing-account binding receives exact intent and returned authorizer identity');
expectTrue($intents->current->completed(), 'intent becomes completed only after successful provider exchange and transactional save');
expectSame('wx-authorizer-1', $intents->current->completedAuthorizerAppId(), 'completed intent stores safe authorizer AppId');
$auditJson = json_encode($audit->events);
expectTrue(
    is_string($auditJson)
    && !str_contains($auditJson, 'authorization-code-secret')
    && !str_contains($auditJson, 'initial-refresh-secret')
    && !str_contains($auditJson, 'initial-access-secret'),
    'completion audit contains no authorization code or provider tokens',
);

$again = $service->completeIntent($pending(), 'different-code-must-not-be-used', $now->modify('+1 second'), 'req-2', 'trace-2');
expectSame('completed', $again->status(), 'completed intent returns idempotent completed result');
expectSame('wx-authorizer-1', $again->authorizerAppId(), 'idempotent result preserves safe authorizer AppId');
expectSame(1, count($authorizerClient->queryCalls), 'completed intent never exchanges a second authorization code');

$busyBase = $pending('intent-busy');
$intents->current = $busyBase->withClaim('other-holder', $now->modify('+30 seconds'));
$authorizerClient->queryCalls = [];
$busy = $service->completeIntent($busyBase, 'busy-code', $now, 'req-busy', 'trace-busy');
expectSame('processing', $busy->status(), 'busy completion claim returns processing result');
expectSame(null, $busy->authorizerAppId(), 'processing result exposes no guessed authorizer identity');
expectSame(0, count($authorizerClient->queryCalls), 'busy loser performs zero query-auth calls');

$expiredCreated = $now->modify('-700 seconds');
$expired = $pending('intent-expired', $expiredCreated, $now->modify('-100 seconds'));
$intents->current = $expired;
try {
    $service->completeIntent($expired, 'expired-code', $now, 'req-expired', 'trace-expired');
    throw new RuntimeException('expired intent must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'expired intent maps to UNAUTHORIZED');
    expectSame(401, $e->httpStatus(), 'expired intent maps to HTTP 401');
}

$failureBase = $pending('intent-provider-failure');
$intents->current = $failureBase;
$authorizerClient->throwQuery = true;
$releasedBefore = count($intents->released);
try {
    $service->completeIntent($failureBase, 'provider-failure-code', $now, 'req-fail', 'trace-fail');
    throw new RuntimeException('provider failure must propagate sanitized BAD_GATEWAY');
} catch (AppException $e) {
    expectSame(ErrorCode::BAD_GATEWAY, $e->errorCode(), 'query-auth provider failure remains BAD_GATEWAY');
}
expectSame($releasedBefore + 1, count($intents->released), 'provider failure releases still-valid completion claim for retry');
$authorizerClient->throwQuery = false;

$staleBase = $pending('intent-stale-tx');
$intents->current = $staleBase;
$savedBefore = count($authorizations->saved);
$bindingBefore = count($binding->calls);
$transactions->beforeRun = function () use ($intents, $now): void {
    $intents->current = $intents->current->withClaim('stale-other-holder', $now->modify('+30 seconds'));
};
try {
    $service->completeIntent($staleBase, 'stale-code', $now, 'req-stale', 'trace-stale');
    throw new RuntimeException('stale claim holder/version must fail before persistence');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'stale completion work maps to CONFLICT');
    expectSame(409, $e->httpStatus(), 'stale completion work maps to HTTP 409');
}
expectSame($savedBefore, count($authorizations->saved), 'stale transaction re-check prevents authorization persistence');
expectSame($bindingBefore, count($binding->calls), 'stale transaction re-check prevents Account binding');
