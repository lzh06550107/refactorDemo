<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\AuthorizerAccountOwnership;
use DateTimeImmutable;

interface AuthorizerConnectionStore
{
    public function enableExisting(AuthorizerAccountOwnership $ownership, DateTimeImmutable $now): void;

    public function disable(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $now): void;
}
