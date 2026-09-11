<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

interface AuthorizerTenantScopeReader
{
    public function allowsTenant(
        string $tenantId,
        string $componentPlatformId,
        string $authorizerAppId,
    ): bool;
}
