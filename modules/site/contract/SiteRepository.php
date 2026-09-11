<?php

declare(strict_types=1);

namespace modules\site\contract;

use modules\site\domain\Site;

interface SiteRepository
{
    public function findById(string $tenantId, string $siteId): ?Site;
}
