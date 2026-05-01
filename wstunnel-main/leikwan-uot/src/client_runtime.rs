use std::collections::{BTreeMap, HashMap};
use std::net::SocketAddr;
use std::sync::Arc;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use anyhow::{bail, Context, Result};
use tokio::io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader};
use tokio::net::{TcpStream, UdpSocket};
use tokio::sync::{mpsc, Mutex};
use tokio::time::MissedTickBehavior;

use crate::config::ClientConfig;
use crate::frame::{decode_frame, encode_frame, DataFrame, MsgType};
use crate::merge::{MergeBuffer, MergeItem};

pub const LEG_PROBATION_AFTER_FAILURES: u32 = 2;
pub const LEG_QUARANTINE_AFTER_FAILURES: u32 = 3;
pub const LEG_PROBATION_MS: u64 = 5_000;
pub const LEG_QUARANTINE_MS: u64 = 30_000;
const FRAME_PREFIX_BYTES: usize = 4;
const MAX_RECONNECT_BACKOFF_MS: u64 = 5_000;
const KEEPALIVE_INTERVAL_MS: u64 = 2_000;

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RuntimeLegState {
    pub endpoint_id: u64,
    pub weight: u16,
    pub active: bool,
    pub base_weight: u16,
    pub consecutive_failures: u32,
    pub suppress_until_ms: u64,
}

impl RuntimeLegState {
    pub fn new(endpoint_id: u64, base_weight: u16) -> Self {
        let base_weight = base_weight.max(1);
        Self {
            endpoint_id,
            weight: base_weight,
            active: false,
            base_weight,
            consecutive_failures: 0,
            suppress_until_ms: 0,
        }
    }
}

#[derive(Debug, Clone)]
struct ClientLegConfig {
    endpoint_id: u64,
    host: String,
    port: u16,
    auth_token: String,
}

#[derive(Debug, Default)]
struct Stats {
    live_legs: u64,
    sessions_active: u64,
    datagrams_in: u64,
    datagrams_out: u64,
    datagrams_dropped_gap: u64,
    reorder_buffer_bytes: u64,
}

#[derive(Debug)]
struct ClientState {
    legs: BTreeMap<u64, RuntimeLegState>,
    merge: MergeBuffer,
    peer_to_session: HashMap<SocketAddr, u64>,
    session_to_peer: HashMap<u64, SocketAddr>,
    session_next_seq: HashMap<u64, u64>,
    session_last_seen_ms: HashMap<u64, u64>,
    next_session_id: u64,
    rr_cursor: usize,
    stats: Stats,
}

impl ClientState {
    fn new(config: &ClientConfig) -> Self {
        let legs = config
            .legs
            .iter()
            .map(|leg| (leg.endpoint_id, RuntimeLegState::new(leg.endpoint_id, leg.weight)))
            .collect();
        Self {
            legs,
            merge: MergeBuffer::new(
                config.reorder_window_packets,
                config.reorder_window_bytes,
                config.reorder_timeout_ms,
            ),
            peer_to_session: HashMap::new(),
            session_to_peer: HashMap::new(),
            session_next_seq: HashMap::new(),
            session_last_seen_ms: HashMap::new(),
            next_session_id: 1,
            rr_cursor: 0,
            stats: Stats::default(),
        }
    }

    fn refresh_live_legs(&mut self) {
        self.stats.live_legs = self.legs.values().filter(|leg| leg.active).count() as u64;
    }

    fn alloc_session(&mut self, peer: SocketAddr) -> u64 {
        if let Some(existing) = self.peer_to_session.get(&peer).copied() {
            self.session_last_seen_ms.insert(existing, now_ms());
            return existing;
        }
        let session_id = self.next_session_id;
        self.next_session_id += 1;
        self.peer_to_session.insert(peer, session_id);
        self.session_to_peer.insert(session_id, peer);
        self.session_next_seq.insert(session_id, 1);
        self.session_last_seen_ms.insert(session_id, now_ms());
        self.stats.sessions_active = self.session_to_peer.len() as u64;
        session_id
    }

    fn close_session(&mut self, session_id: u64) {
        if let Some(peer) = self.session_to_peer.remove(&session_id) {
            self.peer_to_session.remove(&peer);
        }
        self.session_next_seq.remove(&session_id);
        self.session_last_seen_ms.remove(&session_id);
        self.merge.session_state.remove(&session_id);
        self.stats.sessions_active = self.session_to_peer.len() as u64;
    }

    fn cleanup_idle_sessions(&mut self, idle_timeout_ms: u64) -> u64 {
        let now = now_ms();
        let stale = self
            .session_last_seen_ms
            .iter()
            .filter_map(|(session_id, seen_at)| {
                if now.saturating_sub(*seen_at) >= idle_timeout_ms {
                    Some(*session_id)
                } else {
                    None
                }
            })
            .collect::<Vec<_>>();
        for session_id in &stale {
            self.close_session(*session_id);
        }
        stale.len() as u64
    }

    fn choose_leg(&mut self) -> Option<u64> {
        let weighted = self
            .legs
            .values()
            .filter(|leg| leg.active && leg.weight > 0)
            .flat_map(|leg| std::iter::repeat_n(leg.endpoint_id, leg.weight as usize))
            .collect::<Vec<_>>();
        if weighted.is_empty() {
            return None;
        }
        let index = self.rr_cursor % weighted.len();
        self.rr_cursor = self.rr_cursor.wrapping_add(1);
        weighted.get(index).copied()
    }
}

#[derive(Debug)]
struct InboundFrame {
    frame: DataFrame,
}

pub fn build_leg_auth_payload(endpoint_id: u64, weight: u16, session_token: &str) -> Vec<u8> {
    serde_json::json!({
        "token": session_token,
        "endpoint_id": endpoint_id,
        "weight": weight.max(1),
    })
    .to_string()
    .into_bytes()
}

pub fn penalize_leg(leg: &mut RuntimeLegState, now_ms: u64) {
    leg.consecutive_failures += 1;
    if leg.consecutive_failures >= LEG_QUARANTINE_AFTER_FAILURES {
        leg.weight = 0;
        leg.suppress_until_ms = now_ms + LEG_QUARANTINE_MS;
    } else if leg.consecutive_failures >= LEG_PROBATION_AFTER_FAILURES {
        leg.weight = (leg.base_weight / 4).max(1);
        leg.suppress_until_ms = now_ms + LEG_PROBATION_MS;
    } else {
        leg.weight = (leg.base_weight / 2).max(1);
        leg.suppress_until_ms = 0;
    }
}

pub fn restore_leg(leg: &mut RuntimeLegState) {
    leg.consecutive_failures = 0;
    leg.suppress_until_ms = 0;
    leg.weight = leg.base_weight;
}

fn now_ms() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_else(|_| Duration::from_secs(0))
        .as_millis() as u64
}

fn parse_host_port(value: &str) -> Result<SocketAddr> {
    value
        .parse::<SocketAddr>()
        .with_context(|| format!("invalid host:port {value:?}"))
}

async fn open_plain_tcp_leg(host: &str, port: u16, route_key: &str, auth_token: &str) -> Result<TcpStream> {
    let mut stream = TcpStream::connect((host, port))
        .await
        .with_context(|| format!("failed to connect to leg {host}:{port}"))?;
    let hello = format!("LEIKWAN-INGRESS 1 OPEN {route_key} {auth_token}\n");
    stream.write_all(hello.as_bytes()).await?;
    stream.flush().await?;

    let mut reader = BufReader::new(stream);
    let mut response = Vec::new();
    reader.read_until(b'\n', &mut response).await?;
    if response.as_slice() != b"OK\n" && response.as_slice() != b"OK\r\n" {
        bail!(
            "plain tcp mux open rejected: {}",
            String::from_utf8_lossy(&response).trim()
        );
    }
    Ok(reader.into_inner())
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
    let mut len_buf = [0u8; FRAME_PREFIX_BYTES];
    reader.read_exact(&mut len_buf).await?;
    let frame_len = u32::from_be_bytes(len_buf) as usize;
    let mut encoded = vec![0u8; frame_len];
    reader.read_exact(&mut encoded).await?;
    decode_frame(&encoded)
}

async fn leg_loop(
    leg_cfg: ClientLegConfig,
    state: Arc<Mutex<ClientState>>,
    config: ClientConfig,
    mut outbound_rx: mpsc::Receiver<DataFrame>,
    inbound_tx: mpsc::Sender<InboundFrame>,
) -> Result<()> {
    let mut backoff_ms = 250u64;
    loop {
        {
            let guard = state.lock().await;
            if let Some(leg) = guard.legs.get(&leg_cfg.endpoint_id) {
                if leg.suppress_until_ms > now_ms() {
                    let sleep_ms = leg.suppress_until_ms.saturating_sub(now_ms());
                    drop(guard);
                    tokio::time::sleep(Duration::from_millis(sleep_ms)).await;
                }
            }
        }

        let stream = match open_plain_tcp_leg(&leg_cfg.host, leg_cfg.port, &config.route_key, &leg_cfg.auth_token).await {
            Ok(stream) => stream,
            Err(_) => {
                let mut guard = state.lock().await;
                if let Some(leg) = guard.legs.get_mut(&leg_cfg.endpoint_id) {
                    penalize_leg(leg, now_ms());
                    leg.active = false;
                }
                guard.refresh_live_legs();
                drop(guard);
                tokio::time::sleep(Duration::from_millis(backoff_ms)).await;
                backoff_ms = (backoff_ms * 2).min(MAX_RECONNECT_BACKOFF_MS);
                continue;
            }
        };

        let (mut reader, mut writer) = stream.into_split();
        let auth = DataFrame {
            version: 1,
            msg_type: MsgType::Auth,
            flags: 0,
            session_id: 0,
            seq: 0,
            payload: build_leg_auth_payload(leg_cfg.endpoint_id, {
                let guard = state.lock().await;
                guard.legs.get(&leg_cfg.endpoint_id).map(|leg| leg.weight).unwrap_or(1)
            }, &config.session_token),
        };
        if let Err(_) = write_frame_prefixed(&mut writer, &auth).await {
            let mut guard = state.lock().await;
            if let Some(leg) = guard.legs.get_mut(&leg_cfg.endpoint_id) {
                penalize_leg(leg, now_ms());
                leg.active = false;
            }
            guard.refresh_live_legs();
            drop(guard);
            tokio::time::sleep(Duration::from_millis(backoff_ms)).await;
            backoff_ms = (backoff_ms * 2).min(MAX_RECONNECT_BACKOFF_MS);
            continue;
        }

        {
            let mut guard = state.lock().await;
            if let Some(leg) = guard.legs.get_mut(&leg_cfg.endpoint_id) {
                restore_leg(leg);
                leg.active = true;
            }
            guard.refresh_live_legs();
        }
        backoff_ms = 250;

        let (frame_tx, mut frame_rx) = mpsc::channel::<Result<DataFrame>>(128);
        tokio::spawn(async move {
            loop {
                let frame = read_frame_prefixed(&mut reader).await;
                if frame_tx.send(frame).await.is_err() {
                    break;
                }
            }
        });

        let mut keepalive = tokio::time::interval(Duration::from_millis(KEEPALIVE_INTERVAL_MS));
        keepalive.set_missed_tick_behavior(MissedTickBehavior::Delay);
        let result: Result<()> = loop {
            tokio::select! {
                maybe_inbound = frame_rx.recv() => {
                    match maybe_inbound {
                        Some(Ok(frame)) => {
                            inbound_tx.send(InboundFrame { frame }).await?;
                        }
                        Some(Err(err)) => break Err(err),
                        None => break Ok(()),
                    }
                }
                maybe_outbound = outbound_rx.recv() => {
                    let Some(frame) = maybe_outbound else {
                        break Ok(());
                    };
                    if let Err(err) = write_frame_prefixed(&mut writer, &frame).await {
                        break Err(err);
                    }
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
                    if let Err(err) = write_frame_prefixed(&mut writer, &frame).await {
                        break Err(err);
                    }
                }
            }
        };

        {
            let mut guard = state.lock().await;
            if let Some(leg) = guard.legs.get_mut(&leg_cfg.endpoint_id) {
                penalize_leg(leg, now_ms());
                leg.active = false;
            }
            guard.refresh_live_legs();
        }

        if result.is_err() {
            tokio::time::sleep(Duration::from_millis(backoff_ms)).await;
            backoff_ms = (backoff_ms * 2).min(MAX_RECONNECT_BACKOFF_MS);
        }
    }
}

pub async fn run_from_path(path: &str) -> Result<()> {
    let raw = std::fs::read_to_string(path)?;
    let config: ClientConfig = serde_json::from_str(&raw)?;
    run(config).await
}

pub async fn run(config: ClientConfig) -> Result<()> {
    let listen_addr = parse_host_port(&config.listen_udp)?;
    let socket = Arc::new(UdpSocket::bind(listen_addr).await?);
    let state = Arc::new(Mutex::new(ClientState::new(&config)));
    let (inbound_tx, mut inbound_rx) = mpsc::channel::<InboundFrame>(4096);

    let mut outbound_txs = HashMap::<u64, mpsc::Sender<DataFrame>>::new();
    for leg in &config.legs {
        let (tx, rx) = mpsc::channel(4096);
        outbound_txs.insert(leg.endpoint_id, tx);
        let leg_cfg = ClientLegConfig {
            endpoint_id: leg.endpoint_id,
            host: leg.host.clone(),
            port: leg.port,
            auth_token: leg.auth_token.clone(),
        };
        tokio::spawn(leg_loop(
            leg_cfg,
            state.clone(),
            config.clone(),
            rx,
            inbound_tx.clone(),
        ));
    }

    let udp_state = state.clone();
    let udp_socket = socket.clone();
    let udp_outbound = outbound_txs.clone();
    let udp_idle_timeout = config.session_idle_timeout_ms;
    tokio::spawn(async move {
        let mut buf = vec![0u8; 65_535];
        loop {
            let Ok((len, peer)) = udp_socket.recv_from(&mut buf).await else {
                continue;
            };
            let payload = buf[..len].to_vec();
            let maybe_frame = {
                let mut guard = udp_state.lock().await;
                guard.stats.datagrams_in += 1;
                let session_id = guard.alloc_session(peer);
                let seq = guard.session_next_seq.get(&session_id).copied().unwrap_or(1);
                guard.session_next_seq.insert(session_id, seq + 1);
                if let Some(endpoint_id) = guard.choose_leg() {
                    Some((endpoint_id, DataFrame {
                        version: 1,
                        msg_type: MsgType::Data,
                        flags: 0,
                        session_id: session_id as u32,
                        seq,
                        payload,
                    }))
                } else {
                    guard.stats.datagrams_dropped_gap += 1;
                    None
                }
            };
            if let Some((endpoint_id, frame)) = maybe_frame {
                if let Some(tx) = udp_outbound.get(&endpoint_id) {
                    if tx.send(frame).await.is_err() {
                        let mut guard = udp_state.lock().await;
                        guard.stats.datagrams_dropped_gap += 1;
                    }
                }
            }
            let mut guard = udp_state.lock().await;
            let _ = guard.cleanup_idle_sessions(udp_idle_timeout);
        }
    });

    let dispatch_state = state.clone();
    let dispatch_socket = socket.clone();
    let reorder_timeout = config.reorder_timeout_ms;
    let idle_timeout = config.session_idle_timeout_ms;
    tokio::spawn(async move {
        let mut tick = tokio::time::interval(Duration::from_millis((reorder_timeout.max(1) / 2).max(1)));
        tick.set_missed_tick_behavior(MissedTickBehavior::Delay);
        loop {
            tokio::select! {
                maybe_frame = inbound_rx.recv() => {
                    let Some(inbound) = maybe_frame else { break; };
                    let mut emits = Vec::<(Vec<u8>, SocketAddr, u64)>::new();
                    {
                        let mut guard = dispatch_state.lock().await;
                        let frame = inbound.frame;
                        match frame.msg_type {
                            MsgType::Data => {
                                if guard.session_to_peer.contains_key(&(frame.session_id as u64)) {
                                    guard.session_last_seen_ms.insert(frame.session_id as u64, now_ms());
                                    for item in guard.merge.push(frame.session_id as u64, frame.seq, frame.payload) {
                                        if let Some(peer) = guard.session_to_peer.get(&item.session_id).copied() {
                                            emits.push((item.payload, peer, item.session_id));
                                        }
                                    }
                                } else {
                                    guard.stats.datagrams_dropped_gap += 1;
                                }
                            }
                            MsgType::Close => {
                                guard.close_session(frame.session_id as u64);
                            }
                            MsgType::Keepalive | MsgType::Auth | MsgType::Open => {}
                        }
                        guard.stats.reorder_buffer_bytes = guard.merge.total_buffered_bytes() as u64;
                    }
                    for (payload, peer, session_id) in emits {
                        let _ = dispatch_socket.send_to(&payload, peer).await;
                        let mut guard = dispatch_state.lock().await;
                        guard.session_last_seen_ms.insert(session_id, now_ms());
                        guard.stats.datagrams_out += 1;
                    }
                }
                _ = tick.tick() => {
                    let mut emits = Vec::<(Vec<u8>, SocketAddr, u64)>::new();
                    {
                        let mut guard = dispatch_state.lock().await;
                        let released = guard.merge.flush_timeouts(now_ms());
                        if guard.merge.skipped_gap_packets > 0 {
                            guard.stats.datagrams_dropped_gap += guard.merge.skipped_gap_packets;
                        }
                        for MergeItem { session_id, payload, .. } in released {
                            if let Some(peer) = guard.session_to_peer.get(&session_id).copied() {
                                emits.push((payload, peer, session_id));
                            }
                        }
                        let _ = guard.cleanup_idle_sessions(idle_timeout);
                        guard.stats.reorder_buffer_bytes = guard.merge.total_buffered_bytes() as u64;
                    }
                    for (payload, peer, session_id) in emits {
                        let _ = dispatch_socket.send_to(&payload, peer).await;
                        let mut guard = dispatch_state.lock().await;
                        guard.session_last_seen_ms.insert(session_id, now_ms());
                        guard.stats.datagrams_out += 1;
                    }
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
                    "reorder_buffer_bytes": guard.stats.reorder_buffer_bytes,
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
    fn build_leg_auth_payload_encodes_token_endpoint_and_weight() {
        let payload = build_leg_auth_payload(184, 8, "udp-token");
        let decoded: serde_json::Value = serde_json::from_slice(&payload).expect("json");
        assert_eq!(decoded["token"], "udp-token");
        assert_eq!(decoded["endpoint_id"], 184);
        assert_eq!(decoded["weight"], 8);
    }

    #[test]
    fn penalize_leg_quarantines_after_three_failures() {
        let mut leg = RuntimeLegState::new(184, 8);
        penalize_leg(&mut leg, 1000);
        assert!(leg.weight >= 1);
        penalize_leg(&mut leg, 1100);
        assert!(leg.suppress_until_ms >= 1100);
        penalize_leg(&mut leg, 1200);
        assert_eq!(leg.weight, 0);
        assert!(leg.suppress_until_ms > 1200);
    }
}
