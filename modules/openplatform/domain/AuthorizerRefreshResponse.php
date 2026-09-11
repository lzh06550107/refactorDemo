<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

use InvalidArgumentException;

final readonly class AuthorizerRefreshResponse
{
    public function __construct(
        private string $accessToken,
        private ?string $refreshToken,
        private int $expiresIn,
    ) {
        if (trim($accessToken) === '' || $expiresIn <= 0) {
            throw new InvalidArgumentException('Invalid OpenPlatform authorizer refresh response.');
        }
        if ($refreshToken !== null && trim($refreshToken) === '') {
            throw new InvalidArgumentException('Rotated authorizer refresh token must not be empty.');
        }
    }

    public function accessToken(): string { return $this->accessToken; }
    public function refreshToken(): ?string { return $this->refreshToken; }
    public function expiresIn(): int { return $this->expiresIn; }
}
