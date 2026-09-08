<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\ComponentPlatform;

interface ComponentPlatformRepository
{
    public function findById(string $componentPlatformId): ?ComponentPlatform;
}
