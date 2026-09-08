<?php

declare(strict_types=1);

namespace app\openplatform\contract;

interface AuthorizerAuthorizationCredentialRepository extends AuthorizerAuthorizationRepository
{
    public function currentRefreshToken(string $componentPlatformId, string $authorizerAppId): ?string;
}
