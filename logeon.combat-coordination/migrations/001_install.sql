CREATE TABLE IF NOT EXISTS `combat_coordination_plans` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `conflict_id` INT NOT NULL,
  `leader_id` INT NOT NULL,
  `team_key` VARCHAR(40) NOT NULL DEFAULT 'side_a',
  `maneuver_key` VARCHAR(40) NOT NULL,
  `required_action_type` VARCHAR(40) NOT NULL,
  `primary_target_id` INT NULL,
  `supporter_ids_json` LONGTEXT NULL,
  `bonus_scale` INT NOT NULL DEFAULT 1,
  `notes` TEXT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'active',
  `created_by` INT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `consumed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ccp_conflict` (`conflict_id`),
  KEY `idx_ccp_leader` (`leader_id`),
  KEY `idx_ccp_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `combat_coordination_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `plan_id` INT NOT NULL,
  `conflict_id` INT NOT NULL,
  `actor_id` INT NULL,
  `log_type` VARCHAR(40) NOT NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ccl_plan` (`plan_id`),
  KEY `idx_ccl_conflict` (`conflict_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
