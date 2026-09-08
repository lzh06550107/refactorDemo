<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\AuthorizerAccessToken;

interface AuthorizerTokenRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAccessToken;
}
