CREATE TABLE IF NOT EXISTS agent_usage_hourly (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(32) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    period_start DATETIME NOT NULL,
    bytes_in BIGINT NOT NULL DEFAULT 0,
    bytes_out BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_agent_usage_period (entity_type, entity_id, period_start),
    INDEX idx_agent_usage_lookup (entity_type, entity_id, period_start)
);
