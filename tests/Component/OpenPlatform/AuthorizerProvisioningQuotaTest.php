<?php

declare(strict_types=1);

use modules\account\domain\AccountType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\application\AuthorizerProvisioningQuotaService;
use modules\openplatform\contract\AuthorizerProvisioningRepository;
use modules\openplatform\domain\AuthorizerProvisioning;
use modules\openplatform\domain\AuthorizerProvisioningStatus;
use modules\quota\application\QuotaService;
use modules\quota\contract\QuotaLedgerRepository;
use modules\quota\domain\QuotaAvailability;
use modules\quota\domain\QuotaGrant;
use modules\quota\domain\QuotaLedgerEntry;
use modules\quota\domain\QuotaResource;

$now = new DateTimeImmutable('2026-09-09T08:00:00Z');
$ready = AuthorizerProvisioning::pending(
    'quota-provisioning-1',
    'intent-quota-1',
    'tenant-1',
    'platform-1',
    'wx-authorizer-1',
    $now,
)->withMetadata(AccountType::WECHAT_MINI_PROGRAM, 1, $now->modify('+1 second'));

$provisionings = new class($ready) implements AuthorizerProvisioningRepository {
    /** @var array<string,AuthorizerProvisioning> */ public array $rows;
    public int $saveCalls = 0;
    public function __construct(AuthorizerProvisioning $row) { $this->rows = [$row->id() => $row]; }
    public function insert(AuthorizerProvisioning $provisioning): void { $this->rows[$provisioning->id()] = $provisioning; }
    public function find(string $id): ?AuthorizerProvisioning { return $this->rows[$id] ?? null; }
    public function findForTenant(string $id, string $tenantId): ?AuthorizerProvisioning { $row = $this->rows[$id] ?? null; return $row !== null && $row->tenantId() === $tenantId ? $row : null; }
    public function findBySourceIntent(string $sourceIntentId): ?AuthorizerProvisioning { foreach ($this->rows as $row) { if ($row->sourceIntentId() === $sourceIntentId) { return $row; } } return null; }
    public function save(AuthorizerProvisioning $next, int $expectedVersion): bool
    {
        $this->saveCalls++;
        $current = $this->rows[$next->id()] ?? null;
        if ($current === null || $current->version() !== $expectedVersion || $next->version() !== $expectedVersion + 1) { return false; }
        $this->rows[$next->id()] = $next;
        return true;
    }
};

$ledger = new class implements QuotaLedgerRepository {
    /** @var array<string,QuotaLedgerEntry> */ public array $entries = [];
    public int $consumeCalls = 0;
    public int $releaseCalls = 0;
    public bool $forbidConsume = false;
    public array $lastConsume = [];
    public array $lastRelease = [];

    public function availability(string $tenantId, QuotaResource $resource, DateTimeImmutable $at): QuotaAvailability { return QuotaAvailability::unlimited(); }
    public function findByIdempotencyKey(string $tenantId, QuotaResource $resource, string $idempotencyKey): ?QuotaLedgerEntry
    {
        return $this->entries[$tenantId . '|' . $resource->key() . '|' . $idempotencyKey] ?? null;
    }
    public function grant(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry { throw new RuntimeException('not used'); }
    public function expire(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry { throw new RuntimeException('not used'); }
    public function consume(string $tenantId, QuotaResource $resource, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        if ($this->forbidConsume) { throw new AppException(ErrorCode::FORBIDDEN, 'quota exhausted', 403); }
        $this->consumeCalls++;
        $this->lastConsume = [$tenantId, $resource->key(), $amount, $idempotencyKey];
        $entry = QuotaLedgerEntry::consume('consume-' . $this->consumeCalls, $tenantId, $resource, $amount, $amount, 0, $idempotencyKey, $at);
        $this->entries[$tenantId . '|' . $resource->key() . '|' . $idempotencyKey] = $entry;
        return $entry;
    }
    public function release(string $tenantId, QuotaResource $resource, string $consumeEntryId, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        $this->releaseCalls++;
        $this->lastRelease = [$tenantId, $resource->key(), $consumeEntryId, $amount, $idempotencyKey];
        $entry = QuotaLedgerEntry::release('release-' . $this->releaseCalls, $tenantId, $resource, $amount, $amount, 0, $consumeEntryId, $idempotencyKey, $at);
        $this->entries[$tenantId . '|' . $resource->key() . '|' . $idempotencyKey] = $entry;
        return $entry;
    }
};

$service = new AuthorizerProvisioningQuotaService(new QuotaService($ledger), $provisionings);
$consumed = $service->ensureConsumed($ready, $now->modify('+2 seconds'));
expectSame(AuthorizerProvisioningStatus::QUOTA_CONSUMED, $consumed->status(), 'first unowned provisioning consumes Account-create quota');
expectSame('account_create:wechat_mini_program', $consumed->quotaResourceKey(), 'frozen trusted type selects exact quota resource');
expectSame('consume-1', $consumed->quotaConsumeEntryId(), 'consume ledger id is persisted on provisioning');
expectSame(['tenant-1', 'account_create:wechat_mini_program', 1, 'openplatform-provision:platform-1:wx-authorizer-1:tenant-1'], $ledger->lastConsume, 'consume uses deterministic canonical idempotency key');
expectSame(1, $ledger->consumeCalls, 'first provisioning consumes exactly one unit exactly once');

$again = $service->ensureConsumed($ready, $now->modify('+3 seconds'));
expectSame('consume-1', $again->quotaConsumeEntryId(), 'stale repeated caller recovers persisted semantic consume entry');
expectSame(1, $ledger->consumeCalls, 'repeated ensureConsumed never creates a second ledger consume');

$released = $service->ensureReleased($consumed, $now->modify('+4 seconds'));
expectSame('release-1', $released->quotaReleaseEntryId(), 'terminal compensation persists release ledger id');
expectSame(['tenant-1', 'account_create:wechat_mini_program', 'consume-1', 1, 'openplatform-provision-release:quota-provisioning-1'], $ledger->lastRelease, 'release uses provisioning-scoped idempotency key');
$releasedAgain = $service->ensureReleased($consumed, $now->modify('+5 seconds'));
expectSame('release-1', $releasedAgain->quotaReleaseEntryId(), 'repeated compensation returns same semantic release');
expectSame(1, $ledger->releaseCalls, 'repeated compensation never releases quota twice');

$blockedReady = AuthorizerProvisioning::pending(
    'quota-provisioning-blocked',
    'intent-quota-blocked',
    'tenant-2',
    'platform-1',
    'wx-authorizer-blocked',
    $now,
)->withMetadata(AccountType::OFFICIAL_ACCOUNT, 1, $now->modify('+1 second'));
$provisionings->rows[$blockedReady->id()] = $blockedReady;
$ledger->forbidConsume = true;
try {
    $service->ensureConsumed($blockedReady, $now->modify('+2 seconds'));
    throw new RuntimeException('quota exhaustion must propagate to worker as FORBIDDEN');
} catch (AppException $e) {
    expectSame(ErrorCode::FORBIDDEN, $e->errorCode(), 'quota exhaustion preserves FORBIDDEN classification');
}
expectSame(AuthorizerProvisioningStatus::METADATA_READY, $provisionings->find($blockedReady->id())?->status(), 'failed consume does not fabricate QUOTA_CONSUMED state');
