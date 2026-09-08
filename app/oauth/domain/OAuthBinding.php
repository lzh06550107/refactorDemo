<?php

declare(strict_types=1);

namespace app\oauth\domain;

use InvalidArgumentException;

final readonly class OAuthBinding
{
    public function __construct(
        private string $id,
        private string $tenantId,
        private string $businessAccountId,
        private string $providerType,
        private string $oauthProviderAccountId,
        private bool $enabled,
    ) {
        foreach ([$id, $tenantId, $businessAccountId, $providerType, $oauthProviderAccountId] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('OAuth binding identifiers must not be empty.');
            }
        }
    }

    public function id(): string { return $this->id; }
    public function tenantId(): string { return $this->tenantId; }
    public function businessAccountId(): string { return $this->businessAccountId; }
    public function providerType(): string { return $this->providerType; }
    public function oauthProviderAccountId(): string { return $this->oauthProviderAccountId; }
    public function enabled(): bool { return $this->enabled; }
}
