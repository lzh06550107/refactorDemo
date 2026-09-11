<?php

declare(strict_types=1);

namespace modules\oauth\contract;

use modules\oauth\domain\OAuthBinding;

interface OAuthBindingRepository
{
    public function findEnabled(string $tenantId, string $businessAccountId, string $providerType): ?OAuthBinding;
}
