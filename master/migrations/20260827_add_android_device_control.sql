CREATE TABLE IF NOT EXISTS client_device_controls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    identity_key_hash CHAR(64) NOT NULL,
    attestation_key_hash CHAR(64) NULL,
    state ENUM('active', 'kicked', 'banned') NOT NULL DEFAULT 'active',
    trust_state ENUM('trusted', 'untrusted') NOT NULL DEFAULT 'untrusted',
    trust_reason VARCHAR(64) NULL,
    command_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_acknowledged_command_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_attested_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_client_device_controls_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_client_device_control (client_id, device_id),
    INDEX idx_client_device_control_state (client_id, state, last_seen_at)
);

CREATE TABLE IF NOT EXISTS android_device_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    session_hash CHAR(64) NOT NULL,
    command_version BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_android_device_sessions_control FOREIGN KEY (client_id, device_id)
        REFERENCES client_device_controls(client_id, device_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_android_device_session_hash (session_hash),
    INDEX idx_android_device_session_active (client_id, device_id, expires_at)
);

CREATE TABLE IF NOT EXISTS android_device_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    nonce_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_android_device_challenges_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_android_device_challenge_nonce (nonce_hash),
    INDEX idx_android_device_challenge_expiry (expires_at)
);

CREATE TABLE IF NOT EXISTS android_enrollment_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_android_enrollment_codes_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_android_enrollment_code_hash (code_hash),
    INDEX idx_android_enrollment_code_expiry (expires_at)
);

CREATE TABLE IF NOT EXISTS client_device_control_audits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    command_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    event_type VARCHAR(64) NOT NULL,
    reason VARCHAR(255) NULL,
    actor_type VARCHAR(32) NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_client_device_control_audits_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_client_device_control_audit_device (client_id, device_id, created_at)
);
