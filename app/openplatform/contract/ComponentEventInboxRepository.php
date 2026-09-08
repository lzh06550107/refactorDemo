<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use DateTimeImmutable;

interface ComponentEventInboxRepository
{
    public function accept(
        string $componentPlatformId,
        string $replayKey,
        string $payloadHash,
        string $infoType,
        DateTimeImmutable $sourceTimestamp,
        DateTimeImmutable $receivedAt,
    ): bool;
}
