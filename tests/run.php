<?php

declare(strict_types=1);

require __DIR__ . '/Support/bootstrap.php';

$files = [
    __DIR__ . '/Contract/StructureContractTest.php',
    __DIR__ . '/Contract/IamTenantAccountSchemaContractTest.php',
    __DIR__ . '/Unit/Common/Context/RequestContextTest.php',
    __DIR__ . '/Unit/Common/Context/CorrelationIdFactoryTest.php',
    __DIR__ . '/Unit/Common/Security/SecretValueTest.php',
    __DIR__ . '/Unit/Common/Error/ErrorEnvelopeTest.php',
    __DIR__ . '/Unit/Common/Audit/AuditEventTest.php',
    __DIR__ . '/Unit/Iam/LegacyAccountRoleResolverTest.php',
    __DIR__ . '/Unit/Iam/AdminUserTest.php',
    __DIR__ . '/Unit/Tenant/TenantTest.php',
    __DIR__ . '/Unit/Account/LegacyAccountTypeMapTest.php',
    __DIR__ . '/Unit/Account/AccountTest.php',
    __DIR__ . '/Unit/Account/LegacyAccountMappingTest.php',
    __DIR__ . '/Component/Account/ResolveLegacyAccountTest.php',
    __DIR__ . '/Unit/Iam/AdminSessionTest.php',
    __DIR__ . '/Unit/Iam/SessionTokenHasherTest.php',
    __DIR__ . '/Component/Iam/RestoreAdminSessionTest.php',
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
