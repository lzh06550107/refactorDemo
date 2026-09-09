<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\common\audit\AuditEvent;
use app\common\contract\AuditLogger;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\application\AuthorizerConnectionService;
use app\openplatform\application\AuthorizerMetadataNormalizer;
use app\openplatform\application\AuthorizerMetadataSyncService;
use app\openplatform\application\AuthorizerOwnershipResolver;
use app\openplatform\application\AuthorizerProvisioningWorker;
use app\openplatform\application\ComponentAccessTokenService;
use app\openplatform\contract\AuthorizerAuthorizationRepository;
use app\openplatform\contract\AuthorizerClient;
use app\openplatform\contract\AuthorizerConnectionStore;
use app\openplatform\contract\AuthorizerMetadataRepository;
use app\openplatform\contract\AuthorizerOwnershipRepository;
use app\openplatform\contract\AuthorizerProvisioningRepository;
use app\openplatform\contract\ComponentCredentialProvider;
use app\openplatform\contract\ComponentPlatformRepository;
use app\openplatform\contract\ComponentRefreshLeaseRepository;
use app\openplatform\contract\ComponentTicketRepository;
use app\openplatform\contract\ComponentTokenClient;
use app\openplatform\contract\ComponentTokenRepository;
use app\openplatform\contract\ProvisioningJobRepository;
use app\openplatform\domain\AuthorizerAccessToken;
use app\openplatform\domain\AuthorizerAccountOwnership;
use app\openplatform\domain\AuthorizerAuthorization;
use app\openplatform\domain\AuthorizerAuthorizationResponse;
use app\openplatform\domain\AuthorizerInfoResponse;
use app\openplatform\domain\AuthorizerMetadata;
use app\openplatform\domain\AuthorizerMetadataRecord;
use app\openplatform\domain\AuthorizerProvisioning;
use app\openplatform\domain\AuthorizerProvisioningStatus;
use app\openplatform\domain\AuthorizerRefreshResponse;
use app\openplatform\domain\ComponentAccessToken;
use app\openplatform\domain\ComponentPlatform;
use app\openplatform\domain\ComponentTicketWriteResult;
use app\openplatform\domain\ComponentTokenRefreshLease;
use app\openplatform\domain\ComponentTokenResponse;
use app\openplatform\domain\ComponentVerifyTicket;
use app\openplatform\domain\PreAuthCodeResponse;
use app\openplatform\domain\ProvisioningJob;
use app\openplatform\domain\ProvisioningJobStatus;

$baseNow = new DateTimeImmutable('2026-09-09T07:00:00Z');
$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform { return $componentPlatformId === 'platform-1' ? $this->platform : null; }
};
$componentTokenRepo = new class($baseNow) implements ComponentTokenRepository {
    public function __construct(private DateTimeImmutable $now) {}
    public function current(string $componentPlatformId): ?ComponentAccessToken
    {
        return new ComponentAccessToken('platform-1', 'wx-component-1', 'component-token', $this->now->modify('-1 second'), $this->now->modify('+7200 seconds'), 1);
    }
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool { return false; }
};
$tickets = new class implements ComponentTicketRepository {
    public function current(string $componentPlatformId): ?ComponentVerifyTicket { return null; }
    public function accept(ComponentVerifyTicket $incoming, string $replayKey, string $payloadHash): ComponentTicketWriteResult { throw new RuntimeException('not used'); }
};
$componentLeases = new class implements ComponentRefreshLeaseRepository {
    public function tryAcquire(string $componentPlatformId, string $holderId, DateTimeImmutable $now, int $leaseSeconds): ?ComponentTokenRefreshLease { throw new RuntimeException('cached token must not refresh'); }
    public function release(string $componentPlatformId, string $holderId): void {}
};
$credentials = new class implements ComponentCredentialProvider { public function secretFor(string $credentialRef): string { throw new RuntimeException('not used'); } };
$componentClient = new class implements ComponentTokenClient { public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse { throw new RuntimeException('not used'); } };
$audit = new class implements AuditLogger { public function record(AuditEvent $event): void {} };
$componentTokens = new ComponentAccessTokenService($platforms, $tickets, $componentTokenRepo, $componentLeases, $credentials, $componentClient, $audit);

$provider = new class implements AuthorizerClient {
    public int $metadataCalls = 0;
    public bool $throwMetadata = false;
    public AuthorizerInfoResponse $info;
    public function __construct()
    {
        $this->info = new AuthorizerInfoResponse(
            '可信小程序',
            null,
            'gh_worker',
            '示例主体',
            'worker_alias',
            2,
            0,
            [],
            null,
            ['network' => ['RequestDomain' => ['https://example.test']]],
        );
    }
    public function createPreAuthCode(string $componentAppId, string $componentAccessToken): PreAuthCodeResponse { throw new RuntimeException('not used'); }
    public function queryAuthorization(string $componentAppId, string $componentAccessToken, string $authorizationCode): AuthorizerAuthorizationResponse { throw new RuntimeException('not used'); }
    public function refreshAuthorizerToken(string $componentAppId, string $componentAccessToken, string $authorizerAppId, string $authorizerRefreshToken): AuthorizerRefreshResponse { throw new RuntimeException('not used'); }
    public function getAuthorizerInfo(string $componentAppId, string $componentAccessToken, string $authorizerAppId): AuthorizerInfoResponse
    {
        $this->metadataCalls++;
        if ($this->throwMetadata) {
            throw new AppException(ErrorCode::BAD_GATEWAY, 'sanitized metadata timeout', 502);
        }
        return $this->info;
    }
};

$metadataRepo = new class implements AuthorizerMetadataRepository {
    /** @var array<string,AuthorizerMetadataRecord> */ public array $rows = [];
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerMetadataRecord { return $this->rows[$componentPlatformId . ':' . $authorizerAppId] ?? null; }
    public function observe(AuthorizerMetadata $metadata, DateTimeImmutable $fetchedAt, string $source): AuthorizerMetadataRecord
    {
        $key = $metadata->componentPlatformId() . ':' . $metadata->authorizerAppId();
        $current = $this->rows[$key] ?? null;
        $version = $current === null || $current->metadataHash() !== $metadata->metadataHash() ? ($current?->version() ?? 0) + 1 : $current->version();
        return $this->rows[$key] = AuthorizerMetadataRecord::fromMetadata($metadata, $fetchedAt, $version);
    }
};
$metadataSync = new AuthorizerMetadataSyncService($componentTokens, $provider, new AuthorizerMetadataNormalizer(), $metadataRepo);

$newHarness = static function (string $id, DateTimeImmutable $now) use ($metadataSync): array {
    $provisioning = AuthorizerProvisioning::pending($id, 'intent-' . $id, 'tenant-1', 'platform-1', 'wx-authorizer-1', $now);
    $provisionings = new class($provisioning) implements AuthorizerProvisioningRepository {
        /** @var array<string,AuthorizerProvisioning> */ public array $rows;
        public function __construct(AuthorizerProvisioning $row) { $this->rows = [$row->id() => $row]; }
        public function insert(AuthorizerProvisioning $provisioning): void { $this->rows[$provisioning->id()] = $provisioning; }
        public function find(string $id): ?AuthorizerProvisioning { return $this->rows[$id] ?? null; }
        public function findForTenant(string $id, string $tenantId): ?AuthorizerProvisioning { $row = $this->rows[$id] ?? null; return $row !== null && $row->tenantId() === $tenantId ? $row : null; }
        public function findBySourceIntent(string $sourceIntentId): ?AuthorizerProvisioning { foreach ($this->rows as $row) { if ($row->sourceIntentId() === $sourceIntentId) { return $row; } } return null; }
        public function save(AuthorizerProvisioning $next, int $expectedVersion): bool
        {
            $current = $this->rows[$next->id()] ?? null;
            if ($current === null || $current->version() !== $expectedVersion || $next->version() !== $expectedVersion + 1) { return false; }
            $this->rows[$next->id()] = $next;
            return true;
        }
    };

    $jobs = new class(ProvisioningJob::ready($id, $now, $now)) implements ProvisioningJobRepository {
        public ProvisioningJob $job;
        /** @var list<DateTimeImmutable> */ public array $releaseTimes = [];
        public int $claimCalls = 0;
        public function __construct(ProvisioningJob $job) { $this->job = $job; }
        public function insert(ProvisioningJob $job): void { $this->job = $job; }
        public function tryClaim(string $provisioningId, string $holderId, DateTimeImmutable $now, int $ttlSeconds): ?ProvisioningJob
        {
            $this->claimCalls++;
            if ($this->job->provisioningId() !== $provisioningId || !$this->job->claimableAt($now)) { return null; }
            return $this->job = $this->job->claimedBy($holderId, $now, $ttlSeconds);
        }
        public function release(string $provisioningId, string $holderId, DateTimeImmutable $nextAttemptAt, ?string $errorCode): bool
        {
            try { $this->job = $this->job->releasedBy($holderId, $this->job->updatedAt(), $nextAttemptAt, $errorCode); }
            catch (LogicException) { return false; }
            $this->releaseTimes[] = $nextAttemptAt;
            return true;
        }
        public function complete(string $provisioningId, string $holderId): bool
        {
            try { $this->job = $this->job->completedBy($holderId, $this->job->updatedAt()); return true; }
            catch (LogicException) { return false; }
        }
        public function dead(string $provisioningId, string $holderId, string $errorCode): bool
        {
            try { $this->job = $this->job->deadBy($holderId, $errorCode, $this->job->updatedAt()); return true; }
            catch (LogicException) { return false; }
        }
    };

    $authorizations = new class($now) implements AuthorizerAuthorizationRepository {
        public ?AuthorizerAuthorization $authorization;
        public function __construct(DateTimeImmutable $now)
        {
            $this->authorization = AuthorizerAuthorization::active('platform-1', 'wx-authorizer-1', hash('sha256', 'refresh'), ['17'], $now, $now, $now, 1);
        }
        public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAuthorization { return $this->authorization; }
        public function saveFromAuthorization(AuthorizerAuthorization $authorization, string $refreshToken, string $accessToken, DateTimeImmutable $accessTokenExpiresAt): bool { return false; }
        public function markUnauthorized(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $sourceTimestamp, int $expectedVersion): bool { return false; }
        public function compareAndSetRefresh(AuthorizerAuthorization $authorization, AuthorizerAccessToken $token, string $refreshToken, string $holderId, int $expectedAuthorizationVersion, int $expectedTokenVersion, DateTimeImmutable $now): bool { return false; }
    };

    $ownerships = new class implements AuthorizerOwnershipRepository {
        public ?AuthorizerAccountOwnership $ownership = null;
        public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccountOwnership { return $this->ownership; }
    };
    $connections = new class implements AuthorizerConnectionStore {
        /** @var list<AuthorizerAccountOwnership> */ public array $enabled = [];
        public array $disabled = [];
        public function enableExisting(AuthorizerAccountOwnership $ownership, DateTimeImmutable $now): void { $this->enabled[] = $ownership; }
        public function disable(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $now): void { $this->disabled[] = [$componentPlatformId, $authorizerAppId]; }
    };
    $worker = new AuthorizerProvisioningWorker(
        $jobs,
        $provisionings,
        $authorizations,
        $metadataSync,
        new AuthorizerOwnershipResolver($ownerships),
        $ownerships,
        new AuthorizerConnectionService($connections),
    );
    return [$worker, $provisionings, $jobs, $authorizations, $ownerships, $connections];
};

// Inactive authorization is a terminal business outcome before metadata.
[$worker, $provisionings, $jobs, $authorizations] = $newHarness('worker-inactive', $baseNow);
$authorizations->authorization = $authorizations->authorization?->withUnauthorized($baseNow->modify('+1 second'));
$metadataBefore = $provider->metadataCalls;
$worker->runOne('worker-inactive', $baseNow->modify('+2 seconds'));
expectSame(AuthorizerProvisioningStatus::AUTHORIZATION_INACTIVE, $provisionings->find('worker-inactive')?->status(), 'inactive authorization stops provisioning');
expectSame($metadataBefore, $provider->metadataCalls, 'inactive authorization performs zero metadata calls');
expectSame(ProvisioningJobStatus::COMPLETED, $jobs->job->status(), 'inactive authorization completes durable job without retry loop');

// First retryable metadata failure schedules exactly 60 seconds and keeps authorization ACTIVE.
[$worker, $provisionings, $jobs, $authorizations] = $newHarness('worker-timeout', $baseNow);
$provider->throwMetadata = true;
$worker->runOne('worker-timeout', $baseNow);
expectSame(AuthorizerProvisioningStatus::PENDING_METADATA, $provisionings->find('worker-timeout')?->status(), 'retryable metadata timeout keeps business state pending');
expectTrue($authorizations->authorization?->isActive() === true, 'metadata timeout never marks authorization inactive');
expectSame(ProvisioningJobStatus::READY, $jobs->job->status(), 'retryable metadata timeout releases job to READY');
expectSame($baseNow->modify('+60 seconds')->getTimestamp(), $jobs->job->nextAttemptAt()->getTimestamp(), 'first retry delay is exactly 60 seconds');
$provider->throwMetadata = false;

// Trusted metadata freezes type/version; unowned stays ready for Task 10 quota stage.
[$worker, $provisionings, $jobs, $authorizations, $ownerships, $connections] = $newHarness('worker-unowned', $baseNow);
$worker->runOne('worker-unowned', $baseNow);
$unowned = $provisionings->find('worker-unowned');
expectSame(AuthorizerProvisioningStatus::METADATA_READY, $unowned?->status(), 'trusted metadata advances unowned provisioning to METADATA_READY');
expectSame(AccountType::WECHAT_MINI_PROGRAM, $unowned?->accountType(), 'trusted MiniProgramInfo freezes Mini Program Account type');
expectSame(1, $unowned?->metadataVersion(), 'provisioning records trusted metadata projection version');
expectSame(0, count($connections->enabled), 'unowned path does not invent an existing connection');
expectSame(ProvisioningJobStatus::READY, $jobs->job->status(), 'unowned path remains durably eligible for Task 10 quota stage');

// Same owner reconnects existing Account and consumes no quota/Account-creation stage.
[$worker, $provisionings, $jobs, $authorizations, $ownerships, $connections] = $newHarness('worker-same-owner', $baseNow);
$ownerships->ownership = new AuthorizerAccountOwnership('platform-1', 'wx-authorizer-1', 'tenant-1', 'account-existing', AccountType::WECHAT_MINI_PROGRAM, $baseNow, $baseNow);
$worker->runOne('worker-same-owner', $baseNow);
$sameOwner = $provisionings->find('worker-same-owner');
expectSame(AuthorizerProvisioningStatus::RECONNECTED, $sameOwner?->status(), 'same canonical owner reconnects instead of creating Account');
expectSame('account-existing', $sameOwner?->accountId(), 'same-owner reconnect records existing Account');
expectSame(null, $sameOwner?->quotaConsumeEntryId(), 'same-owner reconnect consumes zero Account-creation quota');
expectSame(1, count($connections->enabled), 'same owner reconnect enables existing subtype connection');
expectSame(ProvisioningJobStatus::COMPLETED, $jobs->job->status(), 'same-owner reconnect completes durable job');

// Other owner is a terminal conflict before quota or Account writes.
[$worker, $provisionings, $jobs, $authorizations, $ownerships, $connections] = $newHarness('worker-other-owner', $baseNow);
$ownerships->ownership = new AuthorizerAccountOwnership('platform-1', 'wx-authorizer-1', 'tenant-other', 'account-other', AccountType::WECHAT_MINI_PROGRAM, $baseNow, $baseNow);
$worker->runOne('worker-other-owner', $baseNow);
$otherOwner = $provisionings->find('worker-other-owner');
expectSame(AuthorizerProvisioningStatus::BINDING_CONFLICT, $otherOwner?->status(), 'other canonical owner becomes terminal binding conflict');
expectSame(null, $otherOwner?->quotaConsumeEntryId(), 'binding conflict consumes zero quota');
expectSame(null, $otherOwner?->accountId(), 'binding conflict creates no Account');
expectSame(0, count($connections->enabled), 'binding conflict never reconnects another owner');
expectSame(ProvisioningJobStatus::COMPLETED, $jobs->job->status(), 'binding conflict completes durable job without retry loop');

// Retry delays: 1m,2m,4m,8m,16m, then 30m cap; tenth failed acquisition becomes DEAD + METADATA_FAILED.
[$worker, $provisionings, $jobs, $authorizations] = $newHarness('worker-backoff', $baseNow);
$provider->throwMetadata = true;
$attemptAt = $baseNow;
$expectedDelays = [60, 120, 240, 480, 960, 1800, 1800, 1800, 1800];
foreach ($expectedDelays as $index => $delay) {
    $worker->runOne('worker-backoff', $attemptAt);
    expectSame($attemptAt->modify('+' . $delay . ' seconds')->getTimestamp(), $jobs->job->nextAttemptAt()->getTimestamp(), 'retry delay matches attempt ' . ($index + 1));
    $attemptAt = $jobs->job->nextAttemptAt();
}
$worker->runOne('worker-backoff', $attemptAt);
expectSame(10, $jobs->job->attemptCount(), 'worker performs at most ten automatic failed acquisitions');
expectSame(ProvisioningJobStatus::DEAD, $jobs->job->status(), 'tenth retryable metadata failure marks job DEAD');
expectSame(AuthorizerProvisioningStatus::METADATA_FAILED, $provisionings->find('worker-backoff')?->status(), 'exhausted metadata retries record METADATA_FAILED business outcome');
$provider->throwMetadata = false;
