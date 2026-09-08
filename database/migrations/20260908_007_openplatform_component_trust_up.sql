CREATE TABLE `component_platforms` (
  `id` varchar(64) NOT NULL,
  `component_app_id` varchar(128) NOT NULL,
  `app_secret_ref` varchar(255) NOT NULL,
  `verify_token_ref` varchar(255) NOT NULL,
  `encoding_aes_key_ref` varchar(255) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_component_platform_appid` (`component_app_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `component_ticket_inbox` (
  `id` varchar(64) NOT NULL,
  `component_platform_id` varchar(64) NOT NULL,
  `replay_key` char(64) NOT NULL,
  `payload_hash` char(64) NOT NULL,
  `source_timestamp` datetime(6) NOT NULL,
  `received_at` datetime(6) NOT NULL,
  `result` varchar(32) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_component_ticket_replay` (`component_platform_id`,`replay_key`),
  KEY `idx_component_ticket_received` (`component_platform_id`,`received_at`),
  CONSTRAINT `fk_component_ticket_inbox_platform` FOREIGN KEY (`component_platform_id`) REFERENCES `component_platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `component_verify_tickets` (
  `component_platform_id` varchar(64) NOT NULL,
  `ticket_ciphertext` text NOT NULL,
  `ticket_key_version` varchar(64) NOT NULL,
  `ticket_hash` char(64) NOT NULL,
  `source_timestamp` datetime(6) NOT NULL,
  `received_at` datetime(6) NOT NULL,
  `version` bigint unsigned NOT NULL,
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`component_platform_id`),
  CONSTRAINT `fk_component_verify_ticket_platform` FOREIGN KEY (`component_platform_id`) REFERENCES `component_platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `component_access_tokens` (
  `component_platform_id` varchar(64) NOT NULL,
  `token_ciphertext` text NOT NULL,
  `token_key_version` varchar(64) NOT NULL,
  `issued_at` datetime(6) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `version` bigint unsigned NOT NULL,
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`component_platform_id`),
  KEY `idx_component_access_token_expiry` (`expires_at`),
  CONSTRAINT `fk_component_access_token_platform` FOREIGN KEY (`component_platform_id`) REFERENCES `component_platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `component_token_refresh_leases` (
  `component_platform_id` varchar(64) NOT NULL,
  `holder_id` varchar(128) DEFAULT NULL,
  `lease_expires_at` datetime(6) DEFAULT NULL,
  `version` bigint unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`component_platform_id`),
  CONSTRAINT `fk_component_refresh_lease_platform` FOREIGN KEY (`component_platform_id`) REFERENCES `component_platforms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `miniapp_provider_accounts`
  ADD CONSTRAINT `fk_miniapp_provider_component_platform`
  FOREIGN KEY (`component_platform_id`) REFERENCES `component_platforms` (`id`) ON DELETE RESTRICT;
