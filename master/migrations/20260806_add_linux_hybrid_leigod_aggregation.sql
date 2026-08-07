ALTER TABLE leigod_lines
    ADD COLUMN allow_node_execution TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active,
    ADD COLUMN allow_client_execution TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_node_execution,
    ADD COLUMN max_parallel_lanes TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER allow_client_execution;

ALTER TABLE node_leigod_line_health
    ADD COLUMN lease_id CHAR(36) NULL AFTER leigod_line_id,
    ADD COLUMN lease_generation BIGINT UNSIGNED NULL AFTER lease_id;

CREATE TABLE leigod_execution_leases (
    leigod_line_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    lease_id CHAR(36) NOT NULL,
    owner_type ENUM('client_device','node') NOT NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    owner_device_id VARCHAR(191) NULL,
    generation BIGINT UNSIGNED NOT NULL,
    max_parallel_lanes TINYINT UNSIGNED NOT NULL DEFAULT 2,
    state ENUM('pending','active','revoking') NOT NULL,
    issued_at DATETIME NOT NULL,
    ready_deadline_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    last_renewed_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_leigod_lease_id (lease_id),
    CONSTRAINT fk_lel_line FOREIGN KEY (leigod_line_id) REFERENCES leigod_lines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE client_config_revisions (
    client_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    revision BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_ccr_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE leigod_egress_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    profile_key VARCHAR(191) NOT NULL,
    region VARCHAR(191) NOT NULL,
    host VARCHAR(255) NOT NULL,
    tcp_port INT UNSIGNED NOT NULL,
    secret_ref VARCHAR(191) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_leigod_egress_profile_key (profile_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE forward_routes
    ADD COLUMN leigod_egress_profile_id BIGINT UNSIGNED NULL AFTER tenant_badge,
    ADD CONSTRAINT fk_forward_route_leigod_egress_profile
        FOREIGN KEY (leigod_egress_profile_id) REFERENCES leigod_egress_profiles(id) ON DELETE SET NULL;
