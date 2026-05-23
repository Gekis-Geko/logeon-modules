CREATE TABLE IF NOT EXISTS `economy_effects` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `description` TEXT NULL,
    `effect_type` ENUM('price_percent_modifier','price_flat_modifier','availability_override','stock_limit','faction_access','faction_price_modifier','label') NOT NULL,
    `scope_type` ENUM('global','area','shop','faction','event') NOT NULL DEFAULT 'global',
    `target_type` ENUM('all','item','category') NOT NULL DEFAULT 'all',
    `target_ref_id` INT UNSIGNED NULL,
    `priority` INT NOT NULL DEFAULT 100,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `visible_to_players` TINYINT(1) NOT NULL DEFAULT 1,
    `price_percent_value` INT NOT NULL DEFAULT 0,
    `price_flat_value` INT NOT NULL DEFAULT 0,
    `availability_mode` ENUM('default','available','unavailable') NOT NULL DEFAULT 'default',
    `stock_limit_value` INT UNSIGNED NULL,
    `faction_access_mode` ENUM('default','allowed','blocked') NOT NULL DEFAULT 'default',
    `faction_price_percent_value` INT NOT NULL DEFAULT 0,
    `label_text` VARCHAR(255) NULL,
    `start_at` DATETIME NULL,
    `end_at` DATETIME NULL,
    `meta_json` LONGTEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `updated_by` INT UNSIGNED NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_economy_effects_active_scope` (`is_active`, `scope_type`, `priority`),
    KEY `idx_economy_effects_type_target` (`effect_type`, `target_type`, `target_ref_id`),
    KEY `idx_economy_effects_dates` (`start_at`, `end_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `economy_effect_links` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `effect_id` INT UNSIGNED NOT NULL,
    `entity_type` ENUM('area','shop','faction','event') NOT NULL,
    `entity_id` INT UNSIGNED NOT NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_economy_effect_link` (`effect_id`, `entity_type`, `entity_id`),
    KEY `idx_economy_effect_link_lookup` (`entity_type`, `entity_id`),
    CONSTRAINT `fk_economy_effect_links_effect`
        FOREIGN KEY (`effect_id`) REFERENCES `economy_effects` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
