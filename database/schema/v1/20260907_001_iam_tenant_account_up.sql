CREATE TABLE `admin_users` (
  `id` varchar(64) NOT NULL,
  `username` varchar(64) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `expires_at` datetime(6) DEFAULT NULL,
  `session_version` int unsigned NOT NULL DEFAULT 0,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenants` (
  `id` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_tenants_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `admin_sessions` (
  `id` varchar(64) NOT NULL,
  `admin_user_id` varchar(64) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `issued_at` datetime(6) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `last_seen_at` datetime(6) DEFAULT NULL,
  `client_ip` varchar(45) DEFAULT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_sessions_token_hash` (`token_hash`),
  KEY `idx_admin_sessions_user_expiry` (`admin_user_id`,`expires_at`),
  CONSTRAINT `fk_admin_sessions_user` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenant_memberships` (
  `tenant_id` varchar(64) NOT NULL,
  `admin_user_id` varchar(64) NOT NULL,
  `role` varchar(32) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`tenant_id`,`admin_user_id`),
  KEY `idx_tenant_memberships_tenant` (`tenant_id`),
  KEY `idx_tenant_memberships_user` (`admin_user_id`),
  CONSTRAINT `fk_tenant_memberships_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tenant_memberships_user` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `accounts` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `type` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_accounts_tenant` (`tenant_id`),
  KEY `idx_accounts_tenant_status` (`tenant_id`,`status`),
  CONSTRAINT `fk_accounts_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_capabilities` (
  `tenant_id` varchar(64) NOT NULL,
  `account_id` varchar(64) NOT NULL,
  `capability` varchar(64) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`account_id`,`capability`),
  KEY `idx_account_capabilities_tenant` (`tenant_id`),
  KEY `idx_account_capabilities_tenant_account` (`tenant_id`,`account_id`),
  CONSTRAINT `fk_account_capabilities_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_account_capabilities_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `legacy_mappings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` varchar(64) NOT NULL,
  `entity_type` varchar(32) NOT NULL,
  `legacy_key` varchar(32) NOT NULL,
  `legacy_value` varchar(128) NOT NULL,
  `target_id` varchar(64) NOT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_legacy_identity` (`entity_type`,`legacy_key`,`legacy_value`),
  KEY `idx_legacy_mappings_tenant` (`tenant_id`),
  KEY `idx_legacy_mappings_target` (`entity_type`,`target_id`),
  CONSTRAINT `fk_legacy_mappings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
