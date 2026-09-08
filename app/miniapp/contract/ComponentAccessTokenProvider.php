<?php

declare(strict_types=1);

namespace app\miniapp\contract;

use app\miniapp\domain\ComponentAccessToken;

interface ComponentAccessTokenProvider
{
    public function forPlatform(string $componentPlatformId): ComponentAccessToken;
}
