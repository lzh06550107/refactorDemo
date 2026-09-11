<?php

declare(strict_types=1);

namespace modules\site\domain;

use InvalidArgumentException;

final readonly class DomainBinding
{
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $accountId,
        private string $siteId,
        private DomainName $host,
        private DomainBindingSource $source,
        private ?int $legacyMultiId = null,
        private ?string $defaultModule = null,
    ) {
        foreach (['id' => $id, 'tenantId' => $tenantId, 'accountId' => $accountId, 'siteId' => $siteId] as $key => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($key . ' must not be empty.');
            }
        }
        if ($legacyMultiId !== null && $legacyMultiId <= 0) {
            throw new InvalidArgumentException('legacyMultiId must be positive when present.');
        }
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function accountId(): string { return $this->accountId; }
    public function siteId(): string { return $this->siteId; }
    public function host(): DomainName { return $this->host; }
    public function source(): DomainBindingSource { return $this->source; }
    public function legacyMultiId(): ?int { return $this->legacyMultiId; }
    public function defaultModule(): ?string { return $this->defaultModule; }
}
