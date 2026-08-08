use anyhow::{bail, Result};
use serde::Deserialize;

use crate::frame::FRAME_HEADER_BYTES;

const STREAM_PREFIX_BYTES: usize = 4;
const MAX_PROCESS_QUEUE_BYTES: usize = 64 * 1024 * 1024;
const MAX_PROCESS_QUEUE_PACKETS: usize = 262_144;
const MAX_PROCESS_OUTSTANDING_BYTES: usize = 64 * 1024 * 1024;
const MAX_PROCESS_SESSIONS: usize = 65_536;

#[derive(Debug, Clone, Copy, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum DeliveryMode {
    Ordered,
    Unordered,
}

impl DeliveryMode {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Ordered => "ordered",
            Self::Unordered => "unordered",
        }
    }
}

#[derive(Debug, Clone, Copy, Deserialize, PartialEq, Eq)]
#[serde(rename_all = "snake_case")]
pub enum SchedulerMode {
    Legacy,
    FeedbackDrr,
}

impl Default for SchedulerMode {
    fn default() -> Self {
        Self::Legacy
    }
}

impl SchedulerMode {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Legacy => "legacy",
            Self::FeedbackDrr => "feedback_drr",
        }
    }
}

#[derive(Debug, Clone, Deserialize, PartialEq, Eq)]
pub struct SchedulerConfig {
    #[serde(default)]
    pub scheduler_mode: SchedulerMode,
    #[serde(default = "default_aggregate_rate_bps")]
    pub aggregate_rate_bps: u64,
    #[serde(default = "default_queue_target_ms")]
    pub queue_target_ms: u64,
    #[serde(default = "default_queue_deadline_ms")]
    pub queue_deadline_ms: u64,
    #[serde(default = "default_global_queue_bytes")]
    pub global_queue_bytes: usize,
    #[serde(default = "default_global_queue_packets")]
    pub global_queue_packets: usize,
    #[serde(default = "default_per_session_queue_bytes")]
    pub per_session_queue_bytes: usize,
    #[serde(default = "default_per_session_queue_packets")]
    pub per_session_queue_packets: usize,
    #[serde(default = "default_drr_quantum_bytes")]
    pub drr_quantum_bytes: usize,
    #[serde(default = "default_chunk_min_bytes")]
    pub chunk_min_bytes: usize,
    #[serde(default = "default_chunk_target_bytes")]
    pub chunk_target_bytes: usize,
    #[serde(default = "default_chunk_max_bytes")]
    pub chunk_max_bytes: usize,
    #[serde(default = "default_min_leg_window_bytes")]
    pub min_leg_window_bytes: usize,
    #[serde(default = "default_max_leg_window_bytes")]
    pub max_leg_window_bytes: usize,
    #[serde(default = "default_max_total_outstanding_bytes")]
    pub max_total_outstanding_bytes: usize,
    #[serde(default = "default_progress_interval_bytes")]
    pub progress_interval_bytes: u64,
    #[serde(default = "default_progress_interval_frames")]
    pub progress_interval_frames: u64,
    #[serde(default = "default_progress_interval_ms")]
    pub progress_interval_ms: u64,
    #[serde(default = "default_finish_skew_budget_ms")]
    pub finish_skew_budget_ms: u64,
    #[serde(default = "default_probe_interval_ms")]
    pub probe_interval_ms: u64,
    #[serde(default = "default_max_sessions")]
    pub max_sessions: usize,
    #[serde(default = "default_control_queue_packets")]
    pub control_queue_packets: usize,
}

impl Default for SchedulerConfig {
    fn default() -> Self {
        Self {
            scheduler_mode: SchedulerMode::Legacy,
            aggregate_rate_bps: default_aggregate_rate_bps(),
            queue_target_ms: default_queue_target_ms(),
            queue_deadline_ms: default_queue_deadline_ms(),
            global_queue_bytes: default_global_queue_bytes(),
            global_queue_packets: default_global_queue_packets(),
            per_session_queue_bytes: default_per_session_queue_bytes(),
            per_session_queue_packets: default_per_session_queue_packets(),
            drr_quantum_bytes: default_drr_quantum_bytes(),
            chunk_min_bytes: default_chunk_min_bytes(),
            chunk_target_bytes: default_chunk_target_bytes(),
            chunk_max_bytes: default_chunk_max_bytes(),
            min_leg_window_bytes: default_min_leg_window_bytes(),
            max_leg_window_bytes: default_max_leg_window_bytes(),
            max_total_outstanding_bytes: default_max_total_outstanding_bytes(),
            progress_interval_bytes: default_progress_interval_bytes(),
            progress_interval_frames: default_progress_interval_frames(),
            progress_interval_ms: default_progress_interval_ms(),
            finish_skew_budget_ms: default_finish_skew_budget_ms(),
            probe_interval_ms: default_probe_interval_ms(),
            max_sessions: default_max_sessions(),
            control_queue_packets: default_control_queue_packets(),
        }
    }
}

impl SchedulerConfig {
    pub fn burst_bytes(&self, max_wire_frame_bytes: usize) -> usize {
        let two_ms = ((u128::from(self.aggregate_rate_bps) * 2) / 8_000) as usize;
        max_wire_frame_bytes.max(two_ms)
    }

    pub fn validate(&self, max_datagram_bytes: usize) -> Result<()> {
        if self.aggregate_rate_bps == 0 {
            bail!("aggregate_rate_bps must be positive");
        }
        if self.queue_target_ms > self.queue_deadline_ms {
            bail!("queue_target_ms must not exceed queue_deadline_ms");
        }
        if self.queue_deadline_ms == 0 {
            bail!("queue_deadline_ms must be positive");
        }
        if self.global_queue_bytes == 0
            || self.global_queue_bytes > MAX_PROCESS_QUEUE_BYTES
            || self.global_queue_packets == 0
            || self.global_queue_packets > MAX_PROCESS_QUEUE_PACKETS
        {
            bail!("global queue limits are outside process bounds");
        }
        if self.per_session_queue_bytes == 0
            || self.per_session_queue_bytes > self.global_queue_bytes
            || self.per_session_queue_packets == 0
            || self.per_session_queue_packets > self.global_queue_packets
        {
            bail!("per-session queue limits must fit global queue limits");
        }
        if self.drr_quantum_bytes == 0 {
            bail!("drr_quantum_bytes must be positive");
        }
        if self.chunk_min_bytes == 0
            || self.chunk_min_bytes > self.chunk_target_bytes
            || self.chunk_target_bytes > self.chunk_max_bytes
        {
            bail!("chunk sizes must satisfy 0 < min <= target <= max");
        }
        if self.chunk_max_bytes < max_datagram_bytes {
            bail!("chunk_max_bytes must fit max_datagram_bytes");
        }
        if self.min_leg_window_bytes == 0
            || self.min_leg_window_bytes > self.max_leg_window_bytes
            || self.max_leg_window_bytes > self.max_total_outstanding_bytes
            || self.max_total_outstanding_bytes > MAX_PROCESS_OUTSTANDING_BYTES
        {
            bail!("leg windows must satisfy 0 < min <= max <= total process bound");
        }
        if self.progress_interval_bytes == 0
            || self.progress_interval_frames == 0
            || self.progress_interval_ms == 0
            || self.probe_interval_ms == 0
        {
            bail!("progress and probe intervals must be positive");
        }
        if self.max_sessions == 0 || self.max_sessions > MAX_PROCESS_SESSIONS {
            bail!("max_sessions is outside process bounds");
        }
        if self.control_queue_packets == 0 || self.control_queue_packets > MAX_PROCESS_QUEUE_PACKETS
        {
            bail!("control_queue_packets is outside process bounds");
        }
        let max_wire_frame_bytes = STREAM_PREFIX_BYTES + FRAME_HEADER_BYTES + max_datagram_bytes;
        if self.burst_bytes(max_wire_frame_bytes) < max_wire_frame_bytes {
            bail!("token bucket burst must fit one maximum wire frame");
        }
        Ok(())
    }
}

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
    #[serde(default)]
    pub direct_tcp: bool,
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
    #[serde(default = "default_delivery_mode")]
    pub delivery_mode: DeliveryMode,
    #[serde(default)]
    pub wireguard_aware: bool,
    #[serde(default = "default_max_leg_queue_packets")]
    pub max_leg_queue_packets: usize,
    #[serde(default = "default_max_leg_queue_bytes")]
    pub max_leg_queue_bytes: usize,
    #[serde(default = "default_max_datagram_bytes")]
    pub max_datagram_bytes: usize,
    #[serde(default = "default_udp_socket_buffer_bytes")]
    pub udp_socket_buffer_bytes: usize,
    pub legs: Vec<ClientLegConfig>,
    #[serde(default = "default_client_stats_path")]
    pub stats_path: String,
    #[serde(default = "default_session_idle_timeout_ms")]
    pub session_idle_timeout_ms: u64,
    #[serde(flatten)]
    pub scheduler: SchedulerConfig,
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
    #[serde(default = "default_delivery_mode")]
    pub delivery_mode: DeliveryMode,
    #[serde(default)]
    pub wireguard_aware: bool,
    #[serde(default = "default_max_leg_queue_packets")]
    pub max_leg_queue_packets: usize,
    #[serde(default = "default_max_leg_queue_bytes")]
    pub max_leg_queue_bytes: usize,
    #[serde(default = "default_max_datagram_bytes")]
    pub max_datagram_bytes: usize,
    #[serde(default = "default_udp_socket_buffer_bytes")]
    pub udp_socket_buffer_bytes: usize,
    pub stats_path: String,
    #[serde(default = "default_session_idle_timeout_ms")]
    pub session_idle_timeout_ms: u64,
    #[serde(flatten)]
    pub scheduler: SchedulerConfig,
}

fn default_aggregate_rate_bps() -> u64 {
    300_000_000
}

fn default_queue_target_ms() -> u64 {
    5
}

fn default_queue_deadline_ms() -> u64 {
    10
}

fn default_global_queue_bytes() -> usize {
    1_048_576
}

fn default_global_queue_packets() -> usize {
    8_192
}

fn default_per_session_queue_bytes() -> usize {
    262_144
}

fn default_per_session_queue_packets() -> usize {
    512
}

fn default_drr_quantum_bytes() -> usize {
    16_384
}

fn default_chunk_min_bytes() -> usize {
    16_384
}

fn default_chunk_target_bytes() -> usize {
    32_768
}

fn default_chunk_max_bytes() -> usize {
    65_536
}

fn default_min_leg_window_bytes() -> usize {
    262_144
}

fn default_max_leg_window_bytes() -> usize {
    4_194_304
}

fn default_max_total_outstanding_bytes() -> usize {
    8_388_608
}

fn default_progress_interval_bytes() -> u64 {
    65_536
}

fn default_progress_interval_frames() -> u64 {
    32
}

fn default_progress_interval_ms() -> u64 {
    5
}

fn default_finish_skew_budget_ms() -> u64 {
    2
}

fn default_probe_interval_ms() -> u64 {
    1_000
}

fn default_max_sessions() -> usize {
    4_096
}

fn default_control_queue_packets() -> usize {
    256
}

fn default_health_role() -> String {
    String::from("healthy")
}

fn default_delivery_mode() -> DeliveryMode {
    DeliveryMode::Ordered
}

fn default_max_leg_queue_packets() -> usize {
    2_048
}

fn default_max_leg_queue_bytes() -> usize {
    16 * 1024 * 1024
}

fn default_max_datagram_bytes() -> usize {
    65_535
}

fn default_udp_socket_buffer_bytes() -> usize {
    8 * 1024 * 1024
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

fn validate_delivery_config(
    delivery_mode: DeliveryMode,
    wireguard_aware: bool,
    max_leg_queue_packets: usize,
    max_leg_queue_bytes: usize,
    max_datagram_bytes: usize,
    udp_socket_buffer_bytes: usize,
) -> Result<()> {
    if matches!(delivery_mode, DeliveryMode::Unordered) && !wireguard_aware {
        bail!("unordered delivery requires wireguard_aware=true");
    }
    if max_leg_queue_packets == 0 {
        bail!("max_leg_queue_packets must be positive");
    }
    if max_leg_queue_bytes == 0 {
        bail!("max_leg_queue_bytes must be positive");
    }
    if !(1..=65_535).contains(&max_datagram_bytes) {
        bail!("max_datagram_bytes must be between 1 and 65535");
    }
    if udp_socket_buffer_bytes == 0 {
        bail!("udp_socket_buffer_bytes must be positive");
    }
    Ok(())
}

impl ClientConfig {
    pub fn validate(&self) -> Result<()> {
        validate_delivery_config(
            self.delivery_mode,
            self.wireguard_aware,
            self.max_leg_queue_packets,
            self.max_leg_queue_bytes,
            self.max_datagram_bytes,
            self.udp_socket_buffer_bytes,
        )?;
        if matches!(self.scheduler.scheduler_mode, SchedulerMode::FeedbackDrr)
            && !matches!(self.delivery_mode, DeliveryMode::Unordered)
        {
            bail!("feedback_drr requires unordered delivery");
        }
        self.scheduler.validate(self.max_datagram_bytes)
    }
}

impl SidecarConfig {
    pub fn validate(&self) -> Result<()> {
        validate_delivery_config(
            self.delivery_mode,
            self.wireguard_aware,
            self.max_leg_queue_packets,
            self.max_leg_queue_bytes,
            self.max_datagram_bytes,
            self.udp_socket_buffer_bytes,
        )?;
        if matches!(self.scheduler.scheduler_mode, SchedulerMode::FeedbackDrr)
            && !matches!(self.delivery_mode, DeliveryMode::Unordered)
        {
            bail!("feedback_drr requires unordered delivery");
        }
        self.scheduler.validate(self.max_datagram_bytes)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const LEGACY_CLIENT_JSON: &str = r#"{
      "forward_id": 3,
      "listen_udp": "127.0.0.1:33113",
      "route_key": "test",
      "session_token": "test",
      "reorder_window_packets": 16,
      "reorder_window_bytes": 4096,
      "reorder_timeout_ms": 25,
      "legs": []
    }"#;

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
        assert_eq!(parsed.udp_socket_buffer_bytes, 8 * 1024 * 1024);
    }

    #[test]
    fn client_config_defaults_to_ordered_delivery() {
        let raw = r#"{
          "forward_id": 3,
          "listen_udp": "127.0.0.1:33113",
          "route_key": "test",
          "session_token": "test",
          "reorder_window_packets": 16,
          "reorder_window_bytes": 4096,
          "reorder_timeout_ms": 25,
          "legs": []
        }"#;
        let parsed: ClientConfig = serde_json::from_str(raw).expect("legacy client config");
        assert_eq!(parsed.delivery_mode, DeliveryMode::Ordered);
        assert_eq!(parsed.max_leg_queue_packets, 2_048);
        assert_eq!(parsed.max_leg_queue_bytes, 16 * 1024 * 1024);
        assert_eq!(parsed.max_datagram_bytes, 65_535);
        assert_eq!(parsed.udp_socket_buffer_bytes, 8 * 1024 * 1024);
        assert!(!parsed.wireguard_aware);
        parsed.validate().expect("legacy config stays valid");
    }

    #[test]
    fn client_config_rejects_unordered_without_wireguard_contract() {
        let raw = r#"{
          "forward_id": 3,
          "listen_udp": "127.0.0.1:33113",
          "route_key": "test",
          "session_token": "test",
          "reorder_window_packets": 16,
          "reorder_window_bytes": 4096,
          "reorder_timeout_ms": 25,
          "delivery_mode": "unordered",
          "wireguard_aware": false,
          "legs": []
        }"#;
        let parsed: ClientConfig = serde_json::from_str(raw).expect("config syntax");
        assert_eq!(
            parsed
                .validate()
                .expect_err("unordered must be WireGuard scoped")
                .to_string(),
            "unordered delivery requires wireguard_aware=true"
        );
    }

    #[test]
    fn legacy_json_defaults_to_legacy_scheduler() {
        let parsed: ClientConfig = serde_json::from_str(LEGACY_CLIENT_JSON).unwrap();
        assert_eq!(parsed.scheduler.scheduler_mode, SchedulerMode::Legacy);
        assert_eq!(parsed.scheduler.aggregate_rate_bps, 300_000_000);
        assert_eq!(parsed.scheduler.queue_target_ms, 5);
        assert_eq!(parsed.scheduler.queue_deadline_ms, 10);
        parsed.validate().unwrap();
    }

    #[test]
    fn feedback_defaults_match_the_approved_contract() {
        let cfg = SchedulerConfig {
            scheduler_mode: SchedulerMode::FeedbackDrr,
            ..SchedulerConfig::default()
        };
        assert_eq!(cfg.global_queue_bytes, 1_048_576);
        assert_eq!(cfg.global_queue_packets, 8_192);
        assert_eq!(cfg.per_session_queue_bytes, 262_144);
        assert_eq!(cfg.per_session_queue_packets, 512);
        assert_eq!(cfg.drr_quantum_bytes, 16_384);
        assert_eq!(
            (
                cfg.chunk_min_bytes,
                cfg.chunk_target_bytes,
                cfg.chunk_max_bytes
            ),
            (16_384, 32_768, 65_536)
        );
        assert_eq!(
            (cfg.min_leg_window_bytes, cfg.max_leg_window_bytes),
            (262_144, 4_194_304)
        );
        assert_eq!(cfg.max_total_outstanding_bytes, 8_388_608);
        assert_eq!(
            (
                cfg.progress_interval_bytes,
                cfg.progress_interval_frames,
                cfg.progress_interval_ms
            ),
            (65_536, 32, 5)
        );
        assert_eq!(cfg.finish_skew_budget_ms, 2);
        assert_eq!(cfg.probe_interval_ms, 1_000);
        assert_eq!(cfg.max_sessions, 4_096);
        assert_eq!(cfg.control_queue_packets, 256);
    }

    #[test]
    fn feedback_validation_rejects_an_impossible_contract() {
        let mut cfg = SchedulerConfig {
            scheduler_mode: SchedulerMode::FeedbackDrr,
            ..SchedulerConfig::default()
        };
        cfg.queue_target_ms = 11;
        assert_eq!(
            cfg.validate(65_535).unwrap_err().to_string(),
            "queue_target_ms must not exceed queue_deadline_ms"
        );
        cfg.queue_target_ms = 5;
        cfg.chunk_max_bytes = 65_534;
        assert_eq!(
            cfg.validate(65_535).unwrap_err().to_string(),
            "chunk_max_bytes must fit max_datagram_bytes"
        );
    }

    #[test]
    fn feedback_runtime_rejects_ordered_delivery() {
        let mut parsed: ClientConfig = serde_json::from_str(LEGACY_CLIENT_JSON).unwrap();
        parsed.scheduler.scheduler_mode = SchedulerMode::FeedbackDrr;
        assert_eq!(
            parsed.validate().unwrap_err().to_string(),
            "feedback_drr requires unordered delivery"
        );
    }
}
