ALTER TABLE clients
    ADD COLUMN online_device_limit INT NOT NULL DEFAULT 1 AFTER agent_update_requested_at;

CREATE TABLE IF NOT EXISTS client_device_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    device_name VARCHAR(191) NULL,
    platform VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    last_ip VARCHAR(64) NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    last_pull_at DATETIME NULL,
    last_heartbeat_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_client_device_sessions_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_client_device (client_id, device_id),
    INDEX idx_client_device_sessions_seen (client_id, last_seen_at)
);
