<?php

declare(strict_types=1);

namespace modules\quota\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class QuotaGrant
{
    public function __construct(
        private string $id,
        private string $tenantId,
        private QuotaResource $resource,
        private QuotaGrantSource $source,
        private int $amount,
        private ?DateTimeImmutable $startsAt = null,
        private ?DateTimeImmutable $endsAt = null,
        private ?string $parentPoolId = null,
    ) {
        if (trim($id) === '' || trim($tenantId) === '') {
            throw new InvalidArgumentException('Grant id and tenantId must not be empty.');
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException('Grant amount must be positive.');
        }
        if ($startsAt !== null && $endsAt !== null && $endsAt < $startsAt) {
            throw new InvalidArgumentException('Grant end time must not precede start time.');
        }
        if ($source === QuotaGrantSource::PURCHASE && $parentPoolId !== null) {
            throw new InvalidArgumentException('Purchase grants must not consume a parent quota pool.');
        }
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function resource(): QuotaResource { return $this->resource; }
    public function source(): QuotaGrantSource { return $this->source; }
    public function amount(): int { return $this->amount; }
    public function parentPoolId(): ?string { return $this->parentPoolId; }
    public function startsAt(): ?DateTimeImmutable { return $this->startsAt; }
    public function endsAt(): ?DateTimeImmutable { return $this->endsAt; }

    public function isActiveAt(DateTimeImmutable $at): bool
    {
        if ($this->startsAt !== null && $at < $this->startsAt) {
            return false;
        }
        return $this->endsAt === null || $at <= $this->endsAt;
    }
}
