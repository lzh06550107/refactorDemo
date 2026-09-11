<?php

declare(strict_types=1);

namespace modules\miniapp\contract;

use modules\miniapp\domain\ComponentAccessToken;

interface ComponentAccessTokenProvider
{
    public function forPlatform(string $componentPlatformId): ComponentAccessToken;
}
