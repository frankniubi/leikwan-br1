CREATE TABLE ultra_sources (
    source_id VARCHAR(191) NOT NULL PRIMARY KEY,
    agent_version VARCHAR(64) NULL,
    inventory_fingerprint CHAR(64) NULL,
    inventory_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_seen_at DATETIME NULL,
    last_inventory_at DATETIME NULL,
    last_jobs_at DATETIME NULL,
    last_material_upload_at DATETIME NULL,
    last_requested_line_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_accepted_line_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error_code VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ultra_sources_seen (last_seen_at)
);

CREATE TABLE ultra_zones (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    zone_code VARCHAR(32) NOT NULL,
    zone_role VARCHAR(16) NOT NULL,
    label VARCHAR(191) NOT NULL,
    aliases_json JSON NULL,
    sort_order INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_ultra_zone_role_code (zone_role, zone_code),
    INDEX idx_ultra_zones_active (zone_role, is_active, sort_order)
);

CREATE TABLE ultra_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_id VARCHAR(191) NOT NULL,
    line_id INT UNSIGNED NOT NULL,
    source_title VARCHAR(255) NULL,
    source_family_key VARCHAR(255) NULL,
    game_id INT UNSIGNED NULL,
    area_id INT UNSIGNED NULL,
    line_type INT UNSIGNED NULL,
    discovery_ip VARCHAR(64) NULL,
    config_sha256 CHAR(64) NOT NULL,
    auto_ingress_zone VARCHAR(32) NOT NULL DEFAULT 'unknown',
    auto_destination_zone VARCHAR(32) NOT NULL DEFAULT 'unknown',
    admin_ingress_zone VARCHAR(32) NULL,
    admin_destination_zone VARCHAR(32) NULL,
    admin_label VARCHAR(191) NULL,
    relay_ips_json JSON NULL,
    last_material_acquired_at DATETIME NULL,
    last_material_expires_at DATETIME NULL,
    last_discovered_at DATETIME NOT NULL,
    is_source_present TINYINT(1) NOT NULL DEFAULT 1,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_ultra_lines_source FOREIGN KEY (source_id) REFERENCES ultra_sources(source_id),
    UNIQUE KEY uniq_ultra_source_line (source_id, line_id),
    INDEX idx_ultra_lines_policy (is_source_present, is_enabled, auto_destination_zone, auto_ingress_zone),
    INDEX idx_ultra_lines_material_expiry (last_material_expires_at)
);

CREATE TABLE forward_combination_ultra_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    ultra_line_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fcul_combination FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE,
    CONSTRAINT fk_fcul_line FOREIGN KEY (ultra_line_id) REFERENCES ultra_lines(id),
    UNIQUE KEY uniq_combination_ultra_line (forward_combination_id, ultra_line_id),
    INDEX idx_fcul_selection (forward_combination_id, enabled, sort_order)
);

ALTER TABLE forward_combinations
    ADD COLUMN ultra_destination_zone VARCHAR(32) NULL AFTER line_direction,
    ADD COLUMN ultra_allowed_ingress_zones_json JSON NULL AFTER ultra_destination_zone,
    ADD COLUMN ultra_fallback_policy VARCHAR(32) NOT NULL DEFAULT 'detected_then_any' AFTER ultra_allowed_ingress_zones_json;

ALTER TABLE client_device_sessions
    ADD COLUMN ultra_status_json JSON NULL AFTER last_heartbeat_at,
    ADD COLUMN ultra_status_updated_at DATETIME NULL AFTER ultra_status_json;

INSERT INTO ultra_zones (zone_code, zone_role, label, aliases_json, sort_order, is_active, created_at, updated_at) VALUES
    ('beijing', 'ingress', 'Beijing', '["北京","北京市","Beijing"]', 10, 1, NOW(), NOW()),
    ('shanghai', 'ingress', 'Shanghai', '["上海","上海市","Shanghai"]', 20, 1, NOW(), NOW()),
    ('guangzhou', 'ingress', 'Guangzhou', '["广州","廣州","广州市","Guangzhou"]', 30, 1, NOW(), NOW()),
    ('unknown', 'ingress', 'Unknown', '[]', 999, 1, NOW(), NOW()),
    ('singapore', 'destination', 'Singapore', '["新加坡","Singapore"]', 10, 1, NOW(), NOW()),
    ('hong-kong', 'destination', 'Hong Kong', '["香港","Hong Kong"]', 20, 1, NOW(), NOW()),
    ('tokyo', 'destination', 'Tokyo', '["日本","东京","東京","Japan","Tokyo"]', 30, 1, NOW(), NOW()),
    ('frankfurt', 'destination', 'Frankfurt', '["德国","德國","法兰克福","法蘭克福","Germany","Frankfurt"]', 40, 1, NOW(), NOW()),
    ('los-angeles', 'destination', 'Los Angeles', '["美国","美國","洛杉矶","洛杉磯","USA","United States","Los Angeles"]', 50, 1, NOW(), NOW()),
    ('moscow', 'destination', 'Moscow', '["俄罗斯","俄羅斯","莫斯科","Russia","Moscow"]', 60, 1, NOW(), NOW()),
    ('unknown', 'destination', 'Unknown', '[]', 999, 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    aliases_json = VALUES(aliases_json),
    sort_order = VALUES(sort_order),
    is_active = VALUES(is_active),
    updated_at = VALUES(updated_at);
