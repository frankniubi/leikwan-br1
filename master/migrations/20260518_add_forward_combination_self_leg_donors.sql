CREATE TABLE IF NOT EXISTS forward_combination_self_leg_donors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    desktop_client_session_id BIGINT UNSIGNED NULL,
    device_id VARCHAR(191) NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'qualifying'
        COMMENT 'self_leg_donor_identity_table: qualifying | active | revoked | cooldown',
    donor_public_host VARCHAR(255) NULL,
    donor_public_port INT NULL,
    donor_transport VARCHAR(32) NOT NULL DEFAULT 'tcp',
    last_measured_latency_ms DECIMAL(10,2) NULL,
    qualify_started_at DATETIME NULL,
    activated_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoke_reason VARCHAR(64) NULL,
    open_ports_json TEXT NULL,
    last_self_check_json JSON NULL,
    last_reverse_probe_json JSON NULL,
    last_heartbeat_at DATETIME NULL,
    owner_opt_in TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fc_self_leg_donors_combo
      FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_self_leg_donors_client
      FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_self_leg_donors_desktop_session
      FOREIGN KEY (desktop_client_session_id) REFERENCES desktop_client_sessions(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_fc_self_leg_donor_session (forward_combination_id, client_id, desktop_client_session_id, device_id),
    INDEX idx_fc_self_leg_donors_state (forward_combination_id, state, updated_at),
    INDEX idx_fc_self_leg_donors_client (client_id, state),
    INDEX idx_fc_self_leg_donors_endpoint (donor_public_host, donor_public_port)
);
