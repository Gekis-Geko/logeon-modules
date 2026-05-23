CREATE TABLE IF NOT EXISTS `lf_archetype_attribute_rules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `archetype_id` INT UNSIGNED NOT NULL,
    `attribute_id` INT UNSIGNED NOT NULL,
    `rule_type` VARCHAR(40) NOT NULL,
    `value` VARCHAR(255) NOT NULL DEFAULT '',
    `is_enforced` TINYINT(1) NOT NULL DEFAULT 0,
    `priority` INT NOT NULL DEFAULT 100,
    `notes` TEXT NULL,
    `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_lf_aar_archetype` (`archetype_id`),
    KEY `idx_lf_aar_attribute` (`attribute_id`),
    KEY `idx_lf_aar_type` (`rule_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
