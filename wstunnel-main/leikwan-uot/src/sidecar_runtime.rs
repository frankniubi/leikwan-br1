use std::collections::{BTreeMap, HashMap, VecDeque};
use std::sync::Arc;
use std::time::Duration;

use anyhow::{anyhow, bail, Context, Result};
use base64::Engine;
use hmac::{Hmac, Mac};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use tokio::io::{AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream, UdpSocket};
use tokio::sync::{mpsc, Mutex};
use tokio::time::MissedTickBehavior;

use crate::config::SidecarConfig;
use crate::frame::{decode_frame, encode_frame, DataFrame, MsgType};
use crate::merge::{MergeBuffer, MergeItem};

pub const LEG_PROBATION_AFTER_FAILURES: u32 = 2;
pub const LEG_QUARANTINE_AFTER_FAILURES: u32 = 3;
pub const LEG_PROBATION_MS: u64 = 5_000;
pub const LEG_QUARANTINE_MS: u64 = 30_000;

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct ParsedLegAuthPayload {
    pub token: String,
    pub endpoint_id: u64,
    pub weight: u16,
}

#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct EndpointPenalty {
    pub failures: u32,
    pub suppress_until_ms: u64,
}

#[derive(Debug, Clone, Default)]
pub struct EndpointPenalties {
    pub entries: HashMap<u64, EndpointPenalty>,
}

impl EndpointPenalties {
    pub fn apply_failure(&mut self, endpoint_id: u64, now_ms: u64) {
        if endpoint_id == 0 {
            return;
        }
        let penalty = self.entries.entry(endpoint_id).or_default();
        penalty.failures += 1;
        if penalty.failures >= LEG_QUARANTINE_AFTER_FAILURES {
            penalty.suppress_until_ms = now_ms + LEG_QUARANTINE_MS;
        } else if penalty.failures >= LEG_PROBATION_AFTER_FAILURES {
            penalty.suppress_until_ms = now_ms + LEG_PROBATION_MS;
        } else {
            penalty.suppress_until_ms = 0;
        }
    }

    pub fn clear(&mut self, endpoint_id: u64) {
        if endpoint_id == 0 {
            return;
        }
        self.entries.insert(endpoint_id, EndpointPenalty::default());
    }
}

#[derive(Debug, Clone)]
struct ClaimSet {
    forward_id: u64,
    target_host: String,
    target_port: u16,
}

#[derive(Debug, Clone)]
struct ActiveLeg {
    leg_id: u64,
    endpoint_id: u64,
    scope_key: ScopeKey,
    claims: ClaimSet,
    weight: u16,
}

#[derive(Debug, Clone, PartialEq, Eq, PartialOrd, Ord, Hash)]
struct ScopeKey {
    forward_id: u64,
    target_host: String,
    target_port: u16,
    token_fingerprint: String,
}

#[derive(Debug)]
struct SessionInfo {
    scope_key: ScopeKey,
    session_id: u64,
    forward_id: u64,
    target_host: String,
    target_port: u16,
    socket: Arc<UdpSocket>,
    reverse_seq: u64,
    last_seen_ms: u64,
}

#[derive(Debug, Default)]
struct Stats {
    live_legs: u64,
    sessions_active: u64,
    datagrams_in: u64,
    datagrams_out: u64,
    datagrams_dropped_gap: u64,
    datagrams_dropped_no_leg: u64,
    bytes_in: u64,
    bytes_out: u64,
    reorder_buffer_bytes: u64,
}

#[derive(Debug)]
struct SidecarState {
    merge: MergeBuffer,
    active_legs: HashMap<u64, ActiveLeg>,
    scope_leg_order: BTreeMap<ScopeKey, VecDeque<u64>>,
    scope_claims: BTreeMap<ScopeKey, ClaimSet>,
    sessions: BTreeMap<(ScopeKey, u64), SessionInfo>,
    endpoint_penalties: EndpointPenalties,
    next_leg_id: u64,
    stats: Stats,
}

impl SidecarState {
    fn new(config: &SidecarConfig) -> Self {
        Self {
            merge: MergeBuffer::new(
                config.reorder_window_packets,
                config.reorder_window_bytes,
                config.reorder_timeout_ms,
            ),
            active_legs: HashMap::new(),
            scope_leg_order: BTreeMap::new(),
            scope_claims: BTreeMap::new(),
            sessions: BTreeMap::new(),
            endpoint_penalties: EndpointPenalties::default(),
            next_leg_id: 1,
            stats: Stats::default(),
        }
    }

    fn register_leg(&mut self, leg: ActiveLeg) {
        let leg_id = leg.leg_id;
        let scope_key = leg.scope_key.clone();
        let claims = leg.claims.clone();
        let weight = leg.weight.max(1);
        self.active_legs.insert(leg_id, leg);
        let order = self.scope_leg_order.entry(scope_key.clone()).or_default();
        if !order.iter().any(|existing| *existing == leg_id) {
            for _ in 0..weight {
                order.push_back(leg_id);
            }
        }
        self.scope_claims.insert(scope_key, claims);
        self.stats.live_legs = self.active_legs.len() as u64;
    }

    fn remove_leg(&mut self, leg_id: u64) {
        if let Some(leg) = self.active_legs.remove(&leg_id) {
            if let Some(order) = self.scope_leg_order.get_mut(&leg.scope_key) {
                *order = order.iter().copied().filter(|existing| *existing != leg_id).collect();
                if order.is_empty() {
                    self.scope_leg_order.remove(&leg.scope_key);
                    self.scope_claims.remove(&leg.scope_key);
                }
            }
        }
        self.stats.live_legs = self.active_legs.len() as u64;
    }

    fn pick_leg(&mut self, scope_key: &ScopeKey) -> Option<ActiveLeg> {
        let order = self.scope_leg_order.get_mut(scope_key)?;
        for _ in 0..order.len() {
            let leg_id = order.pop_front()?;
            order.push_back(leg_id);
            if let Some(leg) = self.active_legs.get(&leg_id) {
                return Some(leg.clone());
            }
        }
        None
    }
}

pub fn parse_leg_auth_payload(payload: &[u8]) -> Result<ParsedLegAuthPayload> {
    let text = std::str::from_utf8(payload)?.to_string();
    match serde_json::from_str::<serde_json::Value>(&text) {
        Ok(value) => {
            let obj = value
                .as_object()
                .ok_or_else(|| anyhow!("auth payload must be an object"))?;
            let token = obj
                .get("token")
                .and_then(|value| value.as_str())
                .unwrap_or("")
                .trim()
                .to_string();
            if token.is_empty() {
                bail!("auth payload missing token");
            }
            Ok(ParsedLegAuthPayload {
                token,
                endpoint_id: obj.get("endpoint_id").and_then(|value| value.as_u64()).unwrap_or(0),
                weight: obj
                    .get("weight")
                    .and_then(|value| value.as_u64())
                    .unwrap_or(1)
                    .max(1) as u16,
            })
        }
        Err(_) => Ok(ParsedLegAuthPayload {
            token: text,
            endpoint_id: 0,
            weight: 1,
        }),
    }
}

fn now_ms() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_else(|_| Duration::from_secs(0))
        .as_millis() as u64
}

fn verify_session_token(token: &str, secret: &str, now: u64) -> Result<ClaimSet> {
    let (payload_b64, signature_b64) = token
        .split_once('.')
        .ok_or_else(|| anyhow!("invalid token format"))?;
    let payload_bytes = base64::engine::general_purpose::URL_SAFE_NO_PAD
        .decode(payload_b64)
        .context("invalid payload encoding")?;
    let actual_sig = base64::engine::general_purpose::URL_SAFE_NO_PAD
        .decode(signature_b64)
        .context("invalid signature encoding")?;
    let mut mac = Hmac::<Sha256>::new_from_slice(secret.as_bytes()).context("invalid secret")?;
    mac.update(&payload_bytes);
    mac.verify_slice(&actual_sig).map_err(|_| anyhow!("token signature verification failed"))?;
    let payload: serde_json::Value = serde_json::from_slice(&payload_bytes)?;
    let expires_at = payload.get("expires_at").and_then(|v| v.as_u64()).unwrap_or(0);
    if expires_at > 0 && now > expires_at {
        bail!("session token expired");
    }
    let target_protocol = payload
        .get("target_protocol")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .trim()
        .to_string();
    let target_host = payload
        .get("target_host")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .trim()
        .to_string();
    let target_port = payload.get("target_port").and_then(|v| v.as_u64()).unwrap_or(0) as u16;
    let forward_id = payload.get("forward_id").and_then(|v| v.as_u64()).unwrap_or(0);
    if target_protocol.to_lowercase() != "udp" {
        bail!("protocol mismatch");
    }
    if target_host.is_empty() || target_port == 0 {
        bail!("missing udp target");
    }
    Ok(ClaimSet {
        forward_id,
        target_host,
        target_port,
    })
}

fn build_scope_key(claims: &ClaimSet, token: &str) -> ScopeKey {
    ScopeKey {
        forward_id: claims.forward_id,
        target_host: claims.target_host.clone(),
        target_port: claims.target_port,
        token_fingerprint: format!("{:x}", sha2::Sha256::digest(token.as_bytes()))[..16].to_string(),
    }
}

async fn write_frame_prefixed(writer: &mut tokio::net::tcp::OwnedWriteHalf, frame: &DataFrame) -> Result<()> {
    let encoded = encode_frame(frame);
    let len = u32::try_from(encoded.len()).context("frame too large")?;
    writer.write_all(&len.to_be_bytes()).await?;
    writer.write_all(&encoded).await?;
    writer.flush().await?;
    Ok(())
}

async fn read_frame_prefixed(reader: &mut tokio::net::tcp::OwnedReadHalf) -> Result<DataFrame> {
    let mut len_buf = [0u8; 4];
    reader.read_exact(&mut len_buf).await?;
    let len = u32::from_be_bytes(len_buf) as usize;
    let mut buf = vec![0u8; len];
    reader.read_exact(&mut buf).await?;
    decode_frame(&buf)
}

async fn handle_leg(
    stream: TcpStream,
    state: Arc<Mutex<SidecarState>>,
    outbound_txs: Arc<Mutex<HashMap<u64, mpsc::Sender<DataFrame>>>>,
    config: SidecarConfig,
    inbound_tx: mpsc::Sender<(u64, DataFrame)>,
) -> Result<()> {
    let leg_id = {
        let mut guard = state.lock().await;
        let current = guard.next_leg_id;
        guard.next_leg_id += 1;
        current
    };
    let (mut reader, mut writer) = stream.into_split();
    let first_frame = read_frame_prefixed(&mut reader).await?;
    if first_frame.msg_type != MsgType::Auth {
        bail!("auth frame required before data");
    }
    let auth_payload = parse_leg_auth_payload(&first_frame.payload)?;
    let now = now_ms();
    let claims = verify_session_token(&auth_payload.token, &config.token_secret, now)?;
    if !config.allowed_forward_ids.is_empty() && !config.allowed_forward_ids.contains(&claims.forward_id) {
        bail!("forward {} is not allowed", claims.forward_id);
    }
    let scope_key = build_scope_key(&claims, &auth_payload.token);
    let penalty = {
        let guard = state.lock().await;
        guard.endpoint_penalties.entries.get(&auth_payload.endpoint_id).cloned().unwrap_or_default()
    };
    if penalty.suppress_until_ms > now {
        bail!("endpoint {} still suppressed", auth_payload.endpoint_id);
    }
    let mut weight = auth_payload.weight.max(1);
    if penalty.failures >= LEG_QUARANTINE_AFTER_FAILURES {
        weight = 1;
    } else if penalty.failures >= LEG_PROBATION_AFTER_FAILURES {
        weight = (weight / 4).max(1);
    }
    let (tx, mut rx) = mpsc::channel::<DataFrame>(4096);
    {
        let mut guard = outbound_txs.lock().await;
        guard.insert(leg_id, tx);
    }
    {
        let mut guard = state.lock().await;
        guard.endpoint_penalties.clear(auth_payload.endpoint_id);
        guard.register_leg(ActiveLeg {
            leg_id,
            endpoint_id: auth_payload.endpoint_id,
            scope_key: scope_key.clone(),
            claims: claims.clone(),
            weight,
        });
    }

    let (frame_tx, mut frame_rx) = mpsc::channel::<Result<DataFrame>>(128);
    tokio::spawn(async move {
        loop {
            let frame = read_frame_prefixed(&mut reader).await;
            if frame_tx.send(frame).await.is_err() {
                break;
            }
        }
    });

    let mut keepalive = tokio::time::interval(Duration::from_secs(2));
    keepalive.set_missed_tick_behavior(MissedTickBehavior::Delay);
    loop {
        tokio::select! {
            maybe_frame = frame_rx.recv() => {
                match maybe_frame {
                    Some(Ok(frame)) => {
                        if frame.msg_type == MsgType::Auth {
                            bail!("auth frame must be first binary frame");
                        }
                        inbound_tx.send((leg_id, frame)).await?;
                    }
                    Some(Err(err)) => {
                        let mut guard = state.lock().await;
                        guard.endpoint_penalties.apply_failure(auth_payload.endpoint_id, now_ms());
                        guard.remove_leg(leg_id);
                        drop(guard);
                        let mut outbound = outbound_txs.lock().await;
                        outbound.remove(&leg_id);
                        return Err(err);
                    }
                    None => break,
                }
            }
            maybe_out = rx.recv() => {
                let Some(frame) = maybe_out else { break; };
                write_frame_prefixed(&mut writer, &frame).await?;
            }
            _ = keepalive.tick() => {
                let frame = DataFrame {
                    version: 1,
                    msg_type: MsgType::Keepalive,
                    flags: 0,
                    session_id: 0,
                    seq: 0,
                    payload: Vec::new(),
                };
                write_frame_prefixed(&mut writer, &frame).await?;
            }
        }
    }

    {
        let mut guard = state.lock().await;
        guard.endpoint_penalties.apply_failure(auth_payload.endpoint_id, now_ms());
        guard.remove_leg(leg_id);
    }
    let mut outbound = outbound_txs.lock().await;
    outbound.remove(&leg_id);
    Ok(())
}

pub async fn run_from_path(path: &str) -> Result<()> {
    let raw = std::fs::read_to_string(path)?;
    let config: SidecarConfig = serde_json::from_str(&raw)?;
    run(config).await
}

pub async fn run(config: SidecarConfig) -> Result<()> {
    let state = Arc::new(Mutex::new(SidecarState::new(&config)));
    let outbound_txs = Arc::new(Mutex::new(HashMap::<u64, mpsc::Sender<DataFrame>>::new()));
    let (inbound_tx, mut inbound_rx) = mpsc::channel::<(u64, DataFrame)>(4096);
    let (udp_tx, mut udp_rx) = mpsc::channel::<((ScopeKey, u64), Vec<u8>)>(4096);
    let listener = TcpListener::bind((config.listen_host.as_str(), config.listen_port)).await?;

    let accept_state = state.clone();
    let accept_outbound = outbound_txs.clone();
    let accept_config = config.clone();
    let accept_inbound = inbound_tx.clone();
    tokio::spawn(async move {
        loop {
            let Ok((stream, _peer)) = listener.accept().await else {
                continue;
            };
            let state = accept_state.clone();
            let outbound = accept_outbound.clone();
            let config = accept_config.clone();
            let inbound_tx = accept_inbound.clone();
            tokio::spawn(async move {
                let _ = handle_leg(stream, state, outbound, config, inbound_tx).await;
            });
        }
    });

    let dispatch_state = state.clone();
    let dispatch_outbound = outbound_txs.clone();
    let dispatch_udp_tx = udp_tx.clone();
    let idle_timeout = config.session_idle_timeout_ms;
    let reorder_timeout = config.reorder_timeout_ms;
    tokio::spawn(async move {
        let mut tick = tokio::time::interval(Duration::from_millis((reorder_timeout.max(1) / 2).max(1)));
        tick.set_missed_tick_behavior(MissedTickBehavior::Delay);
        loop {
            tokio::select! {
                maybe_inbound = inbound_rx.recv() => {
                    let Some((leg_id, frame)) = maybe_inbound else { break; };
                    let mut sends = Vec::<(Arc<UdpSocket>, Vec<u8>)>::new();
                    match frame.msg_type {
                        MsgType::Data => {
                            let mut guard = dispatch_state.lock().await;
                            let leg = match guard.active_legs.get(&leg_id).cloned() {
                                Some(leg) => leg,
                                None => continue,
                            };
                            for MergeItem { session_id, payload, .. } in guard.merge.push(frame.session_id as u64, frame.seq, frame.payload) {
                                let full_key = (leg.scope_key.clone(), session_id);
                                if !guard.sessions.contains_key(&full_key) {
                                    if let Ok(socket) = UdpSocket::bind("0.0.0.0:0").await {
                                        if socket.connect((leg.claims.target_host.as_str(), leg.claims.target_port)).await.is_ok() {
                                            let session_socket = Arc::new(socket);
                                            let udp_tx = dispatch_udp_tx.clone();
                                            let session_key = full_key.clone();
                                            let reader_socket = session_socket.clone();
                                            tokio::spawn(async move {
                                                let mut buf = vec![0u8; 65_535];
                                                loop {
                                                    let Ok(size) = reader_socket.recv(&mut buf).await else {
                                                        break;
                                                    };
                                                    if udp_tx.send((session_key.clone(), buf[..size].to_vec())).await.is_err() {
                                                        break;
                                                    }
                                                }
                                            });
                                            guard.sessions.insert(full_key.clone(), SessionInfo {
                                                scope_key: leg.scope_key.clone(),
                                                session_id,
                                                forward_id: leg.claims.forward_id,
                                                target_host: leg.claims.target_host.clone(),
                                                target_port: leg.claims.target_port,
                                                socket: session_socket,
                                                reverse_seq: 1,
                                                last_seen_ms: now_ms(),
                                            });
                                        }
                                    }
                                }
                                if let Some(session) = guard.sessions.get_mut(&full_key) {
                                    session.last_seen_ms = now_ms();
                                    sends.push((session.socket.clone(), payload));
                                    guard.stats.datagrams_in += 1;
                                    guard.stats.bytes_in += sends.last().map(|(_, p)| p.len() as u64).unwrap_or(0);
                                }
                            }
                            guard.stats.reorder_buffer_bytes = guard.merge.total_buffered_bytes() as u64;
                        }
                        MsgType::Close => {
                            let mut guard = dispatch_state.lock().await;
                            let leg = match guard.active_legs.get(&leg_id).cloned() {
                                Some(leg) => leg,
                                None => continue,
                            };
                            guard.sessions.remove(&(leg.scope_key, frame.session_id as u64));
                            guard.stats.sessions_active = guard.sessions.len() as u64;
                        }
                        MsgType::Keepalive | MsgType::Auth | MsgType::Open => {}
                    }
                    for (socket, payload) in sends {
                        let _ = socket.send(&payload).await;
                    }
                }
                maybe_udp = udp_rx.recv() => {
                    let Some((session_key, payload)) = maybe_udp else { break; };
                    let mut maybe_send = None::<(u64, DataFrame)>;
                    {
                        let mut guard = dispatch_state.lock().await;
                        let (scope_key, session_id) = session_key.clone();
                        let chosen_leg = guard.pick_leg(&scope_key);
                        if let Some(session) = guard.sessions.get_mut(&session_key) {
                            session.last_seen_ms = now_ms();
                            if let Some(leg) = chosen_leg {
                                let frame = DataFrame {
                                    version: 1,
                                    msg_type: MsgType::Data,
                                    flags: 0,
                                    session_id: session_id as u32,
                                    seq: session.reverse_seq,
                                    payload,
                                };
                                session.reverse_seq += 1;
                                guard.stats.datagrams_out += 1;
                                guard.stats.bytes_out += frame.payload.len() as u64;
                                maybe_send = Some((leg.leg_id, frame));
                            } else {
                                guard.stats.datagrams_dropped_no_leg += 1;
                            }
                        }
                    }
                    if let Some((leg_id, frame)) = maybe_send {
                        let outbound = dispatch_outbound.lock().await;
                        if let Some(tx) = outbound.get(&leg_id) {
                            let _ = tx.send(frame).await;
                        }
                    }
                }
                _ = tick.tick() => {
                    let mut guard = dispatch_state.lock().await;
                    let released = guard.merge.flush_timeouts(now_ms());
                    if guard.merge.skipped_gap_packets > 0 {
                        guard.stats.datagrams_dropped_gap += guard.merge.skipped_gap_packets;
                    }
                    let stale = guard.sessions.iter().filter_map(|(key, session)| {
                        if now_ms().saturating_sub(session.last_seen_ms) >= idle_timeout {
                            Some(key.clone())
                        } else {
                            None
                        }
                    }).collect::<Vec<_>>();
                    for key in stale {
                        guard.sessions.remove(&key);
                    }
                    guard.stats.sessions_active = guard.sessions.len() as u64;
                    guard.stats.reorder_buffer_bytes = guard.merge.total_buffered_bytes() as u64;
                    let _ = released;
                }
            }
        }
    });

    let stats_state = state.clone();
    let stats_path = config.stats_path.clone();
    tokio::spawn(async move {
        loop {
            let payload = {
                let guard = stats_state.lock().await;
                serde_json::json!({
                    "live_legs": guard.stats.live_legs,
                    "sessions_active": guard.stats.sessions_active,
                    "datagrams_in": guard.stats.datagrams_in,
                    "datagrams_out": guard.stats.datagrams_out,
                    "datagrams_dropped_gap": guard.stats.datagrams_dropped_gap,
                    "datagrams_dropped_no_leg": guard.stats.datagrams_dropped_no_leg,
                    "bytes_in": guard.stats.bytes_in,
                    "bytes_out": guard.stats.bytes_out,
                    "reorder_buffer_bytes": guard.stats.reorder_buffer_bytes,
                    "updated_at": now_ms() / 1000,
                })
            };
            let path = std::path::Path::new(&stats_path);
            if let Some(parent) = path.parent() {
                let _ = std::fs::create_dir_all(parent);
            }
            let _ = std::fs::write(path, serde_json::to_vec(&payload).unwrap_or_default());
            tokio::time::sleep(Duration::from_secs(1)).await;
        }
    });

    tokio::signal::ctrl_c().await?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parse_leg_auth_payload_accepts_weighted_json_payload() {
        let payload = br#"{"token":"udp-token","endpoint_id":184,"weight":8}"#;
        let parsed = parse_leg_auth_payload(payload).expect("parse");
        assert_eq!(parsed.token, "udp-token");
        assert_eq!(parsed.endpoint_id, 184);
        assert_eq!(parsed.weight, 8);
    }

    #[test]
    fn parse_leg_auth_payload_accepts_legacy_plain_token() {
        let parsed = parse_leg_auth_payload(b"legacy-token").expect("parse");
        assert_eq!(parsed.token, "legacy-token");
        assert_eq!(parsed.endpoint_id, 0);
        assert_eq!(parsed.weight, 1);
    }

    #[test]
    fn apply_endpoint_failure_quarantines_after_three_failures() {
        let mut penalties = EndpointPenalties::default();
        penalties.apply_failure(184, 1000);
        penalties.apply_failure(184, 1100);
        penalties.apply_failure(184, 1200);
        let penalty = penalties.entries.get(&184).expect("penalty");
        assert_eq!(penalty.failures, 3);
        assert!(penalty.suppress_until_ms > 1200);
    }
}
