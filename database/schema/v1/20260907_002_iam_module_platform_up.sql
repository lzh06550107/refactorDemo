CREATE TABLE `roles` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `code` varchar(96) NOT NULL,
  `name` varchar(128) NOT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_tenant_code` (`tenant_id`,`code`),
  KEY `idx_roles_tenant` (`tenant_id`),
  CONSTRAINT `fk_roles_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permissions` (
  `id` varchar(64) NOT NULL,
  `permission_key` varchar(190) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_permissions_key` (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `role_permissions` (
  `role_id` varchar(64) NOT NULL,
  `permission_id` varchar(64) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `idx_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `permission_assignments` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `account_id` varchar(64) DEFAULT NULL,
  `admin_user_id` varchar(64) NOT NULL,
  `role_id` varchar(64) NOT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_permission_assignments_subject_role_account` (`admin_user_id`,`role_id`,`account_id`),
  KEY `idx_permission_assignments_tenant_account` (`tenant_id`,`account_id`),
  KEY `idx_permission_assignments_user` (`admin_user_id`),
  CONSTRAINT `fk_permission_assignments_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_permission_assignments_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_permission_assignments_user` FOREIGN KEY (`admin_user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_permission_assignments_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `module_definitions` (
  `id` varchar(64) NOT NULL,
  `name` varchar(128) NOT NULL,
  `title` varchar(190) NOT NULL,
  `system_module` tinyint(1) NOT NULL DEFAULT 0,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_definitions_name` (`name`),
  KEY `idx_module_definitions_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `module_versions` (
  `id` varchar(64) NOT NULL,
  `module_id` varchar(64) NOT NULL,
  `version` varchar(64) NOT NULL,
  `manifest_json` json NOT NULL,
  `checksum_sha256` char(64) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_versions_module_version` (`module_id`,`version`),
  CONSTRAINT `fk_module_versions_module` FOREIGN KEY (`module_id`) REFERENCES `module_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `module_bindings` (
  `id` varchar(64) NOT NULL,
  `module_version_id` varchar(64) NOT NULL,
  `entry_type` varchar(32) NOT NULL,
  `do_name` varchar(128) NOT NULL,
  `title` varchar(190) NOT NULL,
  `route_path` varchar(512) DEFAULT NULL,
  `legacy_call` varchar(190) DEFAULT NULL,
  `multilevel` tinyint(1) NOT NULL DEFAULT 0,
  `parent_key` varchar(128) DEFAULT NULL,
  `display_order` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_bindings_version_entry_do` (`module_version_id`,`entry_type`,`do_name`),
  KEY `idx_module_bindings_version_order` (`module_version_id`,`display_order`),
  CONSTRAINT `fk_module_bindings_version` FOREIGN KEY (`module_version_id`) REFERENCES `module_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `module_capabilities` (
  `id` varchar(64) NOT NULL,
  `module_version_id` varchar(64) NOT NULL,
  `capability_key` varchar(128) NOT NULL,
  `capability_value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_module_capabilities_version_key` (`module_version_id`,`capability_key`),
  CONSTRAINT `fk_module_capabilities_version` FOREIGN KEY (`module_version_id`) REFERENCES `module_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `tenant_modules` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `module_id` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `source` varchar(32) NOT NULL DEFAULT 'legacy_migration',
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_modules_tenant_module` (`tenant_id`,`module_id`),
  KEY `idx_tenant_modules_tenant_status` (`tenant_id`,`status`),
  CONSTRAINT `fk_tenant_modules_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tenant_modules_module` FOREIGN KEY (`module_id`) REFERENCES `module_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_module_configs` (
  `id` varchar(64) NOT NULL,
  `tenant_id` varchar(64) NOT NULL,
  `account_id` varchar(64) NOT NULL,
  `module_id` varchar(64) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `display_order` int NOT NULL DEFAULT 0,
  `shortcut` tinyint(1) NOT NULL DEFAULT 0,
  `module_shortcut` tinyint(1) NOT NULL DEFAULT 0,
  `settings_json` json DEFAULT NULL,
  `created_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_account_module_configs_account_module` (`account_id`,`module_id`),
  KEY `idx_account_module_configs_tenant_account` (`tenant_id`,`account_id`),
  CONSTRAINT `fk_account_module_configs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_account_module_configs_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_account_module_configs_module` FOREIGN KEY (`module_id`) REFERENCES `module_definitions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
