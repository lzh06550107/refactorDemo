<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\ComponentPlatform;

interface ComponentPlatformRepository
{
    public function findById(string $componentPlatformId): ?ComponentPlatform;
}
