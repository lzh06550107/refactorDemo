<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\miniapp\contract\ComponentAccessTokenProvider;
use modules\miniapp\contract\MiniAppCredentialProvider;
use modules\miniapp\contract\MiniAppHttpTransport;
use modules\miniapp\domain\ComponentAccessToken;
use modules\miniapp\domain\MiniAppConnectionMode;
use modules\miniapp\domain\MiniAppProviderAccount;
use modules\miniapp\infrastructure\WechatMiniAppCodeExchangeClient;

$transport = new class implements MiniAppHttpTransport {
    public array $requests = [];
    public array $responses = [];

    public function get(string $url, array $query): array
    {
        $this->requests[] = ['url' => $url, 'query' => $query];
        return array_shift($this->responses) ?? [];
    }
};

$credentials = new class implements MiniAppCredentialProvider {
    public array $requested = [];

    public function secretFor(string $credentialRef): string
    {
        $this->requested[] = $credentialRef;
        return 'resolved-secret';
    }
};

$componentTokens = new class implements ComponentAccessTokenProvider {
    public array $requested = [];

    public function forPlatform(string $componentPlatformId): ComponentAccessToken
    {
        $this->requested[] = $componentPlatformId;
        return new ComponentAccessToken('component-appid-1', 'component-token-1');
    }
};

$client = new WechatMiniAppCodeExchangeClient($transport, $credentials, $componentTokens);

$transport->responses[] = ['openid' => 'openid-manual', 'unionid' => 'union-manual', 'session_key' => 'session-manual'];
$manualProvider = new MiniAppProviderAccount('tenant-1', 'account-1', 'wx-manual', MiniAppConnectionMode::MANUAL, 'credential-ref-1', null);
$manual = $client->exchange($manualProvider, 'code-manual');
expectSame('https://api.weixin.qq.com/sns/jscode2session', $transport->requests[0]['url'], 'manual MiniApp uses standard jscode2session endpoint');
expectSame([
    'appid' => 'wx-manual',
    'secret' => 'resolved-secret',
    'js_code' => 'code-manual',
    'grant_type' => 'authorization_code',
], $transport->requests[0]['query'], 'manual MiniApp request has exact provider-scoped parameters');
expectSame(['credential-ref-1'], $credentials->requested, 'manual secret is resolved by credential reference');
expectSame('wx-manual', $manual->providerAppId(), 'manual code session keeps provider appid');
expectSame('openid-manual', $manual->openId(), 'manual code session returns openid');
expectSame('union-manual', $manual->unionId(), 'manual code session preserves optional unionid');
expectSame('session-manual', $manual->sessionKey(), 'manual code session returns session key only to server-side domain');

$transport->responses[] = ['openid' => 'openid-component', 'session_key' => 'session-component'];
$componentProvider = new MiniAppProviderAccount('tenant-1', 'account-2', 'wx-authorized', MiniAppConnectionMode::COMPONENT, null, 'platform-1');
$component = $client->exchange($componentProvider, 'code-component');
expectSame('https://api.weixin.qq.com/sns/component/jscode2session', $transport->requests[1]['url'], 'authorized MiniApp uses component jscode2session endpoint');
expectSame([
    'appid' => 'wx-authorized',
    'js_code' => 'code-component',
    'grant_type' => 'authorization_code',
    'component_appid' => 'component-appid-1',
    'component_access_token' => 'component-token-1',
], $transport->requests[1]['query'], 'authorized MiniApp request uses component token scoped to component platform');
expectSame(['platform-1'], $componentTokens->requested, 'component token is resolved by explicit platform id');
expectSame('openid-component', $component->openId(), 'component code session returns openid');
expectSame(null, $component->unionId(), 'component code session allows absent unionid');

foreach ([
    ['errcode' => 40029, 'errmsg' => 'invalid code'],
    ['session_key' => 'missing-openid'],
    ['openid' => 'missing-session-key'],
] as $badResponse) {
    $transport->responses[] = $badResponse;
    try {
        $client->exchange($manualProvider, 'bad-code');
        throw new RuntimeException('bad MiniApp code exchange response must be rejected');
    } catch (AppException $e) {
        expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'bad MiniApp code exchange maps to UNAUTHORIZED');
        expectSame(401, $e->httpStatus(), 'bad MiniApp code exchange maps to HTTP 401');
    }
}
