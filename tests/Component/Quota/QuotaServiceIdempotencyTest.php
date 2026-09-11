<?php

declare(strict_types=1);

use modules\account\domain\AccountType;
use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\quota\application\QuotaService;
use modules\quota\contract\QuotaLedgerRepository;
use modules\quota\domain\QuotaAvailability;
use modules\quota\domain\QuotaGrant;
use modules\quota\domain\QuotaGrantSource;
use modules\quota\domain\QuotaLedgerEntry;
use modules\quota\domain\QuotaResource;

$at = new DateTimeImmutable('2026-09-07T12:00:00+00:00');
$resource = QuotaResource::accountCreate(AccountType::OFFICIAL_ACCOUNT);
$existing = QuotaLedgerEntry::consume('entry-1', 'tenant-1', $resource, 1, 1, 0, 'idem-1', $at);

$repository = new class($existing) implements QuotaLedgerRepository {
    public function __construct(private QuotaLedgerEntry $existing) {}
    public function availability(string $tenantId, QuotaResource $resource, DateTimeImmutable $at): QuotaAvailability { return QuotaAvailability::limited(0, 0, 0, null); }
    public function findByIdempotencyKey(string $tenantId, QuotaResource $resource, string $idempotencyKey): ?QuotaLedgerEntry { return $idempotencyKey === 'idem-1' ? $this->existing : null; }
    public function grant(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry { throw new LogicException('unexpected grant'); }
    public function expire(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry { throw new LogicException('unexpected expire'); }
    public function consume(string $tenantId, QuotaResource $resource, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry { throw new LogicException('unexpected consume'); }
    public function release(string $tenantId, QuotaResource $resource, string $consumeEntryId, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry { throw new LogicException('unexpected release'); }
};

$service = new QuotaService($repository);
expectSame($existing, $service->consume('tenant-1', $resource, 1, 'idem-1', $at), 'same idempotency payload must return the original ledger entry');

try {
    $service->consume('tenant-1', $resource, 2, 'idem-1', $at);
    throw new RuntimeException('different idempotency payload must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::CONFLICT, $e->errorCode(), 'different idempotency payload must produce CONFLICT');
    expectSame(409, $e->httpStatus(), 'different idempotency payload must use HTTP 409');
}

expectThrows(
    static fn () => new QuotaGrant('grant-buy', 'tenant-1', $resource, QuotaGrantSource::PURCHASE, 1, null, null, 'parent-1'),
    InvalidArgumentException::class,
    'purchase grants must never consume a parent quota pool',
);
