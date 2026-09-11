<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\AuthorizationIntent;
use DateTimeImmutable;

interface AuthorizationIntentRepository
{
    public function insert(AuthorizationIntent $intent): void;

    public function findByStateHash(string $stateHash): ?AuthorizationIntent;

    public function findByPreAuthCodeHash(string $componentPlatformId, string $preAuthCodeHash): ?AuthorizationIntent;

    public function tryClaim(
        string $intentId,
        string $holderId,
        DateTimeImmutable $now,
        int $leaseSeconds,
        int $expectedVersion,
    ): ?AuthorizationIntent;

    public function releaseClaim(string $intentId, string $holderId): void;

    public function complete(
        string $intentId,
        string $holderId,
        string $authorizerAppId,
        DateTimeImmutable $now,
        int $expectedVersion,
    ): bool;
}
