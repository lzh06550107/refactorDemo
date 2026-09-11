<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\ComponentAccessToken;
use DateTimeImmutable;

interface ComponentTokenRepository
{
    public function current(string $componentPlatformId): ?ComponentAccessToken;
    public function compareAndSet(ComponentAccessToken $token, string $holderId, int $expectedVersion, DateTimeImmutable $now): bool;
}
