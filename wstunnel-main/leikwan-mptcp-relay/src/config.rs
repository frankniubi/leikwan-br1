use serde::Deserialize;
use std::path::Path;

/// Client-side relay configuration.
#[derive(Debug, Deserialize)]
#[allow(dead_code)]
pub struct ClientConfig {
    pub forward_id: u64,
    pub listen_port: u16,
    pub transport: String,
    pub remote_host: String,
    pub remote_port: u16,

    #[serde(default)]
    pub tcp_session_token: String,
    #[serde(default)]
    pub udp_session_token: String,
    #[serde(default = "default_udp_mode")]
    pub udp_mode: String,
    #[serde(default = "default_congestion")]
    pub tcp_congestion_control: String,
    #[serde(default = "default_socket_buffer")]
    pub socket_buffer_bytes: usize,
    #[serde(default = "default_connect_timeout")]
    pub connect_timeout_ms: u64,
    #[serde(default = "default_idle_timeout")]
    pub relay_idle_timeout_seconds: u64,
    #[serde(default)]
    pub speed_limit_mbps: u64,

    #[serde(default)]
    pub manage_mptcp_endpoints: bool,
    #[serde(default)]
    pub mptcp_endpoint_device: String,
    #[serde(default)]
    pub mptcp_endpoint_id_base: u32,
    #[serde(default)]
    pub mptcp_endpoints: Vec<serde_json::Value>,
    #[serde(default)]
    pub endpoints: Vec<serde_json::Value>,

    #[serde(default)]
    pub underlay: serde_json::Value,
    #[serde(default)]
    pub underlays: Vec<serde_json::Value>,

    #[serde(default = "default_initcwnd")]
    pub initcwnd: u32,
    #[serde(default = "default_initrwnd")]
    pub initrwnd: u32,
    #[serde(default = "default_add_addr_timeout")]
    pub add_addr_timeout: u32,
    #[serde(default = "default_tcp_wmem_max")]
    pub tcp_wmem_max: u64,
    #[serde(default = "default_tcp_rmem_max")]
    pub tcp_rmem_max: u64,
    #[serde(default = "default_subflow_limit")]
    pub subflow_limit: u32,
}

/// Server-side relay configuration.
#[derive(Debug, Deserialize)]
#[allow(dead_code)]
pub struct ServerConfig {
    pub listen_host: String,
    pub listen_port: u16,
    pub token_secret: String,

    #[serde(default = "default_congestion")]
    pub tcp_congestion_control: String,
    #[serde(default = "default_socket_buffer")]
    pub socket_buffer_bytes: usize,
    #[serde(default)]
    pub allow_tcp_fallback: bool,
    #[serde(default)]
    pub stats_path: String,

    #[serde(default)]
    pub manage_mptcp_endpoints: bool,
    #[serde(default)]
    pub underlay: serde_json::Value,
}

// ---- default value functions ----

fn default_udp_mode() -> String {
    "stream_compat".to_string()
}

fn default_congestion() -> String {
    "bbr".to_string()
}

fn default_socket_buffer() -> usize {
    8 * 1024 * 1024 // 8 MB
}

fn default_connect_timeout() -> u64 {
    3000
}

fn default_idle_timeout() -> u64 {
    120
}

fn default_initcwnd() -> u32 {
    80
}

fn default_initrwnd() -> u32 {
    80
}

fn default_add_addr_timeout() -> u32 {
    5
}

fn default_tcp_wmem_max() -> u64 {
    16_777_216
}

fn default_tcp_rmem_max() -> u64 {
    16_777_216
}

fn default_subflow_limit() -> u32 {
    2
}

/// Load and parse a ClientConfig from a JSON file.
pub fn load_client_config<P: AsRef<Path>>(path: P) -> anyhow::Result<ClientConfig> {
    let data = std::fs::read_to_string(path.as_ref())
        .map_err(|e| anyhow::anyhow!("failed to read client config {:?}: {}", path.as_ref(), e))?;
    let config: ClientConfig = serde_json::from_str(&data)
        .map_err(|e| anyhow::anyhow!("failed to parse client config: {}", e))?;
    Ok(config)
}

/// Load and parse a ServerConfig from a JSON file.
pub fn load_server_config<P: AsRef<Path>>(path: P) -> anyhow::Result<ServerConfig> {
    let data = std::fs::read_to_string(path.as_ref())
        .map_err(|e| anyhow::anyhow!("failed to read server config {:?}: {}", path.as_ref(), e))?;
    let config: ServerConfig = serde_json::from_str(&data)
        .map_err(|e| anyhow::anyhow!("failed to parse server config: {}", e))?;
    Ok(config)
}
