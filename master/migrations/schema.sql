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
    runtime_preference VARCHAR(64) NOT NULL DEFAULT 'single',
    allow_private_self_leg TINYINT(1) NOT NULL DEFAULT 0,
    self_leg_scope VARCHAR(64) NOT NULL DEFAULT 'none',
    self_leg_role VARCHAR(64) NOT NULL DEFAULT 'none',
    helper_group_cap INT NOT NULL DEFAULT 4 COMMENT 'Max legs used for bond/CF Bond/MPTCP. 0 = unlimited. Default 4.',
    helper_escalation_policy VARCHAR(64) NOT NULL DEFAULT 'disabled',
    self_leg_cf_transport VARCHAR(16) NOT NULL DEFAULT 'cf_direct'
        COMMENT 'Self-leg transport under CF Bond. Values: cf_direct (default) | cf_wg.',
    desired_latency_ms INT NOT NULL DEFAULT 0,
    allow_public_self_leg_donation TINYINT(1) NOT NULL DEFAULT 0,
    donation_qualify_window_seconds INT NOT NULL DEFAULT 60,
    donation_revoke_window_seconds INT NOT NULL DEFAULT 30,
    donation_min_open_ports INT NOT NULL DEFAULT 1,
    bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'plain_tcp_mux',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forward_combinations_sidecar FOREIGN KEY (sidecar_point_id) REFERENCES sidecar_points(id),
    CONSTRAINT fk_forward_combinations_cf_group FOREIGN KEY (cf_location_group_id) REFERENCES cf_location_groups(id)
);

CREATE TABLE IF NOT EXISTS forward_combination_helper_groups (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    node_group_key VARCHAR(191) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forward_combination_helper_groups_combo FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE,
    INDEX idx_forward_combination_helper_groups_combo (forward_combination_id, sort_order),
    INDEX idx_forward_combination_helper_groups_group (node_group_key)
);


CREATE TABLE IF NOT EXISTS forward_combination_slaves (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    selection_kind VARCHAR(32) NOT NULL DEFAULT 'admin_pin',
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fc_slaves_combo
      FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_slaves_node
      FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_fc_slave_node (forward_combination_id, node_id, selection_kind),
    INDEX idx_fc_slaves_combo (forward_combination_id, is_active, sort_order),
    INDEX idx_fc_slaves_node (node_id, is_active)
);

CREATE TABLE IF NOT EXISTS forward_combination_self_leg_donors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    desktop_client_session_id BIGINT UNSIGNED NULL,
    device_id VARCHAR(191) NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'qualifying'
        COMMENT 'self_leg_donor_identity_table: qualifying | active | revoked | cooldown',
    donor_public_host VARCHAR(255) NULL,
    donor_public_port INT NULL,
    donor_transport VARCHAR(32) NOT NULL DEFAULT 'tcp',
    last_measured_latency_ms DECIMAL(10,2) NULL,
    qualify_started_at DATETIME NULL,
    activated_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoke_reason VARCHAR(64) NULL,
    open_ports_json TEXT NULL,
    last_self_check_json JSON NULL,
    last_reverse_probe_json JSON NULL,
    last_heartbeat_at DATETIME NULL,
    owner_opt_in TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fc_self_leg_donors_combo
      FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_self_leg_donors_client
      FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_self_leg_donors_desktop_session
      FOREIGN KEY (desktop_client_session_id) REFERENCES desktop_client_sessions(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_fc_self_leg_donor_session (forward_combination_id, client_id, desktop_client_session_id, device_id),
    INDEX idx_fc_self_leg_donors_state (forward_combination_id, state, updated_at),
    INDEX idx_fc_self_leg_donors_client (client_id, state),
    INDEX idx_fc_self_leg_donors_endpoint (donor_public_host, donor_public_port)
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
    default_client_online_device_limit INT NOT NULL DEFAULT 1,
    max_client_profiles INT NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_tenant_visible TINYINT(1) NOT NULL DEFAULT 1,
    feature_flags_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS service_plan_forward_combinations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_plan_forward_combination_plan FOREIGN KEY (plan_id) REFERENCES service_plans(id),
    CONSTRAINT fk_plan_forward_combination_combo FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id),
    UNIQUE KEY uniq_plan_forward_combination (plan_id, forward_combination_id)
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
    bond_listen_port INT NOT NULL,
    mptcp_listen_port INT NOT NULL,
    cf_listen_port INT NULL,
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
    bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'wss_path_mux',
    bond_sidecar_point_id BIGINT UNSIGNED NULL,
    forward_combination_id BIGINT UNSIGNED NULL,
    backend_mode VARCHAR(32) NOT NULL DEFAULT 'current_mode',
    cf_location_group_id BIGINT UNSIGNED NULL,
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
    is_default_for_owner TINYINT(1) NOT NULL DEFAULT 0,
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
    online_device_limit INT NOT NULL DEFAULT 1,
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
    INDEX idx_clients_owner_default_status (owner_user_id, is_default_for_owner, status),
    INDEX idx_clients_status (status)
);

CREATE TABLE IF NOT EXISTS client_device_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    device_name VARCHAR(191) NULL,
    platform VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    last_ip VARCHAR(64) NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    last_pull_at DATETIME NULL,
    last_heartbeat_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_client_device_sessions_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_client_device (client_id, device_id),
    INDEX idx_client_device_sessions_seen (client_id, last_seen_at)
);

CREATE TABLE IF NOT EXISTS desktop_client_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_token VARCHAR(128) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    email VARCHAR(255) NOT NULL,
    username VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_desktop_client_sessions_token (session_token),
    KEY idx_desktop_client_sessions_user (user_id)
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
    INDEX idx_provision_tasks_node_status (node_id, status),
    -- Composite index matching collapsePendingProvisionTasksForNode's WHERE predicate.
    -- Without it MySQL used an index_merge intersect and locked rows in inconsistent
    -- order across concurrent heartbeats, tripping InnoDB deadlocks (SQLSTATE 40001).
    INDEX idx_provision_tasks_collapse (node_id, action, service_id, forward_id, endpoint_id, status)
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

CREATE TABLE IF NOT EXISTS bond_leg_reputation_current (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    observer_type VARCHAR(32) NOT NULL,
    observer_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    node_id BIGINT UNSIGNED NULL,
    host VARCHAR(255) NOT NULL,
    state VARCHAR(32) NOT NULL,
    score DECIMAL(10,4) NOT NULL DEFAULT 0,
    effective_weight DECIMAL(10,4) NOT NULL DEFAULT 0,
    delivered_ewma DECIMAL(18,4) NOT NULL DEFAULT 0,
    penalty_ewma DECIMAL(18,4) NOT NULL DEFAULT 0,
    last_reason TEXT NULL,
    last_good_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_current_leg (observer_type, observer_id, forward_id, endpoint_id, protocol),
    INDEX idx_bond_leg_reputation_current_state (state, updated_at),
    INDEX idx_bond_leg_reputation_current_forward (forward_id, protocol)
);

CREATE TABLE IF NOT EXISTS bond_leg_reputation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    observer_type VARCHAR(32) NOT NULL,
    observer_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    host VARCHAR(255) NOT NULL,
    from_state VARCHAR(32) NULL,
    to_state VARCHAR(32) NOT NULL,
    score DECIMAL(10,4) NOT NULL DEFAULT 0,
    effective_weight DECIMAL(10,4) NOT NULL DEFAULT 0,
    reason TEXT NULL,
    observed_at DATETIME NOT NULL,
    INDEX idx_bond_leg_reputation_events_observed (observed_at),
    INDEX idx_bond_leg_reputation_events_forward (forward_id, protocol)
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


-- migration mirror snippets for regression coverage
-- ALTER TABLE sidecar_points ADD COLUMN cf_listen_port INT NULL AFTER mptcp_listen_port;
-- ALTER TABLE forwards ADD COLUMN forward_combination_id BIGINT UNSIGNED NULL AFTER bond_sidecar_point_id;
-- ALTER TABLE forwards ADD COLUMN backend_mode VARCHAR(32) NOT NULL DEFAULT 'current_mode' AFTER forward_combination_id;
-- ALTER TABLE forwards ADD COLUMN cf_location_group_id BIGINT UNSIGNED NULL AFTER backend_mode;
