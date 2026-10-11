CREATE TABLE IF NOT EXISTS ultra_candidate_reputation_current (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    candidate_key_hash CHAR(64) NOT NULL,
    source_id VARCHAR(191) NOT NULL,
    line_id INT UNSIGNED NOT NULL,
    ingress_zone VARCHAR(32) NOT NULL,
    destination_zone VARCHAR(32) NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'healthy',
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    consecutive_successes INT UNSIGNED NOT NULL DEFAULT 0,
    independent_observers INT UNSIGNED NOT NULL DEFAULT 0,
    backoff_level INT UNSIGNED NOT NULL DEFAULT 0,
    quarantine_until DATETIME NULL,
    last_reason VARCHAR(64) NULL,
    last_observed_at DATETIME NULL,
    last_good_at DATETIME NULL,
    sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_ultra_candidate_reputation_scope (candidate_key_hash),
    INDEX idx_ultra_candidate_reputation_state (state, quarantine_until, updated_at),
    INDEX idx_ultra_candidate_reputation_route (source_id, line_id, ingress_zone, destination_zone, protocol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ultra_candidate_reputation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    candidate_key_hash CHAR(64) NOT NULL,
    event_type VARCHAR(16) NOT NULL,
    state VARCHAR(16) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    observer_hash CHAR(64) NULL,
    evidence_hash CHAR(64) NOT NULL,
    observed_at DATETIME NOT NULL,
    UNIQUE KEY uniq_ultra_candidate_reputation_evidence (candidate_key_hash, evidence_hash),
    INDEX idx_ultra_candidate_reputation_events_observed (observed_at),
    INDEX idx_ultra_candidate_reputation_events_candidate (candidate_key_hash, event_type, observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
