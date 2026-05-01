CREATE TABLE IF NOT EXISTS app_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(191) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

INSERT INTO app_settings (setting_key, setting_value, created_at, updated_at)
VALUES ('tenant_registration_require_code', '1', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS tenant_registration_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    label VARCHAR(191) NULL,
    max_uses INT NOT NULL DEFAULT 1,
    used_count INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    expires_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_registration_codes_user FOREIGN KEY (created_by_user_id) REFERENCES users(id),
    INDEX idx_registration_codes_active (is_active, expires_at)
);

ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL;
ALTER TABLE users ADD COLUMN email_verification_token VARCHAR(128) NULL;
ALTER TABLE users ADD COLUMN verification_sent_at DATETIME NULL;
CREATE UNIQUE INDEX uniq_users_email_verification_token ON users (email_verification_token);
UPDATE users
SET email_verified_at = COALESCE(email_verified_at, created_at)
WHERE status = 'active' AND email_verified_at IS NULL;

ALTER TABLE service_plans ADD COLUMN billing_mode VARCHAR(32) NOT NULL DEFAULT 'monthly_bundle';
ALTER TABLE service_plans ADD COLUMN billing_currency VARCHAR(16) NOT NULL DEFAULT 'CNY';
ALTER TABLE service_plans ADD COLUMN monthly_base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE service_plans ADD COLUMN monthly_included_traffic_gb INT NOT NULL DEFAULT 0;
ALTER TABLE service_plans ADD COLUMN traffic_price_per_gb DECIMAL(10,4) NOT NULL DEFAULT 0.0000;
UPDATE service_plans
SET monthly_included_traffic_gb = default_traffic_limit_gb
WHERE monthly_included_traffic_gb = 0;

ALTER TABLE services ADD COLUMN billing_mode VARCHAR(32) NOT NULL DEFAULT 'monthly_bundle';
ALTER TABLE services ADD COLUMN billing_currency VARCHAR(16) NOT NULL DEFAULT 'CNY';
ALTER TABLE services ADD COLUMN monthly_base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE services ADD COLUMN monthly_included_traffic_gb INT NOT NULL DEFAULT 0;
ALTER TABLE services ADD COLUMN traffic_price_per_gb DECIMAL(10,4) NOT NULL DEFAULT 0.0000;
ALTER TABLE services ADD COLUMN billing_cycle_started_at DATETIME NULL;
ALTER TABLE services ADD COLUMN billing_cycle_traffic_used_bytes BIGINT NOT NULL DEFAULT 0;
UPDATE services
SET billing_mode = 'monthly_bundle',
    billing_currency = 'CNY',
    monthly_base_fee = 0.00,
    monthly_included_traffic_gb = traffic_limit_gb,
    traffic_price_per_gb = 0.0000,
    billing_cycle_started_at = COALESCE(billing_cycle_started_at, created_at),
    billing_cycle_traffic_used_bytes = traffic_used_bytes
WHERE billing_cycle_started_at IS NULL OR monthly_included_traffic_gb = 0;

CREATE TABLE IF NOT EXISTS service_billing_cycles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    service_id BIGINT UNSIGNED NOT NULL,
    cycle_started_at DATETIME NOT NULL,
    cycle_ended_at DATETIME NOT NULL,
    billing_mode VARCHAR(32) NOT NULL DEFAULT 'monthly_bundle',
    billing_currency VARCHAR(16) NOT NULL DEFAULT 'CNY',
    monthly_base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    monthly_included_traffic_gb INT NOT NULL DEFAULT 0,
    traffic_price_per_gb DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    usage_bytes BIGINT NOT NULL DEFAULT 0,
    billable_bytes BIGINT NOT NULL DEFAULT 0,
    estimated_total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_service_billing_cycles_service FOREIGN KEY (service_id) REFERENCES services(id),
    UNIQUE KEY uniq_service_billing_cycle (service_id, cycle_started_at),
    INDEX idx_service_billing_cycles_service (service_id, cycle_ended_at)
);
