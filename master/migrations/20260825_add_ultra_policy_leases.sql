CREATE TABLE IF NOT EXISTS ultra_client_leases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_hash CHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    policy_generation CHAR(64) NOT NULL,
    nonce_hash CHAR(64) NOT NULL,
    lease_hash CHAR(64) NOT NULL,
    quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    issued_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_ultra_lease_nonce (client_id, device_hash, forward_id, nonce_hash),
    INDEX idx_ultra_lease_expiry (forward_id, expires_at),
    INDEX idx_ultra_lease_generation (policy_generation, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ultra_usage_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_hash CHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    policy_generation CHAR(64) NOT NULL,
    sequence_no BIGINT UNSIGNED NOT NULL,
    bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_ultra_usage_sequence (client_id, device_hash, forward_id, sequence_no),
    INDEX idx_ultra_usage_forward (forward_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
