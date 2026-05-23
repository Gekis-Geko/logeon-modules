-- Logeon Advance Maps - install
-- Idempotent patch (MySQL/MariaDB): usa IF NOT EXISTS dove supportato.

ALTER TABLE maps
    ADD COLUMN IF NOT EXISTS map_type VARCHAR(50) NULL AFTER parent_map_id,
    ADD COLUMN IF NOT EXISTS is_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER map_type;

ALTER TABLE maps
    ADD INDEX IF NOT EXISTS idx_maps_is_visible (is_visible),
    ADD INDEX IF NOT EXISTS idx_maps_parent_visible (parent_map_id, is_visible);

CREATE TABLE IF NOT EXISTS map_hotspots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    map_id INT NOT NULL,
    target_type VARCHAR(20) NOT NULL DEFAULT 'location',
    target_id INT NOT NULL,
    label VARCHAR(255) NULL,
    x DECIMAL(6,3) NULL,
    y DECIMAL(6,3) NULL,
    width DECIMAL(6,3) NULL,
    height DECIMAL(6,3) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    date_created TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    date_updated TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_map_hotspots_map_id (map_id),
    INDEX idx_map_hotspots_target (target_type, target_id),
    INDEX idx_map_hotspots_visible (is_visible),
    CONSTRAINT fk_map_hotspots_map
        FOREIGN KEY (map_id) REFERENCES maps(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

