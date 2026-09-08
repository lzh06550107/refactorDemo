<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\AuthorizerAccessToken;
use app\openplatform\domain\AuthorizerAuthorization;
use DateTimeImmutable;

interface AuthorizerAuthorizationRepository
{
    public function current(string $componentPlatformId, string $authorizerAppId): ?AuthorizerAuthorization;

    public function saveFromAuthorization(
        AuthorizerAuthorization $authorization,
        string $refreshToken,
        string $accessToken,
        DateTimeImmutable $accessTokenExpiresAt,
    ): bool;

    public function markUnauthorized(
        string $componentPlatformId,
        string $authorizerAppId,
        DateTimeImmutable $sourceTimestamp,
        int $expectedVersion,
    ): bool;

    public function compareAndSetRefresh(
        AuthorizerAuthorization $authorization,
        AuthorizerAccessToken $token,
        string $refreshToken,
        string $holderId,
        int $expectedAuthorizationVersion,
        int $expectedTokenVersion,
        DateTimeImmutable $now,
    ): bool;
}
