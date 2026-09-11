<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

interface AuthorizerAccountBinding
{
    public function bindExistingAccount(
        string $tenantId,
        string $accountId,
        string $componentPlatformId,
        string $authorizerAppId,
    ): void;
}
