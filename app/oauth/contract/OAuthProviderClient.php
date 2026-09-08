<?php

declare(strict_types=1);

namespace app\oauth\contract;

use app\member\domain\ProviderIdentity;
use app\oauth\domain\OAuthBinding;
use app\oauth\domain\OAuthState;

interface OAuthProviderClient
{
    public function authorizationUrl(OAuthBinding $binding, string $stateToken): string;
    public function exchangeCode(OAuthState $state, string $code): ProviderIdentity;
}
