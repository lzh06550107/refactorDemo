<?php

declare(strict_types=1);

namespace modules\openplatform\application;

use modules\openplatform\contract\AuthorizerOwnershipRepository;
use modules\openplatform\domain\AuthorizerOwnershipResolution;

final readonly class AuthorizerOwnershipResolver
{
    public function __construct(private AuthorizerOwnershipRepository $ownerships)
    {
    }

    public function resolve(
        string $componentPlatformId,
        string $authorizerAppId,
        string $tenantId,
        string $accountId,
    ): AuthorizerOwnershipResolution {
        $ownership = $this->ownerships->current($componentPlatformId, $authorizerAppId);
        if ($ownership === null) {
            return AuthorizerOwnershipResolution::UNOWNED;
        }

        return $ownership->ownedBy($tenantId, $accountId)
            ? AuthorizerOwnershipResolution::SAME_OWNER
            : AuthorizerOwnershipResolution::OTHER_OWNER;
    }
}
