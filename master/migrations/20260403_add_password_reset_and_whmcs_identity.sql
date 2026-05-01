ALTER TABLE users ADD COLUMN wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER updated_at;
ALTER TABLE users ADD COLUMN wallet_currency VARCHAR(16) NOT NULL DEFAULT 'CNY' AFTER wallet_balance;
ALTER TABLE users ADD COLUMN whmcs_client_id BIGINT UNSIGNED NULL AFTER wallet_currency;
ALTER TABLE users ADD COLUMN whmcs_user_id BIGINT UNSIGNED NULL AFTER whmcs_client_id;
ALTER TABLE users ADD COLUMN whmcs_email VARCHAR(191) NULL AFTER whmcs_user_id;
ALTER TABLE users ADD COLUMN whmcs_full_name VARCHAR(191) NULL AFTER whmcs_email;
ALTER TABLE users ADD COLUMN whmcs_currency_code VARCHAR(16) NULL AFTER whmcs_full_name;
ALTER TABLE users ADD COLUMN whmcs_bound_at DATETIME NULL AFTER whmcs_currency_code;
ALTER TABLE users ADD COLUMN whmcs_last_synced_at DATETIME NULL AFTER whmcs_bound_at;
CREATE UNIQUE INDEX uniq_users_whmcs_client_id ON users (whmcs_client_id);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_password_reset_tokens_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_password_reset_tokens_user (user_id, expires_at)
);

CREATE TABLE IF NOT EXISTS whmcs_login_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    public_token VARCHAR(64) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    whmcs_client_id BIGINT UNSIGNED NOT NULL,
    whmcs_user_id BIGINT UNSIGNED NULL,
    whmcs_email VARCHAR(191) NOT NULL,
    verification_code_hash CHAR(64) NOT NULL,
    ticket_id BIGINT UNSIGNED NULL,
    ticket_tid VARCHAR(64) NULL,
    ticket_access_code VARCHAR(64) NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_whmcs_login_challenges_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_whmcs_login_challenges_user (user_id, created_at),
    INDEX idx_whmcs_login_challenges_expiry (expires_at, verified_at)
);

CREATE TABLE IF NOT EXISTS wallet_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    direction VARCHAR(32) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(16) NOT NULL DEFAULT 'CNY',
    panel_balance_before DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    panel_balance_after DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    whmcs_balance_before DECIMAL(12,2) NULL,
    whmcs_balance_after DECIMAL(12,2) NULL,
    context_json JSON NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_wallet_transactions_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_wallet_transactions_user (user_id, created_at)
);
