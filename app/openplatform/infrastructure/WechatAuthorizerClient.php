<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\AuthorizerClient;
use app\openplatform\contract\OpenPlatformHttpTransport;
use app\openplatform\domain\AuthorizerAuthorizationResponse;
use app\openplatform\domain\AuthorizerInfoResponse;
use app\openplatform\domain\AuthorizerRefreshResponse;
use app\openplatform\domain\PreAuthCodeResponse;
use InvalidArgumentException;
use Throwable;

final readonly class WechatAuthorizerClient implements AuthorizerClient
{
    private const CREATE_PRE_AUTH_ENDPOINT = 'https://api.weixin.qq.com/cgi-bin/component/api_create_preauthcode';
    private const QUERY_AUTH_ENDPOINT = 'https://api.weixin.qq.com/cgi-bin/component/api_query_auth';
    private const REFRESH_ENDPOINT = 'https://api.weixin.qq.com/cgi-bin/component/api_authorizer_token';
    private const AUTHORIZER_INFO_ENDPOINT = 'https://api.weixin.qq.com/cgi-bin/component/api_get_authorizer_info';

    public function __construct(
        private OpenPlatformHttpTransport $transport,
        private int $timeoutSeconds = 10,
    ) {
    }

    public function createPreAuthCode(string $componentAppId, string $componentAccessToken): PreAuthCodeResponse
    {
        $response = $this->post(
            self::CREATE_PRE_AUTH_ENDPOINT,
            $componentAccessToken,
            ['component_appid' => $componentAppId],
        );

        $code = $response['pre_auth_code'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;
        if (!is_string($code) || trim($code) === '' || !is_int($expiresIn) || $expiresIn <= 0) {
            $this->badGateway();
        }

        return new PreAuthCodeResponse($code, $expiresIn);
    }

    public function queryAuthorization(
        string $componentAppId,
        string $componentAccessToken,
        string $authorizationCode,
    ): AuthorizerAuthorizationResponse {
        $response = $this->post(
            self::QUERY_AUTH_ENDPOINT,
            $componentAccessToken,
            [
                'component_appid' => $componentAppId,
                'authorization_code' => $authorizationCode,
            ],
        );

        $info = $response['authorization_info'] ?? null;
        if (!is_array($info)) {
            $this->badGateway();
        }

        $authorizerAppId = $info['authorizer_appid'] ?? null;
        $accessToken = $info['authorizer_access_token'] ?? null;
        $refreshToken = $info['authorizer_refresh_token'] ?? null;
        $expiresIn = $info['expires_in'] ?? null;
        if (
            !is_string($authorizerAppId) || trim($authorizerAppId) === ''
            || !is_string($accessToken) || trim($accessToken) === ''
            || !is_string($refreshToken) || trim($refreshToken) === ''
            || !is_int($expiresIn) || $expiresIn <= 0
        ) {
            $this->badGateway();
        }

        $scopeSet = [];
        $funcInfo = $info['func_info'] ?? [];
        if (is_array($funcInfo)) {
            foreach ($funcInfo as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $category = $entry['funcscope_category'] ?? null;
                if (!is_array($category) || !array_key_exists('id', $category)) {
                    continue;
                }
                $scope = trim((string) $category['id']);
                if ($scope !== '') {
                    $scopeSet[] = $scope;
                }
            }
        }

        return new AuthorizerAuthorizationResponse(
            $authorizerAppId,
            $accessToken,
            $refreshToken,
            $expiresIn,
            $scopeSet,
        );
    }

    public function refreshAuthorizerToken(
        string $componentAppId,
        string $componentAccessToken,
        string $authorizerAppId,
        string $authorizerRefreshToken,
    ): AuthorizerRefreshResponse {
        $response = $this->post(
            self::REFRESH_ENDPOINT,
            $componentAccessToken,
            [
                'component_appid' => $componentAppId,
                'authorizer_appid' => $authorizerAppId,
                'authorizer_refresh_token' => $authorizerRefreshToken,
            ],
        );

        $accessToken = $response['authorizer_access_token'] ?? null;
        $refreshToken = $response['authorizer_refresh_token'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;
        if (
            !is_string($accessToken) || trim($accessToken) === ''
            || !is_int($expiresIn) || $expiresIn <= 0
            || ($refreshToken !== null && (!is_string($refreshToken) || trim($refreshToken) === ''))
        ) {
            $this->badGateway();
        }

        return new AuthorizerRefreshResponse($accessToken, $refreshToken, $expiresIn);
    }

    public function getAuthorizerInfo(
        string $componentAppId,
        string $componentAccessToken,
        string $authorizerAppId,
    ): AuthorizerInfoResponse {
        $response = $this->post(
            self::AUTHORIZER_INFO_ENDPOINT,
            $componentAccessToken,
            [
                'component_appid' => $componentAppId,
                'authorizer_appid' => $authorizerAppId,
            ],
        );

        $info = $response['authorizer_info'] ?? null;
        if (!is_array($info)) {
            $this->badGateway();
        }

        try {
            return AuthorizerInfoResponse::fromAuthorizerInfo($info);
        } catch (InvalidArgumentException) {
            $this->badGateway();
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function post(string $endpoint, string $componentAccessToken, array $payload): array
    {
        try {
            $response = $this->transport->postJson(
                $endpoint . '?component_access_token=' . rawurlencode($componentAccessToken),
                $payload,
                $this->timeoutSeconds,
            );
        } catch (Throwable) {
            $this->badGateway();
        }

        if (array_key_exists('errcode', $response) && (int) $response['errcode'] !== 0) {
            $this->badGateway();
        }

        return $response;
    }

    private function badGateway(): never
    {
        throw new AppException(
            ErrorCode::BAD_GATEWAY,
            'OpenPlatform authorizer provider unavailable.',
            502,
        );
    }
}
