<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$contractPath = $root . '/modules/openplatform/contract/AuthorizerAccountFinalizer.php';
$implementationPath = $root . '/modules/openplatform/infrastructure/ThinkPhpAuthorizerAccountFinalizer.php';

expectTrue(is_file($contractPath), 'AuthorizerAccountFinalizer contract must exist');
expectTrue(is_file($implementationPath), 'ThinkPHP Authorizer Account finalizer must exist');

$contract = (string) file_get_contents($contractPath);
$source = (string) file_get_contents($implementationPath);

expectTrue(str_contains($contract, 'function provision('), 'finalizer contract exposes atomic provision operation');
expectTrue(str_contains($contract, 'function reconcile('), 'finalizer contract exposes crash reconciliation operation');
expectTrue(str_contains($source, 'implements AuthorizerAccountFinalizer'), 'ThinkPHP finalizer implements the approved port');
expectTrue(str_contains($source, 'Db::transaction'), 'all Account finalization writes are wrapped by one DB transaction');
expectTrue(str_contains($source, "Db::table('authorizer_provisionings')"), 'finalizer locks durable provisioning state');
expectTrue(str_contains($source, "Db::table('authorizer_authorizations')"), 'finalizer re-checks authoritative authorization state');
expectTrue(str_contains($source, "Db::table('authorizer_account_ownerships')"), 'finalizer locks canonical ownership before creation');
expectTrue(str_contains($source, "Db::table('accounts')"), 'finalizer creates the internal Account');
expectTrue(str_contains($source, "Db::table('miniapp_provider_accounts')"), 'Mini Program finalization writes Mini Program provider projection');
expectTrue(str_contains($source, "Db::table('official_account_provider_accounts')"), 'Official Account finalization writes Official Account provider projection');
expectTrue(str_contains($source, 'lock(true)'), 'finalizer serializes provisioning and ownership with row locks');
expectTrue(str_contains($source, "'status' => 'active'"), 'new Account starts active after active authorization re-check');
expectTrue(str_contains($source, "'connection_mode' => 'component'"), 'auto-provisioned provider connection is component-managed');
expectTrue(str_contains($source, "'credential_ref' => null"), 'component-managed projection stores no manual credential reference');
expectTrue(str_contains($source, "'enabled' => 1"), 'new provider projection is enabled');
expectTrue(str_contains($source, 'AccountType::WECHAT_MINI_PROGRAM'), 'finalizer branches explicitly for Mini Program');
expectTrue(str_contains($source, 'AccountType::OFFICIAL_ACCOUNT'), 'finalizer branches explicitly for Official Account');
expectTrue(str_contains($source, '微信小程序 · '), 'Mini Program missing nickname has deterministic safe fallback');
expectTrue(str_contains($source, '微信公众号 · '), 'Official Account missing nickname has deterministic safe fallback');
expectTrue(str_contains($source, "'status' => 'provisioned'"), 'same finalizer transaction persists PROVISIONED business state');

$provisioningPos = strpos($source, "Db::table('authorizer_provisionings')");
$ownershipPos = strpos($source, "Db::table('authorizer_account_ownerships')");
$accountPos = strpos($source, "Db::table('accounts')");
expectTrue($provisioningPos !== false && $ownershipPos !== false && $provisioningPos < $ownershipPos, 'lock order is provisioning before canonical ownership');
expectTrue($ownershipPos !== false && $accountPos !== false && $ownershipPos < $accountPos, 'ownership is checked before Account insertion');
expectTrue(!preg_match("/Db::table\('accounts'\)[^;]*->update\(/s", $source), 'provider metadata refresh/finalizer never overwrites Tenant-owned Account.name');
expectTrue(!preg_match("/Db::table\('miniapp_provider_accounts'\)[^;]*provider_app_id[^;]*official_account/s", $source), 'Official Account branch never writes Mini Program provider table');
