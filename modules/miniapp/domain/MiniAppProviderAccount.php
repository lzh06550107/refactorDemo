<?php

declare(strict_types=1);

namespace modules\miniapp\domain;

use InvalidArgumentException;

final readonly class MiniAppProviderAccount
{
    public function __construct(
        private string $tenantId,
        private string $accountId,
        private string $providerAppId,
        private MiniAppConnectionMode $mode,
        private ?string $credentialRef,
        private ?string $componentPlatformId,
    ) {
        foreach (['tenantId' => $tenantId, 'accountId' => $accountId, 'providerAppId' => $providerAppId] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException($name . ' must not be empty.');
            }
        }

        if ($mode === MiniAppConnectionMode::MANUAL) {
            if ($credentialRef === null || trim($credentialRef) === '') {
                throw new InvalidArgumentException('Manual MiniApp provider requires credentialRef.');
            }
            if ($componentPlatformId !== null) {
                throw new InvalidArgumentException('Manual MiniApp provider must not reference a component platform.');
            }
        }

        if ($mode === MiniAppConnectionMode::COMPONENT) {
            if ($componentPlatformId === null || trim($componentPlatformId) === '') {
                throw new InvalidArgumentException('Component MiniApp provider requires componentPlatformId.');
            }
            if ($credentialRef !== null) {
                throw new InvalidArgumentException('Component MiniApp provider must not contain a manual credentialRef.');
            }
        }
    }

    public function tenantId(): string { return $this->tenantId; }
    public function accountId(): string { return $this->accountId; }
    public function providerAppId(): string { return $this->providerAppId; }
    public function mode(): MiniAppConnectionMode { return $this->mode; }
    public function credentialRef(): ?string { return $this->credentialRef; }
    public function componentPlatformId(): ?string { return $this->componentPlatformId; }
}
