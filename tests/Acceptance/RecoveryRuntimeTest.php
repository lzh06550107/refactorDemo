<?php

declare(strict_types=1);

use app\account\domain\AccountType;
use app\quota\application\QuotaService;
use app\quota\domain\QuotaGrant;
use app\quota\domain\QuotaGrantSource;
use app\quota\domain\QuotaResource;
use think\App;

function acceptanceRecoveryRuntimeTest(AcceptanceRuntime $runtime): void
{
    acceptanceTerminalProvisionedRecovery($runtime);
    acceptanceSameOwnerReconnectRecovery($runtime);
}

function acceptanceTerminalProvisionedRecovery(AcceptanceRuntime $runtime): void
{
    $db = $runtime->db();
    $tenantId = 'accept-recovery-tenant';
    $platformId = 'accept-recovery-platform';
    $intentId = 'accept-recovery-intent';
    $authorizerAppId = 'wx-accept-recovery-authorizer';
    $accountId = 'accept-recovery-account';
    $provisioningId = 'accept-recovery-provisioning';

    $db->exec("INSERT INTO tenants (id, name, status) VALUES ('{$tenantId}', 'Recovery Acceptance Tenant', 'active')");
    $db->exec("INSERT INTO component_platforms (id, component_app_id, app_secret_ref, verify_token_ref, encoding_aes_key_ref, enabled) VALUES ('{$platformId}', 'wx-accept-recovery-component', 'acceptance-secret-ref', 'acceptance-secret-ref', 'acceptance-secret-ref', 1)");

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
        'INSERT INTO authorizer_authorizations (component_platform_id, authorizer_app_id, status, refresh_token_ciphertext, refresh_token_key_version, refresh_token_hash, scope_json, provider_updated_at, first_authorized_at, last_authorized_at, unauthorized_at, version) VALUES (?, ?, ?, NULL, NULL, NULL, ?, UTC_TIMESTAMP(6), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY), UTC_TIMESTAMP(6), 2)',
    );
    $authorization->execute([$platformId, $authorizerAppId, 'unauthorized', '[]']);

    $db->prepare('INSERT INTO accounts (id, tenant_id, name, type, status) VALUES (?, ?, ?, ?, ?)')
        ->execute([$accountId, $tenantId, 'Recovered Mini Program', 'wechat_mini_program', 'active']);
    $db->prepare('INSERT INTO authorizer_account_ownerships (component_platform_id, authorizer_app_id, tenant_id, account_id, account_type, first_bound_at, last_connected_at) VALUES (?, ?, ?, ?, ?, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY))')
        ->execute([$platformId, $authorizerAppId, $tenantId, $accountId, 'wechat_mini_program']);
    $db->prepare('INSERT INTO miniapp_provider_accounts (account_id, tenant_id, provider_app_id, connection_mode, credential_ref, component_platform_id, enabled) VALUES (?, ?, ?, ?, NULL, ?, 1)')
        ->execute([$accountId, $tenantId, $authorizerAppId, 'component', $platformId]);

    require_once $runtime->config->root . '/vendor/autoload.php';
    $app = (new App($runtime->config->root))->initialize();
    $quota = $app->make(QuotaService::class);
    acceptanceAssert($quota instanceof QuotaService, 'Recovery acceptance must resolve production QuotaService.');
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $resource = QuotaResource::accountCreate(AccountType::WECHAT_MINI_PROGRAM);
    $grant = new QuotaGrant(
        'accept-recovery-quota-grant',
        $tenantId,
        $resource,
        QuotaGrantSource::PURCHASE,
        1,
    );
    $quota->grant($grant, 'acceptance:recovery:grant', $now);
    $consume = $quota->consume($tenantId, $resource, 1, 'acceptance:recovery:consume', $now);

    $provisioning = $db->prepare(
        'INSERT INTO authorizer_provisionings (id, source_intent_id, tenant_id, component_platform_id, authorizer_app_id, account_type, status, metadata_version, quota_resource_key, quota_consume_entry_id, quota_release_entry_id, account_id, last_error_code, last_error_stage, created_at, updated_at, completed_at, version) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NULL, ?, NULL, NULL, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 MINUTE), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE), 4)',
    );
    $provisioning->execute([
        $provisioningId,
        $intentId,
        $tenantId,
        $platformId,
        $authorizerAppId,
        'wechat_mini_program',
        'provisioned',
        $resource->key(),
        $consume->id(),
        $accountId,
    ]);
    $db->prepare(
        "INSERT INTO authorizer_provisioning_jobs (provisioning_id, status, next_attempt_at, claim_holder_id, claim_expires_at, attempt_count, last_error_code, created_at, updated_at) VALUES (?, 'claimed', DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 MINUTE), 'crashed-worker', DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE), 1, NULL, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 MINUTE), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE))",
    )->execute([$provisioningId]);

    $accountCountBefore = (int) $db->query("SELECT COUNT(*) FROM accounts WHERE tenant_id = '{$tenantId}'")->fetchColumn();
    $ledgerCountBefore = (int) $db->query("SELECT COUNT(*) FROM quota_ledger_entries WHERE tenant_id = '{$tenantId}'")->fetchColumn();

    acceptanceRunSingleWorkerBatch($runtime, 'terminal crash recovery');

    $state = $db->prepare('SELECT status, account_id FROM authorizer_provisionings WHERE id = ?');
    $state->execute([$provisioningId]);
    $row = $state->fetch(PDO::FETCH_ASSOC);
    acceptanceAssert(is_array($row) && $row['status'] === 'provisioned', 'Recovered terminal provisioning must remain PROVISIONED.');
    acceptanceAssert((string) $row['account_id'] === $accountId, 'Recovered terminal provisioning must retain the original Account.');

    $job = $db->prepare('SELECT status FROM authorizer_provisioning_jobs WHERE provisioning_id = ?');
    $job->execute([$provisioningId]);
    acceptanceAssert($job->fetchColumn() === 'completed', 'Recovered expired claim must complete the durable job.');
    acceptanceAssert((int) $db->query("SELECT COUNT(*) FROM accounts WHERE tenant_id = '{$tenantId}'")->fetchColumn() === $accountCountBefore, 'Terminal recovery must not create a second Account.');
    acceptanceAssert((int) $db->query("SELECT COUNT(*) FROM quota_ledger_entries WHERE tenant_id = '{$tenantId}'")->fetchColumn() === $ledgerCountBefore, 'Terminal recovery must not consume or release quota again.');

    $auth = $db->prepare('SELECT status FROM authorizer_authorizations WHERE component_platform_id = ? AND authorizer_app_id = ?');
    $auth->execute([$platformId, $authorizerAppId]);
    acceptanceAssert($auth->fetchColumn() === 'unauthorized', 'Terminal recovery must complete before inactive authorization can regress the durable outcome.');
}

function acceptanceSameOwnerReconnectRecovery(AcceptanceRuntime $runtime): void
{
    $db = $runtime->db();
    $tenantId = 'accept-reconnect-tenant';
    $platformId = 'accept-reconnect-platform';
    $intentId = 'accept-reconnect-intent';
    $authorizerAppId = 'wx-accept-reconnect-authorizer';
    $accountId = 'accept-reconnect-account';
    $provisioningId = 'accept-reconnect-provisioning';

    $db->exec("INSERT INTO tenants (id, name, status) VALUES ('{$tenantId}', 'Reconnect Acceptance Tenant', 'active')");
    $db->exec("INSERT INTO component_platforms (id, component_app_id, app_secret_ref, verify_token_ref, encoding_aes_key_ref, enabled) VALUES ('{$platformId}', 'wx-accept-reconnect-component', 'acceptance-secret-ref', 'acceptance-secret-ref', 'acceptance-secret-ref', 1)");

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
        'INSERT INTO authorizer_authorizations (component_platform_id, authorizer_app_id, status, refresh_token_ciphertext, refresh_token_key_version, refresh_token_hash, scope_json, provider_updated_at, first_authorized_at, last_authorized_at, unauthorized_at, version) VALUES (?, ?, ?, NULL, NULL, ?, ?, UTC_TIMESTAMP(6), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY), UTC_TIMESTAMP(6), NULL, 1)',
    );
    $authorization->execute([$platformId, $authorizerAppId, 'active', hash('sha256', 'acceptance-reconnect-refresh-token'), '[]']);

    $db->prepare('INSERT INTO accounts (id, tenant_id, name, type, status) VALUES (?, ?, ?, ?, ?)')
        ->execute([$accountId, $tenantId, 'Existing Mini Program', 'wechat_mini_program', 'active']);
    $db->prepare('INSERT INTO authorizer_account_ownerships (component_platform_id, authorizer_app_id, tenant_id, account_id, account_type, first_bound_at, last_connected_at) VALUES (?, ?, ?, ?, ?, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 2 DAY), DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 DAY))')
        ->execute([$platformId, $authorizerAppId, $tenantId, $accountId, 'wechat_mini_program']);
    $db->prepare('INSERT INTO miniapp_provider_accounts (account_id, tenant_id, provider_app_id, connection_mode, credential_ref, component_platform_id, enabled) VALUES (?, ?, ?, ?, NULL, ?, 0)')
        ->execute([$accountId, $tenantId, $authorizerAppId, 'component', $platformId]);

    $provisioning = $db->prepare(
        'INSERT INTO authorizer_provisionings (id, source_intent_id, tenant_id, component_platform_id, authorizer_app_id, account_type, status, metadata_version, quota_resource_key, quota_consume_entry_id, quota_release_entry_id, account_id, last_error_code, last_error_stage, created_at, updated_at, completed_at, version) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NULL, NULL, NULL, NULL, NULL, NULL, DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE), UTC_TIMESTAMP(6), NULL, 2)',
    );
    $provisioning->execute([
        $provisioningId,
        $intentId,
        $tenantId,
        $platformId,
        $authorizerAppId,
        'wechat_mini_program',
        'metadata_ready',
    ]);
    $db->prepare(
        "INSERT INTO authorizer_provisioning_jobs (provisioning_id, status, next_attempt_at, claim_holder_id, claim_expires_at, attempt_count, last_error_code, created_at, updated_at) VALUES (?, 'ready', DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND), NULL, NULL, 0, NULL, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
    )->execute([$provisioningId]);

    $accountCountBefore = (int) $db->query("SELECT COUNT(*) FROM accounts WHERE tenant_id = '{$tenantId}'")->fetchColumn();
    $ledgerCountBefore = (int) $db->query("SELECT COUNT(*) FROM quota_ledger_entries WHERE tenant_id = '{$tenantId}'")->fetchColumn();

    acceptanceRunSingleWorkerBatch($runtime, 'same-owner reconnect');

    $state = $db->prepare('SELECT status, account_id, completed_at FROM authorizer_provisionings WHERE id = ?');
    $state->execute([$provisioningId]);
    $row = $state->fetch(PDO::FETCH_ASSOC);
    acceptanceAssert(is_array($row) && $row['status'] === 'reconnected', 'Same-owner recovery must finish as RECONNECTED.');
    acceptanceAssert((string) $row['account_id'] === $accountId, 'Reconnect must retain the existing Account id.');
    acceptanceAssert(!empty($row['completed_at']), 'Reconnect must mark provisioning completed.');

    $enabled = $db->prepare('SELECT enabled FROM miniapp_provider_accounts WHERE account_id = ?');
    $enabled->execute([$accountId]);
    acceptanceAssert((int) $enabled->fetchColumn() === 1, 'Reconnect must re-enable the existing provider binding.');

    $job = $db->prepare('SELECT status FROM authorizer_provisioning_jobs WHERE provisioning_id = ?');
    $job->execute([$provisioningId]);
    acceptanceAssert($job->fetchColumn() === 'completed', 'Reconnect recovery must complete its job.');
    acceptanceAssert((int) $db->query("SELECT COUNT(*) FROM accounts WHERE tenant_id = '{$tenantId}'")->fetchColumn() === $accountCountBefore, 'Reconnect must not create a second Account.');
    acceptanceAssert((int) $db->query("SELECT COUNT(*) FROM quota_ledger_entries WHERE tenant_id = '{$tenantId}'")->fetchColumn() === $ledgerCountBefore, 'Reconnect must not consume quota.');
}

function acceptanceRunSingleWorkerBatch(AcceptanceRuntime $runtime, string $scenario): void
{
    $command = [
        PHP_BINARY,
        $runtime->config->root . '/think',
        'openplatform:provisioning-worker',
        '--once',
        '--limit=10',
    ];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = proc_open($command, $descriptors, $pipes, $runtime->config->root, $runtime->config->childEnvironment());
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start provisioning worker for ' . $scenario . '.');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    acceptanceAssert($exitCode === 0, 'Provisioning worker must exit successfully for ' . $scenario . '.');
    acceptanceAssert(trim($stderr) === '', 'Provisioning worker must not emit stderr for ' . $scenario . '.');
    acceptanceAssert(
        str_contains($stdout, 'provisioning batch discovered=1 handled=1 failed=0'),
        'Provisioning worker must handle exactly one due job for ' . $scenario . '.',
    );
}
