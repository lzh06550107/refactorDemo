<?php

declare(strict_types=1);

use app\site\compat\R20SiteSnapshotMapper;
use app\site\domain\DomainBindingSource;
use app\site\domain\SiteStatus;

$mapper = new R20SiteSnapshotMapper();
$default = $mapper->fromSiteMulti([
    'id' => 12,
    'uniacid' => 9,
    'title' => 'Legacy default',
    'styleid' => 23,
    'status' => 0,
    'bindhost' => 'WWW.Example.COM',
], 'tenant-9', 'account-9', 12);
expectSame(9, $default->legacyUniacid(), 'legacy uniacid is preserved separately');
expectSame(12, $default->legacyMultiId(), 'legacy multiid is preserved separately');
expectSame(23, $default->legacyStyleId(), 'legacy styleid is preserved separately');
expectSame(SiteStatus::ENABLED, $default->status(), 'R20 default site is treated as enabled even when stored status drifted');
expectTrue($default->isDefault(), 'default site identity comes from uni_settings.default_site');
expectSame('www.example.com', $default->bindHost()?->value(), 'site_multi.bindhost is normalized');

$webapp = $mapper->fromAccountBindDomain([
    'uniacid' => 9,
    'bind_domain' => 'https://App.Example.com',
    'default_module' => 'shop',
], 'tenant-9', 'account-9', 'site-12');
expectSame('app.example.com', $webapp->host()->value(), 'account bind domain strips scheme');
expectSame('shop', $webapp->defaultModule(), 'webapp default module is preserved');
expectSame(DomainBindingSource::R20_ACCOUNT_BIND_DOMAIN, $webapp->source(), 'binding source is explicit');
