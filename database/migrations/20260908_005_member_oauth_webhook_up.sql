CREATE TABLE `members` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `display_name` varchar(128) DEFAULT NULL,
  `avatar_url` varchar(512) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `idx_members_tenant_status` (`tenant_id`,`status`),
  CONSTRAINT `fk_members_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `external_identities` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `member_id` varchar(64) NOT NULL,
  `provider_type` varchar(64) NOT NULL,
  `provider_account_id` varchar(64) NOT NULL,
  `external_subject` varchar(191) NOT NULL,
  `union_id` varchar(191) DEFAULT NULL,
  `legacy_uniacid` bigint unsigned DEFAULT NULL,
  `legacy_acid` bigint unsigned DEFAULT NULL,
  `legacy_uid` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_external_identities_provider_subject` (`provider_type`,`provider_account_id`,`external_subject`),
  KEY `idx_external_identities_tenant_member` (`tenant_id`,`member_id`),
  KEY `idx_external_identities_union` (`provider_type`,`provider_account_id`,`union_id`),
  CONSTRAINT `fk_external_identities_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_external_identities_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_external_identities_provider_account` FOREIGN KEY (`provider_account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `oauth_bindings` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `business_account_id` varchar(64) NOT NULL,
  `provider_type` varchar(64) NOT NULL,
  `oauth_provider_account_id` varchar(64) NOT NULL,
  `enabled` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oauth_bindings_business_provider` (`tenant_id`,`business_account_id`,`provider_type`),
  KEY `idx_oauth_bindings_provider_account` (`oauth_provider_account_id`),
  CONSTRAINT `fk_oauth_bindings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_bindings_business_account` FOREIGN KEY (`business_account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_bindings_provider_account` FOREIGN KEY (`oauth_provider_account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `oauth_states` (
  `id` varchar(64) NOT NULL,
  `nonce_hash` char(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `business_account_id` varchar(64) NOT NULL,
  `oauth_provider_account_id` varchar(64) NOT NULL,
  `provider_type` varchar(64) NOT NULL,
  `return_url` varchar(2048) NOT NULL,
  `issued_at` datetime(6) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `consumed_at` datetime(6) DEFAULT NULL,
  `result_member_id` varchar(64) DEFAULT NULL,
  `result_external_identity_id` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_oauth_states_nonce_hash` (`nonce_hash`),
  KEY `idx_oauth_states_tenant_business_expiry` (`tenant_id`,`business_account_id`,`expires_at`),
  KEY `idx_oauth_states_result_member` (`result_member_id`),
  CONSTRAINT `fk_oauth_states_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_states_business_account` FOREIGN KEY (`business_account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_states_provider_account` FOREIGN KEY (`oauth_provider_account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_states_result_member` FOREIGN KEY (`result_member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_oauth_states_result_identity` FOREIGN KEY (`result_external_identity_id`) REFERENCES `external_identities` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `webhook_inbox` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `provider_type` varchar(64) NOT NULL,
  `provider_account_id` varchar(64) NOT NULL,
  `provider_event_key` varchar(190) NOT NULL,
  `raw_body_hash` char(64) NOT NULL,
  `received_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `dispatched_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_webhook_inbox_provider_event` (`provider_type`,`provider_account_id`,`provider_event_key`),
  KEY `idx_webhook_inbox_tenant_received` (`tenant_id`,`received_at`),
  CONSTRAINT `fk_webhook_inbox_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_webhook_inbox_provider_account` FOREIGN KEY (`provider_account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
