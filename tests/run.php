<?php

declare(strict_types=1);

require __DIR__ . '/Support/bootstrap.php';

$files = [
    __DIR__ . '/Contract/StructureContractTest.php',
    __DIR__ . '/Contract/ThinkPhpBootConfigContractTest.php',
    __DIR__ . '/Contract/IamTenantAccountSchemaContractTest.php',
    __DIR__ . '/Contract/IamModulePlatformSchemaContractTest.php',
    __DIR__ . '/Contract/LegacyDatabaseReadOnlyContractTest.php',
    __DIR__ . '/Contract/EntitlementQuotaSchemaContractTest.php',
    __DIR__ . '/Contract/SiteThemeSchemaContractTest.php',
    __DIR__ . '/Contract/MemberOAuthWebhookSchemaContractTest.php',
    __DIR__ . '/Contract/ThinkPhpMemberOAuthPersistenceContractTest.php',
    __DIR__ . '/Unit/Common/Context/RequestContextTest.php',
    __DIR__ . '/Unit/Common/Context/CorrelationIdFactoryTest.php',
    __DIR__ . '/Unit/Common/Security/SecretValueTest.php',
    __DIR__ . '/Unit/Common/Error/ErrorEnvelopeTest.php',
    __DIR__ . '/Unit/Common/Audit/AuditEventTest.php',
    __DIR__ . '/Unit/Iam/LegacyAccountRoleResolverTest.php',
    __DIR__ . '/Unit/Iam/AdminUserTest.php',
    __DIR__ . '/Unit/Iam/LegacyPermissionPolicyTest.php',
    __DIR__ . '/Unit/Tenant/TenantTest.php',
    __DIR__ . '/Unit/Account/LegacyAccountTypeMapTest.php',
    __DIR__ . '/Unit/Account/AccountTest.php',
    __DIR__ . '/Unit/Account/LegacyAccountMappingTest.php',
    __DIR__ . '/Component/Account/ResolveLegacyAccountTest.php',
    __DIR__ . '/Unit/Iam/AdminSessionTest.php',
    __DIR__ . '/Unit/Iam/SessionTokenHasherTest.php',
    __DIR__ . '/Component/Iam/RestoreAdminSessionTest.php',
    __DIR__ . '/Unit/Module/RuntimeModuleResolverTest.php',
    __DIR__ . '/Unit/Module/BindingRuntimeRouterTest.php',
    __DIR__ . '/Unit/Module/LegacyModulePermissionCatalogTest.php',
    __DIR__ . '/Unit/Module/ModuleActionAuthorizerTest.php',
    __DIR__ . '/Unit/Module/ModulePluginRelationTest.php',
    __DIR__ . '/Unit/Module/ModuleBindingCompatibilityTest.php',
    __DIR__ . '/Unit/Legacy/LegacySerializedValueDecoderTest.php',
    __DIR__ . '/Component/Module/R20ModuleRuntimeRepositoryTest.php',
    __DIR__ . '/Component/Module/R20ModulePermissionRepositoryTest.php',
    __DIR__ . '/Component/Module/RuntimeModuleServiceTest.php',
    __DIR__ . '/Component/Module/ModuleAuthorizationServiceTest.php',
    __DIR__ . '/GoldenMaster/LegacyModuleAdapterTest.php',
    __DIR__ . '/Unit/Entitlement/TenantModuleEntitlementTest.php',
    __DIR__ . '/Component/Entitlement/TenantModuleEntitlementServiceTest.php',
    __DIR__ . '/Unit/Quota/AccountCreationQuotaPolicyTest.php',
    __DIR__ . '/Unit/Quota/QuotaLedgerTest.php',
    __DIR__ . '/Component/Quota/QuotaServiceIdempotencyTest.php',
    __DIR__ . '/GoldenMaster/R20AccountQuotaSnapshotTest.php',
    __DIR__ . '/Unit/Site/SiteTest.php',
    __DIR__ . '/Unit/Site/DomainNameTest.php',
    __DIR__ . '/Component/Site/SiteDomainResolverTest.php',
    __DIR__ . '/GoldenMaster/R20SiteSnapshotTest.php',
    __DIR__ . '/Unit/Theme/ThemeVersionTest.php',
    __DIR__ . '/Unit/Theme/StyleSnapshotTest.php',
    __DIR__ . '/Component/Theme/ThemeReleaseServiceTest.php',
    __DIR__ . '/Unit/Theme/SafeThemeRendererTest.php',
    __DIR__ . '/GoldenMaster/R20ThemeStyleSnapshotTest.php',
    __DIR__ . '/Unit/Member/ExternalIdentityTest.php',
    __DIR__ . '/GoldenMaster/R20MemberIdentitySnapshotTest.php',
    __DIR__ . '/Unit/OAuth/ReturnUrlPolicyTest.php',
    __DIR__ . '/Unit/OAuth/OAuthStateTest.php',
    __DIR__ . '/Component/Member/MemberIdentityServiceTest.php',
    __DIR__ . '/Component/OAuth/OAuthOrchestratorTest.php',
    __DIR__ . '/GoldenMaster/LegacyEntrypointMappingTest.php',
];

$failed = 0;
foreach ($files as $file) {
    try {
        require $file;
        fwrite(STDOUT, '[PASS] ' . basename($file) . PHP_EOL);
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, '[FAIL] ' . basename($file) . ': ' . $e->getMessage() . PHP_EOL);
    }
}

exit($failed === 0 ? 0 : 1);
