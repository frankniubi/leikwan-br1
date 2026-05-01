use serde::Deserialize;

#[derive(Debug, Clone, Deserialize, PartialEq, Eq)]
pub struct ClientLegConfig {
    pub endpoint_id: u64,
    pub host: String,
    pub port: u16,
    pub auth_token: String,
    #[serde(default)]
    pub dynamic_priority: u16,
    #[serde(default = "default_health_role")]
    pub health_role: String,
    #[serde(default = "default_weight")]
    pub weight: u16,
}

#[derive(Debug, Clone, Deserialize, PartialEq, Eq)]
pub struct ClientConfig {
    pub forward_id: u64,
    pub listen_udp: String,
    pub route_key: String,
    pub session_token: String,
    pub reorder_window_packets: usize,
    pub reorder_window_bytes: usize,
    pub reorder_timeout_ms: u64,
    pub legs: Vec<ClientLegConfig>,
    #[serde(default = "default_client_stats_path")]
    pub stats_path: String,
    #[serde(default = "default_session_idle_timeout_ms")]
    pub session_idle_timeout_ms: u64,
}

#[derive(Debug, Clone, Deserialize, PartialEq, Eq)]
pub struct SidecarConfig {
    pub listen_host: String,
    pub listen_port: u16,
    pub token_secret: String,
    pub allowed_forward_ids: Vec<u64>,
    pub reorder_window_packets: usize,
    pub reorder_window_bytes: usize,
    pub reorder_timeout_ms: u64,
    pub stats_path: String,
    #[serde(default = "default_session_idle_timeout_ms")]
    pub session_idle_timeout_ms: u64,
}

fn default_health_role() -> String {
    String::from("healthy")
}

fn default_weight() -> u16 {
    1
}

fn default_client_stats_path() -> String {
    String::from("/run/leikwan/uot-bond-forward.stats.json")
}

fn default_session_idle_timeout_ms() -> u64 {
    120_000
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn client_config_accepts_existing_json_shape() {
        let raw = r#"{
          "forward_id": 26,
          "listen_udp": "0.0.0.0:11024",
          "route_key": "a43547b6350f72cd.uot",
          "session_token": "udp-token",
          "reorder_window_packets": 4096,
          "reorder_window_bytes": 16777216,
          "reorder_timeout_ms": 25,
          "legs": [
            {
              "endpoint_id": 184,
              "host": "8.163.90.132",
              "port": 7001,
              "auth_token": "token-1",
              "dynamic_priority": 10,
              "health_role": "healthy",
              "weight": 8
            }
          ]
        }"#;
        let parsed: ClientConfig = serde_json::from_str(raw).expect("client config");
        assert_eq!(parsed.forward_id, 26);
        assert_eq!(parsed.legs[0].weight, 8);
    }

    #[test]
    fn sidecar_config_accepts_existing_json_shape() {
        let raw = r#"{
          "listen_host": "0.0.0.0",
          "listen_port": 8999,
          "token_secret": "secret-123",
          "allowed_forward_ids": [26],
          "reorder_window_packets": 4096,
          "reorder_window_bytes": 16777216,
          "reorder_timeout_ms": 25,
          "stats_path": "/var/lib/leikwan-sidecar-4/udp-uot-runtime-stats.json"
        }"#;
        let parsed: SidecarConfig = serde_json::from_str(raw).expect("sidecar config");
        assert_eq!(parsed.listen_port, 8999);
        assert_eq!(parsed.allowed_forward_ids, vec![26]);
    }
}
