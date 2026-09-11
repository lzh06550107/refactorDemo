<?php

declare(strict_types=1);

namespace modules\quota\contract;

use modules\quota\domain\QuotaAvailability;
use modules\quota\domain\QuotaGrant;
use modules\quota\domain\QuotaLedgerEntry;
use modules\quota\domain\QuotaResource;
use DateTimeImmutable;

interface QuotaLedgerRepository
{
    public function availability(string $tenantId, QuotaResource $resource, DateTimeImmutable $at): QuotaAvailability;
    public function findByIdempotencyKey(string $tenantId, QuotaResource $resource, string $idempotencyKey): ?QuotaLedgerEntry;
    public function grant(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry;
    public function expire(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry;
    public function consume(string $tenantId, QuotaResource $resource, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry;
    public function release(string $tenantId, QuotaResource $resource, string $consumeEntryId, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry;
}
