CREATE TABLE IF NOT EXISTS cf_location_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    region_code VARCHAR(32) NOT NULL,
    probe_mode VARCHAR(16) NOT NULL DEFAULT 'auto',
    cache_ttl_seconds INT NOT NULL DEFAULT 120,
    switch_improvement_ms INT NOT NULL DEFAULT 15,
    max_candidates INT NOT NULL DEFAULT 32,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS cf_location_group_cidrs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NOT NULL,
    cidr VARCHAR(64) NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_cf_location_group_cidrs_group FOREIGN KEY (group_id) REFERENCES cf_location_groups(id),
    UNIQUE KEY uniq_cf_location_group_cidr (group_id, cidr)
);

CREATE TABLE IF NOT EXISTS forward_combinations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    tenant_label VARCHAR(191) NOT NULL,
    tenant_description VARCHAR(255) NULL,
    tenant_badge VARCHAR(64) NULL,
    node_group_key VARCHAR(191) NOT NULL,
    backend_mode VARCHAR(32) NOT NULL DEFAULT 'current_mode',
    sidecar_point_id BIGINT UNSIGNED NOT NULL,
    cf_location_group_id BIGINT UNSIGNED NULL,
    client_mode VARCHAR(32) NOT NULL DEFAULT 'single',
    bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'plain_tcp_mux',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forward_combinations_sidecar FOREIGN KEY (sidecar_point_id) REFERENCES sidecar_points(id),
    CONSTRAINT fk_forward_combinations_cf_group FOREIGN KEY (cf_location_group_id) REFERENCES cf_location_groups(id)
);

ALTER TABLE sidecar_points
    ADD COLUMN IF NOT EXISTS cf_listen_port INT NULL AFTER mptcp_listen_port;

ALTER TABLE forwards
    ADD COLUMN IF NOT EXISTS forward_combination_id BIGINT UNSIGNED NULL AFTER bond_sidecar_point_id,
    ADD COLUMN IF NOT EXISTS backend_mode VARCHAR(32) NOT NULL DEFAULT 'current_mode' AFTER forward_combination_id,
    ADD COLUMN IF NOT EXISTS cf_location_group_id BIGINT UNSIGNED NULL AFTER backend_mode;
