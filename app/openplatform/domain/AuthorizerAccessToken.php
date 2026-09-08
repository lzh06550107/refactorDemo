<?php

declare(strict_types=1);

namespace app\openplatform\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthorizerAccessToken
{
    public function __construct(
        private string $componentPlatformId,
        private string $authorizerAppId,
        private string $accessToken,
        private DateTimeImmutable $issuedAt,
        private DateTimeImmutable $expiresAt,
        private int $version,
    ) {
        if (trim($componentPlatformId) === '' || trim($authorizerAppId) === '' || trim($accessToken) === '' || $version < 1 || $expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('Invalid authorizer access token.');
        }
    }

    public function componentPlatformId(): string { return $this->componentPlatformId; }
    public function authorizerAppId(): string { return $this->authorizerAppId; }
    public function accessToken(): string { return $this->accessToken; }
    public function issuedAt(): DateTimeImmutable { return $this->issuedAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function version(): int { return $this->version; }
    public function usableAt(DateTimeImmutable $now): bool { return $this->expiresAt > $now; }

    public function outsideRefreshSkew(DateTimeImmutable $now, int $skewSeconds): bool
    {
        if ($skewSeconds < 0) {
            throw new InvalidArgumentException('Refresh skew must not be negative.');
        }
        return $this->expiresAt->getTimestamp() - $now->getTimestamp() > $skewSeconds;
    }
}
