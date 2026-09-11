<?php

declare(strict_types=1);

namespace modules\quota\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class QuotaLedgerEntry
{
    private function __construct(
        private string $id,
        private string $tenantId,
        private QuotaResource $resource,
        private QuotaLedgerEntryType $type,
        private int $amount,
        private ?string $grantId,
        private ?QuotaGrantSource $grantSource,
        private int $purchaseCharge,
        private int $parentPoolCharge,
        private ?string $referenceEntryId,
        private string $idempotencyKey,
        private DateTimeImmutable $occurredAt,
    ) {
        if (trim($id) === '' || trim($tenantId) === '' || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Ledger id, tenantId and idempotencyKey must not be empty.');
        }
        if ($amount <= 0 || $purchaseCharge < 0 || $parentPoolCharge < 0) {
            throw new InvalidArgumentException('Ledger amounts are invalid.');
        }
        if (in_array($type, [QuotaLedgerEntryType::CONSUME, QuotaLedgerEntryType::RELEASE], true)
            && $purchaseCharge + $parentPoolCharge > $amount) {
            throw new InvalidArgumentException('Ledger charge allocation must not exceed entry amount.');
        }
    }

    public static function grant(string $id, string $tenantId, QuotaResource $resource, int $amount, string $grantId, QuotaGrantSource $source, string $idempotencyKey, DateTimeImmutable $at): self
    {
        return new self($id, $tenantId, $resource, QuotaLedgerEntryType::GRANT, $amount, $grantId, $source, 0, 0, null, $idempotencyKey, $at);
    }

    public static function consume(string $id, string $tenantId, QuotaResource $resource, int $amount, int $purchaseCharge, int $parentPoolCharge, string $idempotencyKey, DateTimeImmutable $at): self
    {
        return new self($id, $tenantId, $resource, QuotaLedgerEntryType::CONSUME, $amount, null, null, $purchaseCharge, $parentPoolCharge, null, $idempotencyKey, $at);
    }

    public static function release(string $id, string $tenantId, QuotaResource $resource, int $amount, int $purchaseCharge, int $parentPoolCharge, string $consumeEntryId, string $idempotencyKey, DateTimeImmutable $at): self
    {
        return new self($id, $tenantId, $resource, QuotaLedgerEntryType::RELEASE, $amount, null, null, $purchaseCharge, $parentPoolCharge, $consumeEntryId, $idempotencyKey, $at);
    }

    public static function expire(string $id, string $tenantId, QuotaResource $resource, int $amount, string $grantId, QuotaGrantSource $source, string $idempotencyKey, DateTimeImmutable $at): self
    {
        return new self($id, $tenantId, $resource, QuotaLedgerEntryType::EXPIRE, $amount, $grantId, $source, 0, 0, null, $idempotencyKey, $at);
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function resource(): QuotaResource { return $this->resource; }
    public function type(): QuotaLedgerEntryType { return $this->type; }
    public function amount(): int { return $this->amount; }
    public function grantId(): ?string { return $this->grantId; }
    public function grantSource(): ?QuotaGrantSource { return $this->grantSource; }
    public function purchaseCharge(): int { return $this->purchaseCharge; }
    public function parentPoolCharge(): int { return $this->parentPoolCharge; }
    public function referenceEntryId(): ?string { return $this->referenceEntryId; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function occurredAt(): DateTimeImmutable { return $this->occurredAt; }
}
