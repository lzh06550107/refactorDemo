<?php

declare(strict_types=1);

use modules\account\domain\AccountType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\application\AuthorizerProvisioningRetryService;
use modules\openplatform\contract\AuthorizerAuthorizationRepository;
use modules\openplatform\contract\AuthorizerProvisioningRepository;
use modules\openplatform\contract\ProvisioningJobScheduler;
use modules\openplatform\domain\AuthorizerAccessToken;
use modules\openplatform\domain\AuthorizerAuthorization;
use modules\openplatform\domain\AuthorizerProvisioning;

$now = new DateTimeImmutable('2026-09-09T06:45:00Z');
$blocked = AuthorizerProvisioning::pending(
    'prov-retry-1', 'intent-retry-1', 'tenant-1', 'platform-1', 'wx-retry-1', $now,
)->withMetadata(AccountType::WECHAT_MINI_PROGRAM, 2, $now->modify('+1 second'))
  ->quotaBlocked('quota_insufficient', $now->modify('+2 seconds'));

$repo = new class($blocked) implements AuthorizerProvisioningRepository {
    public AuthorizerProvisioning $row;
    public array $saveCalls = [];
    public function __construct(AuthorizerProvisioning $row) { $this->row = $row; }
    public function insert(AuthorizerProvisioning $provisioning): void { $this->row = $provisioning; }
    public function find(string $id): ?AuthorizerProvisioning { return $id === $this->row->id() ? $this->row : null; }
    public function findForTenant(string $id, string $tenantId): ?AuthorizerProvisioning
    { return $id === $this->row->id() && $tenantId === $this->row->tenantId() ? $this->row : null; }
    public function findBySourceIntent(string $sourceIntentId): ?AuthorizerProvisioning { return null; }
    public function save(AuthorizerProvisioning $next, int $expectedVersion): bool
    {
        $this->saveCalls[] = [$next->status()->value, $expectedVersion];
        if ($this->row->version() !== $expectedVersion) { return false; }
        $this->row = $next;
        return true;
    }
};

$active = AuthorizerAuthorization::active(
    'platform-1', 'wx-retry-1', hash('sha256', 'refresh'), ['17'], $now, $now, $now, 1,
);
$authRepo = new class($active) implements AuthorizerAuthorizationRepository {
    public ?AuthorizerAuthorization $row;
    public function __construct(?AuthorizerAuthorization $row) { $this->row = $row; }
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAuthorization { return $this->row; }
    public function saveFromAuthorization(AuthorizerAuthorization $authorization, string $refreshToken, string $accessToken, DateTimeImmutable $accessTokenExpiresAt): bool { return false; }
    public function markUnauthorized(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $sourceTimestamp, int $expectedVersion): bool { return false; }
    public function compareAndSetRefresh(AuthorizerAuthorization $authorization, AuthorizerAccessToken $token, string $refreshToken, string $holderId, int $expectedAuthorizationVersion, int $expectedTokenVersion, DateTimeImmutable $now): bool { return false; }
};
$scheduler = new class implements ProvisioningJobScheduler {
    public array $calls = [];
    public bool $accept = true;
    public function requeue(string $provisioningId, DateTimeImmutable $now): bool
    { $this->calls[] = [$provisioningId, $now->format(DATE_ATOM)]; return $this->accept; }
};

$service = new AuthorizerProvisioningRetryService($repo, $authRepo, $scheduler);
$resumed = $service->retry('prov-retry-1', 'tenant-1', $now->modify('+10 seconds'));
expectSame('metadata_ready', $resumed->status()->value, 'quota-blocked retry resumes at quota eligibility stage instead of resetting metadata');
expectSame(AccountType::WECHAT_MINI_PROGRAM, $resumed->accountType(), 'retry preserves frozen trusted Account type');
expectSame(2, $resumed->metadataVersion(), 'retry preserves metadata version');
expectSame([['metadata_ready', $blocked->version()]], $repo->saveCalls, 'retry persists one stage-aware CAS transition');
expectSame(1, count($scheduler->calls), 'accepted retry requeues the durable job exactly once');

$authRepo->row = AuthorizerAuthorization::reconstitute(
    'platform-1', 'wx-retry-1', 'unauthorized', null, ['17'], $now, $now, $now, $now, 2,
);
$repo->row = $blocked;
try {
    $service->retry('prov-retry-1', 'tenant-1', $now->modify('+20 seconds'));
    throw new RuntimeException('inactive authorization must not accept retry');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'retry revalidates current authorization state');
    expectSame(409, $e->httpStatus(), 'inactive authorization retry is a conflict');
}

$compensated = AuthorizerProvisioning::pending(
    'prov-retry-2', 'intent-retry-2', 'tenant-1', 'platform-1', 'wx-retry-1', $now,
)->withMetadata(AccountType::WECHAT_MINI_PROGRAM, 2, $now->modify('+1 second'))
  ->withQuotaConsumed('account_create:wechat_mini_program', 'consume-1', $now->modify('+2 seconds'))
  ->provisionFailed('terminal_db_failure', $now->modify('+3 seconds'))
  ->withQuotaRelease('release-1', $now->modify('+4 seconds'));
$repo->row = $compensated;
$authRepo->row = $active;
try {
    $service->retry('prov-retry-2', 'tenant-1', $now->modify('+30 seconds'));
    throw new RuntimeException('compensated provisioning must not reuse the released consume key');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'compensated terminal failure requires a new provisioning flow');
    expectSame(409, $e->httpStatus(), 'compensated retry is rejected safely');
}
