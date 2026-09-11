CREATE TABLE IF NOT EXISTS openwrt_pairing_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_openwrt_pairing_codes_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_openwrt_pairing_code_hash (code_hash),
    INDEX idx_openwrt_pairing_code_expiry (expires_at)
);
