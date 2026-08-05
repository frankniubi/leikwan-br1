use crate::pathd_line::{
    restart_backoff, validate_line_material, write_private_atomic, LineRuntime, LineRuntimePaths,
    LocalLineStatus, SystemLineClock,
};
use crate::session::{
    prepare_client_tcp_transport, run_prepared_client_tcp_listener, stop_client_tcp_transport,
    ClientTransportRuntime, SessionConfig,
};
use crate::strategy::ClientStrategy;
use crate::transport::{parse_leg_spec, LegDial, LegSpec};
use anyhow::{anyhow, bail, Context, Result};
use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use serde::{Deserialize, Serialize};
use std::collections::{HashMap, HashSet, VecDeque};
use std::fmt;
use std::net::{IpAddr, SocketAddr};
use std::path::{Path, PathBuf};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};
use tokio::sync::oneshot;
use tokio::task::JoinHandle;
use tokio::time::timeout;
use tokio_util::sync::CancellationToken;
use url::Url;
use uuid::Uuid;

pub const MAX_DESIRED_STATE_BYTES: usize = 4 * 1024 * 1024;
const MAX_LOCAL_LEASES: usize = 64;
const MAX_FORWARDS: usize = 32;
const MAX_LEGS_PER_FORWARD: usize = 16;
const MAX_TOTAL_LEGS: usize = 256;
const MAX_AUTH_REFS: usize = 256;
const MAX_WINDOW_BYTES: usize = 64 * 1024 * 1024;
const MAX_TOTAL_WINDOW_BYTES: usize = 128 * 1024 * 1024;
const DEFAULT_LOCAL_SOCKS_PORT_MIN: u16 = 20_000;
const DEFAULT_LOCAL_SOCKS_PORT_MAX: u16 = 29_999;
#[cfg(not(test))]
const LISTENER_READY_TIMEOUT: Duration = Duration::from_secs(5);
#[cfg(test)]
const LISTENER_READY_TIMEOUT: Duration = Duration::from_millis(750);
const LISTENER_DRAIN_TIMEOUT: Duration = Duration::from_secs(5);

#[derive(Clone, Deserialize, PartialEq)]
#[serde(transparent)]
pub struct SecretString(String);

impl SecretString {
    pub(crate) fn expose_secret(&self) -> &str {
        &self.0
    }

    fn is_empty(&self) -> bool {
        self.0.trim().is_empty()
    }

    #[cfg(test)]
    pub(crate) fn new_for_test(value: &str) -> Self {
        Self(value.to_string())
    }
}

impl fmt::Debug for SecretString {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str("[redacted]")
    }
}

impl fmt::Display for SecretString {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str("[redacted]")
    }
}

#[derive(Clone, Debug, Deserialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct DesiredState {
    pub generation: u64,
    pub provider: ProviderSpec,
    #[serde(default)]
    pub auth_refs: HashMap<String, SecretString>,
    pub local_leases: Vec<LocalLeaseSpec>,
    pub forwards: Vec<ForwardSpec>,
}

#[derive(Clone, Debug, Deserialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct ProviderSpec {
    pub binary: PathBuf,
    pub session_json: SecretString,
    pub session_sha256: String,
    #[serde(default = "default_local_socks_port_min")]
    pub local_socks_port_min: u16,
    #[serde(default = "default_local_socks_port_max")]
    pub local_socks_port_max: u16,
}

fn default_local_socks_port_min() -> u16 {
    DEFAULT_LOCAL_SOCKS_PORT_MIN
}

fn default_local_socks_port_max() -> u16 {
    DEFAULT_LOCAL_SOCKS_PORT_MAX
}

impl ProviderSpec {
    pub(crate) fn validate_local_socks_range(&self) -> Result<()> {
        if self.local_socks_port_min == 0 || self.local_socks_port_min > self.local_socks_port_max {
            bail!("local SOCKS port range is invalid");
        }
        Ok(())
    }

    pub(crate) fn allows_local_socks_port(&self, port: u16) -> bool {
        (self.local_socks_port_min..=self.local_socks_port_max).contains(&port)
    }
}

#[derive(Clone, Debug, Deserialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct LocalLeaseSpec {
    pub line_id: u32,
    pub lease_id: String,
    pub lease_generation: u64,
    pub max_lanes: u8,
    pub config_sha256: String,
    pub config_toml: SecretString,
    pub expires_at_unix: i64,
    pub socks_addr: SocketAddr,
}

#[derive(Clone, Deserialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct ForwardSpec {
    pub forward_id: u64,
    pub listen: SocketAddr,
    pub egress: SocketAddr,
    pub session_token: String,
    pub legs: Vec<DesiredLegSpec>,
    pub policy: SessionConfigWire,
}

impl fmt::Debug for ForwardSpec {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter
            .debug_struct("ForwardSpec")
            .field("forward_id", &self.forward_id)
            .field("listen", &self.listen)
            .field("egress", &self.egress)
            .field("session_token", &"[redacted]")
            .field("legs", &self.legs)
            .field("policy", &self.policy)
            .finish()
    }
}

#[derive(Clone, Debug, Deserialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct DesiredLegSpec {
    pub uri: String,
    pub execution_group: String,
    pub line_id: u32,
    pub lease_id: String,
    pub lease_generation: u64,
    pub leg_route_id: u32,
}

impl DesiredLegSpec {
    fn runtime_uri(&self) -> Result<String> {
        let mut url = Url::parse(&self.uri)
            .with_context(|| format!("invalid leg URI for route {}", self.leg_route_id))?;
        let has_route = url.query_pairs().any(|(key, _)| key == "leg_route_id");
        if !has_route && matches!(url.scheme(), "socks5" | "ingress+tcp") {
            bail!("internal leg URI is missing route metadata");
        }
        if has_route {
            let parsed = parse_leg_spec(&self.uri)?;
            if parsed.leg_route_id != Some(self.leg_route_id) {
                bail!(
                    "leg route metadata does not match route {}",
                    self.leg_route_id
                );
            }
            return Ok(self.uri.clone());
        }
        url.query_pairs_mut()
            .append_pair("leg_route_id", &self.leg_route_id.to_string());
        Ok(url.into())
    }

    fn runtime_spec(
        &self,
        egress: SocketAddr,
        auth_refs: &HashMap<String, SecretString>,
    ) -> Result<LegSpec> {
        let runtime_uri = self.runtime_uri()?;
        let spec = parse_leg_spec(&runtime_uri)?;
        if spec.leg_route_id != Some(self.leg_route_id) {
            bail!(
                "leg route metadata does not match route {}",
                self.leg_route_id
            );
        }
        match spec.dial.clone() {
            LegDial::Socks5 {
                line_id,
                lease_generation,
                ..
            } => {
                if line_id != self.line_id || lease_generation != self.lease_generation {
                    bail!("SOCKS leg metadata does not match its lease authority");
                }
                spec.with_socks_connect_target(egress)
            }
            LegDial::IngressTcp { auth_ref, .. } => {
                let auth_token = auth_refs
                    .get(&auth_ref)
                    .ok_or_else(|| anyhow!("ingress leg auth reference is missing"))?;
                spec.with_ingress_auth_token(auth_token.expose_secret())
            }
            LegDial::Direct => Ok(spec),
        }
    }
}

#[derive(Clone, Debug, Deserialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct SessionConfigWire {
    pub chunk_size: usize,
    pub max_window_bytes: usize,
    pub retransmit_ms: u64,
    pub join_timeout_ms: u64,
    pub connect_timeout_ms: u64,
    pub preferred_initial_legs: usize,
    pub minimum_initial_legs: usize,
    pub initial_leg_wait_ms: u64,
    pub leg_reconnect_backoff_ms: u64,
    pub leg_reconnect_backoff_max_ms: u64,
    pub interactive_chunk_bytes: usize,
    pub latency_guard_bytes: usize,
    pub interactive_rtt_slack_ms: u64,
    pub warmup_trigger_bytes: usize,
    pub warmup_slice_bytes: usize,
    pub warmup_leg_delay_ms: u64,
    pub bulk_promote_bytes: usize,
    pub leg_cooldown_ms: u64,
    pub throughput_min_sample_bytes: u64,
    pub throughput_min_sample_ms: u64,
    pub adaptive_line_admission: bool,
    pub throughput_min_mbps: f64,
    pub throughput_relative_floor: f64,
    pub throughput_bad_windows: u32,
    pub throughput_recovery_windows: u32,
    pub throughput_quarantine_ms: u64,
    pub throughput_probe_interval_ms: u64,
    pub throughput_probe_bytes: u64,
    pub latency_first: bool,
    pub udp_primary_only: bool,
    pub default_strategy: String,
}

impl SessionConfigWire {
    fn checked(&self, leg_count: usize) -> Result<SessionConfig> {
        bounded_usize("chunk_size", self.chunk_size, 1024, 1024 * 1024)?;
        bounded_usize(
            "max_window_bytes",
            self.max_window_bytes,
            self.chunk_size,
            MAX_WINDOW_BYTES,
        )?;
        bounded_u64("retransmit_ms", self.retransmit_ms, 10, 60_000)?;
        bounded_u64("join_timeout_ms", self.join_timeout_ms, 10, 60_000)?;
        bounded_u64("connect_timeout_ms", self.connect_timeout_ms, 100, 120_000)?;
        if self.preferred_initial_legs == 0
            || self.minimum_initial_legs == 0
            || self.minimum_initial_legs > self.preferred_initial_legs
            || self.minimum_initial_legs > leg_count
        {
            bail!("invalid initial leg readiness policy");
        }
        bounded_u64("initial_leg_wait_ms", self.initial_leg_wait_ms, 1, 60_000)?;
        bounded_u64(
            "leg_reconnect_backoff_ms",
            self.leg_reconnect_backoff_ms,
            1,
            86_400_000,
        )?;
        bounded_u64(
            "leg_reconnect_backoff_max_ms",
            self.leg_reconnect_backoff_max_ms,
            self.leg_reconnect_backoff_ms,
            86_400_000,
        )?;
        bounded_usize(
            "interactive_chunk_bytes",
            self.interactive_chunk_bytes,
            1,
            self.chunk_size,
        )?;
        bounded_usize(
            "latency_guard_bytes",
            self.latency_guard_bytes,
            self.interactive_chunk_bytes,
            self.max_window_bytes,
        )?;
        bounded_u64(
            "interactive_rtt_slack_ms",
            self.interactive_rtt_slack_ms,
            1,
            10_000,
        )?;
        bounded_usize(
            "warmup_trigger_bytes",
            self.warmup_trigger_bytes,
            1,
            self.max_window_bytes,
        )?;
        bounded_usize(
            "warmup_slice_bytes",
            self.warmup_slice_bytes,
            1,
            self.max_window_bytes,
        )?;
        bounded_u64("warmup_leg_delay_ms", self.warmup_leg_delay_ms, 1, 60_000)?;
        bounded_usize(
            "bulk_promote_bytes",
            self.bulk_promote_bytes,
            1,
            self.max_window_bytes,
        )?;
        bounded_u64("leg_cooldown_ms", self.leg_cooldown_ms, 1, 86_400_000)?;
        bounded_u64(
            "throughput_min_sample_bytes",
            self.throughput_min_sample_bytes,
            1024,
            1024 * 1024 * 1024,
        )?;
        bounded_u64(
            "throughput_min_sample_ms",
            self.throughput_min_sample_ms,
            1,
            60_000,
        )?;
        bounded_f64(
            "throughput_min_mbps",
            self.throughput_min_mbps,
            0.001,
            10_000.0,
        )?;
        bounded_f64(
            "throughput_relative_floor",
            self.throughput_relative_floor,
            f64::EPSILON,
            1.0,
        )?;
        bounded_u32(
            "throughput_bad_windows",
            self.throughput_bad_windows,
            1,
            100,
        )?;
        bounded_u32(
            "throughput_recovery_windows",
            self.throughput_recovery_windows,
            1,
            100,
        )?;
        bounded_u64(
            "throughput_quarantine_ms",
            self.throughput_quarantine_ms,
            1,
            86_400_000,
        )?;
        bounded_u64(
            "throughput_probe_interval_ms",
            self.throughput_probe_interval_ms,
            1,
            86_400_000,
        )?;
        bounded_u64(
            "throughput_probe_bytes",
            self.throughput_probe_bytes,
            1024,
            1024 * 1024 * 1024,
        )?;
        let default_strategy = ClientStrategy::parse(&self.default_strategy)?;

        Ok(SessionConfig {
            chunk_size: self.chunk_size,
            max_window_bytes: self.max_window_bytes,
            retransmit_after: Duration::from_millis(self.retransmit_ms),
            join_timeout: Duration::from_millis(self.join_timeout_ms),
            connect_timeout: Duration::from_millis(self.connect_timeout_ms),
            preferred_initial_legs: self.preferred_initial_legs,
            minimum_initial_legs: self.minimum_initial_legs,
            initial_leg_wait: Duration::from_millis(self.initial_leg_wait_ms),
            leg_reconnect_backoff: Duration::from_millis(self.leg_reconnect_backoff_ms),
            leg_reconnect_backoff_max: Duration::from_millis(self.leg_reconnect_backoff_max_ms),
            interactive_chunk_bytes: self.interactive_chunk_bytes,
            latency_guard_bytes: self.latency_guard_bytes,
            interactive_rtt_slack: Duration::from_millis(self.interactive_rtt_slack_ms),
            warmup_trigger_bytes: self.warmup_trigger_bytes,
            warmup_slice_bytes: self.warmup_slice_bytes,
            warmup_leg_delay: Duration::from_millis(self.warmup_leg_delay_ms),
            bulk_promote_bytes: self.bulk_promote_bytes,
            leg_cooldown: Duration::from_millis(self.leg_cooldown_ms),
            throughput_min_sample_bytes: self.throughput_min_sample_bytes,
            throughput_min_sample: Duration::from_millis(self.throughput_min_sample_ms),
            adaptive_line_admission: self.adaptive_line_admission,
            throughput_min_mbps: self.throughput_min_mbps,
            throughput_relative_floor: self.throughput_relative_floor,
            throughput_bad_windows: self.throughput_bad_windows,
            throughput_recovery_windows: self.throughput_recovery_windows,
            throughput_quarantine: Duration::from_millis(self.throughput_quarantine_ms),
            throughput_probe_interval: Duration::from_millis(self.throughput_probe_interval_ms),
            throughput_probe_bytes: self.throughput_probe_bytes,
            latency_first: self.latency_first,
            udp_primary_only: self.udp_primary_only,
            strategy_file: None,
            default_strategy,
        })
    }
}

pub struct ValidatedDesiredState {
    pub desired: DesiredState,
    pub session_configs: HashMap<u64, SessionConfig>,
}

impl DesiredState {
    pub fn validate_at(self, now_unix: i64) -> Result<ValidatedDesiredState> {
        if self.generation == 0 {
            bail!("desired generation must be positive");
        }
        if !self.provider.binary.is_absolute() || self.provider.session_json.is_empty() {
            bail!("provider runtime material is incomplete");
        }
        validate_sha256("provider session", &self.provider.session_sha256)?;
        self.provider.validate_local_socks_range()?;

        if self.local_leases.len() > MAX_LOCAL_LEASES || self.forwards.len() > MAX_FORWARDS {
            bail!("desired state exceeds managed object limits");
        }
        if self.auth_refs.len() > MAX_AUTH_REFS {
            bail!("desired state exceeds ingress auth reference limit");
        }
        for (auth_ref, auth_token) in &self.auth_refs {
            if !valid_auth_ref(auth_ref)
                || auth_token.is_empty()
                || auth_token.expose_secret().len() > 2048
                || auth_token.expose_secret().chars().any(char::is_whitespace)
            {
                bail!("desired state contains an invalid ingress auth reference");
            }
        }

        let mut local_lines = HashSet::new();
        let mut local_fences = HashMap::new();
        let mut local_sockets = Vec::new();
        for lease in &self.local_leases {
            if lease.line_id == 0 || !local_lines.insert(lease.line_id) {
                bail!("local physical line must be positive and unique");
            }
            validate_fence(&lease.lease_id, lease.lease_generation)?;
            if !(1..=4).contains(&lease.max_lanes) {
                bail!("local max_lanes must be between one and four");
            }
            validate_sha256("local line config", &lease.config_sha256)?;
            if lease.config_toml.is_empty() || lease.expires_at_unix <= now_unix {
                bail!("local line material is empty or expired");
            }
            if !lease.socks_addr.ip().is_loopback()
                || !self
                    .provider
                    .allows_local_socks_port(lease.socks_addr.port())
            {
                bail!("local line endpoint must be a loopback socket");
            }
            if local_sockets
                .iter()
                .copied()
                .any(|active| listeners_conflict(active, lease.socks_addr))
            {
                bail!("local line endpoints conflict");
            }
            local_sockets.push(lease.socks_addr);
            local_fences.insert(
                lease.line_id,
                (
                    lease.lease_id.to_ascii_lowercase(),
                    lease.lease_generation,
                    usize::from(lease.max_lanes),
                    lease.socks_addr,
                ),
            );
        }

        let mut forward_ids = HashSet::new();
        let mut listeners = Vec::new();
        let mut line_authority = HashMap::<u32, (String, String, u64)>::new();
        let mut session_configs = HashMap::new();
        let mut total_legs = 0usize;
        let mut total_window_bytes = 0usize;
        for forward in &self.forwards {
            if forward.forward_id == 0 || !forward_ids.insert(forward.forward_id) {
                bail!("forward IDs must be positive and unique");
            }
            if forward.listen.port() == 0
                || listeners
                    .iter()
                    .copied()
                    .any(|active| listeners_conflict(active, forward.listen))
                || local_sockets
                    .iter()
                    .copied()
                    .any(|active| listeners_conflict(active, forward.listen))
            {
                bail!("forward listeners must be nonzero, unique, and separate from local lines");
            }
            listeners.push(forward.listen);
            if forward.egress.port() == 0 || forward.egress.ip().is_unspecified() {
                bail!("egress endpoint is invalid");
            }
            validate_token_claims(&forward.session_token, forward.forward_id, now_unix)?;
            if forward.legs.is_empty() || forward.legs.len() > MAX_LEGS_PER_FORWARD {
                bail!("forward must contain bond legs");
            }
            total_legs = total_legs.saturating_add(forward.legs.len());
            if total_legs > MAX_TOTAL_LEGS {
                bail!("desired state exceeds total leg limit");
            }
            total_window_bytes = total_window_bytes.saturating_add(forward.policy.max_window_bytes);
            if total_window_bytes > MAX_TOTAL_WINDOW_BYTES {
                bail!("desired state exceeds total window budget");
            }

            let mut route_ids = HashSet::new();
            let mut execution_groups = HashSet::new();
            let mut local_lane_counts = HashMap::<u32, usize>::new();
            for leg in &forward.legs {
                if leg.line_id == 0 || leg.leg_route_id == 0 || !route_ids.insert(leg.leg_route_id)
                {
                    bail!("leg line and route IDs must be positive and routes unique");
                }
                validate_fence(&leg.lease_id, leg.lease_generation)?;
                if leg.execution_group.trim().is_empty() {
                    bail!("leg execution group is empty");
                }
                if local_fences.contains_key(&leg.line_id)
                    && !leg.execution_group.starts_with("client:")
                {
                    bail!("local physical line cannot use node execution authority");
                }
                execution_groups.insert(leg.execution_group.as_str());
                let authority = (
                    leg.execution_group.clone(),
                    leg.lease_id.to_ascii_lowercase(),
                    leg.lease_generation,
                );
                if let Some(existing) = line_authority.get(&leg.line_id) {
                    if existing != &authority {
                        bail!("physical line has conflicting execution authority");
                    }
                } else {
                    line_authority.insert(leg.line_id, authority);
                }
                let parsed = validate_leg_uri(leg, forward.egress, &self.auth_refs)?;
                if leg.execution_group.starts_with("client:") {
                    let expected = local_fences
                        .get(&leg.line_id)
                        .ok_or_else(|| anyhow!("client leg has no matching local lease"))?;
                    if expected.0 != leg.lease_id.to_ascii_lowercase()
                        || expected.1 != leg.lease_generation
                    {
                        bail!("client leg lease fence is stale");
                    }
                    let parsed_ip = parsed
                        .host
                        .parse::<IpAddr>()
                        .context("client leg host is not an IP address")?;
                    if parsed_ip != expected.3.ip() || parsed.port != expected.3.port() {
                        bail!("client leg endpoint does not match its local lease");
                    }
                    let count = local_lane_counts.entry(leg.line_id).or_default();
                    *count = count.saturating_add(1);
                    if *count > expected.2 {
                        bail!("client leg count exceeds its leased lane cap");
                    }
                }
            }
            if execution_groups.len() < 2 {
                bail!("hybrid bond requires at least two execution groups");
            }
            session_configs.insert(
                forward.forward_id,
                forward.policy.checked(forward.legs.len())?,
            );
        }

        Ok(ValidatedDesiredState {
            desired: self,
            session_configs,
        })
    }
}

fn validate_leg_uri(
    leg: &DesiredLegSpec,
    egress: SocketAddr,
    auth_refs: &HashMap<String, SecretString>,
) -> Result<LegSpec> {
    let parsed = leg.runtime_spec(egress, auth_refs)?;
    if parsed.host.trim().is_empty() || parsed.port == 0 {
        bail!("leg URI endpoint is incomplete");
    }
    let url = Url::parse(&leg.uri)?;
    if !url.username().is_empty() || url.password().is_some() {
        bail!("inline leg credentials are forbidden");
    }
    for (key, _) in url.query_pairs() {
        if matches!(
            key.as_ref(),
            "target" | "target_host" | "target_port" | "token" | "auth"
        ) {
            bail!("leg URI contains forbidden target or credential override");
        }
    }
    let _ = leg.runtime_uri()?;
    Ok(parsed)
}

fn valid_auth_ref(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 128
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_' | b'.' | b':'))
}

fn desired_line_fences(desired: &DesiredState) -> Result<HashMap<u32, (String, u64)>> {
    let mut fences = HashMap::new();
    for (line_id, lease_id, generation) in desired
        .local_leases
        .iter()
        .map(|lease| {
            (
                lease.line_id,
                lease.lease_id.as_str(),
                lease.lease_generation,
            )
        })
        .chain(desired.forwards.iter().flat_map(|forward| {
            forward
                .legs
                .iter()
                .map(|leg| (leg.line_id, leg.lease_id.as_str(), leg.lease_generation))
        }))
    {
        let fence = (lease_id.to_ascii_lowercase(), generation);
        if let Some(existing) = fences.get(&line_id) {
            if existing != &fence {
                bail!("physical line contains conflicting lease fences");
            }
        } else {
            fences.insert(line_id, fence);
        }
    }
    Ok(fences)
}

fn listeners_conflict(left: SocketAddr, right: SocketAddr) -> bool {
    if left.port() != right.port() {
        return false;
    }
    if left.ip() == right.ip() {
        return true;
    }
    left.ip().is_unspecified() || right.ip().is_unspecified()
}

fn validate_token_claims(token: &str, forward_id: u64, now_unix: i64) -> Result<()> {
    let (payload, signature) = token
        .split_once('.')
        .ok_or_else(|| anyhow!("session token format is invalid"))?;
    if payload.is_empty() || signature.is_empty() {
        bail!("session token is empty");
    }
    let signature_bytes = URL_SAFE_NO_PAD
        .decode(signature)
        .context("session token signature is not base64url")?;
    if signature_bytes.len() != 32 {
        bail!("session token signature length is invalid");
    }
    let decoded = URL_SAFE_NO_PAD
        .decode(payload)
        .context("session token payload is not base64url")?;
    let value: serde_json::Value =
        serde_json::from_slice(&decoded).context("session token payload is not JSON")?;
    let expires_at = value
        .get("expires_at")
        .and_then(serde_json::Value::as_i64)
        .ok_or_else(|| anyhow!("session token has no expiry"))?;
    if expires_at <= now_unix {
        bail!("session token is expired");
    }
    if value
        .get("target_host")
        .and_then(serde_json::Value::as_str)
        .map(str::trim)
        .filter(|host| !host.is_empty() && host.len() <= 253)
        .is_none()
        || value
            .get("target_port")
            .and_then(serde_json::Value::as_u64)
            .filter(|port| (1..=u64::from(u16::MAX)).contains(port))
            .is_none()
        || value
            .get("target_protocol")
            .and_then(serde_json::Value::as_str)
            .filter(|protocol| protocol.eq_ignore_ascii_case("tcp"))
            .is_none()
    {
        bail!("session token target claim is invalid");
    }
    if value
        .get("forward_id")
        .and_then(serde_json::Value::as_u64)
        .filter(|claim| *claim == forward_id)
        .is_none()
    {
        bail!("session token forward claim does not match");
    }
    Ok(())
}

fn validate_fence(lease_id: &str, generation: u64) -> Result<()> {
    let uuid = Uuid::parse_str(lease_id).context("lease ID is not a UUID")?;
    if uuid.get_version_num() != 4 || generation == 0 {
        bail!("lease fence must contain a UUIDv4 and positive generation");
    }
    Ok(())
}

fn validate_sha256(label: &str, value: &str) -> Result<()> {
    if value.len() != 64 || !value.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        bail!("{label} SHA-256 is invalid");
    }
    Ok(())
}

fn bounded_usize(label: &str, value: usize, min: usize, max: usize) -> Result<()> {
    if !(min..=max).contains(&value) {
        bail!("{label} is out of range");
    }
    Ok(())
}

fn bounded_u64(label: &str, value: u64, min: u64, max: u64) -> Result<()> {
    if !(min..=max).contains(&value) {
        bail!("{label} is out of range");
    }
    Ok(())
}

fn bounded_u32(label: &str, value: u32, min: u32, max: u32) -> Result<()> {
    if !(min..=max).contains(&value) {
        bail!("{label} is out of range");
    }
    Ok(())
}

fn bounded_f64(label: &str, value: f64, min: f64, max: f64) -> Result<()> {
    if !value.is_finite() || value < min || value > max {
        bail!("{label} is out of range");
    }
    Ok(())
}

struct RunningForward {
    spec: ForwardSpec,
    cancel: CancellationToken,
    handle: JoinHandle<Result<()>>,
}

struct PreparedForward {
    spec: ForwardSpec,
    config: SessionConfig,
    legs: Vec<LegSpec>,
    cancel: CancellationToken,
    transport: ClientTransportRuntime,
}

#[derive(Serialize)]
struct DaemonStatus<'a> {
    generation: u64,
    status: &'a str,
    forwards: HashMap<String, ForwardStatus<'a>>,
    local_lines: Vec<LocalLineStatus>,
}

#[derive(Serialize)]
struct ForwardStatus<'a> {
    listener: &'a str,
    sessions: u64,
}

pub struct PathDaemon {
    status_path: PathBuf,
    generation: u64,
    desired: Option<DesiredState>,
    active_configs: HashMap<u64, SessionConfig>,
    forwards: HashMap<u64, RunningForward>,
    line_paths: LineRuntimePaths,
    lines: HashMap<u32, LineRuntime>,
    line_restart_failures: HashMap<String, (u32, Instant)>,
    line_fence_watermarks: HashMap<u32, (String, u64)>,
}

impl PathDaemon {
    pub fn new(status_path: impl Into<PathBuf>) -> Self {
        let status_path = status_path.into();
        let runtime_root = status_path
            .parent()
            .unwrap_or_else(|| Path::new("/run/leikwan-pathd"))
            .to_path_buf();
        Self::new_with_runtime_root(status_path, runtime_root)
    }

    pub fn new_with_runtime_root(
        status_path: impl Into<PathBuf>,
        runtime_root: impl Into<PathBuf>,
    ) -> Self {
        Self {
            status_path: status_path.into(),
            generation: 0,
            desired: None,
            active_configs: HashMap::new(),
            forwards: HashMap::new(),
            line_paths: LineRuntimePaths::new(runtime_root),
            lines: HashMap::new(),
            line_restart_failures: HashMap::new(),
            line_fence_watermarks: HashMap::new(),
        }
    }

    pub fn generation(&self) -> u64 {
        self.generation
    }

    pub async fn enforce_expiry(&mut self) -> Result<bool> {
        let Some(active_desired) = self.desired.as_ref() else {
            return Ok(false);
        };
        let now = unix_now_i64()?;
        let expired_lines: HashSet<u32> = active_desired
            .local_leases
            .iter()
            .filter(|lease| lease.expires_at_unix <= now)
            .map(|lease| lease.line_id)
            .collect();
        if expired_lines.is_empty() {
            return Ok(false);
        }

        let mut degraded = active_desired.clone();
        degraded
            .local_leases
            .retain(|lease| !expired_lines.contains(&lease.line_id));
        for forward in &mut degraded.forwards {
            forward.legs.retain(|leg| {
                !(leg.execution_group.starts_with("client:")
                    && expired_lines.contains(&leg.line_id))
            });
        }
        degraded.forwards.retain(|forward| !forward.legs.is_empty());
        let wanted_forwards = degraded
            .forwards
            .iter()
            .map(|forward| forward.forward_id)
            .collect::<HashSet<_>>();
        let mut configs = self.active_configs.clone();
        configs.retain(|forward_id, _| wanted_forwards.contains(forward_id));

        if let Err(error) = self.reconcile_runtime(&degraded, &configs).await {
            self.fail_closed().await;
            let _ = self.write_status_for(0, "degraded").await;
            return Err(error).context("failed to remove expired local lanes from active forwards");
        }
        self.prune_lines(&degraded).await;
        self.write_status_for(self.generation, "degraded").await?;
        self.desired = Some(degraded);
        self.active_configs = configs;
        Ok(true)
    }

    pub async fn apply(&mut self, desired: DesiredState) -> Result<()> {
        let now = unix_now_i64()?;
        let validated = desired.validate_at(now)?;
        let desired_fences = desired_line_fences(&validated.desired)?;
        self.reject_stale_line_fences(&desired_fences)?;
        if validated.desired.generation < self.generation {
            bail!("desired generation is older than the active generation");
        }
        if validated.desired.generation == self.generation {
            if self.desired.as_ref() != Some(&validated.desired) {
                bail!("active generation cannot be replaced with different content");
            }
        }

        let previous_desired = self.desired.clone();
        let previous_configs = self.active_configs.clone();
        let changed_lines = self.changed_line_ids(&validated.desired);
        self.drain_forwards_referencing_lines(&changed_lines).await;
        if let Err(line_error) = self.reconcile_lines_start(&validated.desired).await {
            self.restore_or_fail_closed(previous_desired.as_ref(), &previous_configs)
                .await
                .with_context(|| {
                    format!("line reconcile failed and previous generation could not be restored: {line_error:#}")
                })?;
            return Err(line_error);
        }
        if let Err(readiness_error) = self.verify_required_local_lines(&validated.desired).await {
            self.restore_or_fail_closed(previous_desired.as_ref(), &previous_configs)
                .await
                .with_context(|| {
                    format!(
                        "local line readiness failed and previous generation could not be restored: {readiness_error:#}"
                    )
                })?;
            return Err(readiness_error);
        }
        if let Err(reconcile_error) = self
            .reconcile_runtime(&validated.desired, &validated.session_configs)
            .await
        {
            self.restore_or_fail_closed(previous_desired.as_ref(), &previous_configs)
                .await
                .with_context(|| {
                    format!("runtime reconcile failed and previous generation could not be restored: {reconcile_error:#}")
                })?;
            return Err(reconcile_error);
        }
        self.prune_lines(&validated.desired).await;

        if let Err(status_error) = self
            .write_status_for(validated.desired.generation, "ready")
            .await
        {
            self.restore_or_fail_closed(previous_desired.as_ref(), &previous_configs)
                .await
                .with_context(|| {
                    format!("status commit failed and previous generation could not be restored: {status_error:#}")
                })?;
            return Err(status_error);
        }

        self.generation = validated.desired.generation;
        self.line_fence_watermarks.extend(desired_fences);
        self.desired = Some(validated.desired);
        self.active_configs = validated.session_configs;
        Ok(())
    }

    fn reject_stale_line_fences(&self, desired_fences: &HashMap<u32, (String, u64)>) -> Result<()> {
        for (line_id, (lease_id, generation)) in desired_fences {
            if let Some((observed_lease_id, observed_generation)) =
                self.line_fence_watermarks.get(line_id)
            {
                if *generation < *observed_generation
                    || (*generation == *observed_generation
                        && !lease_id.eq_ignore_ascii_case(observed_lease_id))
                {
                    bail!("physical line lease fence is older than the observed watermark");
                }
            }
        }
        Ok(())
    }

    fn changed_line_ids(&mut self, desired: &DesiredState) -> HashSet<u32> {
        desired
            .local_leases
            .iter()
            .filter_map(|lease| {
                let unchanged = self
                    .lines
                    .get_mut(&lease.line_id)
                    .map(|runtime| runtime.matches(lease, &desired.provider))
                    .unwrap_or(false);
                (!unchanged).then_some(lease.line_id)
            })
            .collect()
    }

    async fn drain_forwards_referencing_lines(&mut self, line_ids: &HashSet<u32>) {
        if line_ids.is_empty() {
            return;
        }
        let affected = self
            .forwards
            .iter()
            .filter(|(_, running)| {
                running.spec.legs.iter().any(|leg| {
                    leg.execution_group.starts_with("client:") && line_ids.contains(&leg.line_id)
                })
            })
            .map(|(forward_id, _)| *forward_id)
            .collect::<Vec<_>>();
        let affected = affected
            .into_iter()
            .filter_map(|forward_id| self.forwards.remove(&forward_id))
            .collect();
        stop_forwards(affected).await;
    }

    async fn reconcile_runtime(
        &mut self,
        desired: &DesiredState,
        configs: &HashMap<u64, SessionConfig>,
    ) -> Result<()> {
        let mut unchanged = HashSet::new();
        let auth_refs_unchanged = self
            .desired
            .as_ref()
            .map(|active| active.auth_refs == desired.auth_refs)
            .unwrap_or(false);
        for spec in &desired.forwards {
            if self
                .forwards
                .get(&spec.forward_id)
                .map(|running| {
                    auth_refs_unchanged && &running.spec == spec && !running.handle.is_finished()
                })
                .unwrap_or(false)
            {
                unchanged.insert(spec.forward_id);
            }
        }

        let mut prepared = VecDeque::new();
        for spec in &desired.forwards {
            if unchanged.contains(&spec.forward_id) {
                continue;
            }
            let config = configs
                .get(&spec.forward_id)
                .cloned()
                .ok_or_else(|| anyhow!("validated forward policy disappeared"))?;
            match prepare_forward(spec.clone(), config, &desired.auth_refs).await {
                Ok(forward) => prepared.push_back(forward),
                Err(err) => {
                    stop_prepared_forwards(prepared).await;
                    return Err(err);
                }
            }
        }

        let new_listeners: Vec<SocketAddr> = prepared.iter().map(|item| item.spec.listen).collect();
        let blocking_old: Vec<u64> = self
            .forwards
            .iter()
            .filter(|(id, running)| {
                !unchanged.contains(id)
                    && new_listeners
                        .iter()
                        .copied()
                        .any(|listen| listeners_conflict(listen, running.spec.listen))
            })
            .map(|(id, _)| *id)
            .collect();
        let blocking_old = blocking_old
            .into_iter()
            .filter_map(|id| self.forwards.remove(&id))
            .collect();
        stop_forwards(blocking_old).await;

        while let Some(forward) = prepared.pop_front() {
            let forward_id = forward.spec.forward_id;
            match activate_prepared_forward(forward).await {
                Ok(running) => {
                    if let Some(previous) = self.forwards.insert(forward_id, running) {
                        stop_forwards(vec![previous]).await;
                    }
                }
                Err(err) => {
                    stop_prepared_forwards(prepared).await;
                    return Err(err);
                }
            }
        }

        let wanted: HashSet<u64> = desired
            .forwards
            .iter()
            .map(|spec| spec.forward_id)
            .collect();
        let obsolete: Vec<u64> = self
            .forwards
            .keys()
            .filter(|id| !wanted.contains(id))
            .copied()
            .collect();
        let obsolete = obsolete
            .into_iter()
            .filter_map(|id| self.forwards.remove(&id))
            .collect();
        stop_forwards(obsolete).await;
        Ok(())
    }

    async fn restore_or_fail_closed(
        &mut self,
        previous: Option<&DesiredState>,
        configs: &HashMap<u64, SessionConfig>,
    ) -> Result<()> {
        let empty = DesiredState {
            generation: 1,
            provider: ProviderSpec {
                binary: PathBuf::from("/disabled"),
                session_json: SecretString(String::from("disabled")),
                session_sha256: String::new(),
                local_socks_port_min: DEFAULT_LOCAL_SOCKS_PORT_MIN,
                local_socks_port_max: DEFAULT_LOCAL_SOCKS_PORT_MAX,
            },
            auth_refs: HashMap::new(),
            local_leases: Vec::new(),
            forwards: Vec::new(),
        };
        let target = previous.unwrap_or(&empty);
        if let Err(err) = self.reconcile_lines_start(target).await {
            self.fail_closed().await;
            return Err(err);
        }
        if let Err(err) = self.reconcile_runtime(target, configs).await {
            self.fail_closed().await;
            return Err(err);
        }
        self.prune_lines(target).await;
        Ok(())
    }

    async fn fail_closed(&mut self) {
        let running = std::mem::take(&mut self.forwards).into_values().collect();
        stop_forwards(running).await;
        let lines = std::mem::take(&mut self.lines).into_values().collect();
        stop_lines(lines).await;
        let _ = self.line_paths.cleanup_secrets();
        self.generation = 0;
        self.desired = None;
        self.active_configs.clear();
    }

    async fn verify_required_local_lines(&mut self, desired: &DesiredState) -> Result<()> {
        let required = desired
            .forwards
            .iter()
            .flat_map(|forward| forward.legs.iter())
            .filter(|leg| leg.execution_group.starts_with("client:"))
            .map(|leg| leg.line_id)
            .collect::<HashSet<_>>();
        if required.is_empty() {
            return Ok(());
        }

        let deadline = tokio::time::Instant::now() + LISTENER_READY_TIMEOUT;
        let mut probes = tokio::task::JoinSet::new();
        for line_id in &required {
            let runtime = self
                .lines
                .get_mut(line_id)
                .ok_or_else(|| anyhow!("required local line runtime is missing"))?;
            if !runtime.is_alive() {
                bail!("required local line provider process exited before readiness");
            }
            let line_id = *line_id;
            let socks_addr = runtime.socks_addr();
            probes.spawn(async move {
                wait_for_tcp_ready(socks_addr, deadline)
                    .await
                    .map(|()| line_id)
            });
        }
        while let Some(joined) = probes.join_next().await {
            joined.context("local line readiness probe task failed")??;
        }
        for line_id in required {
            if !self
                .lines
                .get_mut(&line_id)
                .is_some_and(LineRuntime::is_alive)
            {
                bail!("required local line provider process exited during readiness");
            }
        }
        Ok(())
    }

    async fn reconcile_lines_start(&mut self, desired: &DesiredState) -> Result<()> {
        for lease in &desired.local_leases {
            validate_line_material(lease, &desired.provider, &SystemLineClock)?;
        }
        for lease in &desired.local_leases {
            let unchanged = self
                .lines
                .get_mut(&lease.line_id)
                .map(|runtime| runtime.matches(lease, &desired.provider))
                .unwrap_or(false);
            if unchanged {
                continue;
            }
            let failure_key = format!(
                "{}:{}:{}:{}:{}",
                lease.line_id,
                lease.lease_id.to_ascii_lowercase(),
                lease.lease_generation,
                lease.config_sha256.to_ascii_lowercase(),
                desired.provider.session_sha256.to_ascii_lowercase(),
            );
            if let Some((_, retry_at)) = self.line_restart_failures.get(&failure_key) {
                if *retry_at > Instant::now() {
                    bail!(
                        "provider runtime restart backoff is active for line {}",
                        lease.line_id
                    );
                }
            }
            if let Some(previous) = self.lines.remove(&lease.line_id) {
                stop_lines(vec![previous]).await;
            }
            let runtime = match LineRuntime::start(
                lease,
                &desired.provider,
                &self.line_paths,
                &SystemLineClock,
            )
            .await
            {
                Ok(runtime) => runtime,
                Err(err) => {
                    if self.line_restart_failures.len() >= 256 {
                        self.line_restart_failures.clear();
                    }
                    let attempt = self
                        .line_restart_failures
                        .get(&failure_key)
                        .map(|(attempt, _)| attempt.saturating_add(1))
                        .unwrap_or(0);
                    self.line_restart_failures.insert(
                        failure_key,
                        (attempt, Instant::now() + restart_backoff(attempt)),
                    );
                    return Err(err);
                }
            };
            self.line_restart_failures.remove(&failure_key);
            self.lines.insert(lease.line_id, runtime);
        }
        Ok(())
    }

    async fn prune_lines(&mut self, desired: &DesiredState) {
        let wanted: HashSet<u32> = desired
            .local_leases
            .iter()
            .map(|lease| lease.line_id)
            .collect();
        let obsolete: Vec<u32> = self
            .lines
            .keys()
            .filter(|line_id| !wanted.contains(line_id))
            .copied()
            .collect();
        let obsolete = obsolete
            .into_iter()
            .filter_map(|line_id| self.lines.remove(&line_id))
            .collect();
        stop_lines(obsolete).await;
        if self.lines.is_empty() {
            let _ = self.line_paths.cleanup_secrets();
        }
    }

    pub async fn shutdown(&mut self) -> Result<()> {
        let running = std::mem::take(&mut self.forwards).into_values().collect();
        stop_forwards(running).await;
        let lines = std::mem::take(&mut self.lines).into_values().collect();
        stop_lines(lines).await;
        let _ = self.line_paths.cleanup_secrets();
        self.write_status_for(self.generation, "stopped").await
    }

    async fn write_status_for(&mut self, generation: u64, status: &str) -> Result<()> {
        let mut probes = tokio::task::JoinSet::new();
        for line in self.lines.values_mut() {
            let line_id = line.line_id();
            let status = line.status();
            let socks_addr = line.socks_addr();
            probes.spawn(async move {
                let mut status = status;
                if status.service_state == "active" {
                    let started = Instant::now();
                    match timeout(
                        Duration::from_millis(500),
                        tokio::net::TcpStream::connect(socks_addr),
                    )
                    .await
                    {
                        Ok(Ok(stream)) => {
                            drop(stream);
                            status.tcp_status = String::from("ok");
                            status.tcp_connect_ms = Some(
                                started.elapsed().as_millis().min(u128::from(u64::MAX)) as u64,
                            );
                        }
                        _ => {
                            status.tcp_status = String::from("unreachable");
                            status.last_error_code = Some(String::from("tcp_connect_failed"));
                        }
                    }
                }
                (line_id, status)
            });
        }
        let mut local_lines = Vec::with_capacity(self.lines.len());
        while let Some(joined) = probes.join_next().await {
            local_lines.push(joined.context("local line status probe task failed")?.1);
        }
        local_lines.sort_by_key(|line| line.line_id);
        let status = if status == "ready"
            && local_lines
                .iter()
                .any(|line| line.service_state != "active" || line.tcp_status != "ok")
        {
            "degraded"
        } else {
            status
        };
        let forwards = self
            .forwards
            .iter()
            .map(|(id, running)| {
                (
                    id.to_string(),
                    ForwardStatus {
                        listener: if running.handle.is_finished() {
                            "failed"
                        } else {
                            "ready"
                        },
                        sessions: 0,
                    },
                )
            })
            .collect();
        let encoded = serde_json::to_vec(&DaemonStatus {
            generation,
            status,
            forwards,
            local_lines,
        })?;
        atomic_write(&self.status_path, &encoded).await
    }
}

async fn prepare_forward(
    spec: ForwardSpec,
    config: SessionConfig,
    auth_refs: &HashMap<String, SecretString>,
) -> Result<PreparedForward> {
    let legs = spec
        .legs
        .iter()
        .map(|leg| leg.runtime_spec(spec.egress, auth_refs))
        .collect::<Result<Vec<_>>>()?;
    let cancel = CancellationToken::new();
    let transport = timeout(
        LISTENER_READY_TIMEOUT,
        prepare_client_tcp_transport(
            legs.clone(),
            spec.session_token.clone(),
            config.clone(),
            cancel.clone(),
        ),
    )
    .await
    .context("forward transport readiness timed out")??;
    Ok(PreparedForward {
        spec,
        config,
        legs,
        cancel,
        transport,
    })
}

async fn activate_prepared_forward(forward: PreparedForward) -> Result<RunningForward> {
    let PreparedForward {
        spec,
        config,
        legs,
        cancel,
        transport,
    } = forward;
    let child_cancel = cancel.clone();
    let (ready_tx, ready_rx) = oneshot::channel();
    let bind = spec.listen.to_string();
    let token = spec.session_token.clone();
    let handle = tokio::spawn(async move {
        run_prepared_client_tcp_listener(
            bind,
            legs,
            token,
            config,
            child_cancel,
            Some(ready_tx),
            transport,
        )
        .await
    });
    match timeout(LISTENER_READY_TIMEOUT, ready_rx).await {
        Ok(Ok(Ok(()))) => Ok(RunningForward {
            spec,
            cancel,
            handle,
        }),
        Ok(Ok(Err(message))) => {
            cancel.cancel();
            let _ = handle.await;
            bail!("forward listener failed to start: {message}");
        }
        Ok(Err(_)) => {
            cancel.cancel();
            let result = handle.await;
            bail!("forward listener exited before readiness: {result:?}");
        }
        Err(_) => {
            cancel.cancel();
            let _ = handle.await;
            bail!("forward listener readiness timed out");
        }
    }
}

async fn stop_prepared_forwards(mut prepared: VecDeque<PreparedForward>) {
    for forward in &prepared {
        forward.cancel.cancel();
    }
    while let Some(forward) = prepared.pop_front() {
        stop_client_tcp_transport(forward.transport).await;
    }
}

async fn stop_forwards(running: Vec<RunningForward>) {
    for forward in &running {
        forward.cancel.cancel();
    }
    let deadline = tokio::time::Instant::now() + LISTENER_DRAIN_TIMEOUT;
    for forward in running {
        let mut handle = forward.handle;
        if tokio::time::timeout_at(deadline, &mut handle)
            .await
            .is_err()
        {
            handle.abort();
            let _ = handle.await;
        }
    }
}

async fn stop_lines(lines: Vec<LineRuntime>) {
    let mut tasks = tokio::task::JoinSet::new();
    for line in lines {
        tasks.spawn(line.stop(Duration::from_secs(2)));
    }
    if timeout(LISTENER_DRAIN_TIMEOUT, async {
        while tasks.join_next().await.is_some() {}
    })
    .await
    .is_err()
    {
        tasks.abort_all();
        while tasks.join_next().await.is_some() {}
    }
}

async fn wait_for_tcp_ready(address: SocketAddr, deadline: tokio::time::Instant) -> Result<()> {
    loop {
        let now = tokio::time::Instant::now();
        if now >= deadline {
            bail!("local line SOCKS endpoint did not become reachable");
        }
        let attempt_timeout = (deadline - now).min(Duration::from_millis(100));
        if matches!(
            timeout(attempt_timeout, tokio::net::TcpStream::connect(address)).await,
            Ok(Ok(_))
        ) {
            return Ok(());
        }
        tokio::time::sleep(Duration::from_millis(25)).await;
    }
}

async fn atomic_write(path: &Path, contents: &[u8]) -> Result<()> {
    write_private_atomic(path, contents)
}

fn unix_now_i64() -> Result<i64> {
    let seconds = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .context("system clock is before the Unix epoch")?
        .as_secs();
    i64::try_from(seconds).context("Unix timestamp does not fit in i64")
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::frame::{read_frame, write_frame, WireFrame};
    use crate::pathd_line::{process_is_alive, sha256_bytes};
    use std::fs;
    use std::net::TcpListener as StdTcpListener;
    use std::os::unix::fs::PermissionsExt;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::sync::Arc;

    struct FakeProvider {
        root: PathBuf,
        binary: PathBuf,
    }

    impl FakeProvider {
        fn start() -> Self {
            Self::start_with_body("#!/bin/sh\ntrap 'exit 0' TERM INT\nwhile :; do sleep 1; done\n")
        }

        fn start_with_body(body: &str) -> Self {
            let root = temp_status_path().with_extension("provider");
            fs::create_dir_all(&root).unwrap();
            let binary = root.join("provider-runtime");
            fs::write(&binary, body).unwrap();
            fs::set_permissions(&binary, fs::Permissions::from_mode(0o700)).unwrap();
            Self { root, binary }
        }

        fn attach(&self, value: &mut serde_json::Value) {
            let session = "session-secret";
            let config = "provider-secret";
            value["provider"]["binary"] = self.binary.display().to_string().into();
            value["provider"]["session_json"] = session.into();
            value["provider"]["session_sha256"] = sha256_bytes(session.as_bytes()).into();
            value["local_leases"][0]["config_toml"] = config.into();
            value["local_leases"][0]["config_sha256"] = sha256_bytes(config.as_bytes()).into();
        }

        fn stop(self) {
            let _ = fs::remove_dir_all(self.root);
        }
    }

    struct FakeLegServer {
        addr: SocketAddr,
        cancel: CancellationToken,
        active: Arc<AtomicUsize>,
        handle: JoinHandle<()>,
        provider: FakeProvider,
    }

    impl FakeLegServer {
        async fn start() -> Self {
            Self::start_with_ack_delay(Duration::ZERO).await
        }

        async fn start_with_ack_delay(ack_delay: Duration) -> Self {
            let listener = bind_local_socks_listener().await;
            let provider = FakeProvider::start();
            let addr = listener.local_addr().unwrap();
            let cancel = CancellationToken::new();
            let child_cancel = cancel.clone();
            let active = Arc::new(AtomicUsize::new(0));
            let child_active = active.clone();
            let handle = tokio::spawn(async move {
                let mut connections = tokio::task::JoinSet::new();
                loop {
                    tokio::select! {
                        _ = child_cancel.cancelled() => break,
                        accepted = listener.accept() => {
                            let Ok((mut stream, _)) = accepted else { break };
                            let connection_cancel = child_cancel.clone();
                            let connection_active = child_active.clone();
                            connections.spawn(async move {
                                if matches!(read_frame(&mut stream).await, Ok(WireFrame::Hello(_))) {
                                    connection_active.fetch_add(1, Ordering::SeqCst);
                                    tokio::time::sleep(ack_delay).await;
                                    let _ = write_frame(&mut stream, &WireFrame::HelloAck).await;
                                    tokio::select! {
                                        _ = connection_cancel.cancelled() => {}
                                        _ = async {
                                            loop {
                                                if read_frame(&mut stream).await.is_err() {
                                                    break;
                                                }
                                            }
                                        } => {}
                                    }
                                    connection_active.fetch_sub(1, Ordering::SeqCst);
                                }
                            });
                        }
                    }
                }
                connections.abort_all();
                while connections.join_next().await.is_some() {}
            });
            Self {
                addr,
                cancel,
                active,
                handle,
                provider,
            }
        }

        fn attach(&self, value: &mut serde_json::Value) {
            self.provider.attach(value);
            value["local_leases"][0]["socks_addr"] = self.addr.to_string().into();
            for leg in value["forwards"][0]["legs"].as_array_mut().unwrap() {
                leg["uri"] = format!("tcp://{}", self.addr).into();
            }
        }

        async fn stop(self) {
            self.cancel.cancel();
            let _ = self.handle.await;
            self.provider.stop();
        }
    }

    fn free_loopback_addr() -> SocketAddr {
        let listener = StdTcpListener::bind("127.0.0.1:0").unwrap();
        listener.local_addr().unwrap()
    }

    async fn bind_local_socks_listener() -> tokio::net::TcpListener {
        for port in DEFAULT_LOCAL_SOCKS_PORT_MIN..=DEFAULT_LOCAL_SOCKS_PORT_MAX {
            if let Ok(listener) = tokio::net::TcpListener::bind(("127.0.0.1", port)).await {
                return listener;
            }
        }
        panic!("no free local SOCKS test port");
    }

    fn temp_status_path() -> PathBuf {
        std::env::temp_dir().join(format!(
            "leikwan-pathd-status-{}-{}.json",
            std::process::id(),
            SystemTime::now()
                .duration_since(UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ))
    }

    fn test_daemon(status_path: &Path) -> PathDaemon {
        PathDaemon::new_with_runtime_root(status_path, temp_status_path().with_extension("runtime"))
    }

    fn valid_json() -> serde_json::Value {
        serde_json::json!({
            "generation": 42,
            "provider": {
                "binary": "/usr/local/libexec/leikwan/provider-runtime",
                "session_json": "session-secret",
                "session_sha256": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
            },
            "local_leases": [{
                "line_id": 451,
                "lease_id": "00000000-0000-4000-8000-000000000020",
                "lease_generation": 7,
                "max_lanes": 2,
                "config_sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
                "config_toml": "provider-secret",
                "expires_at_unix": 2000000000,
                "socks_addr": "127.0.0.1:20451"
            }],
            "forwards": [{
                "forward_id": 17,
                "listen": "127.0.0.1:35017",
                "egress": "198.51.100.50:7443",
                "session_token": "eyJ0YXJnZXRfaG9zdCI6InY0LWhrdC1iLm1jYmFyLmNjIiwidGFyZ2V0X3BvcnQiOjUyMDEsInRhcmdldF9wcm90b2NvbCI6InRjcCIsImZvcndhcmRfaWQiOjE3LCJleHBpcmVzX2F0IjoyMDAwMDAwMDAwfQ.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
                "legs": [
                    {"uri":"tcp://127.0.0.1:20451","execution_group":"client:20:dev","line_id":451,"lease_id":"00000000-0000-4000-8000-000000000020","lease_generation":7,"leg_route_id":451},
                    {"uri":"tcp://192.0.2.1:8001","execution_group":"node:101","line_id":452,"lease_id":"00000000-0000-4000-8000-000000000101","lease_generation":3,"leg_route_id":452}
                ],
                "policy": {
                    "chunk_size": 131072,
                    "max_window_bytes": 16777216,
                    "retransmit_ms": 300,
                    "join_timeout_ms": 400,
                    "connect_timeout_ms": 3000,
                    "preferred_initial_legs": 2,
                    "minimum_initial_legs": 1,
                    "initial_leg_wait_ms": 600,
                    "leg_reconnect_backoff_ms": 1800000,
                    "leg_reconnect_backoff_max_ms": 1800000,
                    "interactive_chunk_bytes": 16384,
                    "latency_guard_bytes": 262144,
                    "interactive_rtt_slack_ms": 30,
                    "warmup_trigger_bytes": 65536,
                    "warmup_slice_bytes": 65536,
                    "warmup_leg_delay_ms": 80,
                    "bulk_promote_bytes": 262144,
                    "leg_cooldown_ms": 1800000,
                    "throughput_min_sample_bytes": 1048576,
                    "throughput_min_sample_ms": 1000,
                    "adaptive_line_admission": true,
                    "throughput_min_mbps": 10.0,
                    "throughput_relative_floor": 0.35,
                    "throughput_bad_windows": 3,
                    "throughput_recovery_windows": 2,
                    "throughput_quarantine_ms": 60000,
                    "throughput_probe_interval_ms": 30000,
                    "throughput_probe_bytes": 1048576,
                    "latency_first": true,
                    "udp_primary_only": false,
                    "default_strategy": "latency_best"
                }
            }]
        })
    }

    #[test]
    fn secrets_are_always_redacted() {
        let secret: SecretString = serde_json::from_str("\"top-secret\"").unwrap();
        assert_eq!("[redacted]", format!("{secret}"));
        assert_eq!("[redacted]", format!("{secret:?}"));
    }

    #[test]
    fn desired_state_debug_redacts_ingress_auth_and_session_tokens() {
        let mut value = valid_json();
        value["auth_refs"] = serde_json::json!({"slave-316": "opaque-ingress-secret"});
        let session_token = value["forwards"][0]["session_token"]
            .as_str()
            .unwrap()
            .to_string();
        let desired: DesiredState = serde_json::from_value(value).unwrap();

        let debug = format!("{desired:?}");
        assert!(!debug.contains("opaque-ingress-secret"));
        assert!(!debug.contains(&session_token));
        assert!(debug.contains("[redacted]"));
    }

    #[test]
    fn valid_desired_state_passes_checked_conversion() {
        let desired: DesiredState = serde_json::from_value(valid_json()).unwrap();
        let validated = desired.validate_at(1_900_000_000).unwrap();
        assert_eq!(42, validated.desired.generation);
        assert_eq!(2, validated.session_configs[&17].preferred_initial_legs);
    }

    #[test]
    fn internal_legs_resolve_only_from_the_desired_auth_map_and_lease_metadata() {
        let mut value = valid_json();
        value["auth_refs"] = serde_json::json!({"slave-316": "opaque-ingress-secret"});
        value["forwards"][0]["legs"][0]["uri"] =
            "socks5://127.0.0.1:20451?leg_route_id=451&line_id=451&lease_generation=7".into();
        value["forwards"][0]["legs"][1]["uri"] =
            "ingress+tcp://slave.example:7003?leg_route_id=452&endpoint_id=316&route_key=fixed&auth_ref=slave-316".into();
        let desired: DesiredState = serde_json::from_value(value.clone()).unwrap();
        desired.validate_at(1_900_000_000).unwrap();

        value["auth_refs"] = serde_json::json!({});
        assert!(serde_json::from_value::<DesiredState>(value.clone())
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());

        value["auth_refs"] = serde_json::json!({"slave-316": "opaque-ingress-secret"});
        value["forwards"][0]["legs"][0]["uri"] =
            "socks5://127.0.0.1:20451?leg_route_id=451&line_id=999&lease_generation=7".into();
        assert!(serde_json::from_value::<DesiredState>(value)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());
    }

    #[test]
    fn rejects_invalid_generation_listener_routes_and_policy() {
        let mut generation = valid_json();
        generation["generation"] = 0.into();
        assert!(serde_json::from_value::<DesiredState>(generation)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());

        let mut duplicate_listener = valid_json();
        let duplicate = duplicate_listener["forwards"][0].clone();
        duplicate_listener["forwards"]
            .as_array_mut()
            .unwrap()
            .push(duplicate);
        assert!(serde_json::from_value::<DesiredState>(duplicate_listener)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());

        let mut duplicate_route = valid_json();
        duplicate_route["forwards"][0]["legs"][1]["leg_route_id"] = 451.into();
        assert!(serde_json::from_value::<DesiredState>(duplicate_route)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());

        let mut policy = valid_json();
        policy["forwards"][0]["policy"]["throughput_min_mbps"] = 0.into();
        assert!(serde_json::from_value::<DesiredState>(policy)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());
    }

    #[test]
    fn rejects_public_socks_single_group_and_expired_token() {
        let mut public_socks = valid_json();
        public_socks["local_leases"][0]["socks_addr"] = "0.0.0.0:20451".into();
        assert!(serde_json::from_value::<DesiredState>(public_socks)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());

        let mut single_group = valid_json();
        single_group["forwards"][0]["legs"]
            .as_array_mut()
            .unwrap()
            .truncate(1);
        assert!(serde_json::from_value::<DesiredState>(single_group)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());

        let mut expired = valid_json();
        expired["forwards"][0]["session_token"] =
            "eyJleHBpcmVzX2F0IjoxfQ.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA".into();
        assert!(serde_json::from_value::<DesiredState>(expired)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());

        let mut malformed_signature = valid_json();
        malformed_signature["forwards"][0]["session_token"] =
            "eyJleHBpcmVzX2F0IjoyMDAwMDAwMDAwfQ.c2hvcnQ".into();
        assert!(serde_json::from_value::<DesiredState>(malformed_signature)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());
    }

    #[test]
    fn rejects_conflicting_line_authority_and_local_lane_overcommit() {
        let mut conflicting = valid_json();
        conflicting["forwards"][0]["legs"][1]["line_id"] = 451.into();
        assert!(serde_json::from_value::<DesiredState>(conflicting)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());

        let mut overcommitted = valid_json();
        overcommitted["local_leases"][0]["max_lanes"] = 1.into();
        let mut extra = overcommitted["forwards"][0]["legs"][0].clone();
        extra["leg_route_id"] = 1_004_512.into();
        overcommitted["forwards"][0]["legs"]
            .as_array_mut()
            .unwrap()
            .push(extra);
        assert!(serde_json::from_value::<DesiredState>(overcommitted)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());
    }

    #[test]
    fn rejects_unbounded_local_leases_and_wildcard_listener_overlap() {
        let mut oversized = valid_json();
        let leases = oversized["local_leases"].as_array_mut().unwrap();
        leases.clear();
        for index in 1..=65u32 {
            leases.push(serde_json::json!({
                "line_id": 1000 + index,
                "lease_id": format!("00000000-0000-4000-8000-{index:012x}"),
                "lease_generation": 1,
                "max_lanes": 1,
                "config_sha256": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
                "config_toml": "provider-secret",
                "expires_at_unix": 2000000000,
                "socks_addr": format!("127.0.0.1:{}", 21000 + index)
            }));
        }
        assert!(serde_json::from_value::<DesiredState>(oversized)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());
        assert!(listeners_conflict(
            "0.0.0.0:35017".parse().unwrap(),
            "127.0.0.1:35017".parse().unwrap()
        ));
        assert!(listeners_conflict(
            "[::]:35017".parse().unwrap(),
            "[::1]:35017".parse().unwrap()
        ));
    }

    #[test]
    fn configured_local_socks_range_and_socket_collisions_are_rejected() {
        let mut outside_range = valid_json();
        outside_range["provider"]["local_socks_port_min"] = 24_000.into();
        outside_range["provider"]["local_socks_port_max"] = 24_999.into();
        outside_range["local_leases"][0]["socks_addr"] = "127.0.0.1:20451".into();
        let desired: DesiredState = serde_json::from_value(outside_range).unwrap();
        assert!(desired.validate_at(1_900_000_000).is_err());

        let mut duplicate_socket = valid_json();
        let mut second = duplicate_socket["local_leases"][0].clone();
        second["line_id"] = 999.into();
        second["lease_id"] = "00000000-0000-4000-8000-000000000999".into();
        duplicate_socket["local_leases"]
            .as_array_mut()
            .unwrap()
            .push(second);
        assert!(serde_json::from_value::<DesiredState>(duplicate_socket)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());

        let mut listener_collision = valid_json();
        listener_collision["forwards"][0]["listen"] = "127.0.0.1:20451".into();
        assert!(serde_json::from_value::<DesiredState>(listener_collision)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());
    }

    #[test]
    fn unused_local_lease_still_blocks_node_authority_for_the_same_line() {
        let mut value = valid_json();
        let mut unused_local = value["local_leases"][0].clone();
        unused_local["line_id"] = 999.into();
        unused_local["lease_id"] = "00000000-0000-4000-8000-000000000999".into();
        unused_local["socks_addr"] = "127.0.0.1:20999".into();
        value["local_leases"]
            .as_array_mut()
            .unwrap()
            .push(unused_local);
        value["forwards"][0]["legs"][1]["line_id"] = 999.into();

        assert!(serde_json::from_value::<DesiredState>(value)
            .unwrap()
            .validate_at(1_900_000_000)
            .is_err());
    }

    #[tokio::test]
    async fn daemon_does_not_report_ready_without_initial_leg_quorum() {
        let provider = FakeProvider::start();
        let listen = free_loopback_addr();
        let unreachable: SocketAddr = "127.0.0.2:29998".parse().unwrap();
        let status_path = temp_status_path();
        let mut value = valid_json();
        provider.attach(&mut value);
        value["forwards"][0]["listen"] = listen.to_string().into();
        value["local_leases"][0]["socks_addr"] = unreachable.to_string().into();
        for leg in value["forwards"][0]["legs"].as_array_mut().unwrap() {
            leg["uri"] = format!("tcp://{unreachable}").into();
        }
        value["forwards"][0]["policy"]["connect_timeout_ms"] = 100.into();
        let mut daemon = test_daemon(&status_path);

        assert!(daemon
            .apply(serde_json::from_value(value).unwrap())
            .await
            .is_err());
        assert_eq!(0, daemon.generation());
        assert!(!status_path.exists());
        provider.stop();
    }

    #[tokio::test]
    async fn referenced_local_line_must_be_reachable_before_forward_ready() {
        let server = FakeLegServer::start().await;
        let listen = free_loopback_addr();
        let unreachable_socks: SocketAddr = "127.0.0.2:29999".parse().unwrap();
        let status_path = temp_status_path();
        let mut value = valid_json();
        server.attach(&mut value);
        value["forwards"][0]["listen"] = listen.to_string().into();
        value["local_leases"][0]["socks_addr"] = unreachable_socks.to_string().into();
        value["forwards"][0]["legs"][0]["uri"] =
            format!("socks5://{unreachable_socks}?leg_route_id=451&line_id=451&lease_generation=7")
                .into();
        value["forwards"][0]["policy"]["preferred_initial_legs"] = 1.into();
        value["forwards"][0]["policy"]["minimum_initial_legs"] = 1.into();
        let mut daemon = test_daemon(&status_path);

        assert!(daemon
            .apply(serde_json::from_value(value).unwrap())
            .await
            .is_err());
        assert_eq!(0, daemon.generation());
        assert!(tokio::net::TcpStream::connect(listen).await.is_err());

        server.stop().await;
    }

    #[tokio::test]
    async fn higher_desired_generation_cannot_roll_back_a_line_fence() {
        let server = FakeLegServer::start().await;
        let listen = free_loopback_addr();
        let status_path = temp_status_path();
        let mut initial = valid_json();
        server.attach(&mut initial);
        initial["forwards"][0]["listen"] = listen.to_string().into();
        let mut daemon = test_daemon(&status_path);
        daemon
            .apply(serde_json::from_value(initial.clone()).unwrap())
            .await
            .unwrap();

        initial["generation"] = 43.into();
        initial["local_leases"][0]["lease_generation"] = 6.into();
        initial["forwards"][0]["legs"][0]["lease_generation"] = 6.into();
        assert!(daemon
            .apply(serde_json::from_value(initial).unwrap())
            .await
            .is_err());
        assert_eq!(42, daemon.generation());
        assert_eq!(7, daemon.lines.get(&451).unwrap().lease_generation());
        tokio::net::TcpStream::connect(listen).await.unwrap();

        daemon.shutdown().await.unwrap();
        let _ = tokio::fs::remove_file(status_path).await;
        server.stop().await;
    }

    #[tokio::test]
    async fn repeated_provider_start_failure_is_backed_off() {
        let provider = FakeProvider::start_with_body("#!/bin/sh\nexit 7\n");
        let status_path = temp_status_path();
        let mut value = valid_json();
        provider.attach(&mut value);
        value["forwards"] = serde_json::json!([]);
        let desired: DesiredState = serde_json::from_value(value).unwrap();
        let mut daemon = test_daemon(&status_path);

        assert!(daemon.apply(desired.clone()).await.is_err());
        assert_eq!(1, daemon.line_restart_failures.len());
        let first = daemon.line_restart_failures.values().next().unwrap().0;
        assert!(daemon.apply(desired).await.is_err());
        assert_eq!(
            first,
            daemon.line_restart_failures.values().next().unwrap().0
        );
        assert!(daemon.lines.is_empty());

        provider.stop();
    }

    #[tokio::test]
    async fn expired_local_lease_keeps_the_slave_forward_listener_available() {
        let server = FakeLegServer::start().await;
        let listen = free_loopback_addr();
        let status_path = temp_status_path();
        let mut value = valid_json();
        server.attach(&mut value);
        value["forwards"][0]["listen"] = listen.to_string().into();
        let mut daemon = test_daemon(&status_path);
        daemon
            .apply(serde_json::from_value(value).unwrap())
            .await
            .unwrap();
        daemon.desired.as_mut().unwrap().local_leases[0].expires_at_unix =
            unix_now_i64().unwrap() - 1;

        assert!(daemon.enforce_expiry().await.unwrap());
        assert_eq!(1, daemon.forwards.len());
        let running = daemon.forwards.get(&17).unwrap();
        assert_eq!(1, running.spec.legs.len());
        assert!(running.spec.legs[0].execution_group.starts_with("node:"));
        assert!(daemon.lines.is_empty());
        tokio::net::TcpStream::connect(listen).await.unwrap();
        let status: serde_json::Value =
            serde_json::from_slice(&tokio::fs::read(&status_path).await.unwrap()).unwrap();
        assert_eq!(
            0o600,
            fs::metadata(&status_path).unwrap().permissions().mode() & 0o777
        );
        assert_eq!("degraded", status["status"]);
        assert_eq!(0, status["local_lines"].as_array().unwrap().len());

        daemon.shutdown().await.unwrap();
        let _ = tokio::fs::remove_file(status_path).await;
        server.stop().await;
    }

    #[tokio::test]
    async fn status_commit_failure_rolls_back_runtime_and_generation() {
        let server = FakeLegServer::start().await;
        let listen = free_loopback_addr();
        let blocked_parent = temp_status_path();
        tokio::fs::write(&blocked_parent, b"not-a-directory")
            .await
            .unwrap();
        let status_path = blocked_parent.join("status.json");
        let mut value = valid_json();
        server.attach(&mut value);
        value["forwards"][0]["listen"] = listen.to_string().into();
        let mut daemon = test_daemon(&status_path);

        assert!(daemon
            .apply(serde_json::from_value(value).unwrap())
            .await
            .is_err());
        assert_eq!(0, daemon.generation());
        assert!(daemon.forwards.is_empty());
        assert!(tokio::net::TcpStream::connect(listen).await.is_err());
        assert_eq!(0, server.active.load(Ordering::SeqCst));

        let _ = tokio::fs::remove_file(blocked_parent).await;
        server.stop().await;
    }

    #[tokio::test]
    async fn same_listener_update_keeps_old_listener_until_new_transport_is_ready() {
        let old_server = FakeLegServer::start().await;
        let new_server = FakeLegServer::start_with_ack_delay(Duration::from_millis(300)).await;
        let listen = free_loopback_addr();
        let status_path = temp_status_path();
        let mut initial = valid_json();
        old_server.attach(&mut initial);
        initial["forwards"][0]["listen"] = listen.to_string().into();
        let mut replacement = initial.clone();
        replacement["generation"] = 43.into();
        replacement["forwards"][0]["legs"][1]["uri"] = format!("tcp://{}", new_server.addr).into();
        let mut daemon = test_daemon(&status_path);
        daemon
            .apply(serde_json::from_value(initial).unwrap())
            .await
            .unwrap();

        let update = tokio::spawn(async move {
            let result = daemon
                .apply(serde_json::from_value(replacement).unwrap())
                .await;
            (result, daemon)
        });
        tokio::time::sleep(Duration::from_millis(75)).await;
        tokio::net::TcpStream::connect(listen).await.unwrap();

        let (result, mut daemon) = update.await.unwrap();
        result.unwrap();
        assert_eq!(43, daemon.generation());
        daemon.shutdown().await.unwrap();
        let _ = tokio::fs::remove_file(status_path).await;
        old_server.stop().await;
        new_server.stop().await;
    }

    #[tokio::test]
    async fn changed_line_drains_its_old_forward_before_stopping_the_child() {
        let old_server = FakeLegServer::start().await;
        let new_server = FakeLegServer::start().await;
        let listen = free_loopback_addr();
        let status_path = temp_status_path();
        let mut initial = valid_json();
        old_server.attach(&mut initial);
        initial["forwards"][0]["listen"] = listen.to_string().into();
        let mut daemon = test_daemon(&status_path);
        daemon
            .apply(serde_json::from_value(initial).unwrap())
            .await
            .unwrap();
        let old_pid = daemon.lines.get(&451).unwrap().pid();
        assert!(old_server.active.load(Ordering::SeqCst) > 0);

        let mut replacement = valid_json();
        new_server.attach(&mut replacement);
        replacement["generation"] = 43.into();
        replacement["forwards"][0]["listen"] = listen.to_string().into();
        let old_active = old_server.active.clone();
        let stopped_observer = tokio::spawn(async move {
            let deadline = tokio::time::Instant::now() + Duration::from_secs(2);
            loop {
                if !process_is_alive(old_pid) {
                    return old_active.load(Ordering::SeqCst);
                }
                assert!(
                    tokio::time::Instant::now() < deadline,
                    "old child did not stop"
                );
                tokio::time::sleep(Duration::from_millis(5)).await;
            }
        });

        daemon
            .apply(serde_json::from_value(replacement).unwrap())
            .await
            .unwrap();
        assert_eq!(0, stopped_observer.await.unwrap());

        daemon.shutdown().await.unwrap();
        let _ = tokio::fs::remove_file(status_path).await;
        old_server.stop().await;
        new_server.stop().await;
    }

    #[tokio::test]
    async fn failed_status_commit_restores_an_existing_generation() {
        let server = FakeLegServer::start().await;
        let listen = free_loopback_addr();
        let status_path = temp_status_path();
        let mut initial = valid_json();
        server.attach(&mut initial);
        initial["forwards"][0]["listen"] = listen.to_string().into();
        let desired: DesiredState = serde_json::from_value(initial).unwrap();
        let mut daemon = test_daemon(&status_path);
        daemon.apply(desired.clone()).await.unwrap();

        let blocked_parent = temp_status_path();
        tokio::fs::write(&blocked_parent, b"not-a-directory")
            .await
            .unwrap();
        daemon.status_path = blocked_parent.join("status.json");
        let mut replacement = desired;
        replacement.generation = 43;
        replacement.forwards[0].policy.throughput_min_mbps = 11.0;
        assert!(daemon.apply(replacement).await.is_err());
        assert_eq!(42, daemon.generation());
        assert_eq!(42, daemon.desired.as_ref().unwrap().generation);
        tokio::net::TcpStream::connect(listen).await.unwrap();

        daemon.status_path = status_path.clone();
        daemon.shutdown().await.unwrap();
        let _ = tokio::fs::remove_file(status_path).await;
        let _ = tokio::fs::remove_file(blocked_parent).await;
        server.stop().await;
    }

    #[tokio::test]
    async fn reconcile_is_ready_replaces_same_listener_and_preserves_failed_generation() {
        let server = FakeLegServer::start().await;
        let listen = free_loopback_addr();
        let status_path = temp_status_path();
        let mut initial = valid_json();
        server.attach(&mut initial);
        initial["forwards"][0]["listen"] = listen.to_string().into();
        let desired: DesiredState = serde_json::from_value(initial).unwrap();
        let mut daemon = test_daemon(&status_path);

        daemon.apply(desired.clone()).await.unwrap();
        assert_eq!(42, daemon.generation());
        let status: serde_json::Value =
            serde_json::from_slice(&tokio::fs::read(&status_path).await.unwrap()).unwrap();
        assert_eq!("ready", status["status"]);
        assert_eq!("ready", status["forwards"]["17"]["listener"]);
        assert_eq!(451, status["local_lines"][0]["line_id"]);
        assert_eq!(7, status["local_lines"][0]["lease_generation"]);
        assert_eq!("active", status["local_lines"][0]["service_state"]);
        assert_eq!("ok", status["local_lines"][0]["tcp_status"]);
        assert!(status["local_lines"][0]["tcp_connect_ms"].is_number());
        let encoded_status = serde_json::to_string(&status).unwrap();
        assert!(!encoded_status.contains("session-secret"));
        assert!(!encoded_status.contains("provider-secret"));
        tokio::net::TcpStream::connect(listen).await.unwrap();

        daemon.forwards.get(&17).unwrap().cancel.cancel();
        tokio::time::sleep(Duration::from_millis(25)).await;
        daemon.apply(desired.clone()).await.unwrap();
        assert!(!daemon.forwards.get(&17).unwrap().handle.is_finished());

        let mut replacement = desired.clone();
        replacement.generation = 43;
        replacement.forwards[0].policy.throughput_min_mbps = 11.0;
        daemon.apply(replacement).await.unwrap();
        assert_eq!(43, daemon.generation());
        tokio::net::TcpStream::connect(listen).await.unwrap();

        let mut rejected = desired;
        rejected.generation = 44;
        rejected.forwards[0].policy.throughput_min_mbps = 0.0;
        assert!(daemon.apply(rejected).await.is_err());
        assert_eq!(43, daemon.generation());
        tokio::net::TcpStream::connect(listen).await.unwrap();

        let line_pid = daemon.lines.get(&451).unwrap().pid();
        daemon.shutdown().await.unwrap();
        let status: serde_json::Value =
            serde_json::from_slice(&tokio::fs::read(&status_path).await.unwrap()).unwrap();
        assert_eq!("stopped", status["status"]);
        assert!(tokio::net::TcpStream::connect(listen).await.is_err());
        assert_eq!(0, server.active.load(Ordering::SeqCst));
        assert!(!process_is_alive(line_pid));
        let _ = tokio::fs::remove_file(status_path).await;
        server.stop().await;
    }
}
