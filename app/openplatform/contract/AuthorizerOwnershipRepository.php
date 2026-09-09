<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\AuthorizerAccountOwnership;

interface AuthorizerOwnershipRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccountOwnership;
}
