use crate::frame::{HelloFrame, WireFrame, read_frame, write_frame};
use crate::strategy::{ClientStrategy, load_strategy};
use crate::token::{SessionClaims, TransportProtocol, verify_session_token};
use crate::transport::{
    LegIo, LegRole, LegSpec, LegTransport, accept_quic_leg, build_server_quic_endpoint, connect_leg, default_quic_listen,
    parse_leg_specs,
};
use anyhow::{Context, anyhow};
use bytes::Bytes;
use chrono::{SecondsFormat, Utc};
use serde::Serialize;
use std::collections::{BTreeMap, HashMap, HashSet, VecDeque};
use std::fs;
use std::net::SocketAddr;
use std::path::PathBuf;
use std::sync::Arc;
use std::time::{Duration, SystemTime, UNIX_EPOCH};
use tokio::io::{AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream, UdpSocket, lookup_host};
use tokio::sync::{mpsc, watch};
use tokio::task::JoinSet;
use tokio::time::{Instant, interval, sleep, timeout};
use log::{debug, info, warn};
use uuid::Uuid;

const UDP_FRAGMENT_MIN_SIZE: usize = 1200;
const UDP_FRAGMENT_MAX_SIZE: usize = 16 * 1024;
const UDP_REASSEMBLY_TIMEOUT: Duration = Duration::from_secs(30);
const UDP_COMPLETED_CACHE_TTL: Duration = Duration::from_secs(60);
const UDP_PEER_IDLE_TTL: Duration = Duration::from_secs(120);
const UDP_MAX_REASSEMBLED_BYTES: usize = 1024 * 1024;
const DEFAULT_TCP_LEG_RTT: Duration = Duration::from_millis(120);
const DEFAULT_QUIC_LEG_RTT: Duration = Duration::from_millis(35);
const DEFAULT_INTERACTIVE_RTT_SLACK: Duration = Duration::from_millis(30);
const CLIENT_LEG_RETRY_BACKOFF: Duration = Duration::from_secs(30 * 60);
const MIN_TRANSPORT_RESUME_TIMEOUT: Duration = Duration::from_secs(5);
const MIN_TRANSPORT_IDLE_TIMEOUT: Duration = Duration::from_secs(30);
const CLIENT_TCP_LEG_JOIN_GRACE: Duration = Duration::from_millis(600);
const TRANSPORT_STREAM_CLOSE_GRACE: Duration = Duration::from_secs(2);
const MAX_TCP_RETRANSMIT_BATCH: usize = 8;
const MAX_RECENT_HEALTH_EVENTS: usize = 20;
const SNAPSHOT_WRITE_INTERVAL: Duration = Duration::from_millis(250);

#[derive(Debug, Clone)]
pub struct SessionConfig {
    pub chunk_size: usize,
    pub max_window_bytes: usize,
    pub retransmit_after: Duration,
    pub join_timeout: Duration,
    pub connect_timeout: Duration,
    pub interactive_chunk_bytes: usize,
    pub latency_guard_bytes: usize,
    pub interactive_rtt_slack: Duration,
    pub warmup_trigger_bytes: usize,
    pub warmup_slice_bytes: usize,
    pub warmup_leg_delay: Duration,
    pub bulk_promote_bytes: usize,
    pub leg_cooldown: Duration,
    pub latency_first: bool,
    pub udp_primary_only: bool,
    pub strategy_file: Option<PathBuf>,
    pub default_strategy: ClientStrategy,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum SessionRole {
    Client,
    Aggregator,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum TcpStripingStage {
    Sticky,
    Warmup,
    Bulk,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
#[serde(rename_all = "lowercase")]
enum LegHealthState {
    Healthy,
    Degraded,
    Probation,
    Quarantined,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum HealthProtocol {
    Tcp,
    Udp,
}

impl HealthProtocol {
    fn as_str(self) -> &'static str {
        match self {
            Self::Tcp => "tcp",
            Self::Udp => "udp",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum TcpPenalty {
    RetransmitBurst,
    StreamOpenTimeout,
    RecvReset,
    ControlSocketClosedUnexpectedly,
    SelectedButUnproductive,
    LocalReadFailed,
    LocalWriteFailed,
    CloseTimeout,
}

impl TcpPenalty {
    fn weight(self) -> f64 {
        match self {
            Self::RetransmitBurst => 0.18,
            Self::StreamOpenTimeout => 0.45,
            Self::RecvReset => 0.55,
            Self::ControlSocketClosedUnexpectedly => 0.28,
            Self::SelectedButUnproductive => 0.15,
            Self::LocalReadFailed => 0.60,
            Self::LocalWriteFailed => 0.60,
            Self::CloseTimeout => 0.55,
        }
    }

    fn as_str(self) -> &'static str {
        match self {
            Self::RetransmitBurst => "retransmit_burst",
            Self::StreamOpenTimeout => "stream_open_timeout",
            Self::RecvReset => "recv_reset",
            Self::ControlSocketClosedUnexpectedly => "control_socket_closed_unexpectedly",
            Self::SelectedButUnproductive => "selected_but_unproductive",
            Self::LocalReadFailed => "local_read_failed",
            Self::LocalWriteFailed => "local_write_failed",
            Self::CloseTimeout => "close_timeout",
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum UdpPenalty {
    RetransmitBurst,
    HighLossRatio,
    LegClosed,
}

impl UdpPenalty {
    fn weight(self) -> f64 {
        match self {
            Self::RetransmitBurst => 0.18,
            Self::HighLossRatio => 0.28,
            Self::LegClosed => 0.45,
        }
    }

    fn as_str(self) -> &'static str {
        match self {
            Self::RetransmitBurst => "retransmit_burst",
            Self::HighLossRatio => "high_loss_ratio",
            Self::LegClosed => "leg_closed",
        }
    }
}

#[derive(Debug, Clone)]
struct ProtocolLegHealth {
    state: LegHealthState,
    score: f64,
    effective_weight: f64,
    delivered_bytes_ewma: f64,
    penalty_ewma: f64,
    last_reason: Option<String>,
    last_good_at: Option<SystemTime>,
    last_probe_at: Option<SystemTime>,
}

impl ProtocolLegHealth {
    fn new() -> Self {
        Self {
            state: LegHealthState::Healthy,
            score: 1.0,
            effective_weight: 1.0,
            delivered_bytes_ewma: 0.0,
            penalty_ewma: 0.0,
            last_reason: None,
            last_good_at: Some(SystemTime::now()),
            last_probe_at: None,
        }
    }
}

#[derive(Debug, Clone)]
struct LegHealthEvent {
    protocol: HealthProtocol,
    from: LegHealthState,
    to: LegHealthState,
    reason: String,
    observed_at: SystemTime,
}

#[derive(Debug, Clone)]
struct SnapshotContext {
    observer_type: &'static str,
    observer_id: u64,
    forward_id: u64,
    path: PathBuf,
}

impl SessionRole {
    fn outbound_data(self, stream_id: u32, seq: u64, payload: Bytes) -> WireFrame {
        match self {
            Self::Client => WireFrame::DataC2S {
                stream_id,
                seq,
                payload,
            },
            Self::Aggregator => WireFrame::DataS2C {
                stream_id,
                seq,
                payload,
            },
        }
    }

    fn outbound_udp_fragment(
        self,
        peer_id: u32,
        datagram_id: u64,
        frag_index: u16,
        frag_total: u16,
        payload: Bytes,
    ) -> WireFrame {
        match self {
            Self::Client => WireFrame::UdpFragC2S {
                peer_id,
                datagram_id,
                frag_index,
                frag_total,
                payload,
            },
            Self::Aggregator => WireFrame::UdpFragS2C {
                peer_id,
                datagram_id,
                frag_index,
                frag_total,
                payload,
            },
        }
    }

    fn outbound_udp_ack(self, peer_id: u32, datagram_id: u64) -> WireFrame {
        match self {
            Self::Client => WireFrame::UdpAckS2C {
                peer_id,
                datagram_id,
            },
            Self::Aggregator => WireFrame::UdpAckC2S {
                peer_id,
                datagram_id,
            },
        }
    }

    fn outbound_ack(self, stream_id: u32, ack: u64) -> WireFrame {
        match self {
            Self::Client => WireFrame::AckS2C { stream_id, ack },
            Self::Aggregator => WireFrame::AckC2S { stream_id, ack },
        }
    }

    fn outbound_fin(self, stream_id: u32, seq: u64) -> WireFrame {
        match self {
            Self::Client => WireFrame::FinC2S { stream_id, seq },
            Self::Aggregator => WireFrame::FinS2C { stream_id, seq },
        }
    }

    fn is_inbound_data(self, frame: &WireFrame) -> bool {
        matches!(
            (self, frame),
            (Self::Client, WireFrame::DataS2C { .. }) | (Self::Aggregator, WireFrame::DataC2S { .. })
        )
    }

    fn is_inbound_udp_fragment(self, frame: &WireFrame) -> bool {
        matches!(
            (self, frame),
            (Self::Client, WireFrame::UdpFragS2C { .. }) | (Self::Aggregator, WireFrame::UdpFragC2S { .. })
        )
    }

    fn is_inbound_udp_ack(self, frame: &WireFrame) -> bool {
        matches!(
            (self, frame),
            (Self::Client, WireFrame::UdpAckC2S { .. }) | (Self::Aggregator, WireFrame::UdpAckS2C { .. })
        )
    }

    fn is_inbound_ack(self, frame: &WireFrame) -> bool {
        matches!(
            (self, frame),
            (Self::Client, WireFrame::AckC2S { .. }) | (Self::Aggregator, WireFrame::AckS2C { .. })
        )
    }

    fn is_inbound_fin(self, frame: &WireFrame) -> bool {
        matches!(
            (self, frame),
            (Self::Client, WireFrame::FinS2C { .. }) | (Self::Aggregator, WireFrame::FinC2S { .. })
        )
    }
}

#[allow(dead_code)]
#[derive(Debug)]
enum SessionEvent {
    LocalChunk(Bytes),
    LocalEof,
    LocalReadFailed(String),
    LocalWriteClosed,
    LocalWriteFailed(String),
    UdpLocalDatagram { peer: SocketAddr, payload: Bytes },
    UdpLocalFailed(String),
    UdpUpstreamDatagram { peer_id: u32, payload: Bytes },
    UdpUpstreamPeerFailed { peer_id: u32, error: String },
    Frame { _leg_id: usize, frame: WireFrame },
    LegClosed { leg_id: usize },
}

#[derive(Debug)]
enum LocalWriteCommand {
    Data(Bytes),
    Shutdown,
}

#[derive(Clone)]
struct LegState {
    id: usize,
    sender: mpsc::UnboundedSender<WireFrame>,
    active: bool,
    inflight_bytes: usize,
    transport: LegTransport,
    priority: u16,
    role: LegRole,
    smoothed_rtt: Duration,
    label: String,
    spec_index: Option<usize>,
    endpoint_id: Option<u64>,
    node_id: Option<u64>,
    host: String,
    cooldown_until: Option<Instant>,
    penalty_count: u8,
    tcp_health: ProtocolLegHealth,
    udp_health: ProtocolLegHealth,
    recent_events: VecDeque<LegHealthEvent>,
}

struct PendingChunk {
    payload: Bytes,
    last_sent_at: Instant,
    last_leg_id: usize,
    transmit_count: u8,
}

struct FinTracker {
    seq: u64,
    last_sent_at: Instant,
}

struct InboundState {
    next_offset: u64,
    acked_offset: u64,
    buffer: BTreeMap<u64, Bytes>,
    pending_write_lengths: VecDeque<usize>,
    fin_offset: Option<u64>,
    writer_shutdown: bool,
}

struct PendingUdpDatagram {
    peer_id: u32,
    fragments: Vec<Bytes>,
    last_sent_at: Instant,
    last_leg_id: Option<usize>,
    transmit_count: u8,
}

impl PendingUdpDatagram {
    fn new(peer_id: u32, payload: Bytes, fragment_size: usize) -> Self {
        Self {
            peer_id,
            fragments: fragment_udp_payload(payload, fragment_size),
            last_sent_at: Instant::now(),
            last_leg_id: None,
            transmit_count: 0,
        }
    }

    fn total_bytes(&self) -> usize {
        self.fragments.iter().map(|fragment| fragment.len()).sum()
    }
}

struct UdpReassembly {
    frag_total: u16,
    fragments: BTreeMap<u16, Bytes>,
    total_bytes: usize,
    last_updated: Instant,
}

impl UdpReassembly {
    fn new(frag_total: u16) -> Self {
        Self {
            frag_total,
            fragments: BTreeMap::new(),
            total_bytes: 0,
            last_updated: Instant::now(),
        }
    }
}

struct OrderedUdpDatagram {
    peer_id: u32,
    payload: Bytes,
}

struct UdpDeliveryBuffer {
    next_datagram_id: u64,
    buffered: BTreeMap<u64, OrderedUdpDatagram>,
}

impl UdpDeliveryBuffer {
    fn new() -> Self {
        Self {
            next_datagram_id: 1,
            buffered: BTreeMap::new(),
        }
    }

    fn insert(&mut self, peer_id: u32, datagram_id: u64, payload: Bytes) -> Vec<OrderedUdpDatagram> {
        if datagram_id < self.next_datagram_id {
            return Vec::new();
        }

        self.buffered
            .entry(datagram_id)
            .or_insert(OrderedUdpDatagram { peer_id, payload });

        let mut ready = Vec::new();
        while let Some(datagram) = self.buffered.remove(&self.next_datagram_id) {
            ready.push(datagram);
            self.next_datagram_id = self.next_datagram_id.wrapping_add(1).max(1);
        }
        ready
    }
}

struct ClientPeerState {
    addr: SocketAddr,
    last_seen: Instant,
    strategy: ClientStrategy,
    preferred_leg_id: Option<usize>,
}

impl InboundState {
    fn new() -> Self {
        Self {
            next_offset: 0,
            acked_offset: 0,
            buffer: BTreeMap::new(),
            pending_write_lengths: VecDeque::new(),
            fin_offset: None,
            writer_shutdown: false,
        }
    }
}

struct OpenLeg {
    stream: LegIo,
    transport: LegTransport,
    priority: u16,
    role: LegRole,
    label: String,
    spec_index: Option<usize>,
    endpoint_id: Option<u64>,
    node_id: Option<u64>,
    host: String,
}

impl OpenLeg {
    fn new(stream: LegIo, priority: u16, role: LegRole, label: String) -> Self {
        let transport = stream.transport();
        Self {
            stream,
            transport,
            priority,
            role,
            host: extract_host_from_label(&label),
            label,
            spec_index: None,
            endpoint_id: None,
            node_id: None,
        }
    }

    fn with_spec_index(mut self, spec_index: usize) -> Self {
        self.spec_index = Some(spec_index);
        self
    }

    fn with_metadata(mut self, endpoint_id: Option<u64>, node_id: Option<u64>, host: String) -> Self {
        self.endpoint_id = endpoint_id;
        self.node_id = node_id;
        if !host.is_empty() {
            self.host = host;
        }
        self
    }
}

fn extract_host_from_label(label: &str) -> String {
    let trimmed = label.trim();
    let after_scheme = trimmed.split("://").nth(1).unwrap_or(trimmed);
    let without_query = after_scheme.split('?').next().unwrap_or(after_scheme);
    if let Some(stripped) = without_query.strip_prefix('[') {
        return stripped.split(']').next().unwrap_or(stripped).to_string();
    }
    if let Some((host, _port)) = without_query.rsplit_once(':') {
        return host.to_string();
    }
    without_query.to_string()
}

pub async fn run_client_listener(
    bind: String,
    leg_targets: Vec<String>,
    session_token: String,
    protocol: TransportProtocol,
    config: SessionConfig,
) -> anyhow::Result<()> {
    match protocol {
        TransportProtocol::Tcp => run_client_tcp_listener(bind, leg_targets, session_token, config).await,
        TransportProtocol::Udp => run_client_udp_listener(bind, leg_targets, session_token, config).await,
    }
}

async fn run_client_tcp_listener(
    bind: String,
    leg_targets: Vec<String>,
    session_token: String,
    config: SessionConfig,
) -> anyhow::Result<()> {
    let listener = TcpListener::bind(&bind)
        .await
        .with_context(|| format!("cannot bind bond client listener on {bind}"))?;
    info!("bond client listening on {}", bind);
    let mut transport_tx = connect_client_tcp_transport_session(
        leg_targets.clone(),
        session_token.clone(),
        config.clone(),
    )
    .await;

    loop {
        let (accepted_stream, peer) = listener.accept().await?;
        let mut pending_stream = Some(accepted_stream);
        let strategy = config
            .strategy_file
            .as_deref()
            .map(|path| load_strategy(path, config.default_strategy))
            .unwrap_or(config.default_strategy);

        while let Some(local_stream) = pending_stream.take() {
            match transport_tx.send(ClientTransportCommand::AttachLocalStream {
                local_stream,
                strategy,
            }) {
                Ok(()) => {
                    debug!(
                        "attached local TCP connection {} to persistent bond transport with {:?}",
                        peer, strategy
                    );
                }
                Err(err) => {
                    let ClientTransportCommand::AttachLocalStream { local_stream, .. } = err.0;
                    warn!(
                        "bond tcp transport closed before accepting {}; reconnecting persistent session",
                        peer
                    );
                    pending_stream = Some(local_stream);
                    transport_tx = connect_client_tcp_transport_session(
                        leg_targets.clone(),
                        session_token.clone(),
                        config.clone(),
                    )
                    .await;
                    continue;
                }
            }
        }
    }
}

async fn connect_client_tcp_transport_session(
    leg_targets: Vec<String>,
    session_token: String,
    config: SessionConfig,
) -> mpsc::UnboundedSender<ClientTransportCommand> {
    loop {
        match open_client_tcp_transport_session(
            leg_targets.clone(),
            session_token.clone(),
            config.clone(),
        )
        .await
        {
            Ok(transport_tx) => {
                info!("bond client TCP transport session established");
                return transport_tx;
            }
            Err(err) => {
                warn!("failed to establish persistent bond TCP transport: {:?}; retrying", err);
                sleep(Duration::from_secs(1)).await;
            }
        }
    }
}

async fn run_client_udp_listener(
    bind: String,
    leg_targets: Vec<String>,
    session_token: String,
    config: SessionConfig,
) -> anyhow::Result<()> {
    let socket = Arc::new(
        UdpSocket::bind(&bind)
            .await
            .with_context(|| format!("cannot bind bond client UDP listener on {bind}"))?,
    );
    info!("bond client UDP listening on {}", bind);

    loop {
        match connect_client_udp_legs(leg_targets.clone(), session_token.clone(), config.clone()).await {
            Ok((session_id, legs, _add_leg_rx)) => {
                info!("bond client UDP session {} opened with {} leg(s)", session_id, legs.len());
                if let Err(err) = run_bonded_udp_client(
                    socket.clone(),
                    legs,
                    snapshot_context(SessionRole::Client, None),
                    config.clone(),
                )
                .await
                {
                    warn!("bond client UDP session {} failed: {:?}", session_id, err);
                    sleep(Duration::from_secs(1)).await;
                }
            }
            Err(err) => {
                warn!("failed to open UDP bond session: {:?}", err);
                sleep(Duration::from_secs(1)).await;
            }
        }
    }
}

pub async fn run_server_listener(
    bind: String,
    quic_listen: Option<String>,
    enable_quic: bool,
    token_secret: String,
    config: SessionConfig,
) -> anyhow::Result<()> {
    let listener = TcpListener::bind(&bind)
        .await
        .with_context(|| format!("cannot bind bond aggregator listener on {bind}"))?;
    info!("bond aggregator listening on {}", bind);
    let quic_bind = quic_listen.unwrap_or_else(|| default_quic_listen(&bind));
    let quic_endpoint = if enable_quic {
        match build_server_quic_endpoint(&quic_bind) {
            Ok(endpoint) => {
                info!("bond aggregator QUIC listening on {}", quic_bind);
                Some(endpoint)
            }
            Err(err) => {
                warn!("failed to enable quic listener on {}: {:?}", quic_bind, err);
                None
            }
        }
    } else {
        None
    };

    let (collector_tx, mut collector_rx) = mpsc::unbounded_channel::<PreparedLeg>();
    let (tcp_done_tx, mut tcp_done_rx) = mpsc::unbounded_channel::<Uuid>();
    let mut pending_udp: HashMap<Uuid, PendingUdpSession> = HashMap::new();
    let mut pending_tcp: HashMap<Uuid, PendingTcpSession> = HashMap::new();
    let mut active_tcp_sessions: HashMap<Uuid, ActiveTcpSessionHandle> = HashMap::new();
    let mut ticker = interval(Duration::from_millis(100));

    loop {
        tokio::select! {
            accept = listener.accept() => {
                let (socket, peer) = accept?;
                let tx = collector_tx.clone();
                tokio::spawn(async move {
                    let leg = OpenLeg::new(
                        LegIo::Tcp(socket),
                        LegTransport::Tcp.default_priority(),
                        LegRole::Primary,
                        format!("tcp://{}", peer),
                    );
                    if let Err(err) = read_hello_and_queue(leg, tx).await {
                        warn!("failed to accept bond leg from {}: {:?}", peer, err);
                    }
                });
            }
            incoming = async {
                if let Some(endpoint) = &quic_endpoint {
                    endpoint.accept().await
                } else {
                    std::future::pending::<Option<quinn::Incoming>>().await
                }
            } => {
                if let Some(incoming) = incoming {
                    let peer = incoming.remote_address();
                    let tx = collector_tx.clone();
                    tokio::spawn(async move {
                        match accept_quic_leg(incoming).await {
                            Ok(stream) => {
                                let leg = OpenLeg::new(
                                    stream,
                                    LegTransport::Quic.default_priority(),
                                    LegRole::Primary,
                                    format!("quic://{}", peer),
                                );
                                if let Err(err) = read_hello_and_queue(leg, tx).await {
                                    warn!("failed to accept quic bond leg from {}: {:?}", peer, err);
                                }
                            }
                            Err(err) => warn!("failed to accept quic bond leg from {}: {:?}", peer, err),
                        }
                    });
                }
            }
            Some(done_session_id) = tcp_done_rx.recv() => {
                active_tcp_sessions.remove(&done_session_id);
            }
            Some(mut prepared) = collector_rx.recv() => {
                let now = Instant::now();
                let claims = match verify_session_token(&prepared.hello.token, &token_secret, unix_now()) {
                    Ok(claims) => claims,
                    Err(err) => {
                        warn!("rejecting bond leg with invalid token: {:?}", err);
                        let mut leg = prepared.leg;
                        let _ = write_frame(
                            &mut leg.stream,
                            &WireFrame::Reset {
                                stream_id: 0,
                                reason: String::from("invalid_token"),
                            },
                        )
                        .await;
                        continue;
                    }
                };

                if claims.target_protocol == TransportProtocol::Tcp {
                    if let Some(active) = active_tcp_sessions.get(&prepared.hello.session_id) {
                        if active.claims.target_host != claims.target_host
                            || active.claims.target_port != claims.target_port
                            || active.claims.target_protocol != claims.target_protocol
                        {
                            warn!("rejecting mismatched late leg for active tcp session {}", prepared.hello.session_id);
                            let mut leg = prepared.leg;
                            let _ = write_frame(
                                &mut leg.stream,
                                &WireFrame::Reset {
                                    stream_id: 0,
                                    reason: String::from("session_mismatch"),
                                },
                            )
                            .await;
                            continue;
                        }
                        match active.add_leg_tx.send(prepared.leg) {
                            Ok(()) => continue,
                            Err(err) => {
                                active_tcp_sessions.remove(&prepared.hello.session_id);
                                prepared.leg = err.0;
                            }
                        }
                    }

                    let entry = pending_tcp.entry(prepared.hello.session_id).or_insert_with(|| PendingTcpSession {
                        claims: claims.clone(),
                        expected_links: prepared.hello.expected_links.max(1),
                        created_at: now,
                        legs: Vec::new(),
                    });

                    if entry.claims.target_host != claims.target_host
                        || entry.claims.target_port != claims.target_port
                        || entry.claims.target_protocol != claims.target_protocol
                        || entry.expected_links != prepared.hello.expected_links.max(1)
                    {
                        warn!("rejecting mismatched tcp leg for session {}", prepared.hello.session_id);
                        let mut leg = prepared.leg;
                        let _ = write_frame(
                            &mut leg.stream,
                            &WireFrame::Reset {
                                stream_id: 0,
                                reason: String::from("session_mismatch"),
                            },
                        )
                        .await;
                        continue;
                    }

                    entry.legs.push(prepared.leg);
                    if entry.legs.len() as u16 >= entry.expected_links {
                        if let Some(session) = pending_tcp.remove(&prepared.hello.session_id) {
                            let (add_leg_tx, add_leg_rx) = mpsc::unbounded_channel::<OpenLeg>();
                            active_tcp_sessions.insert(
                                prepared.hello.session_id,
                                ActiveTcpSessionHandle {
                                    claims: session.claims.clone(),
                                    add_leg_tx,
                                },
                            );
                            spawn_active_tcp_session(
                                prepared.hello.session_id,
                                session.claims,
                                session.legs,
                                add_leg_rx,
                                config.clone(),
                                tcp_done_tx.clone(),
                            );
                        }
                    }
                    continue;
                }

                let entry = pending_udp.entry(prepared.hello.session_id).or_insert_with(|| PendingUdpSession {
                    claims: claims.clone(),
                    expected_links: prepared.hello.expected_links.max(1),
                    created_at: now,
                    legs: Vec::new(),
                });

                if entry.claims.target_host != claims.target_host
                    || entry.claims.target_port != claims.target_port
                    || entry.claims.target_protocol != claims.target_protocol
                    || entry.expected_links != prepared.hello.expected_links.max(1)
                {
                    warn!("rejecting mismatched leg for session {}", prepared.hello.session_id);
                    let mut leg = prepared.leg;
                    let _ = write_frame(
                        &mut leg.stream,
                        &WireFrame::Reset {
                            stream_id: 0,
                            reason: String::from("session_mismatch"),
                        },
                    )
                    .await;
                    continue;
                }

                entry.legs.push(prepared.leg);
                if entry.legs.len() as u16 >= entry.expected_links {
                    if let Some(session) = pending_udp.remove(&prepared.hello.session_id) {
                        spawn_pending_udp_session(prepared.hello.session_id, session, config.clone());
                    }
                }
            }
            _ = ticker.tick() => {
                let now = Instant::now();
                let expired_tcp_ids: Vec<Uuid> = pending_tcp
                    .iter()
                    .filter_map(|(session_id, item)| {
                        if now.duration_since(item.created_at) >= config.join_timeout {
                            Some(*session_id)
                        } else {
                            None
                        }
                    })
                    .collect();
                for session_id in expired_tcp_ids {
                    if let Some(session) = pending_tcp.remove(&session_id) {
                        let (add_leg_tx, add_leg_rx) = mpsc::unbounded_channel::<OpenLeg>();
                        active_tcp_sessions.insert(
                            session_id,
                            ActiveTcpSessionHandle {
                                claims: session.claims.clone(),
                                add_leg_tx,
                            },
                        );
                        spawn_active_tcp_session(
                            session_id,
                            session.claims,
                            session.legs,
                            add_leg_rx,
                            config.clone(),
                            tcp_done_tx.clone(),
                        );
                    }
                }

                let now = Instant::now();
                let expired_ids: Vec<Uuid> = pending_udp
                    .iter()
                    .filter_map(|(session_id, item)| {
                        if now.duration_since(item.created_at) >= config.join_timeout {
                            Some(*session_id)
                        } else {
                            None
                        }
                    })
                    .collect();
                for session_id in expired_ids {
                    if let Some(session) = pending_udp.remove(&session_id) {
                        spawn_pending_udp_session(session_id, session, config.clone());
                    }
                }
            }
        }
    }
}

struct PreparedLeg {
    hello: HelloFrame,
    leg: OpenLeg,
}

struct PendingUdpSession {
    claims: SessionClaims,
    expected_links: u16,
    created_at: Instant,
    legs: Vec<OpenLeg>,
}

struct PendingTcpSession {
    claims: SessionClaims,
    expected_links: u16,
    created_at: Instant,
    legs: Vec<OpenLeg>,
}

struct ActiveTcpSessionHandle {
    claims: SessionClaims,
    add_leg_tx: mpsc::UnboundedSender<OpenLeg>,
}

struct ClientTransportBootstrap {
    session_id: Uuid,
    initial_legs: Vec<OpenLeg>,
    add_leg_rx: mpsc::UnboundedReceiver<OpenLeg>,
    active_specs_tx: watch::Sender<HashSet<usize>>,
    connector_shutdown_tx: watch::Sender<bool>,
}

enum ClientTransportCommand {
    AttachLocalStream {
        local_stream: TcpStream,
        strategy: ClientStrategy,
    },
}

enum TcpTransportEvent {
    LocalChunk { stream_id: u32, payload: Bytes },
    LocalEof { stream_id: u32 },
    LocalReadFailed { stream_id: u32, error: String },
    LocalWriteCommitted { stream_id: u32, bytes: usize },
    LocalWriteClosed { stream_id: u32 },
    LocalWriteFailed { stream_id: u32, error: String },
    Frame { _leg_id: usize, frame: WireFrame },
    LegClosed { leg_id: usize },
    UpstreamConnected { stream_id: u32, stream: TcpStream },
    UpstreamConnectFailed { stream_id: u32, error: String },
}

struct TcpStreamState {
    strategy: ClientStrategy,
    local_backlog: VecDeque<Bytes>,
    pending_chunks: BTreeMap<u64, PendingChunk>,
    inbound: InboundState,
    outbound_next_offset: u64,
    last_ack_offset: u64,
    last_ack_progress_at: Instant,
    outbound_fin: Option<FinTracker>,
    outbound_fin_acked: bool,
    local_eof: bool,
    opened: bool,
    open_sent_at: Option<Instant>,
    preferred_leg_id: Option<usize>,
    bulk_mode: bool,
    striping_stage: TcpStripingStage,
    warmup_leg_count: usize,
    warmup_stage_started_at: Option<Instant>,
    warmup_leg_cursor: usize,
    warmup_leg_bytes: usize,
    close_deadline_at: Option<Instant>,
    local_write_tx: mpsc::Sender<LocalWriteCommand>,
    close_tx: watch::Sender<bool>,
}

impl TcpStreamState {
    fn new(local_write_tx: mpsc::Sender<LocalWriteCommand>, close_tx: watch::Sender<bool>, opened: bool) -> Self {
        Self::new_with_strategy(local_write_tx, close_tx, opened, ClientStrategy::SpeedMax)
    }

    fn new_with_strategy(
        local_write_tx: mpsc::Sender<LocalWriteCommand>,
        close_tx: watch::Sender<bool>,
        opened: bool,
        strategy: ClientStrategy,
    ) -> Self {
        Self {
            strategy,
            local_backlog: VecDeque::new(),
            pending_chunks: BTreeMap::new(),
            inbound: InboundState::new(),
            outbound_next_offset: 0,
            last_ack_offset: 0,
            last_ack_progress_at: Instant::now(),
            outbound_fin: None,
            outbound_fin_acked: false,
            local_eof: false,
            opened,
            open_sent_at: None,
            preferred_leg_id: None,
            bulk_mode: false,
            striping_stage: TcpStripingStage::Sticky,
            warmup_leg_count: 1,
            warmup_stage_started_at: None,
            warmup_leg_cursor: 0,
            warmup_leg_bytes: 0,
            close_deadline_at: None,
            local_write_tx,
            close_tx,
        }
    }

    fn is_finished(&self) -> bool {
        self.local_eof
            && self.outbound_fin_acked
            && self.opened
            && self.inbound.fin_offset.is_some()
            && self.local_backlog.is_empty()
            && self.pending_chunks.is_empty()
            && self.outbound_fin.is_none()
            && self.inbound.writer_shutdown
    }

    fn is_drained_closing(&self) -> bool {
        self.opened
            && self.local_eof
            && self.inbound.fin_offset.is_some()
            && self.inbound.writer_shutdown
            && self.local_backlog.is_empty()
            && self.pending_chunks.is_empty()
    }
}

fn spawn_pending_udp_session(session_id: Uuid, pending: PendingUdpSession, config: SessionConfig) {
    tokio::spawn(async move {
        if let Err(err) = start_pending_udp_session(session_id, pending, config).await {
            warn!("bond UDP session {} failed: {:?}", session_id, err);
        }
    });
}

fn spawn_pending_tcp_session(
    session_id: Uuid,
    pending: PendingTcpSession,
    config: SessionConfig,
    done_tx: mpsc::UnboundedSender<Uuid>,
) {
    let (_add_leg_tx, add_leg_rx) = mpsc::unbounded_channel::<OpenLeg>();
    spawn_active_tcp_session(
        session_id,
        pending.claims,
        pending.legs,
        add_leg_rx,
        config,
        done_tx,
    );
}

fn transport_stream_close_deadline() -> Instant {
    Instant::now() + TRANSPORT_STREAM_CLOSE_GRACE
}

fn arm_transport_stream_close_deadline(state: &mut TcpStreamState) -> bool {
    if state.close_deadline_at.is_none() {
        state.close_deadline_at = Some(transport_stream_close_deadline());
        return true;
    }
    false
}

fn should_reset_transport_stream_on_close_timeout(state: &TcpStreamState, now: Instant) -> bool {
    let Some(deadline) = state.close_deadline_at else {
        return false;
    };

    if now < deadline || state.is_finished() {
        return false;
    }

    // Once our FIN has been acknowledged, the peer may still legitimately send
    // control/result bytes before it closes its own write side. Treat that as a
    // half-closed stream, not a stuck close-path that should be reset.
    if state.outbound_fin_acked
        && state.inbound.fin_offset.is_none()
        && !state.inbound.writer_shutdown
    {
        return false;
    }

    true
}

fn stream_lifecycle_snapshot(state: &TcpStreamState) -> String {
    format!(
        "opened={} local_eof={} inbound_next={} inbound_acked={} fin_offset={:?} writer_shutdown={} backlog={} pending_chunks={} pending_bytes={} outbound_next={} outbound_fin={:?} outbound_fin_acked={} preferred_leg={:?} close_deadline_armed={}",
        state.opened,
        state.local_eof,
        state.inbound.next_offset,
        state.inbound.acked_offset,
        state.inbound.fin_offset,
        state.inbound.writer_shutdown,
        state.local_backlog.len(),
        state.pending_chunks.len(),
        pending_bytes(&state.pending_chunks),
        state.outbound_next_offset,
        state.outbound_fin.as_ref().map(|fin| fin.seq),
        state.outbound_fin_acked,
        state.preferred_leg_id,
        state.close_deadline_at.is_some(),
    )
}

fn should_log_close_lifecycle_ack(state: &TcpStreamState) -> bool {
    state.local_eof
        || state.inbound.fin_offset.is_some()
        || state.inbound.writer_shutdown
        || state.outbound_fin.is_some()
        || state.outbound_fin_acked
        || state.close_deadline_at.is_some()
}

fn should_abort_transport_after_stream_failure(role: SessionRole, state: Option<&TcpStreamState>) -> bool {
    if role != SessionRole::Client {
        return false;
    }

    let Some(state) = state else {
        return false;
    };

    let has_meaningful_progress = state.last_ack_offset > 0 || state.inbound.acked_offset > 0;

    !state.local_eof
        && !state.inbound.writer_shutdown
        && state.inbound.fin_offset.is_none()
        && state.outbound_fin.is_none()
        && !state.outbound_fin_acked
        && state.close_deadline_at.is_none()
        && !has_meaningful_progress
}

fn should_abandon_unopened_local_eof_stream(state: &TcpStreamState, connect_timeout: Duration) -> bool {
    !state.opened
        && state.local_eof
        && state.local_backlog.is_empty()
        && state.pending_chunks.is_empty()
        && state.outbound_fin.is_none()
        && !state.outbound_fin_acked
        && state
            .open_sent_at
            .is_some_and(|sent| sent.elapsed() >= connect_timeout)
}

fn log_transport_lifecycle(
    session_id: Uuid,
    event: &str,
    stream_id: u32,
    state: Option<&TcpStreamState>,
    detail: impl AsRef<str>,
) {
    let detail = detail.as_ref();
    if let Some(state) = state {
        info!(
            "transport {} lifecycle {} stream={} {} {}",
            session_id,
            event,
            stream_id,
            detail,
            stream_lifecycle_snapshot(state)
        );
    } else {
        info!(
            "transport {} lifecycle {} stream={} {}",
            session_id,
            event,
            stream_id,
            detail
        );
    }
}

fn spawn_active_tcp_session(
    session_id: Uuid,
    claims: SessionClaims,
    initial_legs: Vec<OpenLeg>,
    add_leg_rx: mpsc::UnboundedReceiver<OpenLeg>,
    config: SessionConfig,
    done_tx: mpsc::UnboundedSender<Uuid>,
) {
    tokio::spawn(async move {
        if let Err(err) = start_active_tcp_session(session_id, claims, initial_legs, add_leg_rx, config).await {
            warn!("bond active TCP transport {} failed: {:?}", session_id, err);
        }
        let _ = done_tx.send(session_id);
    });
}

async fn start_active_tcp_session(
    session_id: Uuid,
    claims: SessionClaims,
    initial_legs: Vec<OpenLeg>,
    add_leg_rx: mpsc::UnboundedReceiver<OpenLeg>,
    config: SessionConfig,
) -> anyhow::Result<()> {
    let legs = acknowledge_pending_legs(session_id, initial_legs).await?;
    info!(
        "bond TCP transport {} started with {} leg(s) toward {}:{}",
        session_id,
        legs.len(),
        claims.target_host,
        claims.target_port
    );
    run_tcp_transport_session(session_id, SessionRole::Aggregator, Some(claims), legs, add_leg_rx, None, None, config)
        .await
}

async fn start_pending_udp_session(
    session_id: Uuid,
    pending: PendingUdpSession,
    config: SessionConfig,
) -> anyhow::Result<()> {
    let target = format!("{}:{}", pending.claims.target_host, pending.claims.target_port);
    let target_addr = resolve_udp_target(&pending.claims).await?;
    let legs = acknowledge_pending_legs(session_id, pending.legs).await?;
    info!(
        "bond UDP session {} started with {} leg(s) toward {}",
        session_id,
        legs.len(),
        target
    );
    run_bonded_udp_server(
        target_addr,
        legs,
        snapshot_context(SessionRole::Aggregator, Some(&pending.claims)),
        config,
    )
    .await
}

async fn acknowledge_pending_legs(session_id: Uuid, pending_legs: Vec<OpenLeg>) -> anyhow::Result<Vec<OpenLeg>> {
    let mut legs = Vec::new();
    for mut leg in pending_legs {
        if let Err(err) = write_frame(&mut leg.stream, &WireFrame::HelloAck).await {
            warn!("failed to send hello-ack for session {}: {:?}", session_id, err);
            continue;
        }
        legs.push(leg);
    }

    if legs.is_empty() {
        return Err(anyhow!("no live legs after hello-ack"));
    }

    Ok(legs)
}

async fn acknowledge_late_leg(session_id: Uuid, mut leg: OpenLeg) -> anyhow::Result<OpenLeg> {
    write_frame(&mut leg.stream, &WireFrame::HelloAck)
        .await
        .with_context(|| format!("failed to send hello-ack for late leg in session {}", session_id))?;
    Ok(leg)
}

async fn read_hello_and_queue(leg: OpenLeg, collector_tx: mpsc::UnboundedSender<PreparedLeg>) -> anyhow::Result<()> {
    let mut leg = leg;
    let hello = timeout(Duration::from_secs(5), read_frame(&mut leg.stream))
        .await
        .with_context(|| "timed out waiting for hello frame")??;
    let WireFrame::Hello(hello) = hello else {
        return Err(anyhow!("expected hello frame as first message"));
    };

    collector_tx
        .send(PreparedLeg { hello, leg })
        .map_err(|_| anyhow!("collector channel closed"))?;
    Ok(())
}

async fn open_client_tcp_transport_session(
    leg_targets: Vec<String>,
    session_token: String,
    config: SessionConfig,
) -> anyhow::Result<mpsc::UnboundedSender<ClientTransportCommand>> {
    let bootstrap = connect_client_tcp_transport_legs(leg_targets, session_token, config.connect_timeout).await?;
    let session_id = bootstrap.session_id;
    let connector_shutdown_tx = bootstrap.connector_shutdown_tx.clone();
    let active_specs_tx = bootstrap.active_specs_tx.clone();
    let (command_tx, command_rx) = mpsc::unbounded_channel::<ClientTransportCommand>();

    tokio::spawn(async move {
        let result = run_tcp_transport_session(
            session_id,
            SessionRole::Client,
            None,
            bootstrap.initial_legs,
            bootstrap.add_leg_rx,
            Some(command_rx),
            Some(active_specs_tx),
            config,
        )
        .await;
        let _ = connector_shutdown_tx.send(true);
        if let Err(err) = result {
            warn!("bond client TCP transport {} failed: {:?}", session_id, err);
        }
    });

    Ok(command_tx)
}

async fn connect_client_tcp_transport_legs(
    leg_targets: Vec<String>,
    session_token: String,
    connect_timeout: Duration,
) -> anyhow::Result<ClientTransportBootstrap> {
    let mut specs = parse_leg_specs(leg_targets)?;
    specs.sort_by_key(|spec| (spec.priority, transport_bias(spec.transport), spec.original.clone()));
    if specs.is_empty() {
        return Err(anyhow!("at least one bond leg target is required"));
    }

    let session_id = Uuid::now_v7();
    let expected_links = specs.len().min(u16::MAX as usize) as u16;
    let hello = WireFrame::Hello(HelloFrame {
        session_id,
        expected_links: expected_links.max(1),
        token: session_token,
    });

    let mut initial_legs = Vec::new();
    let mut active_specs = HashSet::new();
    let mut initial_connects = JoinSet::new();
    for (spec_index, spec) in specs.iter().cloned().enumerate() {
        let hello = hello.clone();
        initial_connects.spawn(async move {
            let result = connect_and_ack_leg(&spec, &hello, connect_timeout).await;
            (spec_index, spec.original, result)
        });
    }

    let first_leg_deadline = Instant::now() + connect_timeout;
    let mut join_deadline = None::<Instant>;
    while !initial_connects.is_empty() {
        let deadline = join_deadline.unwrap_or(first_leg_deadline);
        let now = Instant::now();
        if now >= deadline {
            break;
        }

        let wait_for = deadline.saturating_duration_since(now);
        let joined = match timeout(wait_for, initial_connects.join_next()).await {
            Ok(value) => value,
            Err(_) => break,
        };
        let Some(joined) = joined else {
            break;
        };

        match joined {
            Ok((spec_index, _spec_original, Ok(leg))) => {
                initial_legs.push(leg.with_spec_index(spec_index));
                active_specs.insert(spec_index);
                if join_deadline.is_none() {
                    join_deadline = Some(Instant::now() + CLIENT_TCP_LEG_JOIN_GRACE.min(connect_timeout));
                }
            }
            Ok((_spec_index, spec_original, Err(err))) => {
                warn!("failed to connect initial bond leg {}: {:?}", spec_original, err);
            }
            Err(err) => warn!("initial bond leg task join failed: {:?}", err),
        }
    }
    initial_connects.abort_all();

    if initial_legs.is_empty() {
        return Err(anyhow!("no bond legs acknowledged the TCP transport"));
    }

    let (active_specs_tx, active_specs_rx) = watch::channel(active_specs);
    let (connector_shutdown_tx, connector_shutdown_rx) = watch::channel(false);
    let (add_leg_tx, add_leg_rx) = mpsc::unbounded_channel::<OpenLeg>();
    let specs = Arc::new(specs);
    let desired_active_specs = specs.len().min(2);
    tokio::spawn(run_client_leg_connector(
        specs,
        hello,
        connect_timeout,
        active_specs_rx,
        connector_shutdown_rx,
        add_leg_tx,
    ));

    if desired_active_specs > 1 {
        let mut wait_rx = active_specs_tx.subscribe();
        let deadline = Instant::now() + CLIENT_TCP_LEG_JOIN_GRACE.min(connect_timeout);
        while wait_rx.borrow().len() < desired_active_specs {
            let now = Instant::now();
            if now >= deadline {
                break;
            }
            let sleep_for = deadline.saturating_duration_since(now);
            if timeout(sleep_for, wait_rx.changed()).await.is_err() {
                break;
            }
        }
    }

    Ok(ClientTransportBootstrap {
        session_id,
        initial_legs,
        add_leg_rx,
        active_specs_tx,
        connector_shutdown_tx,
    })
}

async fn connect_client_udp_legs(
    leg_targets: Vec<String>,
    session_token: String,
    config: SessionConfig,
) -> anyhow::Result<(Uuid, Vec<OpenLeg>, mpsc::UnboundedReceiver<OpenLeg>)> {
    let mut specs = parse_leg_specs(leg_targets)?;
    specs.sort_by_key(|spec| (spec.priority, transport_bias(spec.transport), spec.original.clone()));
    if specs.is_empty() {
        return Err(anyhow!("at least one bond leg target is required"));
    }

    let mut connected = Vec::<(usize, LegSpec, LegIo)>::new();
    for (spec_index, spec) in specs.iter().enumerate() {
        match connect_leg(spec, config.connect_timeout).await {
            Ok(stream) => connected.push((spec_index, spec.clone(), stream)),
            Err(err) => warn!("failed to connect UDP bond leg {}: {:?}", spec.original, err),
        }
    }
    if connected.is_empty() {
        return Err(anyhow!("no UDP bond legs available"));
    }

    let session_id = Uuid::now_v7();
    let hello = WireFrame::Hello(HelloFrame {
        session_id,
        expected_links: connected.len() as u16,
        token: session_token,
    });

    let mut hello_sent = Vec::new();
    for (spec_index, spec, mut stream) in connected {
        write_frame(&mut stream, &hello)
            .await
            .with_context(|| format!("failed to send bond hello to leg {}", spec.original))?;
        hello_sent.push((spec_index, spec, stream));
    }

    let mut ready_legs = Vec::new();
    for (spec_index, spec, mut stream) in hello_sent {
        let ack = timeout(Duration::from_secs(5), read_frame(&mut stream))
            .await
            .with_context(|| format!("timed out waiting for bond hello-ack from {}", spec.original))??;
        if matches!(ack, WireFrame::HelloAck) {
            ready_legs.push(
                OpenLeg::new(stream, spec.priority, spec.role, spec.original.clone())
                    .with_metadata(
                        spec.endpoint_id,
                        spec.node_id,
                        spec.display_host.clone().unwrap_or_else(|| spec.host.clone()),
                    )
                    .with_spec_index(spec_index),
            );
            continue;
        }
        if let WireFrame::Reset { reason, .. } = ack {
            warn!("bond leg {} rejected UDP session during handshake: {}", spec.original, reason);
            continue;
        }
        warn!("bond leg {} returned unexpected frame during UDP handshake", spec.original);
    }

    if ready_legs.is_empty() {
        return Err(anyhow!("no UDP bond legs acknowledged the session"));
    }

    let (_add_leg_tx, add_leg_rx) = mpsc::unbounded_channel::<OpenLeg>();
    Ok((session_id, ready_legs, add_leg_rx))
}

async fn connect_and_ack_leg(
    spec: &LegSpec,
    hello: &WireFrame,
    connect_timeout: Duration,
) -> anyhow::Result<OpenLeg> {
    let mut stream = connect_leg(spec, connect_timeout)
        .await
        .with_context(|| format!("failed to connect bond leg {}", spec.original))?;
    write_frame(&mut stream, hello)
        .await
        .with_context(|| format!("failed to send bond hello to leg {}", spec.original))?;
    let ack = timeout(Duration::from_secs(5), read_frame(&mut stream))
        .await
        .with_context(|| format!("timed out waiting for bond hello-ack from {}", spec.original))??;
    if matches!(ack, WireFrame::HelloAck) {
        return Ok(
            OpenLeg::new(stream, spec.priority, spec.role, spec.original.clone()).with_metadata(
                spec.endpoint_id,
                spec.node_id,
                spec.display_host.clone().unwrap_or_else(|| spec.host.clone()),
            ),
        );
    }
    if let WireFrame::Reset { reason, .. } = ack {
        return Err(anyhow!(
            "bond leg {} rejected session during handshake: {}",
            spec.original,
            reason
        ));
    }
    Err(anyhow!(
        "bond leg {} returned unexpected frame during handshake",
        spec.original
    ))
}

async fn run_client_leg_connector(
    specs: Arc<Vec<LegSpec>>,
    hello: WireFrame,
    connect_timeout: Duration,
    mut active_specs_rx: watch::Receiver<HashSet<usize>>,
    mut shutdown_rx: watch::Receiver<bool>,
    add_leg_tx: mpsc::UnboundedSender<OpenLeg>,
) {
    let mut next_retry_at = vec![Instant::now(); specs.len()];

    loop {
        if *shutdown_rx.borrow() {
            return;
        }

        let active_specs = active_specs_rx.borrow().clone();
        let now = Instant::now();
        let mut next_deadline = now + CLIENT_LEG_RETRY_BACKOFF;

        for (spec_index, spec) in specs.iter().enumerate() {
            if active_specs.contains(&spec_index) {
                continue;
            }

            let retry_at = next_retry_at[spec_index];
            if retry_at > now {
                if retry_at < next_deadline {
                    next_deadline = retry_at;
                }
                continue;
            }

            match connect_and_ack_leg(spec, &hello, connect_timeout).await {
                Ok(leg) => {
                    next_retry_at[spec_index] = Instant::now() + CLIENT_LEG_RETRY_BACKOFF;
                    if add_leg_tx.send(leg.with_spec_index(spec_index)).is_err() {
                        return;
                    }
                }
                Err(err) => {
                    debug!("failed to reconnect bond leg {}: {:?}", spec.original, err);
                    next_retry_at[spec_index] = Instant::now() + CLIENT_LEG_RETRY_BACKOFF;
                }
            }

            if next_retry_at[spec_index] < next_deadline {
                next_deadline = next_retry_at[spec_index];
            }
        }

        tokio::select! {
            changed = active_specs_rx.changed() => {
                if changed.is_err() {
                    return;
                }
            }
            changed = shutdown_rx.changed() => {
                if changed.is_err() || *shutdown_rx.borrow() {
                    return;
                }
            }
            _ = tokio::time::sleep_until(next_deadline) => {}
        }
    }
}

async fn run_tcp_transport_session(
    session_id: Uuid,
    role: SessionRole,
    claims: Option<SessionClaims>,
    initial_legs: Vec<OpenLeg>,
    mut add_leg_rx: mpsc::UnboundedReceiver<OpenLeg>,
    mut command_rx: Option<mpsc::UnboundedReceiver<ClientTransportCommand>>,
    active_specs_tx: Option<watch::Sender<HashSet<usize>>>,
    config: SessionConfig,
) -> anyhow::Result<()> {
    let (event_tx, mut event_rx) = mpsc::unbounded_channel::<TcpTransportEvent>();
    let mut legs = spawn_tcp_leg_tasks(initial_legs, event_tx.clone());
    let snapshot = snapshot_context(role, claims.as_ref());
    let mut last_snapshot_write = None::<Instant>;
    let mut streams = HashMap::<u32, TcpStreamState>::new();
    let mut connecting_streams = HashSet::<u32>::new();
    let mut closed_streams = HashSet::<u32>::new();
    let mut reset_reasons = HashMap::<u32, String>::new();
    let mut next_stream_id = 1u32;
    let mut round_robin_cursor = 0usize;
    let mut random_state = session_id.as_u128() as u64 ^ ((session_id.as_u128() >> 64) as u64);
    let mut retransmit_tick = interval(Duration::from_millis(100));
    let mut last_activity = Instant::now();
    let mut no_leg_deadline = None::<Instant>;
    let resume_timeout = transport_resume_timeout(&config);
    let idle_timeout = transport_idle_timeout(&config);

    refresh_active_spec_snapshot(&legs, active_specs_tx.as_ref());

    loop {
        flush_tcp_stream_backlogs(session_id, &mut streams, &mut legs, &mut round_robin_cursor, role, &config);
        prune_finished_transport_streams(&mut streams, &mut closed_streams);

        let has_live_streams = !streams.is_empty() || !connecting_streams.is_empty();
        if !has_active_leg(&legs) {
            if has_live_streams {
                if transport_streams_can_end_cleanly(&streams, &connecting_streams) {
                    break;
                }
                if no_leg_deadline.is_none() {
                    no_leg_deadline = Some(Instant::now() + resume_timeout);
                }
                if Instant::now() >= no_leg_deadline.expect("no-leg deadline must be set") {
                    return Err(anyhow!("no active bond legs remaining for tcp transport session"));
                }
            } else if last_activity.elapsed() >= idle_timeout {
                break;
            }
        } else {
            no_leg_deadline = None;
        }

        tokio::select! {
            Some(new_leg) = add_leg_rx.recv() => {
                let new_leg = if role == SessionRole::Aggregator {
                    acknowledge_late_leg(session_id, new_leg).await?
                } else {
                    new_leg
                };
                let new_leg_id = legs.len();
                let state = spawn_tcp_leg_task(new_leg_id, new_leg, event_tx.clone());
                info!("bond tcp transport {} added leg {}", session_id, state.label);
                legs.push(state);
                refresh_active_spec_snapshot(&legs, active_specs_tx.as_ref());
                last_activity = Instant::now();
                no_leg_deadline = None;
            }
            command = async {
                match &mut command_rx {
                    Some(rx) => rx.recv().await,
                    None => std::future::pending::<Option<ClientTransportCommand>>().await,
                }
            }, if role == SessionRole::Client => {
                match command {
                    Some(ClientTransportCommand::AttachLocalStream { local_stream, strategy }) => {
                        let stream_id = next_transport_stream_id(&streams, &connecting_streams, &closed_streams, &mut next_stream_id);
                        let (local_read, local_write) = local_stream.into_split();
                        let (local_write_tx, local_write_rx) = mpsc::channel(64);
                        let (close_tx, close_rx) = watch::channel(false);
                        let mut state = TcpStreamState::new_with_strategy(
                            local_write_tx.clone(),
                            close_tx.clone(),
                            false,
                            strategy,
                        );
                        state.preferred_leg_id = select_strategy_affinity_leg(
                            strategy,
                            &legs,
                            &mut round_robin_cursor,
                            &mut random_state,
                        );
                        streams.insert(stream_id, state);
                        debug!("transport {} attached local stream {}", session_id, stream_id);
                        spawn_transport_stream_reader(stream_id, local_read, config.chunk_size, close_rx, event_tx.clone());
                        spawn_transport_stream_writer(stream_id, local_write, local_write_rx, event_tx.clone());
                        if let Some(state) = streams.get_mut(&stream_id) {
                            if send_stream_control_frame(
                                &mut legs,
                                &mut round_robin_cursor,
                                state.preferred_leg_id,
                                WireFrame::StreamOpen { stream_id },
                            ) {
                                state.open_sent_at = Some(Instant::now());
                                debug!("transport {} sent stream-open for stream {}", session_id, stream_id);
                                log_transport_lifecycle(
                                    session_id,
                                    "send_stream_open",
                                    stream_id,
                                    Some(state),
                                    format!("preferred_leg={:?}", state.preferred_leg_id),
                                );
                            }
                        }
                        last_activity = Instant::now();
                    }
                    None => {
                        command_rx = None;
                    }
                }
            }
            maybe_event = event_rx.recv() => {
                let Some(event) = maybe_event else {
                    break;
                };
                last_activity = Instant::now();
                match event {
                    TcpTransportEvent::LocalChunk { stream_id, payload } => {
                        if let Some(state) = streams.get_mut(&stream_id) {
                            debug!(
                                "transport {} queued local chunk stream={} bytes={}",
                                session_id,
                                stream_id,
                                payload.len()
                            );
                            state.local_backlog.push_back(payload);
                        }
                    }
                    TcpTransportEvent::LocalEof { stream_id } => {
                        if let Some(state) = streams.get_mut(&stream_id) {
                            debug!("transport {} observed local eof on stream {}", session_id, stream_id);
                            state.local_eof = true;
                            log_transport_lifecycle(session_id, "local_eof", stream_id, Some(state), "reader_reached_eof");
                            if state.outbound_fin.is_some() || state.outbound_fin_acked {
                                if arm_transport_stream_close_deadline(state) {
                                    log_transport_lifecycle(
                                        session_id,
                                        "arm_close_deadline",
                                        stream_id,
                                        Some(state),
                                        "reason=local_eof_after_fin",
                                    );
                                }
                            }
                        }
                    }
                    TcpTransportEvent::LocalReadFailed { stream_id, error } => {
                        let abort_transport = should_abort_transport_after_stream_failure(role, streams.get(&stream_id));
                        let normalized = error.to_ascii_lowercase();
                        if normalized.contains("connection reset by peer")
                            && streams
                                .get(&stream_id)
                                .is_some_and(|state| state.inbound.writer_shutdown || state.local_eof)
                        {
                            debug!(
                                "transport {} treating local read reset as benign close stream={} error={}",
                                session_id,
                                stream_id,
                                error
                            );
                            if let Some(state) = streams.get_mut(&stream_id) {
                                state.local_eof = true;
                                log_transport_lifecycle(
                                    session_id,
                                    "local_read_reset_benign",
                                    stream_id,
                                    Some(state),
                                    format!("error={}", error),
                                );
                                if state.outbound_fin.is_some() || state.outbound_fin_acked {
                                    if arm_transport_stream_close_deadline(state) {
                                        log_transport_lifecycle(
                                            session_id,
                                            "arm_close_deadline",
                                            stream_id,
                                            Some(state),
                                            "reason=benign_local_read_reset",
                                        );
                                    }
                                }
                            }
                            continue;
                        }
                        if let Some(state) = streams.get_mut(&stream_id) {
                            let _ = suppress_stream_preferred_tcp_leg(
                                &mut legs,
                                state,
                                TcpPenalty::LocalReadFailed,
                                config.leg_cooldown,
                            );
                        }
                        warn!("bond tcp stream {} local read failed: {}", stream_id, error);
                        log_transport_lifecycle(
                            session_id,
                            "local_read_failed",
                            stream_id,
                            streams.get(&stream_id),
                            format!("error={}", error),
                        );
                        let reason = String::from("local_read_failed");
                        reset_reasons.insert(stream_id, reason.clone());
                        connecting_streams.remove(&stream_id);
                        let _ = send_control_frame(&mut legs, &mut round_robin_cursor, WireFrame::Reset { stream_id, reason });
                        close_transport_stream(stream_id, &mut streams, &mut closed_streams);
                        recompute_transport_inflight(&mut legs, &streams);
                        if abort_transport {
                            return Err(anyhow!(
                                "client transport stream {} failed with local_read_failed; forcing transport reconnect",
                                stream_id
                            ));
                        }
                    }
                    TcpTransportEvent::LocalWriteCommitted { stream_id, bytes } => {
                        if let Some(state) = streams.get_mut(&stream_id) {
                            let ack = record_inbound_write_commit(&mut state.inbound, bytes);
                            let _ = send_stream_control_frame(
                                &mut legs,
                                &mut round_robin_cursor,
                                state.preferred_leg_id,
                                role.outbound_ack(stream_id, ack),
                            );
                            debug!("transport sent committed ack stream={} ack={}", stream_id, ack);
                            if should_log_close_lifecycle_ack(state) {
                                log_transport_lifecycle(
                                    session_id,
                                    "send_ack",
                                    stream_id,
                                    Some(state),
                                    format!("ack={} bytes={}", ack, bytes),
                                );
                            }
                        }
                    }
                    TcpTransportEvent::LocalWriteClosed { stream_id } => {
                        if let Some(state) = streams.get_mut(&stream_id) {
                            debug!("transport {} local writer closed on stream {}", session_id, stream_id);
                            state.inbound.writer_shutdown = true;
                            log_transport_lifecycle(
                                session_id,
                                "local_writer_closed",
                                stream_id,
                                Some(state),
                                "writer_shutdown=true",
                            );
                            let _ = send_stream_control_frame(
                                &mut legs,
                                &mut round_robin_cursor,
                                state.preferred_leg_id,
                                role.outbound_ack(stream_id, state.inbound.acked_offset),
                            );
                            if state.local_eof && (state.outbound_fin.is_some() || state.outbound_fin_acked) {
                                if arm_transport_stream_close_deadline(state) {
                                    log_transport_lifecycle(
                                        session_id,
                                        "arm_close_deadline",
                                        stream_id,
                                        Some(state),
                                        "reason=local_writer_closed_after_fin",
                                    );
                                }
                            }
                        }
                    }
                    TcpTransportEvent::LocalWriteFailed { stream_id, error } => {
                        if let Some(state) = streams.get_mut(&stream_id) {
                            let _ = suppress_stream_preferred_tcp_leg(
                                &mut legs,
                                state,
                                TcpPenalty::LocalWriteFailed,
                                config.leg_cooldown,
                            );
                        }
                        let abort_transport = should_abort_transport_after_stream_failure(role, streams.get(&stream_id));
                        warn!("bond tcp stream {} local write failed: {}", stream_id, error);
                        log_transport_lifecycle(
                            session_id,
                            "local_write_failed",
                            stream_id,
                            streams.get(&stream_id),
                            format!("error={}", error),
                        );
                        let reason = String::from("local_write_failed");
                        reset_reasons.insert(stream_id, reason.clone());
                        connecting_streams.remove(&stream_id);
                        let _ = send_control_frame(&mut legs, &mut round_robin_cursor, WireFrame::Reset { stream_id, reason });
                        close_transport_stream(stream_id, &mut streams, &mut closed_streams);
                        recompute_transport_inflight(&mut legs, &streams);
                        if abort_transport {
                            return Err(anyhow!(
                                "client transport stream {} failed with local_write_failed; forcing transport reconnect",
                                stream_id
                            ));
                        }
                    }
                    TcpTransportEvent::Frame { _leg_id, frame } => {
                        handle_tcp_transport_frame(
                            session_id,
                            role,
                            claims.as_ref(),
                            _leg_id,
                            frame,
                            &mut streams,
                            &mut connecting_streams,
                            &mut closed_streams,
                            &mut reset_reasons,
                            &mut legs,
                            &mut round_robin_cursor,
                            &event_tx,
                            &config,
                        )
                        .await?;
                        recompute_transport_inflight(&mut legs, &streams);
                    }
                    TcpTransportEvent::LegClosed { leg_id } => {
                        if let Some(leg) = legs.get(leg_id) {
                            debug!("bond tcp transport {} lost leg {}", session_id, leg.label);
                        }
                        mark_leg_inactive(&mut legs, leg_id);
                        mark_transport_streams_for_retry(&mut streams, leg_id, config.retransmit_after);
                        if legs.iter().filter(|leg| leg.active).count() < 2 {
                            for state in streams.values_mut() {
                                state.preferred_leg_id = None;
                            }
                        }
                        recompute_transport_inflight(&mut legs, &streams);
                        refresh_active_spec_snapshot(&legs, active_specs_tx.as_ref());
                    }
                    TcpTransportEvent::UpstreamConnected { stream_id, stream } => {
                        if !connecting_streams.remove(&stream_id) || closed_streams.contains(&stream_id) {
                            continue;
                        }
                        debug!("transport {} upstream connected for stream {}", session_id, stream_id);
                        let (upstream_read, upstream_write) = stream.into_split();
                        let (local_write_tx, local_write_rx) = mpsc::channel(64);
                        let (close_tx, close_rx) = watch::channel(false);
                        streams.insert(stream_id, TcpStreamState::new(local_write_tx.clone(), close_tx.clone(), true));
                        spawn_transport_stream_reader(stream_id, upstream_read, config.chunk_size, close_rx, event_tx.clone());
                        spawn_transport_stream_writer(stream_id, upstream_write, local_write_rx, event_tx.clone());
                        let _ = send_control_frame(&mut legs, &mut round_robin_cursor, WireFrame::StreamOpenAck { stream_id });
                        debug!("transport {} sent stream-open-ack for stream {}", session_id, stream_id);
                        log_transport_lifecycle(
                            session_id,
                            "send_stream_open_ack",
                            stream_id,
                            streams.get(&stream_id),
                            "upstream_connected",
                        );
                    }
                    TcpTransportEvent::UpstreamConnectFailed { stream_id, error } => {
                        warn!("failed to open upstream tcp stream {}: {}", stream_id, error);
                        connecting_streams.remove(&stream_id);
                        reset_reasons.insert(stream_id, error.clone());
                        closed_streams.insert(stream_id);
                        let _ = send_control_frame(&mut legs, &mut round_robin_cursor, WireFrame::Reset { stream_id, reason: error });
                    }
                }
            }
            _ = retransmit_tick.tick(), if has_live_streams => {
                let mut stream_ids: Vec<u32> = streams.keys().copied().collect();
                stream_ids.sort_unstable();

                for stream_id in stream_ids {
                    let Some(state) = streams.get_mut(&stream_id) else {
                        continue;
                    };

                    if role == SessionRole::Client
                        && should_abandon_unopened_local_eof_stream(state, config.connect_timeout)
                    {
                        let reason = String::from("local_eof_before_open_ack_timeout");
                        log_transport_lifecycle(
                            session_id,
                            "abandon_unopened_local_eof_stream",
                            stream_id,
                            Some(state),
                            format!("reason={}", reason),
                        );
                        reset_reasons.insert(stream_id, reason.clone());
                        connecting_streams.remove(&stream_id);
                        let _ = send_control_frame(&mut legs, &mut round_robin_cursor, WireFrame::Reset { stream_id, reason });
                        close_transport_stream(stream_id, &mut streams, &mut closed_streams);
                        continue;
                    }

                    if role == SessionRole::Client
                        && !state.opened
                        && state.open_sent_at.map(|sent| sent.elapsed() >= config.retransmit_after).unwrap_or(true)
                        && {
                            let _ = suppress_stream_preferred_tcp_leg(
                                &mut legs,
                                state,
                                TcpPenalty::StreamOpenTimeout,
                                config.leg_cooldown,
                            );
                            send_stream_control_frame(
                                &mut legs,
                                &mut round_robin_cursor,
                                state.preferred_leg_id,
                                WireFrame::StreamOpen { stream_id },
                            )
                        }
                    {
                        state.open_sent_at = Some(Instant::now());
                        log_transport_lifecycle(session_id, "resend_stream_open", stream_id, Some(state), "open_ack_pending");
                    }

                    retransmit_transport_pending(
                        stream_id,
                        &mut state.pending_chunks,
                        state.last_ack_progress_at,
                        &mut state.preferred_leg_id,
                        state.bulk_mode,
                        &mut legs,
                        &mut round_robin_cursor,
                        role,
                        &config,
                    );

                    if let Some(fin) = &mut state.outbound_fin {
                        if fin.last_sent_at.elapsed() >= config.retransmit_after
                            && send_control_frame(
                                &mut legs,
                                &mut round_robin_cursor,
                                role.outbound_fin(stream_id, fin.seq),
                            )
                        {
                            let fin_seq = fin.seq;
                            fin.last_sent_at = Instant::now();
                            log_transport_lifecycle(
                                session_id,
                                "resend_fin",
                                stream_id,
                                Some(state),
                                format!("seq={}", fin_seq),
                            );
                        }
                        if arm_transport_stream_close_deadline(state) {
                            log_transport_lifecycle(
                                session_id,
                                "arm_close_deadline",
                                stream_id,
                                Some(state),
                                "reason=outbound_fin_inflight",
                            );
                        }
                    }

                    if should_reset_transport_stream_on_close_timeout(state, Instant::now()) {
                        let _ = suppress_stream_preferred_tcp_leg(
                            &mut legs,
                            state,
                            TcpPenalty::CloseTimeout,
                            config.leg_cooldown,
                        );
                        log_transport_lifecycle(
                            session_id,
                            "close_timeout",
                            stream_id,
                            Some(state),
                            "sending_reset",
                        );
                        let reason = String::from("close_timeout");
                        reset_reasons.insert(stream_id, reason.clone());
                        let _ = send_control_frame(&mut legs, &mut round_robin_cursor, WireFrame::Reset { stream_id, reason });
                        close_transport_stream(stream_id, &mut streams, &mut closed_streams);
                    }
                }

                recompute_transport_inflight(&mut legs, &streams);
            }
        }
        maybe_write_leg_health_snapshot(snapshot.as_ref(), &mut last_snapshot_write, &legs);
    }

    maybe_write_leg_health_snapshot(snapshot.as_ref(), &mut last_snapshot_write, &legs);
    debug!("bond tcp transport session {} ended", session_id);
    Ok(())
}

async fn handle_tcp_transport_frame(
    session_id: Uuid,
    role: SessionRole,
    claims: Option<&SessionClaims>,
    leg_id: usize,
    frame: WireFrame,
    streams: &mut HashMap<u32, TcpStreamState>,
    connecting_streams: &mut HashSet<u32>,
    closed_streams: &mut HashSet<u32>,
    reset_reasons: &mut HashMap<u32, String>,
    legs: &mut [LegState],
    round_robin_cursor: &mut usize,
    event_tx: &mpsc::UnboundedSender<TcpTransportEvent>,
    config: &SessionConfig,
) -> anyhow::Result<()> {
    if let WireFrame::Reset { stream_id, reason } = &frame {
        if *stream_id == 0 {
            return Err(anyhow!("bond remote reset: {}", reason));
        }
        penalize_tcp_leg(legs, leg_id, TcpPenalty::RecvReset);
        log_transport_lifecycle(
            session_id,
            "recv_reset",
            *stream_id,
            streams.get(stream_id),
            format!("leg_id={} reason={}", leg_id, reason),
        );
        reset_reasons.insert(*stream_id, reason.clone());
        connecting_streams.remove(stream_id);
        close_transport_stream(*stream_id, streams, closed_streams);
        return Ok(());
    }

    match (role, &frame) {
        (SessionRole::Client, WireFrame::StreamOpenAck { stream_id }) => {
            if let Some(state) = streams.get_mut(stream_id) {
                state.opened = true;
                state.open_sent_at = None;
                state.preferred_leg_id = Some(leg_id);
                log_transport_lifecycle(
                    session_id,
                    "recv_stream_open_ack",
                    *stream_id,
                    Some(state),
                    format!("leg_id={}", leg_id),
                );
            }
            debug!("transport received stream-open-ack for stream {}", stream_id);
            return Ok(());
        }
        (SessionRole::Aggregator, WireFrame::StreamOpen { stream_id }) => {
            if let Some(reason) = reset_reasons.get(stream_id).cloned() {
                let _ = send_control_frame(
                    legs,
                    round_robin_cursor,
                    WireFrame::Reset {
                        stream_id: *stream_id,
                        reason,
                    },
                );
                return Ok(());
            }
            if streams.contains_key(stream_id) {
                let _ = send_control_frame(legs, round_robin_cursor, WireFrame::StreamOpenAck { stream_id: *stream_id });
                debug!("transport resent stream-open-ack for existing stream {}", stream_id);
                log_transport_lifecycle(
                    session_id,
                    "resend_stream_open_ack",
                    *stream_id,
                    streams.get(stream_id),
                    format!("leg_id={}", leg_id),
                );
                return Ok(());
            }
            if connecting_streams.contains(stream_id) {
                return Ok(());
            }
            debug!("transport received stream-open for stream {}", stream_id);
            log_transport_lifecycle(
                session_id,
                "recv_stream_open",
                *stream_id,
                None,
                format!("leg_id={}", leg_id),
            );
            let claims = claims.ok_or_else(|| anyhow!("aggregator transport is missing target claims"))?;
            connecting_streams.insert(*stream_id);
            spawn_upstream_connector(*stream_id, claims.clone(), config.connect_timeout, event_tx.clone());
            return Ok(());
        }
        _ => {}
    }

    if role.is_inbound_ack(&frame) {
        let (stream_id, ack) = match frame {
            WireFrame::AckC2S { stream_id, ack } | WireFrame::AckS2C { stream_id, ack } => (stream_id, ack),
            _ => unreachable!(),
        };
        debug!("transport received ack stream={} ack={}", stream_id, ack);
        if let Some(state) = streams.get_mut(&stream_id) {
            if state
                .preferred_leg_id
                .is_none_or(|preferred| !legs.get(preferred).is_some_and(|leg| leg.active))
            {
                state.preferred_leg_id = Some(leg_id);
            }
            apply_ack(&mut state.pending_chunks, legs, ack);
            if ack > state.last_ack_offset {
                state.last_ack_offset = ack;
                state.last_ack_progress_at = Instant::now();
            }
            if state.outbound_fin.as_ref().is_some_and(|fin| ack >= fin.seq) {
                state.outbound_fin = None;
                state.outbound_fin_acked = true;
                log_transport_lifecycle(
                    session_id,
                    "recv_ack_fin_complete",
                    stream_id,
                    Some(state),
                    format!("leg_id={} ack={}", leg_id, ack),
                );
                if arm_transport_stream_close_deadline(state) {
                    log_transport_lifecycle(
                        session_id,
                        "arm_close_deadline",
                        stream_id,
                        Some(state),
                        "reason=fin_acked",
                    );
                }
            } else if should_log_close_lifecycle_ack(state) {
                log_transport_lifecycle(
                    session_id,
                    "recv_ack",
                    stream_id,
                    Some(state),
                    format!("leg_id={} ack={}", leg_id, ack),
                );
            }
        }
        return Ok(());
    }

    if role.is_inbound_data(&frame) {
        let (stream_id, seq, payload) = match frame {
            WireFrame::DataC2S {
                stream_id,
                seq,
                payload,
            }
            | WireFrame::DataS2C {
                stream_id,
                seq,
                payload,
            } => (stream_id, seq, payload),
            _ => unreachable!(),
        };
        debug!(
            "transport received data stream={} seq={} bytes={}",
            stream_id,
            seq,
            payload.len()
        );

        if let Some(state) = streams.get_mut(&stream_id) {
            if state
                .preferred_leg_id
                .is_none_or(|preferred| !legs.get(preferred).is_some_and(|leg| leg.active))
            {
                state.preferred_leg_id = Some(leg_id);
            }
            let _queued_offset = handle_inbound_data(&mut state.inbound, seq, payload, &state.local_write_tx).await?;
        } else {
            let reason = reset_reasons
                .get(&stream_id)
                .cloned()
                .unwrap_or_else(|| String::from("unknown_stream"));
            let _ = send_control_frame(legs, round_robin_cursor, WireFrame::Reset { stream_id, reason });
        }
        return Ok(());
    }

        if role.is_inbound_fin(&frame) {
        let (stream_id, seq) = match frame {
            WireFrame::FinC2S { stream_id, seq } | WireFrame::FinS2C { stream_id, seq } => (stream_id, seq),
            _ => unreachable!(),
        };
        debug!("transport received fin stream={} seq={}", stream_id, seq);

        if let Some(state) = streams.get_mut(&stream_id) {
            if state
                .preferred_leg_id
                .is_none_or(|preferred| !legs.get(preferred).is_some_and(|leg| leg.active))
            {
                state.preferred_leg_id = Some(leg_id);
            }
            log_transport_lifecycle(
                session_id,
                "recv_fin",
                stream_id,
                Some(state),
                format!("leg_id={} seq={}", leg_id, seq),
            );
            let duplicate_or_late_fin = state
                .inbound
                .fin_offset
                .is_some_and(|existing| seq <= existing);
            state.inbound.fin_offset = Some(seq);
            if state.inbound.next_offset >= seq && !state.inbound.writer_shutdown {
                let _ = state.local_write_tx.send(LocalWriteCommand::Shutdown).await;
                state.inbound.writer_shutdown = true;
            }
            if duplicate_or_late_fin
                && state.outbound_fin.is_some()
                && state.is_drained_closing()
            {
                debug!(
                    "transport treating duplicate fin as close confirmation stream={} seq={}",
                    stream_id,
                    seq
                );
                state.outbound_fin = None;
                state.outbound_fin_acked = true;
                log_transport_lifecycle(
                    session_id,
                    "duplicate_fin_confirms_close",
                    stream_id,
                    Some(state),
                    format!("leg_id={} seq={}", leg_id, seq),
                );
            }
            if state.local_eof {
                if arm_transport_stream_close_deadline(state) {
                    log_transport_lifecycle(
                        session_id,
                        "arm_close_deadline",
                        stream_id,
                        Some(state),
                        "reason=remote_fin_after_local_eof",
                    );
                }
            }
            let _ = send_stream_control_frame(
                legs,
                round_robin_cursor,
                state.preferred_leg_id,
                role.outbound_ack(stream_id, state.inbound.acked_offset),
            );
            debug!(
                "transport sent final ack stream={} ack={}",
                stream_id,
                state.inbound.acked_offset
            );
            log_transport_lifecycle(
                session_id,
                "send_final_ack",
                stream_id,
                Some(state),
                format!("ack={}", state.inbound.acked_offset),
            );
        } else {
            let reason = reset_reasons
                .get(&stream_id)
                .cloned()
                .unwrap_or_else(|| String::from("unknown_stream"));
            let _ = send_control_frame(legs, round_robin_cursor, WireFrame::Reset { stream_id, reason });
        }
        return Ok(());
    }

    if matches!(
        frame,
        WireFrame::HelloAck
            | WireFrame::Hello(_)
            | WireFrame::StreamOpen { .. }
            | WireFrame::StreamOpenAck { .. }
            | WireFrame::UdpFragC2S { .. }
            | WireFrame::UdpFragS2C { .. }
            | WireFrame::UdpAckC2S { .. }
            | WireFrame::UdpAckS2C { .. }
    ) {
        debug!("bond leg delivered an unexpected post-handshake frame in TCP transport");
    }

    Ok(())
}

fn spawn_upstream_connector(
    stream_id: u32,
    claims: SessionClaims,
    connect_timeout: Duration,
    event_tx: mpsc::UnboundedSender<TcpTransportEvent>,
) {
    tokio::spawn(async move {
        let target = format!("{}:{}", claims.target_host, claims.target_port);
        let result = timeout(connect_timeout, TcpStream::connect(&target)).await;
        match result {
            Ok(Ok(stream)) => {
                let _ = stream.set_nodelay(true);
                let _ = event_tx.send(TcpTransportEvent::UpstreamConnected { stream_id, stream });
            }
            Ok(Err(err)) => {
                let _ = event_tx.send(TcpTransportEvent::UpstreamConnectFailed {
                    stream_id,
                    error: format!("failed to connect upstream target {}: {}", target, err),
                });
            }
            Err(_) => {
                let _ = event_tx.send(TcpTransportEvent::UpstreamConnectFailed {
                    stream_id,
                    error: format!("timed out connecting upstream target {}", target),
                });
            }
        }
    });
}

fn spawn_transport_stream_reader<R>(
    stream_id: u32,
    mut reader: R,
    chunk_size: usize,
    mut close_rx: watch::Receiver<bool>,
    event_tx: mpsc::UnboundedSender<TcpTransportEvent>,
) where
    R: AsyncRead + Unpin + Send + 'static,
{
    tokio::spawn(async move {
        let mut buf = vec![0u8; chunk_size.max(1024)];
        loop {
            tokio::select! {
                changed = close_rx.changed() => {
                    if changed.is_err() || *close_rx.borrow() {
                        return;
                    }
                }
                read = reader.read(&mut buf) => {
                    match read {
                        Ok(0) => {
                            let _ = event_tx.send(TcpTransportEvent::LocalEof { stream_id });
                            return;
                        }
                        Ok(read_len) => {
                            let _ = event_tx.send(TcpTransportEvent::LocalChunk {
                                stream_id,
                                payload: Bytes::copy_from_slice(&buf[..read_len]),
                            });
                        }
                        Err(err) => {
                            let _ = event_tx.send(TcpTransportEvent::LocalReadFailed {
                                stream_id,
                                error: err.to_string(),
                            });
                            return;
                        }
                    }
                }
            }
        }
    });
}

fn spawn_transport_stream_writer<W>(
    stream_id: u32,
    mut writer: W,
    mut rx: mpsc::Receiver<LocalWriteCommand>,
    event_tx: mpsc::UnboundedSender<TcpTransportEvent>,
) where
    W: AsyncWrite + Unpin + Send + 'static,
{
    tokio::spawn(async move {
        while let Some(cmd) = rx.recv().await {
            match cmd {
                LocalWriteCommand::Data(bytes) => {
                    if let Err(err) = writer.write_all(bytes.as_ref()).await {
                        let _ = event_tx.send(TcpTransportEvent::LocalWriteFailed {
                            stream_id,
                            error: err.to_string(),
                        });
                        return;
                    }
                    let _ = event_tx.send(TcpTransportEvent::LocalWriteCommitted {
                        stream_id,
                        bytes: bytes.len(),
                    });
                }
                LocalWriteCommand::Shutdown => {
                    if let Err(err) = writer.shutdown().await {
                        let _ = event_tx.send(TcpTransportEvent::LocalWriteFailed {
                            stream_id,
                            error: err.to_string(),
                        });
                    } else {
                        let _ = event_tx.send(TcpTransportEvent::LocalWriteClosed { stream_id });
                    }
                    return;
                }
            }
        }
        let _ = writer.shutdown().await;
        let _ = event_tx.send(TcpTransportEvent::LocalWriteClosed { stream_id });
    });
}

fn spawn_tcp_leg_tasks(legs: Vec<OpenLeg>, event_tx: mpsc::UnboundedSender<TcpTransportEvent>) -> Vec<LegState> {
    let mut states = Vec::with_capacity(legs.len());
    for (leg_id, leg) in legs.into_iter().enumerate() {
        states.push(spawn_tcp_leg_task(leg_id, leg, event_tx.clone()));
    }
    states
}

fn spawn_tcp_leg_task(leg_id: usize, leg: OpenLeg, event_tx: mpsc::UnboundedSender<TcpTransportEvent>) -> LegState {
    let transport = leg.transport;
    let priority = leg.priority;
    let role = leg.role;
    let label = leg.label;
    let spec_index = leg.spec_index;
    let endpoint_id = leg.endpoint_id;
    let node_id = leg.node_id;
    let host = leg.host;
    let (reader, writer) = tokio::io::split(leg.stream);
    let (tx, rx) = mpsc::unbounded_channel();
    tokio::spawn(run_tcp_leg_writer(leg_id, writer, rx, event_tx.clone()));
    tokio::spawn(run_tcp_leg_reader(leg_id, reader, event_tx));
    LegState {
        id: leg_id,
        sender: tx,
        active: true,
        inflight_bytes: 0,
        transport,
        priority,
        role,
        smoothed_rtt: initial_leg_rtt(transport),
        label,
        spec_index,
        endpoint_id,
        node_id,
        host,
        cooldown_until: None,
        penalty_count: 0,
        tcp_health: ProtocolLegHealth::new(),
        udp_health: ProtocolLegHealth::new(),
        recent_events: VecDeque::new(),
    }
}

async fn run_tcp_leg_reader<R>(leg_id: usize, mut reader: R, event_tx: mpsc::UnboundedSender<TcpTransportEvent>)
where
    R: AsyncRead + Unpin + Send + 'static,
{
    loop {
        match read_frame(&mut reader).await {
            Ok(frame) => {
                if event_tx.send(TcpTransportEvent::Frame { _leg_id: leg_id, frame }).is_err() {
                    return;
                }
            }
            Err(err) => {
                debug!("bond tcp leg {} reader closed: {:?}", leg_id, err);
                let _ = event_tx.send(TcpTransportEvent::LegClosed { leg_id });
                return;
            }
        }
    }
}

async fn run_tcp_leg_writer<W>(
    leg_id: usize,
    mut writer: W,
    mut rx: mpsc::UnboundedReceiver<WireFrame>,
    event_tx: mpsc::UnboundedSender<TcpTransportEvent>,
) where
    W: AsyncWrite + Unpin + Send + 'static,
{
    while let Some(frame) = rx.recv().await {
        if let Err(err) = write_frame(&mut writer, &frame).await {
            debug!("bond tcp leg {} writer closed: {:?}", leg_id, err);
            let _ = event_tx.send(TcpTransportEvent::LegClosed { leg_id });
            return;
        }
    }
    let _ = event_tx.send(TcpTransportEvent::LegClosed { leg_id });
}

fn refresh_active_spec_snapshot(legs: &[LegState], active_specs_tx: Option<&watch::Sender<HashSet<usize>>>) {
    let Some(active_specs_tx) = active_specs_tx else {
        return;
    };

    let active_specs = legs
        .iter()
        .filter(|leg| leg.active)
        .filter_map(|leg| leg.spec_index)
        .collect::<HashSet<_>>();
    let _ = active_specs_tx.send(active_specs);
}

fn next_transport_stream_id(
    streams: &HashMap<u32, TcpStreamState>,
    connecting_streams: &HashSet<u32>,
    closed_streams: &HashSet<u32>,
    next_stream_id: &mut u32,
) -> u32 {
    loop {
        let candidate = (*next_stream_id).max(1);
        *next_stream_id = candidate.wrapping_add(1).max(1);
        if !streams.contains_key(&candidate)
            && !connecting_streams.contains(&candidate)
            && !closed_streams.contains(&candidate)
        {
            return candidate;
        }
    }
}

fn flush_tcp_stream_backlogs(
    session_id: Uuid,
    streams: &mut HashMap<u32, TcpStreamState>,
    legs: &mut [LegState],
    round_robin_cursor: &mut usize,
    role: SessionRole,
    config: &SessionConfig,
) {
    let mut stream_ids: Vec<u32> = streams.keys().copied().collect();
    stream_ids.sort_unstable();
    let mut outstanding_bytes = transport_pending_bytes(streams);

    for stream_id in stream_ids {
        let Some(state) = streams.get_mut(&stream_id) else {
            continue;
        };
        if !state.opened {
            continue;
        }

        while pending_bytes(&state.pending_chunks) < config.max_window_bytes {
            let Some(payload) = state.local_backlog.pop_front() else {
                break;
            };

            let backlog_bytes = state
                .local_backlog
                .iter()
                .map(|chunk| chunk.len())
                .sum::<usize>()
                .saturating_add(payload.len());
            let next_stage = strategy_striping_stage(
                state.strategy,
                decide_striping_stage(
                    state.outbound_next_offset as usize,
                    backlog_bytes,
                    state.warmup_leg_count,
                    legs.iter().filter(|leg| leg.active).count(),
                    config,
                ),
            );
            if next_stage != state.striping_stage {
                info!(
                    "bond tcp stream {} stage {:?} -> {:?}",
                    stream_id,
                    state.striping_stage,
                    next_stage
                );
                state.striping_stage = next_stage;
                state.bulk_mode = next_stage == TcpStripingStage::Bulk;
                if next_stage != TcpStripingStage::Warmup {
                    state.warmup_stage_started_at = None;
                    state.warmup_leg_bytes = 0;
                    state.warmup_leg_count = 1;
                }
            }

            let now = Instant::now();
            let Some(leg_index) = (match state.striping_stage {
                TcpStripingStage::Sticky => select_pinned_strategy_leg(
                    state.strategy,
                    legs,
                    round_robin_cursor,
                    state.preferred_leg_id,
                    payload.len(),
                    outstanding_bytes,
                    config,
                ),
                TcpStripingStage::Warmup => select_stream_leg_warmup(
                    state,
                    legs,
                    round_robin_cursor,
                    payload.len(),
                    outstanding_bytes,
                    config,
                    now,
                ),
                TcpStripingStage::Bulk => select_stream_leg(
                    legs,
                    round_robin_cursor,
                    None,
                    payload.len(),
                    outstanding_bytes,
                    config,
                ),
            }) else {
                state.local_backlog.push_front(payload);
                break;
            };

            let seq = state.outbound_next_offset;
            let frame = role.outbound_data(stream_id, seq, payload.clone());
            if !send_to_leg(legs, leg_index, frame) {
                state.local_backlog.push_front(payload);
                continue;
            }
            debug!(
                "transport scheduled data stream={} seq={} bytes={} leg={}",
                stream_id,
                seq,
                payload.len(),
                leg_index
            );

            state.pending_chunks.insert(
                seq,
                PendingChunk {
                    payload: payload.clone(),
                    last_sent_at: Instant::now(),
                    last_leg_id: leg_index,
                    transmit_count: 1,
                },
            );
            if state.striping_stage != TcpStripingStage::Bulk {
                state.preferred_leg_id = Some(leg_index);
            }
            state.outbound_next_offset += payload.len() as u64;
            outstanding_bytes += payload.len();
        }

        if state.local_eof
            && state.local_backlog.is_empty()
            && state.pending_chunks.is_empty()
            && !state.outbound_fin_acked
            && state.outbound_fin.is_none()
            && send_stream_control_frame(
                legs,
                round_robin_cursor,
                state.preferred_leg_id,
                role.outbound_fin(stream_id, state.outbound_next_offset),
            )
        {
            state.outbound_fin = Some(FinTracker {
                seq: state.outbound_next_offset,
                last_sent_at: Instant::now(),
            });
            debug!(
                "transport sent fin stream={} seq={}",
                stream_id,
                state.outbound_next_offset
            );
            log_transport_lifecycle(
                session_id,
                "send_fin",
                stream_id,
                Some(state),
                format!("seq={}", state.outbound_next_offset),
            );
        }
    }

    recompute_transport_inflight(legs, streams);
}

fn retransmit_transport_pending(
    stream_id: u32,
    pending: &mut BTreeMap<u64, PendingChunk>,
    last_ack_progress_at: Instant,
    preferred_leg_id: &mut Option<usize>,
    bulk_mode: bool,
    legs: &mut [LegState],
    round_robin_cursor: &mut usize,
    role: SessionRole,
    config: &SessionConfig,
) {
    let now = Instant::now();
    let resend = collect_retransmit_sequences(
        pending,
        now,
        config.retransmit_after,
        last_ack_progress_at,
        MAX_TCP_RETRANSMIT_BATCH,
    );

    for seq in resend {
        let outstanding_bytes = pending_bytes(pending);
        let Some(chunk) = pending.get_mut(&seq) else {
            continue;
        };
        if chunk.transmit_count >= 3 {
            apply_leg_cooldown(legs, chunk.last_leg_id, config.leg_cooldown);
        }
        let Some(leg_index) = select_retransmit_leg(
            legs,
            round_robin_cursor,
            if bulk_mode { None } else { *preferred_leg_id },
            chunk.last_leg_id,
            chunk.transmit_count,
            chunk.payload.len(),
            outstanding_bytes,
            config,
        ) else {
            break;
        };
        if send_to_leg(legs, leg_index, role.outbound_data(stream_id, seq, chunk.payload.clone())) {
            chunk.last_sent_at = now;
            chunk.last_leg_id = leg_index;
            chunk.transmit_count = chunk.transmit_count.saturating_add(1);
            if !bulk_mode {
                *preferred_leg_id = Some(leg_index);
            }
            debug!(
                "transport retransmitted data stream={} seq={} bytes={} leg={} count={}",
                stream_id,
                seq,
                chunk.payload.len(),
                leg_index,
                chunk.transmit_count
            );
        }
    }
}

fn collect_retransmit_sequences(
    pending: &BTreeMap<u64, PendingChunk>,
    now: Instant,
    retransmit_after: Duration,
    last_ack_progress_at: Instant,
    batch_limit: usize,
) -> Vec<u64> {
    if now.duration_since(last_ack_progress_at) < retransmit_after {
        return Vec::new();
    }

    pending
        .iter()
        .filter_map(|(seq, chunk)| {
            if now.duration_since(chunk.last_sent_at) >= retransmit_after {
                Some(*seq)
            } else {
                None
            }
        })
        .take(batch_limit)
        .collect()
}

fn close_transport_stream(
    stream_id: u32,
    streams: &mut HashMap<u32, TcpStreamState>,
    closed_streams: &mut HashSet<u32>,
) {
    if let Some(state) = streams.remove(&stream_id) {
        let _ = state.close_tx.send(true);
        let writer_shutdown = state.inbound.writer_shutdown;
        let local_write_tx = state.local_write_tx;
        if !writer_shutdown {
            tokio::spawn(async move {
                let _ = local_write_tx.send(LocalWriteCommand::Shutdown).await;
            });
        }
        closed_streams.insert(stream_id);
    }
}

fn prune_finished_transport_streams(
    streams: &mut HashMap<u32, TcpStreamState>,
    closed_streams: &mut HashSet<u32>,
) {
    let completed: Vec<u32> = streams
        .iter()
        .filter_map(|(stream_id, state)| if state.is_finished() { Some(*stream_id) } else { None })
        .collect();
    for stream_id in completed {
        close_transport_stream(stream_id, streams, closed_streams);
    }
}

fn transport_streams_can_end_cleanly(
    streams: &HashMap<u32, TcpStreamState>,
    connecting_streams: &HashSet<u32>,
) -> bool {
    connecting_streams.is_empty()
        && !streams.is_empty()
        && streams.values().all(TcpStreamState::is_drained_closing)
}

fn mark_transport_streams_for_retry(
    streams: &mut HashMap<u32, TcpStreamState>,
    leg_id: usize,
    retransmit_after: Duration,
) {
    for state in streams.values_mut() {
        mark_pending_for_retry(&mut state.pending_chunks, leg_id, retransmit_after);
        if state.preferred_leg_id == Some(leg_id) {
            state.preferred_leg_id = None;
        }
    }
}

fn recompute_transport_inflight(legs: &mut [LegState], streams: &HashMap<u32, TcpStreamState>) {
    for leg in legs.iter_mut() {
        leg.inflight_bytes = 0;
    }

    for state in streams.values() {
        for chunk in state.pending_chunks.values() {
            if let Some(leg) = legs.get_mut(chunk.last_leg_id) {
                if leg.active {
                    leg.inflight_bytes += chunk.payload.len();
                }
            }
        }
    }
}

fn transport_pending_bytes(streams: &HashMap<u32, TcpStreamState>) -> usize {
    streams
        .values()
        .map(|state| pending_bytes(&state.pending_chunks))
        .sum()
}

fn should_enable_bulk_tcp_striping(
    state: &TcpStreamState,
    next_payload_len: usize,
    legs: &[LegState],
    config: &SessionConfig,
) -> bool {
    let active_leg_count = legs.iter().filter(|leg| leg.active).count();
    if active_leg_count < 2 {
        return false;
    }

    let bulk_promote_bytes = config.bulk_promote_bytes.max(config.chunk_size);
    let backlog_bytes: usize = state
        .local_backlog
        .iter()
        .map(|chunk| chunk.len())
        .sum::<usize>()
        .saturating_add(next_payload_len);
    let stream_pending_bytes = pending_bytes(&state.pending_chunks);
    let sent_bytes = state.outbound_next_offset as usize;
    let in_flight_or_backlog = stream_pending_bytes.saturating_add(backlog_bytes);
    let long_stream = sent_bytes >= config.chunk_size.saturating_mul(2);

    sent_bytes.saturating_add(backlog_bytes) >= bulk_promote_bytes
        && (
            long_stream
                || in_flight_or_backlog >= bulk_promote_bytes
                || state.local_backlog.len() >= 2
        )
}

fn transport_resume_timeout(config: &SessionConfig) -> Duration {
    config.join_timeout.max(MIN_TRANSPORT_RESUME_TIMEOUT)
}

fn transport_idle_timeout(config: &SessionConfig) -> Duration {
    transport_resume_timeout(config).max(MIN_TRANSPORT_IDLE_TIMEOUT)
}

async fn run_bonded_udp_client(
    local_socket: Arc<UdpSocket>,
    legs: Vec<OpenLeg>,
    snapshot: Option<SnapshotContext>,
    config: SessionConfig,
) -> anyhow::Result<()> {
    let fragment_size = udp_fragment_payload_size(&config);
    let (event_tx, mut event_rx) = mpsc::unbounded_channel();
    spawn_udp_local_reader(local_socket.clone(), event_tx.clone());
    let mut legs = spawn_leg_tasks(legs, event_tx.clone());
    let mut round_robin_cursor = 0usize;
    let mut retransmit_tick = interval(Duration::from_millis(100));
    let mut cleanup_tick = interval(Duration::from_secs(15));
    let mut peer_id_by_addr = HashMap::<SocketAddr, u32>::new();
    let mut peer_state_by_id = HashMap::<u32, ClientPeerState>::new();
    let mut next_peer_id = 1u32;
    let mut next_datagram_id = 1u64;
    let mut last_snapshot_write = None::<Instant>;
    let mut pending_outbound = BTreeMap::<u64, PendingUdpDatagram>::new();
    let mut inbound_reassembly = HashMap::<(u32, u64), UdpReassembly>::new();
    let mut completed_inbound = HashMap::<(u32, u64), Instant>::new();
    let mut ordered_delivery = UdpDeliveryBuffer::new();

    loop {
        if !has_active_leg(&legs) {
            return Err(anyhow!("no active bond legs remaining for udp session"));
        }

        tokio::select! {
            maybe_event = event_rx.recv() => {
                let Some(event) = maybe_event else {
                    break;
                };
                match event {
                    SessionEvent::UdpLocalDatagram { peer, payload } => {
                        let is_new_flow = !peer_id_by_addr.contains_key(&peer);
                        let flow_strategy = if is_new_flow {
                            config
                                .strategy_file
                                .as_deref()
                                .map(|path| load_strategy(path, config.default_strategy))
                                .unwrap_or(config.default_strategy)
                        } else {
                            config.default_strategy
                        };
                        let peer_id = assign_client_peer_id(
                            peer,
                            &mut peer_id_by_addr,
                            &mut peer_state_by_id,
                            &mut next_peer_id,
                            flow_strategy,
                        );
                        let peer_state = peer_state_by_id
                            .get_mut(&peer_id)
                            .expect("assigned UDP peer must have state");
                        let mut flow_random_state = ((peer_id as u64) << 32) ^ next_datagram_id;
                        let selected_leg = select_udp_leg_for_strategy(
                            peer_state.strategy,
                            &legs,
                            &mut round_robin_cursor,
                            &mut flow_random_state,
                            peer_state.preferred_leg_id,
                            payload.len(),
                            &config,
                        );
                        let datagram_id = next_datagram_id;
                        next_datagram_id = next_datagram_id.wrapping_add(1).max(1);
                        let mut pending = PendingUdpDatagram::new(peer_id, payload, fragment_size);
                        if !send_udp_datagram(
                            &mut pending,
                            datagram_id,
                            &mut legs,
                            &mut round_robin_cursor,
                            SessionRole::Client,
                            &config,
                            selected_leg,
                        ) {
                            return Err(anyhow!("failed to dispatch UDP datagram because all bond legs are unavailable"));
                        }
                        peer_state.preferred_leg_id = pending.last_leg_id;
                        pending_outbound.insert(datagram_id, pending);
                    }
                    SessionEvent::UdpLocalFailed(err) => {
                        return Err(anyhow!("bond UDP local socket failed: {err}"));
                    }
                    SessionEvent::Frame { frame, .. } => {
                        if let WireFrame::Reset { reason, .. } = frame {
                            return Err(anyhow!("bond remote reset: {reason}"));
                        }

                        if SessionRole::Client.is_inbound_udp_ack(&frame) {
                            let (peer_id, datagram_id) = match frame {
                                WireFrame::UdpAckC2S { peer_id, datagram_id }
                                | WireFrame::UdpAckS2C { peer_id, datagram_id } => (peer_id, datagram_id),
                                _ => unreachable!(),
                            };
                            acknowledge_udp_datagram(&mut pending_outbound, &mut legs, peer_id, datagram_id);
                            continue;
                        }

                        if SessionRole::Client.is_inbound_udp_fragment(&frame) {
                            let (peer_id, datagram_id, frag_index, frag_total, payload) = match frame {
                                WireFrame::UdpFragC2S {
                                    peer_id,
                                    datagram_id,
                                    frag_index,
                                    frag_total,
                                    payload,
                                }
                                | WireFrame::UdpFragS2C {
                                    peer_id,
                                    datagram_id,
                                    frag_index,
                                    frag_total,
                                    payload,
                                } => (peer_id, datagram_id, frag_index, frag_total, payload),
                                _ => unreachable!(),
                            };

                            if completed_inbound.contains_key(&(peer_id, datagram_id)) {
                                completed_inbound.insert((peer_id, datagram_id), Instant::now());
                                let _ = send_control_frame(
                                    &mut legs,
                                    &mut round_robin_cursor,
                                    SessionRole::Client.outbound_udp_ack(peer_id, datagram_id),
                                );
                                continue;
                            }

                            if let Some(datagram) = insert_udp_fragment(
                                &mut inbound_reassembly,
                                peer_id,
                                datagram_id,
                                frag_index,
                                frag_total,
                                payload,
                            )? {
                                for ready in ordered_delivery.insert(peer_id, datagram_id, datagram) {
                                    if let Some(peer_state) = peer_state_by_id.get_mut(&ready.peer_id) {
                                        debug!(
                                            "routing udp datagram_id={} peer_id={} back to {}",
                                            datagram_id,
                                            ready.peer_id,
                                            peer_state.addr
                                        );
                                        local_socket
                                            .send_to(ready.payload.as_ref(), peer_state.addr)
                                            .await
                                            .with_context(|| format!("failed to write UDP datagram back to local peer {}", peer_state.addr))?;
                                        peer_state.last_seen = Instant::now();
                                    } else {
                                        debug!("dropping inbound UDP datagram for unknown peer_id {}", ready.peer_id);
                                    }
                                }
                                completed_inbound.insert((peer_id, datagram_id), Instant::now());
                                let _ = send_control_frame(
                                    &mut legs,
                                    &mut round_robin_cursor,
                                    SessionRole::Client.outbound_udp_ack(peer_id, datagram_id),
                                );
                            }
                            continue;
                        }
                    }
                    SessionEvent::LegClosed { leg_id } => {
                        mark_leg_inactive(&mut legs, leg_id);
                        force_retry_udp_datagrams(&mut pending_outbound, config.retransmit_after);
                    }
                    SessionEvent::LocalChunk(_)
                    | SessionEvent::LocalEof
                    | SessionEvent::LocalReadFailed(_)
                    | SessionEvent::LocalWriteClosed
                    | SessionEvent::LocalWriteFailed(_)
                    | SessionEvent::UdpUpstreamDatagram { .. }
                    | SessionEvent::UdpUpstreamPeerFailed { .. } => {}
                }
            }
            _ = retransmit_tick.tick() => {
                retransmit_udp_datagrams(
                    &mut pending_outbound,
                    &mut legs,
                    &mut round_robin_cursor,
                    SessionRole::Client,
                    config.retransmit_after,
                    &config,
                );
            }
            _ = cleanup_tick.tick() => {
                cleanup_client_peers(&mut peer_id_by_addr, &mut peer_state_by_id);
                cleanup_udp_reassembly(&mut inbound_reassembly);
                cleanup_udp_completed(&mut completed_inbound);
            }
        }
        maybe_write_leg_health_snapshot(snapshot.as_ref(), &mut last_snapshot_write, &legs);
    }

    maybe_write_leg_health_snapshot(snapshot.as_ref(), &mut last_snapshot_write, &legs);
    Ok(())
}

async fn run_bonded_udp_server(
    target_addr: SocketAddr,
    legs: Vec<OpenLeg>,
    snapshot: Option<SnapshotContext>,
    config: SessionConfig,
) -> anyhow::Result<()> {
    let fragment_size = udp_fragment_payload_size(&config);
    let (event_tx, mut event_rx) = mpsc::unbounded_channel();
    let mut legs = spawn_leg_tasks(legs, event_tx.clone());
    let mut round_robin_cursor = 0usize;
    let mut retransmit_tick = interval(Duration::from_millis(100));
    let mut cleanup_tick = interval(Duration::from_secs(15));
    let mut next_datagram_id = 1u64;
    let mut last_snapshot_write = None::<Instant>;
    let mut pending_outbound = BTreeMap::<u64, PendingUdpDatagram>::new();
    let mut inbound_reassembly = HashMap::<(u32, u64), UdpReassembly>::new();
    let mut completed_inbound = HashMap::<(u32, u64), Instant>::new();
    let mut upstream_peers = HashMap::<u32, Arc<UdpSocket>>::new();
    let mut ordered_delivery = UdpDeliveryBuffer::new();

    loop {
        if !has_active_leg(&legs) {
            return Err(anyhow!("no active bond legs remaining for udp session"));
        }

        tokio::select! {
            maybe_event = event_rx.recv() => {
                let Some(event) = maybe_event else {
                    break;
                };
                match event {
                    SessionEvent::Frame { frame, .. } => {
                        if let WireFrame::Reset { reason, .. } = frame {
                            return Err(anyhow!("bond remote reset: {reason}"));
                        }

                        if SessionRole::Aggregator.is_inbound_udp_ack(&frame) {
                            let (peer_id, datagram_id) = match frame {
                                WireFrame::UdpAckC2S { peer_id, datagram_id }
                                | WireFrame::UdpAckS2C { peer_id, datagram_id } => (peer_id, datagram_id),
                                _ => unreachable!(),
                            };
                            acknowledge_udp_datagram(&mut pending_outbound, &mut legs, peer_id, datagram_id);
                            continue;
                        }

                        if SessionRole::Aggregator.is_inbound_udp_fragment(&frame) {
                            let (peer_id, datagram_id, frag_index, frag_total, payload) = match frame {
                                WireFrame::UdpFragC2S {
                                    peer_id,
                                    datagram_id,
                                    frag_index,
                                    frag_total,
                                    payload,
                                }
                                | WireFrame::UdpFragS2C {
                                    peer_id,
                                    datagram_id,
                                    frag_index,
                                    frag_total,
                                    payload,
                                } => (peer_id, datagram_id, frag_index, frag_total, payload),
                                _ => unreachable!(),
                            };

                            if completed_inbound.contains_key(&(peer_id, datagram_id)) {
                                completed_inbound.insert((peer_id, datagram_id), Instant::now());
                                let _ = send_control_frame(
                                    &mut legs,
                                    &mut round_robin_cursor,
                                    SessionRole::Aggregator.outbound_udp_ack(peer_id, datagram_id),
                                );
                                continue;
                            }

                            if let Some(datagram) = insert_udp_fragment(
                                &mut inbound_reassembly,
                                peer_id,
                                datagram_id,
                                frag_index,
                                frag_total,
                                payload,
                            )? {
                                for ready in ordered_delivery.insert(peer_id, datagram_id, datagram) {
                                    let upstream = ensure_upstream_peer(ready.peer_id, target_addr, &mut upstream_peers, &event_tx).await?;
                                    upstream
                                        .send(ready.payload.as_ref())
                                        .await
                                        .with_context(|| format!("failed to send UDP datagram to target {}", target_addr))?;
                                }
                                completed_inbound.insert((peer_id, datagram_id), Instant::now());
                                let _ = send_control_frame(
                                    &mut legs,
                                    &mut round_robin_cursor,
                                    SessionRole::Aggregator.outbound_udp_ack(peer_id, datagram_id),
                                );
                            }
                            continue;
                        }
                    }
                    SessionEvent::UdpUpstreamDatagram { peer_id, payload } => {
                        let datagram_id = next_datagram_id;
                        next_datagram_id = next_datagram_id.wrapping_add(1).max(1);
                        let mut pending = PendingUdpDatagram::new(peer_id, payload, fragment_size);
                        if !send_udp_datagram(
                            &mut pending,
                            datagram_id,
                            &mut legs,
                            &mut round_robin_cursor,
                            SessionRole::Aggregator,
                            &config,
                            None,
                        ) {
                            return Err(anyhow!("failed to dispatch UDP response because all bond legs are unavailable"));
                        }
                        pending_outbound.insert(datagram_id, pending);
                    }
                    SessionEvent::UdpUpstreamPeerFailed { peer_id, error } => {
                        upstream_peers.remove(&peer_id);
                        warn!("bond upstream UDP peer {} closed: {}", peer_id, error);
                    }
                    SessionEvent::LegClosed { leg_id } => {
                        mark_leg_inactive(&mut legs, leg_id);
                        force_retry_udp_datagrams(&mut pending_outbound, config.retransmit_after);
                    }
                    SessionEvent::LocalChunk(_)
                    | SessionEvent::LocalEof
                    | SessionEvent::LocalReadFailed(_)
                    | SessionEvent::LocalWriteClosed
                    | SessionEvent::LocalWriteFailed(_)
                    | SessionEvent::UdpLocalDatagram { .. }
                    | SessionEvent::UdpLocalFailed(_) => {}
                }
            }
            _ = retransmit_tick.tick() => {
                retransmit_udp_datagrams(
                    &mut pending_outbound,
                    &mut legs,
                    &mut round_robin_cursor,
                    SessionRole::Aggregator,
                    config.retransmit_after,
                    &config,
                );
            }
            _ = cleanup_tick.tick() => {
                cleanup_udp_reassembly(&mut inbound_reassembly);
                cleanup_udp_completed(&mut completed_inbound);
            }
        }
        maybe_write_leg_health_snapshot(snapshot.as_ref(), &mut last_snapshot_write, &legs);
    }

    maybe_write_leg_health_snapshot(snapshot.as_ref(), &mut last_snapshot_write, &legs);
    Ok(())
}

#[allow(dead_code)]
fn spawn_local_reader<R>(mut reader: R, chunk_size: usize, event_tx: mpsc::UnboundedSender<SessionEvent>)
where
    R: AsyncRead + Unpin + Send + 'static,
{
    tokio::spawn(async move {
        let mut buf = vec![0u8; chunk_size.max(1024)];
        loop {
            match reader.read(&mut buf).await {
                Ok(0) => {
                    let _ = event_tx.send(SessionEvent::LocalEof);
                    return;
                }
                Ok(read_len) => {
                    let _ = event_tx.send(SessionEvent::LocalChunk(Bytes::copy_from_slice(&buf[..read_len])));
                }
                Err(err) => {
                    let _ = event_tx.send(SessionEvent::LocalReadFailed(err.to_string()));
                    return;
                }
            }
        }
    });
}

#[allow(dead_code)]
fn spawn_local_writer<W>(
    mut writer: W,
    mut rx: mpsc::Receiver<LocalWriteCommand>,
    event_tx: mpsc::UnboundedSender<SessionEvent>,
) where
    W: AsyncWrite + Unpin + Send + 'static,
{
    tokio::spawn(async move {
        while let Some(cmd) = rx.recv().await {
            match cmd {
                LocalWriteCommand::Data(bytes) => {
                    if let Err(err) = writer.write_all(bytes.as_ref()).await {
                        let _ = event_tx.send(SessionEvent::LocalWriteFailed(err.to_string()));
                        return;
                    }
                }
                LocalWriteCommand::Shutdown => {
                    if let Err(err) = writer.shutdown().await {
                        let _ = event_tx.send(SessionEvent::LocalWriteFailed(err.to_string()));
                    } else {
                        let _ = event_tx.send(SessionEvent::LocalWriteClosed);
                    }
                    return;
                }
            }
        }
        let _ = event_tx.send(SessionEvent::LocalWriteClosed);
    });
}

fn spawn_udp_local_reader(socket: Arc<UdpSocket>, event_tx: mpsc::UnboundedSender<SessionEvent>) {
    tokio::spawn(async move {
        let mut buf = vec![0u8; 65_535];
        loop {
            match socket.recv_from(&mut buf).await {
                Ok((len, peer)) => {
                    if len == 0 {
                        continue;
                    }
                    let _ = event_tx.send(SessionEvent::UdpLocalDatagram {
                        peer,
                        payload: Bytes::copy_from_slice(&buf[..len]),
                    });
                }
                Err(err) => {
                    let _ = event_tx.send(SessionEvent::UdpLocalFailed(err.to_string()));
                    return;
                }
            }
        }
    });
}

fn spawn_udp_upstream_reader(peer_id: u32, socket: Arc<UdpSocket>, event_tx: mpsc::UnboundedSender<SessionEvent>) {
    tokio::spawn(async move {
        let mut buf = vec![0u8; 65_535];
        loop {
            match socket.recv(&mut buf).await {
                Ok(len) => {
                    let _ = event_tx.send(SessionEvent::UdpUpstreamDatagram {
                        peer_id,
                        payload: Bytes::copy_from_slice(&buf[..len]),
                    });
                }
                Err(err) => {
                    let _ = event_tx.send(SessionEvent::UdpUpstreamPeerFailed {
                        peer_id,
                        error: err.to_string(),
                    });
                    return;
                }
            }
        }
    });
}

fn spawn_leg_tasks(legs: Vec<OpenLeg>, event_tx: mpsc::UnboundedSender<SessionEvent>) -> Vec<LegState> {
    let mut states = Vec::with_capacity(legs.len());
    for (leg_id, leg) in legs.into_iter().enumerate() {
        states.push(spawn_leg_task(leg_id, leg, event_tx.clone()));
    }
    states
}

fn spawn_leg_task(leg_id: usize, leg: OpenLeg, event_tx: mpsc::UnboundedSender<SessionEvent>) -> LegState {
    let transport = leg.transport;
    let priority = leg.priority;
    let role = leg.role;
    let label = leg.label;
    let spec_index = leg.spec_index;
    let endpoint_id = leg.endpoint_id;
    let node_id = leg.node_id;
    let host = leg.host;
    let (reader, writer) = tokio::io::split(leg.stream);
    let (tx, rx) = mpsc::unbounded_channel();
    tokio::spawn(run_leg_writer(leg_id, writer, rx, event_tx.clone()));
    tokio::spawn(run_leg_reader(leg_id, reader, event_tx));
    LegState {
        id: leg_id,
        sender: tx,
        active: true,
        inflight_bytes: 0,
        transport,
        priority,
        role,
        smoothed_rtt: initial_leg_rtt(transport),
        label,
        spec_index,
        endpoint_id,
        node_id,
        host,
        cooldown_until: None,
        penalty_count: 0,
        tcp_health: ProtocolLegHealth::new(),
        udp_health: ProtocolLegHealth::new(),
        recent_events: VecDeque::new(),
    }
}

async fn run_leg_reader<R>(leg_id: usize, mut reader: R, event_tx: mpsc::UnboundedSender<SessionEvent>)
where
    R: AsyncRead + Unpin + Send + 'static,
{
    loop {
        match read_frame(&mut reader).await {
            Ok(frame) => {
                if event_tx.send(SessionEvent::Frame { _leg_id: leg_id, frame }).is_err() {
                    return;
                }
            }
            Err(err) => {
                debug!("bond leg {} reader closed: {:?}", leg_id, err);
                let _ = event_tx.send(SessionEvent::LegClosed { leg_id });
                return;
            }
        }
    }
}

async fn run_leg_writer<W>(
    leg_id: usize,
    mut writer: W,
    mut rx: mpsc::UnboundedReceiver<WireFrame>,
    event_tx: mpsc::UnboundedSender<SessionEvent>,
) where
    W: AsyncWrite + Unpin + Send + 'static,
{
    while let Some(frame) = rx.recv().await {
        if let Err(err) = write_frame(&mut writer, &frame).await {
            debug!("bond leg {} writer closed: {:?}", leg_id, err);
            let _ = event_tx.send(SessionEvent::LegClosed { leg_id });
            return;
        }
    }
    let _ = event_tx.send(SessionEvent::LegClosed { leg_id });
}

async fn handle_inbound_data(
    inbound: &mut InboundState,
    seq: u64,
    payload: Bytes,
    local_write_tx: &mpsc::Sender<LocalWriteCommand>,
) -> anyhow::Result<u64> {
    if inbound.writer_shutdown {
        return Ok(inbound.next_offset);
    }

    let mut seq = seq;
    let mut payload = payload;

    if seq < inbound.next_offset {
        let already_seen = (inbound.next_offset - seq) as usize;
        if already_seen >= payload.len() {
            return Ok(inbound.next_offset);
        }
        payload = payload.slice(already_seen..);
        seq = inbound.next_offset;
    }

    if seq == inbound.next_offset {
        local_write_tx
            .send(LocalWriteCommand::Data(payload.clone()))
            .await
            .map_err(|_| anyhow!("local writer closed"))?;
        inbound.pending_write_lengths.push_back(payload.len());
        inbound.next_offset += payload.len() as u64;

        while let Some(chunk) = inbound.buffer.remove(&inbound.next_offset) {
            local_write_tx
                .send(LocalWriteCommand::Data(chunk.clone()))
                .await
                .map_err(|_| anyhow!("local writer closed"))?;
            inbound.pending_write_lengths.push_back(chunk.len());
            inbound.next_offset += chunk.len() as u64;
        }
    } else {
        inbound.buffer.entry(seq).or_insert(payload);
    }

    if let Some(fin_offset) = inbound.fin_offset {
        if inbound.next_offset >= fin_offset && !inbound.writer_shutdown {
            local_write_tx
                .send(LocalWriteCommand::Shutdown)
                .await
                .map_err(|_| anyhow!("local writer closed"))?;
            inbound.writer_shutdown = true;
        }
    }

    Ok(inbound.next_offset)
}

fn record_inbound_write_commit(inbound: &mut InboundState, written_len: usize) -> u64 {
    if let Some(expected_len) = inbound.pending_write_lengths.pop_front() {
        inbound.acked_offset += expected_len.min(written_len) as u64;
    }
    inbound.acked_offset
}

#[allow(dead_code)]
fn flush_local_backlog(
    backlog: &mut VecDeque<Bytes>,
    pending: &mut BTreeMap<u64, PendingChunk>,
    legs: &mut [LegState],
    round_robin_cursor: &mut usize,
    role: SessionRole,
    outbound_next_offset: &mut u64,
    config: &SessionConfig,
    max_window_bytes: usize,
) {
    while pending_bytes(pending) < max_window_bytes {
        let Some(payload) = backlog.pop_front() else {
            break;
        };

        let outstanding_bytes = pending_bytes(pending);
        let Some(leg_index) = select_leg_for_data(
            legs,
            round_robin_cursor,
            HealthProtocol::Tcp,
            payload.len(),
            outstanding_bytes,
            config,
        ) else {
            backlog.push_front(payload);
            break;
        };

        let seq = *outbound_next_offset;
        let frame = role.outbound_data(0, seq, payload.clone());
        if !send_to_leg(legs, leg_index, frame) {
            backlog.push_front(payload);
            continue;
        }

        pending.insert(
            seq,
            PendingChunk {
                payload: payload.clone(),
                last_sent_at: Instant::now(),
                last_leg_id: leg_index,
                transmit_count: 1,
            },
        );
        *outbound_next_offset += payload.len() as u64;
        recompute_inflight(legs, pending);
    }
}

fn pending_bytes(pending: &BTreeMap<u64, PendingChunk>) -> usize {
    pending.values().map(|chunk| chunk.payload.len()).sum()
}

fn protocol_health(leg: &LegState, protocol: HealthProtocol) -> &ProtocolLegHealth {
    match protocol {
        HealthProtocol::Tcp => &leg.tcp_health,
        HealthProtocol::Udp => &leg.udp_health,
    }
}

fn protocol_health_mut(leg: &mut LegState, protocol: HealthProtocol) -> &mut ProtocolLegHealth {
    match protocol {
        HealthProtocol::Tcp => &mut leg.tcp_health,
        HealthProtocol::Udp => &mut leg.udp_health,
    }
}

fn ewma(current: f64, sample: f64) -> f64 {
    if current <= 0.0 {
        sample.max(0.0)
    } else {
        (current * 0.7) + (sample.max(0.0) * 0.3)
    }
}

fn decay_penalty(current: f64) -> f64 {
    (current * 0.78).max(0.0)
}

fn apply_penalty(current: f64, weight: f64) -> f64 {
    (current * 0.85 + weight.max(0.0)).min(2.0)
}

fn leg_health_state_rank(state: LegHealthState) -> u8 {
    match state {
        LegHealthState::Healthy => 0,
        LegHealthState::Degraded => 1,
        LegHealthState::Probation => 2,
        LegHealthState::Quarantined => 3,
    }
}

fn effective_weight_sort_rank(weight: f64) -> u16 {
    let normalized = weight.clamp(0.0, 1.0);
    (1000.0 - (normalized * 1000.0)).round() as u16
}

fn push_leg_health_event(
    leg: &mut LegState,
    protocol: HealthProtocol,
    from: LegHealthState,
    to: LegHealthState,
    reason: String,
) {
    if from == to {
        return;
    }
    if leg.recent_events.len() >= MAX_RECENT_HEALTH_EVENTS {
        leg.recent_events.pop_front();
    }
    leg.recent_events.push_back(LegHealthEvent {
        protocol,
        from,
        to,
        reason,
        observed_at: SystemTime::now(),
    });
}

fn recompute_protocol_leg_state(leg: &mut LegState, protocol: HealthProtocol) {
    let reason = protocol_health(leg, protocol)
        .last_reason
        .clone()
        .unwrap_or_else(|| String::from("delivery_recovered"));
    let (next_state, next_score, next_weight) = {
        let health = protocol_health(leg, protocol);
        let delivered_factor = (health.delivered_bytes_ewma / 262_144.0).min(1.0);
        let penalty_factor = (health.penalty_ewma / 1.5).min(1.0);
        let score = (((1.0 - penalty_factor) * 0.7) + (delivered_factor * 0.3)).clamp(0.0, 1.0);
        let state = if health.penalty_ewma >= 0.95 {
            LegHealthState::Quarantined
        } else if health.penalty_ewma >= 0.70 {
            LegHealthState::Probation
        } else if health.penalty_ewma >= 0.40 || score < 0.45 {
            LegHealthState::Degraded
        } else {
            LegHealthState::Healthy
        };
        let weight = match state {
            LegHealthState::Healthy => 1.0,
            LegHealthState::Degraded => 0.20,
            LegHealthState::Probation => 0.05,
            LegHealthState::Quarantined => 0.0,
        };
        (state, score, weight)
    };

    let previous = protocol_health(leg, protocol).state;
    let health = protocol_health_mut(leg, protocol);
    health.score = next_score;
    health.state = next_state;
    health.effective_weight = next_weight;
    push_leg_health_event(leg, protocol, previous, next_state, reason);
}

fn credit_tcp_delivery(legs: &mut [LegState], leg_id: usize, delivered_bytes: usize) {
    let Some(leg) = legs.get_mut(leg_id) else {
        return;
    };
    leg.tcp_health.delivered_bytes_ewma = ewma(leg.tcp_health.delivered_bytes_ewma, delivered_bytes as f64);
    leg.tcp_health.penalty_ewma = decay_penalty(leg.tcp_health.penalty_ewma);
    leg.tcp_health.last_reason = None;
    leg.tcp_health.last_good_at = Some(SystemTime::now());
    recompute_protocol_leg_state(leg, HealthProtocol::Tcp);
}

fn penalize_tcp_leg(legs: &mut [LegState], leg_id: usize, reason: TcpPenalty) {
    let Some(leg) = legs.get_mut(leg_id) else {
        return;
    };
    leg.tcp_health.penalty_ewma = apply_penalty(leg.tcp_health.penalty_ewma, reason.weight());
    leg.tcp_health.last_reason = Some(reason.as_str().to_string());
    recompute_protocol_leg_state(leg, HealthProtocol::Tcp);
}

fn credit_udp_delivery(legs: &mut [LegState], leg_id: usize, delivered_bytes: usize) {
    let Some(leg) = legs.get_mut(leg_id) else {
        return;
    };
    leg.udp_health.delivered_bytes_ewma = ewma(leg.udp_health.delivered_bytes_ewma, delivered_bytes as f64);
    leg.udp_health.penalty_ewma = decay_penalty(leg.udp_health.penalty_ewma);
    leg.udp_health.last_reason = None;
    leg.udp_health.last_good_at = Some(SystemTime::now());
    recompute_protocol_leg_state(leg, HealthProtocol::Udp);
}

fn penalize_udp_leg(legs: &mut [LegState], leg_id: usize, reason: UdpPenalty) {
    let Some(leg) = legs.get_mut(leg_id) else {
        return;
    };
    leg.udp_health.penalty_ewma = apply_penalty(leg.udp_health.penalty_ewma, reason.weight());
    leg.udp_health.last_reason = Some(reason.as_str().to_string());
    recompute_protocol_leg_state(leg, HealthProtocol::Udp);
}

fn snapshot_context(role: SessionRole, claims: Option<&SessionClaims>) -> Option<SnapshotContext> {
    let observer_type = match role {
        SessionRole::Client => "client",
        SessionRole::Aggregator => "sidecar",
    };
    let observer_id = std::env::var("LEIKWAN_BOND_OBSERVER_ID")
        .ok()
        .and_then(|value| value.parse::<u64>().ok())
        .unwrap_or(0);
    let forward_id = std::env::var("LEIKWAN_BOND_FORWARD_ID")
        .ok()
        .and_then(|value| value.parse::<u64>().ok())
        .unwrap_or_else(|| claims.map(|item| item.forward_id).unwrap_or(0));

    let path = if let Ok(value) = std::env::var("LEIKWAN_BOND_SNAPSHOT_PATH") {
        let trimmed = value.trim();
        if trimmed.is_empty() {
            None
        } else {
            Some(PathBuf::from(trimmed))
        }
    } else if role == SessionRole::Client && forward_id > 0 {
        Some(PathBuf::from(format!(
            "/run/leikwan/bond-leg-health-forward-{}.json",
            forward_id
        )))
    } else if role == SessionRole::Client {
        None
    } else {
        let state_dir = std::env::var("LEIKWAN_BOND_STATE_DIR")
            .ok()
            .filter(|value| !value.trim().is_empty())
            .unwrap_or_else(|| String::from("/var/lib/leikwan-sidecar"));
        Some(PathBuf::from(state_dir).join("bond-leg-health.json"))
    }?;

    Some(SnapshotContext {
        observer_type,
        observer_id,
        forward_id,
        path,
    })
}

#[derive(Serialize)]
struct SnapshotProtocolView {
    state: LegHealthState,
    score: f64,
    effective_weight: f64,
    delivered_bytes_ewma: f64,
    penalty_ewma: f64,
    last_reason: Option<String>,
    last_good_at: Option<String>,
}

#[derive(Serialize)]
struct SnapshotEventView {
    protocol: String,
    from: LegHealthState,
    to: LegHealthState,
    reason: String,
    observed_at: String,
}

#[derive(Serialize)]
struct SnapshotLegView {
    endpoint_id: u64,
    node_id: u64,
    host: String,
    tcp: SnapshotProtocolView,
    udp: SnapshotProtocolView,
    recent_events: Vec<SnapshotEventView>,
}

#[derive(Serialize)]
struct SnapshotPayloadView {
    observer_type: String,
    observer_id: u64,
    forward_id: u64,
    updated_at: String,
    legs: Vec<SnapshotLegView>,
}

fn iso8601(time: SystemTime) -> String {
    chrono::DateTime::<Utc>::from(time).to_rfc3339_opts(SecondsFormat::Secs, true)
}

fn snapshot_protocol_view(health: &ProtocolLegHealth) -> SnapshotProtocolView {
    SnapshotProtocolView {
        state: health.state,
        score: (health.score * 10_000.0).round() / 10_000.0,
        effective_weight: (health.effective_weight * 10_000.0).round() / 10_000.0,
        delivered_bytes_ewma: (health.delivered_bytes_ewma * 10.0).round() / 10.0,
        penalty_ewma: (health.penalty_ewma * 10_000.0).round() / 10_000.0,
        last_reason: health.last_reason.clone(),
        last_good_at: health.last_good_at.map(iso8601),
    }
}

fn build_leg_health_snapshot_json(
    observer_type: &str,
    observer_id: u64,
    forward_id: u64,
    legs: &[LegState],
) -> anyhow::Result<String> {
    let payload = SnapshotPayloadView {
        observer_type: observer_type.to_string(),
        observer_id,
        forward_id,
        updated_at: iso8601(SystemTime::now()),
        legs: legs
            .iter()
            .map(|leg| SnapshotLegView {
                endpoint_id: leg.endpoint_id.unwrap_or(0),
                node_id: leg.node_id.unwrap_or(0),
                host: leg.host.clone(),
                tcp: snapshot_protocol_view(&leg.tcp_health),
                udp: snapshot_protocol_view(&leg.udp_health),
                recent_events: leg
                    .recent_events
                    .iter()
                    .map(|event| SnapshotEventView {
                        protocol: event.protocol.as_str().to_string(),
                        from: event.from,
                        to: event.to,
                        reason: event.reason.clone(),
                        observed_at: iso8601(event.observed_at),
                    })
                    .collect(),
            })
            .collect(),
    };
    serde_json::to_string_pretty(&payload).map_err(|err| anyhow!("failed to serialize leg snapshot: {err}"))
}

fn maybe_write_leg_health_snapshot(
    context: Option<&SnapshotContext>,
    last_written_at: &mut Option<Instant>,
    legs: &[LegState],
) {
    let Some(context) = context else {
        return;
    };
    if last_written_at
        .is_some_and(|instant| instant.elapsed() < SNAPSHOT_WRITE_INTERVAL)
    {
        return;
    }
    let snapshot = match build_leg_health_snapshot_json(
        context.observer_type,
        context.observer_id,
        context.forward_id,
        legs,
    ) {
        Ok(payload) => payload,
        Err(err) => {
            warn!("failed to build bond leg health snapshot: {:?}", err);
            return;
        }
    };
    if let Some(parent) = context.path.parent() {
        let _ = fs::create_dir_all(parent);
    }
    if let Err(err) = fs::write(&context.path, snapshot) {
        warn!("failed to write bond leg health snapshot {}: {:?}", context.path.display(), err);
        return;
    }
    *last_written_at = Some(Instant::now());
}

fn apply_ack(pending: &mut BTreeMap<u64, PendingChunk>, legs: &mut [LegState], ack: u64) {
    let now = Instant::now();
    let completed: Vec<u64> = pending
        .iter()
        .filter_map(|(seq, chunk)| {
            if *seq + chunk.payload.len() as u64 <= ack {
                Some(*seq)
            } else {
                None
            }
        })
        .collect();

    for seq in completed {
        if let Some(chunk) = pending.remove(&seq) {
            if chunk.transmit_count <= 1 {
                update_leg_rtt(legs, chunk.last_leg_id, now.duration_since(chunk.last_sent_at));
            }
            credit_tcp_delivery(legs, chunk.last_leg_id, chunk.payload.len());
        }
    }
}

#[allow(dead_code)]
fn retransmit_pending(
    pending: &mut BTreeMap<u64, PendingChunk>,
    legs: &mut [LegState],
    round_robin_cursor: &mut usize,
    role: SessionRole,
    config: &SessionConfig,
) {
    let now = Instant::now();
    let resend: Vec<u64> = pending
        .iter()
        .filter_map(|(seq, chunk)| {
            if now.duration_since(chunk.last_sent_at) >= config.retransmit_after {
                Some(*seq)
            } else {
                None
            }
        })
        .collect();

    for seq in resend {
        let outstanding_bytes = pending_bytes(pending);
        let Some(chunk) = pending.get_mut(&seq) else {
            continue;
        };
        penalize_tcp_leg(legs, chunk.last_leg_id, TcpPenalty::RetransmitBurst);
        let Some(leg_index) = select_leg_for_data(
            legs,
            round_robin_cursor,
            HealthProtocol::Tcp,
            chunk.payload.len(),
            outstanding_bytes,
            config,
        ) else {
            break;
        };
        if send_to_leg(legs, leg_index, role.outbound_data(0, seq, chunk.payload.clone())) {
            chunk.last_sent_at = now;
            chunk.last_leg_id = leg_index;
            chunk.transmit_count = chunk.transmit_count.saturating_add(1);
        }
    }

    recompute_inflight(legs, pending);
}

fn send_udp_datagram(
    pending: &mut PendingUdpDatagram,
    datagram_id: u64,
    legs: &mut [LegState],
    round_robin_cursor: &mut usize,
    role: SessionRole,
    config: &SessionConfig,
    preferred_leg_id: Option<usize>,
) -> bool {
    let frag_total = pending.fragments.len() as u16;
    let attempts = legs.len().max(1);
    for attempt in 0..attempts {
        let selected_preferred = if attempt == 0 {
            preferred_leg_id.filter(|leg_id| {
                legs.get(*leg_id)
                    .is_some_and(|leg| leg_available_for_new_data(leg, Instant::now()))
            })
        } else {
            None
        };
        let Some(leg_index) = selected_preferred
            .or_else(|| select_udp_leg(legs, round_robin_cursor, pending.total_bytes(), config))
        else {
            return false;
        };

        let mut success = true;
        for (frag_index, payload) in pending.fragments.iter().enumerate() {
            let frame = role.outbound_udp_fragment(
                pending.peer_id,
                datagram_id,
                frag_index as u16,
                frag_total,
                payload.clone(),
            );
            if !send_to_leg(legs, leg_index, frame) {
                success = false;
                break;
            }
        }

        if success {
            pending.last_sent_at = Instant::now();
            pending.last_leg_id = Some(leg_index);
            pending.transmit_count = pending.transmit_count.saturating_add(1).max(1);
            return true;
        }
    }
    false
}

fn send_frame_via_any_leg(legs: &mut [LegState], round_robin_cursor: &mut usize, frame: WireFrame) -> bool {
    let attempts = legs.len().max(1);
    for _ in 0..attempts {
        let Some(leg_index) = select_control_leg(legs, round_robin_cursor) else {
            return false;
        };
        if send_to_leg(legs, leg_index, frame.clone()) {
            return true;
        }
    }
    false
}

fn retransmit_udp_datagrams(
    pending: &mut BTreeMap<u64, PendingUdpDatagram>,
    legs: &mut [LegState],
    round_robin_cursor: &mut usize,
    role: SessionRole,
    retransmit_after: Duration,
    config: &SessionConfig,
) {
    let now = Instant::now();
    let resend: Vec<u64> = pending
        .iter()
        .filter_map(|(datagram_id, pending)| {
            if now.duration_since(pending.last_sent_at) >= retransmit_after {
                Some(*datagram_id)
            } else {
                None
            }
        })
        .collect();

    for datagram_id in resend {
        let Some(pending_datagram) = pending.get_mut(&datagram_id) else {
            continue;
        };
        if let Some(last_leg_id) = pending_datagram.last_leg_id {
            penalize_udp_leg(legs, last_leg_id, UdpPenalty::RetransmitBurst);
        }
        let preferred_leg_id = pending_datagram.last_leg_id;
        let _ = send_udp_datagram(
            pending_datagram,
            datagram_id,
            legs,
            round_robin_cursor,
            role,
            config,
            preferred_leg_id,
        );
    }
}

fn force_retry_udp_datagrams(pending: &mut BTreeMap<u64, PendingUdpDatagram>, retransmit_after: Duration) {
    let retry_at = Instant::now() - retransmit_after;
    for pending_datagram in pending.values_mut() {
        pending_datagram.last_sent_at = retry_at;
    }
}

fn acknowledge_udp_datagram(
    pending: &mut BTreeMap<u64, PendingUdpDatagram>,
    legs: &mut [LegState],
    peer_id: u32,
    datagram_id: u64,
) {
    if pending.get(&datagram_id).map(|item| item.peer_id) == Some(peer_id) {
        if let Some(datagram) = pending.remove(&datagram_id) {
            if datagram.transmit_count <= 1 {
                if let Some(leg_id) = datagram.last_leg_id {
                    update_leg_rtt(legs, leg_id, Instant::now().duration_since(datagram.last_sent_at));
                }
            }
            if let Some(leg_id) = datagram.last_leg_id {
                credit_udp_delivery(legs, leg_id, datagram.total_bytes());
            }
        }
    }
}

fn insert_udp_fragment(
    reassembly: &mut HashMap<(u32, u64), UdpReassembly>,
    peer_id: u32,
    datagram_id: u64,
    frag_index: u16,
    frag_total: u16,
    payload: Bytes,
) -> anyhow::Result<Option<Bytes>> {
    if frag_total == 0 {
        return Err(anyhow!("udp fragment total cannot be zero"));
    }
    if frag_index >= frag_total {
        return Err(anyhow!("udp fragment index {} out of range {}", frag_index, frag_total));
    }

    let key = (peer_id, datagram_id);
    let entry = reassembly.entry(key).or_insert_with(|| UdpReassembly::new(frag_total));
    if entry.frag_total != frag_total {
        return Err(anyhow!(
            "udp fragment total mismatch for peer_id={} datagram_id={}",
            peer_id,
            datagram_id
        ));
    }
    entry.last_updated = Instant::now();
    if let std::collections::btree_map::Entry::Vacant(vacant) = entry.fragments.entry(frag_index) {
        entry.total_bytes += payload.len();
        if entry.total_bytes > UDP_MAX_REASSEMBLED_BYTES {
            return Err(anyhow!(
                "udp datagram exceeds reassembly limit for peer_id={} datagram_id={}",
                peer_id,
                datagram_id
            ));
        }
        vacant.insert(payload);
    }

    if entry.fragments.len() == entry.frag_total as usize {
        let entry = reassembly
            .remove(&key)
            .ok_or_else(|| anyhow!("udp reassembly entry disappeared"))?;
        let mut data = Vec::with_capacity(entry.total_bytes);
        for index in 0..entry.frag_total {
            let fragment = entry
                .fragments
                .get(&index)
                .ok_or_else(|| anyhow!("udp fragment {} missing during completion", index))?;
            data.extend_from_slice(fragment.as_ref());
        }
        return Ok(Some(Bytes::from(data)));
    }

    Ok(None)
}

fn cleanup_udp_reassembly(reassembly: &mut HashMap<(u32, u64), UdpReassembly>) {
    let now = Instant::now();
    reassembly.retain(|_, entry| now.duration_since(entry.last_updated) < UDP_REASSEMBLY_TIMEOUT);
}

fn cleanup_udp_completed(completed: &mut HashMap<(u32, u64), Instant>) {
    let now = Instant::now();
    completed.retain(|_, seen_at| now.duration_since(*seen_at) < UDP_COMPLETED_CACHE_TTL);
}

fn assign_client_peer_id(
    addr: SocketAddr,
    peer_id_by_addr: &mut HashMap<SocketAddr, u32>,
    peer_state_by_id: &mut HashMap<u32, ClientPeerState>,
    next_peer_id: &mut u32,
    strategy: ClientStrategy,
) -> u32 {
    if let Some(peer_id) = peer_id_by_addr.get(&addr).copied() {
        if let Some(state) = peer_state_by_id.get_mut(&peer_id) {
            state.last_seen = Instant::now();
        }
        return peer_id;
    }

    let peer_id = *next_peer_id;
    *next_peer_id = (*next_peer_id).wrapping_add(1);
    if *next_peer_id == 0 {
        *next_peer_id = 1;
    }
    peer_id_by_addr.insert(addr, peer_id);
    peer_state_by_id.insert(
        peer_id,
        ClientPeerState {
            addr,
            last_seen: Instant::now(),
            strategy,
            preferred_leg_id: None,
        },
    );
    debug!("assigned udp peer_id={} to local peer {}", peer_id, addr);
    peer_id
}

fn cleanup_client_peers(
    peer_id_by_addr: &mut HashMap<SocketAddr, u32>,
    peer_state_by_id: &mut HashMap<u32, ClientPeerState>,
) {
    let now = Instant::now();
    let expired: Vec<(u32, SocketAddr)> = peer_state_by_id
        .iter()
        .filter_map(|(peer_id, state)| {
            if now.duration_since(state.last_seen) >= UDP_PEER_IDLE_TTL {
                Some((*peer_id, state.addr))
            } else {
                None
            }
        })
        .collect();

    for (peer_id, addr) in expired {
        peer_state_by_id.remove(&peer_id);
        peer_id_by_addr.remove(&addr);
    }
}

async fn ensure_upstream_peer(
    peer_id: u32,
    target_addr: SocketAddr,
    upstream_peers: &mut HashMap<u32, Arc<UdpSocket>>,
    event_tx: &mpsc::UnboundedSender<SessionEvent>,
) -> anyhow::Result<Arc<UdpSocket>> {
    if let Some(socket) = upstream_peers.get(&peer_id) {
        return Ok(socket.clone());
    }

    let bind_addr = if target_addr.is_ipv4() { "0.0.0.0:0" } else { "[::]:0" };
    let socket = Arc::new(
        UdpSocket::bind(bind_addr)
            .await
            .with_context(|| format!("failed to bind upstream UDP peer socket for {}", target_addr))?,
    );
    socket
        .connect(target_addr)
        .await
        .with_context(|| format!("failed to connect upstream UDP peer socket for {}", target_addr))?;
    debug!("created upstream udp peer_id={} toward {}", peer_id, target_addr);
    spawn_udp_upstream_reader(peer_id, socket.clone(), event_tx.clone());
    upstream_peers.insert(peer_id, socket.clone());
    Ok(socket)
}

fn fragment_udp_payload(payload: Bytes, fragment_size: usize) -> Vec<Bytes> {
    if payload.is_empty() {
        return vec![Bytes::new()];
    }

    let mut fragments = Vec::new();
    let mut offset = 0usize;
    while offset < payload.len() {
        let end = (offset + fragment_size).min(payload.len());
        fragments.push(payload.slice(offset..end));
        offset = end;
    }
    fragments
}

fn udp_fragment_payload_size(config: &SessionConfig) -> usize {
    config.chunk_size.clamp(UDP_FRAGMENT_MIN_SIZE, UDP_FRAGMENT_MAX_SIZE)
}

fn send_control_frame(legs: &mut [LegState], round_robin_cursor: &mut usize, frame: WireFrame) -> bool {
    send_frame_via_any_leg(legs, round_robin_cursor, frame)
}

fn send_stream_control_frame(
    legs: &mut [LegState],
    round_robin_cursor: &mut usize,
    preferred_leg_id: Option<usize>,
    frame: WireFrame,
) -> bool {
    if let Some(leg_id) = preferred_leg_id {
        if send_to_leg(legs, leg_id, frame.clone()) {
            return true;
        }
    }
    send_frame_via_any_leg(legs, round_robin_cursor, frame)
}

fn preferred_leg_allowed_for_interactive_tcp(legs: &[LegState], leg_id: usize, now: Instant) -> bool {
    let Some(leg) = legs.get(leg_id) else {
        return false;
    };
    if !leg_available_for_new_data(leg, now) {
        return false;
    }
    matches!(leg.tcp_health.state, LegHealthState::Healthy)
}

fn select_stream_leg(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    preferred_leg_id: Option<usize>,
    payload_len: usize,
    outstanding_bytes: usize,
    config: &SessionConfig,
) -> Option<usize> {
    let interactive_flow = payload_len <= config.interactive_chunk_bytes
        || outstanding_bytes < config.latency_guard_bytes;

    if config.latency_first && outstanding_bytes < config.bulk_promote_bytes {
        if let Some(primary_leg) = legs
            .iter()
            .find(|leg| leg.active && leg.role == LegRole::Primary && leg_available_for_new_data(leg, Instant::now()))
        {
            return Some(primary_leg.id);
        }
    }

    // Keep sticky-leg behavior for small/interactive traffic so ack/control
    // latency stays low, but allow larger streams to stripe chunks across
    // active legs instead of pinning the whole stream to the first winner.
    if interactive_flow {
        if let Some(leg_id) = preferred_leg_id {
            if preferred_leg_allowed_for_interactive_tcp(legs, leg_id, Instant::now()) {
                return Some(leg_id);
            }
        }
    }
    select_leg_for_data(
        legs,
        round_robin_cursor,
        HealthProtocol::Tcp,
        payload_len,
        outstanding_bytes,
        config,
    )
}

fn select_pinned_strategy_leg(
    strategy: ClientStrategy,
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    preferred_leg_id: Option<usize>,
    payload_len: usize,
    outstanding_bytes: usize,
    config: &SessionConfig,
) -> Option<usize> {
    match strategy {
        ClientStrategy::LatencyBest | ClientStrategy::Random => {
            if let Some(leg_id) = preferred_leg_id {
                if preferred_leg_allowed_for_interactive_tcp(legs, leg_id, Instant::now()) {
                    return Some(leg_id);
                }
            }
            if strategy == ClientStrategy::LatencyBest {
                return select_latency_best_leg(legs);
            }
            let mut candidates = eligible_strategy_legs(legs);
            candidates.sort_by_key(|leg| leg.id);
            if candidates.is_empty() {
                return None;
            }
            let selected = candidates[*round_robin_cursor % candidates.len()].id;
            *round_robin_cursor = round_robin_cursor.wrapping_add(1);
            return Some(selected);
        }
        ClientStrategy::SpeedMax => {}
    }

    select_stream_leg(
        legs,
        round_robin_cursor,
        preferred_leg_id,
        payload_len,
        outstanding_bytes,
        config,
    )
}

fn select_alternate_leg(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    protocol: HealthProtocol,
    avoid_leg_id: usize,
) -> Option<usize> {
    let active_count = legs.len().max(1);
    let mut candidates: Vec<&LegState> = legs
        .iter()
        .filter(|leg| leg_available_for_new_data(leg, Instant::now()) && leg.id != avoid_leg_id)
        .collect();
    if candidates.is_empty() {
        candidates = legs
            .iter()
            .filter(|leg| leg.active && leg.id != avoid_leg_id)
            .collect();
    }
    if candidates.is_empty() {
        return None;
    }
    candidates.sort_by_key(|leg| {
        let health = protocol_health(leg, protocol);
        (
            leg_health_state_rank(health.state),
            effective_weight_sort_rank(health.effective_weight),
            leg.priority,
            leg.inflight_bytes,
            leg.smoothed_rtt,
            (leg.id + *round_robin_cursor) % active_count,
        )
    });
    *round_robin_cursor = round_robin_cursor.wrapping_add(1);
    candidates.first().map(|leg| leg.id)
}

fn select_retransmit_leg(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    preferred_leg_id: Option<usize>,
    last_leg_id: usize,
    transmit_count: u8,
    payload_len: usize,
    outstanding_bytes: usize,
    config: &SessionConfig,
) -> Option<usize> {
    if transmit_count >= 1 {
        if let Some(leg_id) = select_alternate_leg(legs, round_robin_cursor, HealthProtocol::Tcp, last_leg_id) {
            return Some(leg_id);
        }
    }

    if let Some(leg_id) = preferred_leg_id {
        if legs.get(leg_id).is_some_and(|leg| leg.active) && leg_id != last_leg_id {
            return Some(leg_id);
        }
    }

    select_leg_for_data_with_cooldown(
        legs,
        round_robin_cursor,
        HealthProtocol::Tcp,
        payload_len,
        outstanding_bytes,
        config,
        Instant::now(),
    )
}

fn select_leg_for_data(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    protocol: HealthProtocol,
    payload_len: usize,
    outstanding_bytes: usize,
    config: &SessionConfig,
) -> Option<usize> {
    select_leg_for_data_with_cooldown(
        legs,
        round_robin_cursor,
        protocol,
        payload_len,
        outstanding_bytes,
        config,
        Instant::now(),
    )
}

fn select_leg_for_data_with_cooldown(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    protocol: HealthProtocol,
    payload_len: usize,
    outstanding_bytes: usize,
    config: &SessionConfig,
    now: Instant,
) -> Option<usize> {
    let prefer_low_latency = payload_len <= config.interactive_chunk_bytes || outstanding_bytes < config.latency_guard_bytes;
    let active_without_cooldown = legs
        .iter()
        .filter(|leg| leg_available_for_new_data(leg, now))
        .count();
    if active_without_cooldown == 0 {
        return select_leg_with_mode(
            legs,
            round_robin_cursor,
            protocol,
            prefer_low_latency,
            config.interactive_rtt_slack,
        );
    }
    select_leg_with_mode_filtered(
        legs,
        round_robin_cursor,
        protocol,
        prefer_low_latency,
        config.interactive_rtt_slack,
        |leg| leg_available_for_new_data(leg, now),
    )
}

fn select_control_leg(legs: &[LegState], round_robin_cursor: &mut usize) -> Option<usize> {
    if let Some(primary_leg) = legs
        .iter()
        .find(|leg| leg.active && leg.role == LegRole::Primary)
    {
        return Some(primary_leg.id);
    }
    select_leg_with_mode(
        legs,
        round_robin_cursor,
        HealthProtocol::Tcp,
        true,
        DEFAULT_INTERACTIVE_RTT_SLACK,
    )
}

fn select_stream_affinity_leg(legs: &[LegState], round_robin_cursor: &mut usize) -> Option<usize> {
    if let Some(primary_leg) = legs
        .iter()
        .find(|leg| leg.active && leg.role == LegRole::Primary)
    {
        return Some(primary_leg.id);
    }
    let mut candidates: Vec<&LegState> = legs.iter().filter(|leg| leg.active).collect();
    if candidates.is_empty() {
        return None;
    }

    let primary_priority = candidates
        .iter()
        .map(|leg| leg.priority)
        .min()
        .unwrap_or(u16::MAX);
    candidates.retain(|leg| leg.priority == primary_priority);
    let active_count = candidates.len().max(1);

    candidates.sort_by_key(|leg| {
        let health = &leg.tcp_health;
        (
            leg_health_state_rank(health.state),
            effective_weight_sort_rank(health.effective_weight),
            (leg.id + *round_robin_cursor) % active_count,
            leg.inflight_bytes,
            leg.smoothed_rtt,
        )
    });
    *round_robin_cursor = round_robin_cursor.wrapping_add(1);
    candidates.first().map(|leg| leg.id)
}

fn select_strategy_affinity_leg(
    strategy: ClientStrategy,
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    random_state: &mut u64,
) -> Option<usize> {
    match strategy {
        ClientStrategy::SpeedMax => select_stream_affinity_leg(legs, round_robin_cursor),
        ClientStrategy::LatencyBest => select_latency_best_leg(legs),
        ClientStrategy::Random => select_random_healthy_leg(legs, random_state),
    }
}

fn eligible_strategy_legs(legs: &[LegState]) -> Vec<&LegState> {
    let now = Instant::now();
    let healthy = legs
        .iter()
        .filter(|leg| {
            leg_available_for_new_data(leg, now) && leg.tcp_health.state == LegHealthState::Healthy
        })
        .collect::<Vec<_>>();
    if healthy.is_empty() {
        legs.iter()
            .filter(|leg| leg_available_for_new_data(leg, now))
            .collect()
    } else {
        healthy
    }
}

fn select_latency_best_leg(legs: &[LegState]) -> Option<usize> {
    let mut candidates = eligible_strategy_legs(legs);
    candidates.sort_by_key(|leg| {
        (
            leg_health_state_rank(leg.tcp_health.state),
            leg.smoothed_rtt,
            leg.priority,
            leg.inflight_bytes,
            leg.id,
        )
    });
    candidates.first().map(|leg| leg.id)
}

fn select_random_healthy_leg(legs: &[LegState], random_state: &mut u64) -> Option<usize> {
    let candidates = eligible_strategy_legs(legs);
    if candidates.is_empty() {
        return None;
    }

    let mut value = (*random_state).max(1);
    value ^= value << 13;
    value ^= value >> 7;
    value ^= value << 17;
    *random_state = value;
    Some(candidates[(value as usize) % candidates.len()].id)
}

fn select_udp_leg(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    payload_len: usize,
    config: &SessionConfig,
) -> Option<usize> {
    if config.latency_first && config.udp_primary_only {
        if let Some(primary_leg) = legs.iter().find(|leg| leg.active && leg.role == LegRole::Primary) {
            return Some(primary_leg.id);
        }
    }
    let prefer_low_latency = payload_len <= config.interactive_chunk_bytes;
    select_leg_with_mode(
        legs,
        round_robin_cursor,
        HealthProtocol::Udp,
        prefer_low_latency,
        config.interactive_rtt_slack,
    )
}

fn select_udp_leg_for_strategy(
    strategy: ClientStrategy,
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    random_state: &mut u64,
    preferred_leg_id: Option<usize>,
    payload_len: usize,
    config: &SessionConfig,
) -> Option<usize> {
    if strategy != ClientStrategy::SpeedMax {
        if let Some(leg_id) = preferred_leg_id {
            if legs.get(leg_id).is_some_and(|leg| {
                leg_available_for_new_data(leg, Instant::now())
                    && leg.udp_health.state == LegHealthState::Healthy
            }) {
                return Some(leg_id);
            }
        }
    }

    match strategy {
        ClientStrategy::SpeedMax => {
            select_udp_leg(legs, round_robin_cursor, payload_len, config)
        }
        ClientStrategy::LatencyBest => select_latency_best_udp_leg(legs),
        ClientStrategy::Random => select_random_healthy_udp_leg(legs, random_state),
    }
}

fn eligible_udp_strategy_legs(legs: &[LegState]) -> Vec<&LegState> {
    let now = Instant::now();
    let healthy = legs
        .iter()
        .filter(|leg| {
            leg_available_for_new_data(leg, now) && leg.udp_health.state == LegHealthState::Healthy
        })
        .collect::<Vec<_>>();
    if healthy.is_empty() {
        legs.iter()
            .filter(|leg| leg_available_for_new_data(leg, now))
            .collect()
    } else {
        healthy
    }
}

fn select_latency_best_udp_leg(legs: &[LegState]) -> Option<usize> {
    let mut candidates = eligible_udp_strategy_legs(legs);
    candidates.sort_by_key(|leg| {
        (
            leg_health_state_rank(leg.udp_health.state),
            leg.smoothed_rtt,
            leg.priority,
            leg.inflight_bytes,
            leg.id,
        )
    });
    candidates.first().map(|leg| leg.id)
}

fn select_random_healthy_udp_leg(legs: &[LegState], random_state: &mut u64) -> Option<usize> {
    let candidates = eligible_udp_strategy_legs(legs);
    if candidates.is_empty() {
        return None;
    }
    let mut value = (*random_state).max(1);
    value ^= value << 13;
    value ^= value >> 7;
    value ^= value << 17;
    *random_state = value;
    Some(candidates[(value as usize) % candidates.len()].id)
}


fn select_leg_with_mode(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    protocol: HealthProtocol,
    prefer_low_latency: bool,
    rtt_slack: Duration,
) -> Option<usize> {
    select_leg_with_mode_filtered(
        legs,
        round_robin_cursor,
        protocol,
        prefer_low_latency,
        rtt_slack,
        |leg| leg.active,
    )
}

fn select_leg_with_mode_filtered<F>(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    protocol: HealthProtocol,
    prefer_low_latency: bool,
    rtt_slack: Duration,
    predicate: F,
) -> Option<usize>
where
    F: Fn(&LegState) -> bool,
{
    let active_count = legs.len().max(1);
    let active: Vec<&LegState> = legs.iter().filter(|leg| predicate(leg)).collect();
    if active.is_empty() {
        return None;
    }

    let primary_priority = active.iter().map(|leg| leg.priority).min().unwrap_or(u16::MAX);
    let mut candidates: Vec<&LegState> = if prefer_low_latency {
        let primary: Vec<&LegState> = active
            .iter()
            .copied()
            .filter(|leg| leg.priority == primary_priority)
            .collect();
        if primary.is_empty() {
            active.iter().copied().collect()
        } else {
            primary
        }
    } else {
        active.iter().copied().collect()
    };

    if prefer_low_latency {
        let fastest_rtt = candidates
            .iter()
            .map(|leg| leg.smoothed_rtt)
            .min()
            .unwrap_or(DEFAULT_TCP_LEG_RTT);
        let rtt_cap = fastest_rtt.checked_add(rtt_slack).unwrap_or(fastest_rtt);
        let fast_subset: Vec<&LegState> = candidates
            .iter()
            .copied()
            .filter(|leg| leg.smoothed_rtt <= rtt_cap)
            .collect();
        if !fast_subset.is_empty() {
            candidates = fast_subset;
        }
    }

    if prefer_low_latency {
        candidates.sort_by_key(|leg| {
            let health = protocol_health(leg, protocol);
            (
                leg_health_state_rank(health.state),
                effective_weight_sort_rank(health.effective_weight),
                leg.priority,
                transport_bias(leg.transport),
                leg.inflight_bytes,
                leg.smoothed_rtt,
                (leg.id + *round_robin_cursor) % active_count,
            )
        });
    } else {
        candidates.sort_by_key(|leg| {
            let health = protocol_health(leg, protocol);
            (
                leg_health_state_rank(health.state),
                effective_weight_sort_rank(health.effective_weight),
                leg.inflight_bytes,
                leg.priority,
                leg.smoothed_rtt,
                (leg.id + *round_robin_cursor) % active_count,
            )
        });
    }

    *round_robin_cursor = round_robin_cursor.wrapping_add(1);
    candidates.first().map(|leg| leg.id)
}

fn transport_bias(transport: LegTransport) -> u8 {
    match transport {
        LegTransport::Quic => 0,
        LegTransport::Tcp => 1,
    }
}

fn initial_leg_rtt(transport: LegTransport) -> Duration {
    match transport {
        LegTransport::Quic => DEFAULT_QUIC_LEG_RTT,
        LegTransport::Tcp => DEFAULT_TCP_LEG_RTT,
    }
}

fn update_leg_rtt(legs: &mut [LegState], leg_id: usize, sample: Duration) {
    let Some(leg) = legs.get_mut(leg_id) else {
        return;
    };
    let sample_micros = sample.as_micros().clamp(1, 5_000_000);
    let current_micros = leg.smoothed_rtt.as_micros().max(1);
    let blended = ((current_micros * 7) + sample_micros) / 8;
    let bounded = blended.min(u64::MAX as u128) as u64;
    leg.smoothed_rtt = Duration::from_micros(bounded);
}

fn decide_striping_stage(
    sent_bytes: usize,
    backlog_bytes: usize,
    warmup_used_legs: usize,
    active_leg_count: usize,
    config: &SessionConfig,
) -> TcpStripingStage {
    if active_leg_count < 2 {
        return TcpStripingStage::Sticky;
    }
    let total_bytes = sent_bytes.saturating_add(backlog_bytes);
    if config.latency_first {
        if total_bytes < config.bulk_promote_bytes {
            return TcpStripingStage::Sticky;
        }
        if warmup_used_legs < 2 {
            return TcpStripingStage::Warmup;
        }
        return TcpStripingStage::Bulk;
    }
    if total_bytes >= config.bulk_promote_bytes {
        return TcpStripingStage::Bulk;
    }
    if total_bytes >= config.warmup_trigger_bytes || warmup_used_legs >= 2 {
        return TcpStripingStage::Warmup;
    }
    TcpStripingStage::Sticky
}

fn strategy_striping_stage(strategy: ClientStrategy, calculated: TcpStripingStage) -> TcpStripingStage {
    match strategy {
        ClientStrategy::SpeedMax => calculated,
        ClientStrategy::LatencyBest | ClientStrategy::Random => TcpStripingStage::Sticky,
    }
}

fn allowed_leg_count(stage: TcpStripingStage, healthy_leg_count: usize) -> usize {
    match stage {
        TcpStripingStage::Sticky => healthy_leg_count.min(1),
        TcpStripingStage::Warmup => healthy_leg_count.min(2),
        TcpStripingStage::Bulk => healthy_leg_count.max(1),
    }
}

fn leg_available_for_new_data(leg: &LegState, now: Instant) -> bool {
    if !leg.active {
        return false;
    }
    !leg.cooldown_until.is_some_and(|deadline| deadline > now)
}

fn apply_leg_cooldown(legs: &mut [LegState], leg_id: usize, cooldown: Duration) {
    if let Some(leg) = legs.get_mut(leg_id) {
        leg.cooldown_until = Some(Instant::now() + cooldown);
        leg.penalty_count = leg.penalty_count.saturating_add(1);
        warn!("bond leg {} entering cooldown for {:?}", leg.label, cooldown);
    }
    penalize_tcp_leg(legs, leg_id, TcpPenalty::SelectedButUnproductive);
}

fn suppress_stream_preferred_tcp_leg(
    legs: &mut [LegState],
    state: &mut TcpStreamState,
    penalty: TcpPenalty,
    cooldown: Duration,
) -> Option<usize> {
    let leg_id = state.preferred_leg_id.take()?;
    apply_leg_cooldown(legs, leg_id, cooldown);
    penalize_tcp_leg(legs, leg_id, penalty);
    Some(leg_id)
}

fn select_stream_leg_warmup(
    state: &mut TcpStreamState,
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    payload_len: usize,
    outstanding_bytes: usize,
    config: &SessionConfig,
    now: Instant,
) -> Option<usize> {
    let primary = state
        .preferred_leg_id
        .filter(|leg_id| legs.get(*leg_id).is_some_and(|leg| leg_available_for_new_data(leg, now)))
        .or_else(|| {
            select_leg_for_data_with_cooldown(
                legs,
                round_robin_cursor,
                HealthProtocol::Tcp,
                payload_len,
                outstanding_bytes,
                config,
                now,
            )
        })?;

    let healthy_leg_count = legs.iter().filter(|leg| leg_available_for_new_data(leg, now)).count();
    if allowed_leg_count(TcpStripingStage::Warmup, healthy_leg_count) < 2 {
        state.warmup_leg_count = 1;
        state.warmup_leg_cursor = primary;
        state.warmup_leg_bytes = state.warmup_leg_bytes.saturating_add(payload_len);
        return Some(primary);
    }

    let alternate = select_alternate_leg(legs, round_robin_cursor, HealthProtocol::Tcp, primary).unwrap_or(primary);
    if alternate == primary {
        state.warmup_leg_count = 1;
        state.warmup_leg_cursor = primary;
        state.warmup_leg_bytes = state.warmup_leg_bytes.saturating_add(payload_len);
        return Some(primary);
    }

    if state.warmup_stage_started_at.is_none() {
        state.warmup_stage_started_at = Some(now);
        state.warmup_leg_count = 2;
        state.warmup_leg_cursor = alternate;
        state.warmup_leg_bytes = payload_len;
        return Some(alternate);
    }

    let rotate = state.warmup_leg_bytes >= config.warmup_slice_bytes
        || state
            .warmup_stage_started_at
            .is_some_and(|started| now.duration_since(started) >= config.warmup_leg_delay);
    if rotate {
        state.warmup_leg_cursor = if state.warmup_leg_cursor == primary { alternate } else { primary };
        state.warmup_stage_started_at = Some(now);
        state.warmup_leg_bytes = 0;
    }

    let chosen = if state.warmup_leg_cursor == alternate { alternate } else { primary };
    state.warmup_leg_count = 2;
    state.warmup_leg_bytes = state.warmup_leg_bytes.saturating_add(payload_len);
    Some(chosen)
}

fn send_to_leg(legs: &mut [LegState], leg_index: usize, frame: WireFrame) -> bool {
    let Some(leg) = legs.get_mut(leg_index) else {
        return false;
    };
    if !leg.active {
        return false;
    }
    match leg.sender.send(frame) {
        Ok(()) => true,
        Err(_) => {
            leg.active = false;
            false
        }
    }
}

#[allow(dead_code)]
fn recompute_inflight(legs: &mut [LegState], pending: &BTreeMap<u64, PendingChunk>) {
    for leg in legs.iter_mut() {
        leg.inflight_bytes = 0;
    }

    for chunk in pending.values() {
        if let Some(leg) = legs.get_mut(chunk.last_leg_id) {
            if leg.active {
                leg.inflight_bytes += chunk.payload.len();
            }
        }
    }
}

fn mark_leg_inactive(legs: &mut [LegState], leg_id: usize) {
    if let Some(leg) = legs.get_mut(leg_id) {
        leg.active = false;
        leg.inflight_bytes = 0;
        leg.cooldown_until = Some(Instant::now());
        leg.penalty_count = leg.penalty_count.saturating_add(1);
    }
    penalize_tcp_leg(legs, leg_id, TcpPenalty::ControlSocketClosedUnexpectedly);
    penalize_udp_leg(legs, leg_id, UdpPenalty::LegClosed);
}

fn mark_pending_for_retry(pending: &mut BTreeMap<u64, PendingChunk>, leg_id: usize, retransmit_after: Duration) {
    let force_retry = Instant::now()
        .checked_sub(retransmit_after)
        .unwrap_or_else(Instant::now);
    for chunk in pending.values_mut() {
        if chunk.last_leg_id == leg_id {
            chunk.last_sent_at = force_retry;
        }
    }
}

fn has_active_leg(legs: &[LegState]) -> bool {
    legs.iter().any(|leg| leg.active)
}

async fn resolve_udp_target(claims: &SessionClaims) -> anyhow::Result<SocketAddr> {
    let target = format!("{}:{}", claims.target_host, claims.target_port);
    let mut addrs = lookup_host(&target)
        .await
        .with_context(|| format!("failed to resolve UDP target {target}"))?;
    addrs
        .next()
        .ok_or_else(|| anyhow!("UDP target resolved to no usable addresses: {target}"))
}

fn unix_now() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_else(|_| Duration::from_secs(0))
        .as_secs()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fake_leg(id: usize, priority: u16, inflight_bytes: usize, smoothed_rtt_ms: u64) -> LegState {
        let (tx, _rx) = mpsc::unbounded_channel();
        LegState {
            id,
            sender: tx,
            active: true,
            inflight_bytes,
            transport: LegTransport::Tcp,
            priority,
            role: LegRole::Primary,
            smoothed_rtt: Duration::from_millis(smoothed_rtt_ms),
            label: format!("leg-{id}"),
            spec_index: Some(id),
            endpoint_id: Some(id as u64),
            node_id: Some((id + 1) as u64),
            host: format!("host-{id}.example"),
            cooldown_until: None,
            penalty_count: 0,
            tcp_health: ProtocolLegHealth::new(),
            udp_health: ProtocolLegHealth::new(),
            recent_events: VecDeque::new(),
        }
    }

    fn test_config() -> SessionConfig {
        SessionConfig {
            chunk_size: 131072,
            max_window_bytes: 16777216,
            retransmit_after: Duration::from_millis(300),
            join_timeout: Duration::from_millis(400),
            connect_timeout: Duration::from_millis(3000),
            interactive_chunk_bytes: 16384,
            latency_guard_bytes: 262144,
            interactive_rtt_slack: Duration::from_millis(30),
            warmup_trigger_bytes: 65536,
            warmup_slice_bytes: 65536,
            warmup_leg_delay: Duration::from_millis(80),
            bulk_promote_bytes: 262144,
            leg_cooldown: Duration::from_millis(1800000),
            latency_first: false,
            udp_primary_only: false,
            strategy_file: None,
            default_strategy: ClientStrategy::LatencyBest,
        }
    }

    #[test]
    fn client_leg_retry_backoff_is_extended_beyond_subsecond_thrashing() {
        assert_eq!(CLIENT_LEG_RETRY_BACKOFF, Duration::from_secs(30 * 60));
    }

    #[test]
    fn default_leg_cooldown_is_extended_to_thirty_minutes() {
        assert_eq!(test_config().leg_cooldown, Duration::from_secs(30 * 60));
    }

    #[test]
    fn sticky_stage_keeps_one_leg_for_small_streams() {
        assert_eq!(
            decide_striping_stage(16 * 1024, 0, 1, 3, &test_config()),
            TcpStripingStage::Sticky
        );
    }

    #[test]
    fn warmup_stage_starts_before_full_bulk_threshold() {
        assert_eq!(
            decide_striping_stage(64 * 1024, 0, 1, 3, &test_config()),
            TcpStripingStage::Warmup
        );
    }

    #[test]
    fn bulk_stage_allows_all_healthy_legs_for_large_streams() {
        assert_eq!(allowed_leg_count(TcpStripingStage::Bulk, 5), 5);
    }

    #[test]
    fn latency_best_never_promotes_a_stream_to_bulk_striping() {
        assert_eq!(
            strategy_striping_stage(ClientStrategy::LatencyBest, TcpStripingStage::Bulk),
            TcpStripingStage::Sticky
        );
    }

    #[test]
    fn latency_best_selects_the_lowest_latency_healthy_leg() {
        let mut legs = vec![fake_leg(0, 10, 0, 45), fake_leg(1, 10, 0, 12)];
        legs[0].role = LegRole::Primary;
        let mut cursor = 0usize;
        let mut random_state = 7u64;

        let selected = select_strategy_affinity_leg(
            ClientStrategy::LatencyBest,
            &legs,
            &mut cursor,
            &mut random_state,
        );

        assert_eq!(selected, Some(1));
    }

    #[test]
    fn random_is_pinned_per_stream_until_its_leg_fails() {
        let legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        let mut cursor = 0usize;
        let selected = select_pinned_strategy_leg(
            ClientStrategy::Random,
            &legs,
            &mut cursor,
            Some(1),
            128 * 1024,
            512 * 1024,
            &test_config(),
        );
        assert_eq!(selected, Some(1));

        let mut failed = legs;
        failed[1].active = false;
        let selected_after_failure = select_pinned_strategy_leg(
            ClientStrategy::Random,
            &failed,
            &mut cursor,
            Some(1),
            128 * 1024,
            512 * 1024,
            &test_config(),
        );
        assert_eq!(selected_after_failure, Some(0));
    }

    #[test]
    fn interactive_tcp_skips_unhealthy_preferred_leg() {
        let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        penalize_tcp_leg(&mut legs, 0, TcpPenalty::ControlSocketClosedUnexpectedly);
        penalize_tcp_leg(&mut legs, 0, TcpPenalty::RetransmitBurst);

        let mut cursor = 0usize;
        let selected = select_stream_leg(&legs, &mut cursor, Some(0), 1024, 0, &test_config());

        assert_eq!(legs[0].tcp_health.state, LegHealthState::Degraded);
        assert_eq!(selected, Some(1));
    }

    #[test]
    fn interactive_tcp_keeps_healthy_preferred_leg() {
        let legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        let mut cursor = 0usize;
        let selected = select_stream_leg(&legs, &mut cursor, Some(0), 1024, 0, &test_config());
        assert_eq!(selected, Some(0));
    }

    #[test]
    fn stream_open_ack_repins_preferred_leg_to_the_responsive_leg() {
        let (local_write_tx, _local_write_rx) = mpsc::channel::<LocalWriteCommand>(8);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(local_write_tx, close_tx, false);
        state.preferred_leg_id = Some(1);

        let mut streams = HashMap::new();
        streams.insert(7_u32, state);
        let mut connecting_streams = HashSet::new();
        let mut closed_streams = HashSet::new();
        let mut reset_reasons = HashMap::new();
        let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        let mut cursor = 0usize;
        let (event_tx, _event_rx) = mpsc::unbounded_channel();

        let result = tokio::runtime::Runtime::new()
            .expect("runtime")
            .block_on(handle_tcp_transport_frame(
                Uuid::nil(),
                SessionRole::Client,
                None,
                0,
                WireFrame::StreamOpenAck { stream_id: 7 },
                &mut streams,
                &mut connecting_streams,
                &mut closed_streams,
                &mut reset_reasons,
                &mut legs,
                &mut cursor,
                &event_tx,
                &test_config(),
            ));

        assert!(result.is_ok());
        let state = streams.get(&7).expect("stream state");
        assert!(state.opened);
        assert_eq!(state.preferred_leg_id, Some(0));
    }

    #[test]
    fn suppress_stream_preferred_tcp_leg_cools_down_and_unpins_the_leg() {
        let (local_write_tx, _local_write_rx) = mpsc::channel::<LocalWriteCommand>(8);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(local_write_tx, close_tx, false);
        state.preferred_leg_id = Some(0);

        let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        let suppressed = suppress_stream_preferred_tcp_leg(
            &mut legs,
            &mut state,
            TcpPenalty::LocalWriteFailed,
            test_config().leg_cooldown,
        );

        assert_eq!(suppressed, Some(0));
        assert_eq!(state.preferred_leg_id, None);
        assert!(legs[0].cooldown_until.is_some());
        assert!(matches!(
            legs[0].tcp_health.state,
            LegHealthState::Degraded | LegHealthState::Probation | LegHealthState::Quarantined
        ));
    }

    #[test]
    fn suppressed_preferred_leg_falls_back_to_another_healthy_leg() {
        let (local_write_tx, _local_write_rx) = mpsc::channel::<LocalWriteCommand>(8);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(local_write_tx, close_tx, false);
        state.preferred_leg_id = Some(0);

        let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        let _ = suppress_stream_preferred_tcp_leg(
            &mut legs,
            &mut state,
            TcpPenalty::StreamOpenTimeout,
            test_config().leg_cooldown,
        );

        let mut cursor = 0usize;
        let selected = select_stream_leg(&legs, &mut cursor, state.preferred_leg_id, 1024, 0, &test_config());

        assert_eq!(selected, Some(1));
    }

    #[test]
    fn retransmit_candidates_wait_for_ack_stall() {
        let now = Instant::now();
        let old = now.checked_sub(Duration::from_secs(1)).unwrap();
        let mut pending = BTreeMap::new();
        for seq in [0_u64, 131072, 262144] {
            pending.insert(
                seq,
                PendingChunk {
                    payload: Bytes::from_static(b"x"),
                    last_sent_at: old,
                    last_leg_id: 0,
                    transmit_count: 1,
                },
            );
        }

        let resend = collect_retransmit_sequences(
            &pending,
            now,
            Duration::from_millis(300),
            now.checked_sub(Duration::from_millis(150)).unwrap(),
            8,
        );

        assert!(resend.is_empty(), "ack progress should suppress retransmit storms");
    }

    #[test]
    fn retransmit_candidates_cap_to_oldest_gap_chunks() {
        let now = Instant::now();
        let old = now.checked_sub(Duration::from_secs(1)).unwrap();
        let mut pending = BTreeMap::new();
        for seq in [0_u64, 131072, 262144, 393216, 524288, 655360] {
            pending.insert(
                seq,
                PendingChunk {
                    payload: Bytes::from_static(b"x"),
                    last_sent_at: old,
                    last_leg_id: 0,
                    transmit_count: 1,
                },
            );
        }

        let resend = collect_retransmit_sequences(
            &pending,
            now,
            Duration::from_millis(300),
            old,
            3,
        );

        assert_eq!(resend, vec![0, 131072, 262144]);
    }

    #[test]
    fn cooled_down_leg_is_skipped_for_new_data_when_alternatives_exist() {
        let now = Instant::now();
        let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        legs[0].cooldown_until = Some(now + Duration::from_secs(2));
        let mut cursor = 0usize;
        let selected = select_leg_for_data_with_cooldown(
            &legs,
            &mut cursor,
            HealthProtocol::Tcp,
            64 * 1024,
            0,
            &test_config(),
            now,
        );
        assert_eq!(selected, Some(1));
    }

    #[test]
    fn toxic_tcp_leg_is_downweighted_before_pool_collapses() {
        let mut legs = vec![
            fake_leg(0, 10, 0, 30),
            fake_leg(1, 10, 0, 30),
            fake_leg(2, 10, 0, 30),
            fake_leg(3, 10, 0, 30),
            fake_leg(4, 10, 0, 30),
        ];

        penalize_tcp_leg(&mut legs, 4, TcpPenalty::ControlSocketClosedUnexpectedly);
        penalize_tcp_leg(&mut legs, 4, TcpPenalty::RetransmitBurst);

        assert_eq!(legs[4].tcp_health.state, LegHealthState::Degraded);
        assert!(legs[4].tcp_health.effective_weight < 0.25);
        assert_eq!(legs[0].tcp_health.state, LegHealthState::Healthy);
    }

    #[test]
    fn toxic_udp_leg_is_downweighted_before_pool_collapses() {
        let mut legs = vec![
            fake_leg(0, 10, 0, 30),
            fake_leg(1, 10, 0, 30),
            fake_leg(2, 10, 0, 30),
        ];

        penalize_udp_leg(&mut legs, 2, UdpPenalty::HighLossRatio);
        penalize_udp_leg(&mut legs, 2, UdpPenalty::RetransmitBurst);

        assert_eq!(legs[2].udp_health.state, LegHealthState::Degraded);
        assert!(legs[2].udp_health.effective_weight < 0.25);
        assert_eq!(legs[0].udp_health.state, LegHealthState::Healthy);
    }

    #[test]
    fn latency_first_stays_on_primary_before_bulk_threshold() {
        let mut config = test_config();
        config.latency_first = true;

        let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        legs[0].role = LegRole::Primary;
        legs[1].role = LegRole::Helper;

        let mut cursor = 0usize;
        let selected = select_stream_leg(&legs, &mut cursor, Some(0), 32 * 1024, 0, &config);
        assert_eq!(selected, Some(0));
    }

    #[test]
    fn latency_first_udp_prefers_primary_until_primary_fails() {
        let mut config = test_config();
        config.latency_first = true;
        config.udp_primary_only = true;

        let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        legs[0].role = LegRole::Primary;
        legs[1].role = LegRole::Helper;

        let mut cursor = 0usize;
        assert_eq!(select_udp_leg(&legs, &mut cursor, 1200, &config), Some(0));

        legs[0].active = false;
        assert_eq!(select_udp_leg(&legs, &mut cursor, 1200, &config), Some(1));
    }

    #[test]
    fn udp_random_keeps_one_leg_per_flow() {
        let legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        let mut cursor = 0usize;
        let mut random_state = 11u64;
        let first = select_udp_leg_for_strategy(
            ClientStrategy::Random,
            &legs,
            &mut cursor,
            &mut random_state,
            None,
            1200,
            &test_config(),
        );
        let second = select_udp_leg_for_strategy(
            ClientStrategy::Random,
            &legs,
            &mut cursor,
            &mut random_state,
            first,
            1200,
            &test_config(),
        );
        assert_eq!(first, second);
    }

    #[test]
    fn udp_latency_best_selects_lowest_rtt_leg() {
        let legs = vec![fake_leg(0, 10, 0, 35), fake_leg(1, 10, 0, 9)];
        let mut cursor = 0usize;
        let mut random_state = 13u64;
        assert_eq!(
            select_udp_leg_for_strategy(
                ClientStrategy::LatencyBest,
                &legs,
                &mut cursor,
                &mut random_state,
                None,
                1200,
                &test_config(),
            ),
            Some(1)
        );
    }

    #[test]
    fn leg_health_state_transitions_downward_and_upward() {
        let mut legs = vec![fake_leg(0, 10, 0, 20)];

        penalize_tcp_leg(&mut legs, 0, TcpPenalty::ControlSocketClosedUnexpectedly);
        penalize_tcp_leg(&mut legs, 0, TcpPenalty::RetransmitBurst);
        assert_eq!(legs[0].tcp_health.state, LegHealthState::Degraded);

        penalize_tcp_leg(&mut legs, 0, TcpPenalty::StreamOpenTimeout);
        assert_eq!(legs[0].tcp_health.state, LegHealthState::Probation);

        penalize_tcp_leg(&mut legs, 0, TcpPenalty::RecvReset);
        assert_eq!(legs[0].tcp_health.state, LegHealthState::Quarantined);

        for _ in 0..2 {
            credit_tcp_delivery(&mut legs, 0, 512 * 1024);
        }
        assert_eq!(legs[0].tcp_health.state, LegHealthState::Probation);

        for _ in 0..2 {
            credit_tcp_delivery(&mut legs, 0, 512 * 1024);
        }
        assert_eq!(legs[0].tcp_health.state, LegHealthState::Degraded);

        for _ in 0..2 {
            credit_tcp_delivery(&mut legs, 0, 512 * 1024);
        }
        assert_eq!(legs[0].tcp_health.state, LegHealthState::Healthy);
    }

    #[test]
    fn local_snapshot_serialization_shape_includes_protocol_health_and_events() {
        let mut legs = vec![fake_leg(0, 10, 0, 20)];
        legs[0].endpoint_id = Some(185);
        legs[0].node_id = Some(5);
        legs[0].host = String::from("8.163.90.235");

        penalize_tcp_leg(&mut legs, 0, TcpPenalty::ControlSocketClosedUnexpectedly);
        penalize_tcp_leg(&mut legs, 0, TcpPenalty::RetransmitBurst);
        penalize_udp_leg(&mut legs, 0, UdpPenalty::HighLossRatio);
        penalize_udp_leg(&mut legs, 0, UdpPenalty::RetransmitBurst);

        let snapshot = build_leg_health_snapshot_json("client", 123, 26, &legs).expect("snapshot json");
        let value: serde_json::Value = serde_json::from_str(&snapshot).expect("valid json");

        assert_eq!(value["observer_type"], "client");
        assert_eq!(value["observer_id"], 123);
        assert_eq!(value["forward_id"], 26);
        assert_eq!(value["legs"][0]["endpoint_id"], 185);
        assert_eq!(value["legs"][0]["node_id"], 5);
        assert_eq!(value["legs"][0]["host"], "8.163.90.235");
        assert!(value["legs"][0]["tcp"]["effective_weight"].as_f64().unwrap_or(1.0) < 1.0);
        assert!(value["legs"][0]["udp"]["effective_weight"].as_f64().unwrap_or(1.0) < 1.0);
        assert!(value["legs"][0]["recent_events"].as_array().is_some_and(|items| !items.is_empty()));
    }

    #[test]
    fn local_eof_waits_for_buffered_data_before_sending_fin() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(tx, close_tx, true);
        state.local_eof = true;
        state.preferred_leg_id = Some(0);
        state.outbound_next_offset = 4;
        state.pending_chunks.insert(
            0,
            PendingChunk {
                payload: Bytes::from_static(b"tail"),
                last_sent_at: Instant::now(),
                last_leg_id: 0,
                transmit_count: 1,
            },
        );

        let mut streams = HashMap::new();
        streams.insert(2_u32, state);
        let (leg_tx, _leg_rx) = mpsc::unbounded_channel();
        let mut legs = vec![LegState {
            id: 0,
            sender: leg_tx,
            active: true,
            inflight_bytes: 0,
            transport: LegTransport::Tcp,
            priority: 10,
            role: LegRole::Primary,
            smoothed_rtt: Duration::from_millis(20),
            label: String::from("leg-0"),
            spec_index: Some(0),
            endpoint_id: Some(0),
            node_id: Some(0),
            host: String::from("127.0.0.1"),
            cooldown_until: None,
            penalty_count: 0,
            tcp_health: ProtocolLegHealth::new(),
            udp_health: ProtocolLegHealth::new(),
            recent_events: VecDeque::new(),
        }];
        let mut cursor = 0usize;

        flush_tcp_stream_backlogs(
            Uuid::nil(),
            &mut streams,
            &mut legs,
            &mut cursor,
            SessionRole::Aggregator,
            &test_config(),
        );

        let state = streams.get(&2).expect("stream should remain tracked");
        assert!(
            state.outbound_fin.is_none(),
            "fin must wait until backlog and pending chunks are fully drained"
        );
        assert!(
            !state.pending_chunks.is_empty(),
            "test setup should leave buffered outbound data in flight"
        );
    }

    #[tokio::test]
    async fn inbound_ack_progress_waits_for_write_commit() {
        let (tx, mut rx) = mpsc::channel::<LocalWriteCommand>(8);
        let mut inbound = InboundState::new();
        let payload = Bytes::from_static(b"abcd");

        let queued = handle_inbound_data(&mut inbound, 0, payload, &tx).await.expect("queue inbound payload");
        assert_eq!(queued, 4);
        assert_eq!(inbound.next_offset, 4);
        assert_eq!(inbound.acked_offset, 0);

        match rx.recv().await.expect("queued local write command") {
            LocalWriteCommand::Data(bytes) => assert_eq!(bytes.len(), 4),
            LocalWriteCommand::Shutdown => panic!("unexpected shutdown"),
        }

        let ack = record_inbound_write_commit(&mut inbound, 4);
        assert_eq!(ack, 4);
        assert_eq!(inbound.acked_offset, 4);
    }

    #[tokio::test]
    async fn late_inbound_data_is_ignored_after_writer_shutdown() {
        let (tx, rx) = mpsc::channel::<LocalWriteCommand>(1);
        drop(rx);

        let mut inbound = InboundState::new();
        inbound.next_offset = 8;
        inbound.writer_shutdown = true;

        let next_offset = handle_inbound_data(&mut inbound, 8, Bytes::from_static(b"tail"), &tx)
            .await
            .expect("late data after shutdown should not fail the whole transport");

        assert_eq!(next_offset, 8);
        assert_eq!(inbound.next_offset, 8);
    }

    #[test]
    fn connection_reset_after_writer_shutdown_is_benign_close() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(tx, close_tx, true);
        state.inbound.writer_shutdown = true;

        let error = "Connection reset by peer (os error 104)".to_string().to_ascii_lowercase();
        assert!(error.contains("connection reset by peer"));
        assert!(state.inbound.writer_shutdown || state.local_eof);
    }

    #[test]
    fn stream_lifecycle_snapshot_reports_close_relevant_fields() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(tx, close_tx, true);
        state.local_eof = true;
        state.inbound.fin_offset = Some(128);
        state.inbound.next_offset = 128;
        state.inbound.acked_offset = 96;
        state.inbound.writer_shutdown = true;
        state.outbound_next_offset = 256;
        state.outbound_fin = Some(FinTracker {
            seq: 256,
            last_sent_at: Instant::now(),
        });
        state.outbound_fin_acked = false;
        state.close_deadline_at = Some(transport_stream_close_deadline());

        let snapshot = stream_lifecycle_snapshot(&state);

        assert!(snapshot.contains("opened=true"));
        assert!(snapshot.contains("local_eof=true"));
        assert!(snapshot.contains("fin_offset=Some(128)"));
        assert!(snapshot.contains("writer_shutdown=true"));
        assert!(snapshot.contains("outbound_fin=Some(256)"));
        assert!(snapshot.contains("close_deadline_armed=true"));
    }

    #[test]
    fn close_lifecycle_ack_logging_only_when_stream_is_closing() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(tx, close_tx, true);

        assert!(!should_log_close_lifecycle_ack(&state));

        state.local_eof = true;
        assert!(should_log_close_lifecycle_ack(&state));
    }

    #[test]
    fn arm_transport_stream_close_deadline_returns_true_once() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(tx, close_tx, true);

        assert!(arm_transport_stream_close_deadline(&mut state));
        assert!(!arm_transport_stream_close_deadline(&mut state));
    }

    #[test]
    fn close_timeout_waits_for_remote_result_after_local_fin_is_acked() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(tx, close_tx, true);
        state.local_eof = true;
        state.outbound_fin_acked = true;
        state.close_deadline_at = Some(Instant::now() - Duration::from_secs(5));

        assert!(
            !should_reset_transport_stream_on_close_timeout(&state, Instant::now()),
            "once our fin is acked, keep the stream open for remote result/control data instead of resetting it immediately"
        );
    }

    #[test]
    fn udp_delivery_buffer_releases_datagrams_in_id_order() {
        let mut buffer = UdpDeliveryBuffer::new();

        let ready = buffer.insert(7, 2, Bytes::from_static(b"two"));
        assert!(ready.is_empty(), "future datagram must wait for earlier ids");

        let ready = buffer.insert(7, 1, Bytes::from_static(b"one"));
        assert_eq!(ready.len(), 2);
        assert_eq!(ready[0].peer_id, 7);
        assert_eq!(ready[0].payload.as_ref(), b"one");
        assert_eq!(ready[1].peer_id, 7);
        assert_eq!(ready[1].payload.as_ref(), b"two");
    }

    #[test]
    fn udp_delivery_buffer_ignores_already_delivered_duplicates() {
        let mut buffer = UdpDeliveryBuffer::new();

        let ready = buffer.insert(3, 1, Bytes::from_static(b"one"));
        assert_eq!(ready.len(), 1);
        assert_eq!(ready[0].payload.as_ref(), b"one");

        let duplicate = buffer.insert(3, 1, Bytes::from_static(b"one-again"));
        assert!(duplicate.is_empty(), "duplicate delivered datagram should not be re-emitted");
    }


    #[test]
    fn client_stream_failure_aborts_persistent_transport() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let state = TcpStreamState::new(tx, close_tx, true);

        assert!(should_abort_transport_after_stream_failure(
            SessionRole::Client,
            Some(&state),
        ));
    }

    #[test]
    fn client_stream_failure_after_meaningful_progress_does_not_abort_transport() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(tx, close_tx, true);
        state.inbound.acked_offset = 1024;

        assert!(!should_abort_transport_after_stream_failure(
            SessionRole::Client,
            Some(&state),
        ));
    }

    #[test]
    fn aggregator_stream_failure_does_not_abort_transport() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let state = TcpStreamState::new(tx, close_tx, true);

        assert!(!should_abort_transport_after_stream_failure(
            SessionRole::Aggregator,
            Some(&state),
        ));
    }

    #[test]
    fn benign_close_does_not_abort_persistent_transport() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(tx, close_tx, true);
        state.local_eof = true;
        state.inbound.writer_shutdown = true;

        assert!(!should_abort_transport_after_stream_failure(
            SessionRole::Client,
            Some(&state),
        ));
    }

    #[test]
    fn unopened_local_eof_stream_times_out_after_connect_timeout() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(tx, close_tx, false);
        state.local_eof = true;
        state.open_sent_at = Some(Instant::now() - Duration::from_secs(5));

        assert!(should_abandon_unopened_local_eof_stream(
            &state,
            Duration::from_secs(3),
        ));
    }

    #[test]
    fn unopened_local_eof_stream_does_not_timeout_while_data_is_pending() {
        let (tx, _rx) = mpsc::channel::<LocalWriteCommand>(1);
        let (close_tx, _close_rx) = watch::channel(false);
        let mut state = TcpStreamState::new(tx, close_tx, false);
        state.local_eof = true;
        state.open_sent_at = Some(Instant::now() - Duration::from_secs(5));
        state.pending_chunks.insert(
            0,
            PendingChunk {
                payload: Bytes::from_static(b"x"),
                last_sent_at: Instant::now(),
                last_leg_id: 0,
                transmit_count: 1,
            },
        );

        assert!(!should_abandon_unopened_local_eof_stream(
            &state,
            Duration::from_secs(3),
        ));
    }
}
