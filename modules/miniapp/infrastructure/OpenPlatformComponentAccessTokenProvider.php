<?php

declare(strict_types=1);

namespace modules\miniapp\infrastructure;

use modules\miniapp\contract\ComponentAccessTokenProvider;
use modules\miniapp\domain\ComponentAccessToken;
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
