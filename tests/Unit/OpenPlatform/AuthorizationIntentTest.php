<?php

declare(strict_types=1);

use modules\openplatform\domain\AuthorizationIntent;
use modules\openplatform\domain\AuthorizationIntentMode;

$now = new DateTimeImmutable('2026-09-08T09:00:00Z', new DateTimeZone('UTC'));
$localExpiry = $now->modify('+10 minutes');
$providerExpiry = $now->modify('+7 minutes');

$intent = AuthorizationIntent::pending(
    'intent-1',
    'platform-1',
    'tenant-1',
    AuthorizationIntentMode::BIND_EXISTING_ACCOUNT,
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
expectSame(AuthorizationIntentMode::BIND_EXISTING_ACCOUNT, $intent->mode(), 'existing Account mode is explicit');
expectSame('account-1', $intent->targetAccountId(), 'existing Account mode carries explicit target Account');
expectSame(hash('sha256', 'opaque-state'), $intent->stateHash(), 'intent stores state hash only');
expectSame(hash('sha256', 'pre-auth-code'), $intent->preAuthCodeHash(), 'intent stores pre-auth hash only');
expectSame($providerExpiry->getTimestamp(), $intent->effectiveExpiresAt()->getTimestamp(), 'provider expiry shortens local intent lifetime');
expectTrue($intent->validAt($now), 'fresh pending intent is valid');
expectTrue($intent->claimableAt($now), 'fresh pending intent is claimable');
expectTrue(!$intent->completed(), 'new intent is not completed');
expectSame(1, $intent->version(), 'new intent starts at version one');

$auto = AuthorizationIntent::pending(
    'intent-auto',
    'platform-1',
    'tenant-1',
    AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT,
    null,
    hash('sha256', 'auto-state'),
    hash('sha256', 'auto-pre-auth'),
    '1',
    $now,
    $localExpiry,
    $providerExpiry,
);
expectSame(AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT, $auto->mode(), 'auto provisioning mode is explicit');
expectSame(null, $auto->targetAccountId(), 'auto provisioning mode has no target Account');

expectThrows(
    static fn () => AuthorizationIntent::pending(
        'bad-bind', 'platform-1', 'tenant-1', AuthorizationIntentMode::BIND_EXISTING_ACCOUNT, null,
        hash('sha256', 'state'), hash('sha256', 'pre'), '1', $now, $localExpiry, $providerExpiry
    ),
    InvalidArgumentException::class,
    'existing Account mode requires target Account',
);
expectThrows(
    static fn () => AuthorizationIntent::pending(
        'bad-auto', 'platform-1', 'tenant-1', AuthorizationIntentMode::AUTO_PROVISION_ACCOUNT, 'account-1',
        hash('sha256', 'state'), hash('sha256', 'pre'), '1', $now, $localExpiry, $providerExpiry
    ),
    InvalidArgumentException::class,
    'auto provisioning mode forbids target Account',
);

$claimed = $intent->withClaim('holder-1', $now->modify('+30 seconds'));
expectSame('holder-1', $claimed->claimHolderId(), 'claim holder is explicit');
expectSame(AuthorizationIntentMode::BIND_EXISTING_ACCOUNT, $claimed->mode(), 'claim preserves explicit mode');
expectTrue(!$claimed->claimableAt($now->modify('+10 seconds')), 'live claim blocks a second completion worker');
expectTrue($claimed->claimableAt($now->modify('+31 seconds')), 'expired claim allows recovery');

$completed = $claimed->completedBy('wx-authorizer-1', $now->modify('+5 seconds'));
expectTrue($completed->completed(), 'completed intent is terminal');
expectSame(AuthorizationIntentMode::BIND_EXISTING_ACCOUNT, $completed->mode(), 'completion preserves explicit mode');
expectSame('wx-authorizer-1', $completed->completedAuthorizerAppId(), 'completed intent stores only safe authorizer AppId');
expectTrue(!$completed->claimableAt($now->modify('+40 seconds')), 'completed intent can never be claimed again');

expectThrows(
    static fn () => AuthorizationIntent::pending(
        'intent-2', 'platform-1', 'tenant-1', AuthorizationIntentMode::BIND_EXISTING_ACCOUNT, 'account-1',
        'not-a-sha256', hash('sha256', 'pre'), '1', $now, $localExpiry, $providerExpiry
    ),
    InvalidArgumentException::class,
    'invalid state hash must be rejected',
);

expectThrows(
    static fn () => AuthorizationIntent::pending(
        'intent-3', 'platform-1', 'tenant-1', AuthorizationIntentMode::BIND_EXISTING_ACCOUNT, 'account-1',
        hash('sha256', 'state'), hash('sha256', 'pre'), '1', $now, $now->modify('-1 second'), $providerExpiry
    ),
    InvalidArgumentException::class,
    'local expiry before creation must be rejected',
);
