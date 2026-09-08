<?php

declare(strict_types=1);

namespace app\openplatform\contract;

interface AuthorizerAccountBinding
{
    public function bindExistingAccount(
        string $tenantId,
        string $accountId,
        string $componentPlatformId,
        string $authorizerAppId,
    ): void;
}
