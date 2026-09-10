<?php

declare(strict_types=1);

function acceptanceIamRuntimeTest(AcceptanceRuntime $runtime): void
{
    $db = $runtime->db();
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash_hmac('sha256', $rawToken, $runtime->config->pepper);

    $db->exec("INSERT INTO admin_users (id, username, password_hash, status, expires_at) VALUES ('accept-admin', 'accept-admin', 'acceptance-no-login', 'active', NULL)");
    $db->exec("INSERT INTO tenants (id, name, status) VALUES ('accept-tenant', 'V1 Acceptance Tenant', 'active')");
    $db->exec("INSERT INTO tenant_memberships (tenant_id, admin_user_id, role) VALUES ('accept-tenant', 'accept-admin', 'owner')");
    $db->exec("INSERT INTO roles (id, tenant_id, code, name, is_system) VALUES ('accept-openplatform-reader', 'accept-tenant', 'accept-openplatform-reader', 'Acceptance OpenPlatform Reader', 0)");
    $db->exec("INSERT INTO role_permissions (role_id, permission_id) SELECT 'accept-openplatform-reader', id FROM permissions WHERE permission_key = 'openplatform.authorizer.read'");
    $db->exec("INSERT INTO permission_assignments (id, tenant_id, account_id, admin_user_id, role_id) VALUES ('accept-openplatform-reader-assignment', 'accept-tenant', NULL, 'accept-admin', 'accept-openplatform-reader')");

    $session = $db->prepare(
        'INSERT INTO admin_sessions (id, admin_user_id, token_hash, issued_at, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 2 HOUR))',
    );
    $session->execute(['accept-session', 'accept-admin', $tokenHash]);

    $runtime->startServer();
    try {
        $path = '/api/v1/openplatform/provisionings/acceptance-not-found';
        $valid = $runtime->http('GET', $path, [
            'Authorization' => 'Bearer ' . $rawToken,
            'X-Tenant-Id' => 'accept-tenant',
        ]);
        acceptanceAssert($valid['status'] === 404, 'Valid IAM context must reach provisioning business 404.');
        acceptanceAssert(acceptanceCode($valid) === 'NOT_FOUND', 'Valid IAM context must return NOT_FOUND.');

        $badToken = $runtime->http('GET', $path, [
            'Authorization' => 'Bearer wrong-token',
            'X-Tenant-Id' => 'accept-tenant',
        ]);
        acceptanceAssert($badToken['status'] === 401, 'Invalid bearer token must return 401.');
        acceptanceAssert(acceptanceCode($badToken) === 'UNAUTHORIZED', 'Invalid bearer token must be UNAUTHORIZED.');

        $missingTenant = $runtime->http('GET', $path, [
            'Authorization' => 'Bearer ' . $rawToken,
        ]);
        acceptanceAssert($missingTenant['status'] === 403, 'Missing trusted Tenant selector must return 403.');
        acceptanceAssert(acceptanceCode($missingTenant) === 'FORBIDDEN', 'Missing Tenant selector must be FORBIDDEN.');

        $db->exec("UPDATE admin_users SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE id = 'accept-admin'");
        $expired = $runtime->http('GET', $path, [
            'Authorization' => 'Bearer ' . $rawToken,
            'X-Tenant-Id' => 'accept-tenant',
        ]);
        acceptanceAssert($expired['status'] === 401, 'Expired administrator must return 401.');
        acceptanceAssert(acceptanceCode($expired) === 'UNAUTHORIZED', 'Expired administrator must be UNAUTHORIZED.');

        $db->exec("UPDATE admin_users SET expires_at = NULL WHERE id = 'accept-admin'");
        $restored = $runtime->http('GET', $path, [
            'Authorization' => 'Bearer ' . $rawToken,
            'X-Tenant-Id' => 'accept-tenant',
        ]);
        acceptanceAssert($restored['status'] === 404, 'Restored administrator must reach business 404 again.');
        acceptanceAssert(acceptanceCode($restored) === 'NOT_FOUND', 'Restored administrator must return NOT_FOUND.');
    } finally {
        $runtime->stopServer();
    }
}
