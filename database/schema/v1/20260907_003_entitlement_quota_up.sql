CREATE TABLE `tenant_module_entitlements` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `module_id` varchar(64) NOT NULL,
  `source` varchar(32) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `starts_at` datetime(6) DEFAULT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_entitlements_tenant_module_created` (`tenant_id`,`module_id`,`created_at`),
  KEY `idx_entitlements_tenant_status` (`tenant_id`,`status`),
  CONSTRAINT `fk_entitlements_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_entitlements_module` FOREIGN KEY (`module_id`) REFERENCES `module_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quota_parent_pools` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `resource_key` varchar(190) NOT NULL,
  `limit_amount` bigint unsigned NOT NULL,
  `consumed_amount` bigint unsigned NOT NULL DEFAULT 0,
  `version` bigint unsigned NOT NULL DEFAULT 0,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_quota_parent_pools_tenant_resource` (`tenant_id`,`resource_key`),
  CONSTRAINT `fk_quota_parent_pool_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quota_grants` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `resource_key` varchar(190) NOT NULL,
  `source` varchar(32) NOT NULL,
  `amount` bigint unsigned NOT NULL,
  `parent_pool_id` varchar(64) DEFAULT NULL,
  `starts_at` datetime(6) DEFAULT NULL,
  `ends_at` datetime(6) DEFAULT NULL,
  `expired_at` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_quota_grants_tenant_resource` (`tenant_id`,`resource_key`),
  KEY `idx_quota_grants_parent_pool` (`parent_pool_id`),
  CONSTRAINT `fk_quota_grants_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quota_grants_parent_pool` FOREIGN KEY (`parent_pool_id`) REFERENCES `quota_parent_pools` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quota_balances` (
  `tenant_id` varchar(64) NOT NULL,
  `resource_key` varchar(190) NOT NULL,
  `granted_amount` bigint unsigned NOT NULL DEFAULT 0,
  `consumed_amount` bigint unsigned NOT NULL DEFAULT 0,
  `purchase_granted_amount` bigint unsigned NOT NULL DEFAULT 0,
  `purchase_consumed_amount` bigint unsigned NOT NULL DEFAULT 0,
  `version` bigint unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`tenant_id`,`resource_key`),
  UNIQUE KEY `uk_quota_balances_tenant_resource` (`tenant_id`,`resource_key`),
  CONSTRAINT `fk_quota_balances_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quota_ledger_entries` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `resource_key` varchar(190) NOT NULL,
  `grant_id` varchar(64) DEFAULT NULL,
  `grant_source` varchar(32) DEFAULT NULL,
  `entry_type` varchar(32) NOT NULL,
  `amount` bigint unsigned NOT NULL,
  `purchase_charge` bigint unsigned NOT NULL DEFAULT 0,
  `parent_pool_id` varchar(64) DEFAULT NULL,
  `parent_pool_charge` bigint unsigned NOT NULL DEFAULT 0,
  `consume_entry_id` varchar(64) DEFAULT NULL,
  `idempotency_key` varchar(190) NOT NULL,
  `occurred_at` datetime(6) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_quota_ledger_idempotency` (`tenant_id`,`resource_key`,`idempotency_key`),
  KEY `idx_quota_ledger_tenant_resource_time` (`tenant_id`,`resource_key`,`occurred_at`),
  KEY `idx_quota_ledger_grant` (`grant_id`,`entry_type`),
  KEY `idx_quota_ledger_consume` (`consume_entry_id`,`entry_type`),
  CONSTRAINT `fk_quota_ledger_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quota_ledger_grant` FOREIGN KEY (`grant_id`) REFERENCES `quota_grants` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_quota_ledger_parent_pool` FOREIGN KEY (`parent_pool_id`) REFERENCES `quota_parent_pools` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
