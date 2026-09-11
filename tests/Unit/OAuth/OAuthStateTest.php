<?php

declare(strict_types=1);

use modules\oauth\domain\OAuthState;

$issuedAt = new \DateTimeImmutable('2026-09-08T10:00:00+08:00');
$expiresAt = $issuedAt->modify('+10 minutes');
$state = new OAuthState(
    'state-1',
    str_repeat('a', 64),
    'tenant-1',
    'business-account-1',
    'provider-account-9',
    'wechat_official',
    '/member/home',
    $issuedAt,
    $expiresAt,
);

expectTrue(!$state->isExpired($issuedAt->modify('+9 minutes 59 seconds')), 'state remains valid before TTL boundary');
expectTrue($state->isExpired($expiresAt), 'state expires at the TTL boundary');
expectTrue(!$state->isConsumed(), 'fresh state is not consumed');
expectTrue(!$state->hasCompleteResult(), 'fresh state has no semantic result');
expectSame('business-account-1', $state->businessAccountId(), 'business account context is preserved');
expectSame('provider-account-9', $state->oauthProviderAccountId(), 'oauth provider account context is preserved separately');

$completed = $state->complete('member-7', 'external-8', $issuedAt->modify('+2 minutes'));
expectTrue($completed->isConsumed(), 'completed state is consumed');
expectTrue($completed->hasCompleteResult(), 'completed state has a semantic result');
expectSame('member-7', $completed->resultMemberId(), 'completed state stores member result');
expectSame('external-8', $completed->resultExternalIdentityId(), 'completed state stores external identity result');
expectTrue(!$state->isConsumed(), 'completion returns a new immutable state rather than mutating original state');
