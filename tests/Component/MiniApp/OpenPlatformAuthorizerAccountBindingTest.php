<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$adapterPath = $root . '/app/miniapp/infrastructure/OpenPlatformAuthorizerAccountBinding.php';
expectTrue(is_file($adapterPath), 'R8C MiniApp authorizer Account binding adapter must exist');
$source = (string) file_get_contents($adapterPath);

expectTrue(str_contains($source, 'implements AuthorizerAccountBinding'), 'binding adapter implements OpenPlatform binding port');
expectTrue(str_contains($source, 'Db::transaction'), 'binding adapter validates and writes in one short transaction');
expectTrue(str_contains($source, "Db::table('tenants')"), 'binding adapter validates existing Tenant');
expectTrue(str_contains($source, "Db::table('accounts')"), 'binding adapter validates existing Account');
expectTrue(str_contains($source, "'tenant_id' => \$tenantId") || str_contains($source, "where('tenant_id', \$tenantId)"), 'binding adapter scopes Account to intent Tenant');
expectTrue(str_contains($source, "'wechat_mini_program'") || str_contains($source, 'AccountType::WECHAT_MINI_PROGRAM'), 'binding adapter accepts only WeChat Mini Program Account type');
expectTrue(str_contains($source, "Db::table('miniapp_provider_accounts')"), 'binding adapter writes only MiniApp provider configuration');
expectTrue(str_contains($source, "'connection_mode' => 'component'") || str_contains($source, 'MiniAppConnectionMode::COMPONENT'), 'binding adapter forces component mode');
expectTrue(str_contains($source, "'credential_ref' => null"), 'binding adapter clears manual credential reference');
expectTrue(str_contains($source, "'component_platform_id' => \$componentPlatformId"), 'binding adapter persists exact Component Platform');
expectTrue(str_contains($source, "'provider_app_id' => \$authorizerAppId"), 'binding adapter persists returned authorizer AppId');
expectTrue(str_contains($source, 'ErrorCode::FORBIDDEN'), 'conflicting or ineligible binding fails closed');
expectTrue(!preg_match("/Db::table\('(?:tenants|accounts)'\)->insert/", $source), 'R8C binding must never create Tenant or Account rows');
