<?php

declare(strict_types=1);

namespace app\openplatform\contract;

interface AuthorizerTenantScopeReader
{
    public function allowsTenant(
        string $tenantId,
        string $componentPlatformId,
        string $authorizerAppId,
    ): bool;
}
