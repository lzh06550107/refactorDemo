<?php

declare(strict_types=1);

namespace app\miniapp\infrastructure;

use app\miniapp\contract\ComponentAccessTokenProvider;
use app\miniapp\domain\ComponentAccessToken;
use app\openplatform\application\ComponentAccessTokenService;

final readonly class OpenPlatformComponentAccessTokenProvider implements ComponentAccessTokenProvider
{
    public function __construct(private ComponentAccessTokenService $service)
    {
    }

    public function forPlatform(string $componentPlatformId): ComponentAccessToken
    {
        $token = $this->service->forPlatform($componentPlatformId);

        return new ComponentAccessToken(
            $token->componentAppId(),
            $token->accessToken(),
        );
    }
}
