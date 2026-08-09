use std::collections::{BTreeMap, HashMap};
use std::io;
use std::net::SocketAddr;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use anyhow::{bail, Context, Result};
use bytes::Bytes;
use tokio::io::{AsyncBufReadExt, AsyncRead, AsyncReadExt, AsyncWriteExt, BufReader};
use tokio::net::{TcpStream, UdpSocket};
use tokio::sync::{mpsc, Mutex};
use tokio::time::MissedTickBehavior;

use crate::config::{ClientConfig, SchedulerMode};
use crate::delivery::DatagramDelivery;
use crate::frame::{decode_frame, encode_frame, DataFrame, MsgType};
use crate::merge::MergeItem;
use crate::progress::{AuthAck, AuthCapabilities, Direction, LegProgress, ProgressEmitter};
use crate::queue::{select_least_backlogged_after, LegQueueBudget, QueueCandidate};
use crate::scheduler::{LegRegistration, SchedulerScopeKey, SchedulerSessionKey, TrafficClass};
use crate::scheduler_runtime::{
    connection_epoch, process_epoch, spawn_scheduler, RuntimeChunk, SchedulerCommand,
    SchedulerHandle,
};
use crate::telemetry::UdpErrorKind;
use crate::udp_socket::bind_udp_socket;
use crate::wireguard::is_duplicate_eligible;

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
    queue: LegQueueBudget,
    sent_packets: u64,
    received_packets: u64,
    write_failures: u64,
    reconnects: u64,
}

impl RuntimeLegState {
    pub fn new(endpoint_id: u64, base_weight: u16) -> Self {
        Self::with_queue_limits(endpoint_id, base_weight, 2_048, 16 * 1024 * 1024)
    }

    fn with_queue_limits(
        endpoint_id: u64,
        base_weight: u16,
        max_queue_packets: usize,
        max_queue_bytes: usize,
    ) -> Self {
        let base_weight = base_weight.max(1);
        Self {
            endpoint_id,
            weight: base_weight,
            active: false,
            base_weight,
            consecutive_failures: 0,
            suppress_until_ms: 0,
            queue: LegQueueBudget::new(max_queue_packets, max_queue_bytes),
            sent_packets: 0,
            received_packets: 0,
            write_failures: 0,
            reconnects: 0,
        }
    }
}

#[derive(Debug, Clone)]
struct ClientLegConfig {
    endpoint_id: u64,
    host: String,
    port: u16,
    auth_token: String,
    direct_tcp: bool,
    socks5_proxy: Option<String>,
}

#[derive(Debug, Default)]
struct Stats {
    live_legs: u64,
    sessions_active: u64,
    datagrams_in: u64,
    datagrams_out: u64,
    datagrams_dropped_gap: u64,
    datagrams_dropped_no_leg: u64,
    datagrams_dropped_queue_full: u64,
    datagrams_dropped_invalid: u64,
    datagrams_dropped_unknown_session: u64,
    datagrams_direct_delivered: u64,
    handshake_datagrams_duplicated: u64,
    reorder_buffer_bytes: u64,
    udp_egress_errors: u64,
    control_queue_drops: u64,
}

#[derive(Debug)]
struct ClientState {
    legs: BTreeMap<u64, RuntimeLegState>,
    delivery: DatagramDelivery,
    peer_to_session: HashMap<SocketAddr, u64>,
    session_to_peer: HashMap<u64, SocketAddr>,
    session_next_seq: HashMap<u64, u64>,
    session_last_seen_ms: HashMap<u64, u64>,
    next_session_id: u64,
    next_dispatch_cursor: u64,
    stats: Stats,
}

impl ClientState {
    fn new(config: &ClientConfig) -> Self {
        let legs = config
            .legs
            .iter()
            .map(|leg| {
                (
                    leg.endpoint_id,
                    RuntimeLegState::with_queue_limits(
                        leg.endpoint_id,
                        leg.weight,
                        config.max_leg_queue_packets,
                        config.max_leg_queue_bytes,
                    ),
                )
            })
            .collect();
        Self {
            legs,
            delivery: DatagramDelivery::new(
                config.delivery_mode,
                config.reorder_window_packets,
                config.reorder_window_bytes,
                config.reorder_timeout_ms,
            ),
            peer_to_session: HashMap::new(),
            session_to_peer: HashMap::new(),
            session_next_seq: HashMap::new(),
            session_last_seen_ms: HashMap::new(),
            next_session_id: session_id_seed(now_ms(), std::process::id()),
            next_dispatch_cursor: 1,
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
        self.next_session_id = if session_id >= u64::from(u32::MAX) {
            1
        } else {
            session_id + 1
        };
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
        self.delivery.remove_session(session_id);
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

    fn reserve_next_leg(&mut self, payload_bytes: usize, exclude: Option<u64>) -> Option<u64> {
        let endpoint_id = select_least_backlogged_after(
            self.legs
                .values()
                .filter(|leg| Some(leg.endpoint_id) != exclude)
                .map(|leg| QueueCandidate {
                    endpoint_id: leg.endpoint_id,
                    active: leg.active && leg.weight > 0,
                    weight: leg.weight,
                    queued_packets: leg.queue.queued_packets(),
                    queued_bytes: leg.queue.queued_bytes(),
                    can_accept: leg.queue.can_reserve(payload_bytes),
                }),
            self.next_dispatch_cursor,
        )?;
        let reserved = self
            .legs
            .get_mut(&endpoint_id)
            .and_then(|leg| leg.queue.try_reserve(payload_bytes).then_some(endpoint_id));
        if reserved.is_some() {
            self.next_dispatch_cursor = endpoint_id.saturating_add(1);
        }
        reserved
    }

    fn release_leg_queue(&mut self, endpoint_id: u64, payload_bytes: usize) {
        if let Some(leg) = self.legs.get_mut(&endpoint_id) {
            leg.queue.release(payload_bytes);
        }
    }

    fn dequeue_leg_frame(&mut self, endpoint_id: u64, payload_bytes: usize) {
        self.release_leg_queue(endpoint_id, payload_bytes);
    }

    fn mark_leg_sent(&mut self, endpoint_id: u64) {
        if let Some(leg) = self.legs.get_mut(&endpoint_id) {
            leg.sent_packets += 1;
        }
    }

    fn mark_leg_received(&mut self, endpoint_id: u64) {
        if let Some(leg) = self.legs.get_mut(&endpoint_id) {
            leg.received_packets += 1;
        }
    }

    fn mark_leg_write_failure(&mut self, endpoint_id: u64) {
        if let Some(leg) = self.legs.get_mut(&endpoint_id) {
            leg.write_failures += 1;
        }
    }

    fn has_active_leg(&self) -> bool {
        self.legs.values().any(|leg| leg.active && leg.weight > 0)
    }

    fn deliver_inbound_data(
        &mut self,
        session_id: u64,
        seq: u64,
        payload: Vec<u8>,
    ) -> Vec<(Vec<u8>, SocketAddr, u64)> {
        if !self.session_to_peer.contains_key(&session_id) {
            return Vec::new();
        }
        self.session_last_seen_ms.insert(session_id, now_ms());
        let emitted = self.delivery.ingest(session_id, seq, payload);
        let result: Vec<_> = emitted
            .into_iter()
            .filter_map(|item| {
                self.session_to_peer
                    .get(&item.session_id)
                    .copied()
                    .map(|peer| (item.payload, peer, item.session_id))
            })
            .collect();
        if self.delivery.is_unordered() {
            self.stats.datagrams_direct_delivered += result.len() as u64;
        }
        self.stats.reorder_buffer_bytes = self.delivery.buffered_bytes() as u64;
        result
    }
}

#[derive(Debug)]
struct InboundFrame {
    leg_id: u64,
    connection_epoch: u64,
    wire_bytes: usize,
    frame: DataFrame,
}

#[derive(Debug)]
struct QueuedFrame {
    frame: DataFrame,
    payload_bytes: usize,
}

#[derive(Debug)]
struct ControlFrame {
    connection_epoch: u64,
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

pub fn build_extended_leg_auth_payload(
    endpoint_id: u64,
    weight: u16,
    session_token: &str,
    connection_epoch: u64,
) -> Vec<u8> {
    let capabilities = AuthCapabilities::leg_progress(connection_epoch);
    serde_json::json!({
        "token": session_token,
        "endpoint_id": endpoint_id,
        "weight": weight.max(1),
        "protocol_version": capabilities.protocol_version,
        "capabilities": capabilities.capabilities,
        "connection_epoch": capabilities.connection_epoch,
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

fn session_id_seed(now_ms: u64, process_id: u32) -> u64 {
    let mixed = now_ms
        .wrapping_mul(0x9E37_79B9)
        .wrapping_add(u64::from(process_id).wrapping_mul(0x85EB_CA6B));
    u64::from((mixed as u32).max(1))
}

fn parse_host_port(value: &str) -> Result<SocketAddr> {
    value
        .parse::<SocketAddr>()
        .with_context(|| format!("invalid host:port {value:?}"))
}

async fn open_plain_tcp_leg(
    host: &str,
    port: u16,
    route_key: &str,
    auth_token: &str,
    direct_tcp: bool,
    socks5_proxy: Option<&str>,
) -> Result<TcpStream> {
    let mut stream = if let Some(proxy) = socks5_proxy {
        // Minimal inline SOCKS5 CONNECT (no-auth, RFC 1928).
        // Used for LeiGod multi-line bonding: each leg routes through its own
        // LeiGod line's local SOCKS5 listener (e.g. 127.0.0.1:20455).
        let mut s = TcpStream::connect(proxy)
            .await
            .with_context(|| format!("failed to connect to SOCKS5 proxy {proxy}"))?;
        s.set_nodelay(true)
            .context("failed to disable Nagle on SOCKS5 proxy connection")?;
        // Greeting: VER=5, NMETHODS=1, METHOD=0 (no auth)
        s.write_all(&[0x05, 0x01, 0x00]).await?;
        let mut greet_resp = [0u8; 2];
        s.read_exact(&mut greet_resp).await?;
        anyhow::ensure!(
            greet_resp[0] == 0x05 && greet_resp[1] == 0x00,
            "SOCKS5 proxy {proxy} rejected no-auth: {greet_resp:?}"
        );
        // CONNECT request: VER=5, CMD=1, RSV=0, ATYP=3 (domain name)
        let host_bytes = host.as_bytes();
        let host_len = u8::try_from(host_bytes.len())
            .context("target hostname too long for SOCKS5 ATYP=3")?;
        let mut req = Vec::with_capacity(7 + host_bytes.len());
        req.extend_from_slice(&[0x05, 0x01, 0x00, 0x03, host_len]);
        req.extend_from_slice(host_bytes);
        req.push((port >> 8) as u8);
        req.push((port & 0xff) as u8);
        s.write_all(&req).await?;
        s.flush().await?;
        // Reply header: VER, REP, RSV, ATYP
        let mut rep = [0u8; 4];
        s.read_exact(&mut rep).await?;
        anyhow::ensure!(
            rep[0] == 0x05 && rep[1] == 0x00,
            "SOCKS5 CONNECT to {host}:{port} via {proxy} failed: reply code {}",
            rep[1]
        );
        // Discard bound address/port returned by the proxy
        match rep[3] {
            0x01 => { let mut _b = [0u8; 6]; s.read_exact(&mut _b).await?; }
            0x03 => {
                let mut len = [0u8; 1]; s.read_exact(&mut len).await?;
                let mut _b = vec![0u8; usize::from(len[0]) + 2];
                s.read_exact(&mut _b).await?;
            }
            0x04 => { let mut _b = [0u8; 18]; s.read_exact(&mut _b).await?; }
            t => bail!("SOCKS5 unknown bound address type {t}"),
        }
        s
    } else {
        let s = TcpStream::connect((host, port))
            .await
            .with_context(|| format!("failed to connect to leg {host}:{port}"))?;
        s.set_nodelay(true)
            .context("failed to disable Nagle on TCP leg")?;
        s
    };
    if direct_tcp {
        return Ok(stream);
    }
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

async fn write_frame_prefixed(
    writer: &mut tokio::net::tcp::OwnedWriteHalf,
    frame: &DataFrame,
) -> Result<()> {
    let encoded = encode_frame(frame);
    let len = u32::try_from(encoded.len()).context("frame too large")?;
    writer.write_all(&len.to_be_bytes()).await?;
    writer.write_all(&encoded).await?;
    writer.flush().await?;
    Ok(())
}

async fn read_frame_prefixed<R>(
    reader: &mut R,
    max_datagram_bytes: usize,
) -> Result<(DataFrame, usize)>
where
    R: AsyncRead + Unpin,
{
    let mut len_buf = [0u8; FRAME_PREFIX_BYTES];
    reader.read_exact(&mut len_buf).await?;
    let frame_len = u32::from_be_bytes(len_buf) as usize;
    if frame_len > max_datagram_bytes.saturating_add(crate::frame::FRAME_HEADER_BYTES) {
        bail!("frame exceeds configured datagram limit");
    }
    let mut encoded = vec![0u8; frame_len];
    reader.read_exact(&mut encoded).await?;
    let frame = decode_frame(&encoded)?;
    if frame.payload.len() > max_datagram_bytes {
        bail!("frame exceeds configured datagram limit");
    }
    Ok((frame, FRAME_PREFIX_BYTES + frame_len))
}

async fn send_udp_checked(
    socket: &UdpSocket,
    payload: &[u8],
    peer: SocketAddr,
) -> io::Result<usize> {
    let sent = socket.send_to(payload, peer).await?;
    if sent != payload.len() {
        return Err(io::Error::new(io::ErrorKind::WriteZero, "short UDP send"));
    }
    Ok(sent)
}

fn progress_frame(progress: LegProgress) -> DataFrame {
    DataFrame {
        version: 1,
        msg_type: MsgType::LegProgress,
        flags: 0,
        session_id: 0,
        seq: 0,
        payload: progress.encode().to_vec(),
    }
}

fn enqueue_progress(
    emitter: &mut ProgressEmitter,
    control_txs: &HashMap<u64, mpsc::Sender<ControlFrame>>,
    leg_id: u64,
    now_us: u64,
    interval_bytes: u64,
    interval_frames: u64,
    interval_ms: u64,
) -> bool {
    let Some(progress) = emitter.maybe_emit(now_us, interval_bytes, interval_frames, interval_ms)
    else {
        return true;
    };
    let queued = control_txs.get(&leg_id).is_some_and(|tx| {
        tx.try_send(ControlFrame {
            connection_epoch: emitter.connection_epoch(),
            frame: progress_frame(progress),
        })
        .is_ok()
    });
    if !queued {
        emitter.retry_cumulative();
    }
    queued
}

async fn receive_feedback(
    receiver: &mut Option<mpsc::Receiver<RuntimeChunk>>,
) -> Option<RuntimeChunk> {
    match receiver {
        Some(receiver) => receiver.recv().await,
        None => std::future::pending().await,
    }
}

async fn write_feedback_chunk(
    writer: &mut tokio::net::tcp::OwnedWriteHalf,
    chunk: RuntimeChunk,
    scheduler: &SchedulerHandle,
    state: &Arc<Mutex<ClientState>>,
) -> Result<()> {
    if chunk.frames.len() != chunk.reservation_ids.len() {
        scheduler
            .send(SchedulerCommand::WriterFailed {
                leg_id: chunk.leg_id,
                connection_epoch: chunk.connection_epoch,
                reservation_ids: chunk.reservation_ids,
            })
            .await
            .ok();
        bail!("scheduler chunk frame/reservation count mismatch");
    }
    scheduler
        .send(SchedulerCommand::WriterStarted {
            leg_id: chunk.leg_id,
            connection_epoch: chunk.connection_epoch,
            reservation_ids: chunk.reservation_ids.clone(),
        })
        .await
        .context("scheduler stopped before writer start")?;
    for (index, frame) in chunk.frames.iter().enumerate() {
        if let Err(error) = write_frame_prefixed(writer, frame).await {
            scheduler
                .send(SchedulerCommand::WriterFailed {
                    leg_id: chunk.leg_id,
                    connection_epoch: chunk.connection_epoch,
                    reservation_ids: chunk.reservation_ids[index..].to_vec(),
                })
                .await
                .ok();
            let mut guard = state.lock().await;
            guard.mark_leg_write_failure(chunk.leg_id);
            return Err(error);
        }
        scheduler
            .send(SchedulerCommand::WriterAccepted {
                leg_id: chunk.leg_id,
                connection_epoch: chunk.connection_epoch,
                reservation_ids: vec![chunk.reservation_ids[index]],
            })
            .await
            .context("scheduler stopped after writer acceptance")?;
        let mut guard = state.lock().await;
        guard.mark_leg_sent(chunk.leg_id);
    }
    Ok(())
}

async fn leg_loop(
    leg_cfg: ClientLegConfig,
    state: Arc<Mutex<ClientState>>,
    config: ClientConfig,
    mut outbound_rx: mpsc::Receiver<QueuedFrame>,
    mut feedback_rx: Option<mpsc::Receiver<RuntimeChunk>>,
    feedback_tx: Option<mpsc::Sender<RuntimeChunk>>,
    mut control_rx: mpsc::Receiver<ControlFrame>,
    inbound_tx: mpsc::Sender<InboundFrame>,
    scheduler: Option<SchedulerHandle>,
    runtime_process_epoch: u64,
    reconnect_counter: Arc<AtomicU64>,
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

        let stream = match open_plain_tcp_leg(
            &leg_cfg.host,
            leg_cfg.port,
            &config.route_key,
            &leg_cfg.auth_token,
            leg_cfg.direct_tcp,
            leg_cfg.socks5_proxy.as_deref(),
        )
        .await
        {
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
        let active_connection_epoch = scheduler.as_ref().map_or(0, |_| {
            connection_epoch(
                runtime_process_epoch,
                reconnect_counter.fetch_add(1, Ordering::Relaxed) + 1,
            )
        });
        let current_weight = {
            let guard = state.lock().await;
            guard
                .legs
                .get(&leg_cfg.endpoint_id)
                .map(|leg| leg.weight)
                .unwrap_or(1)
        };
        let auth = DataFrame {
            version: 1,
            msg_type: MsgType::Auth,
            flags: 0,
            session_id: 0,
            seq: 0,
            payload: if scheduler.is_some() {
                build_extended_leg_auth_payload(
                    leg_cfg.endpoint_id,
                    current_weight,
                    &config.session_token,
                    active_connection_epoch,
                )
            } else {
                build_leg_auth_payload(leg_cfg.endpoint_id, current_weight, &config.session_token)
            },
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
                leg.reconnects += 1;
            }
            guard.refresh_live_legs();
        }
        if let (Some(scheduler), Some(data_tx)) = (&scheduler, &feedback_tx) {
            scheduler
                .send(SchedulerCommand::LegConnected {
                    registration: LegRegistration {
                        leg_id: leg_cfg.endpoint_id,
                        scope_key: SchedulerScopeKey::Client {
                            forward_id: config.forward_id,
                        },
                        connection_epoch: active_connection_epoch,
                        progress_direction: Direction::ClientToSidecar,
                        weight: current_weight,
                        now_us: 0,
                    },
                    data_tx: data_tx.clone(),
                })
                .await
                .context("scheduler stopped while registering client leg")?;
        }
        backoff_ms = 250;

        let (frame_tx, mut frame_rx) = mpsc::channel::<Result<InboundFrame>>(128);
        let endpoint_id = leg_cfg.endpoint_id;
        let max_datagram_bytes = config.max_datagram_bytes;
        tokio::spawn(async move {
            loop {
                let frame = read_frame_prefixed(&mut reader, max_datagram_bytes).await;
                let inbound = frame.map(|(frame, wire_bytes)| InboundFrame {
                    leg_id: endpoint_id,
                    connection_epoch: active_connection_epoch,
                    wire_bytes,
                    frame,
                });
                if frame_tx.send(inbound).await.is_err() {
                    break;
                }
            }
        });

        let mut keepalive = tokio::time::interval(Duration::from_millis(KEEPALIVE_INTERVAL_MS));
        keepalive.set_missed_tick_behavior(MissedTickBehavior::Delay);
        let result: Result<()> = loop {
            tokio::select! {
                biased;
                maybe_control = control_rx.recv() => {
                    let Some(control) = maybe_control else {
                        break Ok(());
                    };
                    if control.connection_epoch == active_connection_epoch {
                        if let Err(err) = write_frame_prefixed(&mut writer, &control.frame).await {
                            break Err(err);
                        }
                    }
                }
                maybe_inbound = frame_rx.recv() => {
                    match maybe_inbound {
                        Some(Ok(inbound)) => {
                            inbound_tx.send(inbound).await?;
                        }
                        Some(Err(err)) => break Err(err),
                        None => break Ok(()),
                    }
                }
                maybe_feedback = receive_feedback(&mut feedback_rx), if feedback_rx.is_some() => {
                    let Some(chunk) = maybe_feedback else {
                        break Ok(());
                    };
                    if chunk.connection_epoch == active_connection_epoch {
                        if let Some(scheduler) = scheduler.as_ref() {
                            if let Err(err) = write_feedback_chunk(
                                &mut writer,
                                chunk,
                                scheduler,
                                &state,
                            ).await {
                                break Err(err);
                            }
                        }
                    }
                }
                maybe_outbound = outbound_rx.recv() => {
                    let Some(queued) = maybe_outbound else {
                        break Ok(());
                    };
                    {
                        let mut guard = state.lock().await;
                        guard.dequeue_leg_frame(leg_cfg.endpoint_id, queued.payload_bytes);
                    }
                    if let Err(err) = write_frame_prefixed(&mut writer, &queued.frame).await {
                        let mut guard = state.lock().await;
                        guard.mark_leg_write_failure(leg_cfg.endpoint_id);
                        break Err(err);
                    }
                    let mut guard = state.lock().await;
                    guard.mark_leg_sent(leg_cfg.endpoint_id);
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

        if let Some(scheduler) = &scheduler {
            scheduler
                .send(SchedulerCommand::LegDisconnected {
                    leg_id: leg_cfg.endpoint_id,
                    connection_epoch: active_connection_epoch,
                })
                .await
                .ok();
        }

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
    config.validate()?;
    let listen_addr = parse_host_port(&config.listen_udp)?;
    let socket = Arc::new(bind_udp_socket(
        listen_addr,
        config.udp_socket_buffer_bytes,
    )?);
    let state = Arc::new(Mutex::new(ClientState::new(&config)));
    let (inbound_tx, mut inbound_rx) = mpsc::channel::<InboundFrame>(4096);
    let feedback_enabled = matches!(config.scheduler.scheduler_mode, SchedulerMode::FeedbackDrr);
    let runtime_process_epoch = process_epoch();
    let scheduler =
        feedback_enabled.then(|| spawn_scheduler(config.scheduler.clone(), runtime_process_epoch));
    let reconnect_counter = Arc::new(AtomicU64::new(0));

    let mut outbound_txs = HashMap::<u64, mpsc::Sender<QueuedFrame>>::new();
    let mut control_txs = HashMap::<u64, mpsc::Sender<ControlFrame>>::new();
    for leg in &config.legs {
        let (tx, rx) = mpsc::channel(config.max_leg_queue_packets);
        outbound_txs.insert(leg.endpoint_id, tx);
        let (control_tx, control_rx) = mpsc::channel(config.scheduler.control_queue_packets);
        control_txs.insert(leg.endpoint_id, control_tx);
        let (feedback_tx, feedback_rx) = if feedback_enabled {
            let (tx, rx) = mpsc::channel(config.max_leg_queue_packets);
            (Some(tx), Some(rx))
        } else {
            (None, None)
        };
        let leg_cfg = ClientLegConfig {
            endpoint_id: leg.endpoint_id,
            host: leg.host.clone(),
            port: leg.port,
            auth_token: leg.auth_token.clone(),
            direct_tcp: leg.direct_tcp,
            socks5_proxy: leg.socks5_proxy.clone(),
        };
        tokio::spawn(leg_loop(
            leg_cfg,
            state.clone(),
            config.clone(),
            rx,
            feedback_rx,
            feedback_tx,
            control_rx,
            inbound_tx.clone(),
            scheduler.clone(),
            runtime_process_epoch,
            reconnect_counter.clone(),
        ));
    }

    let udp_state = state.clone();
    let udp_socket = socket.clone();
    let udp_outbound = outbound_txs.clone();
    let udp_idle_timeout = config.session_idle_timeout_ms;
    let max_datagram_bytes = config.max_datagram_bytes;
    let wireguard_aware = config.wireguard_aware;
    let udp_scheduler = scheduler.clone();
    let scheduler_scope = SchedulerScopeKey::Client {
        forward_id: config.forward_id,
    };
    tokio::spawn(async move {
        let mut buf = vec![0u8; 65_535];
        loop {
            let Ok((len, peer)) = udp_socket.recv_from(&mut buf).await else {
                continue;
            };
            if len > max_datagram_bytes {
                let mut guard = udp_state.lock().await;
                guard.stats.datagrams_dropped_invalid += 1;
                continue;
            }
            let payload = buf[..len].to_vec();
            let (session_id, seq) = {
                let mut guard = udp_state.lock().await;
                guard.stats.datagrams_in += 1;
                let session_id = guard.alloc_session(peer);
                let seq = guard
                    .session_next_seq
                    .get(&session_id)
                    .copied()
                    .unwrap_or(1);
                guard.session_next_seq.insert(session_id, seq + 1);
                (session_id, seq)
            };
            if let Some(scheduler) = &udp_scheduler {
                let class = if is_duplicate_eligible(&payload) {
                    TrafficClass::WireGuardHandshake
                } else {
                    TrafficClass::Regular
                };
                if scheduler
                    .try_admit(
                        SchedulerSessionKey {
                            scope_key: scheduler_scope.clone(),
                            session_id,
                            direction: Direction::ClientToSidecar,
                            process_epoch: runtime_process_epoch,
                        },
                        seq,
                        Bytes::from(payload),
                        class,
                    )
                    .is_err()
                {
                    let mut guard = udp_state.lock().await;
                    guard.stats.datagrams_dropped_queue_full += 1;
                }
            } else {
                let frame = DataFrame {
                    version: 1,
                    msg_type: MsgType::Data,
                    flags: 0,
                    session_id: session_id as u32,
                    seq,
                    payload,
                };
                let payload_bytes = frame.payload.len();
                let selected_legs = {
                    let mut guard = udp_state.lock().await;
                    match guard.reserve_next_leg(payload_bytes, None) {
                        Some(primary_leg) => {
                            let mut selected_legs = vec![primary_leg];
                            if wireguard_aware && is_duplicate_eligible(&frame.payload) {
                                if let Some(second_leg) =
                                    guard.reserve_next_leg(payload_bytes, Some(primary_leg))
                                {
                                    selected_legs.push(second_leg);
                                }
                            }
                            selected_legs
                        }
                        None => {
                            if guard.has_active_leg() {
                                guard.stats.datagrams_dropped_queue_full += 1;
                            } else {
                                guard.stats.datagrams_dropped_no_leg += 1;
                            }
                            Vec::new()
                        }
                    }
                };
                for (index, endpoint_id) in selected_legs.into_iter().enumerate() {
                    let sent = udp_outbound
                        .get(&endpoint_id)
                        .map(|tx| {
                            tx.try_send(QueuedFrame {
                                frame: frame.clone(),
                                payload_bytes,
                            })
                        })
                        .is_some_and(|result| result.is_ok());
                    if sent {
                        if index > 0 {
                            let mut guard = udp_state.lock().await;
                            guard.stats.handshake_datagrams_duplicated += 1;
                        }
                    } else {
                        let mut guard = udp_state.lock().await;
                        guard.release_leg_queue(endpoint_id, payload_bytes);
                        guard.stats.datagrams_dropped_queue_full += 1;
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
    let dispatch_scheduler = scheduler.clone();
    let dispatch_control_txs = control_txs.clone();
    let progress_interval_bytes = config.scheduler.progress_interval_bytes;
    let progress_interval_frames = config.scheduler.progress_interval_frames;
    let progress_interval_ms = config.scheduler.progress_interval_ms;
    tokio::spawn(async move {
        let progress_started = tokio::time::Instant::now();
        let mut progress_emitters = HashMap::<u64, ProgressEmitter>::new();
        let tick_ms = (reorder_timeout.max(1) / 2)
            .max(1)
            .min(progress_interval_ms.max(1));
        let mut tick = tokio::time::interval(Duration::from_millis(tick_ms));
        tick.set_missed_tick_behavior(MissedTickBehavior::Delay);
        loop {
            tokio::select! {
                maybe_frame = inbound_rx.recv() => {
                    let Some(inbound) = maybe_frame else { break; };
                    let InboundFrame {
                        leg_id,
                        connection_epoch,
                        wire_bytes,
                        frame,
                    } = inbound;
                    {
                        let mut guard = dispatch_state.lock().await;
                        guard.mark_leg_received(leg_id);
                    }
                    if matches!(frame.msg_type, MsgType::AuthAck) {
                        if let Some(scheduler) = &dispatch_scheduler {
                            match serde_json::from_slice::<AuthAck>(&frame.payload) {
                                Ok(ack) => {
                                    if ack.matches(connection_epoch) {
                                        progress_emitters.insert(
                                            leg_id,
                                            ProgressEmitter::new(
                                                Direction::SidecarToClient,
                                                connection_epoch,
                                                progress_started.elapsed().as_micros() as u64,
                                            ),
                                        );
                                    }
                                    scheduler
                                        .send(SchedulerCommand::AuthAcknowledged { leg_id, ack })
                                        .await
                                        .ok();
                                }
                                Err(_) => {
                                    scheduler.send(SchedulerCommand::InvalidControl).await.ok();
                                }
                            }
                        }
                        continue;
                    }
                    if matches!(frame.msg_type, MsgType::LegProgress) {
                        if let Some(scheduler) = &dispatch_scheduler {
                            match LegProgress::decode(&frame.payload) {
                                Ok(progress) => {
                                    scheduler
                                        .send(SchedulerCommand::Progress { leg_id, progress })
                                        .await
                                        .ok();
                                }
                                Err(_) => {
                                    scheduler.send(SchedulerCommand::InvalidControl).await.ok();
                                }
                            }
                        }
                        continue;
                    }
                    if matches!(frame.msg_type, MsgType::Data) {
                        if let Some(emitter) = progress_emitters.get_mut(&leg_id) {
                            if emitter.connection_epoch() == connection_epoch {
                                emitter.record_decoded(wire_bytes);
                            }
                        }
                    }
                    let progress_identity = progress_emitters
                        .get(&leg_id)
                        .filter(|emitter| emitter.connection_epoch() == connection_epoch)
                        .map(|_| (leg_id, connection_epoch, wire_bytes));
                    let mut emits = Vec::<(
                        Vec<u8>,
                        SocketAddr,
                        u64,
                        Option<(u64, u64, usize)>,
                    )>::new();
                    {
                        let mut guard = dispatch_state.lock().await;
                        match frame.msg_type {
                            MsgType::Data => {
                                let session_id = frame.session_id as u64;
                                if guard.session_to_peer.contains_key(&session_id) {
                                    emits.extend(
                                        guard
                                            .deliver_inbound_data(session_id, frame.seq, frame.payload)
                                            .into_iter()
                                            .map(|(payload, peer, session_id)| {
                                                (payload, peer, session_id, progress_identity)
                                            }),
                                    );
                                } else {
                                    guard.stats.datagrams_dropped_unknown_session += 1;
                                }
                            }
                            MsgType::Close => {
                                guard.close_session(frame.session_id as u64);
                            }
                            MsgType::Keepalive
                            | MsgType::Auth
                            | MsgType::Open
                            | MsgType::FecParity
                            | MsgType::AuthAck
                            | MsgType::LegProgress => {}
                        }
                        guard.stats.reorder_buffer_bytes = guard.delivery.buffered_bytes() as u64;
                    }
                    for (payload, peer, session_id, progress_identity) in emits {
                        let sent = send_udp_checked(&dispatch_socket, &payload, peer).await;
                        let mut guard = dispatch_state.lock().await;
                        if sent.is_ok() {
                            guard.session_last_seen_ms.insert(session_id, now_ms());
                            guard.stats.datagrams_out += 1;
                        } else {
                            guard.stats.udp_egress_errors += 1;
                        }
                        drop(guard);
                        if let Err(error) = &sent {
                            if let Some(scheduler) = &dispatch_scheduler {
                                scheduler
                                    .send(SchedulerCommand::UdpEgressError {
                                        kind: UdpErrorKind::from_io_error(error),
                                        payload_bytes: payload.len(),
                                        wire_bytes: progress_identity
                                            .map(|(_, _, bytes)| bytes)
                                            .unwrap_or(0),
                                    })
                                    .await
                                    .ok();
                            }
                        }
                        if sent.is_ok() {
                            if let Some((progress_leg, epoch, accepted_wire_bytes)) = progress_identity {
                                if let Some(emitter) = progress_emitters.get_mut(&progress_leg) {
                                    if emitter.connection_epoch() == epoch {
                                        emitter.record_udp_accepted(accepted_wire_bytes);
                                    }
                                }
                            }
                        }
                    }
                    let now_us = progress_started.elapsed().as_micros() as u64;
                    if let Some(emitter) = progress_emitters.get_mut(&leg_id) {
                        if !enqueue_progress(
                            emitter,
                            &dispatch_control_txs,
                            leg_id,
                            now_us,
                            progress_interval_bytes,
                            progress_interval_frames,
                            progress_interval_ms,
                        ) {
                            let mut guard = dispatch_state.lock().await;
                            guard.stats.control_queue_drops += 1;
                        }
                    }
                }
                _ = tick.tick() => {
                    let mut emits = Vec::<(Vec<u8>, SocketAddr, u64)>::new();
                    {
                        let mut guard = dispatch_state.lock().await;
                        let released = guard.delivery.flush_timeouts(now_ms());
                        if guard.delivery.skipped_gap_packets() > 0 {
                            guard.stats.datagrams_dropped_gap += guard.delivery.skipped_gap_packets();
                        }
                        for MergeItem { session_id, payload, .. } in released {
                            if let Some(peer) = guard.session_to_peer.get(&session_id).copied() {
                                emits.push((payload, peer, session_id));
                            }
                        }
                        let _ = guard.cleanup_idle_sessions(idle_timeout);
                        guard.stats.reorder_buffer_bytes = guard.delivery.buffered_bytes() as u64;
                    }
                    for (payload, peer, session_id) in emits {
                        let sent = send_udp_checked(&dispatch_socket, &payload, peer).await;
                        let mut guard = dispatch_state.lock().await;
                        if sent.is_ok() {
                            guard.session_last_seen_ms.insert(session_id, now_ms());
                            guard.stats.datagrams_out += 1;
                        } else {
                            guard.stats.udp_egress_errors += 1;
                        }
                        drop(guard);
                        if let Err(error) = &sent {
                            if let Some(scheduler) = &dispatch_scheduler {
                                scheduler
                                    .send(SchedulerCommand::UdpEgressError {
                                        kind: UdpErrorKind::from_io_error(error),
                                        payload_bytes: payload.len(),
                                        wire_bytes: 0,
                                    })
                                    .await
                                    .ok();
                            }
                        }
                    }
                    let now_us = progress_started.elapsed().as_micros() as u64;
                    for (leg_id, emitter) in &mut progress_emitters {
                        if !enqueue_progress(
                            emitter,
                            &dispatch_control_txs,
                            *leg_id,
                            now_us,
                            progress_interval_bytes,
                            progress_interval_frames,
                            progress_interval_ms,
                        ) {
                            let mut guard = dispatch_state.lock().await;
                            guard.stats.control_queue_drops += 1;
                        }
                    }
                }
            }
        }
    });

    let stats_state = state.clone();
    let stats_path = config.stats_path.clone();
    let delivery_mode = config.delivery_mode.as_str();
    let scheduler_mode = config.scheduler.scheduler_mode.as_str();
    let stats_scheduler = scheduler.clone();
    tokio::spawn(async move {
        loop {
            let scheduler_snapshot = match &stats_scheduler {
                Some(scheduler) => scheduler.snapshot().await,
                None => None,
            };
            let payload = {
                let guard = stats_state.lock().await;
                serde_json::json!({
                    "delivery_mode": delivery_mode,
                    "scheduler_mode": scheduler_mode,
                    "live_legs": guard.stats.live_legs,
                    "sessions_active": guard.stats.sessions_active,
                    "datagrams_in": guard.stats.datagrams_in,
                    "datagrams_out": guard.stats.datagrams_out,
                    "datagrams_dropped_gap": guard.stats.datagrams_dropped_gap,
                    "datagrams_dropped_no_leg": guard.stats.datagrams_dropped_no_leg,
                    "datagrams_dropped_queue_full": guard.stats.datagrams_dropped_queue_full,
                    "datagrams_dropped_invalid": guard.stats.datagrams_dropped_invalid,
                    "datagrams_dropped_unknown_session": guard.stats.datagrams_dropped_unknown_session,
                    "datagrams_direct_delivered": guard.stats.datagrams_direct_delivered,
                    "handshake_datagrams_duplicated": guard.stats.handshake_datagrams_duplicated,
                    "reorder_buffer_bytes": guard.stats.reorder_buffer_bytes,
                    "udp_egress_errors": guard.stats.udp_egress_errors,
                    "control_queue_drops": guard.stats.control_queue_drops,
                    "scheduler": scheduler_snapshot,
                    "legs": guard.legs.values().map(|leg| serde_json::json!({
                        "endpoint_id": leg.endpoint_id,
                        "active": leg.active,
                        "queued_packets": leg.queue.queued_packets(),
                        "queued_bytes": leg.queue.queued_bytes(),
                        "sent_packets": leg.sent_packets,
                        "received_packets": leg.received_packets,
                        "write_failures": leg.write_failures,
                        "reconnects": leg.reconnects,
                    })).collect::<Vec<_>>(),
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
    use crate::config::{ClientLegConfig as ConfigLeg, DeliveryMode};

    #[test]
    fn build_leg_auth_payload_encodes_token_endpoint_and_weight() {
        let payload = build_leg_auth_payload(184, 8, "udp-token");
        let decoded: serde_json::Value = serde_json::from_slice(&payload).expect("json");
        assert_eq!(decoded["token"], "udp-token");
        assert_eq!(decoded["endpoint_id"], 184);
        assert_eq!(decoded["weight"], 8);
    }

    #[test]
    fn extended_auth_preserves_legacy_fields_and_requests_progress() {
        let payload = build_extended_leg_auth_payload(184, 8, "udp-token", 77);
        let decoded: serde_json::Value = serde_json::from_slice(&payload).expect("json");
        assert_eq!(decoded["token"], "udp-token");
        assert_eq!(decoded["endpoint_id"], 184);
        assert_eq!(decoded["weight"], 8);
        assert_eq!(decoded["protocol_version"], 2);
        assert_eq!(
            decoded["capabilities"],
            serde_json::json!(["leg-progress-v1"])
        );
        assert_eq!(decoded["connection_epoch"], 77);
    }

    #[tokio::test]
    async fn framed_reader_reports_exact_stream_wire_bytes() {
        let frame = DataFrame {
            version: 1,
            msg_type: MsgType::Data,
            flags: 0,
            session_id: 1,
            seq: 2,
            payload: b"abc".to_vec(),
        };
        let encoded = encode_frame(&frame);
        let expected_wire_bytes = FRAME_PREFIX_BYTES + encoded.len();
        let (mut writer, mut reader) = tokio::io::duplex(256);
        tokio::spawn(async move {
            writer
                .write_all(&(encoded.len() as u32).to_be_bytes())
                .await
                .unwrap();
            writer.write_all(&encoded).await.unwrap();
        });

        let (decoded, wire_bytes) = read_frame_prefixed(&mut reader, 65_535).await.unwrap();
        assert_eq!(decoded, frame);
        assert_eq!(wire_bytes, expected_wire_bytes);
    }

    #[tokio::test]
    async fn checked_udp_send_counts_only_a_full_datagram() {
        let receiver = UdpSocket::bind("127.0.0.1:0").await.unwrap();
        let sender = UdpSocket::bind("127.0.0.1:0").await.unwrap();
        let payload = b"checked";
        assert_eq!(
            send_udp_checked(&sender, payload, receiver.local_addr().unwrap())
                .await
                .unwrap(),
            payload.len()
        );
        let mut buffer = [0_u8; 32];
        let (received, _) = receiver.recv_from(&mut buffer).await.unwrap();
        assert_eq!(&buffer[..received], payload);
    }

    #[test]
    fn session_id_seed_is_nonzero_and_changes_across_process_epochs() {
        assert_eq!(session_id_seed(0, 0), 1);
        assert_ne!(session_id_seed(1_000, 17), session_id_seed(1_001, 17));
        assert_ne!(session_id_seed(1_000, 17), session_id_seed(1_000, 18));
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

    #[test]
    fn client_unordered_receive_does_not_wait_for_gap() {
        let config = ClientConfig {
            forward_id: 3,
            listen_udp: "127.0.0.1:33113".to_string(),
            route_key: "test".to_string(),
            session_token: "test".to_string(),
            reorder_window_packets: 16,
            reorder_window_bytes: 4096,
            reorder_timeout_ms: 25,
            delivery_mode: DeliveryMode::Unordered,
            wireguard_aware: true,
            max_leg_queue_packets: 2,
            max_leg_queue_bytes: 1024,
            max_datagram_bytes: 1024,
            udp_socket_buffer_bytes: 1024,
            legs: Vec::new(),
            stats_path: "unused".to_string(),
            session_idle_timeout_ms: 120_000,
            scheduler: crate::config::SchedulerConfig::default(),
        };
        let mut state = ClientState::new(&config);
        let peer = "127.0.0.1:45000".parse().expect("peer");
        let session_id = state.alloc_session(peer);

        let emitted = state.deliver_inbound_data(session_id, 2, b"late".to_vec());

        assert_eq!(emitted, vec![(b"late".to_vec(), peer, session_id)]);
        assert_eq!(state.stats.reorder_buffer_bytes, 0);
    }

    #[test]
    fn client_skips_full_leg_for_a_second_healthy_leg() {
        let config = ClientConfig {
            forward_id: 3,
            listen_udp: "127.0.0.1:33113".to_string(),
            route_key: "test".to_string(),
            session_token: "test".to_string(),
            reorder_window_packets: 16,
            reorder_window_bytes: 4096,
            reorder_timeout_ms: 25,
            delivery_mode: DeliveryMode::Unordered,
            wireguard_aware: true,
            max_leg_queue_packets: 1,
            max_leg_queue_bytes: 100,
            max_datagram_bytes: 1024,
            udp_socket_buffer_bytes: 1024,
            legs: vec![
                ConfigLeg {
                    endpoint_id: 1,
                    host: "127.0.0.1".to_string(),
                    port: 26140,
                    auth_token: "test".to_string(),
                    dynamic_priority: 0,
                    health_role: "healthy".to_string(),
                    weight: 1,
                    direct_tcp: true,
                    socks5_proxy: None,
                },
                ConfigLeg {
                    endpoint_id: 2,
                    host: "127.0.0.1".to_string(),
                    port: 26141,
                    auth_token: "test".to_string(),
                    dynamic_priority: 0,
                    health_role: "healthy".to_string(),
                    weight: 1,
                    direct_tcp: true,
                    socks5_proxy: None,
                },
            ],
            stats_path: "unused".to_string(),
            session_idle_timeout_ms: 120_000,
            scheduler: crate::config::SchedulerConfig::default(),
        };
        let mut state = ClientState::new(&config);
        state.legs.get_mut(&1).expect("first leg").active = true;
        state.legs.get_mut(&2).expect("second leg").active = true;
        assert!(state
            .legs
            .get_mut(&1)
            .expect("first leg")
            .queue
            .try_reserve(100));

        assert_eq!(state.reserve_next_leg(1, None), Some(2));
    }

    #[tokio::test]
    async fn direct_tcp_leg_skips_ingress_preamble() {
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0")
            .await
            .expect("listener");
        let port = listener.local_addr().expect("listener address").port();
        let (accepted_tx, accepted_rx) = tokio::sync::oneshot::channel();
        let (checked_tx, checked_rx) = tokio::sync::oneshot::channel();
        let (release_tx, release_rx) = tokio::sync::oneshot::channel();
        let server = tokio::spawn(async move {
            let (mut socket, _) = listener.accept().await.expect("accept");
            accepted_tx.send(()).expect("accepted signal");
            let mut byte = [0u8; 1];
            assert!(
                tokio::time::timeout(Duration::from_millis(50), socket.read(&mut byte))
                    .await
                    .is_err()
            );
            checked_tx.send(()).expect("checked signal");
            release_rx.await.expect("release signal");
        });

        let stream = open_plain_tcp_leg("127.0.0.1", port, "route", "auth", true, None)
            .await
            .expect("direct connection");
        accepted_rx.await.expect("server accepted connection");
        checked_rx.await.expect("server checked connection");
        release_tx.send(()).expect("release server");
        drop(stream);
        server.await.expect("server task");
    }

    #[tokio::test]
    async fn tcp_leg_disables_nagle_after_connect() {
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0")
            .await
            .expect("listener");
        let port = listener.local_addr().expect("listener address").port();
        let (accepted_tx, accepted_rx) = tokio::sync::oneshot::channel();
        let server = tokio::spawn(async move {
            let (_socket, _) = listener.accept().await.expect("accept");
            accepted_tx.send(()).expect("accepted signal");
        });

        let stream = open_plain_tcp_leg("127.0.0.1", port, "route", "auth", true, None)
            .await
            .expect("direct connection");

        accepted_rx.await.expect("server accepted connection");
        assert!(stream.nodelay().expect("read TCP_NODELAY"));
        drop(stream);
        server.await.expect("server task");
    }

    #[tokio::test]
    async fn framed_reader_rejects_oversized_payload_before_allocation() {
        let (mut writer, mut reader) = tokio::io::duplex(64);
        writer
            .write_all(&(1025u32).to_be_bytes())
            .await
            .expect("length prefix");

        let error = read_frame_prefixed(&mut reader, 1000)
            .await
            .expect_err("oversized frame must be rejected");

        assert!(error
            .to_string()
            .contains("frame exceeds configured datagram limit"));
    }
}
