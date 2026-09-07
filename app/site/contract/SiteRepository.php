<?php

declare(strict_types=1);

namespace app\site\contract;

use app\site\domain\Site;

interface SiteRepository
{
    public function findById(string $tenantId, string $siteId): ?Site;
}
