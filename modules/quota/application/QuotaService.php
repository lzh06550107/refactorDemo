<?php

declare(strict_types=1);

namespace modules\quota\application;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\quota\contract\QuotaLedgerRepository;
use modules\quota\domain\QuotaAvailability;
use modules\quota\domain\QuotaGrant;
use modules\quota\domain\QuotaLedgerEntry;
use modules\quota\domain\QuotaLedgerEntryType;
use modules\quota\domain\QuotaResource;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class QuotaService
{
    public function __construct(private QuotaLedgerRepository $repository) {}

    public function availability(string $tenantId, QuotaResource $resource, DateTimeImmutable $at): QuotaAvailability
    {
        $this->assertTenant($tenantId);
        return $this->repository->availability($tenantId, $resource, $at);
    }

    public function grant(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('idempotency key must not be empty.');
        }
        $existing = $this->repository->findByIdempotencyKey($grant->tenantId(), $grant->resource(), $idempotencyKey);
        if ($existing !== null) {
            $this->assertExisting($existing, QuotaLedgerEntryType::GRANT, $grant->amount(), $grant->id());
            return $existing;
        }
        return $this->repository->grant($grant, $idempotencyKey, $at);
    }

    public function expire(QuotaGrant $grant, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('idempotency key must not be empty.');
        }
        $existing = $this->repository->findByIdempotencyKey($grant->tenantId(), $grant->resource(), $idempotencyKey);
        if ($existing !== null) {
            $this->assertExisting($existing, QuotaLedgerEntryType::EXPIRE, $grant->amount(), $grant->id());
            return $existing;
        }
        return $this->repository->expire($grant, $idempotencyKey, $at);
    }

    public function consume(string $tenantId, QuotaResource $resource, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        $this->assertWriteInput($tenantId, $amount, $idempotencyKey);
        $existing = $this->repository->findByIdempotencyKey($tenantId, $resource, $idempotencyKey);
        if ($existing !== null) {
            $this->assertExisting($existing, QuotaLedgerEntryType::CONSUME, $amount, null);
            return $existing;
        }
        return $this->repository->consume($tenantId, $resource, $amount, $idempotencyKey, $at);
    }

    public function release(string $tenantId, QuotaResource $resource, string $consumeEntryId, int $amount, string $idempotencyKey, DateTimeImmutable $at): QuotaLedgerEntry
    {
        $this->assertWriteInput($tenantId, $amount, $idempotencyKey);
        if (trim($consumeEntryId) === '') {
            throw new InvalidArgumentException('consumeEntryId must not be empty.');
        }
        $existing = $this->repository->findByIdempotencyKey($tenantId, $resource, $idempotencyKey);
        if ($existing !== null) {
            $this->assertExisting($existing, QuotaLedgerEntryType::RELEASE, $amount, $consumeEntryId);
            return $existing;
        }
        return $this->repository->release($tenantId, $resource, $consumeEntryId, $amount, $idempotencyKey, $at);
    }

    private function assertExisting(QuotaLedgerEntry $entry, QuotaLedgerEntryType $expectedType, int $amount, ?string $referenceId): void
    {
        $matchesReference = match ($expectedType) {
            QuotaLedgerEntryType::GRANT, QuotaLedgerEntryType::EXPIRE => $entry->grantId() === $referenceId,
            QuotaLedgerEntryType::RELEASE => $entry->referenceEntryId() === $referenceId,
            QuotaLedgerEntryType::CONSUME => true,
        };
        if ($entry->type() !== $expectedType || $entry->amount() !== $amount || !$matchesReference) {
            throw new AppException(ErrorCode::CONFLICT, 'Idempotency key was already used for a different quota operation.', 409);
        }
    }

    private function assertWriteInput(string $tenantId, int $amount, string $idempotencyKey): void
    {
        $this->assertTenant($tenantId);
        if ($amount <= 0 || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Quota amount must be positive and idempotency key must not be empty.');
        }
    }

    private function assertTenant(string $tenantId): void
    {
        if (trim($tenantId) === '') {
            throw new InvalidArgumentException('tenantId must not be empty.');
        }
    }
}
