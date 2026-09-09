ALTER TABLE `openplatform_authorization_intents`
  ADD COLUMN `intent_mode` varchar(32) DEFAULT NULL AFTER `tenant_id`,
  MODIFY COLUMN `target_account_id` varchar(64) DEFAULT NULL;

UPDATE `openplatform_authorization_intents`
   SET `intent_mode` = 'bind_existing_account'
 WHERE `intent_mode` IS NULL;

ALTER TABLE `openplatform_authorization_intents`
  MODIFY COLUMN `intent_mode` varchar(32) NOT NULL,
  ADD CONSTRAINT `chk_openplatform_intent_mode_target` CHECK (
    (`intent_mode` = 'bind_existing_account' AND `target_account_id` IS NOT NULL)
    OR
    (`intent_mode` = 'auto_provision_account' AND `target_account_id` IS NULL)
  );

CREATE TABLE `authorizer_metadata_current` (
  `component_platform_id` varchar(64) NOT NULL,
  `authorizer_app_id` varchar(128) NOT NULL,
  `account_type` varchar(64) NOT NULL,
  `nickname` varchar(190) DEFAULT NULL,
  `original_id` varchar(128) DEFAULT NULL,
  `principal_name` varchar(255) DEFAULT NULL,
  `alias` varchar(128) DEFAULT NULL,
  `head_image_url` varchar(1024) DEFAULT NULL,
  `qrcode_url` varchar(1024) DEFAULT NULL,
  `service_type` int DEFAULT NULL,
  `verify_type` int DEFAULT NULL,
  `business_info_json` json DEFAULT NULL,
  `mini_program_info_json` json DEFAULT NULL,
  `normalized_metadata_json` json NOT NULL,
  `metadata_hash` char(64) NOT NULL,
  `provider_fetched_at` datetime(6) NOT NULL,
  `version` bigint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`component_platform_id`,`authorizer_app_id`),
  KEY `idx_authorizer_metadata_current_type` (`account_type`),
  KEY `idx_authorizer_metadata_current_fetched` (`provider_fetched_at`),
  CONSTRAINT `fk_authorizer_metadata_current_authorization`
    FOREIGN KEY (`component_platform_id`,`authorizer_app_id`)
    REFERENCES `authorizer_authorizations` (`component_platform_id`,`authorizer_app_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `authorizer_metadata_snapshots` (
  `id` varchar(64) NOT NULL,
  `component_platform_id` varchar(64) NOT NULL,
  `authorizer_app_id` varchar(128) NOT NULL,
  `account_type` varchar(64) NOT NULL,
  `metadata_hash` char(64) NOT NULL,
  `normalized_metadata_json` json NOT NULL,
  `observed_at` datetime(6) NOT NULL,
  `source` varchar(64) NOT NULL,
  `version` bigint unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_authorizer_metadata_snapshot_version` (`component_platform_id`,`authorizer_app_id`,`version`),
  KEY `idx_authorizer_metadata_snapshot_hash` (`component_platform_id`,`authorizer_app_id`,`metadata_hash`),
  KEY `idx_authorizer_metadata_snapshot_observed` (`observed_at`),
  CONSTRAINT `fk_authorizer_metadata_snapshot_authorization`
    FOREIGN KEY (`component_platform_id`,`authorizer_app_id`)
    REFERENCES `authorizer_authorizations` (`component_platform_id`,`authorizer_app_id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `authorizer_account_ownerships` (
  `component_platform_id` varchar(64) NOT NULL,
  `authorizer_app_id` varchar(128) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `account_id` varchar(64) NOT NULL,
  `account_type` varchar(64) NOT NULL,
  `first_bound_at` datetime(6) NOT NULL,
  `last_connected_at` datetime(6) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`component_platform_id`,`authorizer_app_id`),
  UNIQUE KEY `uk_authorizer_ownership_account` (`account_id`),
  KEY `idx_authorizer_ownership_tenant_account` (`tenant_id`,`account_id`),
  CONSTRAINT `fk_authorizer_ownership_authorization`
    FOREIGN KEY (`component_platform_id`,`authorizer_app_id`)
    REFERENCES `authorizer_authorizations` (`component_platform_id`,`authorizer_app_id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_authorizer_ownership_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_authorizer_ownership_account`
    FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `official_account_provider_accounts` (
  `account_id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `provider_app_id` varchar(128) NOT NULL,
  `connection_mode` varchar(32) NOT NULL,
  `credential_ref` varchar(255) DEFAULT NULL,
  `component_platform_id` varchar(64) DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `uk_official_account_provider_tenant_appid` (`tenant_id`,`provider_app_id`),
  KEY `idx_official_account_provider_tenant` (`tenant_id`),
  KEY `idx_official_account_provider_component` (`component_platform_id`),
  CONSTRAINT `chk_official_account_connection_mode` CHECK (
    (`connection_mode` = 'manual' AND `credential_ref` IS NOT NULL AND `component_platform_id` IS NULL)
    OR
    (`connection_mode` = 'component' AND `credential_ref` IS NULL AND `component_platform_id` IS NOT NULL)
  ),
  CONSTRAINT `fk_official_account_provider_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_official_account_provider_account`
    FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_official_account_provider_component`
    FOREIGN KEY (`component_platform_id`) REFERENCES `component_platforms` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `authorizer_provisionings` (
  `id` varchar(64) NOT NULL,
  `source_intent_id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `component_platform_id` varchar(64) NOT NULL,
  `authorizer_app_id` varchar(128) NOT NULL,
  `account_type` varchar(64) DEFAULT NULL,
  `status` varchar(64) NOT NULL,
  `metadata_version` bigint unsigned DEFAULT NULL,
  `quota_resource_key` varchar(190) DEFAULT NULL,
  `quota_consume_entry_id` varchar(64) DEFAULT NULL,
  `quota_release_entry_id` varchar(64) DEFAULT NULL,
  `account_id` varchar(64) DEFAULT NULL,
  `last_error_code` varchar(64) DEFAULT NULL,
  `last_error_stage` varchar(64) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  `completed_at` datetime(6) DEFAULT NULL,
  `version` bigint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_authorizer_provisioning_source_intent` (`source_intent_id`),
  KEY `idx_authorizer_provisioning_canonical_tenant` (`component_platform_id`,`authorizer_app_id`,`tenant_id`),
  KEY `idx_authorizer_provisioning_tenant_status` (`tenant_id`,`status`),
  KEY `idx_authorizer_provisioning_account` (`account_id`),
  KEY `idx_authorizer_provisioning_quota_consume` (`quota_consume_entry_id`),
  KEY `idx_authorizer_provisioning_quota_release` (`quota_release_entry_id`),
  CONSTRAINT `fk_authorizer_provisioning_intent`
    FOREIGN KEY (`source_intent_id`) REFERENCES `openplatform_authorization_intents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_authorizer_provisioning_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_authorizer_provisioning_component`
    FOREIGN KEY (`component_platform_id`) REFERENCES `component_platforms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_authorizer_provisioning_account`
    FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_authorizer_provisioning_quota_consume`
    FOREIGN KEY (`quota_consume_entry_id`) REFERENCES `quota_ledger_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_authorizer_provisioning_quota_release`
    FOREIGN KEY (`quota_release_entry_id`) REFERENCES `quota_ledger_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `authorizer_provisioning_jobs` (
  `provisioning_id` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL,
  `next_attempt_at` datetime(6) NOT NULL,
  `claim_holder_id` varchar(128) DEFAULT NULL,
  `claim_expires_at` datetime(6) DEFAULT NULL,
  `attempt_count` int unsigned NOT NULL DEFAULT 0,
  `last_error_code` varchar(64) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`provisioning_id`),
  KEY `idx_authorizer_provisioning_job_ready` (`status`,`next_attempt_at`),
  KEY `idx_authorizer_provisioning_job_claim_expiry` (`claim_expires_at`),
  CONSTRAINT `fk_authorizer_provisioning_job_provisioning`
    FOREIGN KEY (`provisioning_id`) REFERENCES `authorizer_provisionings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`id`, `permission_key`, `description`) VALUES
  ('permission-openplatform-authorizer-read', 'openplatform.authorizer.read', 'Read OpenPlatform authorizer and provisioning state'),
  ('permission-openplatform-authorizer-start', 'openplatform.authorizer.start', 'Start OpenPlatform authorizer authorization'),
  ('permission-openplatform-authorizer-bind', 'openplatform.authorizer.bind', 'Bind an OpenPlatform authorizer to an existing Account'),
  ('permission-openplatform-authorizer-provision', 'openplatform.authorizer.provision', 'Automatically provision an Account from an OpenPlatform authorizer'),
  ('permission-openplatform-authorizer-refresh', 'openplatform.authorizer.refresh_metadata', 'Refresh trusted OpenPlatform authorizer metadata'),
  ('permission-openplatform-authorizer-retry', 'openplatform.authorizer.retry_provision', 'Retry an OpenPlatform authorizer provisioning workflow');
