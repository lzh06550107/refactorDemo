<?php

declare(strict_types=1);

function acceptanceFreshDatabaseMigrationTest(AcceptanceRuntime $runtime): void
{
    $db = $runtime->resetDatabase();
    $expected = [
        '20260907_001_iam_tenant_account_up.sql',
        '20260907_002_iam_module_platform_up.sql',
        '20260907_003_entitlement_quota_up.sql',
        '20260907_004_site_theme_runtime_up.sql',
        '20260908_005_member_oauth_webhook_up.sql',
        '20260908_006_miniapp_identity_session_up.sql',
        '20260908_007_openplatform_component_trust_up.sql',
        '20260908_008_openplatform_authorizer_lifecycle_up.sql',
        '20260909_009_openplatform_authorizer_provisioning_up.sql',
    ];

    $directory = $runtime->config->root . '/database/migrations';
    $actual = array_map('basename', glob($directory . '/*_up.sql') ?: []);
    sort($actual);
    acceptanceAssert($actual === $expected, 'Fresh migration set does not exactly match V1 001-009.');

    foreach ($expected as $migration) {
        $sql = file_get_contents($directory . '/' . $migration);
        acceptanceAssert(is_string($sql) && trim($sql) !== '', 'Migration is empty: ' . $migration);

        try {
            $db->exec($sql);
        } catch (Throwable $error) {
            throw new RuntimeException(
                'Migration failed: ' . $migration . ': ' . $error->getMessage(),
                0,
                $error,
            );
        }
    }

    foreach ([
        'admin_users',
        'tenants',
        'permission_assignments',
        'component_platforms',
        'authorizer_authorizations',
        'authorizer_provisionings',
        'authorizer_provisioning_jobs',
    ] as $table) {
        $statement = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
        );
        $statement->execute([$runtime->config->database, $table]);
        acceptanceAssert((int) $statement->fetchColumn() === 1, 'Expected V1 table is missing: ' . $table);
    }

    $permissions = $db->query(
        "SELECT permission_key FROM permissions WHERE permission_key LIKE 'openplatform.%' ORDER BY permission_key",
    )->fetchAll(PDO::FETCH_COLUMN);
    acceptanceAssert($permissions === [
        'openplatform.authorizer.bind',
        'openplatform.authorizer.provision',
        'openplatform.authorizer.read',
        'openplatform.authorizer.refresh_metadata',
        'openplatform.authorizer.retry_provision',
        'openplatform.authorizer.start',
    ], 'OpenPlatform permission seed set is incomplete.');
}
