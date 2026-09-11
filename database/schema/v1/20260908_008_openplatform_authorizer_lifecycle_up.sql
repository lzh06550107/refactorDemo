CREATE TABLE `openplatform_authorization_intents` (
  `id` varchar(64) NOT NULL,
  `component_platform_id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `target_account_id` varchar(64) NOT NULL,
  `state_hash` char(64) NOT NULL,
  `pre_auth_code_hash` char(64) NOT NULL,
  `provider_pre_auth_expires_at` datetime(6) NOT NULL,
  `requested_auth_type` varchar(32) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `claim_holder_id` varchar(128) DEFAULT NULL,
  `claim_expires_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  `completed_authorizer_app_id` varchar(128) DEFAULT NULL,
  `version` bigint unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_openplatform_intent_state_hash` (`state_hash`),
  UNIQUE KEY `uk_openplatform_intent_pre_auth` (`component_platform_id`,`pre_auth_code_hash`),
  KEY `idx_openplatform_intent_target` (`tenant_id`,`target_account_id`),
  KEY `idx_openplatform_intent_expiry` (`expires_at`),
  CONSTRAINT `fk_openplatform_intent_platform` FOREIGN KEY (`component_platform_id`) REFERENCES `component_platforms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_openplatform_intent_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_openplatform_intent_account` FOREIGN KEY (`target_account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `authorizer_authorizations` (
  `component_platform_id` varchar(64) NOT NULL,
  `authorizer_app_id` varchar(128) NOT NULL,
  `status` varchar(32) NOT NULL,
  `refresh_token_ciphertext` text DEFAULT NULL,
  `refresh_token_key_version` varchar(64) DEFAULT NULL,
  `refresh_token_hash` char(64) DEFAULT NULL,
  `scope_json` text NOT NULL,
  `provider_updated_at` datetime(6) NOT NULL,
  `first_authorized_at` datetime(6) NOT NULL,
  `last_authorized_at` datetime(6) NOT NULL,
  `unauthorized_at` datetime(6) DEFAULT NULL,
  `version` bigint unsigned NOT NULL,
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`component_platform_id`,`authorizer_app_id`),
  KEY `idx_authorizer_authorization_status` (`status`),
  CONSTRAINT `fk_authorizer_authorization_platform` FOREIGN KEY (`component_platform_id`) REFERENCES `component_platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `authorizer_access_tokens` (
  `component_platform_id` varchar(64) NOT NULL,
  `authorizer_app_id` varchar(128) NOT NULL,
  `token_ciphertext` text NOT NULL,
  `token_key_version` varchar(64) NOT NULL,
  `issued_at` datetime(6) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `version` bigint unsigned NOT NULL,
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`component_platform_id`,`authorizer_app_id`),
  KEY `idx_authorizer_access_token_expiry` (`expires_at`),
  CONSTRAINT `fk_authorizer_access_token_authorization` FOREIGN KEY (`component_platform_id`,`authorizer_app_id`) REFERENCES `authorizer_authorizations` (`component_platform_id`,`authorizer_app_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `authorizer_token_refresh_leases` (
  `component_platform_id` varchar(64) NOT NULL,
  `authorizer_app_id` varchar(128) NOT NULL,
  `holder_id` varchar(128) DEFAULT NULL,
  `lease_expires_at` datetime(6) DEFAULT NULL,
  `version` bigint unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`component_platform_id`,`authorizer_app_id`),
  CONSTRAINT `fk_authorizer_refresh_lease_authorization` FOREIGN KEY (`component_platform_id`,`authorizer_app_id`) REFERENCES `authorizer_authorizations` (`component_platform_id`,`authorizer_app_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
