CREATE TABLE IF NOT EXISTS `production_professions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(80) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_production_professions_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `production_profession_links` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `profession_id` INT UNSIGNED NOT NULL,
    `character_id` INT UNSIGNED NOT NULL,
    `assigned_by_user_id` INT UNSIGNED NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_production_profession_link` (`profession_id`, `character_id`),
    KEY `idx_production_profession_character` (`character_id`),
    CONSTRAINT `fk_production_profession_links_profession`
        FOREIGN KEY (`profession_id`) REFERENCES `production_professions` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `production_processes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `description` TEXT NULL,
    `process_type` ENUM('gather','refinement','assembly','cooking_alchemy','repair','salvage','conversion','label') NOT NULL,
    `category` VARCHAR(80) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `visibility` ENUM('public','hidden','restricted') NOT NULL DEFAULT 'public',
    `station_type` VARCHAR(80) NULL,
    `duration_type` ENUM('instant','delayed') NOT NULL DEFAULT 'instant',
    `duration_value` INT UNSIGNED NULL,
    `metadata_json` LONGTEXT NULL,
    `created_by_user_id` INT UNSIGNED NULL,
    `updated_by_user_id` INT UNSIGNED NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_production_processes_active` (`is_active`, `visibility`, `process_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `production_process_inputs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `process_id` INT UNSIGNED NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `consume_mode` ENUM('consume','keep') NOT NULL DEFAULT 'consume',
    `notes` VARCHAR(255) NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 100,
    PRIMARY KEY (`id`),
    KEY `idx_production_process_inputs_process` (`process_id`),
    CONSTRAINT `fk_production_process_inputs_process`
        FOREIGN KEY (`process_id`) REFERENCES `production_processes` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `production_process_outputs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `process_id` INT UNSIGNED NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `output_mode` ENUM('create','transform','recover') NOT NULL DEFAULT 'create',
    `notes` VARCHAR(255) NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 100,
    PRIMARY KEY (`id`),
    KEY `idx_production_process_outputs_process` (`process_id`),
    CONSTRAINT `fk_production_process_outputs_process`
        FOREIGN KEY (`process_id`) REFERENCES `production_processes` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `production_process_requirements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `process_id` INT UNSIGNED NOT NULL,
    `requirement_type` ENUM('profession','faction','area','event','item','station') NOT NULL,
    `operator` ENUM('required','allowed','blocked') NOT NULL DEFAULT 'required',
    `requirement_value` VARCHAR(120) NOT NULL,
    `notes` VARCHAR(255) NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 100,
    PRIMARY KEY (`id`),
    KEY `idx_production_process_requirements_process` (`process_id`),
    CONSTRAINT `fk_production_process_requirements_process`
        FOREIGN KEY (`process_id`) REFERENCES `production_processes` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `production_sources` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `source_type` ENUM('shop','event','faction','area','salvage','admin') NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `visibility` ENUM('public','hidden','restricted') NOT NULL DEFAULT 'public',
    `scope_type` ENUM('global','area','faction','event') NOT NULL DEFAULT 'global',
    `scope_ref_id` INT UNSIGNED NULL,
    `metadata_json` LONGTEXT NULL,
    `created_by_user_id` INT UNSIGNED NULL,
    `updated_by_user_id` INT UNSIGNED NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_production_sources_active` (`is_active`, `visibility`, `scope_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `production_source_items` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `source_id` INT UNSIGNED NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
    `acquisition_mode` ENUM('grant','drop','recover','unlock') NOT NULL DEFAULT 'grant',
    `notes` VARCHAR(255) NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 100,
    PRIMARY KEY (`id`),
    KEY `idx_production_source_items_source` (`source_id`),
    CONSTRAINT `fk_production_source_items_source`
        FOREIGN KEY (`source_id`) REFERENCES `production_sources` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `production_jobs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `process_id` INT UNSIGNED NOT NULL,
    `character_id` INT UNSIGNED NOT NULL,
    `faction_id` INT UNSIGNED NULL,
    `status` ENUM('started','completed','cancelled') NOT NULL DEFAULT 'completed',
    `started_at` DATETIME NOT NULL,
    `completed_at` DATETIME NULL,
    `context_snapshot` LONGTEXT NULL,
    `result_snapshot` LONGTEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_production_jobs_process` (`process_id`, `status`, `started_at`),
    KEY `idx_production_jobs_character` (`character_id`, `started_at`),
    CONSTRAINT `fk_production_jobs_process`
        FOREIGN KEY (`process_id`) REFERENCES `production_processes` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
