<?php

declare(strict_types=1);

namespace app\openplatform\application;

use app\openplatform\contract\AuthorizerConnectionStore;
use app\openplatform\domain\AuthorizerAccountOwnership;
use DateTimeImmutable;

final readonly class AuthorizerConnectionService
{
    public function __construct(private AuthorizerConnectionStore $connections)
    {
    }

    public function reconnect(AuthorizerAccountOwnership $ownership, DateTimeImmutable $now): void
    {
        $this->connections->enableExisting($ownership, $now);
    }

    public function disconnect(string $componentPlatformId, string $authorizerAppId, DateTimeImmutable $now): void
    {
        $this->connections->disable($componentPlatformId, $authorizerAppId, $now);
    }
}
