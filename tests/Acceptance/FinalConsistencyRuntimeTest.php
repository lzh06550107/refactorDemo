<?php

declare(strict_types=1);

function acceptanceFinalConsistencyRuntimeTest(AcceptanceRuntime $runtime): void
{
    $db = $runtime->db();

    acceptanceAssert(
        acceptanceConsistencyCount($db, <<<'SQL'
SELECT COUNT(*)
FROM authorizer_provisionings p
LEFT JOIN authorizer_provisioning_jobs j
  ON j.provisioning_id = p.id
LEFT JOIN authorizer_account_ownerships o
  ON o.component_platform_id = p.component_platform_id
 AND o.authorizer_app_id = p.authorizer_app_id
LEFT JOIN accounts a
  ON a.id = p.account_id
WHERE p.status IN ('provisioned', 'reconnected')
  AND (
       p.account_id IS NULL
    OR p.completed_at IS NULL
    OR p.last_error_code IS NOT NULL
    OR p.last_error_stage IS NOT NULL
    OR p.quota_release_entry_id IS NOT NULL
    OR j.provisioning_id IS NULL
    OR j.status <> 'completed'
    OR o.component_platform_id IS NULL
    OR o.tenant_id <> p.tenant_id
    OR o.account_id <> p.account_id
    OR o.account_type <> p.account_type
    OR a.id IS NULL
    OR a.tenant_id <> p.tenant_id
    OR a.type <> p.account_type
    OR a.status = 'deleted'
  )
SQL
        ) === 0,
        'Terminal provisioning, job, Account and canonical ownership must remain mutually consistent.',
    );

    acceptanceAssert(
        acceptanceConsistencyCount($db, <<<'SQL'
SELECT COUNT(*)
FROM authorizer_provisionings p
LEFT JOIN miniapp_provider_accounts m
  ON m.account_id = p.account_id
LEFT JOIN official_account_provider_accounts o
  ON o.account_id = p.account_id
WHERE p.status IN ('provisioned', 'reconnected')
  AND (
       (p.account_type = 'wechat_mini_program' AND (
            m.account_id IS NULL
         OR m.tenant_id <> p.tenant_id
         OR m.provider_app_id <> p.authorizer_app_id
         OR m.component_platform_id <> p.component_platform_id
         OR m.connection_mode <> 'component'
         OR m.enabled <> 1
       ))
    OR (p.account_type = 'official_account' AND (
            o.account_id IS NULL
         OR o.tenant_id <> p.tenant_id
         OR o.provider_app_id <> p.authorizer_app_id
         OR o.component_platform_id <> p.component_platform_id
         OR o.connection_mode <> 'component'
         OR o.enabled <> 1
       ))
    OR p.account_type NOT IN ('wechat_mini_program', 'official_account')
  )
SQL
        ) === 0,
        'Successful OpenPlatform provisioning must retain one enabled canonical provider binding.',
    );

    acceptanceAssert(
        acceptanceConsistencyCount($db, <<<'SQL'
SELECT COUNT(*)
FROM authorizer_provisionings p
LEFT JOIN quota_ledger_entries c
  ON c.id = p.quota_consume_entry_id
WHERE p.quota_consume_entry_id IS NOT NULL
  AND (
       c.id IS NULL
    OR c.entry_type <> 'consume'
    OR c.tenant_id <> p.tenant_id
    OR p.quota_resource_key IS NULL
    OR c.resource_key <> p.quota_resource_key
  )
SQL
        ) === 0,
        'Provisioning quota-consume references must resolve to the matching Tenant/resource consume ledger entry.',
    );

    acceptanceAssert(
        acceptanceConsistencyCount($db, <<<'SQL'
SELECT COUNT(*)
FROM authorizer_provisionings p
LEFT JOIN quota_ledger_entries r
  ON r.id = p.quota_release_entry_id
WHERE p.quota_release_entry_id IS NOT NULL
  AND (
       p.quota_consume_entry_id IS NULL
    OR r.id IS NULL
    OR r.entry_type <> 'release'
    OR r.tenant_id <> p.tenant_id
    OR p.quota_resource_key IS NULL
    OR r.resource_key <> p.quota_resource_key
    OR r.consume_entry_id <> p.quota_consume_entry_id
  )
SQL
        ) === 0,
        'Provisioning quota-release references must compensate the exact matching consume ledger entry.',
    );

    acceptanceAssert(
        acceptanceConsistencyCount($db, <<<'SQL'
SELECT COUNT(*)
FROM authorizer_provisioning_jobs
WHERE (
       status = 'claimed'
   AND (claim_holder_id IS NULL OR claim_expires_at IS NULL)
)
OR (
       status IN ('ready', 'completed', 'dead')
   AND (claim_holder_id IS NOT NULL OR claim_expires_at IS NOT NULL)
)
SQL
        ) === 0,
        'Provisioning job claim fields must agree with durable job status.',
    );

    acceptanceAssert(
        acceptanceConsistencyCount($db, <<<'SQL'
SELECT COUNT(*)
FROM (
    SELECT
        tenant_id,
        resource_key,
        SUM(CASE
            WHEN entry_type = 'grant' THEN amount
            WHEN entry_type = 'expire' THEN -amount
            ELSE 0
        END) AS expected_granted,
        SUM(CASE
            WHEN entry_type = 'consume' THEN amount
            WHEN entry_type = 'release' THEN -amount
            ELSE 0
        END) AS expected_consumed,
        SUM(CASE
            WHEN entry_type = 'grant' AND grant_source = 'purchase' THEN amount
            WHEN entry_type = 'expire' AND grant_source = 'purchase' THEN -amount
            ELSE 0
        END) AS expected_purchase_granted,
        SUM(CASE
            WHEN entry_type = 'consume' THEN purchase_charge
            WHEN entry_type = 'release' THEN -purchase_charge
            ELSE 0
        END) AS expected_purchase_consumed
    FROM quota_ledger_entries
    GROUP BY tenant_id, resource_key
) ledger
LEFT JOIN quota_balances b
  ON b.tenant_id = ledger.tenant_id
 AND b.resource_key = ledger.resource_key
WHERE b.tenant_id IS NULL
   OR b.granted_amount <> ledger.expected_granted
   OR b.consumed_amount <> ledger.expected_consumed
   OR b.purchase_granted_amount <> ledger.expected_purchase_granted
   OR b.purchase_consumed_amount <> ledger.expected_purchase_consumed
SQL
        ) === 0,
        'Quota balance projection must equal the net durable quota ledger.',
    );

    acceptanceAssert(
        acceptanceConsistencyCount($db, <<<'SQL'
SELECT COUNT(*)
FROM quota_balances b
LEFT JOIN quota_ledger_entries l
  ON l.tenant_id = b.tenant_id
 AND l.resource_key = b.resource_key
WHERE l.id IS NULL
  AND (
       b.granted_amount <> 0
    OR b.consumed_amount <> 0
    OR b.purchase_granted_amount <> 0
    OR b.purchase_consumed_amount <> 0
  )
SQL
        ) === 0,
        'Non-zero quota balance projection must have durable ledger evidence.',
    );
}

function acceptanceConsistencyCount(PDO $db, string $sql): int
{
    $value = $db->query($sql)->fetchColumn();
    return (int) $value;
}
