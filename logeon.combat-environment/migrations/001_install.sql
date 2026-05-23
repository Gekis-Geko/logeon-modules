CREATE TABLE IF NOT EXISTS `combat_environment_features` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `conflict_id` INT NOT NULL,
  `zone_key` VARCHAR(80) NULL,
  `feature_name` VARCHAR(160) NOT NULL,
  `feature_type` VARCHAR(40) NOT NULL DEFAULT 'utility',
  `state_key` VARCHAR(40) NOT NULL DEFAULT 'active',
  `control_side_key` VARCHAR(40) NULL,
  `description` TEXT NULL,
  `visibility_impact` INT NOT NULL DEFAULT 0,
  `mobility_impact` INT NOT NULL DEFAULT 0,
  `hazard_impact` INT NOT NULL DEFAULT 0,
  `cover_impact` INT NOT NULL DEFAULT 0,
  `affordance_tags_json` LONGTEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cef_conflict` (`conflict_id`),
  KEY `idx_cef_type` (`feature_type`),
  KEY `idx_cef_state` (`state_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `combat_environment_feature_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `feature_id` INT NOT NULL,
  `conflict_id` INT NOT NULL,
  `actor_character_id` INT NULL,
  `action_key` VARCHAR(40) NOT NULL,
  `old_state_key` VARCHAR(40) NULL,
  `new_state_key` VARCHAR(40) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cefl_feature` (`feature_id`),
  KEY `idx_cefl_conflict` (`conflict_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
