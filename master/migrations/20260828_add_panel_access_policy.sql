ALTER TABLE service_plans
    ADD COLUMN geo_lock_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_tenant_visible;

ALTER TABLE client_device_controls
    ADD COLUMN blocked_until DATETIME NULL AFTER state;

ALTER TABLE client_device_controls
    ADD COLUMN block_origin VARCHAR(32) NULL AFTER blocked_until;

ALTER TABLE client_device_controls
    ADD COLUMN release_policy VARCHAR(32) NULL AFTER block_origin;

CREATE TABLE IF NOT EXISTS access_blacklist_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(32) NOT NULL,
    canonical_value VARCHAR(255) NOT NULL,
    display_value VARCHAR(255) NOT NULL,
    ip_family TINYINT NULL,
    ip_start VARBINARY(16) NULL,
    ip_end VARBINARY(16) NULL,
    province_code VARCHAR(32) NULL,
    city_code VARCHAR(64) NULL,
    country_code VARCHAR(32) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    reason_code VARCHAR(64) NOT NULL,
    admin_note VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_access_blacklist_entity (entity_type, canonical_value),
    INDEX idx_access_blacklist_active_type (entity_type, is_active),
    INDEX idx_access_blacklist_ip (ip_family, ip_start, ip_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_geo_lock_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'unbound',
    main_province_code VARCHAR(32) NULL,
    previous_province_code VARCHAR(32) NULL,
    bound_observation_id BIGINT UNSIGNED NULL,
    last_verified_at DATETIME NULL,
    self_change_available_at DATETIME NULL,
    reset_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reset_started_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_user_geo_lock_profile_user (user_id),
    INDEX idx_user_geo_lock_profile_state (state, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_location_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    auth_context_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    device_uuid VARCHAR(191) NOT NULL,
    attempt_id VARCHAR(128) NOT NULL,
    platform VARCHAR(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_access_location_challenge_token (token_hash),
    INDEX idx_access_location_challenge_binding (user_id, device_uuid, attempt_id),
    INDEX idx_access_location_challenge_expiry (expires_at, consumed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_location_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    challenge_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    device_uuid VARCHAR(191) NOT NULL,
    attempt_id VARCHAR(128) NOT NULL,
    platform VARCHAR(32) NOT NULL,
    collection_mode VARCHAR(32) NOT NULL,
    provider VARCHAR(64) NOT NULL,
    source_ip VARCHAR(64) NULL,
    public_ip VARCHAR(64) NULL,
    country_code VARCHAR(32) NULL,
    province_code VARCHAR(32) NULL,
    city_code VARCHAR(64) NULL,
    observed_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL,
    payload_digest CHAR(64) NOT NULL,
    INDEX idx_access_location_observation_lookup (user_id, device_uuid, source_ip, observed_at),
    INDEX idx_access_location_observation_attempt (user_id, attempt_id, collection_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_policy_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    decision_id CHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    device_uuid VARCHAR(191) NULL,
    source_ip VARCHAR(64) NULL,
    event_type VARCHAR(32) NOT NULL,
    decision VARCHAR(24) NOT NULL,
    reason_code VARCHAR(64) NOT NULL,
    matched_entity_type VARCHAR(32) NULL,
    matched_observation_id BIGINT UNSIGNED NULL,
    attempt_id VARCHAR(128) NULL,
    counts_as_geo_violation TINYINT(1) NOT NULL DEFAULT 0,
    details_digest CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_access_policy_attempt (user_id, attempt_id, event_type),
    UNIQUE KEY uniq_access_policy_decision (decision_id),
    INDEX idx_access_policy_user_time (user_id, created_at, counts_as_geo_violation),
    INDEX idx_access_policy_device_time (device_uuid, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_access_bans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    origin VARCHAR(32) NOT NULL,
    reason_code VARCHAR(64) NOT NULL,
    evidence_event_ids_json JSON NOT NULL,
    banned_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    released_by_user_id BIGINT UNSIGNED NULL,
    release_note VARCHAR(500) NULL,
    INDEX idx_account_access_ban_user (user_id, banned_at),
    INDEX idx_account_access_ban_active (user_id, released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_reset_generations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    generation BIGINT UNSIGNED NOT NULL,
    previous_province_code VARCHAR(32) NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'pending',
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uniq_access_reset_generation (user_id, generation),
    INDEX idx_access_reset_generation_state (user_id, state, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_reset_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reset_generation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    device_uuid VARCHAR(191) NOT NULL,
    blocked_until DATETIME NULL,
    block_created_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_access_reset_device (reset_generation_id, client_id, device_uuid),
    INDEX idx_access_reset_device_user (user_id, device_uuid),
    CONSTRAINT fk_access_reset_device_generation FOREIGN KEY (reset_generation_id) REFERENCES access_reset_generations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
