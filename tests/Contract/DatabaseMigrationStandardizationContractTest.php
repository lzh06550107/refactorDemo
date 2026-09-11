<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Unit/Migration/V1BaselineSqlTest.php';

(static function (): void {
    $root = dirname(__DIR__, 2);

    $composer = json_decode(
        (string) file_get_contents($root . '/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    if (($composer['require']['topthink/think-migration'] ?? null) !== '^3.1') {
        throw new RuntimeException('topthink/think-migration:^3.1 is required.');
    }

    $lock = json_decode(
        (string) file_get_contents($root . '/composer.lock'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $migrationPackage = null;
    foreach ($lock['packages'] ?? [] as $package) {
        if (($package['name'] ?? null) === 'topthink/think-migration') {
            $migrationPackage = $package;
            break;
        }
    }
    if (!is_array($migrationPackage) || ($migrationPackage['version'] ?? null) !== 'v3.1.1') {
        throw new RuntimeException('composer.lock must pin topthink/think-migration v3.1.1.');
    }

    $expectedPhpMigrations = [
        '20260907000100_v001_iam_tenant_account.php',
        '20260907000200_v002_iam_module_platform.php',
        '20260907000300_v003_entitlement_quota.php',
        '20260907000400_v004_site_theme_runtime.php',
        '20260908000500_v005_member_oauth_webhook.php',
        '20260908000600_v006_miniapp_identity_session.php',
        '20260908000700_v007_openplatform_component_trust.php',
        '20260908000800_v008_openplatform_authorizer_lifecycle.php',
        '20260909000900_v009_openplatform_authorizer_provisioning.php',
    ];
    $actualPhpMigrations = array_map(
        'basename',
        glob($root . '/database/migrations/*.php') ?: [],
    );
    sort($actualPhpMigrations);
    if ($actualPhpMigrations !== $expectedPhpMigrations) {
        throw new RuntimeException('database/migrations must contain exactly the nine V1 PHP wrappers.');
    }

    if ((glob($root . '/database/migrations/*_up.sql') ?: []) !== []
        || (glob($root . '/database/migrations/*_down.sql') ?: []) !== []) {
        throw new RuntimeException('Legacy SQL files must not remain under database/migrations.');
    }

    $expectedSqlFiles = [
        '20260907_001_iam_tenant_account_down.sql',
        '20260907_001_iam_tenant_account_up.sql',
        '20260907_002_iam_module_platform_down.sql',
        '20260907_002_iam_module_platform_up.sql',
        '20260907_003_entitlement_quota_down.sql',
        '20260907_003_entitlement_quota_up.sql',
        '20260907_004_site_theme_runtime_down.sql',
        '20260907_004_site_theme_runtime_up.sql',
        '20260908_005_member_oauth_webhook_down.sql',
        '20260908_005_member_oauth_webhook_up.sql',
        '20260908_006_miniapp_identity_session_down.sql',
        '20260908_006_miniapp_identity_session_up.sql',
        '20260908_007_openplatform_component_trust_down.sql',
        '20260908_007_openplatform_component_trust_up.sql',
        '20260908_008_openplatform_authorizer_lifecycle_down.sql',
        '20260908_008_openplatform_authorizer_lifecycle_up.sql',
        '20260909_009_openplatform_authorizer_provisioning_down.sql',
        '20260909_009_openplatform_authorizer_provisioning_up.sql',
    ];

    $manifestPath = $root . '/database/schema/v1/manifest.sha256';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Immutable V1 SHA-256 manifest is required.');
    }
    $lines = file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines) || count($lines) !== 18) {
        throw new RuntimeException('V1 SHA-256 manifest must contain exactly 18 entries.');
    }

    $manifestFiles = [];
    foreach ($lines as $line) {
        if (preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9_]+\.sql)$/', $line, $matches) !== 1) {
            throw new RuntimeException('Invalid V1 SHA-256 manifest entry.');
        }
        [, $expectedHash, $file] = $matches;
        $path = $root . '/database/schema/v1/' . $file;
        if (!is_file($path) || hash_file('sha256', $path) !== $expectedHash) {
            throw new RuntimeException('V1 baseline hash mismatch: ' . $file);
        }
        $manifestFiles[] = $file;
    }
    sort($manifestFiles);
    if ($manifestFiles !== $expectedSqlFiles) {
        throw new RuntimeException('V1 baseline manifest file set does not exactly match 001-009 up/down SQL.');
    }

    $sharedMigration = (string) file_get_contents($root . '/app/common/migration/V1SqlMigration.php');
    if (!str_contains($sharedMigration, '$this->execute($statement)')) {
        throw new RuntimeException('V1 wrappers must execute through the active migration adapter.');
    }

    if (is_file($root . '/.github/workflows/migration-bootstrap-artifacts.yml')) {
        throw new RuntimeException('Temporary migration artifact bootstrap workflow must not remain committed.');
    }
})();
