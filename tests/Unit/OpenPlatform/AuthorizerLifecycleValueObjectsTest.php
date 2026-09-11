<?php

declare(strict_types=1);

use modules\openplatform\contract\AuthorizationIntentRepository;
use modules\openplatform\contract\AuthorizerAuthorizationRepository;
use modules\openplatform\contract\AuthorizerRefreshLeaseRepository;
use modules\openplatform\contract\AuthorizerTokenRepository;
use modules\openplatform\domain\AuthorizerAccessToken;
use modules\openplatform\domain\AuthorizerAuthorizationResponse;
use modules\openplatform\domain\AuthorizerRefreshResponse;
use modules\openplatform\domain\AuthorizerTokenRefreshLease;
use modules\openplatform\domain\PreAuthCodeResponse;

$requiredClasses = [
    AuthorizerAccessToken::class,
    AuthorizerTokenRefreshLease::class,
    PreAuthCodeResponse::class,
    AuthorizerAuthorizationResponse::class,
    AuthorizerRefreshResponse::class,
];
$missingClasses = array_values(array_filter($requiredClasses, static fn (string $class): bool => !class_exists($class)));
expectSame([], $missingClasses, 'Task 1 lifecycle value objects must exist before GREEN');

$requiredInterfaces = [
    AuthorizationIntentRepository::class,
    AuthorizerAuthorizationRepository::class,
    AuthorizerTokenRepository::class,
    AuthorizerRefreshLeaseRepository::class,
];
$missingInterfaces = array_values(array_filter($requiredInterfaces, static fn (string $interface): bool => !interface_exists($interface)));
expectSame([], $missingInterfaces, 'Task 1 repository contracts must exist before GREEN');

$now = new DateTimeImmutable('2026-09-08T09:00:00Z');
$token = new AuthorizerAccessToken(
    'platform-1',
    'wx-authorizer-1',
    'access-token',
    $now,
    $now->modify('+7200 seconds'),
    1,
);
expectTrue($token->usableAt($now), 'fresh authorizer token is usable');
expectTrue($token->outsideRefreshSkew($now, 300), 'fresh authorizer token is outside refresh skew');
expectSame('platform-1', $token->componentPlatformId(), 'authorizer token is platform scoped');
expectSame('wx-authorizer-1', $token->authorizerAppId(), 'authorizer token is authorizer scoped');

$lease = new AuthorizerTokenRefreshLease('platform-1', 'wx-authorizer-1', 'holder-1', $now->modify('+30 seconds'), 1);
expectTrue($lease->heldBy('holder-1', $now), 'refresh lease recognizes current holder');
expectTrue(!$lease->heldBy('holder-2', $now), 'refresh lease rejects another holder');

$preAuth = new PreAuthCodeResponse('pre-auth-code', 600);
expectSame(600, $preAuth->expiresIn(), 'pre-auth response preserves positive integer expiry');
expectThrows(static fn () => new PreAuthCodeResponse('pre-auth-code', 0), InvalidArgumentException::class, 'zero pre-auth expiry is rejected');

$queryAuth = new AuthorizerAuthorizationResponse('wx-authorizer-1', 'access-token', 'refresh-token', 7200, ['17', '18']);
expectSame('wx-authorizer-1', $queryAuth->authorizerAppId(), 'query-auth result exposes authorizer AppId');
expectSame(7200, $queryAuth->expiresIn(), 'query-auth result preserves integer expiry');
expectSame(['17', '18'], $queryAuth->scopeSet(), 'query-auth result normalizes safe scope ids');

$refresh = new AuthorizerRefreshResponse('next-access-token', 'rotated-refresh-token', 7200);
expectSame('rotated-refresh-token', $refresh->refreshToken(), 'refresh response carries optional rotated refresh token inside application boundary');
expectThrows(static fn () => new AuthorizerRefreshResponse('token', null, -1), InvalidArgumentException::class, 'negative authorizer expiry is rejected');

foreach ([
    AuthorizationIntentRepository::class => ['insert', 'findByStateHash', 'findByPreAuthCodeHash', 'tryClaim', 'releaseClaim', 'complete'],
    AuthorizerAuthorizationRepository::class => ['current', 'saveFromAuthorization', 'markUnauthorized', 'compareAndSetRefresh'],
    AuthorizerTokenRepository::class => ['current'],
    AuthorizerRefreshLeaseRepository::class => ['tryAcquire', 'release'],
] as $interface => $methods) {
    foreach ($methods as $method) {
        expectTrue(method_exists($interface, $method), $interface . ' exposes ' . $method);
    }
}
