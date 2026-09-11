<?php

declare(strict_types=1);

namespace modules\account\domain;

use InvalidArgumentException;

final readonly class LegacyAccountMapping
{
    public function __construct(
        private string $accountId,
        private string $tenantId,
        private int $uniacid,
        private int $acid,
        private int $legacyType,
    ) {
        if (trim($accountId) === '' || trim($tenantId) === '') {
            throw new InvalidArgumentException('New account and tenant identifiers must not be empty.');
        }
        if ($uniacid <= 0 || $acid <= 0) {
            throw new InvalidArgumentException('Legacy uniacid and acid must be positive.');
        }
        LegacyAccountTypeMap::fromLegacyType($legacyType);
    }

    public function accountId(): string { return $this->accountId; }
    public function tenantId(): string { return $this->tenantId; }
    public function uniacid(): int { return $this->uniacid; }
    public function acid(): int { return $this->acid; }
    public function legacyType(): int { return $this->legacyType; }
    public function accountType(): AccountType { return LegacyAccountTypeMap::fromLegacyType($this->legacyType); }
}
