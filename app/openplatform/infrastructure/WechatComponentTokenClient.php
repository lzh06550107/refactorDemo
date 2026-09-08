<?php

declare(strict_types=1);

namespace app\openplatform\infrastructure;

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\ComponentTokenClient;
use app\openplatform\contract\OpenPlatformHttpTransport;
use app\openplatform\domain\ComponentPlatform;
use app\openplatform\domain\ComponentTokenResponse;
use Throwable;

final readonly class WechatComponentTokenClient implements ComponentTokenClient
{
    private const ENDPOINT = 'https://api.weixin.qq.com/cgi-bin/component/api_component_token';

    public function __construct(private OpenPlatformHttpTransport $transport, private int $timeoutSeconds = 10)
    {
    }

    public function refresh(ComponentPlatform $platform, string $appSecret, string $verifyTicket): ComponentTokenResponse
    {
        try {
            $response = $this->transport->postJson(self::ENDPOINT, [
                'component_appid' => $platform->componentAppId(),
                'component_appsecret' => $appSecret,
                'component_verify_ticket' => $verifyTicket,
            ], $this->timeoutSeconds);
        } catch (Throwable) {
            $this->badGateway();
        }

        $errcode = isset($response['errcode']) ? (int) $response['errcode'] : 0;
        $token = $response['component_access_token'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;
        if ($errcode !== 0 || !is_string($token) || trim($token) === '' || !is_int($expiresIn) && !ctype_digit((string) $expiresIn) || (int) $expiresIn <= 0) {
            $this->badGateway();
        }

        return new ComponentTokenResponse($token, (int) $expiresIn);
    }

    private function badGateway(): never
    {
        throw new AppException(ErrorCode::BAD_GATEWAY, 'OpenPlatform component token provider unavailable.', 502);
    }
}
