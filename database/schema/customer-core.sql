SET FOREIGN_KEY_CHECKS = 0
-- @@
CREATE TABLE `autonumbers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `table_name` varchar(64) NOT NULL,
  `column_name` varchar(64) NOT NULL,
  `prefix` varchar(100) NOT NULL DEFAULT '',
  `suffix` varchar(100) NOT NULL DEFAULT '',
  `last_value` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `autonumbers_table_name_column_name_prefix_suffix_unique` (`table_name`,`column_name`,`prefix`,`suffix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `chat_members` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `last_read_message_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `typing_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_members_chat_id_user_id_unique` (`chat_id`,`user_id`),
  KEY `chat_members_user_id_index` (`user_id`),
  CONSTRAINT `chat_members_chat_id_foreign` FOREIGN KEY (`chat_id`) REFERENCES `chats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `chat_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `kind` varchar(10) NOT NULL DEFAULT 'text',
  `body` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `chat_messages_user_id_foreign` (`user_id`),
  KEY `chat_messages_chat_id_id_index` (`chat_id`,`id`),
  CONSTRAINT `chat_messages_chat_id_foreign` FOREIGN KEY (`chat_id`) REFERENCES `chats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `chats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(10) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `direct_key` varchar(41) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chats_direct_key_unique` (`direct_key`),
  KEY `chats_created_by_foreign` (`created_by`),
  KEY `chats_last_message_at_index` (`last_message_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `content_map` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `content_id` bigint(20) unsigned NOT NULL,
  `record_id` bigint(20) unsigned NOT NULL,
  `workflow_meta_id` bigint(20) unsigned NOT NULL,
  `param_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `content_map_content_id_record_id_param_id_unique` (`content_id`,`record_id`,`param_id`),
  KEY `content_map_workflow_meta_id_record_id_index` (`workflow_meta_id`,`record_id`),
  KEY `content_map_param_id_record_id_index` (`param_id`,`record_id`),
  CONSTRAINT `content_map_content_id_foreign` FOREIGN KEY (`content_id`) REFERENCES `contents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `contents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `physical_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_extension` varchar(20) DEFAULT NULL,
  `file_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `type_id` bigint(20) unsigned DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `contents_created_by_foreign` (`created_by`),
  KEY `contents_type_id_index` (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `languages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_source` tinyint(1) NOT NULL DEFAULT 0,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `order_number` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `languages_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `grid_meta_id` bigint(20) unsigned NOT NULL,
  `action` varchar(20) NOT NULL,
  `label` varchar(255) NOT NULL,
  `icon_cls` varchar(255) DEFAULT NULL,
  `form_meta_id` bigint(20) unsigned DEFAULT NULL,
  `get_meta_id` bigint(20) unsigned DEFAULT NULL,
  `needs_row` tinyint(1) NOT NULL DEFAULT 0,
  `show_in_toolbar` tinyint(1) NOT NULL DEFAULT 1,
  `show_in_row` tinyint(1) NOT NULL DEFAULT 0,
  `order_number` int(11) NOT NULL DEFAULT 1,
  `json_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`json_config`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meta_actions_form_meta_id_foreign` (`form_meta_id`),
  KEY `meta_actions_get_meta_id_foreign` (`get_meta_id`),
  KEY `meta_actions_grid_meta_id_order_number_index` (`grid_meta_id`,`order_number`),
  CONSTRAINT `meta_actions_form_meta_id_foreign` FOREIGN KEY (`form_meta_id`) REFERENCES `metas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `meta_actions_get_meta_id_foreign` FOREIGN KEY (`get_meta_id`) REFERENCES `metas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `meta_actions_grid_meta_id_foreign` FOREIGN KEY (`grid_meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_meta_map` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `src_meta_id` bigint(20) unsigned NOT NULL,
  `trg_meta_id` bigint(20) unsigned NOT NULL,
  `src_path` varchar(255) DEFAULT NULL,
  `trg_path` varchar(255) DEFAULT NULL,
  `src_param_id` bigint(20) unsigned DEFAULT NULL,
  `trg_param_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(30) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meta_meta_map_trg_meta_id_foreign` (`trg_meta_id`),
  KEY `meta_meta_map_src_param_id_foreign` (`src_param_id`),
  KEY `meta_meta_map_trg_param_id_foreign` (`trg_param_id`),
  KEY `meta_meta_map_src_meta_id_src_param_id_type_index` (`src_meta_id`,`src_param_id`,`type`),
  CONSTRAINT `meta_meta_map_src_meta_id_foreign` FOREIGN KEY (`src_meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_meta_map_src_param_id_foreign` FOREIGN KEY (`src_param_id`) REFERENCES `meta_params` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_meta_map_trg_meta_id_foreign` FOREIGN KEY (`trg_meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_meta_map_trg_param_id_foreign` FOREIGN KEY (`trg_param_id`) REFERENCES `meta_params` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_notification_map` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `notification_id` bigint(20) unsigned NOT NULL,
  `meta_id` bigint(20) unsigned DEFAULT NULL,
  `status_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meta_notification_map_notification_id_unique` (`notification_id`),
  KEY `meta_notification_map_meta_id_foreign` (`meta_id`),
  KEY `meta_notification_map_status_id_foreign` (`status_id`),
  CONSTRAINT `meta_notification_map_meta_id_foreign` FOREIGN KEY (`meta_id`) REFERENCES `metas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `meta_notification_map_notification_id_foreign` FOREIGN KEY (`notification_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_notification_map_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `wfm_status` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_params` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_number` int(11) NOT NULL,
  `field_path` varchar(255) NOT NULL,
  `param_name` varchar(255) NOT NULL,
  `column_path` varchar(255) DEFAULT NULL,
  `label_name` varchar(255) DEFAULT NULL,
  `data_type` varchar(255) NOT NULL,
  `data_mask` varchar(255) DEFAULT NULL,
  `render_type` varchar(255) DEFAULT NULL,
  `widget_position` varchar(10) DEFAULT NULL,
  `icon_cls` varchar(255) DEFAULT NULL,
  `main_meta_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `ref_meta_id` bigint(20) unsigned DEFAULT NULL,
  `display_field` varchar(255) DEFAULT NULL,
  `value_field` varchar(255) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_label` tinyint(1) NOT NULL DEFAULT 0,
  `is_parent` tinyint(1) NOT NULL DEFAULT 0,
  `is_show` tinyint(1) NOT NULL DEFAULT 1,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_criteria` tinyint(1) NOT NULL DEFAULT 0,
  `is_unique` tinyint(1) NOT NULL DEFAULT 0,
  `is_merge` tinyint(1) NOT NULL DEFAULT 0,
  `is_select` tinyint(1) NOT NULL DEFAULT 0,
  `is_mandatory_criteria` tinyint(1) NOT NULL DEFAULT 0,
  `default_value` varchar(255) DEFAULT NULL,
  `min_value` varchar(255) DEFAULT NULL,
  `max_value` varchar(255) DEFAULT NULL,
  `column_width` varchar(255) DEFAULT NULL,
  `expression_string` varchar(255) DEFAULT NULL,
  `json_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`json_config`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meta_params_ref_meta_id_foreign` (`ref_meta_id`),
  KEY `meta_params_parent_id_foreign` (`parent_id`),
  KEY `meta_params_main_meta_id_parent_id_order_number_index` (`main_meta_id`,`parent_id`,`order_number`),
  CONSTRAINT `meta_params_main_meta_id_foreign` FOREIGN KEY (`main_meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_params_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `meta_params` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_params_ref_meta_id_foreign` FOREIGN KEY (`ref_meta_id`) REFERENCES `metas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `meta_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT 1,
  `can_create` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meta_permissions_meta_id_role_id_unique` (`meta_id`,`role_id`),
  KEY `meta_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `meta_permissions_meta_id_foreign` FOREIGN KEY (`meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_process_behaviour` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_id` bigint(20) unsigned NOT NULL,
  `source_path` varchar(255) NOT NULL,
  `target_path` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meta_process_behaviour_workflow_id_foreign` (`workflow_id`),
  CONSTRAINT `meta_process_behaviour_workflow_id_foreign` FOREIGN KEY (`workflow_id`) REFERENCES `meta_process_workflow` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_process_transition` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `main_process_id` bigint(20) unsigned NOT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `criteria` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`criteria`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meta_process_transition_main_process_id_foreign` (`main_process_id`),
  KEY `meta_process_transition_source_id_foreign` (`source_id`),
  KEY `meta_process_transition_target_id_foreign` (`target_id`),
  CONSTRAINT `meta_process_transition_main_process_id_foreign` FOREIGN KEY (`main_process_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_process_transition_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `meta_process_workflow` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_process_transition_target_id_foreign` FOREIGN KEY (`target_id`) REFERENCES `meta_process_workflow` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_process_workflow` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `main_process_id` bigint(20) unsigned NOT NULL,
  `process_id` bigint(20) unsigned NOT NULL,
  `order_number` int(11) NOT NULL DEFAULT 0,
  `pos_x` int(11) DEFAULT NULL,
  `pos_y` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meta_process_workflow_main_process_id_foreign` (`main_process_id`),
  KEY `meta_process_workflow_process_id_foreign` (`process_id`),
  CONSTRAINT `meta_process_workflow_main_process_id_foreign` FOREIGN KEY (`main_process_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_process_workflow_process_id_foreign` FOREIGN KEY (`process_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` bigint(20) unsigned NOT NULL,
  `process_id` bigint(20) unsigned NOT NULL,
  `view_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `meta_schedules_schedule_id_foreign` (`schedule_id`),
  KEY `meta_schedules_process_id_foreign` (`process_id`),
  KEY `meta_schedules_view_id_foreign` (`view_id`),
  CONSTRAINT `meta_schedules_process_id_foreign` FOREIGN KEY (`process_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_schedules_schedule_id_foreign` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_schedules_view_id_foreign` FOREIGN KEY (`view_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_structures` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `meta_id` bigint(20) unsigned NOT NULL,
  `table_name` varchar(255) NOT NULL,
  `columns` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`columns`)),
  `applied_at` timestamp NULL DEFAULT NULL,
  `applied_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meta_structures_meta_id_unique` (`meta_id`),
  UNIQUE KEY `meta_structures_table_name_unique` (`table_name`),
  CONSTRAINT `meta_structures_meta_id_foreign` FOREIGN KEY (`meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_tag_map` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `meta_id` bigint(20) unsigned NOT NULL,
  `tag_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meta_tag_map_meta_id_tag_id_unique` (`meta_id`,`tag_id`),
  KEY `meta_tag_map_tag_id_foreign` (`tag_id`),
  CONSTRAINT `meta_tag_map_meta_id_foreign` FOREIGN KEY (`meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `meta_tag_map_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meta_types_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `meta_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `meta_id` bigint(20) unsigned NOT NULL,
  `version_no` int(10) unsigned NOT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `snapshot` longtext NOT NULL,
  `hash` varchar(64) NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `is_auto` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `meta_versions_meta_id_version_no_unique` (`meta_id`,`version_no`),
  KEY `meta_versions_created_by_foreign` (`created_by`),
  KEY `meta_versions_meta_id_index` (`meta_id`),
  KEY `meta_versions_meta_id_hash_index` (`meta_id`,`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `metas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `label_name` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `is_log` tinyint(1) NOT NULL DEFAULT 0,
  `is_public` tinyint(1) NOT NULL DEFAULT 0,
  `is_open` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `ref_meta_id` bigint(20) unsigned DEFAULT NULL,
  `description` text DEFAULT NULL,
  `table_name` varchar(191) DEFAULT NULL,
  `query_string` longtext DEFAULT NULL,
  `json_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`json_config`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `type_id` bigint(20) unsigned DEFAULT NULL,
  `widget_id` bigint(20) unsigned DEFAULT NULL,
  `load_expression_string` longtext DEFAULT NULL,
  `event_expression_string` longtext DEFAULT NULL,
  `before_save_expression_string` longtext DEFAULT NULL,
  `after_save_expression_string` longtext DEFAULT NULL,
  `var_fnc_expression_string` longtext DEFAULT NULL,
  `service_url` varchar(2048) DEFAULT NULL,
  `service_headers` text DEFAULT NULL,
  `service_query` text DEFAULT NULL,
  `service_list_path` varchar(255) DEFAULT NULL,
  `service_total_path` varchar(255) DEFAULT NULL,
  `service_record_path` varchar(255) DEFAULT NULL,
  `service_update_method` varchar(10) DEFAULT NULL,
  `service_list_body` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `metas_code_unique` (`code`),
  KEY `metas_created_by_foreign` (`created_by`),
  KEY `metas_updated_by_foreign` (`updated_by`),
  KEY `metas_ref_meta_id_foreign` (`ref_meta_id`),
  KEY `metas_type_id_foreign` (`type_id`),
  KEY `metas_widget_id_foreign` (`widget_id`),
  KEY `metas_table_name_index` (`table_name`),
  CONSTRAINT `metas_ref_meta_id_foreign` FOREIGN KEY (`ref_meta_id`) REFERENCES `metas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `metas_type_id_foreign` FOREIGN KEY (`type_id`) REFERENCES `meta_types` (`id`),
  CONSTRAINT `metas_widget_id_foreign` FOREIGN KEY (`widget_id`) REFERENCES `widgets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `patch_installs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patch_code` varchar(100) DEFAULT NULL,
  `patch_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `hash` varchar(64) NOT NULL,
  `source` varchar(255) DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `existing` varchar(10) NOT NULL,
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`result`)),
  `package` longtext NOT NULL,
  `installed_by` bigint(20) unsigned DEFAULT NULL,
  `installed_at` timestamp NULL DEFAULT NULL,
  `rolled_back_at` timestamp NULL DEFAULT NULL,
  `rolled_back_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `patch_installs_installed_by_foreign` (`installed_by`),
  KEY `patch_installs_rolled_back_by_foreign` (`rolled_back_by`),
  KEY `patch_installs_hash_index` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `patch_metas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `patch_id` bigint(20) unsigned NOT NULL,
  `meta_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `patch_metas_patch_id_meta_id_unique` (`patch_id`,`meta_id`),
  KEY `patch_metas_meta_id_foreign` (`meta_id`),
  CONSTRAINT `patch_metas_meta_id_foreign` FOREIGN KEY (`meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `patch_metas_patch_id_foreign` FOREIGN KEY (`patch_id`) REFERENCES `patches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `patches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(100) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `with_used` tinyint(1) NOT NULL DEFAULT 0,
  `data_tables` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data_tables`)),
  `status` varchar(10) NOT NULL DEFAULT 'draft',
  `package` longtext DEFAULT NULL,
  `hash` varchar(64) DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `released_by` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `patches_code_unique` (`code`),
  KEY `patches_released_by_foreign` (`released_by`),
  KEY `patches_created_by_foreign` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `record_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `meta_id` bigint(20) unsigned DEFAULT NULL,
  `table_name` varchar(64) NOT NULL,
  `record_id` bigint(20) unsigned NOT NULL,
  `action` varchar(10) NOT NULL,
  `changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`changes`)),
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`snapshot`)),
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'form',
  `batch_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `record_log_user_id_foreign` (`user_id`),
  KEY `record_log_table_name_record_id_id_index` (`table_name`,`record_id`,`id`),
  KEY `record_log_meta_id_created_at_index` (`meta_id`,`created_at`),
  KEY `record_log_batch_id_index` (`batch_id`),
  CONSTRAINT `record_log_meta_id_foreign` FOREIGN KEY (`meta_id`) REFERENCES `metas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `role_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` bigint(20) unsigned NOT NULL,
  `action` varchar(10) NOT NULL,
  `changes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`changes`)),
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `role_log_user_id_foreign` (`user_id`),
  KEY `role_log_role_id_id_index` (`role_id`,`id`),
  CONSTRAINT `role_log_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `features` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`features`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `schedules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `schedules_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `session_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `path` varchar(100) NOT NULL,
  `query` text NOT NULL,
  `param` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_values_path_unique` (`path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `site_domains` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `site_meta_id` bigint(20) unsigned NOT NULL,
  `host` varchar(190) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_domains_host_unique` (`host`),
  KEY `site_domains_site_meta_id_foreign` (`site_meta_id`),
  CONSTRAINT `site_domains_site_meta_id_foreign` FOREIGN KEY (`site_meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `site_releases` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `site_meta_id` bigint(20) unsigned NOT NULL,
  `param_id` bigint(20) unsigned DEFAULT NULL,
  `page_meta_id` bigint(20) unsigned DEFAULT NULL,
  `path` varchar(255) DEFAULT NULL,
  `snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`snapshot`)),
  `hash` varchar(40) DEFAULT NULL,
  `checks` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checks`)),
  `note` text DEFAULT NULL,
  `restored_from_id` bigint(20) unsigned DEFAULT NULL,
  `publish_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `published_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `site_releases_site_meta_id_foreign` (`site_meta_id`),
  KEY `site_releases_page_meta_id_foreign` (`page_meta_id`),
  KEY `site_releases_restored_from_id_foreign` (`restored_from_id`),
  KEY `site_releases_published_by_foreign` (`published_by`),
  KEY `site_releases_param_id_publish_at_index` (`param_id`,`publish_at`),
  KEY `site_releases_publish_at_index` (`publish_at`),
  CONSTRAINT `site_releases_page_meta_id_foreign` FOREIGN KEY (`page_meta_id`) REFERENCES `metas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_releases_param_id_foreign` FOREIGN KEY (`param_id`) REFERENCES `meta_params` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_releases_restored_from_id_foreign` FOREIGN KEY (`restored_from_id`) REFERENCES `site_releases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_releases_site_meta_id_foreign` FOREIGN KEY (`site_meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `site_visits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `site_meta_id` bigint(20) unsigned NOT NULL,
  `path` varchar(255) NOT NULL,
  `day` date NOT NULL,
  `views` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_visits_site_meta_id_path_day_unique` (`site_meta_id`,`path`,`day`),
  KEY `site_visits_site_meta_id_day_index` (`site_meta_id`,`day`),
  CONSTRAINT `site_visits_site_meta_id_foreign` FOREIGN KEY (`site_meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `tags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `color` varchar(20) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tags_name_unique` (`name`),
  KEY `tags_created_by_foreign` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `meta_id` bigint(20) unsigned NOT NULL,
  `table_name` varchar(64) NOT NULL,
  `row_id` bigint(20) unsigned NOT NULL,
  `field` varchar(64) NOT NULL,
  `locale` varchar(10) NOT NULL,
  `value` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `translations_table_name_row_id_field_locale_unique` (`table_name`,`row_id`,`field`,`locale`),
  KEY `translations_meta_id_locale_index` (`meta_id`,`locale`),
  CONSTRAINT `translations_meta_id_foreign` FOREIGN KEY (`meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `user_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `notification_meta_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `email_status` varchar(10) DEFAULT NULL,
  `email_error` varchar(255) DEFAULT NULL,
  `emailed_at` timestamp NULL DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_notifications_user_id_read_at_index` (`user_id`,`read_at`),
  KEY `user_notifications_notification_meta_id_foreign` (`notification_meta_id`),
  CONSTRAINT `user_notifications_notification_meta_id_foreign` FOREIGN KEY (`notification_meta_id`) REFERENCES `metas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `user_roles` (
  `user_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `user_roles_role_id_foreign` (`role_id`),
  CONSTRAINT `user_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `wfm_status` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `workflow_meta_id` bigint(20) unsigned NOT NULL,
  `color` varchar(255) DEFAULT NULL,
  `meta_id` bigint(20) unsigned DEFAULT NULL,
  `label_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wfm_status_workflow_meta_id_code_unique` (`workflow_meta_id`,`code`),
  KEY `wfm_status_meta_id_foreign` (`meta_id`),
  CONSTRAINT `wfm_status_meta_id_foreign` FOREIGN KEY (`meta_id`) REFERENCES `metas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wfm_status_workflow_meta_id_foreign` FOREIGN KEY (`workflow_meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `wfm_status_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workflow_meta_id` bigint(20) unsigned NOT NULL,
  `record_id` bigint(20) unsigned NOT NULL,
  `status_id` bigint(20) unsigned NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `prev_status_id` bigint(20) unsigned DEFAULT NULL,
  `time_spent` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wfm_status_log_status_id_foreign` (`status_id`),
  KEY `wfm_status_log_created_by_foreign` (`created_by`),
  KEY `wfm_status_log_prev_status_id_foreign` (`prev_status_id`),
  KEY `wfm_status_log_workflow_meta_id_record_id_index` (`workflow_meta_id`,`record_id`),
  CONSTRAINT `wfm_status_log_prev_status_id_foreign` FOREIGN KEY (`prev_status_id`) REFERENCES `wfm_status` (`id`),
  CONSTRAINT `wfm_status_log_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `wfm_status` (`id`),
  CONSTRAINT `wfm_status_log_workflow_meta_id_foreign` FOREIGN KEY (`workflow_meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `wfm_status_permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `status_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `can_enter` tinyint(1) NOT NULL DEFAULT 0,
  `can_see` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wfm_status_permissions_status_id_role_id_unique` (`status_id`,`role_id`),
  KEY `wfm_status_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `wfm_status_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wfm_status_permissions_status_id_foreign` FOREIGN KEY (`status_id`) REFERENCES `wfm_status` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `wfm_transition` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `prev_status_id` bigint(20) unsigned DEFAULT NULL,
  `next_status_id` bigint(20) unsigned NOT NULL,
  `criteria` text DEFAULT NULL,
  `prev_id` bigint(20) unsigned DEFAULT NULL,
  `next_id` bigint(20) unsigned DEFAULT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `is_transition` tinyint(1) NOT NULL DEFAULT 1,
  `workflow_meta_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `wfm_transition_prev_status_id_foreign` (`prev_status_id`),
  KEY `wfm_transition_next_status_id_foreign` (`next_status_id`),
  KEY `wfm_transition_prev_id_foreign` (`prev_id`),
  KEY `wfm_transition_next_id_foreign` (`next_id`),
  KEY `wfm_transition_source_id_foreign` (`source_id`),
  KEY `wfm_transition_workflow_meta_id_foreign` (`workflow_meta_id`),
  CONSTRAINT `wfm_transition_next_id_foreign` FOREIGN KEY (`next_id`) REFERENCES `wfm_transition` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wfm_transition_next_status_id_foreign` FOREIGN KEY (`next_status_id`) REFERENCES `wfm_status` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wfm_transition_prev_id_foreign` FOREIGN KEY (`prev_id`) REFERENCES `wfm_transition` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wfm_transition_prev_status_id_foreign` FOREIGN KEY (`prev_status_id`) REFERENCES `wfm_status` (`id`) ON DELETE CASCADE,
  CONSTRAINT `wfm_transition_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `metas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `wfm_transition_workflow_meta_id_foreign` FOREIGN KEY (`workflow_meta_id`) REFERENCES `metas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
CREATE TABLE `widgets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category` varchar(255) NOT NULL DEFAULT 'Content',
  `renderer` varchar(50) NOT NULL,
  `template_meta_id` bigint(20) unsigned DEFAULT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `props` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`props`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `order_number` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `widgets_code_unique` (`code`),
  KEY `widgets_template_meta_id_foreign` (`template_meta_id`),
  CONSTRAINT `widgets_template_meta_id_foreign` FOREIGN KEY (`template_meta_id`) REFERENCES `metas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('1', 'app', 'App', '1', 'An application with a menu that links to its screens.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('2', 'datagrid', 'Data grid', '1', 'A table of records with columns, sorting and filters.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('3', 'form', 'Form', '1', 'Fields for creating or editing one record.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('4', 'calendar', 'Calendar', '1', 'Records shown as events on a calendar.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('5', 'tabs', 'Tabs', '1', 'Several screens grouped under tabs.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('31', 'gantt', 'Gantt chart', '1', 'Records shown as bars on a timeline, like project tasks.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('55', 'get', 'Get', '1', 'A query that decides the values a form opens with.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('90', 'workflow', 'Workflow', '1', 'Statuses and the moves between them, for the records of a table.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('106', 'dashboard', 'Dashboard', '1', 'A layout of charts, each placed and sized on a grid.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('107', 'chart', 'Chart', '1', 'Numbers from a table or query, drawn as a chart or a single figure.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('126', 'statement', 'Statement', '1', 'Records grouped by columns, with subtotals and a grand total, to print or export.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('127', 'report', 'Report', '1', 'A printable document for one record, such as a purchase order.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('150', 'excel_import', 'Excel import', '1', 'Loads the rows of an Excel file into a table, each column mapped and checked by a row.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('163', 'page', 'Page', '1', 'A page built from widgets: headings, text, images, buttons, charts and grids.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('164', 'widget', 'Widget', '1', 'One widget from the library, with its settings, placed on pages.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('193', 'widget_template', 'Widget template', '1', 'The parts of a composite widget, laid out like a page.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('209', 'notification', 'Notification', '1', 'A message sent to people when records are added, changed or move to a status.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('243', 'workspace', 'Workspace', '1', 'One record and everything about it, such as a tender with its items, bidders and evaluation.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('244', 'matrix', 'Matrix', '1', 'A value for each pair of records from two grids, such as each bidder’s price for each item.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('281', 'stage_query', 'Stage query', '1', 'SQL that changes data (INSERT, UPDATE, DELETE) with named parameters, run by hand, after a form saves or on a schedule.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('421', 'query', 'Query', '1', 'A SELECT drawn by dragging tables and views, joining them and picking columns, filters, groups and order; grids and other queries read it.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('463', 'theme', 'Theme', '1', 'How the app looks: brand colour, greys, corners, font and size, in light and dark. Users pick one; one is the default, and an app can have its own.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('485', 'website', 'Website', '1', 'A public website: pages from the page builder with addresses, a menu, its own domains, publishing and visit counts.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('624', 'screen', 'Screen', '1', 'A built-in screen of the system, such as market contracts, placed in an app’s menu like any other meta.')
-- @@
INSERT INTO `meta_types` (`id`, `code`, `name`, `is_active`, `description`) VALUES ('673', 'structure', 'Structure', '1', 'A database table: its params are the columns. Apply to database creates the table, then adds and changes columns.')
-- @@
INSERT INTO `languages` (`id`, `code`, `name`, `is_source`, `is_default`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('1', 'en', 'English', '1', '1', '1', '1', '2026-10-25 16:26:50', '2026-10-27 07:15:05')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('1', 'section', 'Section', 'Layout', 'section', NULL, 'layout', 'A box that holds other widgets, with an optional title and background.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"subtitle\",\"label\":\"Subtitle\",\"type\":\"text\"},{\"key\":\"background\",\"label\":\"Background\",\"type\":\"select\",\"options\":[\"none\",\"card\",\"subtle\",\"brand\"],\"default\":\"card\"}]', '1', '1', '2026-10-20 15:19:18', '2026-10-20 15:19:18')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('2', 'divider', 'Divider', 'Layout', 'divider', NULL, 'minus', 'A thin line between parts of the page.', '[{\"key\":\"label\",\"label\":\"Text on the line\",\"type\":\"text\"}]', '1', '2', '2026-10-20 15:19:18', '2026-10-20 15:19:18')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('3', 'spacer', 'Spacer', 'Layout', 'spacer', NULL, 'expand', 'Empty space.', '[{\"key\":\"size\",\"label\":\"Height\",\"type\":\"select\",\"options\":[\"small\",\"medium\",\"large\"],\"default\":\"medium\"}]', '1', '3', '2026-10-20 15:19:18', '2026-10-20 15:19:18')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('4', 'hero', 'Banner', 'Content', 'hero', NULL, 'star', 'A large title and message with a button, to open a page.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\",\"default\":\"Welcome\"},{\"key\":\"subtitle\",\"label\":\"Message\",\"type\":\"textarea\"},{\"key\":\"button_label\",\"label\":\"Button\",\"type\":\"text\"},{\"key\":\"meta_id\",\"label\":\"Opens screen\",\"type\":\"meta\",\"hint\":\"A grid, form, report\\u2026 opened in a window.\"},{\"key\":\"url\",\"label\":\"Or opens link\",\"type\":\"url\"},{\"key\":\"image\",\"label\":\"Image\",\"type\":\"image\"},{\"key\":\"tone\",\"label\":\"Colour\",\"type\":\"select\",\"options\":[\"brand\",\"dark\",\"light\"],\"default\":\"brand\"}]', '1', '4', '2026-10-20 15:19:18', '2026-10-20 15:19:18')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('5', 'heading', 'Heading', 'Content', 'heading', NULL, 'type', 'A title, with an optional line under it.', '[{\"key\":\"text\",\"label\":\"Text\",\"type\":\"text\",\"default\":\"Heading\"},{\"key\":\"subtitle\",\"label\":\"Line under it\",\"type\":\"text\"},{\"key\":\"size\",\"label\":\"Size\",\"type\":\"select\",\"options\":[\"large\",\"medium\",\"small\"],\"default\":\"medium\"},{\"key\":\"align\",\"label\":\"Alignment\",\"type\":\"select\",\"options\":[\"left\",\"center\",\"right\"],\"default\":\"left\"}]', '1', '5', '2026-10-20 15:19:18', '2026-10-20 15:19:18')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('6', 'text', 'Text', 'Content', 'text', NULL, 'align-left', 'Paragraphs of text. A blank line starts a new paragraph.', '[{\"key\":\"text\",\"label\":\"Text\",\"type\":\"textarea\",\"default\":\"Write something here.\"},{\"key\":\"size\",\"label\":\"Size\",\"type\":\"select\",\"options\":[\"small\",\"normal\",\"large\"],\"default\":\"normal\"},{\"key\":\"align\",\"label\":\"Alignment\",\"type\":\"select\",\"options\":[\"left\",\"center\",\"right\"],\"default\":\"left\"}]', '1', '6', '2026-10-20 15:19:18', '2026-10-20 15:19:18')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('7', 'image', 'Image', 'Content', 'image', NULL, 'image', 'A picture from a web address.', '[{\"key\":\"src\",\"label\":\"Image address\",\"type\":\"image\"},{\"key\":\"alt\",\"label\":\"Description\",\"type\":\"text\",\"hint\":\"Read out to people who can\\u2019t see the image.\"},{\"key\":\"caption\",\"label\":\"Caption\",\"type\":\"text\"},{\"key\":\"height\",\"label\":\"Height (px)\",\"type\":\"number\",\"default\":240},{\"key\":\"fit\",\"label\":\"Fit\",\"type\":\"select\",\"options\":[\"cover\",\"contain\"],\"default\":\"cover\"}]', '1', '7', '2026-10-20 15:19:18', '2026-10-20 15:19:18')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('8', 'callout', 'Callout', 'Content', 'callout', NULL, 'info', 'A coloured note that stands out.', '[{\"key\":\"tone\",\"label\":\"Kind\",\"type\":\"select\",\"options\":[\"info\",\"success\",\"warning\",\"error\"],\"default\":\"info\"},{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"text\",\"label\":\"Text\",\"type\":\"textarea\",\"default\":\"Something worth knowing.\"}]', '1', '17', '2026-10-20 15:19:18', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('9', 'list', 'Feature list', 'Content', 'list', NULL, 'list', 'Short points, each with a title and a line of text.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"columns\",\"label\":\"Columns\",\"type\":\"select\",\"options\":[\"1\",\"2\",\"3\"],\"default\":\"2\"},{\"key\":\"items\",\"label\":\"Points\",\"type\":\"list\",\"default\":[{\"title\":\"First point\",\"text\":\"Say why it matters.\"}],\"fields\":[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"text\",\"label\":\"Text\",\"type\":\"text\"}]}]', '1', '18', '2026-10-20 15:19:18', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('10', 'button', 'Button', 'Navigation', 'button', NULL, 'cursor', 'A button that opens a screen or a link.', '[{\"key\":\"label\",\"label\":\"Label\",\"type\":\"text\",\"default\":\"Open\"},{\"key\":\"meta_id\",\"label\":\"Opens screen\",\"type\":\"meta\",\"hint\":\"A grid, form, report\\u2026 opened in a window.\"},{\"key\":\"url\",\"label\":\"Or opens link\",\"type\":\"url\"},{\"key\":\"style\",\"label\":\"Style\",\"type\":\"select\",\"options\":[\"primary\",\"secondary\",\"link\"],\"default\":\"primary\"},{\"key\":\"align\",\"label\":\"Alignment\",\"type\":\"select\",\"options\":[\"left\",\"center\",\"right\"],\"default\":\"left\"}]', '1', '19', '2026-10-20 15:19:18', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('11', 'links', 'Quick links', 'Navigation', 'links', NULL, 'link', 'Tiles that open screens or links.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"items\",\"label\":\"Links\",\"type\":\"list\",\"default\":[],\"fields\":[{\"key\":\"label\",\"label\":\"Label\",\"type\":\"text\"},{\"key\":\"description\",\"label\":\"Description\",\"type\":\"text\"},{\"key\":\"meta_id\",\"label\":\"Opens screen\",\"type\":\"meta\",\"hint\":\"A grid, form, report\\u2026 opened in a window.\"},{\"key\":\"url\",\"label\":\"Or opens link\",\"type\":\"url\"}]}]', '1', '20', '2026-10-20 15:19:18', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('12', 'stat', 'Figure', 'Data', 'stat', NULL, 'hash', 'One number written in by hand, with a label and a change.', '[{\"key\":\"label\",\"label\":\"Label\",\"type\":\"text\",\"default\":\"Figure\"},{\"key\":\"value\",\"label\":\"Value\",\"type\":\"text\",\"default\":\"0\"},{\"key\":\"change\",\"label\":\"Change\",\"type\":\"text\",\"hint\":\"e.g. +12%\"},{\"key\":\"trend\",\"label\":\"Trend\",\"type\":\"select\",\"options\":[\"up\",\"down\",\"flat\"],\"default\":\"flat\"},{\"key\":\"note\",\"label\":\"Note\",\"type\":\"text\"}]', '1', '21', '2026-10-20 15:19:18', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('13', 'chart', 'Chart', 'Data', 'chart', NULL, 'chart', 'A chart meta, drawn live.', '[{\"key\":\"meta_id\",\"label\":\"Chart\",\"type\":\"meta\",\"meta_types\":[\"chart\"]},{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\",\"hint\":\"Leave blank to use the chart\\u2019s own.\"},{\"key\":\"height\",\"label\":\"Height\",\"type\":\"select\",\"options\":[\"small\",\"medium\",\"large\"],\"default\":\"medium\"}]', '1', '22', '2026-10-20 15:19:18', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('14', 'datagrid', 'Data grid', 'Data', 'meta', NULL, 'table', 'A grid of records, with its filters and buttons.', '[{\"key\":\"meta_id\",\"label\":\"Grid\",\"type\":\"meta\",\"meta_types\":[\"datagrid\"]}]', '1', '25', '2026-10-20 15:19:18', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('15', 'form', 'Form', 'Data', 'meta', NULL, 'form', 'A form to add a record.', '[{\"key\":\"meta_id\",\"label\":\"Form\",\"type\":\"meta\",\"meta_types\":[\"form\"]}]', '1', '26', '2026-10-20 15:19:18', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('16', 'screen', 'Any screen', 'Data', 'meta', NULL, 'layers', 'Any meta: a calendar, statement, dashboard, tabs…', '[{\"key\":\"meta_id\",\"label\":\"Screen\",\"type\":\"meta\"}]', '1', '28', '2026-10-20 15:19:18', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('17', 'iframe', 'Web page', 'Data', 'iframe', NULL, 'globe', 'Another web page or video, shown in a frame.', '[{\"key\":\"url\",\"label\":\"Address\",\"type\":\"url\"},{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\",\"default\":\"Embedded page\"},{\"key\":\"height\",\"label\":\"Height (px)\",\"type\":\"number\",\"default\":400}]', '1', '29', '2026-10-20 15:19:18', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('18', 'records', 'Record list', 'Data', 'records', NULL, 'list', 'Records from a grid, each drawn by lines you write with {field} placeholders.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"meta_id\",\"label\":\"Records from\",\"type\":\"meta\",\"meta_types\":[\"datagrid\",\"statement\",\"calendar\",\"gantt\"],\"hint\":\"A grid; its filters and permissions apply.\"},{\"key\":\"limit\",\"label\":\"How many\",\"type\":\"number\",\"default\":5},{\"key\":\"sort\",\"label\":\"Order by field\",\"type\":\"text\",\"hint\":\"A field of the grid; blank keeps its usual order.\"},{\"key\":\"dir\",\"label\":\"Order\",\"type\":\"select\",\"options\":[\"desc\",\"asc\"],\"default\":\"desc\"},{\"key\":\"layout\",\"label\":\"Look\",\"type\":\"select\",\"options\":[\"list\",\"cards\",\"table\"],\"default\":\"list\"},{\"key\":\"columns\",\"label\":\"Cards per row\",\"type\":\"select\",\"options\":[\"1\",\"2\",\"3\"],\"default\":\"2\"},{\"key\":\"lines\",\"label\":\"Each record shows\",\"type\":\"list\",\"default\":[{\"template\":\"{name}\",\"style\":\"title\"}],\"fields\":[{\"key\":\"template\",\"label\":\"Text\",\"type\":\"text\",\"hint\":\"e.g. {po_number} \\u00b7 {supplier_name}\"},{\"key\":\"style\",\"label\":\"Style\",\"type\":\"select\",\"options\":[\"title\",\"text\",\"muted\",\"value\",\"badge\"],\"default\":\"text\"},{\"key\":\"label\",\"label\":\"Column heading\",\"type\":\"text\",\"hint\":\"For the table look.\"}]},{\"key\":\"open_meta_id\",\"label\":\"A record opens\",\"type\":\"meta\"},{\"key\":\"open_params\",\"label\":\"Passing\",\"type\":\"text\",\"default\":\"id=id\",\"hint\":\"parameter=field, comma separated.\"},{\"key\":\"empty_text\",\"label\":\"When there are none\",\"type\":\"text\",\"default\":\"Nothing here yet.\"}]', '1', '23', '2026-10-20 15:56:12', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('20', 'photo', 'Wide photo', 'Content', 'photo', NULL, 'image', 'A photograph across the whole width, cropped to a wide frame.', '[{\"key\":\"src\",\"label\":\"Image address\",\"type\":\"image\"},{\"key\":\"alt\",\"label\":\"Description\",\"type\":\"text\",\"hint\":\"Read out to people who can\\u2019t see the image.\"},{\"key\":\"caption\",\"label\":\"Caption\",\"type\":\"text\"},{\"key\":\"ratio\",\"label\":\"Frame\",\"type\":\"select\",\"options\":[\"21:9\",\"16:9\",\"3:2\"],\"default\":\"21:9\"},{\"key\":\"focus\",\"label\":\"Keep in view\",\"type\":\"select\",\"options\":[\"center\",\"top\",\"bottom\",\"left\",\"right\"],\"default\":\"center\",\"hint\":\"The part of the photo the crop keeps.\"}]', '1', '8', '2026-10-28 15:21:02', '2026-10-28 15:21:02')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('21', 'table', 'Table', 'Content', 'table', NULL, 'table', 'Rows and columns you type in: prices, opening hours, sizes.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"subtitle\",\"label\":\"Line under it\",\"type\":\"text\"},{\"key\":\"rows\",\"label\":\"Rows\",\"type\":\"textarea\",\"default\":\"Item | Price\\nFirst row | 0\",\"hint\":\"One row per line, cells split by |. The first line is the heading row.\"},{\"key\":\"note\",\"label\":\"Note under the table\",\"type\":\"text\"}]', '1', '9', '2026-10-28 15:21:02', '2026-10-28 15:21:02')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('22', 'schedule', 'Schedule', 'Data', 'schedule', NULL, 'calendar', 'Times from a grid, each with what is on, the spaces left and a Book button.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"meta_id\",\"label\":\"Times from\",\"type\":\"meta\",\"meta_types\":[\"datagrid\"],\"hint\":\"A grid, one record per time; its filters and permissions apply.\"},{\"key\":\"time_field\",\"label\":\"Starts (field)\",\"type\":\"text\",\"default\":\"starts_at\"},{\"key\":\"end_field\",\"label\":\"Ends (field)\",\"type\":\"text\",\"default\":\"ends_at\"},{\"key\":\"name_field\",\"label\":\"What is on (field)\",\"type\":\"text\",\"default\":\"name\"},{\"key\":\"detail_field\",\"label\":\"Detail (field)\",\"type\":\"text\"},{\"key\":\"spaces_field\",\"label\":\"Spaces left (field)\",\"type\":\"text\",\"hint\":\"At 0 the time shows as full and can\\u2019t be booked.\"},{\"key\":\"limit\",\"label\":\"How many\",\"type\":\"number\",\"default\":8},{\"key\":\"book_meta_id\",\"label\":\"Book opens\",\"type\":\"meta\",\"meta_types\":[\"form\"]},{\"key\":\"book_params\",\"label\":\"Passing\",\"type\":\"text\",\"default\":\"session_id=id\",\"hint\":\"parameter=field, comma separated.\"},{\"key\":\"book_label\",\"label\":\"Button\",\"type\":\"text\",\"default\":\"Book\"},{\"key\":\"empty_text\",\"label\":\"When there are none\",\"type\":\"text\",\"default\":\"Nothing on today.\"}]', '1', '24', '2026-10-28 15:21:02', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('23', 'carousel', 'Carousel', 'Content', 'carousel', NULL, 'slides', 'Pictures one at a time, with Previous, Next and a dot each. Nothing moves by itself.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"ratio\",\"label\":\"Frame\",\"type\":\"select\",\"options\":[\"16:9\",\"21:9\",\"3:2\",\"4:3\",\"1:1\"],\"default\":\"16:9\"},{\"key\":\"slides\",\"label\":\"Slides\",\"type\":\"list\",\"default\":[],\"fields\":[{\"key\":\"src\",\"label\":\"Image address\",\"type\":\"image\"},{\"key\":\"alt\",\"label\":\"Description\",\"type\":\"text\",\"hint\":\"Read out to people who can\\u2019t see the image.\"},{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"text\",\"label\":\"Caption\",\"type\":\"text\"}]}]', '1', '10', '2026-10-28 16:17:14', '2026-10-28 16:17:14')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('24', 'gallery', 'Gallery', 'Content', 'gallery', NULL, 'gallery', 'Pictures on a grid; one opens large when clicked.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"columns\",\"label\":\"Columns\",\"type\":\"select\",\"options\":[\"2\",\"3\",\"4\"],\"default\":\"3\"},{\"key\":\"images\",\"label\":\"Pictures\",\"type\":\"list\",\"default\":[],\"fields\":[{\"key\":\"src\",\"label\":\"Image address\",\"type\":\"image\"},{\"key\":\"alt\",\"label\":\"Description\",\"type\":\"text\",\"hint\":\"Read out to people who can\\u2019t see the image.\"},{\"key\":\"caption\",\"label\":\"Caption\",\"type\":\"text\"}]}]', '1', '11', '2026-10-28 16:17:14', '2026-10-28 16:17:14')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('25', 'accordion', 'Accordion', 'Content', 'accordion', NULL, 'accordion', 'Questions or topics that open one at a time: an FAQ, opening terms.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"items\",\"label\":\"Rows\",\"type\":\"list\",\"default\":[],\"fields\":[{\"key\":\"title\",\"label\":\"Question or topic\",\"type\":\"text\"},{\"key\":\"text\",\"label\":\"Answer\",\"type\":\"textarea\"}]},{\"key\":\"first_open\",\"label\":\"First row starts open\",\"type\":\"boolean\",\"default\":false},{\"key\":\"many\",\"label\":\"Several may be open at once\",\"type\":\"boolean\",\"default\":false}]', '1', '12', '2026-10-28 16:17:14', '2026-10-28 16:17:14')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('26', 'collapse', 'Show more', 'Content', 'collapse', NULL, 'collapse', 'Text hidden under one line until it is opened: small print, the long version.', '[{\"key\":\"label\",\"label\":\"Line that opens it\",\"type\":\"text\",\"default\":\"Show more\"},{\"key\":\"text\",\"label\":\"Text\",\"type\":\"textarea\"},{\"key\":\"open\",\"label\":\"Starts open\",\"type\":\"boolean\",\"default\":false}]', '1', '13', '2026-10-28 16:17:14', '2026-10-28 16:17:14')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('27', 'tabs', 'Tabs', 'Content', 'tabs', NULL, 'tabs', 'A row of tabs, each showing its own text: by season, by audience, by plan.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"items\",\"label\":\"Tabs\",\"type\":\"list\",\"default\":[],\"fields\":[{\"key\":\"label\",\"label\":\"Tab\",\"type\":\"text\"},{\"key\":\"text\",\"label\":\"Text\",\"type\":\"textarea\"}]}]', '1', '14', '2026-10-28 16:17:14', '2026-10-28 16:17:14')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('28', 'quote', 'Quote', 'Content', 'quote', NULL, 'quote', 'Words someone really said, with their name.', '[{\"key\":\"text\",\"label\":\"Quote\",\"type\":\"textarea\"},{\"key\":\"name\",\"label\":\"Who said it\",\"type\":\"text\"},{\"key\":\"role\",\"label\":\"Their role or place\",\"type\":\"text\"}]', '1', '16', '2026-10-28 16:17:14', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('29', 'webform', 'Web form', 'Data', 'webform', NULL, 'send', 'A form drawn in the website’s own look, sent as a new record. Pages in the app show the usual form.', '[{\"key\":\"meta_id\",\"label\":\"Form\",\"type\":\"meta\",\"meta_types\":[\"form\"],\"hint\":\"On a website it must be public.\"},{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\",\"hint\":\"Leave blank to use the form\\u2019s own name.\"},{\"key\":\"subtitle\",\"label\":\"Line under it\",\"type\":\"text\",\"hint\":\"Leave blank to use the form\\u2019s description.\"},{\"key\":\"submit_label\",\"label\":\"Button\",\"type\":\"text\",\"default\":\"Send\"},{\"key\":\"done_text\",\"label\":\"After sending\",\"type\":\"text\",\"default\":\"Thank you. We have it and will be in touch.\"}]', '1', '27', '2026-10-28 16:17:14', '2026-10-31 07:10:36')
-- @@
INSERT INTO `widgets` (`id`, `code`, `name`, `category`, `renderer`, `template_meta_id`, `icon`, `description`, `props`, `is_active`, `order_number`, `created_at`, `updated_at`) VALUES ('30', 'social', 'Social links', 'Content', 'social', NULL, 'share', 'The website\'s Instagram, Facebook and other pages, set under the site\'s Settings.', '[{\"key\":\"title\",\"label\":\"Title\",\"type\":\"text\"},{\"key\":\"labels\",\"label\":\"Show names next to the icons\",\"type\":\"boolean\",\"default\":true},{\"key\":\"align\",\"label\":\"Alignment\",\"type\":\"select\",\"options\":[\"left\",\"center\",\"right\"],\"default\":\"left\"}]', '1', '15', '2026-10-31 07:10:36', '2026-10-31 07:10:36')
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('0001_01_01_000000_create_users_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('0001_01_01_000001_create_cache_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('0001_01_01_000002_create_jobs_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_17_030025_create_personal_access_tokens_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_17_034136_create_metas_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_19_000000_add_parent_id_to_meta_params_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_20_000000_create_procurement_demo_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_21_000000_create_calendar_demo_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_22_000000_create_gantt_demo_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_23_000000_create_proc_purchase_order_lines_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_24_000000_create_lms_demo_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_25_000000_create_meta_actions_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_26_000000_create_workflow_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_26_132207_add_ui_size_to_users_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_27_000000_create_access_and_notification_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_28_000000_create_widgets_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_29_000000_add_template_meta_id_to_widgets_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_30_000000_add_notification_meta_id_to_user_notifications_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_30_002251_add_is_open_to_metas_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_10_31_000000_create_schedules_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_01_000000_create_autonumbers_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_01_141343_add_widget_position_to_meta_params_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_02_000000_create_process_workflow_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_02_103844_add_features_to_roles_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_03_000000_create_meta_versions_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_04_000000_create_proc_order_line_deliveries_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_05_000000_create_tender_demo_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_06_000000_create_meta_map_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_07_000000_add_expressions_to_metas_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_08_000000_create_session_values_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_09_000000_add_service_to_metas_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_10_000000_add_service_list_body_to_metas_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_11_000000_create_tags_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_12_000000_create_eam_demo_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_13_000000_create_eam_finance_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_14_000000_add_sales_and_disposals_to_eam_finance', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_15_000000_add_is_auto_to_meta_versions_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_16_000000_create_proc_supplier_terms_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_17_000000_add_supplier_types_to_proc_suppliers', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_18_000000_add_theme_to_users_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_19_000000_create_languages_and_translations', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_20_000000_create_record_log_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_21_000000_create_contents_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_22_000000_add_show_in_toolbar_to_meta_actions', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_23_000000_create_patches_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_24_000000_add_is_public_to_metas', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_25_000000_create_role_log_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_26_000000_add_is_parent_to_meta_params', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_26_000001_add_parent_to_eam_locations', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_27_000000_add_lookup_indexes_to_metas', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_28_000000_add_email_to_user_notifications', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_28_000001_create_social_accounts_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_29_000000_create_site_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_29_000001_create_web_contact_messages_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_29_000002_create_climbing_demo_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_11_30_000000_create_market_account_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_01_000000_create_market_contract_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_02_000000_create_market_tender_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000000_create_crm_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000001_create_crm_metas', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000002_create_crm_lead_conversion_flow', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000003_create_crm_task_reminders_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000004_create_crm_reports_and_reminders', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000005_fix_crm_task_reminder_link', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000006_merge_crm_services_into_items_with_categories', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000007_create_crm_item_category_metas', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000008_create_cm_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000009_create_cm_metas', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000010_create_crm_customer_types_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000011_create_crm_customer_type_metas', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000012_create_wh_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000013_create_wh_metas', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000014_fix_wh_stock_trigger_and_pick_locations', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000015_create_wand_demo_requests_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000016_create_wand_website_metas', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000017_create_school_site_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000018_create_school_website_metas', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000019_tidy_school_website_events_and_share_images', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000020_add_preferences_to_users_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000021_create_chats_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000022_create_pos_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000023_create_pos_triggers', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000023a_prepare_pos_tag', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000024_create_pos_metas', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000025_create_pos_demo_data', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000026_refine_pos_receipt_and_payments', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000027_create_pay_tables', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000028_create_pay_triggers', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000029_create_pay_metas', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000030_refine_pay_run_register', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000031_create_pay_demo_data', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000032_create_pos_catalogue_grid', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_03_000033_add_data_tables_to_patches_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_04_000002_drop_foreign_keys_to_users', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_04_000003_add_data_to_chat_messages_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_04_000004_add_position_to_meta_process_workflow_table', 1)
-- @@
INSERT INTO `migrations` (`migration`, `batch`) VALUES ('2026_12_04_000005_create_structure_meta_type', 1)
-- @@
SET FOREIGN_KEY_CHECKS = 1
