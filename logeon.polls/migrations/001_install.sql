CREATE TABLE IF NOT EXISTS `polls` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(160) NOT NULL,
    `description` TEXT NULL,
    `status` ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
    `visibility` ENUM('public','player_only','staff_only') NOT NULL DEFAULT 'player_only',
    `created_by` INT UNSIGNED NULL,
    `opens_at` DATETIME NULL,
    `closes_at` DATETIME NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_polls_status_visibility` (`status`, `visibility`),
    KEY `idx_polls_dates` (`opens_at`, `closes_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_options` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `poll_id` INT UNSIGNED NOT NULL,
    `label` VARCHAR(160) NOT NULL,
    `order_index` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_poll_option_order` (`poll_id`, `order_index`),
    KEY `idx_poll_options_poll` (`poll_id`),
    CONSTRAINT `fk_poll_options_poll`
        FOREIGN KEY (`poll_id`) REFERENCES `polls` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_votes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `poll_id` INT UNSIGNED NOT NULL,
    `option_id` INT UNSIGNED NOT NULL,
    `voter_user_id` INT UNSIGNED NOT NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_poll_votes_poll_voter` (`poll_id`, `voter_user_id`),
    KEY `idx_poll_votes_option` (`option_id`),
    CONSTRAINT `fk_poll_votes_poll`
        FOREIGN KEY (`poll_id`) REFERENCES `polls` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_poll_votes_option`
        FOREIGN KEY (`option_id`) REFERENCES `poll_options` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
