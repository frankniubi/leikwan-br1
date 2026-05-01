CREATE TABLE ssl_certificates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    source_type VARCHAR(64) NOT NULL DEFAULT 'uploaded',
    domains_json JSON NULL,
    issuer_name VARCHAR(255) NULL,
    expires_at DATETIME NULL,
    renewal_mode VARCHAR(64) NOT NULL DEFAULT 'manual',
    source_config_json JSON NULL,
    cert_pem LONGTEXT NULL,
    key_pem LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ssl_certificates_expires (expires_at)
);

ALTER TABLE nodes
    ADD COLUMN ssl_certificate_id BIGINT UNSIGNED NULL AFTER credential_profile_id;

ALTER TABLE nodes
    ADD CONSTRAINT fk_nodes_ssl_certificate FOREIGN KEY (ssl_certificate_id) REFERENCES ssl_certificates(id);
