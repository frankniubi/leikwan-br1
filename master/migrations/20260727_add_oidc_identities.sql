CREATE TABLE IF NOT EXISTS oidc_identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    issuer TEXT NOT NULL,
    subject TEXT NOT NULL,
    issuer_hash CHAR(64) NOT NULL,
    subject_hash CHAR(64) NOT NULL,
    email VARCHAR(191) NOT NULL,
    email_verified_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_oidc_identities_user FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uniq_oidc_identity_subject (issuer_hash, subject_hash),
    UNIQUE KEY uniq_oidc_identity_user_issuer (user_id, issuer_hash),
    INDEX idx_oidc_identities_user (user_id),
    INDEX idx_oidc_identities_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
