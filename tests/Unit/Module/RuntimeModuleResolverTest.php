<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\module\domain\AccountModuleConfig;
use app\module\domain\ModuleDefinition;
use app\module\domain\ModuleLifecycleStatus;
use app\module\domain\ModuleSupportMatrix;
use app\module\domain\RuntimeModuleResolver;

$resolver = new RuntimeModuleResolver();
$definition = new ModuleDefinition(
    name: 'demo',
    title: 'Demo',
    version: '1.2.3',
    system: false,
    status: ModuleLifecycleStatus::ACTIVE,
    support: new ModuleSupportMatrix([AccountType::OFFICIAL_ACCOUNT]),
    settingsEnabled: true,
    ruleFieldsEnabled: false,
);

$runtime = $resolver->resolve($definition, null, AccountType::OFFICIAL_ACCOUNT);
expectTrue($runtime !== null && $runtime->enabled(), 'missing uni_account_modules row must default enabled');
expectSame([], $runtime?->settings(), 'missing config must expose empty settings');

$disabled = new AccountModuleConfig('acct-1', 'tenant-1', 'demo', false, 9, true, false, ['x' => 1]);
expectSame(null, $resolver->resolve($definition, $disabled, AccountType::OFFICIAL_ACCOUNT), 'explicit disabled account config must be hidden when enabledOnly=true');
$disabledVisible = $resolver->resolve($definition, $disabled, AccountType::OFFICIAL_ACCOUNT, false);
expectTrue($disabledVisible !== null && !$disabledVisible->enabled(), 'enabledOnly=false must preserve explicit disabled state');
expectSame(['x' => 1], $disabledVisible?->settings(), 'account config settings must overlay global definition');
expectSame(9, $disabledVisible?->displayOrder(), 'display order must overlay');

$systemDefinition = new ModuleDefinition(
    name: 'corex', title: 'Core X', version: '1', system: true,
    status: ModuleLifecycleStatus::ACTIVE,
    support: new ModuleSupportMatrix([AccountType::OFFICIAL_ACCOUNT]),
);
$systemRuntime = $resolver->resolve($systemDefinition, new AccountModuleConfig('acct-1', 'tenant-1', 'corex', false), AccountType::OFFICIAL_ACCOUNT);
expectTrue($systemRuntime !== null && $systemRuntime->enabled(), 'R20 system modules remain enabled regardless of account enabled flag');

$recycled = new ModuleDefinition(
    name: 'old', title: 'Old', version: '1', system: false,
    status: ModuleLifecycleStatus::RECYCLED,
    support: new ModuleSupportMatrix([AccountType::OFFICIAL_ACCOUNT]),
);
expectSame(null, $resolver->resolve($recycled, null, AccountType::OFFICIAL_ACCOUNT), 'recycled module must be hidden when enabledOnly=true');
expectTrue($resolver->resolve($recycled, null, AccountType::OFFICIAL_ACCOUNT, false) !== null, 'enabledOnly=false may inspect recycled module snapshot');

expectSame(null, $resolver->resolve($definition, null, AccountType::WEBAPP), 'unsupported account type must reject module runtime');
