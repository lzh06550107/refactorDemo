<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\AuthorizerAccountOwnership;
use DateTimeImmutable;

interface AuthorizerConnectionStore
{
    public function enableExisting(AuthorizerAccountOwnership $ownership, DateTimeImmutable $now): void;

    public function disable(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $now): void;
}
