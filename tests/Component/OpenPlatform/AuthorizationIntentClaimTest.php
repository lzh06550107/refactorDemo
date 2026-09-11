<?php

declare(strict_types=1);

use modules\openplatform\domain\AuthorizationIntent;
use modules\openplatform\domain\AuthorizationIntentMode;

$now = new DateTimeImmutable('2026-09-08T09:40:00Z');
$intent = AuthorizationIntent::pending(
    'intent-claim-1',
    'platform-1',
    'tenant-1',
    AuthorizationIntentMode::BIND_EXISTING_ACCOUNT,
    'account-1',
    hash('sha256', 'state-claim'),
    hash('sha256', 'pre-auth-claim'),
    '1',
    $now,
    $now->modify('+600 seconds'),
    $now->modify('+300 seconds'),
);

$claimed = $intent->withClaim('holder-1', $now->modify('+30 seconds'));
expectSame(2, $claimed->version(), 'completion claim increments intent version');
expectSame('holder-1', $claimed->claimHolderId(), 'completion claim records holder');
expectSame(AuthorizationIntentMode::BIND_EXISTING_ACCOUNT, $claimed->mode(), 'completion claim preserves explicit bind mode');
expectSame($now->modify('+30 seconds')->getTimestamp(), $claimed->claimExpiresAt()?->getTimestamp(), 'completion claim uses bounded 30-second lease');
expectTrue(!$claimed->claimableAt($now->modify('+29 seconds')), 'live claim blocks competing completion channel');
expectTrue($claimed->claimableAt($now->modify('+31 seconds')), 'expired completion claim is recoverable');

$completed = $claimed->completedBy('wx-authorizer-1', $now->modify('+5 seconds'));
expectSame(3, $completed->version(), 'completion advances intent version after claim');
expectSame(null, $completed->claimHolderId(), 'completed intent clears claim holder in domain state');
expectSame(null, $completed->claimExpiresAt(), 'completed intent clears claim expiry in domain state');
expectSame(AuthorizationIntentMode::BIND_EXISTING_ACCOUNT, $completed->mode(), 'completion preserves explicit bind mode');
expectSame('wx-authorizer-1', $completed->completedAuthorizerAppId(), 'completed intent exposes only safe authorizer AppId');
expectTrue(!$completed->claimableAt($now->modify('+31 seconds')), 'completed intent is terminal even after old claim expiry');
