<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ComponentAccessToken
{
    public function __construct(
        private string $componentPlatformId,
        private string $componentAppId,
        private string $accessToken,
        private DateTimeImmutable $issuedAt,
        private DateTimeImmutable $expiresAt,
        private int $version,
    ) {
        if (trim($componentPlatformId) === '' || trim($componentAppId) === '' || trim($accessToken) === '' || $version < 0 || $expiresAt <= $issuedAt) {
            throw new InvalidArgumentException('Invalid ComponentAccessToken.');
        }
    }

    public function componentPlatformId(): string { return $this->componentPlatformId; }
    public function componentAppId(): string { return $this->componentAppId; }
    public function accessToken(): string { return $this->accessToken; }
    public function issuedAt(): DateTimeImmutable { return $this->issuedAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function version(): int { return $this->version; }
    public function usableAt(DateTimeImmutable $now): bool { return $this->expiresAt > $now; }
    public function outsideRefreshSkew(DateTimeImmutable $now, int $skewSeconds): bool
    {
        return $this->expiresAt->getTimestamp() - $now->getTimestamp() > $skewSeconds;
    }
}
