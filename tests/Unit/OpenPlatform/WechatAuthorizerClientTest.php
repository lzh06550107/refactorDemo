<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\contract\OpenPlatformHttpTransport;
use modules\openplatform\infrastructure\WechatAuthorizerClient;

$transport = new class implements OpenPlatformHttpTransport {
    /** @var array<string,mixed> */
    public array $response = [];
    public array $calls = [];
    public bool $throw = false;

    public function postJson(string $url, array $payload, int $timeoutSeconds): array
    {
        $this->calls[] = ['url' => $url, 'payload' => $payload, 'timeout' => $timeoutSeconds];
        if ($this->throw) {
            throw new RuntimeException('transport failure containing no provider payload');
        }
        return $this->response;
    }
};
$client = new WechatAuthorizerClient($transport, 7);

$transport->response = ['pre_auth_code' => 'pre-auth-1', 'expires_in' => 600];
$pre = $client->createPreAuthCode('wx-component-1', 'component-token-secret');
expectSame('pre-auth-1', $pre->preAuthCode(), 'pre-auth client extracts provider pre_auth_code');
expectSame(600, $pre->expiresIn(), 'pre-auth client preserves positive integer expires_in');
$preCall = $transport->calls[array_key_last($transport->calls)];
expectTrue(str_contains($preCall['url'], '/cgi-bin/component/api_create_preauthcode?component_access_token=component-token-secret'), 'pre-auth endpoint receives component token in query');
expectSame(['component_appid' => 'wx-component-1'], $preCall['payload'], 'pre-auth body includes explicit component_appid');
expectSame(7, $preCall['timeout'], 'authorizer provider timeout is bounded');

$transport->response = ['pre_auth_code' => 'pre-auth-2', 'expires_in' => '600'];
try {
    $client->createPreAuthCode('wx-component-1', 'component-token-secret');
    throw new RuntimeException('numeric-string pre-auth expires_in must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::BAD_GATEWAY, $e->errorCode(), 'malformed pre-auth response maps to BAD_GATEWAY');
    expectSame(502, $e->httpStatus(), 'malformed pre-auth response maps to 502');
    expectTrue(!str_contains($e->getMessage(), 'component-token-secret') && !str_contains($e->getMessage(), 'pre-auth-2'), 'provider error message redacts component/pre-auth credentials');
}

$transport->response = [
    'authorization_info' => [
        'authorizer_appid' => 'wx-authorizer-1',
        'authorizer_access_token' => 'authorizer-access-secret',
        'authorizer_refresh_token' => 'authorizer-refresh-secret',
        'expires_in' => 7200,
        'func_info' => [
            ['funcscope_category' => ['id' => 18]],
            ['funcscope_category' => ['id' => 17]],
            ['funcscope_category' => ['id' => 18]],
        ],
    ],
];
$query = $client->queryAuthorization('wx-component-1', 'component-token-secret', 'authorization-code-secret');
expectSame('wx-authorizer-1', $query->authorizerAppId(), 'query-auth extracts authorizer AppId');
expectSame(['17', '18'], $query->scopeSet(), 'query-auth normalizes provider scopes');
$queryCall = $transport->calls[array_key_last($transport->calls)];
expectTrue(str_contains($queryCall['url'], '/cgi-bin/component/api_query_auth?component_access_token=component-token-secret'), 'query-auth endpoint receives component token in query');
expectSame('wx-component-1', $queryCall['payload']['component_appid'] ?? null, 'query-auth body includes component_appid');
expectSame('authorization-code-secret', $queryCall['payload']['authorization_code'] ?? null, 'query-auth body carries ephemeral authorization code');

$transport->response = [
    'authorizer_access_token' => 'next-access-secret',
    'authorizer_refresh_token' => 'rotated-refresh-secret',
    'expires_in' => 7200,
];
$refresh = $client->refreshAuthorizerToken('wx-component-1', 'component-token-secret', 'wx-authorizer-1', 'old-refresh-secret');
expectSame('next-access-secret', $refresh->accessToken(), 'authorizer refresh extracts new access token');
expectSame('rotated-refresh-secret', $refresh->refreshToken(), 'authorizer refresh preserves rotated refresh token');
$refreshCall = $transport->calls[array_key_last($transport->calls)];
expectSame('wx-component-1', $refreshCall['payload']['component_appid'] ?? null, 'refresh body includes component_appid');
expectSame('wx-authorizer-1', $refreshCall['payload']['authorizer_appid'] ?? null, 'refresh body includes authorizer_appid');
expectSame('old-refresh-secret', $refreshCall['payload']['authorizer_refresh_token'] ?? null, 'refresh body includes current refresh credential');

$transport->response = [
    'authorizer_info' => [
        'nick_name' => '金亚包装小程序',
        'head_img' => 'https://example.test/head.png',
        'service_type_info' => ['id' => 0],
        'verify_type_info' => ['id' => 0],
        'user_name' => 'gh_fixture',
        'principal_name' => '南昌金亚包装有限公司',
        'alias' => 'jinya-mini',
        'business_info' => ['open_pay' => 1],
        'qrcode_url' => 'https://example.test/qrcode.png',
        'MiniProgramInfo' => ['visit_status' => 0],
        'authorizer_access_token' => 'must-not-enter-metadata-dto',
    ],
];
$info = $client->getAuthorizerInfo('wx-component-1', 'component-token-secret', 'wx-authorizer-1');
expectSame('金亚包装小程序', $info->nickName(), 'authorizer-info extracts whitelisted nickname');
expectSame('gh_fixture', $info->originalId(), 'authorizer-info maps provider user_name to original id');
expectTrue($info->miniProgramInfo() !== null, 'authorizer-info preserves optional MiniProgramInfo');
$infoCall = $transport->calls[array_key_last($transport->calls)];
expectSame(
    'https://api.weixin.qq.com/cgi-bin/component/api_get_authorizer_info?component_access_token=component-token-secret',
    $infoCall['url'],
    'authorizer-info uses exact WeChat provider endpoint',
);
expectSame(
    ['component_appid' => 'wx-component-1', 'authorizer_appid' => 'wx-authorizer-1'],
    $infoCall['payload'],
    'authorizer-info body contains trusted component_appid and authorizer_appid only',
);
expectTrue(!str_contains(serialize($info), 'must-not-enter-metadata-dto'), 'unwhitelisted provider credential fields are discarded before domain state');

$transport->response = ['errcode' => 61004, 'errmsg' => 'provider-message-must-not-leak'];
try {
    $client->getAuthorizerInfo('wx-component-1', 'component-token-secret', 'wx-authorizer-1');
    throw new RuntimeException('provider errcode must map to BAD_GATEWAY');
} catch (AppException $e) {
    expectSame(ErrorCode::BAD_GATEWAY, $e->errorCode(), 'authorizer-info provider errcode maps to BAD_GATEWAY');
    expectSame(502, $e->httpStatus(), 'authorizer-info provider errcode maps to HTTP 502');
    expectTrue(!str_contains($e->getMessage(), 'provider-message-must-not-leak'), 'provider errmsg is sanitized');
}

$transport->throw = true;
try {
    $client->refreshAuthorizerToken('wx-component-1', 'component-token-secret', 'wx-authorizer-1', 'old-refresh-secret');
    throw new RuntimeException('transport failure must map to BAD_GATEWAY');
} catch (AppException $e) {
    expectSame(ErrorCode::BAD_GATEWAY, $e->errorCode(), 'transport failure maps to BAD_GATEWAY');
    expectTrue(
        !str_contains($e->getMessage(), 'component-token-secret')
        && !str_contains($e->getMessage(), 'old-refresh-secret'),
        'transport exception is sanitized',
    );
}
