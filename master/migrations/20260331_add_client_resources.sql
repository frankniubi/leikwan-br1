CREATE TABLE client_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_key VARCHAR(191) NOT NULL UNIQUE,
    name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE clients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NULL,
    name VARCHAR(191) NOT NULL,
    display_host VARCHAR(255) NOT NULL,
    bind_ip VARCHAR(64) NULL,
    nic_name VARCHAR(64) NULL,
    port_range_start INT NULL,
    port_range_end INT NULL,
    traffic_multiplier DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    allow_custom_endpoints TINYINT(1) NOT NULL DEFAULT 0,
    force_slave_mode TINYINT(1) NOT NULL DEFAULT 0,
    allow_forward_chain TINYINT(1) NOT NULL DEFAULT 1,
    allow_latency_test TINYINT(1) NOT NULL DEFAULT 1,
    allow_inbound_proxy TINYINT(1) NOT NULL DEFAULT 0,
    max_ip_count INT NULL,
    max_conn_per_ip INT NULL,
    protocol_filter_json JSON NULL,
    custom_config_json JSON NULL,
    speed_limit_mbps INT NULL,
    tags_json JSON NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_clients_group FOREIGN KEY (group_id) REFERENCES client_groups(id),
    INDEX idx_clients_group (group_id),
    INDEX idx_clients_status (status)
);

CREATE TABLE service_plan_client_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    client_group_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_plan_client_group_plan FOREIGN KEY (plan_id) REFERENCES service_plans(id),
    CONSTRAINT fk_plan_client_group_group FOREIGN KEY (client_group_id) REFERENCES client_groups(id),
    UNIQUE KEY uniq_plan_client_group (plan_id, client_group_id)
);

CREATE TABLE forward_chains (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE forward_chain_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_chain_id BIGINT UNSIGNED NOT NULL,
    node_group_id BIGINT UNSIGNED NOT NULL,
    step_order INT NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_chain_step_chain FOREIGN KEY (forward_chain_id) REFERENCES forward_chains(id),
    CONSTRAINT fk_chain_step_node_group FOREIGN KEY (node_group_id) REFERENCES node_groups(id),
    UNIQUE KEY uniq_chain_step_order (forward_chain_id, step_order)
);

ALTER TABLE forwards
    ADD COLUMN line_client_id BIGINT UNSIGNED NULL AFTER target_port;

ALTER TABLE forwards
    ADD COLUMN line_client_group_id BIGINT UNSIGNED NULL AFTER line_client_id;

ALTER TABLE forwards
    ADD COLUMN endpoint_mode VARCHAR(32) NOT NULL DEFAULT 'direct' AFTER line_client_group_id;

ALTER TABLE forwards
    ADD COLUMN forward_chain_id BIGINT UNSIGNED NULL AFTER endpoint_mode;

ALTER TABLE forwards
    ADD COLUMN custom_endpoint_ids_json JSON NULL AFTER forward_chain_id;

ALTER TABLE forwards
    ADD CONSTRAINT fk_forwards_line_client FOREIGN KEY (line_client_id) REFERENCES clients(id);

ALTER TABLE forwards
    ADD CONSTRAINT fk_forwards_line_client_group FOREIGN KEY (line_client_group_id) REFERENCES client_groups(id);

ALTER TABLE forwards
    ADD CONSTRAINT fk_forwards_chain FOREIGN KEY (forward_chain_id) REFERENCES forward_chains(id);
