<?php

declare(strict_types=1);

function acceptanceRetryRuntimeTest(AcceptanceRuntime $runtime): void
{
    $db = $runtime->db();
    $tenantId = 'accept-retry-tenant';
    $platformId = 'accept-retry-platform';
    $authorizerAppId = 'wx-accept-retry-authorizer';
    $intentId = 'accept-retry-intent';
    $provisioningId = 'accept-retry-provisioning';
    $adminId = 'accept-retry-admin';
    $roleId = 'accept-retry-role';
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash_hmac('sha256', $rawToken, $runtime->config->pepper);

    $db->exec("INSERT INTO tenants (id, name, status) VALUES ('{$tenantId}', 'Retry Acceptance Tenant', 'active')");
    $db->exec("INSERT INTO component_platforms (id, component_app_id, app_secret_ref, verify_token_ref, encoding_aes_key_ref, enabled) VALUES ('{$platformId}', 'wx-accept-retry-component', 'acceptance-secret-ref', 'acceptance-secret-ref', 'acceptance-secret-ref', 1)");

    $intent = $db->prepare(
        'INSERT INTO openplatform_authorization_intents (id, component_platform_id, tenant_id, intent_mode, target_account_id, state_hash, pre_auth_code_hash, provider_pre_auth_expires_at, requested_auth_type, created_at, expires_at, version) VALUES (?, ?, ?, ?, NULL, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 MINUTE), ?, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 30 MINUTE), 1)',
    );
    $intent->execute([
        $intentId,
        $platformId,
        $tenantId,
        'auto_provision_account',
        hash('sha256', $intentId . ':state'),
        hash('sha256', $intentId . ':preauth'),
        'all',
    ]);

    $authorization = $db->prepare(
        'INSERT INTO authorizer_authorizations (component_platform_id, authorizer_app_id, status, refresh_token_ciphertext, refresh_token_key_version, refresh_token_hash, scope_json, provider_updated_at, first_authorized_at, last_authorized_at, unauthorized_at, version) VALUES (?, ?, ?, NULL, NULL, ?, ?, UTC_TIMESTAMP(6), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY), UTC_TIMESTAMP(6), NULL, 1)',
    );
    $authorization->execute([
        $platformId,
        $authorizerAppId,
        'active',
        hash('sha256', 'acceptance-retry-refresh-token'),
        '[]',
    ]);

    $provisioning = $db->prepare(
        'INSERT INTO authorizer_provisionings (id, source_intent_id, tenant_id, component_platform_id, authorizer_app_id, account_type, status, metadata_version, quota_resource_key, quota_consume_entry_id, quota_release_entry_id, account_id, last_error_code, last_error_stage, created_at, updated_at, completed_at, version) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NULL, NULL, NULL, NULL, ?, ?, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 MINUTE), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE), 3)',
    );
    $provisioning->execute([
        $provisioningId,
        $intentId,
        $tenantId,
        $platformId,
        $authorizerAppId,
        'wechat_mini_program',
        'quota_blocked',
        'quota_insufficient',
        'quota',
    ]);

    $job = $db->prepare(
        "INSERT INTO authorizer_provisioning_jobs (provisioning_id, status, next_attempt_at, claim_holder_id, claim_expires_at, attempt_count, last_error_code, created_at, updated_at) VALUES (?, 'dead', DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE), NULL, NULL, 10, 'quota_insufficient', DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 MINUTE), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE))",
    );
    $job->execute([$provisioningId]);

    $db->prepare('INSERT INTO admin_users (id, username, password_hash, status, expires_at) VALUES (?, ?, ?, ?, NULL)')
        ->execute([$adminId, 'accept-retry-admin', 'acceptance-no-login', 'active']);
    $db->prepare('INSERT INTO tenant_memberships (tenant_id, admin_user_id, role) VALUES (?, ?, ?)')
        ->execute([$tenantId, $adminId, 'owner']);
    $db->prepare('INSERT INTO roles (id, tenant_id, code, name, is_system) VALUES (?, ?, ?, ?, 0)')
        ->execute([$roleId, $tenantId, 'accept-retry-role', 'Acceptance Retry Role']);
    $db->prepare("INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE permission_key = 'openplatform.authorizer.retry_provision'")
        ->execute([$roleId]);
    $db->prepare('INSERT INTO permission_assignments (id, tenant_id, account_id, admin_user_id, role_id) VALUES (?, ?, NULL, ?, ?)')
        ->execute(['accept-retry-assignment', $tenantId, $adminId, $roleId]);
    $db->prepare('INSERT INTO admin_sessions (id, admin_user_id, token_hash, issued_at, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 2 HOUR))')
        ->execute(['accept-retry-session', $adminId, $tokenHash]);

    $ledgerCountBefore = (int) $db->query("SELECT COUNT(*) FROM quota_ledger_entries WHERE tenant_id = '{$tenantId}'")->fetchColumn();

    $runtime->startServer();
    try {
        $path = '/api/v1/openplatform/provisionings/' . $provisioningId . '/retry';
        $headers = [
            'Authorization' => 'Bearer ' . $rawToken,
            'X-Tenant-Id' => $tenantId,
        ];

        $accepted = $runtime->http('POST', $path, $headers);
        acceptanceAssert($accepted['status'] === 202, 'Retryable provisioning must return HTTP 202.');

        $state = $db->prepare('SELECT status, completed_at, last_error_code, last_error_stage, version FROM authorizer_provisionings WHERE id = ?');
        $state->execute([$provisioningId]);
        $row = $state->fetch(PDO::FETCH_ASSOC);
        acceptanceAssert(is_array($row), 'Retried provisioning row must exist.');
        acceptanceAssert($row['status'] === 'metadata_ready', 'quota_blocked retry must resume at METADATA_READY.');
        acceptanceAssert($row['completed_at'] === null, 'Retry must clear completed_at on the resumed provisioning.');
        acceptanceAssert($row['last_error_code'] === null && $row['last_error_stage'] === null, 'Retry must clear prior error state.');
        acceptanceAssert((int) $row['version'] === 4, 'Retry must advance provisioning version exactly once.');

        $scheduled = $db->prepare('SELECT status, attempt_count, claim_holder_id, claim_expires_at, last_error_code FROM authorizer_provisioning_jobs WHERE provisioning_id = ?');
        $scheduled->execute([$provisioningId]);
        $jobRow = $scheduled->fetch(PDO::FETCH_ASSOC);
        acceptanceAssert(is_array($jobRow) && $jobRow['status'] === 'ready', 'Retry must requeue a dead job as READY.');
        acceptanceAssert((int) $jobRow['attempt_count'] === 0, 'Retry must reset automatic attempt count.');
        acceptanceAssert($jobRow['claim_holder_id'] === null && $jobRow['claim_expires_at'] === null, 'Retry must clear stale claim state.');
        acceptanceAssert($jobRow['last_error_code'] === null, 'Retry must clear prior job error state.');
        acceptanceAssert((int) $db->query("SELECT COUNT(*) FROM quota_ledger_entries WHERE tenant_id = '{$tenantId}'")->fetchColumn() === $ledgerCountBefore, 'Retry scheduling must not consume or release quota.');

        $replayed = $runtime->http('POST', $path, $headers);
        acceptanceAssert($replayed['status'] === 202, 'Repeated retry while READY must remain idempotently accepted.');
        $version = $db->prepare('SELECT version FROM authorizer_provisionings WHERE id = ?');
        $version->execute([$provisioningId]);
        acceptanceAssert((int) $version->fetchColumn() === 4, 'Repeated retry of current stage must not advance provisioning version again.');

        $db->prepare("UPDATE authorizer_provisioning_jobs SET status = 'claimed', claim_holder_id = 'busy-worker', claim_expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 60 SECOND), attempt_count = 1 WHERE provisioning_id = ?")
            ->execute([$provisioningId]);
        $busy = $runtime->http('POST', $path, $headers);
        acceptanceAssert($busy['status'] === 409, 'Retry while job has a live claim must return HTTP 409.');
        acceptanceAssert(acceptanceCode($busy) === 'CONFLICT', 'Busy retry must return CONFLICT.');

        $version->execute([$provisioningId]);
        acceptanceAssert((int) $version->fetchColumn() === 4, 'Busy retry must not mutate provisioning version.');
        acceptanceAssert((int) $db->query("SELECT COUNT(*) FROM quota_ledger_entries WHERE tenant_id = '{$tenantId}'")->fetchColumn() === $ledgerCountBefore, 'Busy retry must not touch quota ledger.');
    } finally {
        $runtime->stopServer();
    }
}
