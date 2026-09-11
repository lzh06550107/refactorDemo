<?php

declare(strict_types=1);

namespace modules\quota\domain;

use InvalidArgumentException;

final readonly class ParentQuotaPool
{
    public function __construct(
        private string $id,
        private string $ownerTenantId,
        private QuotaResource $resource,
        private int $limit,
        private int $consumed,
    ) {
        if (trim($id) === '' || trim($ownerTenantId) === '') {
            throw new InvalidArgumentException('Parent pool id and owner tenant must not be empty.');
        }
        if ($limit < 0 || $consumed < 0 || $consumed > $limit) {
            throw new InvalidArgumentException('Parent pool counters are invalid.');
        }
    }

    public function id(): string { return $this->id; }
    public function ownerTenantId(): string { return $this->ownerTenantId; }
    public function resource(): QuotaResource { return $this->resource; }
    public function limit(): int { return $this->limit; }
    public function consumed(): int { return $this->consumed; }
    public function remaining(): int { return $this->limit - $this->consumed; }
    public function canConsume(int $amount): bool { return $amount > 0 && $amount <= $this->remaining(); }
}
