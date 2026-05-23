CREATE TABLE IF NOT EXISTS `lf_abilities_spells_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(120) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 100,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lf_abilities_spells_category_slug` (`slug`),
    KEY `idx_lf_abilities_spells_category_active_order` (`is_active`, `sort_order`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lf_abilities_spells_point_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(120) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 100,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lf_abilities_spells_point_category_slug` (`slug`),
    KEY `idx_lf_abilities_spells_point_category_active_order` (`is_active`, `sort_order`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lf_abilities_spells_grants` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ability_id` INT UNSIGNED NOT NULL,
    `source_type` VARCHAR(50) NOT NULL DEFAULT 'character',
    `source_id` INT UNSIGNED NOT NULL,
    `grant_mode` VARCHAR(50) NOT NULL DEFAULT 'unlock',
    `retention_policy` VARCHAR(50) NOT NULL DEFAULT 'keep_when_lost',
    `min_rank` INT UNSIGNED NULL,
    `max_rank` INT UNSIGNED NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `priority` INT NOT NULL DEFAULT 100,
    `metadata_json` LONGTEXT NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_lf_abilities_spells_grants_ability` (`ability_id`, `is_active`, `priority`),
    KEY `idx_lf_abilities_spells_grants_source` (`source_type`, `source_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lf_abilities_spells_level_rules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ability_id` INT UNSIGNED NOT NULL,
    `level` INT UNSIGNED NOT NULL DEFAULT 1,
    `points_required` INT UNSIGNED NOT NULL DEFAULT 0,
    `min_rank` INT UNSIGNED NULL,
    `requires_staff_approval` TINYINT(1) NOT NULL DEFAULT 0,
    `metadata_json` LONGTEXT NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lf_abilities_spells_level_rule` (`ability_id`, `level`),
    KEY `idx_lf_abilities_spells_level_rule_ability` (`ability_id`, `level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lf_abilities_spells_requirements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ability_id` INT UNSIGNED NOT NULL,
    `level` INT UNSIGNED NOT NULL DEFAULT 1,
    `requirement_type` VARCHAR(50) NOT NULL,
    `requirement_key` VARCHAR(120) NOT NULL DEFAULT '',
    `operator` VARCHAR(8) NOT NULL DEFAULT '>=',
    `required_value` VARCHAR(255) NOT NULL DEFAULT '',
    `policy_when_unavailable` VARCHAR(20) NOT NULL DEFAULT 'block',
    `is_hidden` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `metadata_json` LONGTEXT NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_lf_abilities_spells_requirements_ability` (`ability_id`, `level`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lf_abilities_spells_effects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ability_id` INT UNSIGNED NOT NULL,
    `level` INT UNSIGNED NOT NULL DEFAULT 1,
    `effect_type` VARCHAR(50) NOT NULL DEFAULT 'modifier',
    `target_system` VARCHAR(80) NOT NULL DEFAULT '',
    `target_key` VARCHAR(120) NOT NULL DEFAULT '',
    `operation` VARCHAR(20) NOT NULL DEFAULT 'add',
    `value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `activation_policy` VARCHAR(50) NOT NULL DEFAULT 'while_ability_usable',
    `policy_when_unavailable` VARCHAR(20) NOT NULL DEFAULT 'ignore',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `metadata_json` LONGTEXT NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_lf_abilities_spells_effects_ability` (`ability_id`, `level`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lf_abilities_spells_character_points` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `character_id` INT UNSIGNED NOT NULL,
    `point_category_id` INT UNSIGNED NOT NULL,
    `available_points` INT NOT NULL DEFAULT 0,
    `spent_points` INT NOT NULL DEFAULT 0,
    `lifetime_points` INT NOT NULL DEFAULT 0,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lf_abilities_spells_character_points` (`character_id`, `point_category_id`),
    KEY `idx_lf_abilities_spells_character_points_character` (`character_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lf_abilities_spells_character_point_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `character_id` INT UNSIGNED NOT NULL,
    `point_category_id` INT UNSIGNED NOT NULL,
    `delta` INT NOT NULL DEFAULT 0,
    `reason` VARCHAR(50) NOT NULL DEFAULT 'staff_grant',
    `reference_type` VARCHAR(50) NULL,
    `reference_id` INT UNSIGNED NULL,
    `created_by_user_id` INT UNSIGNED NULL,
    `note` VARCHAR(255) NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lf_abilities_spells_point_logs_character` (`character_id`, `point_category_id`, `date_created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lf_abilities_spells_rank_point_rewards` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rank` INT UNSIGNED NOT NULL,
    `point_category_id` INT UNSIGNED NOT NULL,
    `points` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lf_abilities_spells_rank_reward` (`rank`, `point_category_id`),
    KEY `idx_lf_abilities_spells_rank_reward_rank` (`rank`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `lf_abilities_spells_abilities`
    ADD COLUMN  `type` VARCHAR(50) NOT NULL DEFAULT 'ability' AFTER `description`;

ALTER TABLE `lf_abilities_spells_abilities`
    ADD COLUMN  `category_id` INT UNSIGNED NULL AFTER `type`;

ALTER TABLE `lf_abilities_spells_abilities`
    ADD COLUMN  `point_category_id` INT UNSIGNED NULL AFTER `category_id`;

ALTER TABLE `lf_abilities_spells_abilities`
    ADD COLUMN  `is_public` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;

ALTER TABLE `lf_abilities_spells_abilities`
    ADD COLUMN  `is_hidden_when_locked` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_public`;

ALTER TABLE `lf_abilities_spells_abilities`
    ADD COLUMN  `requires_learning` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_hidden_when_locked`;

ALTER TABLE `lf_abilities_spells_abilities`
    ADD COLUMN  `requires_staff_approval` TINYINT(1) NOT NULL DEFAULT 0 AFTER `requires_learning`;

ALTER TABLE `lf_abilities_spells_abilities`
    ADD COLUMN  `max_level` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `requires_staff_approval`;

ALTER TABLE `lf_abilities_spells_abilities`
    ADD COLUMN  `metadata_json` LONGTEXT NULL AFTER `max_level`;

ALTER TABLE `lf_abilities_spells_character_abilities`
    ADD COLUMN  `status` VARCHAR(30) NOT NULL DEFAULT 'learned' AFTER `ability_id`;

ALTER TABLE `lf_abilities_spells_character_abilities`
    ADD COLUMN  `level` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `status`;

ALTER TABLE `lf_abilities_spells_character_abilities`
    ADD COLUMN  `pending_points` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `level`;

ALTER TABLE `lf_abilities_spells_character_abilities`
    ADD COLUMN  `spent_points` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `pending_points`;

ALTER TABLE `lf_abilities_spells_character_abilities`
    ADD COLUMN  `approval_status` VARCHAR(30) NOT NULL DEFAULT 'approved' AFTER `spent_points`;

ALTER TABLE `lf_abilities_spells_character_abilities`
    ADD COLUMN  `approved_by_user_id` INT UNSIGNED NULL AFTER `approval_status`;

ALTER TABLE `lf_abilities_spells_character_abilities`
    ADD COLUMN  `approved_at` TIMESTAMP NULL DEFAULT NULL AFTER `approved_by_user_id`;

ALTER TABLE `lf_abilities_spells_character_abilities`
    ADD COLUMN  `suspended_reason` VARCHAR(120) NULL AFTER `approved_at`;

ALTER TABLE `lf_abilities_spells_character_abilities`
    ADD COLUMN  `metadata_json` LONGTEXT NULL AFTER `suspended_reason`;
