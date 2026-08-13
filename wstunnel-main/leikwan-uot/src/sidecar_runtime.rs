use std::collections::{BTreeMap, HashMap};
use std::io;
use std::net::SocketAddr;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Arc;
use std::time::Duration;

use anyhow::{anyhow, bail, Context, Result};
use base64::Engine;
use bytes::Bytes;
use hmac::{Hmac, Mac};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use tokio::io::{AsyncRead, AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream, UdpSocket};
use tokio::sync::{mpsc, Mutex};
use tokio::time::MissedTickBehavior;

use crate::config::{SchedulerMode, SidecarConfig};
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

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct ParsedLegAuthPayload {
    pub token: String,
    pub endpoint_id: u64,
    pub weight: u16,
    #[serde(default)]
    pub protocol_version: Option<u8>,
    #[serde(default)]
    pub capabilities: Vec<String>,
    #[serde(default)]
    pub connection_epoch: Option<u64>,
}

impl ParsedLegAuthPayload {
    fn requested_progress(&self) -> Option<AuthCapabilities> {
        let capabilities = AuthCapabilities {
            protocol_version: self.protocol_version?,
            capabilities: self.capabilities.clone(),
            connection_epoch: self.connection_epoch?,
        };
        capabilities.requests_leg_progress().then_some(capabilities)
    }
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
    queue: LegQueueBudget,
    sent_packets: u64,
    received_packets: u64,
    write_failures: u64,
    reconnects: u64,
}

impl ActiveLeg {
    fn new(
        leg_id: u64,
        endpoint_id: u64,
        scope_key: ScopeKey,
        claims: ClaimSet,
        weight: u16,
        max_queue_packets: usize,
        max_queue_bytes: usize,
    ) -> Self {
        Self {
            leg_id,
            endpoint_id,
            scope_key,
            claims,
            weight: weight.max(1),
            queue: LegQueueBudget::new(max_queue_packets, max_queue_bytes),
            sent_packets: 0,
            received_packets: 0,
            write_failures: 0,
            reconnects: 1,
        }
    }

    #[cfg(test)]
    fn for_test(
        leg_id: u64,
        endpoint_id: u64,
        scope_key: ScopeKey,
        claims: ClaimSet,
        weight: u16,
        max_queue_packets: usize,
        max_queue_bytes: usize,
    ) -> Self {
        Self::new(
            leg_id,
            endpoint_id,
            scope_key,
            claims,
            weight,
            max_queue_packets,
            max_queue_bytes,
        )
    }
}

#[derive(Debug, Clone, PartialEq, Eq, PartialOrd, Ord, Hash)]
struct ScopeKey {
    forward_id: u64,
    target_host: String,
    target_port: u16,
    token_fingerprint: String,
}

type SessionKey = (ScopeKey, u64);

#[derive(Debug)]
struct SessionInfo {
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
    datagrams_dropped_queue_full: u64,
    datagrams_dropped_invalid: u64,
    datagrams_dropped_unknown_session: u64,
    datagrams_direct_delivered: u64,
    handshake_datagrams_duplicated: u64,
    bytes_in: u64,
    bytes_out: u64,
    reorder_buffer_bytes: u64,
    udp_egress_errors: u64,
    control_queue_drops: u64,
}

#[derive(Debug)]
struct SidecarState {
    delivery: DatagramDelivery,
    active_legs: HashMap<u64, ActiveLeg>,
    sessions: BTreeMap<(ScopeKey, u64), SessionInfo>,
    delivery_session_ids: BTreeMap<SessionKey, u64>,
    delivery_session_keys: BTreeMap<u64, SessionKey>,
    endpoint_penalties: EndpointPenalties,
    next_leg_id: u64,
    next_dispatch_cursor: u64,
    next_delivery_session_id: u64,
    stats: Stats,
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

#[derive(Debug)]
struct InboundFrame {
    leg_id: u64,
    connection_epoch: u64,
    feedback_negotiated: bool,
    wire_bytes: usize,
    frame: DataFrame,
}

impl SidecarState {
    fn new(config: &SidecarConfig) -> Self {
        Self {
            delivery: DatagramDelivery::new(
                config.delivery_mode,
                config.reorder_window_packets,
                config.reorder_window_bytes,
                config.reorder_timeout_ms,
            ),
            active_legs: HashMap::new(),
            sessions: BTreeMap::new(),
            delivery_session_ids: BTreeMap::new(),
            delivery_session_keys: BTreeMap::new(),
            endpoint_penalties: EndpointPenalties::default(),
            next_leg_id: 1,
            next_dispatch_cursor: 1,
            next_delivery_session_id: 1,
            stats: Stats::default(),
        }
    }

    fn register_leg(&mut self, leg: ActiveLeg) {
        let leg_id = leg.leg_id;
        self.active_legs.insert(leg_id, leg);
        self.stats.live_legs = self.active_legs.len() as u64;
    }

    fn remove_leg(&mut self, leg_id: u64) {
        self.active_legs.remove(&leg_id);
        self.stats.live_legs = self.active_legs.len() as u64;
    }

    fn reserve_next_leg(
        &mut self,
        scope_key: &ScopeKey,
        payload_bytes: usize,
        exclude: Option<u64>,
    ) -> Option<u64> {
        let leg_id = select_least_backlogged_after(
            self.active_legs
                .values()
                .filter(|leg| &leg.scope_key == scope_key && Some(leg.leg_id) != exclude)
                .map(|leg| QueueCandidate {
                    endpoint_id: leg.leg_id,
                    active: true,
                    weight: leg.weight,
                    queued_packets: leg.queue.queued_packets(),
                    queued_bytes: leg.queue.queued_bytes(),
                    can_accept: leg.queue.can_reserve(payload_bytes),
                }),
            self.next_dispatch_cursor,
        )?;
        let reserved = self
            .active_legs
            .get_mut(&leg_id)
            .and_then(|leg| leg.queue.try_reserve(payload_bytes).then_some(leg_id));
        if reserved.is_some() {
            self.next_dispatch_cursor = leg_id.saturating_add(1);
        }
        reserved
    }

    fn release_leg_queue(&mut self, leg_id: u64, payload_bytes: usize) {
        if let Some(leg) = self.active_legs.get_mut(&leg_id) {
            leg.queue.release(payload_bytes);
        }
    }

    fn dequeue_leg_frame(&mut self, leg_id: u64, payload_bytes: usize) {
        self.release_leg_queue(leg_id, payload_bytes);
    }

    fn mark_leg_sent(&mut self, leg_id: u64) {
        if let Some(leg) = self.active_legs.get_mut(&leg_id) {
            leg.sent_packets += 1;
        }
    }

    fn mark_leg_received(&mut self, leg_id: u64) {
        if let Some(leg) = self.active_legs.get_mut(&leg_id) {
            leg.received_packets += 1;
        }
    }

    fn mark_leg_write_failure(&mut self, leg_id: u64) {
        if let Some(leg) = self.active_legs.get_mut(&leg_id) {
            leg.write_failures += 1;
        }
    }

    fn has_active_leg(&self, scope_key: &ScopeKey) -> bool {
        self.active_legs
            .values()
            .any(|leg| &leg.scope_key == scope_key)
    }

    fn delivery_session_id(&mut self, session_key: &SessionKey) -> u64 {
        if let Some(existing) = self.delivery_session_ids.get(session_key).copied() {
            return existing;
        }
        let delivery_session_id = self.next_delivery_session_id;
        self.next_delivery_session_id += 1;
        self.delivery_session_ids
            .insert(session_key.clone(), delivery_session_id);
        self.delivery_session_keys
            .insert(delivery_session_id, session_key.clone());
        delivery_session_id
    }

    fn map_delivery_items(&self, emitted: Vec<MergeItem>) -> Vec<(SessionKey, Vec<u8>)> {
        emitted
            .into_iter()
            .filter_map(|item| {
                self.delivery_session_keys
                    .get(&item.session_id)
                    .cloned()
                    .map(|session_key| (session_key, item.payload))
            })
            .collect()
    }

    fn deliver_inbound_data(
        &mut self,
        session_key: &SessionKey,
        seq: u64,
        payload: Vec<u8>,
    ) -> Vec<(SessionKey, Vec<u8>)> {
        self.deliver_inbound_data_at(session_key, seq, payload, now_ms())
    }

    fn deliver_inbound_data_at(
        &mut self,
        session_key: &SessionKey,
        seq: u64,
        payload: Vec<u8>,
        arrived_at_ms: u64,
    ) -> Vec<(SessionKey, Vec<u8>)> {
        let delivery_session_id = self.delivery_session_id(session_key);
        let emitted = self
            .delivery
            .ingest_at(delivery_session_id, seq, payload, arrived_at_ms);
        self.stats.reorder_buffer_bytes = self.delivery.buffered_bytes() as u64;
        self.map_delivery_items(emitted)
    }

    fn flush_timed_out_delivery(&mut self, now_ms: u64) -> Vec<(SessionKey, Vec<u8>)> {
        let emitted = self.delivery.flush_timeouts(now_ms);
        if self.delivery.skipped_gap_packets() > 0 {
            self.stats.datagrams_dropped_gap += self.delivery.skipped_gap_packets();
        }
        self.stats.reorder_buffer_bytes = self.delivery.buffered_bytes() as u64;
        self.map_delivery_items(emitted)
    }

    fn remove_session(&mut self, session_key: &SessionKey) {
        self.sessions.remove(session_key);
        if let Some(delivery_session_id) = self.delivery_session_ids.remove(session_key) {
            self.delivery_session_keys.remove(&delivery_session_id);
            self.delivery.remove_session(delivery_session_id);
        }
        self.stats.sessions_active = self.sessions.len() as u64;
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
                endpoint_id: obj
                    .get("endpoint_id")
                    .and_then(|value| value.as_u64())
                    .unwrap_or(0),
                weight: obj
                    .get("weight")
                    .and_then(|value| value.as_u64())
                    .unwrap_or(1)
                    .max(1) as u16,
                protocol_version: obj
                    .get("protocol_version")
                    .and_then(|value| value.as_u64())
                    .and_then(|value| u8::try_from(value).ok()),
                capabilities: obj
                    .get("capabilities")
                    .and_then(|value| value.as_array())
                    .map(|values| {
                        values
                            .iter()
                            .filter_map(|value| value.as_str().map(str::to_string))
                            .collect()
                    })
                    .unwrap_or_default(),
                connection_epoch: obj.get("connection_epoch").and_then(|value| value.as_u64()),
            })
        }
        Err(_) => Ok(ParsedLegAuthPayload {
            token: text,
            endpoint_id: 0,
            weight: 1,
            protocol_version: None,
            capabilities: Vec::new(),
            connection_epoch: None,
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
    mac.verify_slice(&actual_sig)
        .map_err(|_| anyhow!("token signature verification failed"))?;
    let payload: serde_json::Value = serde_json::from_slice(&payload_bytes)?;
    let expires_at = payload
        .get("expires_at")
        .and_then(|v| v.as_u64())
        .unwrap_or(0);
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
    let target_port = payload
        .get("target_port")
        .and_then(|v| v.as_u64())
        .unwrap_or(0) as u16;
    let forward_id = payload
        .get("forward_id")
        .and_then(|v| v.as_u64())
        .unwrap_or(0);
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
        token_fingerprint: format!("{:x}", sha2::Sha256::digest(token.as_bytes()))[..16]
            .to_string(),
    }
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
    let mut len_buf = [0u8; 4];
    reader.read_exact(&mut len_buf).await?;
    let len = u32::from_be_bytes(len_buf) as usize;
    if len > max_datagram_bytes.saturating_add(crate::frame::FRAME_HEADER_BYTES) {
        bail!("frame exceeds configured datagram limit");
    }
    let mut buf = vec![0u8; len];
    reader.read_exact(&mut buf).await?;
    let frame = decode_frame(&buf)?;
    if frame.payload.len() > max_datagram_bytes {
        bail!("frame exceeds configured datagram limit");
    }
    Ok((frame, 4 + len))
}

async fn send_connected_udp_checked(socket: &UdpSocket, payload: &[u8]) -> io::Result<usize> {
    let sent = socket.send(payload).await?;
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

fn auth_ack_frame(connection_epoch: u64) -> DataFrame {
    DataFrame {
        version: 1,
        msg_type: MsgType::AuthAck,
        flags: 0,
        session_id: 0,
        seq: 0,
        payload: serde_json::to_vec(&AuthAck::leg_progress(connection_epoch))
            .expect("AuthAck serialization cannot fail"),
    }
}

fn scheduler_scope(scope: &ScopeKey) -> SchedulerScopeKey {
    SchedulerScopeKey::Sidecar {
        forward_id: scope.forward_id,
        target_host: scope.target_host.clone(),
        target_port: scope.target_port,
        token_fingerprint: scope.token_fingerprint.clone(),
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

async fn write_feedback_chunk(
    writer: &mut tokio::net::tcp::OwnedWriteHalf,
    chunk: RuntimeChunk,
    scheduler: &SchedulerHandle,
    state: &Arc<Mutex<SidecarState>>,
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
        .context("scheduler stopped before sidecar writer start")?;
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
            .context("scheduler stopped after sidecar writer acceptance")?;
        let mut guard = state.lock().await;
        guard.mark_leg_sent(chunk.leg_id);
    }
    Ok(())
}

async fn receive_feedback(
    receiver: &mut Option<mpsc::Receiver<RuntimeChunk>>,
) -> Option<RuntimeChunk> {
    match receiver {
        Some(receiver) => receiver.recv().await,
        None => std::future::pending().await,
    }
}

fn set_tcp_nodelay(stream: &TcpStream) -> Result<()> {
    stream
        .set_nodelay(true)
        .context("failed to disable Nagle on accepted TCP leg")
}

async fn handle_leg(
    stream: TcpStream,
    state: Arc<Mutex<SidecarState>>,
    outbound_txs: Arc<Mutex<HashMap<u64, mpsc::Sender<QueuedFrame>>>>,
    control_txs: Arc<Mutex<HashMap<u64, mpsc::Sender<ControlFrame>>>>,
    config: SidecarConfig,
    inbound_tx: mpsc::Sender<InboundFrame>,
    scheduler: Option<SchedulerHandle>,
    runtime_process_epoch: u64,
    reconnect_counter: Arc<AtomicU64>,
) -> Result<()> {
    let leg_id = {
        let mut guard = state.lock().await;
        let current = guard.next_leg_id;
        guard.next_leg_id += 1;
        current
    };
    set_tcp_nodelay(&stream)?;
    let (mut reader, mut writer) = stream.into_split();
    let (first_frame, _) = read_frame_prefixed(&mut reader, config.max_datagram_bytes).await?;
    if first_frame.msg_type != MsgType::Auth {
        bail!("auth frame required before data");
    }
    let auth_payload = parse_leg_auth_payload(&first_frame.payload)?;
    let now = now_ms();
    let claims = verify_session_token(&auth_payload.token, &config.token_secret, now)?;
    if !config.allowed_forward_ids.is_empty()
        && !config.allowed_forward_ids.contains(&claims.forward_id)
    {
        bail!("forward {} is not allowed", claims.forward_id);
    }
    let scope_key = build_scope_key(&claims, &auth_payload.token);
    let requested_progress = auth_payload.requested_progress();
    let feedback_negotiated = scheduler.is_some() && requested_progress.is_some();
    let active_connection_epoch = requested_progress
        .as_ref()
        .map(|capabilities| capabilities.connection_epoch)
        .unwrap_or_else(|| {
            connection_epoch(
                runtime_process_epoch,
                reconnect_counter.fetch_add(1, Ordering::Relaxed) + 1,
            )
        });
    let penalty = {
        let guard = state.lock().await;
        guard
            .endpoint_penalties
            .entries
            .get(&auth_payload.endpoint_id)
            .cloned()
            .unwrap_or_default()
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
    let (tx, mut rx) = mpsc::channel::<QueuedFrame>(config.max_leg_queue_packets);
    let (control_tx, mut control_rx) =
        mpsc::channel::<ControlFrame>(config.scheduler.control_queue_packets);
    let (feedback_tx, mut feedback_rx) = if scheduler.is_some() {
        let (tx, rx) = mpsc::channel(config.max_leg_queue_packets);
        (Some(tx), Some(rx))
    } else {
        (None, None)
    };
    {
        let mut guard = outbound_txs.lock().await;
        guard.insert(leg_id, tx);
    }
    {
        let mut guard = control_txs.lock().await;
        guard.insert(leg_id, control_tx);
    }
    if feedback_negotiated {
        write_frame_prefixed(&mut writer, &auth_ack_frame(active_connection_epoch)).await?;
    }
    {
        let mut guard = state.lock().await;
        guard.endpoint_penalties.clear(auth_payload.endpoint_id);
        guard.register_leg(ActiveLeg::new(
            leg_id,
            auth_payload.endpoint_id,
            scope_key.clone(),
            claims.clone(),
            weight,
            config.max_leg_queue_packets,
            config.max_leg_queue_bytes,
        ));
    }
    if let (Some(scheduler), Some(data_tx)) = (&scheduler, &feedback_tx) {
        scheduler
            .send(SchedulerCommand::LegConnected {
                registration: LegRegistration {
                    leg_id,
                    scope_key: scheduler_scope(&scope_key),
                    connection_epoch: active_connection_epoch,
                    progress_direction: Direction::SidecarToClient,
                    weight,
                    now_us: 0,
                    path_group: 0,
                },
                data_tx: data_tx.clone(),
            })
            .await
            .context("scheduler stopped while registering sidecar leg")?;
        if feedback_negotiated {
            scheduler
                .send(SchedulerCommand::AuthAcknowledged {
                    leg_id,
                    ack: AuthAck::leg_progress(active_connection_epoch),
                })
                .await
                .context("scheduler stopped while acknowledging sidecar leg")?;
        }
    }

    let (frame_tx, mut frame_rx) = mpsc::channel::<Result<InboundFrame>>(128);
    let max_datagram_bytes = config.max_datagram_bytes;
    tokio::spawn(async move {
        loop {
            let frame = read_frame_prefixed(&mut reader, max_datagram_bytes)
                .await
                .map(|(frame, wire_bytes)| InboundFrame {
                    leg_id,
                    connection_epoch: active_connection_epoch,
                    feedback_negotiated,
                    wire_bytes,
                    frame,
                });
            if frame_tx.send(frame).await.is_err() {
                break;
            }
        }
    });

    let mut keepalive = tokio::time::interval(Duration::from_secs(2));
    keepalive.set_missed_tick_behavior(MissedTickBehavior::Delay);
    let result: Result<()> = loop {
        tokio::select! {
            biased;
            maybe_control = control_rx.recv() => {
                let Some(control) = maybe_control else { break Ok(()); };
                if control.connection_epoch == active_connection_epoch {
                    if let Err(error) = write_frame_prefixed(&mut writer, &control.frame).await {
                        break Err(error);
                    }
                }
            }
            maybe_frame = frame_rx.recv() => {
                match maybe_frame {
                    Some(Ok(inbound)) => {
                        if inbound.frame.msg_type == MsgType::Auth {
                            break Err(anyhow!("auth frame must be first binary frame"));
                        }
                        if inbound_tx.send(inbound).await.is_err() {
                            break Ok(());
                        }
                    }
                    Some(Err(err)) => break Err(err),
                    None => break Ok(()),
                }
            }
            maybe_feedback = receive_feedback(&mut feedback_rx), if feedback_rx.is_some() => {
                let Some(chunk) = maybe_feedback else { break Ok(()); };
                if chunk.connection_epoch == active_connection_epoch {
                    if let Some(scheduler) = scheduler.as_ref() {
                        if let Err(error) = write_feedback_chunk(
                            &mut writer,
                            chunk,
                            scheduler,
                            &state,
                        ).await {
                            break Err(error);
                        }
                    }
                }
            }
            maybe_out = rx.recv() => {
                let Some(queued) = maybe_out else { break Ok(()); };
                {
                    let mut guard = state.lock().await;
                    guard.dequeue_leg_frame(leg_id, queued.payload_bytes);
                }
                if let Err(err) = write_frame_prefixed(&mut writer, &queued.frame).await {
                    let mut guard = state.lock().await;
                    guard.mark_leg_write_failure(leg_id);
                    break Err(err);
                }
                let mut guard = state.lock().await;
                guard.mark_leg_sent(leg_id);
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
                if let Err(error) = write_frame_prefixed(&mut writer, &frame).await {
                    break Err(error);
                }
            }
        }
    };

    if let Some(scheduler) = &scheduler {
        scheduler
            .send(SchedulerCommand::LegDisconnected {
                leg_id,
                connection_epoch: active_connection_epoch,
            })
            .await
            .ok();
    }

    {
        let mut guard = state.lock().await;
        guard
            .endpoint_penalties
            .apply_failure(auth_payload.endpoint_id, now_ms());
        guard.remove_leg(leg_id);
    }
    let mut outbound = outbound_txs.lock().await;
    outbound.remove(&leg_id);
    drop(outbound);
    let mut controls = control_txs.lock().await;
    controls.remove(&leg_id);
    result
}

pub async fn run_from_path(path: &str) -> Result<()> {
    let raw = std::fs::read_to_string(path)?;
    let config: SidecarConfig = serde_json::from_str(&raw)?;
    run(config).await
}

pub async fn run(config: SidecarConfig) -> Result<()> {
    config.validate()?;
    let state = Arc::new(Mutex::new(SidecarState::new(&config)));
    let outbound_txs = Arc::new(Mutex::new(HashMap::<u64, mpsc::Sender<QueuedFrame>>::new()));
    let control_txs = Arc::new(Mutex::new(HashMap::<u64, mpsc::Sender<ControlFrame>>::new()));
    let (inbound_tx, mut inbound_rx) = mpsc::channel::<InboundFrame>(4096);
    let (udp_tx, mut udp_rx) = mpsc::channel::<((ScopeKey, u64), Vec<u8>)>(4096);
    let listener = TcpListener::bind((config.listen_host.as_str(), config.listen_port)).await?;
    let feedback_enabled = matches!(config.scheduler.scheduler_mode, SchedulerMode::FeedbackDrr);
    let runtime_process_epoch = process_epoch();
    let scheduler =
        feedback_enabled.then(|| spawn_scheduler(config.scheduler.clone(), runtime_process_epoch));
    let reconnect_counter = Arc::new(AtomicU64::new(0));

    let accept_state = state.clone();
    let accept_outbound = outbound_txs.clone();
    let accept_controls = control_txs.clone();
    let accept_config = config.clone();
    let accept_inbound = inbound_tx.clone();
    let accept_scheduler = scheduler.clone();
    let accept_reconnect_counter = reconnect_counter.clone();
    tokio::spawn(async move {
        loop {
            let Ok((stream, _peer)) = listener.accept().await else {
                continue;
            };
            let state = accept_state.clone();
            let outbound = accept_outbound.clone();
            let controls = accept_controls.clone();
            let config = accept_config.clone();
            let inbound_tx = accept_inbound.clone();
            let scheduler = accept_scheduler.clone();
            let reconnect_counter = accept_reconnect_counter.clone();
            tokio::spawn(async move {
                let _ = handle_leg(
                    stream,
                    state,
                    outbound,
                    controls,
                    config,
                    inbound_tx,
                    scheduler,
                    runtime_process_epoch,
                    reconnect_counter,
                )
                .await;
            });
        }
    });

    let dispatch_state = state.clone();
    let dispatch_outbound = outbound_txs.clone();
    let dispatch_controls = control_txs.clone();
    let dispatch_udp_tx = udp_tx.clone();
    let idle_timeout = config.session_idle_timeout_ms;
    let reorder_timeout = config.reorder_timeout_ms;
    let wireguard_aware = config.wireguard_aware;
    let udp_socket_buffer_bytes = config.udp_socket_buffer_bytes;
    let dispatch_scheduler = scheduler.clone();
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
                maybe_inbound = inbound_rx.recv() => {
                    let Some(inbound) = maybe_inbound else { break; };
                    let InboundFrame {
                        leg_id,
                        connection_epoch,
                        feedback_negotiated,
                        wire_bytes,
                        frame,
                    } = inbound;
                    {
                        let mut guard = dispatch_state.lock().await;
                        guard.mark_leg_received(leg_id);
                    }
                    if matches!(frame.msg_type, MsgType::LegProgress) {
                        if let Some(scheduler) = &dispatch_scheduler {
                            if feedback_negotiated {
                                match LegProgress::decode(&frame.payload) {
                                    Ok(progress) => {
                                        scheduler
                                            .send(SchedulerCommand::Progress {
                                                leg_id,
                                                connection_epoch,
                                                progress,
                                            })
                                            .await
                                            .ok();
                                    }
                                    Err(_) => {
                                        scheduler.send(SchedulerCommand::InvalidControl).await.ok();
                                    }
                                }
                            } else {
                                scheduler.send(SchedulerCommand::InvalidControl).await.ok();
                            }
                        }
                        continue;
                    }
                    if feedback_negotiated && matches!(frame.msg_type, MsgType::Data) {
                        let emitter = progress_emitters.entry(leg_id).or_insert_with(|| {
                            ProgressEmitter::new(
                                Direction::ClientToSidecar,
                                connection_epoch,
                                progress_started.elapsed().as_micros() as u64,
                            )
                        });
                        if emitter.connection_epoch() == connection_epoch {
                            emitter.record_decoded(wire_bytes);
                        }
                    }
                    let progress_identity = progress_emitters
                        .get(&leg_id)
                        .filter(|emitter| emitter.connection_epoch() == connection_epoch)
                        .map(|_| (leg_id, connection_epoch, wire_bytes));
                    let mut sends = Vec::<(
                        Arc<UdpSocket>,
                        Vec<u8>,
                        Option<(u64, u64, usize)>,
                    )>::new();
                    match frame.msg_type {
                        MsgType::Data => {
                            let leg = match dispatch_state
                                .lock()
                                .await
                                .active_legs
                                .get(&leg_id)
                                .cloned()
                            {
                                Some(leg) => leg,
                                None => continue,
                            };
                            let session_id = frame.session_id as u64;
                            let full_key = (leg.scope_key.clone(), session_id);
                            let session_missing = !dispatch_state
                                .lock()
                                .await
                                .sessions
                                .contains_key(&full_key);
                            if session_missing {
                                let bind_address = SocketAddr::from(([0, 0, 0, 0], 0));
                                if let Ok(socket) = bind_udp_socket(bind_address, udp_socket_buffer_bytes) {
                                    if socket
                                        .connect((leg.claims.target_host.as_str(), leg.claims.target_port))
                                        .await
                                        .is_ok()
                                    {
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
                                                if udp_tx
                                                    .send((session_key.clone(), buf[..size].to_vec()))
                                                    .await
                                                    .is_err()
                                                {
                                                    break;
                                                }
                                            }
                                        });
                                        let mut guard = dispatch_state.lock().await;
                                        guard.sessions.entry(full_key.clone()).or_insert(SessionInfo {
                                            socket: session_socket,
                                            reverse_seq: 1,
                                            last_seen_ms: now_ms(),
                                        });
                                    }
                                }
                            }
                            let mut guard = dispatch_state.lock().await;
                            for (emitted_key, payload) in guard.deliver_inbound_data(&full_key, frame.seq, frame.payload) {
                                if let Some(session) = guard.sessions.get_mut(&emitted_key) {
                                    session.last_seen_ms = now_ms();
                                    sends.push((session.socket.clone(), payload, progress_identity));
                                    guard.stats.datagrams_in += 1;
                                    guard.stats.bytes_in += sends
                                        .last()
                                        .map(|(_, payload, _)| payload.len() as u64)
                                        .unwrap_or(0);
                                }
                            }
                            if guard.delivery.is_unordered() {
                                guard.stats.datagrams_direct_delivered += sends.len() as u64;
                            }
                            guard.stats.reorder_buffer_bytes = guard.delivery.buffered_bytes() as u64;
                        }
                        MsgType::Close => {
                            let mut guard = dispatch_state.lock().await;
                            let leg = match guard.active_legs.get(&leg_id).cloned() {
                                Some(leg) => leg,
                                None => continue,
                            };
                            guard.remove_session(&(leg.scope_key, frame.session_id as u64));
                        }
                        MsgType::Keepalive
                        | MsgType::Auth
                        | MsgType::Open
                        | MsgType::FecParity
                        | MsgType::AuthAck
                        | MsgType::LegProgress => {}
                    }
                    for (socket, payload, progress_identity) in sends {
                        let sent = send_connected_udp_checked(&socket, &payload).await;
                        if sent.is_ok() {
                            if let Some((progress_leg, epoch, accepted_wire_bytes)) = progress_identity {
                                if let Some(emitter) = progress_emitters.get_mut(&progress_leg) {
                                    if emitter.connection_epoch() == epoch {
                                        emitter.record_udp_accepted(accepted_wire_bytes);
                                    }
                                }
                            }
                        } else {
                            let mut guard = dispatch_state.lock().await;
                            guard.stats.udp_egress_errors += 1;
                            drop(guard);
                            if let Some(scheduler) = &dispatch_scheduler {
                                scheduler
                                    .send(SchedulerCommand::UdpEgressError {
                                        kind: UdpErrorKind::from_io_error(
                                            sent.as_ref().unwrap_err(),
                                        ),
                                        payload_bytes: payload.len(),
                                        wire_bytes: progress_identity
                                            .map(|(_, _, bytes)| bytes)
                                            .unwrap_or(0),
                                    })
                                    .await
                                    .ok();
                            }
                        }
                    }
                    let now_us = progress_started.elapsed().as_micros() as u64;
                    if let Some(emitter) = progress_emitters.get_mut(&leg_id) {
                        let controls = dispatch_controls.lock().await;
                        if !enqueue_progress(
                            emitter,
                            &controls,
                            leg_id,
                            now_us,
                            progress_interval_bytes,
                            progress_interval_frames,
                            progress_interval_ms,
                        ) {
                            drop(controls);
                            let mut guard = dispatch_state.lock().await;
                            guard.stats.control_queue_drops += 1;
                        }
                    }
                }
                maybe_udp = udp_rx.recv() => {
                    let Some((session_key, payload)) = maybe_udp else { break; };
                    let outbound_identity = {
                        let mut guard = dispatch_state.lock().await;
                        let (scope_key, session_id) = session_key.clone();
                        if let Some(session) = guard.sessions.get_mut(&session_key) {
                            session.last_seen_ms = now_ms();
                            let sequence = session.reverse_seq;
                            session.reverse_seq += 1;
                            Some((scope_key, session_id, sequence))
                        } else {
                            None
                        }
                    };
                    let Some((scope_key, session_id, sequence)) = outbound_identity else {
                        continue;
                    };
                    let payload_bytes = payload.len();
                    if let Some(scheduler) = &dispatch_scheduler {
                        let class = if is_duplicate_eligible(&payload) {
                            TrafficClass::WireGuardHandshake
                        } else {
                            TrafficClass::Regular
                        };
                        let admitted = scheduler
                            .try_admit(
                                SchedulerSessionKey {
                                    scope_key: scheduler_scope(&scope_key),
                                    session_id,
                                    direction: Direction::SidecarToClient,
                                    process_epoch: runtime_process_epoch,
                                },
                                sequence,
                                Bytes::from(payload),
                                class,
                            )
                            .is_ok();
                        let mut guard = dispatch_state.lock().await;
                        if admitted {
                            guard.stats.datagrams_out += 1;
                            guard.stats.bytes_out += payload_bytes as u64;
                        } else {
                            guard.stats.datagrams_dropped_queue_full += 1;
                        }
                    } else {
                        let frame = DataFrame {
                            version: 1,
                            msg_type: MsgType::Data,
                            flags: 0,
                            session_id: session_id as u32,
                            seq: sequence,
                            payload,
                        };
                        let mut selected_legs = Vec::<u64>::new();
                        {
                            let mut guard = dispatch_state.lock().await;
                            if let Some(primary_leg) = guard.reserve_next_leg(&scope_key, payload_bytes, None) {
                                selected_legs.push(primary_leg);
                                if wireguard_aware && is_duplicate_eligible(&frame.payload) {
                                    if let Some(second_leg) = guard.reserve_next_leg(&scope_key, payload_bytes, Some(primary_leg)) {
                                        selected_legs.push(second_leg);
                                    }
                                }
                                guard.stats.datagrams_out += 1;
                                guard.stats.bytes_out += payload_bytes as u64;
                            } else if guard.has_active_leg(&scope_key) {
                                guard.stats.datagrams_dropped_queue_full += 1;
                            } else {
                                guard.stats.datagrams_dropped_no_leg += 1;
                            }
                        }
                        for (index, leg_id) in selected_legs.into_iter().enumerate() {
                            let sent = {
                                let outbound = dispatch_outbound.lock().await;
                                outbound
                                    .get(&leg_id)
                                    .map(|tx| {
                                        tx.try_send(QueuedFrame {
                                            frame: frame.clone(),
                                            payload_bytes,
                                        })
                                    })
                                    .is_some_and(|result| result.is_ok())
                            };
                            if sent {
                                if index > 0 {
                                    let mut guard = dispatch_state.lock().await;
                                    guard.stats.handshake_datagrams_duplicated += 1;
                                }
                            } else {
                                let mut guard = dispatch_state.lock().await;
                                guard.release_leg_queue(leg_id, payload_bytes);
                                guard.stats.datagrams_dropped_queue_full += 1;
                            }
                        }
                    }
                }
                _ = tick.tick() => {
                    let mut sends = Vec::<(Arc<UdpSocket>, Vec<u8>)>::new();
                    {
                        let mut guard = dispatch_state.lock().await;
                        for (session_key, payload) in guard.flush_timed_out_delivery(now_ms()) {
                            if let Some(session) = guard.sessions.get_mut(&session_key) {
                                session.last_seen_ms = now_ms();
                                sends.push((session.socket.clone(), payload));
                                guard.stats.datagrams_in += 1;
                                guard.stats.bytes_in += sends.last().map(|(_, payload)| payload.len() as u64).unwrap_or(0);
                            }
                        }
                        let stale = guard.sessions.iter().filter_map(|(key, session)| {
                            if now_ms().saturating_sub(session.last_seen_ms) >= idle_timeout {
                                Some(key.clone())
                            } else {
                                None
                            }
                        }).collect::<Vec<_>>();
                        for key in stale {
                            guard.remove_session(&key);
                        }
                        guard.stats.sessions_active = guard.sessions.len() as u64;
                    }
                    for (socket, payload) in sends {
                        if let Err(error) = send_connected_udp_checked(&socket, &payload).await {
                            let mut guard = dispatch_state.lock().await;
                            guard.stats.udp_egress_errors += 1;
                            drop(guard);
                            if let Some(scheduler) = &dispatch_scheduler {
                                scheduler
                                    .send(SchedulerCommand::UdpEgressError {
                                        kind: UdpErrorKind::from_io_error(&error),
                                        payload_bytes: payload.len(),
                                        wire_bytes: 0,
                                    })
                                    .await
                                    .ok();
                            }
                        }
                    }
                    let active_leg_ids = dispatch_state
                        .lock()
                        .await
                        .active_legs
                        .keys()
                        .copied()
                        .collect::<std::collections::HashSet<_>>();
                    progress_emitters.retain(|leg_id, _| active_leg_ids.contains(leg_id));
                    let now_us = progress_started.elapsed().as_micros() as u64;
                    for (leg_id, emitter) in &mut progress_emitters {
                        let controls = dispatch_controls.lock().await;
                        if !enqueue_progress(
                            emitter,
                            &controls,
                            *leg_id,
                            now_us,
                            progress_interval_bytes,
                            progress_interval_frames,
                            progress_interval_ms,
                        ) {
                            drop(controls);
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
                    "bytes_in": guard.stats.bytes_in,
                    "bytes_out": guard.stats.bytes_out,
                    "reorder_buffer_bytes": guard.stats.reorder_buffer_bytes,
                    "udp_egress_errors": guard.stats.udp_egress_errors,
                    "control_queue_drops": guard.stats.control_queue_drops,
                    "scheduler": scheduler_snapshot,
                    "legs": guard.active_legs.values().map(|leg| serde_json::json!({
                        "leg_id": leg.leg_id,
                        "endpoint_id": leg.endpoint_id,
                        "active": true,
                        "queued_packets": leg.queue.queued_packets(),
                        "queued_bytes": leg.queue.queued_bytes(),
                        "sent_packets": leg.sent_packets,
                        "received_packets": leg.received_packets,
                        "write_failures": leg.write_failures,
                        "reconnects": leg.reconnects,
                    })).collect::<Vec<_>>(),
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
    use crate::config::DeliveryMode;

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
        assert!(parsed.requested_progress().is_none());
    }

    #[test]
    fn parse_leg_auth_payload_accepts_extended_capabilities() {
        let payload = br#"{
            "token":"udp-token",
            "endpoint_id":184,
            "weight":8,
            "protocol_version":2,
            "capabilities":["leg-progress-v1"],
            "connection_epoch":77
        }"#;
        let parsed = parse_leg_auth_payload(payload).expect("parse");
        let capabilities = parsed.requested_progress().expect("capability");
        assert_eq!(capabilities.connection_epoch, 77);
        let ack: AuthAck = serde_json::from_slice(&auth_ack_frame(77).payload).unwrap();
        assert!(ack.matches(77));
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
        let expected_wire_bytes = 4 + encoded.len();
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
    async fn checked_connected_udp_send_accepts_only_a_full_datagram() {
        let receiver = UdpSocket::bind("127.0.0.1:0").await.unwrap();
        let sender = UdpSocket::bind("127.0.0.1:0").await.unwrap();
        sender
            .connect(receiver.local_addr().unwrap())
            .await
            .unwrap();
        let payload = b"checked";
        assert_eq!(
            send_connected_udp_checked(&sender, payload).await.unwrap(),
            payload.len()
        );
        let mut buffer = [0_u8; 32];
        let (received, _) = receiver.recv_from(&mut buffer).await.unwrap();
        assert_eq!(&buffer[..received], payload);
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

    #[tokio::test]
    async fn accepted_leg_socket_disables_nagle() {
        let listener = TcpListener::bind("127.0.0.1:0").await.expect("listener");
        let port = listener.local_addr().expect("listener address").port();
        let client = tokio::spawn(async move {
            TcpStream::connect(("127.0.0.1", port))
                .await
                .expect("client connection")
        });
        let (stream, _) = listener.accept().await.expect("accept");
        let client = client.await.expect("client task");

        set_tcp_nodelay(&stream).expect("set TCP_NODELAY");

        assert!(stream.nodelay().expect("read TCP_NODELAY"));
        drop(client);
    }

    #[test]
    fn sidecar_unordered_delivery_does_not_wait_for_gap() {
        let config = SidecarConfig {
            listen_host: "127.0.0.1".to_string(),
            listen_port: 29028,
            token_secret: "test".to_string(),
            allowed_forward_ids: vec![3],
            reorder_window_packets: 16,
            reorder_window_bytes: 4096,
            reorder_timeout_ms: 25,
            delivery_mode: DeliveryMode::Unordered,
            wireguard_aware: true,
            max_leg_queue_packets: 2,
            max_leg_queue_bytes: 1024,
            max_datagram_bytes: 1024,
            udp_socket_buffer_bytes: 1024,
            stats_path: "unused".to_string(),
            session_idle_timeout_ms: 120_000,
            scheduler: crate::config::SchedulerConfig::default(),
        };
        let mut state = SidecarState::new(&config);

        let session_key = (
            ScopeKey {
                forward_id: 3,
                target_host: "127.0.0.1".to_string(),
                target_port: 1002,
                token_fingerprint: "test".to_string(),
            },
            7,
        );
        let emitted = state.deliver_inbound_data(&session_key, 2, b"late".to_vec());

        assert_eq!(emitted, vec![(session_key, b"late".to_vec())]);
        assert_eq!(state.stats.reorder_buffer_bytes, 0);
    }

    #[test]
    fn sidecar_ordered_delivery_flushes_timed_out_data_to_its_scope() {
        let config = SidecarConfig {
            listen_host: "127.0.0.1".to_string(),
            listen_port: 29028,
            token_secret: "test".to_string(),
            allowed_forward_ids: vec![3],
            reorder_window_packets: 16,
            reorder_window_bytes: 4096,
            reorder_timeout_ms: 25,
            delivery_mode: DeliveryMode::Ordered,
            wireguard_aware: true,
            max_leg_queue_packets: 2,
            max_leg_queue_bytes: 1024,
            max_datagram_bytes: 1024,
            udp_socket_buffer_bytes: 1024,
            stats_path: "unused".to_string(),
            session_idle_timeout_ms: 120_000,
            scheduler: crate::config::SchedulerConfig::default(),
        };
        let mut state = SidecarState::new(&config);
        let scope = ScopeKey {
            forward_id: 3,
            target_host: "127.0.0.1".to_string(),
            target_port: 1002,
            token_fingerprint: "test".to_string(),
        };
        let session_key = (scope, 7);

        assert!(state
            .deliver_inbound_data_at(&session_key, 2, b"late".to_vec(), 100)
            .is_empty());
        assert!(state.flush_timed_out_delivery(124).is_empty());

        assert_eq!(
            state.flush_timed_out_delivery(125),
            vec![(session_key, b"late".to_vec())]
        );
    }

    #[test]
    fn sidecar_skips_full_leg_for_second_healthy_leg() {
        let config = SidecarConfig {
            listen_host: "127.0.0.1".to_string(),
            listen_port: 29028,
            token_secret: "test".to_string(),
            allowed_forward_ids: vec![3],
            reorder_window_packets: 16,
            reorder_window_bytes: 4096,
            reorder_timeout_ms: 25,
            delivery_mode: DeliveryMode::Unordered,
            wireguard_aware: true,
            max_leg_queue_packets: 1,
            max_leg_queue_bytes: 100,
            max_datagram_bytes: 1024,
            udp_socket_buffer_bytes: 1024,
            stats_path: "unused".to_string(),
            session_idle_timeout_ms: 120_000,
            scheduler: crate::config::SchedulerConfig::default(),
        };
        let mut state = SidecarState::new(&config);
        let scope = ScopeKey {
            forward_id: 3,
            target_host: "127.0.0.1".to_string(),
            target_port: 1002,
            token_fingerprint: "test".to_string(),
        };
        let claims = ClaimSet {
            forward_id: 3,
            target_host: "127.0.0.1".to_string(),
            target_port: 1002,
        };
        state.register_leg(ActiveLeg::for_test(
            1,
            1,
            scope.clone(),
            claims.clone(),
            1,
            1,
            100,
        ));
        state.register_leg(ActiveLeg::for_test(2, 2, scope.clone(), claims, 1, 1, 100));
        assert!(state
            .active_legs
            .get_mut(&1)
            .expect("first leg")
            .queue
            .try_reserve(100));

        assert_eq!(state.reserve_next_leg(&scope, 1, None), Some(2));
    }
}
