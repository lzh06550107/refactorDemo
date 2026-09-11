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

    $manifest = $root . '/database/schema/v1/manifest.sha256';
    if (!is_file($manifest)) {
        throw new RuntimeException('Immutable V1 SHA-256 manifest is required.');
    }

    $fresh = (string) file_get_contents($root . '/tests/Acceptance/FreshDatabaseMigrationTest.php');
    if (str_contains($fresh, "'*_up.sql'") || str_contains($fresh, 'preg_split')) {
        throw new RuntimeException('Fresh acceptance must use the real migration runtime, not the legacy SQL runner.');
    }

    $browser = (string) file_get_contents($root . '/tests/E2E/PrepareAdminBrowserDatabase.php');
    if (!str_contains($browser, "'migrate:run'")) {
        throw new RuntimeException('Admin browser database setup must execute real migrate:run.');
    }
})();
