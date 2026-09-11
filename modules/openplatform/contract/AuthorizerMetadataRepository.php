<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\AuthorizerMetadata;
use modules\openplatform\domain\AuthorizerMetadataRecord;
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
