CREATE TABLE IF NOT EXISTS `lf_abilities_spells_abilities` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(120) NOT NULL,
    `description` TEXT NULL,
    `target_type` ENUM('self','scene') NOT NULL DEFAULT 'self',
    `effect_mode` ENUM('none','apply_state','remove_state') NOT NULL DEFAULT 'none',
    `narrative_state_id` INT UNSIGNED NULL,
    `cooldown_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
    `sort_order` INT NOT NULL DEFAULT 100,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lf_abilities_spells_slug` (`slug`),
    KEY `idx_lf_abilities_spells_state` (`narrative_state_id`),
    KEY `idx_lf_abilities_spells_active_order` (`is_active`, `sort_order`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lf_abilities_spells_character_abilities` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `character_id` INT UNSIGNED NOT NULL,
    `ability_id` INT UNSIGNED NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 100,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lf_abilities_spells_character_ability` (`character_id`, `ability_id`),
    KEY `idx_lf_abilities_spells_character` (`character_id`, `is_active`, `sort_order`),
    KEY `idx_lf_abilities_spells_assignment_ability` (`ability_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
