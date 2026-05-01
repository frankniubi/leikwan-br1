CREATE TABLE IF NOT EXISTS sidecar_points (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_user_id BIGINT UNSIGNED NULL,
    name VARCHAR(191) NOT NULL,
    sidecar_host VARCHAR(255) NOT NULL,
    listen_port INT NOT NULL,
    bind_host VARCHAR(255) NOT NULL DEFAULT '0.0.0.0',
    token_secret VARCHAR(191) NOT NULL,
    agent_token VARCHAR(191) NOT NULL UNIQUE,
    agent_version VARCHAR(64) NULL,
    last_heartbeat_at DATETIME NULL,
    last_status VARCHAR(32) NULL,
    last_error TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_sidecar_points_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id),
    INDEX idx_sidecar_points_owner (owner_user_id, is_active),
    INDEX idx_sidecar_points_status (last_status, last_heartbeat_at)
);

ALTER TABLE forwards
    ADD COLUMN bond_sidecar_point_id BIGINT UNSIGNED NULL AFTER bond_remote_port;

ALTER TABLE forwards
    ADD CONSTRAINT fk_forwards_sidecar_point FOREIGN KEY (bond_sidecar_point_id) REFERENCES sidecar_points(id);
