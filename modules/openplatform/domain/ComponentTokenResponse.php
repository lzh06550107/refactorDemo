<?php

declare(strict_types=1);

namespace modules\openplatform\domain;

use InvalidArgumentException;

final readonly class ComponentTokenResponse
{
    public function __construct(private string $accessToken, private int $expiresIn)
    {
        if (trim($accessToken) === '' || $expiresIn <= 0) {
            throw new InvalidArgumentException('Invalid component token provider response.');
        }
    }

    public function accessToken(): string { return $this->accessToken; }
    public function expiresIn(): int { return $this->expiresIn; }
}
