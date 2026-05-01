CREATE TABLE bond_leg_reputation_current (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    observer_type VARCHAR(32) NOT NULL,
    observer_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    node_id BIGINT UNSIGNED NULL,
    host VARCHAR(255) NOT NULL,
    state VARCHAR(32) NOT NULL,
    score DECIMAL(10,4) NOT NULL DEFAULT 0,
    effective_weight DECIMAL(10,4) NOT NULL DEFAULT 0,
    delivered_ewma DECIMAL(18,4) NOT NULL DEFAULT 0,
    penalty_ewma DECIMAL(18,4) NOT NULL DEFAULT 0,
    last_reason TEXT NULL,
    last_good_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_current_leg (observer_type, observer_id, forward_id, endpoint_id, protocol),
    INDEX idx_bond_leg_reputation_current_state (state, updated_at),
    INDEX idx_bond_leg_reputation_current_forward (forward_id, protocol)
);

CREATE TABLE bond_leg_reputation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    observer_type VARCHAR(32) NOT NULL,
    observer_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    host VARCHAR(255) NOT NULL,
    from_state VARCHAR(32) NULL,
    to_state VARCHAR(32) NOT NULL,
    score DECIMAL(10,4) NOT NULL DEFAULT 0,
    effective_weight DECIMAL(10,4) NOT NULL DEFAULT 0,
    reason TEXT NULL,
    observed_at DATETIME NOT NULL,
    INDEX idx_bond_leg_reputation_events_observed (observed_at),
    INDEX idx_bond_leg_reputation_events_forward (forward_id, protocol)
);
