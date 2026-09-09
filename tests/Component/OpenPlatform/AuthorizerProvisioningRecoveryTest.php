<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\application\AuthorizerProvisioningQuotaService;
use app\openplatform\contract\AuthorizerProvisioningRepository;
use app\openplatform\domain\AuthorizerProvisioning;
use app\openplatform\domain\AuthorizerProvisioningStatus;
use app\quota\application\QuotaService;
use app\quota\contract\QuotaLedgerRepository;
use app\quota\domain\QuotaAvailability;
use app\quota\domain\QuotaGrant;
use app\quota\domain\QuotaLedgerEntry;
use app\quota\domain\QuotaResource;

$now = new DateTimeImmutable('2026-09-09T09:00:00Z');
$ready = AuthorizerProvisioning::pending(
    'recovery-provisioning-1',
    'intent-recovery-1',
    'tenant-1',
    'platform-1',
    'wx-authorizer-1',
    $now,
)->withMetadata(AccountType::WECHAT_MINI_PROGRAM, 1, $now->modify('+1 second'));

$provisionings = new class($ready) implements AuthorizerProvisioningRepository {
    /** @var array<string,AuthorizerProvisioning> */ public array $rows;
    public int $failSaves = 1;
    public function __construct(AuthorizerProvisioning $row) { $this->rows = [$row->id() => $row]; }
    public function insert(AuthorizerProvisioning $provisioning): void { $this->rows[$provisioning->id()] = $provisioning; }
    public function find(string $id): ?AuthorizerProvisioning { return $this->rows[$id] ?? null; }
    public function findForTenant(string $id, string $tenantId): ?AuthorizerProvisioning { $row = $this->rows[$id] ?? null; return $row !== null && $row->tenantId() === $tenantId ? $row : null; }
    public function findBySourceIntent(string $sourceIntentId): ?AuthorizerProvisioning { foreach ($this->rows as $row) { if ($row->sourceIntentId() === $sourceIntentId) { return $row; } } return null; }
    public function save(AuthorizerProvisioning $next, int $expectedVersion): bool
    {
        if ($this->failSaves > 0) { $this->failSaves--; return false; }
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
    public function availability(string $tenantId, QuotaResource $resource, DateTimeImmutable $at): QuotaAvailability { return QuotaAvailability::unlimited(); }
    public function findByIdempotencyKey(string $tenantId, QuotaResource $resource, string $idempotencyKey): ?QuotaLedgerEntry
    {
        return $this->entries[$tenantId . '|' . $resource->key() . '|' . $idempotencyKey] ?? null;
    }
    public function grant(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry { throw new RuntimeException('not used'); }
    public function expire(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry { throw new RuntimeException('not used'); }
    public function consume(string $tenantId, QuotaResource $resource, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        $this->consumeCalls++;
        $entry = QuotaLedgerEntry::consume('recover-consume-1', $tenantId, $resource, $amount, $amount, 0, $idempotencyKey, $at);
        $this->entries[$tenantId . '|' . $resource->key() . '|' . $idempotencyKey] = $entry;
        return $entry;
    }
    public function release(string $tenantId, QuotaResource $resource, string $consumeEntryId, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        $this->releaseCalls++;
        $entry = QuotaLedgerEntry::release('recover-release-1', $tenantId, $resource, $amount, $amount, 0, $consumeEntryId, $idempotencyKey, $at);
        $this->entries[$tenantId . '|' . $resource->key() . '|' . $idempotencyKey] = $entry;
        return $entry;
    }
};

$service = new AuthorizerProvisioningQuotaService(new QuotaService($ledger), $provisionings);
try {
    $service->ensureConsumed($ready, $now->modify('+2 seconds'));
    throw new RuntimeException('simulated post-consume CAS loss must surface as retryable conflict');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'lost consume-reference CAS maps to CONFLICT for retry');
}
expectSame(1, $ledger->consumeCalls, 'quota ledger consume committed before simulated provisioning CAS loss');
expectSame(AuthorizerProvisioningStatus::METADATA_READY, $provisionings->find($ready->id())?->status(), 'failed CAS leaves durable provisioning without fabricated consume reference');

$recovered = $service->ensureConsumed($ready, $now->modify('+3 seconds'));
expectSame(AuthorizerProvisioningStatus::QUOTA_CONSUMED, $recovered->status(), 'retry repairs missing consume reference');
expectSame('recover-consume-1', $recovered->quotaConsumeEntryId(), 'retry recovers exact original ledger entry');
expectSame(1, $ledger->consumeCalls, 'recovery reuses idempotent consume and never double-charges');

$again = $service->ensureConsumed($recovered, $now->modify('+4 seconds'));
expectSame('recover-consume-1', $again->quotaConsumeEntryId(), 'persisted consume reference short-circuits subsequent retries');
expectSame(1, $ledger->consumeCalls, 'consume reference saved before Account creation prevents any second consume');

$terminal = $recovered->provisionFailed('terminal_finalization_failure', $now->modify('+5 seconds'));
$provisionings->rows[$terminal->id()] = $terminal;
$released = $service->ensureReleased($terminal, $now->modify('+6 seconds'));
expectSame('recover-release-1', $released->quotaReleaseEntryId(), 'proven terminal no-Account path records compensation release');
$releasedAgain = $service->ensureReleased($terminal, $now->modify('+7 seconds'));
expectSame('recover-release-1', $releasedAgain->quotaReleaseEntryId(), 'repeated terminal recovery observes same release entry');
expectSame(1, $ledger->releaseCalls, 'terminal compensation is idempotent and occurs once');

$workerPath = dirname(__DIR__, 3) . '/app/openplatform/application/AuthorizerProvisioningWorker.php';
$workerSource = (string) file_get_contents($workerPath);
expectTrue(str_contains($workerSource, 'AuthorizerAccountFinalizer'), 'worker depends on Account finalizer only at Task 10 stage');
expectTrue(str_contains($workerSource, 'AuthorizerProvisioningQuotaService'), 'worker delegates quota saga to dedicated adapter');
$reconcilePos = strpos($workerSource, '->reconcile(');
$releasePos = strpos($workerSource, '->ensureReleased(');
expectTrue($reconcilePos !== false && $releasePos !== false && $reconcilePos < $releasePos, 'worker reconciles committed Account/ownership before any quota release decision');
expectTrue(str_contains($workerSource, 'PROVISION_FAILED'), 'worker distinguishes finalization retry exhaustion from metadata failure');
