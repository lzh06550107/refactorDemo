<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\AuthorizerAccountOwnership;

interface AuthorizerOwnershipRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccountOwnership;
}
