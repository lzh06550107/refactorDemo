<?php

declare(strict_types=1);

namespace modules\oauth\contract;

use modules\member\domain\ProviderIdentity;
use modules\oauth\domain\OAuthBinding;
use modules\oauth\domain\OAuthState;

interface OAuthProviderClient
{
    public function authorizationUrl(OAuthBinding $binding, string $stateToken): string;
    public function exchangeCode(OAuthState $state, string $code): ProviderIdentity;
}
