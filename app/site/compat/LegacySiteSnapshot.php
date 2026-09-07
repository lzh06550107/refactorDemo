<?php

declare(strict_types=1);

namespace app\site\compat;

use app\site\domain\DomainName;
use app\site\domain\SiteStatus;

final readonly class LegacySiteSnapshot
{
    public function __construct(
        private string $tenantId,
        private string $accountId,
        private int $legacyUniacid,
        private int $legacyMultiId,
        private int $legacyStyleId,
        private string $title,
        private SiteStatus $status,
        private bool $defaultSite,
        private ?DomainName $bindHost,
    ) {}
    public function tenantId(): string { return $this->tenantId; }
    public function accountId(): string { return $this->accountId; }
    public function legacyUniacid(): int { return $this->legacyUniacid; }
    public function legacyMultiId(): int { return $this->legacyMultiId; }
    public function legacyStyleId(): int { return $this->legacyStyleId; }
    public function title(): string { return $this->title; }
    public function status(): SiteStatus { return $this->status; }
    public function isDefault(): bool { return $this->defaultSite; }
    public function bindHost(): ?DomainName { return $this->bindHost; }
}
