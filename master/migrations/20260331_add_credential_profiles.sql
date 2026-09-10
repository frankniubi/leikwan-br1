CREATE TABLE credential_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    provider VARCHAR(64) NOT NULL DEFAULT 'cloudflare_global_key',
    config_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

ALTER TABLE nodes
    ADD COLUMN credential_profile_id BIGINT UNSIGNED NULL AFTER group_id;

ALTER TABLE nodes
    ADD CONSTRAINT fk_nodes_credential_profile FOREIGN KEY (credential_profile_id) REFERENCES credential_profiles(id);
