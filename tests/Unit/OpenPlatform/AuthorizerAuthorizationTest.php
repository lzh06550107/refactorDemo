<?php

declare(strict_types=1);

use app\openplatform\domain\AuthorizerAuthorization;

$now = new DateTimeImmutable('2026-09-08T09:00:00Z', new DateTimeZone('UTC'));
$refreshHash = hash('sha256', 'refresh-token');

$authorization = AuthorizerAuthorization::active(
    'platform-1',
    'wx-authorizer-1',
    $refreshHash,
    ['17', '18'],
    $now,
    $now,
    $now,
    1,
);

expectSame('platform-1', $authorization->componentPlatformId(), 'authorizer authorization is platform scoped');
expectSame('wx-authorizer-1', $authorization->authorizerAppId(), 'authorizer AppId is explicit');
expectTrue($authorization->isActive(), 'new authorization is active');
expectSame($refreshHash, $authorization->refreshTokenHash(), 'only refresh token hash is exposed by domain metadata');
expectSame(['17', '18'], $authorization->scopeSet(), 'safe provider scopes are normalized');
expectSame(1, $authorization->version(), 'initial authorization version is one');
expectTrue($authorization->acceptsSourceTimestamp($now->modify('+1 second')), 'newer provider event is accepted');
expectTrue(!$authorization->acceptsSourceTimestamp($now->modify('-1 second')), 'older provider event is stale');

$unauthorized = $authorization->withUnauthorized($now->modify('+10 seconds'));
expectTrue(!$unauthorized->isActive(), 'unauthorized grant is inactive');
expectSame(null, $unauthorized->refreshTokenHash(), 'unauthorized grant exposes no usable refresh credential hash');
expectSame($now->modify('+10 seconds')->getTimestamp(), $unauthorized->unauthorizedAt()?->getTimestamp(), 'unauthorized time is recorded');
expectSame(2, $unauthorized->version(), 'authorization lifecycle mutation increments version');

expectThrows(
    static fn () => AuthorizerAuthorization::active(
        'platform-1', 'wx-authorizer-1', '', ['17'], $now, $now, $now, 1
    ),
    InvalidArgumentException::class,
    'active authorization requires a refresh token hash',
);

expectThrows(
    static fn () => AuthorizerAuthorization::active(
        '', 'wx-authorizer-1', $refreshHash, ['17'], $now, $now, $now, 1
    ),
    InvalidArgumentException::class,
    'authorizer authorization requires platform id',
);
