<?php

declare(strict_types=1);

use app\common\error\AppException;
use app\common\error\ErrorCode;
use modules\openplatform\contract\ComponentCredentialProvider;
use modules\openplatform\contract\ComponentPlatformRepository;
use modules\openplatform\domain\ComponentPlatform;
use modules\openplatform\security\WechatComponentCallbackAuthenticator;
use modules\openplatform\security\WechatComponentEnvelopeParser;
use modules\openplatform\security\WechatComponentMessageDecryptor;
use modules\openplatform\security\WechatComponentSignatureVerifier;

$platform = new ComponentPlatform('platform-1', 'wx-component-1', 'secret/app', 'secret/verify', 'secret/aes', true);
$platforms = new class($platform) implements ComponentPlatformRepository {
    public function __construct(private ComponentPlatform $platform) {}
    public function findById(string $componentPlatformId): ?ComponentPlatform
    {
        return $componentPlatformId === $this->platform->id() ? $this->platform : null;
    }
};
$rawKey = random_bytes(32);
$encodingKey = rtrim(base64_encode($rawKey), '=');
$credentials = new class($encodingKey) implements ComponentCredentialProvider {
    public function __construct(private string $encodingKey) {}
    public function secretFor(string $credentialRef): string
    {
        return match ($credentialRef) {
            'secret/verify' => 'verify-token',
            'secret/aes' => $this->encodingKey,
            'secret/app' => 'app-secret',
            default => throw new RuntimeException('unexpected secret ref'),
        };
    }
};

$authenticator = new WechatComponentCallbackAuthenticator(
    $platforms,
    $credentials,
    new WechatComponentSignatureVerifier(),
    new WechatComponentEnvelopeParser(),
    new WechatComponentMessageDecryptor(),
    300,
);

$encrypt = static function (string $xml, string $receiver, string $key): string {
    $frame = random_bytes(16) . pack('N', strlen($xml)) . $xml . $receiver;
    $pad = 32 - (strlen($frame) % 32);
    if ($pad === 0) { $pad = 32; }
    $frame .= str_repeat(chr($pad), $pad);
    $cipher = openssl_encrypt($frame, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($key, 0, 16));
    if (!is_string($cipher)) { throw new RuntimeException('fixture encryption failed'); }
    return base64_encode($cipher);
};
$callback = static function (string $innerXml, int $timestamp, string $nonce, string $receiver = 'wx-component-1') use ($encrypt, $rawKey): array {
    $encrypted = $encrypt($innerXml, $receiver, $rawKey);
    $parts = ['verify-token', (string) $timestamp, $nonce, $encrypted];
    sort($parts, SORT_STRING);
    return [
        'raw' => '<xml><AppId>wx-diagnostic-only</AppId><Encrypt><![CDATA[' . $encrypted . ']]></Encrypt></xml>',
        'encrypted' => $encrypted,
        'signature' => sha1(implode('', $parts)),
    ];
};

$now = new DateTimeImmutable('2026-09-08T09:15:00Z');
$ts = $now->getTimestamp();
$cases = [
    [
        'component_verify_ticket',
        '<xml><AppId>wx-component-1</AppId><InfoType>component_verify_ticket</InfoType><ComponentVerifyTicket>ticket-1</ComponentVerifyTicket></xml>',
        static function ($event): void {
            expectSame('ticket-1', $event->componentVerifyTicket(), 'typed ticket event exposes verify ticket only inside application boundary');
        },
    ],
    [
        'authorized',
        '<xml><AppId>wx-component-1</AppId><InfoType>authorized</InfoType><AuthorizerAppid>wx-authorizer-1</AuthorizerAppid><AuthorizationCode>auth-code-1</AuthorizationCode><AuthorizationCodeExpiredTime>600</AuthorizationCodeExpiredTime><PreAuthCode>pre-auth-1</PreAuthCode></xml>',
        static function ($event): void {
            expectSame('wx-authorizer-1', $event->authorizerAppId(), 'authorized event exposes authorizer AppId');
            expectSame('auth-code-1', $event->authorizationCode(), 'authorization code exists only in typed in-memory event');
            expectSame('pre-auth-1', $event->preAuthCode(), 'pre-auth code exists only in typed in-memory event');
        },
    ],
    [
        'updateauthorized',
        '<xml><AppId>wx-component-1</AppId><InfoType>updateauthorized</InfoType><AuthorizerAppid>wx-authorizer-1</AuthorizerAppid><AuthorizationCode>auth-code-2</AuthorizationCode></xml>',
        static function ($event): void {
            expectSame('wx-authorizer-1', $event->authorizerAppId(), 'updateauthorized event is typed');
        },
    ],
    [
        'unauthorized',
        '<xml><AppId>wx-component-1</AppId><InfoType>unauthorized</InfoType><AuthorizerAppid>wx-authorizer-1</AuthorizerAppid></xml>',
        static function ($event): void {
            expectSame('wx-authorizer-1', $event->authorizerAppId(), 'unauthorized event is typed');
            expectSame(null, $event->authorizationCode(), 'unauthorized event carries no authorization code');
        },
    ],
];

foreach ($cases as [$infoType, $innerXml, $assertEvent]) {
    $nonce = 'nonce-' . $infoType;
    $fixture = $callback($innerXml, $ts, $nonce);
    $event = $authenticator->authenticate('platform-1', $fixture['raw'], (string) $ts, $nonce, $fixture['signature'], $now);
    expectSame('platform-1', $event->componentPlatformId(), 'event remains platform scoped');
    expectSame('wx-component-1', $event->componentAppId(), 'event carries authenticated component AppId');
    expectSame($infoType, $event->infoType(), 'InfoType is typed after authentication');
    expectSame(hash('sha256', 'platform-1' . "\n" . $ts . "\n" . $nonce), $event->replayKey(), 'replay identity is deterministic');
    expectSame(hash('sha256', $fixture['encrypted']), $event->payloadHash(), 'payload hash covers encrypted payload');
    $assertEvent($event);
}

$badSignature = $callback('<xml><AppId>wx-component-1</AppId><InfoType>unauthorized</InfoType><AuthorizerAppid>wx-authorizer-1</AuthorizerAppid></xml>', $ts, 'nonce-bad-signature');
try {
    $authenticator->authenticate('platform-1', $badSignature['raw'], (string) $ts, 'nonce-bad-signature', str_repeat('0', 40), $now);
    throw new RuntimeException('invalid signature must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'invalid msg_signature maps to UNAUTHORIZED');
    expectSame(401, $e->httpStatus(), 'invalid msg_signature maps to 401');
}

$staleTs = $ts - 301;
$stale = $callback('<xml><AppId>wx-component-1</AppId><InfoType>unauthorized</InfoType><AuthorizerAppid>wx-authorizer-1</AuthorizerAppid></xml>', $staleTs, 'nonce-stale');
try {
    $authenticator->authenticate('platform-1', $stale['raw'], (string) $staleTs, 'nonce-stale', $stale['signature'], $now);
    throw new RuntimeException('stale callback must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::UNAUTHORIZED, $e->errorCode(), 'stale timestamp maps to UNAUTHORIZED');
}

$wrongInnerApp = $callback('<xml><AppId>wx-other</AppId><InfoType>unauthorized</InfoType><AuthorizerAppid>wx-authorizer-1</AuthorizerAppid></xml>', $ts, 'nonce-wrong-app');
try {
    $authenticator->authenticate('platform-1', $wrongInnerApp['raw'], (string) $ts, 'nonce-wrong-app', $wrongInnerApp['signature'], $now);
    throw new RuntimeException('inner AppId mismatch must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::FORBIDDEN, $e->errorCode(), 'inner AppId mismatch maps to FORBIDDEN');
    expectSame(403, $e->httpStatus(), 'inner AppId mismatch maps to 403');
}

$unknown = $callback('<xml><AppId>wx-component-1</AppId><InfoType>unknown_event</InfoType></xml>', $ts, 'nonce-unknown');
try {
    $authenticator->authenticate('platform-1', $unknown['raw'], (string) $ts, 'nonce-unknown', $unknown['signature'], $now);
    throw new RuntimeException('unknown InfoType must fail');
} catch (AppException $e) {
    expectSame(ErrorCode::INVALID_ARGUMENT, $e->errorCode(), 'unknown InfoType maps to INVALID_ARGUMENT');
    expectSame(400, $e->httpStatus(), 'unknown InfoType maps to 400');
}
