<?php

declare(strict_types=1);

namespace app\openplatform\contract;

use app\openplatform\domain\AuthorizerAuthorizationResponse;
use app\openplatform\domain\AuthorizerInfoResponse;
use app\openplatform\domain\AuthorizerRefreshResponse;
use app\openplatform\domain\PreAuthCodeResponse;

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
