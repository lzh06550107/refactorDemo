<?php

declare(strict_types=1);

use app\openplatform\domain\ComponentPlatform;

$platform = new ComponentPlatform(
    'platform-1',
    'wx-component-1',
    'secret/appsecret',
    'secret/verify-token',
    'secret/encoding-aes-key',
    true,
);

expectSame('platform-1', $platform->id(), 'component platform id is explicit');
expectSame('wx-component-1', $platform->componentAppId(), 'component AppId is explicit');
expectSame('secret/appsecret', $platform->appSecretRef(), 'AppSecret is represented only by reference');
expectSame('secret/verify-token', $platform->verifyTokenRef(), 'verify token is represented only by reference');
expectSame('secret/encoding-aes-key', $platform->encodingAesKeyRef(), 'EncodingAESKey is represented only by reference');
expectTrue($platform->enabled(), 'component platform can be enabled');

$serialized = var_export($platform, true);
expectTrue(!str_contains($serialized, 'actual-app-secret'), 'domain object does not carry raw AppSecret fixture');

expectThrows(
    static fn () => new ComponentPlatform('', 'wx-component-1', 'a', 'b', 'c', true),
    InvalidArgumentException::class,
    'empty platform id must be rejected',
);
expectThrows(
    static fn () => new ComponentPlatform('platform-1', '', 'a', 'b', 'c', true),
    InvalidArgumentException::class,
    'empty component AppId must be rejected',
);
expectThrows(
    static fn () => new ComponentPlatform('platform-1', 'wx-component-1', '', 'b', 'c', true),
    InvalidArgumentException::class,
    'empty AppSecret reference must be rejected',
);
