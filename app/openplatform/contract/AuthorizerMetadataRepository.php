<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\AuthorizerMetadata;
use app\openplatform\domain\AuthorizerMetadataRecord;
use DateTimeImmutable;

interface AuthorizerMetadataRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerMetadataRecord;

    public function observe(
        AuthorizerMetadata $metadata,
        DateTimeImmutable $fetchedAt,
        string $source,
    ): AuthorizerMetadataRecord;
}
