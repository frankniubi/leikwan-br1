CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    email VARCHAR(191) NOT NULL UNIQUE,
    api_token VARCHAR(128) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'tenant',
    feature_flags_json JSON NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    email_verification_token VARCHAR(128) NULL UNIQUE,
    verification_sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    wallet_currency VARCHAR(16) NOT NULL DEFAULT 'CNY',
    whmcs_client_id BIGINT UNSIGNED NULL UNIQUE,
    whmcs_user_id BIGINT UNSIGNED NULL,
    whmcs_email VARCHAR(191) NULL,
    whmcs_full_name VARCHAR(191) NULL,
    whmcs_currency_code VARCHAR(16) NULL,
    whmcs_bound_at DATETIME NULL,
    whmcs_last_synced_at DATETIME NULL,
    wallet_low_balance_threshold DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    wallet_warning_sent TINYINT(1) NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS app_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(191) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

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

CREATE TABLE IF NOT EXISTS traffic_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_type ENUM('slave', 'sidecar') NOT NULL,
    node_id INT NULL COMMENT 'nodes.id (slave) or sidecar_points.id (sidecar)',
    node_group_id INT NULL COMMENT 'node_groups.id (slave only)',
    price_per_gb DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
    currency VARCHAR(8) NOT NULL DEFAULT 'CNY',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS service_plan_sidecar_points (
    plan_id INT NOT NULL,
    sidecar_point_id INT NOT NULL,
    PRIMARY KEY (plan_id, sidecar_point_id)
);

CREATE TABLE IF NOT EXISTS service_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    product_code VARCHAR(191) NOT NULL UNIQUE,
    description TEXT NULL,
    default_bandwidth_limit_mbps INT NOT NULL DEFAULT 100,
    default_traffic_limit_gb INT NOT NULL DEFAULT 1000,
    billing_mode VARCHAR(32) NOT NULL DEFAULT 'monthly_bundle',
    billing_currency VARCHAR(16) NOT NULL DEFAULT 'CNY',
    monthly_base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    monthly_included_traffic_gb INT NOT NULL DEFAULT 0,
    traffic_price_per_gb DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    traffic_reset_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    bandwidth_step_mbps INT NOT NULL DEFAULT 0,
    bandwidth_step_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    included_client_count INT NOT NULL DEFAULT 0,
    extra_client_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    feature_flags_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS services (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NULL,
    name VARCHAR(191) NOT NULL,
    whmcs_service_id BIGINT NOT NULL UNIQUE,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    expire_at DATETIME NULL,
    bandwidth_limit_mbps INT NOT NULL DEFAULT 100,
    traffic_limit_gb INT NOT NULL DEFAULT 1000,
    traffic_used_bytes BIGINT NOT NULL DEFAULT 0,
    billing_mode VARCHAR(32) NOT NULL DEFAULT 'monthly_bundle',
    billing_currency VARCHAR(16) NOT NULL DEFAULT 'CNY',
    monthly_base_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    monthly_included_traffic_gb INT NOT NULL DEFAULT 0,
    traffic_price_per_gb DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    billing_cycle_started_at DATETIME NULL,
    billing_cycle_traffic_used_bytes BIGINT NOT NULL DEFAULT 0,
    client_token VARCHAR(128) NOT NULL UNIQUE,
    suspend_reason VARCHAR(191) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_services_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_services_plan FOREIGN KEY (plan_id) REFERENCES service_plans(id)
);

CREATE TABLE IF NOT EXISTS sidecar_points (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_user_id BIGINT UNSIGNED NULL,
    name VARCHAR(191) NOT NULL,
    sidecar_host VARCHAR(255) NOT NULL,
    listen_port INT NOT NULL,
    bind_host VARCHAR(255) NOT NULL DEFAULT '0.0.0.0',
    total_speed_limit_mbps INT NULL,
    token_secret VARCHAR(191) NOT NULL,
    agent_token VARCHAR(191) NOT NULL UNIQUE,
    agent_version VARCHAR(64) NULL,
    agent_config_json JSON NULL,
    agent_update_request_token VARCHAR(128) NULL,
    agent_update_requested_at DATETIME NULL,
    last_heartbeat_at DATETIME NULL,
    last_status VARCHAR(32) NULL,
    last_error TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_sidecar_points_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id),
    INDEX idx_sidecar_points_owner (owner_user_id, is_active),
    INDEX idx_sidecar_points_status (last_status, last_heartbeat_at)
);

CREATE TABLE IF NOT EXISTS forwards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    service_id BIGINT UNSIGNED NOT NULL,
    listen_port INT NOT NULL,
    target_host VARCHAR(255) NOT NULL,
    target_port INT NOT NULL,
    bond_remote_host VARCHAR(255) NULL,
    bond_remote_port INT NULL,
    bond_sidecar_point_id BIGINT UNSIGNED NULL,
    line_client_id BIGINT UNSIGNED NULL,
    line_client_group_id BIGINT UNSIGNED NULL,
    line_group_mode VARCHAR(32) NOT NULL DEFAULT 'selected',
    line_client_group_ids_json JSON NULL,
    endpoint_mode VARCHAR(32) NOT NULL DEFAULT 'direct',
    forward_chain_id BIGINT UNSIGNED NULL,
    custom_endpoint_ids_json JSON NULL,
    transport_mode VARCHAR(32) NOT NULL DEFAULT 'tcp+udp',
    client_mode VARCHAR(32) NOT NULL DEFAULT 'multi-path',
    sni_host VARCHAR(255) NOT NULL,
    path_prefix VARCHAR(128) NOT NULL,
    node_group_key VARCHAR(191) NOT NULL DEFAULT 'default',
    endpoint_count INT NOT NULL DEFAULT 2,
    public_port INT NOT NULL,
    cdn_host VARCHAR(255) NOT NULL,
    filter_profile VARCHAR(64) NOT NULL DEFAULT 'off',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forwards_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_forwards_line_client FOREIGN KEY (line_client_id) REFERENCES clients(id),
    CONSTRAINT fk_forwards_line_client_group FOREIGN KEY (line_client_group_id) REFERENCES client_groups(id),
    CONSTRAINT fk_forwards_sidecar_point FOREIGN KEY (bond_sidecar_point_id) REFERENCES sidecar_points(id),
    CONSTRAINT fk_forwards_chain FOREIGN KEY (forward_chain_id) REFERENCES forward_chains(id),
    INDEX idx_forwards_service (service_id)
);

CREATE TABLE IF NOT EXISTS node_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_key VARCHAR(191) NOT NULL UNIQUE,
    name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    ingress_cdn_host VARCHAR(255) NULL,
    ingress_sni_host VARCHAR(255) NULL,
    ingress_path_strategy VARCHAR(32) NOT NULL DEFAULT 'random',
    ingress_port_strategy VARCHAR(32) NOT NULL DEFAULT 'client_range_then_target',
    ingress_preferred_public_port INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS service_plan_node_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    node_group_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_plan_group_plan FOREIGN KEY (plan_id) REFERENCES service_plans(id),
    CONSTRAINT fk_plan_group_node_group FOREIGN KEY (node_group_id) REFERENCES node_groups(id),
    UNIQUE KEY uniq_plan_node_group (plan_id, node_group_id)
);

CREATE TABLE IF NOT EXISTS client_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_key VARCHAR(191) NOT NULL UNIQUE,
    name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS clients (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NULL,
    owner_user_id BIGINT UNSIGNED NULL,
    agent_token VARCHAR(128) NULL,
    agent_version VARCHAR(64) NULL,
    last_sync_at DATETIME NULL,
    last_diag_requested_at DATETIME NULL,
    last_diag_request_token VARCHAR(128) NULL,
    last_diag_status VARCHAR(32) NULL,
    last_diag_run_at DATETIME NULL,
    last_diag_result_json JSON NULL,
    last_diag_error TEXT NULL,
    agent_config_json JSON NULL,
    agent_update_request_token VARCHAR(128) NULL,
    agent_update_requested_at DATETIME NULL,
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
    default_endpoint_mode VARCHAR(32) NOT NULL DEFAULT 'slave_group',
    default_node_group_key VARCHAR(191) NULL,
    default_forward_chain_id BIGINT UNSIGNED NULL,
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
    CONSTRAINT fk_clients_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id),
    CONSTRAINT fk_clients_default_chain FOREIGN KEY (default_forward_chain_id) REFERENCES forward_chains(id),
    INDEX idx_clients_group (group_id),
    INDEX idx_clients_status (status)
);

CREATE TABLE IF NOT EXISTS service_plan_client_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    client_group_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_plan_client_group_plan FOREIGN KEY (plan_id) REFERENCES service_plans(id),
    CONSTRAINT fk_plan_client_group_group FOREIGN KEY (client_group_id) REFERENCES client_groups(id),
    UNIQUE KEY uniq_plan_client_group (plan_id, client_group_id)
);

CREATE TABLE IF NOT EXISTS forward_chains (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS forward_chain_steps (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_chain_id BIGINT UNSIGNED NOT NULL,
    node_group_id BIGINT UNSIGNED NOT NULL,
    step_order INT NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_chain_step_chain FOREIGN KEY (forward_chain_id) REFERENCES forward_chains(id),
    CONSTRAINT fk_chain_step_node_group FOREIGN KEY (node_group_id) REFERENCES node_groups(id),
    UNIQUE KEY uniq_chain_step_order (forward_chain_id, step_order)
);

CREATE TABLE IF NOT EXISTS credential_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    provider VARCHAR(64) NOT NULL DEFAULT 'cloudflare_global_key',
    config_json JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS ssl_certificates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    source_type VARCHAR(64) NOT NULL DEFAULT 'uploaded',
    domains_json JSON NULL,
    issuer_name VARCHAR(255) NULL,
    expires_at DATETIME NULL,
    renewal_mode VARCHAR(64) NOT NULL DEFAULT 'manual',
    source_config_json JSON NULL,
    cert_pem LONGTEXT NULL,
    key_pem LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_ssl_certificates_expires (expires_at)
);

CREATE TABLE IF NOT EXISTS ssl_certificate_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    certificate_id BIGINT UNSIGNED NOT NULL,
    task_type VARCHAR(64) NOT NULL DEFAULT 'issue',
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    requested_by_user_id BIGINT UNSIGNED NULL,
    output_log LONGTEXT NULL,
    error_message TEXT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_ssl_certificate_tasks_certificate FOREIGN KEY (certificate_id) REFERENCES ssl_certificates(id),
    INDEX idx_ssl_certificate_tasks_status (status),
    INDEX idx_ssl_certificate_tasks_certificate (certificate_id)
);

CREATE TABLE IF NOT EXISTS nodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    group_id BIGINT UNSIGNED NULL,
    credential_profile_id BIGINT UNSIGNED NULL,
    ssl_certificate_id BIGINT UNSIGNED NULL,
    name VARCHAR(191) NOT NULL,
    region VARCHAR(191) NOT NULL DEFAULT 'unknown',
    public_ip VARCHAR(64) NOT NULL DEFAULT '',
    private_ip VARCHAR(64) NOT NULL DEFAULT '',
    machine_id VARCHAR(191) NULL,
    api_token VARCHAR(128) NOT NULL UNIQUE,
    agent_version VARCHAR(64) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    current_load INT NOT NULL DEFAULT 0,
    supports_udp TINYINT(1) NOT NULL DEFAULT 1,
    supports_filtering TINYINT(1) NOT NULL DEFAULT 0,
    agent_config_json JSON NULL,
    agent_update_request_token VARCHAR(128) NULL,
    agent_update_requested_at DATETIME NULL,
    last_heartbeat_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    sharing_mode ENUM('private', 'group_shared') NOT NULL DEFAULT 'private',
    user_slave_group_id INT NULL,
    CONSTRAINT fk_nodes_group FOREIGN KEY (group_id) REFERENCES node_groups(id),
    CONSTRAINT fk_nodes_credential_profile FOREIGN KEY (credential_profile_id) REFERENCES credential_profiles(id),
    CONSTRAINT fk_nodes_ssl_certificate FOREIGN KEY (ssl_certificate_id) REFERENCES ssl_certificates(id),
    INDEX idx_nodes_machine (machine_id),
    INDEX idx_nodes_status (status),
    INDEX idx_nodes_heartbeat (last_heartbeat_at)
);

CREATE TABLE IF NOT EXISTS service_endpoints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    public_host VARCHAR(255) NOT NULL,
    public_ip VARCHAR(64) NOT NULL DEFAULT '',
    public_port INT NOT NULL,
    cdn_host VARCHAR(255) NOT NULL,
    priority INT NOT NULL DEFAULT 10,
    weight INT NOT NULL DEFAULT 100,
    health_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_service_endpoints_forward FOREIGN KEY (forward_id) REFERENCES forwards(id),
    CONSTRAINT fk_service_endpoints_node FOREIGN KEY (node_id) REFERENCES nodes(id),
    INDEX idx_service_endpoints_forward (forward_id),
    INDEX idx_service_endpoints_node (node_id)
);

CREATE TABLE IF NOT EXISTS provision_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    node_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(32) NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    payload_json JSON NOT NULL,
    lease_expires_at DATETIME NULL,
    attempt_count INT NOT NULL DEFAULT 0,
    result_json JSON NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_provision_tasks_node FOREIGN KEY (node_id) REFERENCES nodes(id),
    CONSTRAINT fk_provision_tasks_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_provision_tasks_forward FOREIGN KEY (forward_id) REFERENCES forwards(id),
    CONSTRAINT fk_provision_tasks_endpoint FOREIGN KEY (endpoint_id) REFERENCES service_endpoints(id),
    INDEX idx_provision_tasks_node_status (node_id, status)
);

CREATE TABLE IF NOT EXISTS node_heartbeats (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    node_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    cpu_usage DECIMAL(8,2) NOT NULL DEFAULT 0,
    memory_usage DECIMAL(8,2) NOT NULL DEFAULT 0,
    disk_usage DECIMAL(8,2) NOT NULL DEFAULT 0,
    payload_json JSON NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_node_heartbeats_node FOREIGN KEY (node_id) REFERENCES nodes(id),
    INDEX idx_node_heartbeats_node_created (node_id, created_at)
);

CREATE TABLE IF NOT EXISTS traffic_usage_hourly (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    service_id BIGINT UNSIGNED NOT NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    period_start DATETIME NOT NULL,
    bytes_in BIGINT NOT NULL DEFAULT 0,
    bytes_out BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_traffic_usage_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_traffic_usage_endpoint FOREIGN KEY (endpoint_id) REFERENCES service_endpoints(id),
    CONSTRAINT fk_traffic_usage_node FOREIGN KEY (node_id) REFERENCES nodes(id),
    UNIQUE KEY uniq_usage_period (service_id, endpoint_id, period_start)
);

CREATE TABLE IF NOT EXISTS agent_usage_hourly (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(32) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    period_start DATETIME NOT NULL,
    bytes_in BIGINT NOT NULL DEFAULT 0,
    bytes_out BIGINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_agent_usage_period (entity_type, entity_id, period_start),
    INDEX idx_agent_usage_lookup (entity_type, entity_id, period_start)
);

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

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(191) NOT NULL,
    context_json JSON NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_action_created (action, created_at)
);

CREATE TABLE IF NOT EXISTS user_slave_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    owner_user_id INT NOT NULL,
    invite_code VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invite_code (invite_code)
);

CREATE TABLE IF NOT EXISTS user_slave_group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner', 'member') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_user (group_id, user_id)
);
