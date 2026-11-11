-- 20260722_add_route_directions_client_rules.sql
-- Route Direction and Client Rule backend/admin schema.
--
-- NOTE: foreign keys are intentionally OMITTED. This deployment runs with
-- foreign_keys disabled in production sqlite/mysql mixed mode and the
-- hosted-nodes migration (20261012) set the precedent: no FK constraints,
-- application-enforced cleanup. Indexes cover every join the service code uses.

CREATE TABLE route_directions (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    label VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'available',
    admin_notes TEXT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    internal_mapping_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_route_directions_status_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE direction_realizations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    direction_id VARCHAR(64) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 1,
    binding_state VARCHAR(32) NOT NULL DEFAULT 'missing',
    forward_id BIGINT UNSIGNED NULL,
    local_endpoint_host VARCHAR(64) NOT NULL DEFAULT '127.0.0.1',
    local_tcp_port INT NULL,
    local_udp_port INT NULL,
    tcp_ready TINYINT(1) NOT NULL DEFAULT 0,
    udp_ready TINYINT(1) NOT NULL DEFAULT 0,
    helper_only TINYINT(1) NOT NULL DEFAULT 1,
    internal_mapping_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_direction_primary_realization (direction_id, is_primary),
    INDEX idx_direction_realizations_forward (forward_id),
    INDEX idx_direction_realizations_state (binding_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE service_plan_route_directions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    direction_id VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_plan_route_direction (plan_id, direction_id),
    INDEX idx_plan_route_directions_direction (direction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE service_plans ADD COLUMN IF NOT EXISTS max_client_rules_per_service_scope INT NOT NULL DEFAULT 5;
ALTER TABLE forwards ADD COLUMN IF NOT EXISTS route_rule_helper_only TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE client_rules (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    service_scope_id VARCHAR(64) NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NULL,
    destination_ip VARCHAR(45) NOT NULL,
    destination_port INT NOT NULL,
    protocol VARCHAR(16) NOT NULL DEFAULT 'tcp_udp',
    direction_id VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    effective_state VARCHAR(32) NOT NULL DEFAULT 'runtime_missing',
    sync_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    active_duplicate_key VARCHAR(191) NULL,
    tombstoned_at DATETIME NULL,
    tombstoned_by VARCHAR(32) NULL,
    notes TEXT NULL,
    created_by_client_id BIGINT UNSIGNED NULL,
    updated_by_client_id BIGINT UNSIGNED NULL,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_client_rule_active_duplicate (service_scope_id, active_duplicate_key),
    INDEX idx_client_rules_scope (service_scope_id, tombstoned_at),
    INDEX idx_client_rules_service (service_id),
    INDEX idx_client_rules_direction (direction_id),
    INDEX idx_client_rules_sync (sync_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
