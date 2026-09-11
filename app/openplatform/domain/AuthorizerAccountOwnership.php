<?php

declare(strict_types=1);

namespace app\openplatform\domain;

use modules\account\domain\AccountType;
use DateTimeImmutable;

final readonly class AuthorizerAccountOwnership
{
    public function __construct(
        private string $componentPlatformId,
        private string $authorizerAppId,
        private string $tenantId,
        private string $accountId,
        private AccountType $accountType,
        private DateTimeImmutable $firstBoundAt,
        private DateTimeImmutable $lastConnectedAt,
    ) {
        foreach ([$componentPlatformId, $authorizerAppId, $tenantId, $accountId] as $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException('Authorizer ownership identifiers must not be empty.');
            }
        }
    }

    public function componentPlatformId(): string
    {
        return $this->componentPlatformId;
    }

    public function authorizerAppId(): string
    {
        return $this->authorizerAppId;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    public function accountType(): AccountType
    {
        return $this->accountType;
    }

    public function firstBoundAt(): DateTimeImmutable
    {
        return $this->firstBoundAt;
    }

    public function lastConnectedAt(): DateTimeImmutable
    {
        return $this->lastConnectedAt;
    }

    public function ownedBy(string $tenantId, string $accountId): bool
    {
        return hash_equals($this->tenantId, $tenantId)
            && hash_equals($this->accountId, $accountId);
    }
}
