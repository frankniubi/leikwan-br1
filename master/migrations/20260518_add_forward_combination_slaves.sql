CREATE TABLE IF NOT EXISTS forward_combination_slaves (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    selection_kind VARCHAR(32) NOT NULL DEFAULT 'admin_pin',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fc_slaves_combo
      FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_slaves_node
      FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_fc_slave_node (forward_combination_id, node_id, selection_kind),
    INDEX idx_fc_slaves_combo (forward_combination_id, is_active, sort_order),
    INDEX idx_fc_slaves_node (node_id, is_active)
);
