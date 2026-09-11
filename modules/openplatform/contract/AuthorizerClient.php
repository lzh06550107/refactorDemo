<?php

declare(strict_types=1);

namespace modules\openplatform\contract;

use modules\openplatform\domain\AuthorizerAuthorizationResponse;
use modules\openplatform\domain\AuthorizerInfoResponse;
use modules\openplatform\domain\AuthorizerRefreshResponse;
use modules\openplatform\domain\PreAuthCodeResponse;

interface AuthorizerClient
{
    public function createPreAuthCode(
        string $componentAppId,
        string $componentAccessToken,
    ): PreAuthCodeResponse;

    public function queryAuthorization(
        string $componentAppId,
        string $componentAccessToken,
        string $authorizationCode,
    ): AuthorizerAuthorizationResponse;

    public function refreshAuthorizerToken(
        string $componentAppId,
        string $componentAccessToken,
        string $authorizerAppId,
        string $authorizerRefreshToken,
    ): AuthorizerRefreshResponse;

    public function getAuthorizerInfo(
        string $componentAppId,
        string $componentAccessToken,
        string $authorizerAppId,
    ): AuthorizerInfoResponse;
}
