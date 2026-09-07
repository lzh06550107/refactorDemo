CREATE TABLE `sites` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `account_id` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'enabled',
  `is_default` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `default_account_id` varchar(64) GENERATED ALWAYS AS (CASE WHEN `is_default` = 1 THEN `account_id` ELSE NULL END) STORED,
  `active_theme_release_id` varchar(64) DEFAULT NULL,
  `legacy_multi_id` bigint unsigned DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sites_one_default_per_account` (`default_account_id`),
  UNIQUE KEY `uk_sites_account_legacy_multi` (`account_id`,`legacy_multi_id`),
  KEY `idx_sites_tenant_account` (`tenant_id`,`account_id`),
  KEY `idx_sites_tenant_status` (`tenant_id`,`status`),
  CONSTRAINT `fk_sites_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sites_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `domain_bindings` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `account_id` varchar(64) NOT NULL,
  `site_id` varchar(64) NOT NULL,
  `host` varchar(253) NOT NULL,
  `source` varchar(64) NOT NULL,
  `legacy_multi_id` bigint unsigned DEFAULT NULL,
  `default_module` varchar(128) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_domain_bindings_host` (`host`),
  KEY `idx_domain_bindings_tenant_account` (`tenant_id`,`account_id`),
  KEY `idx_domain_bindings_site` (`site_id`),
  CONSTRAINT `fk_domain_bindings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_domain_bindings_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_domain_bindings_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `themes` (
  `id` varchar(64) NOT NULL,
  `theme_key` varchar(128) NOT NULL,
  `title` varchar(128) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'enabled',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_themes_key` (`theme_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `theme_versions` (
  `id` varchar(64) NOT NULL,
  `theme_id` varchar(64) NOT NULL,
  `version` varchar(64) NOT NULL,
  `template_root` varchar(255) NOT NULL,
  `manifest_hash` char(64) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_theme_versions_theme_version` (`theme_id`,`version`),
  CONSTRAINT `fk_theme_versions_theme` FOREIGN KEY (`theme_id`) REFERENCES `themes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `style_instances` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `theme_version_id` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `revision` bigint unsigned NOT NULL DEFAULT 1,
  `values_json` json NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_style_instances_tenant_theme_name` (`tenant_id`,`theme_version_id`,`name`),
  KEY `idx_style_instances_tenant` (`tenant_id`),
  CONSTRAINT `fk_style_instances_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_style_instances_theme_version` FOREIGN KEY (`theme_version_id`) REFERENCES `theme_versions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `style_snapshots` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `style_instance_id` varchar(64) NOT NULL,
  `theme_version_id` varchar(64) NOT NULL,
  `revision` bigint unsigned NOT NULL,
  `values_json` json NOT NULL,
  `content_hash` char(64) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_style_snapshots_revision_hash` (`style_instance_id`,`revision`,`content_hash`),
  KEY `idx_style_snapshots_tenant_theme` (`tenant_id`,`theme_version_id`),
  CONSTRAINT `fk_style_snapshots_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_style_snapshots_instance` FOREIGN KEY (`style_instance_id`) REFERENCES `style_instances` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_style_snapshots_theme_version` FOREIGN KEY (`theme_version_id`) REFERENCES `theme_versions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `site_theme_releases` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `site_id` varchar(64) NOT NULL,
  `theme_version_id` varchar(64) NOT NULL,
  `style_snapshot_id` varchar(64) NOT NULL,
  `previous_release_id` varchar(64) DEFAULT NULL,
  `rollback_of_release_id` varchar(64) DEFAULT NULL,
  `idempotency_key` varchar(190) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_site_theme_release_idempotency` (`tenant_id`,`site_id`,`idempotency_key`),
  KEY `idx_site_theme_releases_site_time` (`tenant_id`,`site_id`,`created_at`),
  KEY `idx_site_theme_releases_snapshot` (`style_snapshot_id`),
  KEY `idx_site_theme_releases_previous` (`previous_release_id`),
  KEY `idx_site_theme_releases_rollback` (`rollback_of_release_id`),
  CONSTRAINT `fk_site_theme_releases_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_site_theme_releases_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_site_theme_releases_theme_version` FOREIGN KEY (`theme_version_id`) REFERENCES `theme_versions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_site_theme_releases_snapshot` FOREIGN KEY (`style_snapshot_id`) REFERENCES `style_snapshots` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_site_theme_releases_previous` FOREIGN KEY (`previous_release_id`) REFERENCES `site_theme_releases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_site_theme_releases_rollback` FOREIGN KEY (`rollback_of_release_id`) REFERENCES `site_theme_releases` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `sites`
  ADD CONSTRAINT `fk_sites_active_theme_release` FOREIGN KEY (`active_theme_release_id`) REFERENCES `site_theme_releases` (`id`) ON DELETE SET NULL;
