<?php

declare(strict_types=1);

namespace app\site\domain;

use InvalidArgumentException;
use LogicException;

final readonly class Site
{
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $accountId,
        private string $name,
        private SiteStatus $status,
        private bool $defaultSite,
        private ?string $activeThemeReleaseId = null,
        private ?int $legacyMultiId = null,
    ) {
        foreach (['id' => $id, 'tenantId' => $tenantId, 'accountId' => $accountId, 'name' => $name] as $key => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($key . ' must not be empty.');
            }
        }
        if ($legacyMultiId !== null && $legacyMultiId <= 0) {
            throw new InvalidArgumentException('legacyMultiId must be positive when present.');
        }
        if ($defaultSite && $status !== SiteStatus::ENABLED) {
            throw new InvalidArgumentException('Default site must be enabled.');
        }
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function accountId(): string { return $this->accountId; }
    public function name(): string { return $this->name; }
    public function status(): SiteStatus { return $this->status; }
    public function isDefault(): bool { return $this->defaultSite; }
    public function activeThemeReleaseId(): ?string { return $this->activeThemeReleaseId; }
    public function legacyMultiId(): ?int { return $this->legacyMultiId; }
    public function isEnabled(): bool { return $this->status === SiteStatus::ENABLED; }

    public function disable(): self
    {
        if ($this->defaultSite) {
            throw new LogicException('Default site cannot be disabled.');
        }
        return new self($this->id, $this->tenantId, $this->accountId, $this->name, SiteStatus::DISABLED, false, $this->activeThemeReleaseId, $this->legacyMultiId);
    }

    public function withActiveThemeRelease(string $releaseId): self
    {
        if (trim($releaseId) === '') {
            throw new InvalidArgumentException('releaseId must not be empty.');
        }
        return new self($this->id, $this->tenantId, $this->accountId, $this->name, $this->status, $this->defaultSite, $releaseId, $this->legacyMultiId);
    }
}
