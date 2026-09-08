<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use app\openplatform\contract\OpenPlatformHttpTransport;
use app\openplatform\domain\ComponentPlatform;
use app\openplatform\infrastructure\OpenSslOpenPlatformSecretCipher;
use app\openplatform\infrastructure\WechatComponentTokenClient;

$transport = new class implements OpenPlatformHttpTransport {
    public array $requests = [];
    public array $responses = [];
    public function postJson(string $url, array $payload, int $timeoutSeconds): array
    {
        $this->requests[] = compact('url', 'payload', 'timeoutSeconds');
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) { throw $response; }
        return is_array($response) ? $response : [];
    }
};
$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$client = new WechatComponentTokenClient($transport);
$transport->responses[] = ['component_access_token' => 'token-1', 'expires_in' => 7200];
$response = $client->refresh($platform, 'app-secret-value', 'ticket-value');
expectSame('token-1', $response->accessToken(), 'provider token response returns component token');
expectSame(7200, $response->expiresIn(), 'provider token response validates positive expiry');
expectSame('https://api.weixin.qq.com/cgi-bin/component/api_component_token', $transport->requests[0]['url'], 'component token endpoint is exact');
expectSame([
    'component_appid' => 'wx-component-1',
    'component_appsecret' => 'app-secret-value',
    'component_verify_ticket' => 'ticket-value',
], $transport->requests[0]['payload'], 'component token request body is exact');
expectSame(10, $transport->requests[0]['timeoutSeconds'], 'component token provider timeout is bounded to 10 seconds by default');

foreach ([
    ['errcode' => 40001, 'errmsg' => 'secret leak provider body'],
    ['component_access_token' => '', 'expires_in' => 7200],
    ['component_access_token' => 'token', 'expires_in' => 0],
    ['component_access_token' => 'token', 'expires_in' => '7200'],
] as $bad) {
    $transport->responses[] = $bad;
    try {
        $client->refresh($platform, 'app-secret-value', 'ticket-value');
        throw new RuntimeException('bad component token response must fail');
    } catch (AppException $e) {
        expectSame(ErrorCode::BAD_GATEWAY, $e->errorCode(), 'provider failure maps to BAD_GATEWAY');
        expectSame(502, $e->httpStatus(), 'provider failure maps to 502');
        expectTrue(!str_contains($e->getMessage(), 'app-secret-value') && !str_contains($e->getMessage(), 'ticket-value') && !str_contains($e->getMessage(), 'secret leak provider body'), 'provider error message is sanitized');
    }
}

$transport->responses[] = new RuntimeException('transport body containing app-secret-value ticket-value');
try {
    $client->refresh($platform, 'app-secret-value', 'ticket-value');
    throw new RuntimeException('transport failure must map to BAD_GATEWAY');
} catch (AppException $e) {
    expectSame(ErrorCode::BAD_GATEWAY, $e->errorCode(), 'transport failure maps to BAD_GATEWAY');
    expectSame(502, $e->httpStatus(), 'transport failure maps to HTTP 502');
    expectTrue(!str_contains($e->getMessage(), 'app-secret-value') && !str_contains($e->getMessage(), 'ticket-value'), 'transport exception is sanitized');
}

$cipher = new OpenSslOpenPlatformSecretCipher(['k1' => random_bytes(32), 'k0' => random_bytes(32)], 'k1');
$first = $cipher->protect('sensitive-component-token');
$second = $cipher->protect('sensitive-component-token');
expectSame('k1', $first['keyVersion'], 'R8B cipher records active key version');
expectTrue($first['ciphertext'] !== $second['ciphertext'], 'R8B AES-GCM protection uses randomized IV');
expectSame('sensitive-component-token', $cipher->reveal($first['ciphertext'], $first['keyVersion']), 'R8B protected value round-trips');
$raw = base64_decode($first['ciphertext'], true);
expectTrue(is_string($raw) && strlen($raw) > 29, 'protected value is base64(iv+tag+ciphertext)');
$raw[29] = chr(ord($raw[29]) ^ 1);
try {
    $cipher->reveal(base64_encode($raw), 'k1');
    throw new RuntimeException('tampered R8B protected value must fail authentication');
} catch (AppException $e) {
    expectSame(ErrorCode::INTERNAL_ERROR, $e->errorCode(), 'cipher authentication failure is internal configuration/storage error');
    expectSame(500, $e->httpStatus(), 'cipher authentication failure maps to 500');
}
try {
    $cipher->reveal($first['ciphertext'], 'missing-key-version');
    throw new RuntimeException('unknown R8B key version must fail closed');
} catch (AppException $e) {
    expectSame(ErrorCode::INTERNAL_ERROR, $e->errorCode(), 'unknown cipher key version fails closed');
    expectSame(500, $e->httpStatus(), 'unknown cipher key version maps to 500');
}
