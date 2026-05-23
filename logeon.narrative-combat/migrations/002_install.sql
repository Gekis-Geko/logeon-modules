CREATE TABLE IF NOT EXISTS `combat_guard_relations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conflict_id` INT UNSIGNED NOT NULL,
    `guardian_id` INT UNSIGNED NOT NULL,
    `protected_id` INT UNSIGNED NOT NULL,
    `stamina_upkeep` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_combat_guard_conflict_guardian` (`conflict_id`, `guardian_id`, `is_active`),
    KEY `idx_combat_guard_conflict_protected` (`conflict_id`, `protected_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `combat_environment_contexts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `conflict_id` INT UNSIGNED NOT NULL,
    `visibility_level` TINYINT UNSIGNED NOT NULL DEFAULT 10,
    `mobility_level` TINYINT UNSIGNED NOT NULL DEFAULT 10,
    `hazard_level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `cover_density` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_combat_environment_conflict` (`conflict_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
