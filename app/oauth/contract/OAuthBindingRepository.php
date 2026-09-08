<?php

declare(strict_types=1);

namespace app\oauth\contract;

use app\oauth\domain\OAuthBinding;

interface OAuthBindingRepository
{
    public function findEnabled(string $tenantId, string $businessAccountId, string $providerType): ?OAuthBinding;
}
