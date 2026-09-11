<?php

declare(strict_types=1);

use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\contract\TransactionManager;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\application\AuthorizationCompletionService;
use modules\openplatform\application\ComponentAccessTokenService;
use modules\openplatform\contract\AuthorizationIntentRepository;
use modules\openplatform\contract\AuthorizerAccountBinding;
use modules\openplatform\contract\AuthorizerAuthorizationRepository;
use modules\openplatform\contract\AuthorizerClient;
use modules\openplatform\contract\AuthorizerProvisioningRepository;
use modules\openplatform\contract\ComponentCredentialProvider;
use modules\openplatform\contract\ComponentPlatformRepository;
use modules\openplatform\contract\ComponentRefreshLeaseRepository;
use modules\openplatform\contract\ComponentTicketRepository;
use modules\openplatform\contract\ComponentTokenClient;
use modules\openplatform\contract\ComponentTokenRepository;
use modules\openplatform\contract\ProvisioningJobRepository;
use modules\openplatform\domain\AuthorizationIntent;
use modules\openplatform\domain\AuthorizationIntentMode;
use modules\openplatform\domain\AuthorizerAccessToken;
use modules\openplatform\domain\AuthorizerAuthorization;
use modules\openplatform\domain\AuthorizerAuthorizationResponse;
use modules\openplatform\domain\AuthorizerInfoResponse;
use modules\openplatform\domain\AuthorizerProvisioning;
use modules\openplatform\domain\AuthorizerProvisioningStatus;
use modules\openplatform\domain\AuthorizerRefreshResponse;
use modules\openplatform\domain\ComponentAccessToken;
use modules\openplatform\domain\ComponentPlatform;
use modules\openplatform\domain\ComponentTicketWriteResult;
use modules\openplatform\domain\ComponentTokenRefreshLease;
use modules\openplatform\domain\ComponentTokenResponse;
use modules\openplatform\domain\ComponentVerifyTicket;
use modules\openplatform\domain\PreAuthCodeResponse;
use modules\openplatform\domain\ProvisioningJob;
use modules\openplatform\domain\ProvisioningJobStatus;

$now = new DateTimeImmutable('2026-09-09T06:00:00Z');
$makeIntent = static fn (string $id): AuthorizationIntent => AuthorizationIntent::pending(
    $id,
    'platform-1',
    'tenant-1',
    AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT,
    null,
    hash('sha256', 'auto-state-' . $id),
    hash('sha256', 'auto-pre-' . $id),
    '3',
    $now,
    $now->modify('+600 seconds'),
    $now->modify('+300 seconds'),
);

$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform { return $componentPlatformId === 'platform-1' ? $this->platform : null; }
};
$componentTokenRepo = new class($now) implements ComponentTokenRepository {
    public function __construct(private DateTimeImmutable $now) {}
    public function current(string $componentPlatformId): ?ComponentAccessToken
    {
        return $componentPlatformId === 'platform-1'
            ? new ComponentAccessToken('platform-1', 'wx-component-1', 'component-token', $this->now->modify('-10 seconds'), $this->now->modify('+3600 seconds'), 1)
            : null;
    }
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
$componentTokens = new ComponentAccessTokenService($platforms, $tickets, $componentTokenRepo, $leases, $credentials, $componentClient, $audit);

$provider = new class implements AuthorizerClient {
    public int $queryCalls = 0;
    public function createPreAuthCode(string $componentAppId, string $componentAccessToken): PreAuthCodeResponse { throw new RuntimeException('not used'); }
    public function queryAuthorization(string $componentAppId, string $componentAccessToken, string $authorizationCode): AuthorizerAuthorizationResponse
    {
        $this->queryCalls++;
        return new AuthorizerAuthorizationResponse('wx-auto-authorizer', 'auto-access-secret', 'auto-refresh-secret', 7200, ['17']);
    }
    public function refreshAuthorizerToken(string $componentAppId, string $componentAccessToken, string $authorizerAppId, string $authorizerRefreshToken): AuthorizerRefreshResponse { throw new RuntimeException('not used'); }
    public function getAuthorizerInfo(string $componentAppId, string $componentAccessToken, string $authorizerAppId): AuthorizerInfoResponse { throw new RuntimeException('completion must not fetch metadata'); }
};

$newHarness = static function (AuthorizationIntent $intent) use ($componentTokens, $provider, $audit): array {
    $intents = new class($intent) implements AuthorizationIntentRepository {
        public AuthorizationIntent $current;
        public bool $failComplete = false;
        public array $released = [];
        public function __construct(AuthorizationIntent $intent) { $this->current = $intent; }
        public function insert(AuthorizationIntent $intent): void { $this->current = $intent; }
        public function findByStateHash(string $stateHash): ?AuthorizationIntent { return hash_equals($this->current->stateHash(), $stateHash) ? $this->current : null; }
        public function findByPreAuthCodeHash(string $componentPlatformId, string $preAuthCodeHash): ?AuthorizationIntent { return null; }
        public function tryClaim(string $intentId, string $holderId, DateTimeImmutable $now, int $leaseSeconds, int $expectedVersion): ?AuthorizationIntent
        {
            if ($this->current->id() !== $intentId || $this->current->version() !== $expectedVersion || !$this->current->claimableAt($now)) { return null; }
            $this->current = $this->current->withClaim($holderId, $now->modify('+' . $leaseSeconds . ' seconds'));
            return $this->current;
        }
        public function releaseClaim(string $intentId, string $holderId): void { $this->released[] = [$intentId, $holderId]; }
        public function complete(string $intentId, string $holderId, string $authorizerAppId, DateTimeImmutable $now, int $expectedVersion): bool
        {
            if ($this->failComplete) { return false; }
            if ($this->current->id() !== $intentId || $this->current->claimHolderId() !== $holderId || $this->current->version() !== $expectedVersion) { return false; }
            $this->current = $this->current->completedBy($authorizerAppId, $now);
            return true;
        }
    };

    $authorizations = new class implements AuthorizerAuthorizationRepository {
        public ?AuthorizerAuthorization $currentAuthorization = null;
        public array $saved = [];
        public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAuthorization { return $this->currentAuthorization; }
        public function saveFromAuthorization(AuthorizerAuthorization $authorization, string $refreshToken, string $accessToken, DateTimeImmutable $accessTokenExpiresAt): bool
        {
            $this->currentAuthorization = $authorization;
            $this->saved[] = [$authorization, $refreshToken, $accessToken, $accessTokenExpiresAt];
            return true;
        }
        public function markUnauthorized(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $sourceTimestamp, int $expectedVersion): bool { return false; }
        public function compareAndSetRefresh(AuthorizerAuthorization $authorization, AuthorizerAccessToken $token, string $refreshToken, string $holderId, int $expectedAuthorizationVersion, int $expectedTokenVersion, DateTimeImmutable $now): bool { return false; }
    };

    $provisionings = new class implements AuthorizerProvisioningRepository {
        /** @var array<string,AuthorizerProvisioning> */ public array $rows = [];
        public bool $failInsert = false;
        public function insert(AuthorizerProvisioning $provisioning): void
        {
            if ($this->failInsert) { throw new RuntimeException('injected provisioning insert failure'); }
            if (isset($this->rows[$provisioning->id()])) { throw new RuntimeException('duplicate provisioning'); }
            $this->rows[$provisioning->id()] = $provisioning;
        }
        public function find(string $id): ?AuthorizerProvisioning { return $this->rows[$id] ?? null; }
        public function findForTenant(string $id, string $tenantId): ?AuthorizerProvisioning { $row = $this->rows[$id] ?? null; return $row !== null && $row->tenantId() === $tenantId ? $row : null; }
        public function findBySourceIntent(string $sourceIntentId): ?AuthorizerProvisioning { foreach ($this->rows as $row) { if ($row->sourceIntentId() === $sourceIntentId) { return $row; } } return null; }
        public function save(AuthorizerProvisioning $next, int $expectedVersion): bool { return false; }
    };

    $jobs = new class implements ProvisioningJobRepository {
        /** @var array<string,ProvisioningJob> */ public array $rows = [];
        public bool $failInsert = false;
        public function insert(ProvisioningJob $job): void
        {
            if ($this->failInsert) { throw new RuntimeException('injected job insert failure'); }
            if (isset($this->rows[$job->provisioningId()])) { throw new RuntimeException('duplicate job'); }
            $this->rows[$job->provisioningId()] = $job;
        }
        public function tryClaim(string $provisioningId, string $holderId, DateTimeImmutable $now, int $ttlSeconds): ?ProvisioningJob { return null; }
        public function release(string $provisioningId, string $holderId, DateTimeImmutable $nextAttemptAt, ?string $errorCode): bool { return false; }
        public function complete(string $provisioningId, string $holderId): bool { return false; }
        public function dead(string $provisioningId, string $holderId, string $errorCode): bool { return false; }
    };

    $binding = new class implements AuthorizerAccountBinding {
        public int $calls = 0;
        public function bindExistingAccount(string $tenantId, string $accountId, string $componentPlatformId, string $authorizerAppId): void { $this->calls++; }
    };

    $transactions = new class($intents, $authorizations, $provisionings, $jobs) implements TransactionManager {
        public function __construct(private object $intents, private object $authorizations, private object $provisionings, private object $jobs) {}
        public function run(callable $callback): mixed
        {
            $snapshot = [
                $this->intents->current,
                $this->authorizations->currentAuthorization,
                $this->authorizations->saved,
                $this->provisionings->rows,
                $this->jobs->rows,
            ];
            try {
                return $callback();
            } catch (Throwable $e) {
                [$this->intents->current, $this->authorizations->currentAuthorization, $this->authorizations->saved, $this->provisionings->rows, $this->jobs->rows] = $snapshot;
                throw $e;
            }
        }
    };

    $service = new AuthorizationCompletionService(
        $intents,
        $componentTokens,
        $provider,
        $authorizations,
        $binding,
        $transactions,
        $audit,
        30,
        $provisionings,
        $jobs,
    );

    return [$service, $intents, $authorizations, $provisionings, $jobs, $binding];
};

[$service, $intents, $authorizations, $provisionings, $jobs, $binding] = $newHarness($makeIntent('intent-auto-success'));
$queryBefore = $provider->queryCalls;
$result = $service->completeIntent($intents->current, 'auto-auth-code', $now, 'req-auto', 'trace-auto');
expectSame('provisioning', $result->status(), 'auto-provision completion returns provisioning status');
expectSame('wx-auto-authorizer', $result->authorizerAppId(), 'auto-provision result exposes safe authorizer AppId');
expectTrue(is_string($result->provisioningId()) && $result->provisioningId() !== '', 'auto-provision result exposes durable provisioning id');
expectSame($queryBefore + 1, $provider->queryCalls, 'auto-provision claim winner exchanges authorization code exactly once');
expectSame(0, $binding->calls, 'auto-provision mode makes zero existing Account binding calls');
expectSame(1, count($provisionings->rows), 'auto-provision completion writes exactly one durable provisioning');
expectSame(1, count($jobs->rows), 'auto-provision completion writes exactly one durable job');
$provisioning = array_values($provisionings->rows)[0];
$job = array_values($jobs->rows)[0];
expectSame(AuthorizerProvisioningStatus::PENDING_METADATA, $provisioning->status(), 'durable provisioning starts PENDING_METADATA');
expectSame($intents->current->id(), $provisioning->sourceIntentId(), 'durable provisioning is keyed to source intent');
expectSame(ProvisioningJobStatus::READY, $job->status(), 'durable provisioning job starts READY');
expectSame($provisioning->id(), $job->provisioningId(), 'job and provisioning use the same durable id');
expectTrue($intents->current->completed(), 'intent completes only with durable provisioning trigger present');

$again = $service->completeIntent($intents->current, 'must-not-exchange-again', $now->modify('+1 second'), 'req-auto-again', 'trace-auto-again');
expectSame('provisioning', $again->status(), 'completed auto intent returns idempotent provisioning result');
expectSame($provisioning->id(), $again->provisioningId(), 'idempotent auto result preserves provisioning id');
expectSame($queryBefore + 1, $provider->queryCalls, 'completed auto intent never exchanges a second authorization code');
expectSame(1, count($provisionings->rows), 'completed auto intent never creates second provisioning');
expectSame(1, count($jobs->rows), 'completed auto intent never creates second job');

$rollbackCases = [
    'provisioning_insert' => static function (object $intents, object $provisionings, object $jobs): void { $provisionings->failInsert = true; },
    'job_insert' => static function (object $intents, object $provisionings, object $jobs): void { $jobs->failInsert = true; },
    'intent_complete' => static function (object $intents, object $provisionings, object $jobs): void { $intents->failComplete = true; },
];
foreach ($rollbackCases as $stage => $inject) {
    [$failureService, $failureIntents, $failureAuthorizations, $failureProvisionings, $failureJobs, $failureBinding] = $newHarness($makeIntent('intent-auto-fail-' . $stage));
    $inject($failureIntents, $failureProvisionings, $failureJobs);
    try {
        $failureService->completeIntent($failureIntents->current, 'auto-code-' . $stage, $now, 'req-' . $stage, 'trace-' . $stage);
        throw new RuntimeException('injected auto-provision transaction failure must propagate');
    } catch (Throwable) {
        // Expected. Transaction fake restores all durable state written inside the completion transaction.
    }
    expectTrue(!$failureIntents->current->completed(), $stage . ' rollback never leaves completed intent');
    expectSame(null, $failureAuthorizations->currentAuthorization, $stage . ' rollback removes authorization write');
    expectSame(0, count($failureProvisionings->rows), $stage . ' rollback removes provisioning write');
    expectSame(0, count($failureJobs->rows), $stage . ' rollback removes job write');
    expectSame(0, $failureBinding->calls, $stage . ' auto mode never falls through to existing Account binding');
}
