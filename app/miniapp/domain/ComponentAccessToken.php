<?php

declare(strict_types=1);

namespace app\miniapp\domain;

use InvalidArgumentException;

final readonly class ComponentAccessToken
{
    public function __construct(
        private string $componentAppId,
        private string $accessToken,
    ) {
        if (trim($componentAppId) === '' || trim($accessToken) === '') {
            throw new InvalidArgumentException('Component access token requires component appid and token.');
        }
    }

    public function componentAppId(): string { return $this->componentAppId; }
    public function accessToken(): string { return $this->accessToken; }
}
