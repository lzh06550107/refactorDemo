<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\AuthorizerAccessToken;

interface AuthorizerTokenRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccessToken;
}
