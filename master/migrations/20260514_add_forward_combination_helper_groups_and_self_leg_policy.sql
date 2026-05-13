ALTER TABLE forward_combinations
    ADD COLUMN runtime_preference VARCHAR(64) NOT NULL DEFAULT 'single' AFTER client_mode,
    ADD COLUMN allow_private_self_leg TINYINT(1) NOT NULL DEFAULT 0 AFTER runtime_preference,
    ADD COLUMN self_leg_scope VARCHAR(64) NOT NULL DEFAULT 'none' AFTER allow_private_self_leg,
    ADD COLUMN self_leg_role VARCHAR(64) NOT NULL DEFAULT 'none' AFTER self_leg_scope,
    ADD COLUMN helper_group_cap INT NOT NULL DEFAULT 0 AFTER self_leg_role,
    ADD COLUMN helper_escalation_policy VARCHAR(64) NOT NULL DEFAULT 'disabled' AFTER helper_group_cap;

CREATE TABLE IF NOT EXISTS forward_combination_helper_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    node_group_key VARCHAR(191) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forward_combination_helper_groups_combo
        FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE,
    INDEX idx_forward_combination_helper_groups_combo (forward_combination_id, sort_order),
    INDEX idx_forward_combination_helper_groups_group (node_group_key)
);
