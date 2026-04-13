ALTER TABLE nodes ADD COLUMN owner_service_id BIGINT UNSIGNED NULL DEFAULT NULL;
ALTER TABLE nodes ADD COLUMN enrollment_type VARCHAR(32) NOT NULL DEFAULT 'platform';
ALTER TABLE nodes ADD INDEX idx_nodes_owner_service (owner_service_id);

CREATE TABLE IF NOT EXISTS node_enrollment_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    service_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    used_by_node_id BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_enrollment_token_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE,
    INDEX idx_enrollment_token (token),
    INDEX idx_enrollment_service (service_id)
);
