CREATE TABLE IF NOT EXISTS `lf_narrative_presets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(120) NOT NULL,
    `description` TEXT NULL,
    `target_type` ENUM('character','scene') NOT NULL DEFAULT 'character',
    `category_label` VARCHAR(80) NOT NULL DEFAULT '',
    `visible_to_players` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 100,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lf_narrative_presets_slug` (`slug`),
    KEY `idx_lf_narrative_presets_active_order` (`is_active`, `sort_order`, `name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lf_narrative_preset_states` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `preset_id` INT UNSIGNED NOT NULL,
    `state_id` INT UNSIGNED NOT NULL,
    `effect_mode` ENUM('apply','remove') NOT NULL DEFAULT 'apply',
    `intensity` DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    `duration_value` INT UNSIGNED NULL,
    `duration_unit` ENUM('turn','minute','hour','day','scene') NULL DEFAULT 'scene',
    `sort_order` INT NOT NULL DEFAULT 100,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lf_narrative_preset_states_unique` (`preset_id`, `state_id`, `effect_mode`),
    KEY `idx_lf_narrative_preset_states_preset` (`preset_id`, `sort_order`),
    KEY `idx_lf_narrative_preset_states_state` (`state_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `lf_narrative_character_presets` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `character_id` INT UNSIGNED NOT NULL,
    `preset_id` INT UNSIGNED NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 100,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lf_narrative_character_presets_unique` (`character_id`, `preset_id`),
    KEY `idx_lf_narrative_character_presets_character` (`character_id`, `is_active`, `sort_order`),
    KEY `idx_lf_narrative_character_presets_preset` (`preset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
