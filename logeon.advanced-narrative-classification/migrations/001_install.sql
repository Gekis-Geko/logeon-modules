CREATE TABLE IF NOT EXISTS `anc_taxonomies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(80) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_anc_taxonomies_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `anc_taxonomy_nodes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `taxonomy_id` INT UNSIGNED NOT NULL,
    `parent_id` INT UNSIGNED NULL,
    `slug` VARCHAR(80) NOT NULL,
    `label` VARCHAR(120) NOT NULL,
    `description` VARCHAR(255) NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_anc_taxonomy_nodes_taxonomy_slug` (`taxonomy_id`, `slug`),
    KEY `idx_anc_taxonomy_nodes_parent` (`parent_id`),
    KEY `idx_anc_taxonomy_nodes_active` (`taxonomy_id`, `is_active`, `sort_order`),
    CONSTRAINT `fk_anc_taxonomy_nodes_taxonomy`
        FOREIGN KEY (`taxonomy_id`) REFERENCES `anc_taxonomies` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_anc_taxonomy_nodes_parent`
        FOREIGN KEY (`parent_id`) REFERENCES `anc_taxonomy_nodes` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `anc_tag_aliases` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tag_id` INT UNSIGNED NOT NULL,
    `alias` VARCHAR(120) NOT NULL,
    `normalized_alias` VARCHAR(120) NOT NULL,
    `notes` VARCHAR(255) NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `date_updated` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_anc_tag_aliases_normalized` (`normalized_alias`),
    KEY `idx_anc_tag_aliases_tag` (`tag_id`, `is_active`),
    CONSTRAINT `fk_anc_tag_aliases_tag`
        FOREIGN KEY (`tag_id`) REFERENCES `narrative_tags` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `anc_tag_node_links` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tag_id` INT UNSIGNED NOT NULL,
    `node_id` INT UNSIGNED NOT NULL,
    `date_created` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_anc_tag_node_links` (`tag_id`, `node_id`),
    KEY `idx_anc_tag_node_links_node` (`node_id`),
    CONSTRAINT `fk_anc_tag_node_links_tag`
        FOREIGN KEY (`tag_id`) REFERENCES `narrative_tags` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_anc_tag_node_links_node`
        FOREIGN KEY (`node_id`) REFERENCES `anc_taxonomy_nodes` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
