<?php

declare(strict_types=1);

namespace app\quota\contract;

use app\quota\domain\QuotaAvailability;
use app\quota\domain\QuotaGrant;
use app\quota\domain\QuotaLedgerEntry;
use app\quota\domain\QuotaResource;
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
