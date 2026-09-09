<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$adapterPath = $root . '/app/miniapp/infrastructure/OpenPlatformAuthorizerAccountBinding.php';
expectTrue(is_file($adapterPath), 'OpenPlatform authorizer Account binding adapter must exist');
$source = (string) file_get_contents($adapterPath);

expectTrue(str_contains($source, 'implements AuthorizerAccountBinding'), 'binding adapter preserves R8C OpenPlatform binding port');
expectTrue(str_contains($source, 'Db::transaction'), 'binding adapter validates ownership and writes in one short transaction');
expectTrue(str_contains($source, "Db::table('tenants')"), 'binding adapter validates existing Tenant');
expectTrue(str_contains($source, "Db::table('accounts')"), 'binding adapter validates existing Account');
expectTrue(str_contains($source, "'tenant_id' => \$tenantId") || str_contains($source, "where('tenant_id', \$tenantId)"), 'binding adapter scopes Account to intent Tenant');
expectTrue(str_contains($source, "'wechat_mini_program'") || str_contains($source, 'AccountType::WECHAT_MINI_PROGRAM'), 'binding adapter recognizes WeChat Mini Program Account type');
expectTrue(str_contains($source, "'official_account'") || str_contains($source, 'AccountType::OFFICIAL_ACCOUNT'), 'binding adapter recognizes Official Account type');
expectTrue(str_contains($source, "Db::table('authorizer_account_ownerships')"), 'binding adapter uses global canonical ownership table');
expectTrue(str_contains($source, "Db::table('miniapp_provider_accounts')"), 'Mini Program binding uses miniapp provider table');
expectTrue(str_contains($source, "Db::table('official_account_provider_accounts')"), 'Official Account binding uses dedicated official-account provider table');
expectTrue(str_contains($source, "'connection_mode' => 'component'") || str_contains($source, "'component'"), 'OpenPlatform binding forces component mode');
expectTrue(str_contains($source, "'credential_ref' => null"), 'OpenPlatform binding never retains manual credential reference');
expectTrue(str_contains($source, "'component_platform_id' => \$componentPlatformId"), 'binding adapter persists exact Component Platform');
expectTrue(str_contains($source, "'provider_app_id' => \$authorizerAppId"), 'binding adapter persists exact authorizer AppId');
expectTrue(str_contains($source, "'enabled' => 1"), 'bind/reconnect enables provider connection projection');

$ownershipPos = strpos($source, "Db::table('authorizer_account_ownerships')");
$miniPos = strpos($source, "Db::table('miniapp_provider_accounts')");
$officialPos = strpos($source, "Db::table('official_account_provider_accounts')");
expectTrue($ownershipPos !== false && $miniPos !== false && $ownershipPos < $miniPos, 'canonical ownership is locked/checked before Mini Program provider table');
expectTrue($ownershipPos !== false && $officialPos !== false && $ownershipPos < $officialPos, 'canonical ownership is locked/checked before Official Account provider table');
expectTrue(str_contains($source, 'lock(true)'), 'ownership-aware binding uses row locks');
expectTrue(str_contains($source, 'ErrorCode::CONFLICT'), 'canonical authorizer ownership conflict is explicit');
expectTrue(str_contains($source, '409'), 'canonical ownership conflict maps to HTTP 409');
expectTrue(str_contains($source, 'hash_equals') || str_contains($source, '!=='), 'existing ownership is compared against exact Tenant/Account identity');
expectTrue(!preg_match("/Db::table\('authorizer_account_ownerships'\)[^;]*->delete\(/s", $source), 'binding never deletes canonical ownership');
expectTrue(!preg_match("/Db::table\('(?:tenants|accounts)'\)->insert/", $source), 'existing-account binding never creates Tenant or Account rows');

expectTrue(
    str_contains($source, "'account_type' => \$accountType")
    || str_contains($source, "'account_type' => (string) \$account['type']")
    || str_contains($source, "'account_type' => \$account['type']"),
    'new ownership freezes the local Account type',
);
expectTrue(str_contains($source, "'first_bound_at'"), 'new ownership records first bind time');
expectTrue(str_contains($source, "'last_connected_at'"), 'bind/reconnect advances last connected time');
