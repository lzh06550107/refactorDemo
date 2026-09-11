<?php

declare(strict_types=1);

namespace modules\theme\contract;

use modules\theme\domain\ThemePublication;

interface ThemePublicationRepository
{
    public function findByIdempotencyKey(string $tenantId, string $siteId, string $key): ?ThemePublication;
    public function findRelease(string $tenantId, string $siteId, string $releaseId): ?ThemePublication;
    public function publish(ThemePublication $publication): void;
}
