<?php

declare(strict_types=1);

use app\openplatform\domain\AuthorizationIntent;
use DateTimeImmutable;
use DateTimeZone;

$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('2026-09-08T09:00:00Z', $utc);
$localExpiry = $now->modify('+10 minutes');
$providerExpiry = $now->modify('+7 minutes');

$intent = AuthorizationIntent::pending(
    'intent-1',
    'platform-1',
    'tenant-1',
    'account-1',
    hash('sha256', 'opaque-state'),
    hash('sha256', 'pre-auth-code'),
    '1',
    $now,
    $localExpiry,
    $providerExpiry,
);

expectSame('intent-1', $intent->id(), 'intent id is explicit');
expectSame('platform-1', $intent->componentPlatformId(), 'intent is platform scoped');
expectSame('tenant-1', $intent->tenantId(), 'intent carries explicit tenant binding context');
expectSame('account-1', $intent->targetAccountId(), 'intent carries explicit existing target Account');
expectSame(hash('sha256', 'opaque-state'), $intent->stateHash(), 'intent stores state hash only');
expectSame(hash('sha256', 'pre-auth-code'), $intent->preAuthCodeHash(), 'intent stores pre-auth hash only');
expectSame($providerExpiry->getTimestamp(), $intent->effectiveExpiresAt()->getTimestamp(), 'provider expiry shortens local intent lifetime');
expectTrue($intent->validAt($now), 'fresh pending intent is valid');
expectTrue($intent->claimableAt($now), 'fresh pending intent is claimable');
expectTrue(!$intent->completed(), 'new intent is not completed');
expectSame(1, $intent->version(), 'new intent starts at version one');

$claimed = $intent->withClaim('holder-1', $now->modify('+30 seconds'));
expectSame('holder-1', $claimed->claimHolderId(), 'claim holder is explicit');
expectTrue(!$claimed->claimableAt($now->modify('+10 seconds')), 'live claim blocks a second completion worker');
expectTrue($claimed->claimableAt($now->modify('+31 seconds')), 'expired claim allows recovery');

$completed = $claimed->completedBy('wx-authorizer-1', $now->modify('+5 seconds'));
expectTrue($completed->completed(), 'completed intent is terminal');
expectSame('wx-authorizer-1', $completed->completedAuthorizerAppId(), 'completed intent stores only safe authorizer AppId');
expectTrue(!$completed->claimableAt($now->modify('+40 seconds')), 'completed intent can never be claimed again');

expectThrows(
    static fn () => AuthorizationIntent::pending(
        'intent-2', 'platform-1', 'tenant-1', 'account-1', 'not-a-sha256', hash('sha256', 'pre'), '1', $now, $localExpiry, $providerExpiry
    ),
    InvalidArgumentException::class,
    'invalid state hash must be rejected',
);

expectThrows(
    static fn () => AuthorizationIntent::pending(
        'intent-3', 'platform-1', 'tenant-1', 'account-1', hash('sha256', 'state'), hash('sha256', 'pre'), '1', $now, $now->modify('-1 second'), $providerExpiry
    ),
    InvalidArgumentException::class,
    'local expiry before creation must be rejected',
);
