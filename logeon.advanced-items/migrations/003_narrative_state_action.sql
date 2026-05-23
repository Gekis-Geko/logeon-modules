ALTER TABLE `lf_advanced_items_profiles`
    ADD COLUMN `narrative_state_action` ENUM('apply','remove') NOT NULL DEFAULT 'apply' AFTER `narrative_state_threshold`;
