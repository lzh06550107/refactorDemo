<?php

declare(strict_types=1);

namespace app\miniapp\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\miniapp\contract\ComponentAccessTokenProvider;
use app\miniapp\contract\MiniAppCodeExchangeClient;
use app\miniapp\contract\MiniAppCredentialProvider;
use app\miniapp\contract\MiniAppHttpTransport;
use app\miniapp\domain\MiniAppCodeSession;
use app\miniapp\domain\MiniAppConnectionMode;
use app\miniapp\domain\MiniAppProviderAccount;
use Throwable;

final readonly class WechatMiniAppCodeExchangeClient implements MiniAppCodeExchangeClient
{
    private const MANUAL_ENDPOINT = 'https://api.weixin.qq.com/sns/jscode2session';
    private const COMPONENT_ENDPOINT = 'https://api.weixin.qq.com/sns/component/jscode2session';

    public function __construct(
        private MiniAppHttpTransport $transport,
        private MiniAppCredentialProvider $credentials,
        private ComponentAccessTokenProvider $componentTokens,
    ) {
    }

    public function exchange(MiniAppProviderAccount $provider, string $code): MiniAppCodeSession
    {
        $code = trim($code);
        if ($code === '') {
            throw new AppException(ErrorCode::INVALID_ARGUMENT, 'MiniApp login code must not be empty.', 400);
        }

        [$endpoint, $query] = match ($provider->mode()) {
            MiniAppConnectionMode::MANUAL => $this->manualRequest($provider, $code),
            MiniAppConnectionMode::COMPONENT => $this->componentRequest($provider, $code),
        };

        try {
            $response = $this->transport->get($endpoint, $query);
        } catch (AppException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new AppException(
                ErrorCode::INTERNAL_ERROR,
                'MiniApp provider request failed.',
                502,
                null,
                $e,
            );
        }

        $errcode = (int) ($response['errcode'] ?? 0);
        $openid = trim((string) ($response['openid'] ?? ''));
        $sessionKey = trim((string) ($response['session_key'] ?? ''));
        if ($errcode !== 0 || $openid === '' || $sessionKey === '') {
            throw new AppException(ErrorCode::UNAUTHORIZED, 'MiniApp code exchange was rejected.', 401);
        }

        $unionId = isset($response['unionid']) && trim((string) $response['unionid']) !== ''
            ? trim((string) $response['unionid'])
            : null;

        return new MiniAppCodeSession(
            $provider->providerAppId(),
            $openid,
            $unionId,
            $sessionKey,
        );
    }

    private function manualRequest(MiniAppProviderAccount $provider, string $code): array
    {
        $credentialRef = $provider->credentialRef();
        if ($credentialRef === null) {
            throw new AppException(ErrorCode::FORBIDDEN, 'MiniApp manual provider credential is unavailable.', 403);
        }

        $secret = trim($this->credentials->secretFor($credentialRef));
        if ($secret === '') {
            throw new AppException(ErrorCode::FORBIDDEN, 'MiniApp manual provider credential is unavailable.', 403);
        }

        return [self::MANUAL_ENDPOINT, [
            'appid' => $provider->providerAppId(),
            'secret' => $secret,
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ]];
    }

    private function componentRequest(MiniAppProviderAccount $provider, string $code): array
    {
        $platformId = $provider->componentPlatformId();
        if ($platformId === null) {
            throw new AppException(ErrorCode::FORBIDDEN, 'MiniApp component platform is unavailable.', 403);
        }

        $component = $this->componentTokens->forPlatform($platformId);

        return [self::COMPONENT_ENDPOINT, [
            'appid' => $provider->providerAppId(),
            'js_code' => $code,
            'grant_type' => 'authorization_code',
            'component_appid' => $component->componentAppId(),
            'component_access_token' => $component->accessToken(),
        ]];
    }
}
