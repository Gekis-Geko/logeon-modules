ALTER TABLE `lf_advanced_items_profiles`
    ADD COLUMN `narrative_state_id` INT UNSIGNED NULL AFTER `restore_amount`,
    ADD COLUMN `narrative_state_threshold` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `narrative_state_id`,
    ADD COLUMN `state_intensity` DECIMAL(8,2) NOT NULL DEFAULT 1.00 AFTER `narrative_state_threshold`,
    ADD COLUMN `state_duration_value` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `state_intensity`,
    ADD COLUMN `state_duration_unit` ENUM('scene','turn','minute','hour','day') NOT NULL DEFAULT 'scene' AFTER `state_duration_value`,
    ADD KEY `idx_lf_advanced_items_profiles_narrative_state` (`narrative_state_id`);

ALTER TABLE `lf_advanced_items_character_items`
    ADD COLUMN `use_counter` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `ammo_current`;
