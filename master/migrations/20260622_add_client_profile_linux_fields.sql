ALTER TABLE services ADD COLUMN server_hostname VARCHAR(255) NULL AFTER whmcs_service_id, ADD COLUMN server_public_ip VARCHAR(64) NULL AFTER server_hostname, ADD COLUMN server_ip_state ENUM('fresh', 'stale', 'missing') NOT NULL DEFAULT 'missing' AFTER server_public_ip, ADD COLUMN server_ip_last_synced_at DATETIME NULL AFTER server_ip_state;

ALTER TABLE clients ADD COLUMN allow_paid_lines TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default_for_owner, ADD COLUMN selected_carriers_json JSON NULL AFTER allow_paid_lines, ADD COLUMN runtime_platform_scope VARCHAR(32) NOT NULL DEFAULT 'all' AFTER selected_carriers_json;

ALTER TABLE forwards ADD COLUMN target_source VARCHAR(32) NOT NULL DEFAULT 'manual' AFTER service_id;
ALTER TABLE forwards ADD COLUMN target_service_id BIGINT UNSIGNED NULL AFTER target_source;
