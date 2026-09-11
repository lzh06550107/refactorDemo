<?php

declare(strict_types=1);

use modules\member\domain\ExternalIdentity;
use modules\member\domain\ProviderIdentity;

$wechatA = new ProviderIdentity('wechat_official', 'account-provider-a', 'same-openid', 'union-1');
$wechatB = new ProviderIdentity('wechat_official', 'account-provider-b', 'same-openid', 'union-1');

expectTrue($wechatA->providerKey() !== $wechatB->providerKey(), 'same openid under different provider accounts must produce different provider keys');
expectSame('wechat_official|account-provider-a|same-openid', $wechatA->providerKey(), 'provider key must include provider type, provider account and subject');

$identity = new ExternalIdentity(
    'external-1',
    'tenant-1',
    'member-1',
    $wechatA,
    11,
    22,
    33,
);
expectSame('tenant-1', $identity->tenantId(), 'external identity must preserve tenant ownership');
expectSame('member-1', $identity->memberId(), 'external identity must preserve member ownership');
expectSame($wechatA->providerKey(), $identity->providerIdentity()->providerKey(), 'external identity must preserve provider scoped subject');
expectSame(11, $identity->legacyUniacid(), 'legacy uniacid is retained as migration metadata');
expectSame(22, $identity->legacyAcid(), 'legacy acid is retained as migration metadata');
expectSame(33, $identity->legacyUid(), 'legacy uid is retained as migration metadata');
