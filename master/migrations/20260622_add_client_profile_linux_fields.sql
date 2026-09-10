ALTER TABLE services ADD COLUMN server_hostname VARCHAR(255) NULL;
ALTER TABLE services ADD COLUMN server_public_ip VARCHAR(64) NULL;
ALTER TABLE services ADD COLUMN server_ip_state ENUM('fresh', 'stale', 'missing') NOT NULL DEFAULT 'missing';
ALTER TABLE services ADD COLUMN server_ip_last_synced_at DATETIME NULL;

ALTER TABLE clients ADD COLUMN allow_paid_lines TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE clients ADD COLUMN selected_carriers_json JSON NULL;
ALTER TABLE clients ADD COLUMN runtime_platform_scope VARCHAR(32) NOT NULL DEFAULT 'all';

ALTER TABLE forwards ADD COLUMN target_source VARCHAR(32) NOT NULL DEFAULT 'manual';
ALTER TABLE forwards ADD COLUMN target_service_id BIGINT UNSIGNED NULL;
CREATE INDEX idx_forwards_target_service ON forwards (target_service_id);
ALTER TABLE forwards ADD CONSTRAINT fk_forwards_target_service FOREIGN KEY (target_service_id) REFERENCES services(id);
