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

CREATE TABLE IF NOT EXISTS oidc_identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    issuer TEXT NOT NULL,
    subject TEXT NOT NULL,
    issuer_hash CHAR(64) NOT NULL,
    subject_hash CHAR(64) NOT NULL,
    email VARCHAR(191) NOT NULL,
    email_verified_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_oidc_identities_user FOREIGN KEY (user_id) REFERENCES users(id),
    UNIQUE KEY uniq_oidc_identity_subject (issuer_hash, subject_hash),
    UNIQUE KEY uniq_oidc_identity_user_issuer (user_id, issuer_hash),
    INDEX idx_oidc_identities_user (user_id),
    INDEX idx_oidc_identities_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_whmcs_login_challenges_user FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_whmcs_login_challenges_user (user_id, created_at),
    INDEX idx_whmcs_login_challenges_expiry (expires_at, verified_at)
);

CREATE TABLE IF NOT EXISTS whmcs_sso_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL UNIQUE,
    user_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NULL,
    redirect_path VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_whmcs_sso_tokens_expiry (expires_at)
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
    line_direction VARCHAR(191) NULL,
    ultra_destination_zone VARCHAR(32) NULL,
    ultra_allowed_ingress_zones_json JSON NULL,
    ultra_fallback_policy VARCHAR(32) NOT NULL DEFAULT 'detected_then_any',
    external_uot_enabled TINYINT(1) NOT NULL DEFAULT 0,
    external_uot_sidecar_target VARCHAR(255) NULL,
    external_uot_token VARCHAR(8192) NULL,
    external_uot_hmac_secret VARCHAR(128) NULL,
    external_uot_queue_packets INT NOT NULL DEFAULT 256,
    external_uot_keepalive_seconds INT NOT NULL DEFAULT 10,
    external_uot_reconnect_grace_seconds INT NOT NULL DEFAULT 30,
    external_uot_frame_payload_max INT NOT NULL DEFAULT 65507,
    node_group_key VARCHAR(191) NOT NULL,
    -- Forward Mode: 'current_mode' (direct ingress) | 'cf_mode' (Cloudflare ingress) |
    -- 'leigod_mode' (direct ingress + LeiGod self-leg; derives self_leg_outer_transport=leigod) |
    -- 'ultra' (short-lived capability contract; separate from legacy LeiGod mode).
    backend_mode VARCHAR(32) NOT NULL DEFAULT 'current_mode',
    sidecar_point_id BIGINT UNSIGNED NULL,
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
    self_leg_outer_transport ENUM('cnix','cf','leigod') NOT NULL DEFAULT 'cnix',
    leigod_line_id INT UNSIGNED NULL,
    leigod_socks_user VARCHAR(64) NULL,
    leigod_socks_pass VARCHAR(128) NULL,
    leigod_uot_impl ENUM('gost','lguot') NOT NULL DEFAULT 'gost',
    desired_latency_ms INT NOT NULL DEFAULT 0,
    slave_wg_ideal_latency_ms INT NULL,
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
    max_forward_rule_quantity INT NOT NULL DEFAULT 64,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_tenant_visible TINYINT(1) NOT NULL DEFAULT 1,
    geo_lock_enabled TINYINT(1) NOT NULL DEFAULT 0,
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

CREATE TABLE IF NOT EXISTS forward_routes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    tenant_label VARCHAR(191) NULL,
    tenant_description TEXT NULL,
    tenant_badge VARCHAR(64) NULL,
    race_enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS forward_route_combinations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_route_id BIGINT UNSIGNED NOT NULL,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_route_combo_route FOREIGN KEY (forward_route_id) REFERENCES forward_routes(id),
    CONSTRAINT fk_route_combo_combo FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id),
    UNIQUE KEY uniq_route_combination (forward_route_id, forward_combination_id)
);

CREATE TABLE IF NOT EXISTS service_plan_forward_routes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    forward_route_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_plan_route_plan FOREIGN KEY (plan_id) REFERENCES service_plans(id),
    CONSTRAINT fk_plan_route_route FOREIGN KEY (forward_route_id) REFERENCES forward_routes(id),
    UNIQUE KEY uniq_plan_forward_route (plan_id, forward_route_id)
);

CREATE TABLE IF NOT EXISTS services (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    plan_id BIGINT UNSIGNED NULL,
    name VARCHAR(191) NOT NULL,
    whmcs_service_id BIGINT NOT NULL UNIQUE,
    server_hostname VARCHAR(255) NULL,
    server_public_ip VARCHAR(64) NULL,
    server_ip_state ENUM('fresh', 'stale', 'missing') NOT NULL DEFAULT 'missing',
    server_ip_last_synced_at DATETIME NULL,
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
    api_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
    api_idempotency_key_hash CHAR(64) NULL,
    api_request_hash CHAR(64) NULL,
    user_deleted_at DATETIME NULL,
    balance_suspended_at DATETIME NULL,
    target_source VARCHAR(32) NOT NULL DEFAULT 'manual',
    target_service_id BIGINT UNSIGNED NULL,
    listen_port INT NOT NULL,
    target_host VARCHAR(255) NOT NULL,
    target_port INT NOT NULL,
    bond_remote_host VARCHAR(255) NULL,
    bond_remote_port INT NULL,
    bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'wss_path_mux',
    bond_sidecar_point_id BIGINT UNSIGNED NULL,
    forward_combination_id BIGINT UNSIGNED NULL,
    forward_route_id BIGINT UNSIGNED NULL,
    active_combination_id BIGINT UNSIGNED NULL,
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
    native_outbound_enabled TINYINT(1) NOT NULL DEFAULT 0,
    native_direction_key VARCHAR(64) NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forwards_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_forwards_target_service FOREIGN KEY (target_service_id) REFERENCES services(id),
    CONSTRAINT fk_forwards_line_client FOREIGN KEY (line_client_id) REFERENCES clients(id),
    CONSTRAINT fk_forwards_line_client_group FOREIGN KEY (line_client_group_id) REFERENCES client_groups(id),
    CONSTRAINT fk_forwards_sidecar_point FOREIGN KEY (bond_sidecar_point_id) REFERENCES sidecar_points(id),
    CONSTRAINT fk_forwards_chain FOREIGN KEY (forward_chain_id) REFERENCES forward_chains(id),
    UNIQUE KEY uniq_forward_service_idempotency (service_id, api_idempotency_key_hash),
    UNIQUE KEY uniq_forwards_native_direction (service_id, native_direction_key),
    INDEX idx_forwards_service (service_id),
    INDEX idx_forwards_target_service (target_service_id)
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
    allow_paid_lines TINYINT(1) NOT NULL DEFAULT 0,
    selected_carriers_json JSON NULL,
    runtime_platform_scope VARCHAR(32) NOT NULL DEFAULT 'all',
    agent_token VARCHAR(128) NULL,
    agent_version VARCHAR(64) NULL,
    last_sync_at DATETIME NULL,
    last_diag_requested_at DATETIME NULL,
    last_diag_request_token VARCHAR(128) NULL,
    last_diag_status VARCHAR(32) NULL,
    last_diag_run_at DATETIME NULL,
    last_diag_result_json JSON NULL,
    last_diag_error TEXT NULL,
    debug_collection_uuid VARCHAR(64) NULL,
    debug_collection_expires_at DATETIME NULL,
    debug_collection_status VARCHAR(16) NOT NULL DEFAULT 'closed',
    debug_collection_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    debug_collection_next_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
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
    INDEX idx_clients_status (status),
    UNIQUE KEY idx_clients_debug_collection_uuid (debug_collection_uuid)
);

CREATE TABLE IF NOT EXISTS client_debug_chunks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    collection_uuid VARCHAR(64) NOT NULL,
    sequence BIGINT UNSIGNED NOT NULL,
    chunk_sha256 CHAR(64) NOT NULL,
    byte_length INT UNSIGNED NOT NULL,
    storage_path VARCHAR(768) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE (client_id, collection_uuid, sequence)
);

CREATE TABLE IF NOT EXISTS client_device_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    device_name VARCHAR(191) NULL,
    platform VARCHAR(64) NULL,
    client_version VARCHAR(64) NULL,
    os_release VARCHAR(191) NULL,
    user_agent VARCHAR(255) NULL,
    last_ip VARCHAR(64) NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    last_pull_at DATETIME NULL,
    last_heartbeat_at DATETIME NULL,
    ultra_status_json JSON NULL,
    ultra_status_updated_at DATETIME NULL,
    ultra_runtime_fingerprint VARCHAR(64) NULL,
    ultra_runtime_self_sha256 CHAR(64) NULL,
    ultra_runtime_debugger TINYINT(1) NULL,
    ultra_runtime_instrumented TINYINT(1) NULL,
    ultra_runtime_attested_at DATETIME NULL,
    ultra_lease_key_sha256 CHAR(64) NULL,
    ultra_lease_key_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_client_device_sessions_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_client_device (client_id, device_id),
    INDEX idx_client_device_sessions_seen (client_id, last_seen_at),
    INDEX idx_client_device_sessions_runtime_fingerprint (ultra_runtime_fingerprint, last_seen_at)
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

CREATE TABLE IF NOT EXISTS leigod_only_activation_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    setting_key VARCHAR(191) NOT NULL,
    setting_value_json JSON NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_activation_setting (scope, setting_key),
    INDEX idx_leigod_activation_settings_fence (scope, owner_token, fencing_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_activation_plans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    plan_hash CHAR(64) NOT NULL,
    plan_state VARCHAR(32) NOT NULL DEFAULT 'planned',
    backend_mode VARCHAR(32) NOT NULL DEFAULT 'leigod_mode',
    client_mode VARCHAR(32) NOT NULL DEFAULT 'bond',
    runtime_preference VARCHAR(64) NOT NULL DEFAULT 'single',
    bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'plain_tcp_mux',
    active_family VARCHAR(32) NOT NULL DEFAULT 'standalone_bond',
    platform_family VARCHAR(32) NOT NULL,
    desired_generation BIGINT UNSIGNED NOT NULL,
    desired_state_hash CHAR(64) NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    plan_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_activation_plan_hash (plan_hash),
    INDEX idx_leigod_activation_plan_fence (scope, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_activation_plan_write_hash (write_hash),
    INDEX idx_leigod_activation_plan_state (scope, plan_state, desired_generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_activation_batches (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    plan_id BIGINT UNSIGNED NULL,
    batch_hash CHAR(64) NOT NULL,
    batch_state VARCHAR(32) NOT NULL DEFAULT 'planned',
    affected_forward_ids_json JSON NOT NULL,
    desired_generation BIGINT UNSIGNED NOT NULL,
    desired_state_hash CHAR(64) NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    batch_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_activation_batch_hash (batch_hash),
    INDEX idx_leigod_activation_batch_fence (scope, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_activation_batch_state (scope, batch_state, desired_generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_activation_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    plan_id BIGINT UNSIGNED NULL,
    batch_id BIGINT UNSIGNED NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    client_device_session_id BIGINT UNSIGNED NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    desired_generation BIGINT UNSIGNED NOT NULL,
    logical_leg_key VARCHAR(191) NOT NULL,
    protocol VARCHAR(16) NOT NULL DEFAULT 'tcp',
    current_slot_key VARCHAR(191) NULL,
    assignment_state VARCHAR(32) NOT NULL DEFAULT 'planned',
    assignment_hash CHAR(64) NOT NULL,
    desired_leg_set_hash CHAR(64) NOT NULL,
    active_family VARCHAR(32) NOT NULL DEFAULT 'standalone_bond',
    runtime_contract_json JSON NOT NULL,
    row_expires_at DATETIME NOT NULL DEFAULT '2099-01-01 00:00:00',
    artifact_snapshot_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    assignment_json JSON NOT NULL,
    superseded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_activation_assignment_current_slot (current_slot_key),
    INDEX idx_leigod_activation_assignment_forward (forward_id, desired_generation),
    INDEX idx_leigod_activation_assignment_fence (scope, forward_id, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_activation_assignment_leg_key (logical_leg_key),
    INDEX idx_leigod_activation_assignment_slot_identity (scope, forward_id, client_id, client_device_session_id, current_slot_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_activation_assignment_current_slots (
    slot_key VARCHAR(191) NOT NULL,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    assignment_id BIGINT UNSIGNED NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    client_device_session_id BIGINT UNSIGNED NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    desired_generation BIGINT UNSIGNED NOT NULL,
    logical_leg_key VARCHAR(191) NOT NULL,
    protocol VARCHAR(16) NOT NULL DEFAULT 'tcp',
    slot_state VARCHAR(32) NOT NULL DEFAULT 'current',
    assignment_hash CHAR(64) NOT NULL,
    desired_leg_set_hash CHAR(64) NOT NULL,
    active_family VARCHAR(32) NOT NULL DEFAULT 'standalone_bond',
    artifact_snapshot_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (slot_key),
    INDEX idx_leigod_current_slot_fence (scope, forward_id, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_current_slot_write_hash (write_hash),
    INDEX idx_leigod_current_slot_identity (scope, forward_id, client_id, client_device_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_forward_desired_state_fences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    forward_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    desktop_client_session_id BIGINT UNSIGNED NULL,
    client_device_session_id BIGINT UNSIGNED NULL,
    desired_generation BIGINT UNSIGNED NOT NULL,
    desired_state_hash CHAR(64) NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'fenced',
    current_slot_key VARCHAR(191) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    desired_state_json JSON NOT NULL,
    superseded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_desired_state_write (write_hash),
    UNIQUE KEY uniq_leigod_desired_state_current_slot (current_slot_key),
    INDEX idx_leigod_desired_state_exact_fence (forward_id, owner_token, fencing_token, desired_generation),
    INDEX idx_leigod_desired_state_hash (forward_id, desired_state_hash, artifact_snapshot_hash),
    INDEX idx_leigod_desired_state_sessions (client_id, desktop_client_session_id, client_device_session_id),
    INDEX idx_leigod_desired_state_slot_identity (scope, forward_id, client_id, desktop_client_session_id, client_device_session_id, current_slot_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_artifact_pointer_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    artifact_snapshot_hash CHAR(64) NOT NULL,
    snapshot_immutable TINYINT(1) NOT NULL DEFAULT 1,
    platform_family VARCHAR(32) NOT NULL,
    pointer_manifest_json JSON NOT NULL,
    artifact_hashes_json JSON NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_artifact_snapshot_hash (artifact_snapshot_hash),
    INDEX idx_leigod_artifact_snapshot_fence (scope, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_artifact_snapshot_platform (platform_family, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_artifact_pointer_states (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    forward_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    desktop_client_session_id BIGINT UNSIGNED NULL,
    client_device_session_id BIGINT UNSIGNED NULL,
    desired_generation BIGINT UNSIGNED NOT NULL,
    desired_state_hash CHAR(64) NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    current_slot_key VARCHAR(191) NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    pointer_state_json JSON NOT NULL,
    superseded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_pointer_state_write (write_hash),
    UNIQUE KEY uniq_leigod_pointer_state_current_slot (current_slot_key),
    INDEX idx_leigod_pointer_state_exact_fence (forward_id, owner_token, fencing_token, desired_generation),
    INDEX idx_leigod_pointer_state_hash (forward_id, desired_state_hash, artifact_snapshot_hash),
    INDEX idx_leigod_pointer_state_sessions (client_id, desktop_client_session_id, client_device_session_id),
    INDEX idx_leigod_pointer_state_slot_identity (scope, forward_id, client_id, desktop_client_session_id, client_device_session_id, current_slot_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_fenced_publish_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    job_hash CHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    client_device_session_id BIGINT UNSIGNED NULL,
    desktop_client_session_id BIGINT UNSIGNED NULL,
    current_slot_key VARCHAR(191) NULL,
    desired_generation BIGINT UNSIGNED NOT NULL,
    desired_state_hash CHAR(64) NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    publish_fence_token CHAR(64) NOT NULL,
    job_state VARCHAR(32) NOT NULL DEFAULT 'pending',
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    payload_json JSON NOT NULL,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    leased_until DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_fenced_publish_job_hash (job_hash),
    INDEX idx_leigod_publish_job_slot_identity (scope, forward_id, client_id, desktop_client_session_id, client_device_session_id, current_slot_key),
    INDEX idx_leigod_publish_exact_fence (forward_id, owner_token, fencing_token, publish_fence_token, desired_generation),
    INDEX idx_leigod_publish_queue (job_state, available_at, forward_id),
    INDEX idx_leigod_publish_desired_hash (forward_id, desired_state_hash, artifact_snapshot_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_rollout_locks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    lock_key VARCHAR(191) NOT NULL,
    forward_id BIGINT UNSIGNED NULL,
    lock_state VARCHAR(32) NOT NULL DEFAULT 'held',
    artifact_snapshot_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    lease_expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_rollout_lock (scope, lock_key),
    INDEX idx_leigod_rollout_lock_exact_fence (scope, lock_key, owner_token, fencing_token),
    INDEX idx_leigod_rollout_lock_forward_fence (forward_id, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_rollout_lock_lease (lock_state, lease_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_canary_campaigns (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    campaign_hash CHAR(64) NOT NULL,
    campaign_state VARCHAR(32) NOT NULL DEFAULT 'planned',
    affected_forward_ids_json JSON NOT NULL,
    desired_generation BIGINT UNSIGNED NOT NULL,
    desired_state_hash CHAR(64) NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    campaign_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_canary_campaign_hash (campaign_hash),
    INDEX idx_leigod_canary_campaign_fence (scope, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_canary_campaign_state (scope, campaign_state, desired_generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_canary_forward_status (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    campaign_id BIGINT UNSIGNED NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    desired_generation BIGINT UNSIGNED NOT NULL,
    desired_state_hash CHAR(64) NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    status_json JSON NOT NULL,
    first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_canary_forward_status_write (write_hash),
    INDEX idx_leigod_canary_status_fence (scope, forward_id, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_canary_status_campaign (campaign_id, status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_activation_audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    event_hash CHAR(64) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NULL,
    desired_generation BIGINT UNSIGNED NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    actor VARCHAR(191) NULL,
    event_json JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_activation_audit_event_hash (event_hash),
    INDEX idx_leigod_activation_audit_fence (scope, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_activation_audit_forward (forward_id, event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_normalization_activation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    forward_id BIGINT UNSIGNED NOT NULL,
    plan_hash CHAR(64) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    event_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_normalization_event (forward_id, plan_hash, event_type),
    INDEX idx_leigod_normalization_event_fence (scope, forward_id, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_normalization_event_type (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_only_retirement_activation_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    forward_id BIGINT UNSIGNED NOT NULL,
    record_hash CHAR(64) NOT NULL,
    retirement_state VARCHAR(32) NOT NULL DEFAULT 'planned',
    desired_generation BIGINT UNSIGNED NOT NULL,
    desired_state_hash CHAR(64) NOT NULL,
    artifact_snapshot_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    write_hash CHAR(64) NOT NULL,
    external_authorization_ref VARCHAR(191) NULL,
    record_json JSON NOT NULL,
    activated_at DATETIME NULL,
    retired_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_leigod_retirement_record_hash (record_hash),
    INDEX idx_leigod_retirement_fence (scope, forward_id, owner_token, fencing_token, artifact_snapshot_hash),
    INDEX idx_leigod_retirement_state (retirement_state, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS forward_retirement_cleanup_leases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL DEFAULT 'global',
    forward_id BIGINT UNSIGNED NOT NULL,
    lease_token CHAR(64) NOT NULL,
    result_token CHAR(64) NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'pending',
    cleanup_protocol VARCHAR(64) NOT NULL DEFAULT 'forward_retirement_v1',
    artifact_snapshot_hash CHAR(64) NOT NULL,
    owner_token CHAR(64) NOT NULL,
    fencing_token CHAR(64) NOT NULL,
    resources_json JSON NOT NULL,
    result_json JSON NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_forward_retirement_cleanup_lease (forward_id, lease_token, result_token),
    INDEX idx_forward_retirement_cleanup_pending (state, updated_at),
    INDEX idx_forward_retirement_cleanup_fence (scope, forward_id, owner_token, fencing_token, artifact_snapshot_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS leigod_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    line_id INT UNSIGNED NOT NULL,
    label VARCHAR(191) NOT NULL,
    notes TEXT NULL,
    tcp_probe_host VARCHAR(255) NOT NULL,
    tcp_probe_port INT UNSIGNED NOT NULL,
    udp_probe_host VARCHAR(255) NULL,
    udp_probe_port INT UNSIGNED NULL,
    udp_probe_method VARCHAR(32) NULL,
    config_sha256 CHAR(64) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_leigod_external_line (line_id)
);

CREATE TABLE IF NOT EXISTS ultra_sources (
    source_id VARCHAR(191) NOT NULL PRIMARY KEY,
    display_label VARCHAR(191) NULL,
    priority INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
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
    INDEX idx_ultra_sources_seen (last_seen_at),
    INDEX idx_ultra_sources_priority (is_active, priority, source_id)
);

CREATE TABLE IF NOT EXISTS ultra_zones (
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

CREATE TABLE IF NOT EXISTS ultra_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_id VARCHAR(191) NOT NULL,
    line_id INT UNSIGNED NOT NULL,
    source_title VARCHAR(255) NULL,
    source_family_key VARCHAR(255) NULL,
    game_id INT UNSIGNED NULL,
    area_id INT UNSIGNED NULL,
    line_type INT UNSIGNED NULL,
    in_line_type INT UNSIGNED NULL,
    isp VARCHAR(32) NOT NULL DEFAULT 'unknown',
    provider VARCHAR(64) NULL,
    dispatch_class VARCHAR(16) NOT NULL DEFAULT 'normal',
    admin_dispatch_class VARCHAR(16) NULL DEFAULT NULL,
    admin_dispatch_note VARCHAR(255) NULL DEFAULT NULL,
    discovery_ip VARCHAR(64) NULL,
    catalog_ingress_label VARCHAR(191) NULL,
    catalog_destination_label VARCHAR(191) NULL,
    config_sha256 CHAR(64) NOT NULL,
    access_class VARCHAR(32) NOT NULL DEFAULT 'unknown',
    native_udp_state VARCHAR(32) NOT NULL DEFAULT 'unknown',
    native_socks5_state VARCHAR(16) NOT NULL DEFAULT 'unknown',
    direction_class VARCHAR(32) NOT NULL DEFAULT 'unknown',
    route_class VARCHAR(32) NOT NULL DEFAULT 'unknown',
    pricing_class VARCHAR(16) NOT NULL DEFAULT 'unknown',
    pricing_evidence VARCHAR(255) NULL,
    audit_at DATETIME NULL,
    audit_hash CHAR(64) NULL,
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
    INDEX idx_ultra_lines_quality (access_class, native_udp_state, native_socks5_state, pricing_class, is_source_present, is_enabled),
    INDEX idx_ultra_lines_carrier (isp, route_class, is_source_present, is_enabled),
    INDEX idx_ultra_lines_dispatch (dispatch_class, is_source_present, is_enabled),
    INDEX idx_ultra_lines_material_expiry (last_material_expires_at)
);

CREATE TABLE IF NOT EXISTS forward_combination_ultra_lines (
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

CREATE TABLE IF NOT EXISTS ultra_candidate_reputation_current (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    candidate_key_hash CHAR(64) NOT NULL,
    source_id VARCHAR(191) NOT NULL,
    line_id INT UNSIGNED NOT NULL,
    ingress_zone VARCHAR(32) NOT NULL,
    destination_zone VARCHAR(32) NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'healthy',
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    consecutive_successes INT UNSIGNED NOT NULL DEFAULT 0,
    independent_observers INT UNSIGNED NOT NULL DEFAULT 0,
    backoff_level INT UNSIGNED NOT NULL DEFAULT 0,
    quarantine_until DATETIME NULL,
    last_reason VARCHAR(64) NULL,
    last_observed_at DATETIME NULL,
    last_good_at DATETIME NULL,
    sample_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_ultra_candidate_reputation_scope (candidate_key_hash),
    INDEX idx_ultra_candidate_reputation_state (state, quarantine_until, updated_at),
    INDEX idx_ultra_candidate_reputation_route (source_id, line_id, ingress_zone, destination_zone, protocol)
);

CREATE TABLE IF NOT EXISTS ultra_candidate_reputation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    candidate_key_hash CHAR(64) NOT NULL,
    event_type VARCHAR(16) NOT NULL,
    state VARCHAR(16) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    observer_hash CHAR(64) NULL,
    evidence_hash CHAR(64) NOT NULL,
    observed_at DATETIME NOT NULL,
    UNIQUE KEY uniq_ultra_candidate_reputation_evidence (candidate_key_hash, evidence_hash),
    INDEX idx_ultra_candidate_reputation_events_observed (observed_at),
    INDEX idx_ultra_candidate_reputation_events_candidate (candidate_key_hash, event_type, observed_at)
);

CREATE TABLE IF NOT EXISTS ultra_candidate_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    candidate_key_hash CHAR(64) NOT NULL,
    source_id VARCHAR(191) NOT NULL,
    line_id INT UNSIGNED NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    requested_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    result_json LONGTEXT NULL,
    INDEX idx_ultra_candidate_reviews_state (state, requested_at),
    INDEX idx_ultra_candidate_reviews_source (source_id, state, requested_at),
    INDEX idx_ultra_candidate_reviews_candidate (candidate_key_hash, requested_at)
);

CREATE TABLE IF NOT EXISTS ultra_observer_devices (
    observer_hash CHAR(64) NOT NULL PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    strikes INT UNSIGNED NOT NULL DEFAULT 0,
    demoted_at DATETIME NULL,
    last_rebuttal_at DATETIME NULL,
    last_decay_at DATETIME NULL,
    last_seen_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ultra_observer_devices_client (client_id, last_seen_at),
    INDEX idx_ultra_observer_devices_demoted (demoted_at, strikes)
);

CREATE TABLE IF NOT EXISTS ultra_lease_refusals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_hash CHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    error_code VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ultra_lease_refusal_created (created_at),
    INDEX idx_ultra_lease_refusal_client (client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Panel-observed Ultra connected time (20261003_add_ultra_lease_time.sql):
-- union of lease windows per client + device + forward + UTC day.
CREATE TABLE IF NOT EXISTS ultra_lease_time (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NULL,
    device_hash CHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    period_day DATE NOT NULL,
    leased_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lease_count INT UNSIGNED NOT NULL DEFAULT 0,
    covered_until BIGINT NOT NULL DEFAULT 0,
    first_issued_at DATETIME NOT NULL,
    last_issued_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_ultra_lease_time_day (client_id, device_hash, forward_id, period_day),
    INDEX idx_ultra_lease_time_client_day (client_id, period_day),
    INDEX idx_ultra_lease_time_service_day (service_id, period_day),
    INDEX idx_ultra_lease_time_forward_day (forward_id, period_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS forward_combination_leigod_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    leigod_line_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fcll_combination FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id),
    CONSTRAINT fk_fcll_line FOREIGN KEY (leigod_line_id) REFERENCES leigod_lines(id),
    UNIQUE KEY uniq_combination_leigod_line (forward_combination_id, leigod_line_id)
);

CREATE TABLE IF NOT EXISTS node_leigod_line_health (
    node_id BIGINT UNSIGNED NOT NULL,
    leigod_line_id BIGINT UNSIGNED NOT NULL,
    config_sha256 CHAR(64) NOT NULL,
    service_state VARCHAR(32) NOT NULL,
    tcp_status VARCHAR(32) NOT NULL,
    tcp_connect_ms INT UNSIGNED NULL,
    udp_status VARCHAR(32) NOT NULL,
    consecutive_successes INT UNSIGNED NOT NULL DEFAULT 0,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    checked_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (node_id, leigod_line_id),
    CONSTRAINT fk_nllh_node FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    CONSTRAINT fk_nllh_line FOREIGN KEY (leigod_line_id) REFERENCES leigod_lines(id) ON DELETE CASCADE,
    INDEX idx_nllh_line_freshness (leigod_line_id, checked_at)
);

CREATE TABLE IF NOT EXISTS knowledgebase_topics (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    topic_key VARCHAR(190) NOT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_knowledgebase_topics_key (topic_key),
    KEY idx_knowledgebase_topics_reader (is_pinned, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS knowledgebase_articles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    topic_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(16) NOT NULL,
    author_user_id BIGINT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    excerpt TEXT NULL,
    body_html MEDIUMTEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_knowledgebase_articles_topic FOREIGN KEY (topic_id) REFERENCES knowledgebase_topics(id),
    CONSTRAINT fk_knowledgebase_articles_author FOREIGN KEY (author_user_id) REFERENCES users(id),
    UNIQUE KEY uniq_knowledgebase_article_locale (topic_id, locale),
    KEY idx_knowledgebase_articles_reader (locale, status, topic_id),
    KEY idx_knowledgebase_articles_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_device_controls (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    identity_key_hash CHAR(64) NOT NULL,
    attestation_key_hash CHAR(64) NULL,
    state ENUM('active', 'kicked', 'banned') NOT NULL DEFAULT 'active',
    blocked_until DATETIME NULL,
    block_origin VARCHAR(32) NULL,
    release_policy VARCHAR(32) NULL,
    reason_code VARCHAR(64) NULL,
    changed_by_user_id BIGINT UNSIGNED NULL,
    changed_at DATETIME NULL,
    trust_state ENUM('trusted', 'untrusted') NOT NULL DEFAULT 'untrusted',
    trust_reason VARCHAR(64) NULL,
    command_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_acknowledged_command_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    acknowledged_at DATETIME NULL,
    last_attested_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_client_device_controls_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_client_device_control (client_id, device_id),
    INDEX idx_client_device_control_state (client_id, state, blocked_until, last_seen_at)
);

CREATE TABLE IF NOT EXISTS android_device_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    session_hash CHAR(64) NOT NULL,
    command_version BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_android_device_sessions_control FOREIGN KEY (client_id, device_id)
        REFERENCES client_device_controls(client_id, device_id) ON DELETE CASCADE,
    UNIQUE KEY uniq_android_device_session_hash (session_hash),
    INDEX idx_android_device_session_active (client_id, device_id, expires_at)
);

CREATE TABLE IF NOT EXISTS android_device_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    nonce_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_android_device_challenges_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_android_device_challenge_nonce (nonce_hash),
    INDEX idx_android_device_challenge_expiry (expires_at)
);

CREATE TABLE IF NOT EXISTS android_enrollment_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_android_enrollment_codes_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_android_enrollment_code_hash (code_hash),
    INDEX idx_android_enrollment_code_expiry (expires_at)
);

CREATE TABLE IF NOT EXISTS openwrt_pairing_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_openwrt_pairing_codes_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_openwrt_pairing_code_hash (code_hash),
    INDEX idx_openwrt_pairing_code_expiry (expires_at)
);

CREATE TABLE IF NOT EXISTS client_device_control_audits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_id VARCHAR(191) NOT NULL,
    command_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    event_type VARCHAR(64) NOT NULL,
    reason VARCHAR(255) NULL,
    actor_type VARCHAR(32) NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_client_device_control_audits_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    INDEX idx_client_device_control_audit_device (client_id, device_id, created_at)
);

CREATE TABLE IF NOT EXISTS access_blacklist_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(32) NOT NULL,
    canonical_value VARCHAR(255) NOT NULL,
    display_value VARCHAR(255) NOT NULL,
    ip_family TINYINT NULL,
    ip_start VARBINARY(16) NULL,
    ip_end VARBINARY(16) NULL,
    province_code VARCHAR(32) NULL,
    city_code VARCHAR(64) NULL,
    country_code VARCHAR(32) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    reason_code VARCHAR(64) NOT NULL,
    admin_note VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_access_blacklist_entity (entity_type, canonical_value),
    INDEX idx_access_blacklist_active_type (entity_type, is_active),
    INDEX idx_access_blacklist_ip (ip_family, ip_start, ip_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_geo_lock_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'unbound',
    main_province_code VARCHAR(32) NULL,
    previous_province_code VARCHAR(32) NULL,
    bound_observation_id BIGINT UNSIGNED NULL,
    last_verified_at DATETIME NULL,
    self_change_available_at DATETIME NULL,
    reset_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reset_started_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_user_geo_lock_profile_user (user_id),
    INDEX idx_user_geo_lock_profile_state (state, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_location_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token_hash CHAR(64) NOT NULL,
    auth_context_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    device_uuid VARCHAR(191) NOT NULL,
    attempt_id VARCHAR(128) NOT NULL,
    platform VARCHAR(32) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_access_location_challenge_token (token_hash),
    INDEX idx_access_location_challenge_binding (user_id, device_uuid, attempt_id),
    INDEX idx_access_location_challenge_expiry (expires_at, consumed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_location_observations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    challenge_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    device_uuid VARCHAR(191) NOT NULL,
    attempt_id VARCHAR(128) NOT NULL,
    platform VARCHAR(32) NOT NULL,
    collection_mode VARCHAR(32) NOT NULL,
    provider VARCHAR(64) NOT NULL,
    source_ip VARCHAR(64) NULL,
    public_ip VARCHAR(64) NULL,
    country_code VARCHAR(32) NULL,
    province_code VARCHAR(32) NULL,
    city_code VARCHAR(64) NULL,
    observed_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL,
    payload_digest CHAR(64) NOT NULL,
    INDEX idx_access_location_observation_lookup (user_id, device_uuid, source_ip, observed_at),
    INDEX idx_access_location_observation_attempt (user_id, attempt_id, collection_mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_policy_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    decision_id CHAR(32) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    device_uuid VARCHAR(191) NULL,
    source_ip VARCHAR(64) NULL,
    event_type VARCHAR(32) NOT NULL,
    decision VARCHAR(24) NOT NULL,
    reason_code VARCHAR(64) NOT NULL,
    matched_entity_type VARCHAR(32) NULL,
    matched_observation_id BIGINT UNSIGNED NULL,
    attempt_id VARCHAR(128) NULL,
    counts_as_geo_violation TINYINT(1) NOT NULL DEFAULT 0,
    details_digest CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_access_policy_attempt (user_id, attempt_id, event_type),
    UNIQUE KEY uniq_access_policy_decision (decision_id),
    INDEX idx_access_policy_user_time (user_id, created_at, counts_as_geo_violation),
    INDEX idx_access_policy_device_time (device_uuid, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS account_access_bans (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    origin VARCHAR(32) NOT NULL,
    reason_code VARCHAR(64) NOT NULL,
    evidence_event_ids_json JSON NOT NULL,
    banned_at DATETIME NOT NULL,
    released_at DATETIME NULL,
    released_by_user_id BIGINT UNSIGNED NULL,
    release_note VARCHAR(500) NULL,
    INDEX idx_account_access_ban_user (user_id, banned_at),
    INDEX idx_account_access_ban_active (user_id, released_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_reset_generations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    generation BIGINT UNSIGNED NOT NULL,
    previous_province_code VARCHAR(32) NULL,
    state VARCHAR(24) NOT NULL DEFAULT 'pending',
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY uniq_access_reset_generation (user_id, generation),
    INDEX idx_access_reset_generation_state (user_id, state, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS access_reset_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    reset_generation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    device_uuid VARCHAR(191) NOT NULL,
    blocked_until DATETIME NULL,
    block_created_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_access_reset_device (reset_generation_id, client_id, device_uuid),
    INDEX idx_access_reset_device_user (user_id, device_uuid),
    CONSTRAINT fk_access_reset_device_generation FOREIGN KEY (reset_generation_id) REFERENCES access_reset_generations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_hosted_nodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    sealed_config TEXT NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    source VARCHAR(16) NOT NULL DEFAULT 'console',
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_client_hosted_node_fingerprint (client_id, fingerprint),
    INDEX idx_client_hosted_nodes_client (client_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS client_hosted_node_pairings (
    client_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(16) NOT NULL DEFAULT 'console',
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (client_id, forward_id),
    INDEX idx_client_hosted_node_pairings_node (node_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS external_uot_usage_counters (
    source VARCHAR(191) NOT NULL,
    instance_id CHAR(32) NOT NULL,
    forward_id BIGINT NOT NULL,
    to_target_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    from_target_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (source, instance_id, forward_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS external_uot_usage_daily (
    forward_id BIGINT NOT NULL,
    usage_date DATE NOT NULL,
    service_id BIGINT NULL,
    sidecar_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    client_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    billed_extra_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reconciled_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (forward_id, usage_date),
    INDEX idx_external_uot_usage_daily_pending (reconciled_at, usage_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    bucket_key CHAR(64) NOT NULL PRIMARY KEY,
    window_started_at BIGINT UNSIGNED NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    expires_at BIGINT UNSIGNED NOT NULL,
    INDEX idx_auth_rate_limits_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
