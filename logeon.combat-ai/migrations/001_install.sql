CREATE TABLE IF NOT EXISTS `combat_ai_profiles` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `conflict_id` INT NOT NULL,
  `character_id` INT NOT NULL,
  `behavior_key` VARCHAR(40) NOT NULL DEFAULT 'opportunist',
  `automation_mode` VARCHAR(40) NOT NULL DEFAULT 'suggest_only',
  `priority_focus` VARCHAR(40) NOT NULL DEFAULT 'balanced',
  `notes` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_combat_ai_profile` (`conflict_id`, `character_id`),
  KEY `idx_combat_ai_conflict` (`conflict_id`),
  KEY `idx_combat_ai_character` (`character_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `combat_ai_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `conflict_id` INT NOT NULL,
  `character_id` INT NOT NULL,
  `action_type` VARCHAR(40) NOT NULL,
  `target_id` INT NULL,
  `suggestion_summary` VARCHAR(255) NOT NULL,
  `source_mode` VARCHAR(40) NOT NULL DEFAULT 'suggest_only',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_combat_ai_logs_conflict` (`conflict_id`),
  KEY `idx_combat_ai_logs_character` (`character_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
