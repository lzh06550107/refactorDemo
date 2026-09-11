<?php

declare(strict_types=1);

namespace modules\module\domain;

use InvalidArgumentException;

final readonly class AccountModuleConfig
{
    public function __construct(
        private string $accountId,
        private string $tenantId,
        private string $moduleName,
        private bool $enabled,
        private int $displayOrder = 0,
        private bool $shortcut = false,
        private bool $moduleShortcut = false,
        private array $settings = [],
    ) {
        if (trim($accountId) === '' || trim($tenantId) === '' || trim($moduleName) === '') {
            throw new InvalidArgumentException('Account, tenant and module identifiers must not be empty.');
        }
    }

    public function accountId(): string { return $this->accountId; }
    public function tenantId(): string { return $this->tenantId; }
    public function moduleName(): string { return $this->moduleName; }
    public function enabled(): bool { return $this->enabled; }
    public function displayOrder(): int { return $this->displayOrder; }
    public function shortcut(): bool { return $this->shortcut; }
    public function moduleShortcut(): bool { return $this->moduleShortcut; }
    public function settings(): array { return $this->settings; }
}
