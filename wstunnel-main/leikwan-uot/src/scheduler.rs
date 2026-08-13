use std::collections::{HashMap, VecDeque};
use std::fmt;

use bytes::Bytes;

use crate::config::{SchedulerConfig, SchedulerMode};
use crate::frame::FRAME_HEADER_BYTES;
use crate::progress::{
    AuthAck, Direction, LegProgress, ProgressError, ProgressValidator, RttCheckpoints,
};
use crate::queue::{select_least_backlogged_after, QueueCandidate};
use crate::telemetry::{
    DropReason, LegStatsSnapshot, LifecycleStage, StatsSnapshot, Telemetry, UdpErrorKind,
};
use crate::wireguard::{is_duplicate_eligible, is_latency_sensitive_transport};

const STREAM_PREFIX_BYTES: usize = 4;
const TERMINAL_HISTORY_LIMIT: usize = 1_024;
const BOOTSTRAP_RATE_BPS: u64 = 10_000_000;

#[derive(Clone, Debug, Eq, Hash, PartialEq)]
pub enum SchedulerScopeKey {
    Client {
        forward_id: u64,
    },
    Sidecar {
        forward_id: u64,
        target_host: String,
        target_port: u16,
        token_fingerprint: String,
    },
}

#[derive(Clone, Debug, Eq, Hash, PartialEq)]
pub struct SchedulerSessionKey {
    pub scope_key: SchedulerScopeKey,
    pub session_id: u64,
    pub direction: Direction,
    pub process_epoch: u64,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum TrafficClass {
    WireGuardHandshake,
    /// A bounded WireGuard transport-data datagram eligible for the first-wins
    /// replica race (the primary copy).
    WireGuardSmallTransport,
    /// The second (replica) copy of a first-wins latency-sensitive datagram,
    /// raced on a leg in a different path group.
    WireGuardSmallTransportReplica,
    Regular,
}

impl TrafficClass {
    const fn is_latency_sensitive(self) -> bool {
        matches!(
            self,
            Self::WireGuardSmallTransport | Self::WireGuardSmallTransportReplica
        )
    }

    const fn is_latency_replica(self) -> bool {
        matches!(self, Self::WireGuardSmallTransportReplica)
    }
}

/// Classify a WireGuard datagram payload for scheduling. A handshake (message
/// type 1-3) is always `WireGuardHandshake`. A bounded transport-data datagram
/// (message type 4, within `latency_sensitive_max_bytes`) is
/// `WireGuardSmallTransport` (eligible for the first-wins replica race).
/// Everything else is `Regular`. With `latency_sensitive_max_bytes == 0` (the
/// default) no datagram is ever classified as latency-sensitive.
pub fn classify_wireguard_traffic(
    payload: &[u8],
    latency_sensitive_max_bytes: usize,
) -> TrafficClass {
    if is_duplicate_eligible(payload) {
        TrafficClass::WireGuardHandshake
    } else if is_latency_sensitive_transport(payload, latency_sensitive_max_bytes) {
        TrafficClass::WireGuardSmallTransport
    } else {
        TrafficClass::Regular
    }
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum Admission {
    Admitted(u64),
    Dropped(DropReason),
}

impl Admission {
    pub const fn is_admitted(self) -> bool {
        matches!(self, Self::Admitted(_))
    }

    pub const fn reservation_id(self) -> Option<u64> {
        match self {
            Self::Admitted(id) => Some(id),
            Self::Dropped(_) => None,
        }
    }
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum ReservationStage {
    Admitted,
    Queued,
    QueuedToLeg,
    Writing,
    LocalSocketAccepted,
    LegacySocketAccepted,
    RemoteDecoded,
    RemoteUdpAccepted,
    Dropped(DropReason),
}

impl ReservationStage {
    pub const fn is_terminal(self) -> bool {
        matches!(
            self,
            Self::LegacySocketAccepted | Self::RemoteUdpAccepted | Self::Dropped(_)
        )
    }
}

#[derive(Clone, Debug)]
struct ReservationRecord {
    id: u64,
    session: SchedulerSessionKey,
    sequence: u64,
    payload: Bytes,
    payload_bytes: usize,
    wire_bytes: usize,
    enqueue_us: u64,
    deadline_us: u64,
    class: TrafficClass,
    /// True once this reservation has been paired as a first-wins replica whose
    /// primary has been admitted on a different path group.
    first_wins: bool,
    /// When set, this reservation must avoid legs in the given path group (used
    /// by a first-wins replica to land on a different physical path than the
    /// primary).
    avoid_path_group: Option<u64>,
    leg_id: Option<u64>,
    stage: ReservationStage,
    scheduled_once: bool,
}

#[derive(Clone, Debug, Default)]
struct SessionQueue {
    reservation_ids: VecDeque<u64>,
    queued_bytes: usize,
    queued_packets: usize,
    deficit_bytes: usize,
    weight: u16,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum LegState {
    Connecting,
    Warmup,
    Healthy,
    Lagging,
    Quarantined,
    Probe,
    LegacyFallback,
}

impl LegState {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Connecting => "connecting",
            Self::Warmup => "warmup",
            Self::Healthy => "healthy",
            Self::Lagging => "lagging",
            Self::Quarantined => "quarantined",
            Self::Probe => "probe",
            Self::LegacyFallback => "legacy_fallback",
        }
    }
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum EffectiveSchedulerMode {
    Legacy,
    FeedbackDrr,
}

#[derive(Clone, Debug)]
pub struct LegRegistration {
    pub leg_id: u64,
    pub scope_key: SchedulerScopeKey,
    pub connection_epoch: u64,
    pub progress_direction: Direction,
    pub weight: u16,
    pub now_us: u64,
    /// Path-group identifier for the first-wins scheduler (0 = auto-map to leg_id).
    pub path_group: u64,
}

#[derive(Clone, Debug)]
struct SentRecord {
    cumulative_wire_end: u64,
    reservation_id: u64,
    wire_bytes: usize,
}

#[derive(Clone, Debug)]
pub struct LegScheduleState {
    pub leg_id: u64,
    pub scope_key: SchedulerScopeKey,
    pub state: LegState,
    pub active: bool,
    pub dispatch_available: bool,
    pub feedback_capable: bool,
    pub connection_epoch: u64,
    pub progress_direction: Direction,
    pub weight: u16,
    /// Path group this leg rides. The first-wins scheduler only races a replica
    /// on a leg in a DIFFERENT group.
    pub path_group: u64,
    /// True once the peer's AuthAck advertised selective-first-wins-v1 for this
    /// leg's connection epoch.
    pub first_wins_capable: bool,
    pub window_reserved_wire_bytes: u64,
    pub outstanding_wire_bytes: u64,
    pub target_window_bytes: u64,
    pub delivery_rate_ewma_bps: u64,
    pub progress_srtt_us: u64,
    pub progress_samples: u32,
    pub last_progress_us: u64,
    pub userspace_pending_bytes: usize,
    pub write_stall_started_us: Option<u64>,
    pub write_stalls: u64,
    pub transitions: u64,
    cumulative_sent_frames: u64,
    cumulative_sent_wire_bytes: u64,
    last_progress_accepted_wire_bytes: u64,
    last_rate_sample_us: u64,
    stale_periods: u8,
    quarantined_at_us: u64,
    probe_successes: u8,
    probe_outstanding: bool,
    progress_validator: ProgressValidator,
    rtt_checkpoints: RttCheckpoints,
    sent_records: VecDeque<SentRecord>,
}

impl LegScheduleState {
    fn new(registration: LegRegistration, minimum_window: usize) -> Self {
        // path_group == 0 (unset) auto-maps to the leg id, so every leg is its
        // own group unless explicitly grouped — which means the first-wins
        // diversity predicate (different path_group) is never satisfied by
        // default, keeping the feature inert.
        let path_group = if registration.path_group == 0 {
            registration.leg_id
        } else {
            registration.path_group
        };
        Self {
            leg_id: registration.leg_id,
            scope_key: registration.scope_key,
            state: LegState::LegacyFallback,
            active: true,
            dispatch_available: true,
            feedback_capable: false,
            connection_epoch: registration.connection_epoch,
            progress_direction: registration.progress_direction,
            weight: registration.weight.max(1),
            path_group,
            first_wins_capable: false,
            window_reserved_wire_bytes: 0,
            outstanding_wire_bytes: 0,
            target_window_bytes: minimum_window as u64,
            delivery_rate_ewma_bps: BOOTSTRAP_RATE_BPS,
            progress_srtt_us: 0,
            progress_samples: 0,
            last_progress_us: registration.now_us,
            userspace_pending_bytes: 0,
            write_stall_started_us: None,
            write_stalls: 0,
            transitions: 0,
            cumulative_sent_frames: 0,
            cumulative_sent_wire_bytes: 0,
            last_progress_accepted_wire_bytes: 0,
            last_rate_sample_us: registration.now_us,
            stale_periods: 0,
            quarantined_at_us: 0,
            probe_successes: 0,
            probe_outstanding: false,
            progress_validator: ProgressValidator::new(
                registration.progress_direction,
                registration.connection_epoch,
            ),
            rtt_checkpoints: RttCheckpoints::new(256),
            sent_records: VecDeque::new(),
        }
    }

    fn transition(&mut self, state: LegState) {
        if self.state != state {
            self.state = state;
            self.transitions = self.transitions.saturating_add(1);
        }
    }
}

#[derive(Clone, Debug)]
pub struct ScheduledDatagram {
    pub reservation_id: u64,
    pub session: SchedulerSessionKey,
    pub sequence: u64,
    pub payload: Bytes,
    pub wire_bytes: usize,
}

#[derive(Clone, Debug)]
pub struct ChunkAssignment {
    pub leg_id: u64,
    pub reservation_ids: Vec<u64>,
    pub datagrams: Vec<ScheduledDatagram>,
    pub payload_bytes: usize,
    pub wire_bytes: usize,
}

#[derive(Clone, Debug)]
pub enum ScheduleOutcome {
    Assignment(ChunkAssignment),
    WaitUntilUs(u64),
    Idle,
}

impl ScheduleOutcome {
    pub fn into_assignment(self) -> Option<ChunkAssignment> {
        match self {
            Self::Assignment(assignment) => Some(assignment),
            Self::WaitUntilUs(_) | Self::Idle => None,
        }
    }
}

#[derive(Clone, Copy, Debug, Default, Eq, PartialEq)]
pub struct SentTotals {
    pub cumulative_frames: u64,
    pub cumulative_wire_bytes: u64,
}

#[derive(Clone, Copy, Debug, Default, Eq, PartialEq)]
pub struct ProgressUpdate {
    pub decoded_reservations: usize,
    pub accepted_reservations: usize,
    pub rtt_sample_us: Option<u64>,
}

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
pub enum SchedulerError {
    UnknownLeg,
    InvalidAck,
    Progress(ProgressError),
}

impl fmt::Display for SchedulerError {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::UnknownLeg => formatter.write_str("unknown scheduler leg"),
            Self::InvalidAck => formatter.write_str("invalid scheduler auth ack"),
            Self::Progress(error) => write!(formatter, "invalid leg progress: {error}"),
        }
    }
}

impl std::error::Error for SchedulerError {}

#[derive(Clone, Debug)]
struct RootPacer {
    rate_bps: u64,
    tokens_bytes: u64,
    burst_bytes: u64,
    refill_remainder: u128,
    last_refill_us: u64,
}

impl RootPacer {
    fn new(rate_bps: u64, burst_bytes: usize, now_us: u64) -> Self {
        Self {
            rate_bps,
            tokens_bytes: burst_bytes as u64,
            burst_bytes: burst_bytes as u64,
            refill_remainder: 0,
            last_refill_us: now_us,
        }
    }

    fn refill(&mut self, now_us: u64) {
        let elapsed_us = now_us.saturating_sub(self.last_refill_us);
        if elapsed_us == 0 {
            return;
        }
        let numerator = u128::from(elapsed_us)
            .saturating_mul(u128::from(self.rate_bps))
            .saturating_add(self.refill_remainder);
        let added = numerator / 8_000_000;
        self.refill_remainder = numerator % 8_000_000;
        self.tokens_bytes = self
            .tokens_bytes
            .saturating_add(added.min(u128::from(u64::MAX)) as u64)
            .min(self.burst_bytes);
        self.last_refill_us = now_us;
    }

    fn try_consume(&mut self, wire_bytes: usize, now_us: u64) -> Result<(), u64> {
        self.refill(now_us);
        let required = wire_bytes as u64;
        if self.tokens_bytes >= required {
            self.tokens_bytes -= required;
            return Ok(());
        }
        let missing = required - self.tokens_bytes;
        let numerator = u128::from(missing).saturating_mul(8_000_000);
        let wait_us = numerator.saturating_add(u128::from(self.rate_bps.saturating_sub(1)))
            / u128::from(self.rate_bps.max(1));
        Err(now_us.saturating_add(wait_us.min(u128::from(u64::MAX)) as u64))
    }
}

#[derive(Clone, Debug)]
pub struct Scheduler {
    config: SchedulerConfig,
    process_epoch: u64,
    sessions: HashMap<SchedulerSessionKey, SessionQueue>,
    active_sessions: VecDeque<SchedulerSessionKey>,
    reservations: HashMap<u64, ReservationRecord>,
    terminal_history: HashMap<u64, ReservationStage>,
    terminal_order: VecDeque<u64>,
    legs: HashMap<u64, LegScheduleState>,
    next_reservation_id: u64,
    next_legacy_cursor: u64,
    queue_bytes: usize,
    queue_packets: usize,
    queue_bytes_high_water: usize,
    userspace_pending_bytes: usize,
    total_window_reserved_bytes: u64,
    outstanding_bytes_high_water: u64,
    next_expiry_scan_us: u64,
    duplicate_terminal_events: u64,
    pacer: RootPacer,
    telemetry: Telemetry,
}

impl Scheduler {
    pub fn new(config: SchedulerConfig, process_epoch: u64, now_us: u64) -> Self {
        let max_wire = STREAM_PREFIX_BYTES + FRAME_HEADER_BYTES + 65_535;
        let burst = config.burst_bytes(max_wire);
        Self {
            pacer: RootPacer::new(config.aggregate_rate_bps, burst, now_us),
            config,
            process_epoch: process_epoch.max(1),
            sessions: HashMap::new(),
            active_sessions: VecDeque::new(),
            reservations: HashMap::new(),
            terminal_history: HashMap::new(),
            terminal_order: VecDeque::new(),
            legs: HashMap::new(),
            next_reservation_id: 1,
            next_legacy_cursor: 1,
            queue_bytes: 0,
            queue_packets: 0,
            queue_bytes_high_water: 0,
            userspace_pending_bytes: 0,
            total_window_reserved_bytes: 0,
            outstanding_bytes_high_water: 0,
            next_expiry_scan_us: 0,
            duplicate_terminal_events: 0,
            telemetry: Telemetry::default(),
        }
    }

    pub const fn process_epoch(&self) -> u64 {
        self.process_epoch
    }

    pub fn admit(
        &mut self,
        session: SchedulerSessionKey,
        payload: Bytes,
        class: TrafficClass,
        now_us: u64,
    ) -> Admission {
        self.admit_frame(session, 0, payload, class, now_us)
    }

    pub fn admit_frame(
        &mut self,
        mut session: SchedulerSessionKey,
        sequence: u64,
        payload: Bytes,
        class: TrafficClass,
        now_us: u64,
    ) -> Admission {
        session.process_epoch = self.process_epoch;
        let issue_replica = matches!(class, TrafficClass::WireGuardSmallTransport)
            && self.config.wireguard_latency_sensitive_copies == 2;
        let replica_session = issue_replica.then(|| session.clone());
        let replica_payload = issue_replica.then(|| payload.clone());
        let payload_bytes = payload.len();
        let wire_bytes = STREAM_PREFIX_BYTES + FRAME_HEADER_BYTES + payload_bytes;
        self.telemetry
            .record_lifecycle(LifecycleStage::Offered, payload_bytes, wire_bytes);
        self.purge_expired_session(&session, now_us);
        self.purge_expired_if_due(now_us);

        let is_new_session = !self.sessions.contains_key(&session);
        if is_new_session && self.sessions.len() >= self.config.max_sessions {
            return self.drop_admission(DropReason::RootQueueFull, payload_bytes, wire_bytes);
        }
        let session_queue = self.sessions.get(&session);
        if session_queue.is_some_and(|queue| {
            queue.queued_packets >= self.config.per_session_queue_packets
                || payload_bytes
                    > self
                        .config
                        .per_session_queue_bytes
                        .saturating_sub(queue.queued_bytes)
        }) {
            return self.drop_admission(DropReason::SessionQueueFull, payload_bytes, wire_bytes);
        }

        let (regular_byte_limit, regular_packet_limit) = self.regular_queue_limits();
        let byte_limit = if matches!(class, TrafficClass::WireGuardHandshake) {
            self.config.global_queue_bytes
        } else {
            regular_byte_limit
        };
        let packet_limit = if matches!(class, TrafficClass::WireGuardHandshake) {
            self.config.global_queue_packets
        } else {
            regular_packet_limit
        };
        if self.queue_packets >= packet_limit
            || payload_bytes > byte_limit.saturating_sub(self.queue_bytes)
        {
            return self.drop_admission(DropReason::RootQueueFull, payload_bytes, wire_bytes);
        }

        let reservation_id = self.next_reservation_id;
        self.next_reservation_id = self.next_reservation_id.wrapping_add(1).max(1);
        let deadline_us = now_us.saturating_add(self.config.queue_deadline_ms * 1_000);
        let record = ReservationRecord {
            id: reservation_id,
            session: session.clone(),
            sequence,
            payload,
            payload_bytes,
            wire_bytes,
            enqueue_us: now_us,
            deadline_us,
            class,
            first_wins: false,
            avoid_path_group: None,
            leg_id: None,
            stage: ReservationStage::Queued,
            scheduled_once: false,
        };
        let queue = self
            .sessions
            .entry(session.clone())
            .or_insert_with(|| SessionQueue {
                weight: 1,
                ..SessionQueue::default()
            });
        let was_empty = queue.reservation_ids.is_empty();
        if class.is_latency_sensitive() {
            // Latency-sensitive packets jump ahead of Regular traffic in the
            // per-session queue so they are not stuck behind bulk data.
            let insert_at = queue
                .reservation_ids
                .iter()
                .position(|id| {
                    self.reservations
                        .get(id)
                        .is_some_and(|record| matches!(record.class, TrafficClass::Regular))
                })
                .unwrap_or(queue.reservation_ids.len());
            queue.reservation_ids.insert(insert_at, reservation_id);
        } else {
            queue.reservation_ids.push_back(reservation_id);
        }
        queue.queued_bytes = queue.queued_bytes.saturating_add(payload_bytes);
        queue.queued_packets = queue.queued_packets.saturating_add(1);
        if was_empty {
            if matches!(class, TrafficClass::WireGuardHandshake) {
                self.active_sessions.push_front(session);
            } else {
                self.active_sessions.push_back(session);
            }
        }
        self.reservations.insert(reservation_id, record);
        self.queue_bytes = self.queue_bytes.saturating_add(payload_bytes);
        self.queue_packets = self.queue_packets.saturating_add(1);
        self.queue_bytes_high_water = self.queue_bytes_high_water.max(self.queue_bytes);
        self.telemetry
            .record_lifecycle(LifecycleStage::Admitted, payload_bytes, wire_bytes);
        if let (Some(replica_session), Some(replica_payload)) = (replica_session, replica_payload) {
            // Admit the replica as a second reservation. The scheduler later
            // pairs it with the primary (enable_first_wins_pair) and lands it on
            // a different path group; the receiver's FirstWinsCache keeps only
            // the first-delivered copy.
            let _ = self.admit_frame(
                replica_session,
                sequence,
                replica_payload,
                TrafficClass::WireGuardSmallTransportReplica,
                now_us,
            );
        }
        Admission::Admitted(reservation_id)
    }

    fn regular_queue_limits(&self) -> (usize, usize) {
        if self.config.global_queue_bytes < 1_024 || self.config.global_queue_packets < 128 {
            return (
                self.config.global_queue_bytes,
                self.config.global_queue_packets,
            );
        }
        let reserved_bytes =
            (self.config.global_queue_bytes / 8).min(self.config.per_session_queue_bytes);
        let reserved_packets =
            (self.config.global_queue_packets / 8).min(self.config.control_queue_packets);
        (
            self.config
                .global_queue_bytes
                .saturating_sub(reserved_bytes),
            self.config
                .global_queue_packets
                .saturating_sub(reserved_packets),
        )
    }

    fn drop_admission(
        &mut self,
        reason: DropReason,
        payload_bytes: usize,
        wire_bytes: usize,
    ) -> Admission {
        self.telemetry
            .record_drop(reason, payload_bytes, wire_bytes);
        Admission::Dropped(reason)
    }

    pub fn register_leg(&mut self, registration: LegRegistration) {
        if self.legs.contains_key(&registration.leg_id) {
            self.disconnect_leg(registration.leg_id);
        }
        let leg_id = registration.leg_id;
        let leg = LegScheduleState::new(registration, self.config.min_leg_window_bytes);
        self.legs.insert(leg_id, leg);
    }

    pub fn acknowledge_leg(
        &mut self,
        leg_id: u64,
        ack: &AuthAck,
        now_us: u64,
    ) -> Result<(), SchedulerError> {
        let leg = self
            .legs
            .get_mut(&leg_id)
            .ok_or(SchedulerError::UnknownLeg)?;
        if !ack.matches(leg.connection_epoch) {
            self.telemetry.record_invalid_progress_input();
            self.telemetry
                .record_drop(DropReason::InvalidProgress, 0, 0);
            return Err(SchedulerError::InvalidAck);
        }
        leg.feedback_capable = true;
        leg.first_wins_capable = ack.supports_first_wins(leg.connection_epoch);
        leg.cumulative_sent_frames = 0;
        leg.cumulative_sent_wire_bytes = 0;
        leg.last_progress_accepted_wire_bytes = 0;
        leg.outstanding_wire_bytes = 0;
        leg.sent_records.clear();
        leg.rtt_checkpoints = RttCheckpoints::new(256);
        leg.progress_validator =
            ProgressValidator::new(leg.progress_direction, leg.connection_epoch);
        leg.last_progress_us = now_us;
        leg.transition(LegState::Warmup);
        Ok(())
    }

    pub fn effective_mode(&self, scope: &SchedulerScopeKey) -> EffectiveSchedulerMode {
        if !matches!(self.config.scheduler_mode, SchedulerMode::FeedbackDrr) {
            return EffectiveSchedulerMode::Legacy;
        }
        let mut active = self
            .legs
            .values()
            .filter(|leg| leg.active && &leg.scope_key == scope)
            .peekable();
        if active.peek().is_some() && active.all(|leg| leg.feedback_capable) {
            EffectiveSchedulerMode::FeedbackDrr
        } else {
            EffectiveSchedulerMode::Legacy
        }
    }

    pub fn schedule_next(&mut self, now_us: u64) -> ScheduleOutcome {
        self.purge_expired_if_due(now_us);
        let attempts = self.active_sessions.len();
        for _ in 0..attempts {
            let Some(session_key) = self.active_sessions.pop_front() else {
                break;
            };
            let Some(queue) = self.sessions.get_mut(&session_key) else {
                continue;
            };
            let Some(first_id) = queue.reservation_ids.front().copied() else {
                self.sessions.remove(&session_key);
                continue;
            };
            let Some(first_record) = self.reservations.get(&first_id) else {
                queue.reservation_ids.pop_front();
                self.active_sessions.push_back(session_key);
                continue;
            };
            // A latency-sensitive replica whose primary was never paired into a
            // first-wins pair has no value on its own — drop it and move on, so a
            // feature-off or un-negotiated peer never ships a redundant copy.
            let first_class = first_record.class;
            let first_avoid_path_group = first_record.avoid_path_group;
            if first_class.is_latency_replica() && !first_record.first_wins {
                self.skip_queued_replica(&session_key, first_id);
                self.active_sessions.push_back(session_key);
                continue;
            }
            let quantum = self
                .config
                .drr_quantum_bytes
                .saturating_mul(usize::from(queue.weight.max(1)));
            let deficit_cap = quantum.saturating_mul(4);
            queue.deficit_bytes = queue.deficit_bytes.saturating_add(quantum).min(deficit_cap);
            if first_record.wire_bytes > queue.deficit_bytes {
                if first_record.wire_bytes
                    <= self.config.chunk_max_bytes + STREAM_PREFIX_BYTES + FRAME_HEADER_BYTES
                {
                    queue.deficit_bytes = first_record.wire_bytes;
                } else {
                    self.active_sessions.push_back(session_key);
                    continue;
                }
            }

            let mut candidate_ids = self.chunk_candidates(&session_key);
            if candidate_ids.is_empty() {
                self.active_sessions.push_back(session_key);
                continue;
            }
            let oldest_deadline_us = first_record.deadline_us;
            let (mut payload_bytes, mut wire_bytes) = self.candidate_totals(&candidate_ids);
            let mut selected_leg = self.select_leg(
                &session_key.scope_key,
                wire_bytes,
                oldest_deadline_us,
                now_us,
                first_avoid_path_group,
            );
            if selected_leg.is_none() && self.has_available_probe(&session_key.scope_key) {
                let probe_ids = self.probe_chunk_candidates(&session_key);
                let (probe_payload_bytes, probe_wire_bytes) = self.candidate_totals(&probe_ids);
                if !probe_ids.is_empty() {
                    selected_leg = self.select_leg(
                        &session_key.scope_key,
                        probe_wire_bytes,
                        oldest_deadline_us,
                        now_us,
                        first_avoid_path_group,
                    );
                    if selected_leg.is_some() {
                        candidate_ids = probe_ids;
                        payload_bytes = probe_payload_bytes;
                        wire_bytes = probe_wire_bytes;
                    }
                }
            }
            let Some(leg_id) = selected_leg else {
                self.active_sessions.push_back(session_key);
                return ScheduleOutcome::Idle;
            };
            // If this is a latency-sensitive primary, try to pair it with a
            // replica on a different path group so both can race (first-wins).
            // No-op unless copies==2, feedback_drr, and a capable secondary in a
            // different group exists.
            if matches!(first_class, TrafficClass::WireGuardSmallTransport) {
                self.enable_first_wins_pair(&session_key, first_id, leg_id, wire_bytes);
            }
            if let Err(wake_us) = self.pacer.try_consume(wire_bytes, now_us) {
                self.active_sessions.push_back(session_key);
                return ScheduleOutcome::WaitUntilUs(wake_us);
            }

            let mut datagrams = Vec::with_capacity(candidate_ids.len());
            {
                let queue = self.sessions.get_mut(&session_key).unwrap();
                queue.deficit_bytes = queue.deficit_bytes.saturating_sub(wire_bytes);
                for id in &candidate_ids {
                    if queue.reservation_ids.front() == Some(id) {
                        queue.reservation_ids.pop_front();
                    } else {
                        queue.reservation_ids.retain(|queued| queued != id);
                    }
                    if let Some(record) = self.reservations.get_mut(id) {
                        queue.queued_bytes =
                            queue.queued_bytes.saturating_sub(record.payload_bytes);
                        queue.queued_packets = queue.queued_packets.saturating_sub(1);
                        self.queue_bytes = self.queue_bytes.saturating_sub(record.payload_bytes);
                        self.queue_packets = self.queue_packets.saturating_sub(1);
                        record.leg_id = Some(leg_id);
                        record.stage = ReservationStage::QueuedToLeg;
                        datagrams.push(ScheduledDatagram {
                            reservation_id: record.id,
                            session: record.session.clone(),
                            sequence: record.sequence,
                            payload: record.payload.clone(),
                            wire_bytes: record.wire_bytes,
                        });
                        if !record.scheduled_once {
                            record.scheduled_once = true;
                            self.telemetry.observe_admission_to_schedule_ms(
                                now_us.saturating_sub(record.enqueue_us) / 1_000,
                            );
                            self.telemetry.record_lifecycle(
                                LifecycleStage::Scheduled,
                                record.payload_bytes,
                                record.wire_bytes,
                            );
                            self.telemetry.record_lifecycle(
                                LifecycleStage::QueuedToLeg,
                                record.payload_bytes,
                                record.wire_bytes,
                            );
                        }
                    }
                }
            }
            let has_more = self
                .sessions
                .get(&session_key)
                .is_some_and(|queue| !queue.reservation_ids.is_empty());
            if has_more {
                self.active_sessions.push_back(session_key.clone());
            } else {
                self.sessions.remove(&session_key);
            }
            if let Some(leg) = self.legs.get_mut(&leg_id) {
                let starts_progress_window = leg.feedback_capable
                    && leg.window_reserved_wire_bytes == 0
                    && leg.userspace_pending_bytes == 0
                    && leg.write_stall_started_us.is_none();
                if starts_progress_window {
                    leg.last_progress_us = now_us;
                    leg.stale_periods = 0;
                }
                leg.window_reserved_wire_bytes = leg
                    .window_reserved_wire_bytes
                    .saturating_add(wire_bytes as u64);
                leg.userspace_pending_bytes =
                    leg.userspace_pending_bytes.saturating_add(wire_bytes);
                if matches!(leg.state, LegState::Probe) {
                    leg.probe_outstanding = true;
                }
            }
            self.total_window_reserved_bytes = self
                .total_window_reserved_bytes
                .saturating_add(wire_bytes as u64);
            self.userspace_pending_bytes = self.userspace_pending_bytes.saturating_add(wire_bytes);
            self.outstanding_bytes_high_water = self
                .outstanding_bytes_high_water
                .max(self.total_window_reserved_bytes);
            return ScheduleOutcome::Assignment(ChunkAssignment {
                leg_id,
                reservation_ids: candidate_ids,
                datagrams,
                payload_bytes,
                wire_bytes,
            });
        }
        ScheduleOutcome::Idle
    }

    fn chunk_candidates(&self, session_key: &SchedulerSessionKey) -> Vec<u64> {
        let Some(queue) = self.sessions.get(session_key) else {
            return Vec::new();
        };
        let mut result = Vec::new();
        let mut payload_bytes = 0_usize;
        let mut wire_bytes = 0_usize;
        for id in &queue.reservation_ids {
            let Some(record) = self.reservations.get(id) else {
                continue;
            };
            if matches!(record.class, TrafficClass::WireGuardHandshake) {
                if result.is_empty() {
                    result.push(*id);
                }
                break;
            }
            if !result.is_empty()
                && payload_bytes.saturating_add(record.payload_bytes) > self.config.chunk_max_bytes
            {
                break;
            }
            if !result.is_empty()
                && wire_bytes.saturating_add(record.wire_bytes) > queue.deficit_bytes
            {
                break;
            }
            if result.is_empty() && record.wire_bytes > queue.deficit_bytes {
                break;
            }
            result.push(*id);
            payload_bytes = payload_bytes.saturating_add(record.payload_bytes);
            wire_bytes = wire_bytes.saturating_add(record.wire_bytes);
            if payload_bytes >= self.config.chunk_target_bytes {
                break;
            }
        }
        result
    }

    fn probe_chunk_candidates(&self, session_key: &SchedulerSessionKey) -> Vec<u64> {
        let Some(queue) = self.sessions.get(session_key) else {
            return Vec::new();
        };
        let mut result = Vec::new();
        let mut payload_bytes = 0_usize;
        let mut wire_bytes = 0_usize;
        for id in &queue.reservation_ids {
            let Some(record) = self.reservations.get(id) else {
                continue;
            };
            if !result.is_empty()
                && payload_bytes.saturating_add(record.payload_bytes) > self.config.chunk_min_bytes
            {
                break;
            }
            if !result.is_empty()
                && wire_bytes.saturating_add(record.wire_bytes) > queue.deficit_bytes
            {
                break;
            }
            if result.is_empty() && record.wire_bytes > queue.deficit_bytes {
                break;
            }
            result.push(*id);
            payload_bytes = payload_bytes.saturating_add(record.payload_bytes);
            wire_bytes = wire_bytes.saturating_add(record.wire_bytes);
            if payload_bytes >= self.config.chunk_min_bytes {
                break;
            }
        }
        result
    }

    fn candidate_totals(&self, candidate_ids: &[u64]) -> (usize, usize) {
        candidate_ids
            .iter()
            .filter_map(|id| self.reservations.get(id))
            .fold((0_usize, 0_usize), |(payload, wire), record| {
                (
                    payload.saturating_add(record.payload_bytes),
                    wire.saturating_add(record.wire_bytes),
                )
            })
    }

    fn has_available_probe(&self, scope: &SchedulerScopeKey) -> bool {
        self.legs.values().any(|leg| {
            leg.active
                && leg.dispatch_available
                && &leg.scope_key == scope
                && leg.feedback_capable
                && matches!(leg.state, LegState::Probe)
                && !leg.probe_outstanding
        })
    }

    fn select_leg(
        &mut self,
        scope: &SchedulerScopeKey,
        wire_bytes: usize,
        oldest_deadline_us: u64,
        now_us: u64,
        avoid_path_group: Option<u64>,
    ) -> Option<u64> {
        match self.effective_mode(scope) {
            EffectiveSchedulerMode::Legacy => {
                let selected = select_least_backlogged_after(
                    self.legs
                        .values()
                        .filter(|leg| {
                            leg.active && leg.dispatch_available && &leg.scope_key == scope
                        })
                        .map(|leg| QueueCandidate {
                            endpoint_id: leg.leg_id,
                            active: leg.active,
                            weight: leg.weight,
                            queued_packets: 0,
                            queued_bytes: leg.userspace_pending_bytes,
                            can_accept: wire_bytes
                                <= self
                                    .config
                                    .max_leg_window_bytes
                                    .saturating_sub(leg.userspace_pending_bytes),
                        }),
                    self.next_legacy_cursor,
                );
                if let Some(leg_id) = selected {
                    self.next_legacy_cursor = leg_id.saturating_add(1);
                }
                selected
            }
            EffectiveSchedulerMode::FeedbackDrr => {
                let total_room = self
                    .config
                    .max_total_outstanding_bytes
                    .saturating_sub(self.total_window_reserved_bytes as usize);
                if wire_bytes > total_room {
                    return None;
                }
                // Per-leg fairness cap: prevent a single high-EWMA leg from
                // accumulating more than 2× its fair share of outstanding bytes.
                // Without this, one leg with an inflated EWMA monopolises all chunks
                // (lowest predicted_finish) while others starve at bootstrap rates.
                let n_active_for_cap = self
                    .legs
                    .values()
                    .filter(|l| {
                        l.active
                            && l.feedback_capable
                            && matches!(l.state, LegState::Warmup | LegState::Healthy)
                    })
                    .count()
                    .max(1);
                let per_leg_fair_cap =
                    (self.config.max_total_outstanding_bytes / n_active_for_cap * 2) as u64;
                // Per-leg rate floor for predicted-finish ranking: the fair share of the
                // aggregate rate, never below the bootstrap rate. Using this floor in the
                // leg-selection tiebreak (instead of the raw EWMA) prevents a leg whose
                // EWMA is momentarily stale/frozen from being ranked as perpetually slow,
                // which would starve it of dispatch and trap it behind the others.
                let n_active = self
                    .legs
                    .values()
                    .filter(|l| {
                        l.active
                            && l.dispatch_available
                            && l.feedback_capable
                            && matches!(
                                l.state,
                                LegState::Warmup | LegState::Healthy | LegState::Probe
                            )
                    })
                    .count()
                    .max(1) as u64;
                let rate_floor =
                    (self.config.aggregate_rate_bps / n_active).max(BOOTSTRAP_RATE_BPS);
                let mut healthy_finishes = self
                    .legs
                    .values()
                    .filter(|leg| {
                        leg.active
                            && &leg.scope_key == scope
                            && leg.feedback_capable
                            && matches!(leg.state, LegState::Warmup | LegState::Healthy)
                    })
                    .map(|leg| predicted_finish_us(leg, now_us))
                    .collect::<Vec<_>>();
                healthy_finishes.sort_unstable();
                let median_finish = healthy_finishes
                    .get(healthy_finishes.len() / 2)
                    .copied()
                    .unwrap_or(now_us);
                let selected = self
                    .legs
                    .values()
                    .filter(|leg| {
                        leg.active
                            && leg.dispatch_available
                            && &leg.scope_key == scope
                            && leg.feedback_capable
                            && matches!(
                                leg.state,
                                LegState::Warmup | LegState::Healthy | LegState::Probe
                            )
                            // A first-wins replica must land on a leg in a
                            // different path group than its primary; for any other
                            // class avoid_path_group is None and this is a no-op.
                            && avoid_path_group
                                .is_none_or(|group| leg.path_group != group)
                            && (!matches!(leg.state, LegState::Probe)
                                || (!leg.probe_outstanding
                                    && wire_bytes
                                        <= self.config.chunk_min_bytes
                                            + STREAM_PREFIX_BYTES
                                            + FRAME_HEADER_BYTES))
                            // Per-leg fairness cap: a leg whose window_reserved already
                            // exceeds its 2× fair share is temporarily skipped so that
                            // other legs get a turn. This breaks the winner-take-all loop
                            // where one high-EWMA leg accumulates all outstanding bytes.
                            && wire_bytes as u64
                                <= leg
                                    .target_window_bytes
                                    .min(per_leg_fair_cap)
                                    .saturating_sub(leg.window_reserved_wire_bytes)
                    })
                    .min_by_key(|leg| {
                        (
                            predicted_finish_us_with_floor(leg, now_us, rate_floor),
                            leg.outstanding_wire_bytes,
                            leg.leg_id,
                        )
                    })
                    .map(|leg| {
                        (
                            leg.leg_id,
                            predicted_finish_us_with_floor(leg, now_us, rate_floor),
                        )
                    });
                let skew_limit = median_finish
                    .saturating_add(self.config.finish_skew_budget_ms.saturating_mul(1_000));
                let deadline_pressure = oldest_deadline_us
                    <= now_us.saturating_add(self.config.queue_target_ms.saturating_mul(1_000));
                if let Some((leg_id, predicted_finish)) = selected {
                    if predicted_finish <= skew_limit || deadline_pressure {
                        return Some(leg_id);
                    }
                    // Best healthy leg's predicted finish exceeds skew budget and there is
                    // no deadline pressure — fall through to check the lagging fallback.
                }
                // Fallback: when no healthy/warmup leg is eligible (all Lagging or all
                // outside the skew budget), pick the lagging leg with the smallest remote
                // outstanding so we keep making forward progress instead of returning None
                // and letting every queued packet expire at the 30 ms deadline.
                self.legs
                    .values()
                    .filter(|leg| {
                        leg.active
                            && leg.dispatch_available
                            && &leg.scope_key == scope
                            && leg.feedback_capable
                            && matches!(leg.state, LegState::Lagging)
                            && wire_bytes as u64
                                <= leg
                                    .target_window_bytes
                                    .saturating_sub(leg.window_reserved_wire_bytes)
                    })
                    .min_by_key(|leg| (leg.outstanding_wire_bytes, leg.leg_id))
                    .map(|leg| leg.leg_id)
            }
        }
    }

    pub fn set_leg_dispatch_available(&mut self, leg_id: u64, available: bool) {
        if let Some(leg) = self.legs.get_mut(&leg_id) {
            leg.dispatch_available = available;
        }
    }

    /// Try to pair a latency-sensitive primary reservation with its queued
    /// replica so the replica is raced on a leg in a different path group. The
    /// pair only forms when: two copies are configured, the effective mode is
    /// feedback_drr, the primary leg is first-wins-capable (peer negotiated
    /// selective-first-wins-v1), and a healthy secondary leg in a different
    /// path group that is also first-wins-capable exists. When the pair forms,
    /// the matching replica reservation is marked first-wins and pinned to avoid
    /// the primary's path group. Otherwise the replica is later dropped by
    /// skip_queued_replica in schedule_next and a single copy is sent.
    #[allow(clippy::too_many_arguments)]
    fn enable_first_wins_pair(
        &mut self,
        session_key: &SchedulerSessionKey,
        primary_id: u64,
        primary_leg_id: u64,
        _wire_bytes: usize,
    ) {
        if self.config.wireguard_latency_sensitive_copies != 2 {
            return;
        }
        if !matches!(
            self.effective_mode(&session_key.scope_key),
            EffectiveSchedulerMode::FeedbackDrr
        ) {
            return;
        }
        let primary_group = match self.legs.get(&primary_leg_id) {
            Some(leg) if leg.first_wins_capable => leg.path_group,
            _ => return,
        };
        // Require a healthy secondary in a different path group that is also
        // first-wins-capable, otherwise the replica would just duplicate the
        // primary on the same physical path.
        let has_secondary = self.legs.values().any(|leg| {
            leg.active
                && leg.dispatch_available
                && &leg.scope_key == &session_key.scope_key
                && leg.first_wins_capable
                && leg.path_group != primary_group
                && matches!(leg.state, LegState::Warmup | LegState::Healthy)
        });
        if !has_secondary {
            return;
        }
        let primary_sequence = self
            .reservations
            .get(&primary_id)
            .map(|r| r.sequence)
            .unwrap_or(u64::MAX);
        // Find this primary's queued replica (same session+sequence, replica
        // class) and mark it first-wins, pinned to avoid the primary's group.
        let replica_id = self.reservations.iter().find_map(|(id, record)| {
            if *id != primary_id
                && record.session == *session_key
                && record.sequence == primary_sequence
                && matches!(record.class, TrafficClass::WireGuardSmallTransportReplica)
            {
                Some(*id)
            } else {
                None
            }
        });
        if let Some(replica_id) = replica_id {
            if let Some(replica) = self.reservations.get_mut(&replica_id) {
                replica.first_wins = true;
                replica.avoid_path_group = Some(primary_group);
            }
        }
    }

    /// Drop a queued replica reservation that was never paired into a
    /// first-wins pair (feature off, un-negotiated peer, or no secondary in a
    /// different group). Refunds the session queue and root counters so the
    /// replica does not leak accounting or consume a scheduling slot.
    fn skip_queued_replica(&mut self, session_key: &SchedulerSessionKey, reservation_id: u64) {
        let Some(record) = self.reservations.remove(&reservation_id) else {
            return;
        };
        if let Some(queue) = self.sessions.get_mut(session_key) {
            queue.reservation_ids.retain(|id| *id != reservation_id);
            queue.queued_bytes = queue.queued_bytes.saturating_sub(record.payload_bytes);
            queue.queued_packets = queue.queued_packets.saturating_sub(1);
            if queue.reservation_ids.is_empty() {
                self.sessions.remove(session_key);
            }
        }
        self.queue_bytes = self.queue_bytes.saturating_sub(record.payload_bytes);
        self.queue_packets = self.queue_packets.saturating_sub(1);
        self.telemetry.record_drop(
            DropReason::QueueDeadline,
            record.payload_bytes,
            record.wire_bytes,
        );
    }

    pub fn return_assignment(&mut self, assignment: ChunkAssignment) {
        let Some(session_key) = assignment
            .datagrams
            .first()
            .map(|datagram| datagram.session.clone())
        else {
            return;
        };
        let queue = self
            .sessions
            .entry(session_key.clone())
            .or_insert_with(|| SessionQueue {
                weight: 1,
                ..SessionQueue::default()
            });
        queue.deficit_bytes = queue.deficit_bytes.saturating_add(assignment.wire_bytes);
        for id in assignment.reservation_ids.iter().rev() {
            let Some(record) = self.reservations.get_mut(id) else {
                continue;
            };
            if record.leg_id != Some(assignment.leg_id)
                || !matches!(record.stage, ReservationStage::QueuedToLeg)
            {
                continue;
            }
            record.leg_id = None;
            record.stage = ReservationStage::Queued;
            queue.reservation_ids.push_front(*id);
            queue.queued_bytes = queue.queued_bytes.saturating_add(record.payload_bytes);
            queue.queued_packets = queue.queued_packets.saturating_add(1);
            self.queue_bytes = self.queue_bytes.saturating_add(record.payload_bytes);
            self.queue_packets = self.queue_packets.saturating_add(1);
        }
        if !self.active_sessions.contains(&session_key) {
            self.active_sessions.push_front(session_key);
        }
        if let Some(leg) = self.legs.get_mut(&assignment.leg_id) {
            leg.window_reserved_wire_bytes = leg
                .window_reserved_wire_bytes
                .saturating_sub(assignment.wire_bytes as u64);
            leg.userspace_pending_bytes = leg
                .userspace_pending_bytes
                .saturating_sub(assignment.wire_bytes);
        }
        self.total_window_reserved_bytes = self
            .total_window_reserved_bytes
            .saturating_sub(assignment.wire_bytes as u64);
        self.userspace_pending_bytes = self
            .userspace_pending_bytes
            .saturating_sub(assignment.wire_bytes);
    }

    pub fn writer_started(&mut self, leg_id: u64, reservation_ids: &[u64], now_us: u64) {
        if let Some(leg) = self.legs.get_mut(&leg_id) {
            // If this is the very first write on a fresh leg, reset last_progress_us
            // to now. Without this, progress_age = process_uptime at the moment the
            // first probe fires (can be tens of seconds), which immediately exceeds
            // lag_age and forces the leg into Lagging before any feedback has a chance
            // to arrive. Also reset for a leg in Probe state, whose last_progress_us
            // may still carry a stale value from before quarantine.
            if leg.progress_samples == 0 || matches!(leg.state, LegState::Probe) {
                leg.last_progress_us = now_us;
            }
        }
        for id in reservation_ids {
            if let Some(record) = self.reservations.get_mut(id) {
                if record.leg_id == Some(leg_id)
                    && matches!(record.stage, ReservationStage::QueuedToLeg)
                {
                    record.stage = ReservationStage::Writing;
                    self.telemetry.record_lifecycle(
                        LifecycleStage::Writing,
                        record.payload_bytes,
                        record.wire_bytes,
                    );
                    self.telemetry
                        .observe_writer_wait_ms(now_us.saturating_sub(record.enqueue_us) / 1_000);
                }
            }
        }
    }

    pub fn writer_accepted(
        &mut self,
        leg_id: u64,
        reservation_ids: &[u64],
        now_us: u64,
    ) -> SentTotals {
        let Some(scope_key) = self.legs.get(&leg_id).map(|leg| leg.scope_key.clone()) else {
            return SentTotals::default();
        };
        let feedback_mode = matches!(
            self.effective_mode(&scope_key),
            EffectiveSchedulerMode::FeedbackDrr
        );
        let mut local_terminal = Vec::<(u64, usize)>::new();
        let totals = {
            let leg = self.legs.get_mut(&leg_id).unwrap();
            for id in reservation_ids {
                let Some(record) = self.reservations.get_mut(id) else {
                    continue;
                };
                if record.leg_id != Some(leg_id)
                    || !matches!(
                        record.stage,
                        ReservationStage::QueuedToLeg | ReservationStage::Writing
                    )
                {
                    continue;
                }
                record.stage = ReservationStage::LocalSocketAccepted;
                leg.userspace_pending_bytes = leg
                    .userspace_pending_bytes
                    .saturating_sub(record.wire_bytes);
                self.userspace_pending_bytes = self
                    .userspace_pending_bytes
                    .saturating_sub(record.wire_bytes);
                leg.cumulative_sent_frames = leg.cumulative_sent_frames.saturating_add(1);
                leg.cumulative_sent_wire_bytes = leg
                    .cumulative_sent_wire_bytes
                    .saturating_add(record.wire_bytes as u64);
                if feedback_mode {
                    leg.sent_records.push_back(SentRecord {
                        cumulative_wire_end: leg.cumulative_sent_wire_bytes,
                        reservation_id: *id,
                        wire_bytes: record.wire_bytes,
                    });
                    leg.rtt_checkpoints.record(
                        leg.cumulative_sent_wire_bytes,
                        tokio_instant_from_us(now_us),
                    );
                } else {
                    leg.window_reserved_wire_bytes = leg
                        .window_reserved_wire_bytes
                        .saturating_sub(record.wire_bytes as u64);
                    local_terminal.push((*id, record.wire_bytes));
                }
                self.telemetry.record_lifecycle(
                    LifecycleStage::LocalSocketAccepted,
                    record.payload_bytes,
                    record.wire_bytes,
                );
                self.telemetry
                    .observe_scheduler_queue_ms(now_us.saturating_sub(record.enqueue_us) / 1_000);
            }
            leg.progress_validator.set_locally_sent_totals(
                leg.cumulative_sent_frames,
                leg.cumulative_sent_wire_bytes,
            );
            leg.outstanding_wire_bytes = if feedback_mode {
                leg.cumulative_sent_wire_bytes
                    .saturating_sub(leg.last_progress_accepted_wire_bytes)
            } else {
                0
            };
            SentTotals {
                cumulative_frames: leg.cumulative_sent_frames,
                cumulative_wire_bytes: leg.cumulative_sent_wire_bytes,
            }
        };
        for (id, wire_bytes) in local_terminal {
            self.reservations.remove(&id);
            self.total_window_reserved_bytes = self
                .total_window_reserved_bytes
                .saturating_sub(wire_bytes as u64);
            self.remember_terminal(id, ReservationStage::LegacySocketAccepted);
        }
        totals
    }

    pub fn on_progress(
        &mut self,
        leg_id: u64,
        progress: &LegProgress,
        now_us: u64,
    ) -> Result<ProgressUpdate, SchedulerError> {
        let (decoded_ids, accepted_records, rtt_sample_us) = {
            let leg = self
                .legs
                .get_mut(&leg_id)
                .ok_or(SchedulerError::UnknownLeg)?;
            self.telemetry.record_invalid_progress_input();
            if let Err(error) = leg.progress_validator.accept(progress) {
                self.telemetry
                    .record_drop(DropReason::InvalidProgress, 0, 0);
                return Err(SchedulerError::Progress(error));
            }
            let previous_accepted = leg.last_progress_accepted_wire_bytes;
            let previous_sample_us = leg.last_rate_sample_us;
            let rtt_sample = leg
                .rtt_checkpoints
                .acknowledge(
                    progress.udp_accepted_wire_bytes,
                    tokio_instant_from_us(now_us),
                )
                .map(|duration| duration.as_micros().min(u128::from(u64::MAX)) as u64);
            if let Some(sample) = rtt_sample {
                leg.progress_srtt_us = if leg.progress_srtt_us == 0 {
                    sample
                } else {
                    (leg.progress_srtt_us
                        .saturating_mul(7)
                        .saturating_add(sample))
                        / 8
                };
            }
            let elapsed_us = now_us.saturating_sub(previous_sample_us);
            let accepted_delta = progress
                .udp_accepted_wire_bytes
                .saturating_sub(previous_accepted);
            if elapsed_us > 0 && accepted_delta > 0 {
                let rate = u128::from(accepted_delta)
                    .saturating_mul(8_000_000)
                    .checked_div(u128::from(elapsed_us))
                    .unwrap_or(0)
                    .min(u128::from(u64::MAX)) as u64;
                // Cap each per-sample rate at the configured aggregate ceiling (floor at
                // the bootstrap rate) before it enters the EWMA. A single outlier sample
                // — a huge accepted-byte burst over a tiny elapsed window during startup
                // — can otherwise pin delivery_rate_ewma_bps at a nonsensical Gbps value
                // that starves the leg of scheduling credit forever after.
                let rate = rate.min(self.config.aggregate_rate_bps.max(BOOTSTRAP_RATE_BPS));
                leg.delivery_rate_ewma_bps = if leg.progress_samples == 0 {
                    rate.max(1)
                } else {
                    (leg.delivery_rate_ewma_bps
                        .saturating_mul(7)
                        .saturating_add(rate))
                        / 8
                };
            }
            leg.last_progress_accepted_wire_bytes = progress.udp_accepted_wire_bytes;
            leg.last_rate_sample_us = now_us;
            leg.last_progress_us = now_us;
            leg.progress_samples = leg.progress_samples.saturating_add(1);
            leg.outstanding_wire_bytes = leg
                .cumulative_sent_wire_bytes
                .saturating_sub(progress.udp_accepted_wire_bytes);
            if leg.progress_samples >= 2 && matches!(leg.state, LegState::Warmup) {
                leg.transition(LegState::Healthy);
            }
            if matches!(leg.state, LegState::Probe) && accepted_delta > 0 {
                leg.probe_outstanding = false;
                leg.probe_successes = leg.probe_successes.saturating_add(1);
                if leg.probe_successes >= 3 {
                    leg.probe_successes = 0;
                    leg.transition(LegState::Healthy);
                }
            }
            leg.target_window_bytes = target_window_bytes(
                leg.delivery_rate_ewma_bps,
                leg.progress_srtt_us,
                self.config.min_leg_window_bytes,
                self.config.max_leg_window_bytes,
            );
            let decoded_ids = leg
                .sent_records
                .iter()
                .filter(|record| record.cumulative_wire_end <= progress.decoded_wire_bytes)
                .map(|record| record.reservation_id)
                .collect::<Vec<_>>();
            let accepted_records = leg
                .sent_records
                .iter()
                .filter(|record| record.cumulative_wire_end <= progress.udp_accepted_wire_bytes)
                .cloned()
                .collect::<Vec<_>>();
            leg.sent_records
                .retain(|record| record.cumulative_wire_end > progress.udp_accepted_wire_bytes);
            (decoded_ids, accepted_records, rtt_sample)
        };

        for id in &decoded_ids {
            if let Some(record) = self.reservations.get_mut(id) {
                if matches!(record.stage, ReservationStage::LocalSocketAccepted) {
                    record.stage = ReservationStage::RemoteDecoded;
                    self.telemetry.record_lifecycle(
                        LifecycleStage::RemoteDecoded,
                        record.payload_bytes,
                        record.wire_bytes,
                    );
                }
            }
        }
        for sent in &accepted_records {
            self.complete_remote_accepted(leg_id, sent.reservation_id, sent.wire_bytes);
        }
        Ok(ProgressUpdate {
            decoded_reservations: decoded_ids.len(),
            accepted_reservations: accepted_records.len(),
            rtt_sample_us,
        })
    }

    fn complete_remote_accepted(&mut self, leg_id: u64, reservation_id: u64, wire_bytes: usize) {
        let Some(record) = self.reservations.remove(&reservation_id) else {
            self.note_duplicate_terminal(reservation_id);
            return;
        };
        self.telemetry.record_lifecycle(
            LifecycleStage::RemoteUdpAccepted,
            record.payload_bytes,
            record.wire_bytes,
        );
        self.telemetry.observe_session_delivery(
            format!(
                "{:?}:{}:{}",
                record.session.scope_key, record.session.session_id, record.session.direction as u8
            ),
            record.payload_bytes as u64,
        );
        if let Some(leg) = self.legs.get_mut(&leg_id) {
            leg.window_reserved_wire_bytes = leg
                .window_reserved_wire_bytes
                .saturating_sub(wire_bytes as u64);
        }
        self.total_window_reserved_bytes = self
            .total_window_reserved_bytes
            .saturating_sub(wire_bytes as u64);
        self.remember_terminal(reservation_id, ReservationStage::RemoteUdpAccepted);
    }

    pub fn writer_failed(&mut self, leg_id: u64, reservation_ids: &[u64]) {
        for id in reservation_ids {
            let Some(record) = self.reservations.remove(id) else {
                self.note_duplicate_terminal(*id);
                continue;
            };
            if record.leg_id != Some(leg_id) {
                self.reservations.insert(*id, record);
                continue;
            }
            if let Some(leg) = self.legs.get_mut(&leg_id) {
                if matches!(
                    record.stage,
                    ReservationStage::QueuedToLeg | ReservationStage::Writing
                ) {
                    leg.userspace_pending_bytes = leg
                        .userspace_pending_bytes
                        .saturating_sub(record.wire_bytes);
                    self.userspace_pending_bytes = self
                        .userspace_pending_bytes
                        .saturating_sub(record.wire_bytes);
                }
                leg.window_reserved_wire_bytes = leg
                    .window_reserved_wire_bytes
                    .saturating_sub(record.wire_bytes as u64);
            }
            self.total_window_reserved_bytes = self
                .total_window_reserved_bytes
                .saturating_sub(record.wire_bytes as u64);
            self.telemetry.record_drop(
                DropReason::WriterClosed,
                record.payload_bytes,
                record.wire_bytes,
            );
            self.remember_terminal(*id, ReservationStage::Dropped(DropReason::WriterClosed));
        }
    }

    pub fn disconnect_leg(&mut self, leg_id: u64) {
        let ids = self
            .reservations
            .values()
            .filter(|record| record.leg_id == Some(leg_id))
            .map(|record| record.id)
            .collect::<Vec<_>>();
        self.writer_failed(leg_id, &ids);
        self.legs.remove(&leg_id);
    }

    pub fn mark_leg_failure(&mut self, leg_id: u64, now_us: u64) {
        if let Some(leg) = self.legs.get_mut(&leg_id) {
            leg.quarantined_at_us = now_us;
            leg.transition(LegState::Quarantined);
        }
    }

    pub fn mark_write_stall(&mut self, leg_id: u64, started_us: Option<u64>) {
        if let Some(leg) = self.legs.get_mut(&leg_id) {
            if started_us.is_some() && leg.write_stall_started_us.is_none() {
                leg.write_stalls = leg.write_stalls.saturating_add(1);
            }
            leg.write_stall_started_us = started_us;
        }
    }

    pub fn tick(&mut self, now_us: u64) {
        for leg in self.legs.values_mut() {
            if !leg.feedback_capable {
                continue;
            }
            if matches!(leg.state, LegState::Quarantined) {
                if now_us.saturating_sub(leg.quarantined_at_us)
                    >= self.config.probe_interval_ms * 1_000
                {
                    leg.probe_successes = 0;
                    leg.probe_outstanding = false;
                    // Start the probe cycle with a clean slate: stale_periods accumulated
                    // before quarantine would otherwise immediately re-Lag/re-quarantine
                    // the leg on the next tick before the probe can collect feedback.
                    leg.stale_periods = 0;
                    leg.transition(LegState::Probe);
                }
                continue;
            }
            let has_outstanding_work = leg.window_reserved_wire_bytes > 0
                || leg.userspace_pending_bytes > 0
                || leg.write_stall_started_us.is_some();
            if !has_outstanding_work {
                leg.stale_periods = 0;
                if matches!(leg.state, LegState::Lagging) {
                    if leg.progress_samples >= 2 {
                        leg.transition(LegState::Healthy);
                    } else {
                        leg.transition(LegState::Warmup);
                    }
                }
                // When a leg has been idle long enough that its progress_srtt estimate
                // is stale, decay both rate and SRTT toward their bootstrap minimums.
                // Without this, a single outlier sample during startup (huge byte burst
                // over a tiny elapsed window) can freeze delivery_rate_ewma_bps at a
                // nonsensical Gbps value forever — because the leg never gets outstanding
                // work again, has_outstanding_work stays false, and tick() skips all
                // subsequent checks, including on_progress, so the EWMA never updates.
                let idle_age_us = now_us.saturating_sub(leg.last_progress_us);
                let decay_threshold_us = leg.progress_srtt_us.saturating_mul(4).max(2_000_000);
                if leg.progress_samples > 0 && idle_age_us > decay_threshold_us {
                    leg.delivery_rate_ewma_bps =
                        (leg.delivery_rate_ewma_bps.saturating_mul(7) / 8).max(BOOTSTRAP_RATE_BPS);
                    if leg.progress_srtt_us > 50_000 {
                        leg.progress_srtt_us =
                            (leg.progress_srtt_us.saturating_mul(7) / 8).max(50_000);
                    }
                }
                continue;
            }
            // Use a minimum SRTT of 200 ms when the leg has few samples.
            // The hardcoded 50 ms floor causes lag_age = 100 ms, which fires before
            // the first LegProgress can arrive on any line with RTT > 100 ms (e.g.
            // Berlin/Germany lines at ~170 ms, Russia at ~188 ms). The result is a
            // continuous quarantine/probe cycle that never accumulates enough samples
            // to measure the real RTT. 200 ms gives lag_age = 400 ms, which is safely
            // above the 167-188 ms first-feedback window for European lines while still
            // being aggressive enough to detect genuine stalls within ~400 ms.
            let srtt = leg.progress_srtt_us.max(
                if leg.progress_samples < 4 || matches!(leg.state, LegState::Probe) {
                    200_000
                } else {
                    50_000
                },
            );
            let progress_age = now_us.saturating_sub(leg.last_progress_us);
            let quarantine_age = srtt.saturating_mul(4).max(500_000);
            let lag_age = srtt.saturating_mul(2).max(100_000);
            let write_stalled = leg
                .write_stall_started_us
                .is_some_and(|started| now_us.saturating_sub(started) > 10_000);
            if progress_age > quarantine_age {
                leg.quarantined_at_us = now_us;
                leg.transition(LegState::Quarantined);
                continue;
            }
            // Adaptive stall threshold: at least half the measured SRTT, at least 3×
            // the progress polling interval, and never below 50 ms. Using a fixed 5 ms
            // threshold on a 147 ms RTT path causes false-Lagging on every progress
            // batch delay, which is why this was the primary trigger of the synchronized
            // all-leg stall that killed scheduler throughput.
            let stall_threshold_us = (srtt / 2)
                .max(self.config.progress_interval_ms.saturating_mul(3_000))
                .max(50_000);
            let window_stalled = leg.window_reserved_wire_bytes >= leg.target_window_bytes
                && progress_age > stall_threshold_us;
            if progress_age > lag_age || write_stalled || window_stalled {
                leg.stale_periods = leg.stale_periods.saturating_add(1);
                if leg.stale_periods >= 3 {
                    leg.transition(LegState::Lagging);
                }
            } else {
                leg.stale_periods = 0;
            }
        }
        self.purge_expired_if_due(now_us);
    }

    fn purge_expired_if_due(&mut self, now_us: u64) {
        if now_us < self.next_expiry_scan_us {
            return;
        }
        self.purge_all_expired(now_us);
        let interval_ms = self
            .config
            .queue_target_ms
            .min(self.config.queue_deadline_ms)
            .max(1);
        self.next_expiry_scan_us = now_us.saturating_add(interval_ms.saturating_mul(1_000));
    }

    fn purge_expired_session(&mut self, session_key: &SchedulerSessionKey, now_us: u64) {
        let expired = self
            .sessions
            .get(session_key)
            .map(|queue| {
                queue
                    .reservation_ids
                    .iter()
                    .copied()
                    .take_while(|id| {
                        self.reservations
                            .get(id)
                            .is_some_and(|record| record.deadline_us <= now_us)
                    })
                    .collect::<Vec<_>>()
            })
            .unwrap_or_default();
        for id in expired {
            self.drop_queued_reservation(session_key, id, DropReason::QueueDeadline);
        }
    }

    fn purge_all_expired(&mut self, now_us: u64) {
        let keys = self.sessions.keys().cloned().collect::<Vec<_>>();
        for key in keys {
            self.purge_expired_session(&key, now_us);
        }
        self.active_sessions.retain(|key| {
            self.sessions
                .get(key)
                .is_some_and(|queue| !queue.reservation_ids.is_empty())
        });
        self.sessions
            .retain(|_, queue| !queue.reservation_ids.is_empty());
    }

    fn drop_queued_reservation(
        &mut self,
        session_key: &SchedulerSessionKey,
        reservation_id: u64,
        reason: DropReason,
    ) {
        let Some(record) = self.reservations.remove(&reservation_id) else {
            return;
        };
        if let Some(queue) = self.sessions.get_mut(session_key) {
            queue.reservation_ids.retain(|id| *id != reservation_id);
            queue.queued_bytes = queue.queued_bytes.saturating_sub(record.payload_bytes);
            queue.queued_packets = queue.queued_packets.saturating_sub(1);
        }
        self.queue_bytes = self.queue_bytes.saturating_sub(record.payload_bytes);
        self.queue_packets = self.queue_packets.saturating_sub(1);
        self.telemetry
            .record_drop(reason, record.payload_bytes, record.wire_bytes);
        self.remember_terminal(reservation_id, ReservationStage::Dropped(reason));
    }

    fn remember_terminal(&mut self, id: u64, stage: ReservationStage) {
        self.terminal_history.insert(id, stage);
        self.terminal_order.push_back(id);
        while self.terminal_order.len() > TERMINAL_HISTORY_LIMIT {
            if let Some(expired) = self.terminal_order.pop_front() {
                self.terminal_history.remove(&expired);
            }
        }
    }

    fn note_duplicate_terminal(&mut self, id: u64) {
        if self.terminal_history.contains_key(&id) {
            self.duplicate_terminal_events = self.duplicate_terminal_events.saturating_add(1);
        }
    }

    pub const fn queue_bytes(&self) -> usize {
        self.queue_bytes
    }

    pub const fn queue_packets(&self) -> usize {
        self.queue_packets
    }

    pub const fn queue_bytes_high_water(&self) -> usize {
        self.queue_bytes_high_water
    }

    pub const fn userspace_pending_bytes(&self) -> usize {
        self.userspace_pending_bytes
    }

    pub fn window_reserved_bytes(&self, leg_id: u64) -> u64 {
        self.legs
            .get(&leg_id)
            .map(|leg| leg.window_reserved_wire_bytes)
            .unwrap_or(0)
    }

    pub fn leg(&self, leg_id: u64) -> Option<&LegScheduleState> {
        self.legs.get(&leg_id)
    }

    pub fn reservation_stage(&self, id: u64) -> Option<ReservationStage> {
        self.reservations
            .get(&id)
            .map(|record| record.stage)
            .or_else(|| self.terminal_history.get(&id).copied())
    }

    pub fn drop_packets(&self, reason: DropReason) -> u64 {
        self.telemetry.drop_counter(reason).packets
    }

    pub const fn duplicate_terminal_events(&self) -> u64 {
        self.duplicate_terminal_events
    }

    pub fn telemetry(&self) -> &Telemetry {
        &self.telemetry
    }

    pub fn stats_snapshot(&self, now_us: u64) -> StatsSnapshot {
        let mut legs = self
            .legs
            .values()
            .map(|leg| LegStatsSnapshot {
                leg_id: leg.leg_id,
                state: leg.state.as_str().to_string(),
                outstanding_wire_bytes: leg.outstanding_wire_bytes,
                progress_age_ms: now_us.saturating_sub(leg.last_progress_us) / 1_000,
                progress_srtt_ms: leg.progress_srtt_us / 1_000,
                delivery_rate_ewma_bps: leg.delivery_rate_ewma_bps,
                write_stalls: leg.write_stalls,
                transitions: leg.transitions,
            })
            .collect::<Vec<_>>();
        legs.sort_by_key(|leg| leg.leg_id);
        let active_delivery_rate_bps = self
            .legs
            .values()
            .filter(|leg| leg.active && leg.feedback_capable)
            .map(|leg| leg.delivery_rate_ewma_bps)
            .sum();
        self.telemetry.snapshot(
            std::iter::empty::<(String, u64, bool)>(),
            active_delivery_rate_bps,
            self.feedback_capable_active_legs(),
            self.legacy_fallback_active_legs(),
            self.queue_bytes,
            self.queue_bytes_high_water,
            self.total_window_reserved_bytes as usize,
            self.outstanding_bytes_high_water as usize,
            legs,
        )
    }

    pub fn record_invalid_control(&mut self) {
        self.telemetry.record_invalid_progress_input();
        self.telemetry
            .record_drop(DropReason::InvalidProgress, 0, 0);
    }

    pub fn record_udp_egress_error(
        &mut self,
        kind: UdpErrorKind,
        payload_bytes: usize,
        wire_bytes: usize,
    ) {
        self.telemetry.record_udp_error(kind);
        self.telemetry
            .record_drop(DropReason::UdpEgressError, payload_bytes, wire_bytes);
    }

    pub fn feedback_capable_active_legs(&self) -> usize {
        self.legs
            .values()
            .filter(|leg| leg.active && leg.feedback_capable)
            .count()
    }

    pub fn legacy_fallback_active_legs(&self) -> usize {
        self.legs
            .values()
            .filter(|leg| leg.active && !leg.feedback_capable)
            .count()
    }
}

pub fn target_window_bytes(
    delivery_rate_bps: u64,
    progress_srtt_us: u64,
    minimum: usize,
    maximum: usize,
) -> u64 {
    let bdp = u128::from(delivery_rate_bps)
        .saturating_mul(u128::from(progress_srtt_us.saturating_add(10_000)))
        / 8_000_000;
    (bdp.min(u128::from(u64::MAX)) as u64).clamp(minimum as u64, maximum as u64)
}

fn predicted_finish_us(leg: &LegScheduleState, now_us: u64) -> u64 {
    let rate = leg.delivery_rate_ewma_bps.max(BOOTSTRAP_RATE_BPS);
    // Use the total bytes not yet confirmed by the remote end:
    //   window_reserved = still in writer queue (not yet accepted by local socket)
    //   outstanding     = accepted by local socket but remote has not confirmed receipt
    // Both are mutually exclusive and together represent the true in-flight backlog.
    let total_undelivered = leg
        .window_reserved_wire_bytes
        .saturating_add(leg.outstanding_wire_bytes);
    let service_us = u128::from(total_undelivered)
        .saturating_mul(8_000_000)
        .checked_div(u128::from(rate))
        .unwrap_or(0)
        .min(u128::from(u64::MAX)) as u64;
    let stall_penalty = leg
        .write_stall_started_us
        .map(|started| now_us.saturating_sub(started))
        .unwrap_or(0);
    now_us
        .saturating_add(service_us)
        .saturating_add(leg.progress_srtt_us / 2)
        .saturating_add(stall_penalty)
}

/// Same model as `predicted_finish_us` but clamps the effective rate to a floor
/// derived from the number of active legs. When the active fleet is small the
/// per-leg fair share of the aggregate rate is large; using that as a floor
/// prevents a leg with a momentarily-frozen EWMA (long idle, stale sample) from
/// being ranked as if it can never catch up, which would starve it of dispatch
/// and keep it permanently behind. The extra-credit variant lets a caller add a
/// fixed byte budget (e.g. an about-to-be-admitted chunk) on top of the leg's
/// current in-flight bytes.
fn predicted_finish_us_with_floor_and_extra(
    leg: &LegScheduleState,
    now_us: u64,
    rate_floor: u64,
    extra_wire_bytes: u64,
) -> u64 {
    let rate = leg
        .delivery_rate_ewma_bps
        .max(rate_floor.max(BOOTSTRAP_RATE_BPS));
    let total_undelivered = leg
        .window_reserved_wire_bytes
        .saturating_add(leg.outstanding_wire_bytes)
        .saturating_add(extra_wire_bytes);
    let service_us = u128::from(total_undelivered)
        .saturating_mul(8_000_000)
        .checked_div(u128::from(rate))
        .unwrap_or(0)
        .min(u128::from(u64::MAX)) as u64;
    let stall_penalty = leg
        .write_stall_started_us
        .map(|started| now_us.saturating_sub(started))
        .unwrap_or(0);
    now_us
        .saturating_add(service_us)
        .saturating_add(leg.progress_srtt_us / 2)
        .saturating_add(stall_penalty)
}

fn predicted_finish_us_with_floor(leg: &LegScheduleState, now_us: u64, rate_floor: u64) -> u64 {
    predicted_finish_us_with_floor_and_extra(leg, now_us, rate_floor, 0)
}

fn tokio_instant_from_us(offset_us: u64) -> tokio::time::Instant {
    static BASE: std::sync::OnceLock<tokio::time::Instant> = std::sync::OnceLock::new();
    *BASE.get_or_init(tokio::time::Instant::now) + std::time::Duration::from_micros(offset_us)
}

#[cfg(test)]
mod tests {
    use std::collections::{BTreeMap, BTreeSet};

    use bytes::Bytes;

    use super::*;
    use crate::config::{SchedulerConfig, SchedulerMode};
    use crate::progress::{AuthAck, Direction, LegProgress};
    use crate::telemetry::DropReason;

    fn scope() -> SchedulerScopeKey {
        SchedulerScopeKey::Client { forward_id: 3 }
    }

    fn session(id: u64) -> SchedulerSessionKey {
        SchedulerSessionKey {
            scope_key: scope(),
            session_id: id,
            direction: Direction::ClientToSidecar,
            process_epoch: 77,
        }
    }

    fn feedback_scheduler() -> Scheduler {
        let config = SchedulerConfig {
            scheduler_mode: SchedulerMode::FeedbackDrr,
            ..SchedulerConfig::default()
        };
        Scheduler::new(config, 77, 0)
    }

    fn add_feedback_leg(scheduler: &mut Scheduler, leg_id: u64, epoch: u64) {
        scheduler.register_leg(LegRegistration {
            leg_id,
            scope_key: scope(),
            connection_epoch: epoch,
            progress_direction: Direction::ClientToSidecar,
            weight: 1,
            now_us: 0,
            path_group: 0,
        });
        scheduler
            .acknowledge_leg(leg_id, &AuthAck::leg_progress(epoch), 0)
            .unwrap();
    }

    #[test]
    fn expired_data_is_removed_before_capacity_rejection() {
        let mut config = SchedulerConfig::default();
        config.global_queue_bytes = 100;
        config.per_session_queue_bytes = 100;
        config.global_queue_packets = 2;
        config.per_session_queue_packets = 2;
        config.queue_deadline_ms = 10;
        let mut scheduler = Scheduler::new(config, 77, 0);
        assert!(scheduler
            .admit(
                session(1),
                Bytes::from(vec![1; 100]),
                TrafficClass::Regular,
                0
            )
            .is_admitted());
        assert!(scheduler
            .admit(
                session(2),
                Bytes::from(vec![2; 100]),
                TrafficClass::Regular,
                11_000,
            )
            .is_admitted());
        assert_eq!(scheduler.drop_packets(DropReason::QueueDeadline), 1);
        assert_eq!(scheduler.queue_bytes(), 100);
    }

    #[test]
    fn root_and_session_limits_never_overcommit() {
        let mut config = SchedulerConfig::default();
        config.global_queue_bytes = 200;
        config.global_queue_packets = 2;
        config.per_session_queue_bytes = 120;
        config.per_session_queue_packets = 1;
        let mut scheduler = Scheduler::new(config, 77, 0);
        assert!(scheduler
            .admit(
                session(1),
                Bytes::from(vec![1; 100]),
                TrafficClass::Regular,
                0
            )
            .is_admitted());
        assert_eq!(
            scheduler.admit(session(1), Bytes::from(vec![1]), TrafficClass::Regular, 0),
            Admission::Dropped(DropReason::SessionQueueFull)
        );
        assert!(scheduler
            .admit(
                session(2),
                Bytes::from(vec![2; 100]),
                TrafficClass::Regular,
                0
            )
            .is_admitted());
        assert_eq!(
            scheduler.admit(session(3), Bytes::from(vec![3]), TrafficClass::Regular, 0),
            Admission::Dropped(DropReason::RootQueueFull)
        );
        assert_eq!(scheduler.queue_bytes(), 200);
        assert_eq!(scheduler.queue_packets(), 2);
    }

    #[test]
    fn partial_negotiation_keeps_the_whole_scope_on_legacy() {
        let mut scheduler = feedback_scheduler();
        scheduler.register_leg(LegRegistration {
            leg_id: 1,
            scope_key: scope(),
            connection_epoch: 10,
            progress_direction: Direction::ClientToSidecar,
            weight: 1,
            now_us: 0,
            path_group: 0,
        });
        scheduler.register_leg(LegRegistration {
            leg_id: 2,
            scope_key: scope(),
            connection_epoch: 20,
            progress_direction: Direction::ClientToSidecar,
            weight: 1,
            now_us: 0,
            path_group: 0,
        });
        scheduler
            .acknowledge_leg(1, &AuthAck::leg_progress(10), 0)
            .unwrap();
        assert_eq!(
            scheduler.effective_mode(&scope()),
            EffectiveSchedulerMode::Legacy
        );
        scheduler
            .acknowledge_leg(2, &AuthAck::leg_progress(20), 0)
            .unwrap();
        assert_eq!(
            scheduler.effective_mode(&scope()),
            EffectiveSchedulerMode::FeedbackDrr
        );
        scheduler.register_leg(LegRegistration {
            leg_id: 1,
            scope_key: scope(),
            connection_epoch: 11,
            progress_direction: Direction::ClientToSidecar,
            weight: 1,
            now_us: 1_000,
            path_group: 0,
        });
        assert_eq!(
            scheduler.effective_mode(&scope()),
            EffectiveSchedulerMode::Legacy
        );
    }

    #[test]
    fn legacy_fallback_releases_credit_on_local_socket_acceptance() {
        let mut scheduler = feedback_scheduler();
        scheduler.register_leg(LegRegistration {
            leg_id: 1,
            scope_key: scope(),
            connection_epoch: 10,
            progress_direction: Direction::ClientToSidecar,
            weight: 1,
            now_us: 0,
            path_group: 0,
        });
        let reservation_id = scheduler
            .admit(
                session(1),
                Bytes::from_static(b"legacy"),
                TrafficClass::Regular,
                0,
            )
            .reservation_id()
            .unwrap();
        let assignment = scheduler.schedule_next(0).into_assignment().unwrap();
        scheduler.writer_started(1, &assignment.reservation_ids, 1);
        scheduler.writer_accepted(1, &assignment.reservation_ids, 2);

        assert_eq!(
            scheduler.reservation_stage(reservation_id),
            Some(ReservationStage::LegacySocketAccepted)
        );
        assert_eq!(scheduler.window_reserved_bytes(1), 0);
    }

    #[test]
    fn late_ack_resets_progress_origin_before_feedback_credit() {
        let mut scheduler = feedback_scheduler();
        scheduler.register_leg(LegRegistration {
            leg_id: 1,
            scope_key: scope(),
            connection_epoch: 10,
            progress_direction: Direction::ClientToSidecar,
            weight: 1,
            now_us: 0,
            path_group: 0,
        });
        scheduler.admit(
            session(1),
            Bytes::from_static(b"before-ack"),
            TrafficClass::Regular,
            0,
        );
        let legacy = scheduler.schedule_next(0).into_assignment().unwrap();
        scheduler.writer_accepted(1, &legacy.reservation_ids, 1);

        scheduler
            .acknowledge_leg(1, &AuthAck::leg_progress(10), 2)
            .unwrap();
        let feedback_id = scheduler
            .admit(
                session(1),
                Bytes::from_static(b"after-ack"),
                TrafficClass::Regular,
                2,
            )
            .reservation_id()
            .unwrap();
        let feedback = scheduler.schedule_next(2).into_assignment().unwrap();
        let sent = scheduler.writer_accepted(1, &feedback.reservation_ids, 3);
        assert_eq!(sent.cumulative_frames, 1);
        scheduler
            .on_progress(
                1,
                &LegProgress::accepted(
                    Direction::ClientToSidecar,
                    10,
                    sent.cumulative_frames,
                    sent.cumulative_wire_bytes,
                    sent.cumulative_frames,
                    sent.cumulative_wire_bytes,
                ),
                4,
            )
            .unwrap();
        assert_eq!(
            scheduler.reservation_stage(feedback_id),
            Some(ReservationStage::RemoteUdpAccepted)
        );
    }

    #[test]
    fn one_session_aggregates_across_multiple_feedback_legs() {
        let mut scheduler = feedback_scheduler();
        add_feedback_leg(&mut scheduler, 1, 10);
        add_feedback_leg(&mut scheduler, 2, 20);
        for _ in 0..64 {
            assert!(scheduler
                .admit(
                    session(1),
                    Bytes::from(vec![0; 1_024]),
                    TrafficClass::Regular,
                    0,
                )
                .is_admitted());
        }
        let mut legs = BTreeSet::new();
        while let ScheduleOutcome::Assignment(assignment) = scheduler.schedule_next(0) {
            legs.insert(assignment.leg_id);
        }
        assert_eq!(legs, BTreeSet::from([1, 2]));
    }

    #[test]
    fn finish_skew_holds_a_slow_leg_until_deadline_pressure() {
        let mut scheduler = feedback_scheduler();
        for leg_id in 1..=4 {
            add_feedback_leg(&mut scheduler, leg_id, leg_id * 10);
            let leg = scheduler.legs.get_mut(&leg_id).unwrap();
            leg.state = LegState::Healthy;
            leg.progress_samples = 2;
            leg.target_window_bytes = 4 * 1024 * 1024;
            if leg_id < 4 {
                leg.delivery_rate_ewma_bps = 1_000_000_000;
                leg.window_reserved_wire_bytes = leg.target_window_bytes;
            } else {
                leg.delivery_rate_ewma_bps = 10_000_000;
                leg.window_reserved_wire_bytes = 100_000;
            }
        }
        scheduler.total_window_reserved_bytes = scheduler
            .legs
            .values()
            .map(|leg| leg.window_reserved_wire_bytes)
            .sum();
        assert!(scheduler
            .admit(
                session(1),
                Bytes::from(vec![0; 1_200]),
                TrafficClass::Regular,
                0,
            )
            .is_admitted());

        // With the rate floor, leg 4 (10 Mbps EWMA) is elevated to
        // rate_floor = aggregate_rate / n_legs = 450M/4 = 112.5 Mbps for predicted-
        // finish purposes. Its predicted_finish is now low enough that it falls within
        // the skew budget and is selected immediately — the intent of the floor.
        let assignment = scheduler
            .schedule_next(0)
            .into_assignment()
            .expect("rate floor should allow the only eligible leg to be selected immediately");
        assert_eq!(assignment.leg_id, 4);
    }

    #[test]
    fn decoded_progress_does_not_release_udp_accepted_credit() {
        let mut scheduler = feedback_scheduler();
        add_feedback_leg(&mut scheduler, 1, 10);
        scheduler
            .admit(
                session(1),
                Bytes::from(vec![0; 16_000]),
                TrafficClass::Regular,
                0,
            )
            .is_admitted();
        let assignment = scheduler.schedule_next(0).into_assignment().unwrap();
        scheduler.writer_started(1, &assignment.reservation_ids, 100);
        let sent = scheduler.writer_accepted(1, &assignment.reservation_ids, 200);
        scheduler
            .on_progress(
                1,
                &LegProgress::accepted(
                    Direction::ClientToSidecar,
                    10,
                    sent.cumulative_frames,
                    sent.cumulative_wire_bytes,
                    0,
                    0,
                ),
                300,
            )
            .unwrap();
        assert_eq!(
            scheduler.window_reserved_bytes(1),
            assignment.wire_bytes as u64
        );
        scheduler
            .on_progress(
                1,
                &LegProgress::accepted(
                    Direction::ClientToSidecar,
                    10,
                    sent.cumulative_frames,
                    sent.cumulative_wire_bytes,
                    sent.cumulative_frames,
                    sent.cumulative_wire_bytes,
                ),
                400,
            )
            .unwrap();
        assert_eq!(scheduler.window_reserved_bytes(1), 0);
    }

    #[test]
    fn writer_failure_releases_each_reservation_once() {
        let mut scheduler = feedback_scheduler();
        add_feedback_leg(&mut scheduler, 1, 10);
        scheduler
            .admit(
                session(1),
                Bytes::from(vec![0; 1_000]),
                TrafficClass::Regular,
                0,
            )
            .is_admitted();
        let assignment = scheduler.schedule_next(0).into_assignment().unwrap();
        scheduler.writer_failed(1, &assignment.reservation_ids);
        scheduler.writer_failed(1, &assignment.reservation_ids);
        assert_eq!(scheduler.userspace_pending_bytes(), 0);
        assert_eq!(scheduler.drop_packets(DropReason::WriterClosed), 1);
        assert_eq!(scheduler.duplicate_terminal_events(), 1);
    }

    #[test]
    fn token_bucket_never_exceeds_rate_plus_burst() {
        let mut pacer = RootPacer::new(300_000_000, 75_000, 0);
        let mut wire_bytes = 0_u64;
        for now_us in (0..=1_000_000).step_by(100) {
            while pacer.try_consume(1_200, now_us).is_ok() {
                wire_bytes += 1_200;
            }
        }
        assert!(wire_bytes <= 37_500_000 + 75_000);
        assert!(wire_bytes >= 37_400_000);
    }

    #[test]
    fn sixty_four_equal_sessions_are_fair() {
        let mut config = SchedulerConfig {
            scheduler_mode: SchedulerMode::FeedbackDrr,
            global_queue_bytes: 8 * 1024 * 1024,
            global_queue_packets: 8_192,
            per_session_queue_bytes: 128 * 1024,
            queue_deadline_ms: 100,
            ..SchedulerConfig::default()
        };
        config.max_total_outstanding_bytes = 8 * 1024 * 1024;
        let mut scheduler = Scheduler::new(config, 77, 0);
        add_feedback_leg(&mut scheduler, 1, 10);
        for session_id in 1..=64 {
            for _ in 0..32 {
                assert!(scheduler
                    .admit(
                        session(session_id),
                        Bytes::from(vec![0; 1_200]),
                        TrafficClass::Regular,
                        0,
                    )
                    .is_admitted());
            }
        }
        let mut delivered = BTreeMap::<u64, u64>::new();
        let mut now_us = 0_u64;
        let mut iterations = 0_usize;
        while scheduler.queue_packets() > 0 {
            iterations += 1;
            assert!(iterations < 20_000);
            match scheduler.schedule_next(now_us) {
                ScheduleOutcome::Assignment(assignment) => {
                    for datagram in &assignment.datagrams {
                        *delivered.entry(datagram.session.session_id).or_default() +=
                            datagram.payload.len() as u64;
                    }
                    scheduler.writer_started(
                        assignment.leg_id,
                        &assignment.reservation_ids,
                        now_us,
                    );
                    let sent = scheduler.writer_accepted(
                        assignment.leg_id,
                        &assignment.reservation_ids,
                        now_us,
                    );
                    scheduler
                        .on_progress(
                            assignment.leg_id,
                            &LegProgress::accepted(
                                Direction::ClientToSidecar,
                                10,
                                sent.cumulative_frames,
                                sent.cumulative_wire_bytes,
                                sent.cumulative_frames,
                                sent.cumulative_wire_bytes,
                            ),
                            now_us.saturating_add(1),
                        )
                        .unwrap();
                }
                ScheduleOutcome::WaitUntilUs(wake_us) => now_us = wake_us,
                ScheduleOutcome::Idle => now_us = now_us.saturating_add(1),
            }
        }
        assert_eq!(delivered.len(), 64);
        let fairness = crate::telemetry::jain_fairness(
            delivered.iter().map(|(key, value)| (*key, *value, true)),
        );
        assert!(fairness >= 0.90, "fairness={fairness}");
    }

    #[test]
    fn maximum_datagram_is_scheduled_whole() {
        let mut scheduler = feedback_scheduler();
        add_feedback_leg(&mut scheduler, 1, 10);
        assert!(scheduler
            .admit(
                session(1),
                Bytes::from(vec![0; 65_535]),
                TrafficClass::Regular,
                0,
            )
            .is_admitted());
        let assignment = scheduler.schedule_next(0).into_assignment().unwrap();
        assert_eq!(assignment.datagrams.len(), 1);
        assert_eq!(assignment.payload_bytes, 65_535);
        assert_eq!(
            assignment.wire_bytes,
            65_535 + STREAM_PREFIX_BYTES + FRAME_HEADER_BYTES
        );
    }

    #[test]
    fn scheduled_datagram_preserves_runtime_sequence() {
        let mut scheduler = feedback_scheduler();
        add_feedback_leg(&mut scheduler, 1, 10);

        assert!(scheduler
            .admit_frame(
                session(41),
                9_001,
                Bytes::from_static(b"payload"),
                TrafficClass::Regular,
                0,
            )
            .is_admitted());

        let assignment = scheduler
            .schedule_next(0)
            .into_assignment()
            .expect("assignment");
        assert_eq!(assignment.datagrams[0].sequence, 9_001);
    }

    #[test]
    fn stale_leg_quarantines_then_recovers_after_three_probes() {
        let mut scheduler = feedback_scheduler();
        add_feedback_leg(&mut scheduler, 1, 10);
        scheduler.admit(
            session(1),
            Bytes::from(vec![0; 1_000]),
            TrafficClass::Regular,
            0,
        );
        let stalled = scheduler.schedule_next(0).into_assignment().unwrap();
        scheduler.writer_accepted(1, &stalled.reservation_ids, 1);
        // With progress_samples < 4, bootstrap srtt min = 200 ms → lag_age = 400 ms,
        // quarantine_age = 800 ms. Advance past those thresholds.
        for now_us in [401_000, 402_000, 403_000] {
            scheduler.tick(now_us);
        }
        assert_eq!(scheduler.leg(1).unwrap().state, LegState::Lagging);
        scheduler.tick(801_000);
        assert_eq!(scheduler.leg(1).unwrap().state, LegState::Quarantined);
        scheduler.tick(1_801_000);
        assert_eq!(scheduler.leg(1).unwrap().state, LegState::Probe);

        let mut now_us = 1_501_000;
        for _ in 0..3 {
            assert!(scheduler
                .admit(
                    session(1),
                    Bytes::from(vec![0; 1_000]),
                    TrafficClass::Regular,
                    now_us,
                )
                .is_admitted());
            let assignment = scheduler.schedule_next(now_us).into_assignment().unwrap();
            scheduler.writer_started(1, &assignment.reservation_ids, now_us);
            let sent = scheduler.writer_accepted(1, &assignment.reservation_ids, now_us);
            now_us += 1_000;
            scheduler
                .on_progress(
                    1,
                    &LegProgress::accepted(
                        Direction::ClientToSidecar,
                        10,
                        sent.cumulative_frames,
                        sent.cumulative_wire_bytes,
                        sent.cumulative_frames,
                        sent.cumulative_wire_bytes,
                    ),
                    now_us,
                )
                .unwrap();
        }
        assert_eq!(scheduler.leg(1).unwrap().state, LegState::Healthy);
    }

    #[test]
    fn backlogged_probe_leg_schedules_a_probe_sized_prefix_after_credit_returns() {
        let mut scheduler = feedback_scheduler();
        add_feedback_leg(&mut scheduler, 1, 10);
        {
            let leg = scheduler.legs.get_mut(&1).unwrap();
            leg.state = LegState::Probe;
            leg.probe_outstanding = true;
        }
        for sequence in 1..=64 {
            assert!(scheduler
                .admit_frame(
                    session(1),
                    sequence,
                    Bytes::from(vec![0; 1_200]),
                    TrafficClass::Regular,
                    0,
                )
                .is_admitted());
        }
        for _ in 0..4 {
            assert!(matches!(scheduler.schedule_next(0), ScheduleOutcome::Idle));
        }
        scheduler.legs.get_mut(&1).unwrap().probe_outstanding = false;

        let assignment = scheduler
            .schedule_next(0)
            .into_assignment()
            .expect("probe credit must select a bounded prefix despite accumulated deficit");

        assert!(assignment.payload_bytes <= scheduler.config.chunk_min_bytes);
        assert!(!assignment.datagrams.is_empty());
    }

    #[test]
    fn idle_feedback_leg_does_not_become_stale_without_outstanding_work() {
        let mut scheduler = feedback_scheduler();
        add_feedback_leg(&mut scheduler, 1, 10);

        scheduler.tick(2_000_000);

        assert_eq!(scheduler.leg(1).unwrap().state, LegState::Warmup);
        assert!(scheduler
            .admit(
                session(1),
                Bytes::from_static(b"first-after-idle"),
                TrafficClass::Regular,
                2_000_000,
            )
            .is_admitted());
        assert!(scheduler
            .schedule_next(2_000_000)
            .into_assignment()
            .is_some());
    }

    #[test]
    fn first_assignment_after_idle_starts_a_fresh_progress_deadline() {
        let mut scheduler = feedback_scheduler();
        add_feedback_leg(&mut scheduler, 1, 10);
        scheduler.tick(2_000_000);
        assert!(scheduler
            .admit(
                session(1),
                Bytes::from_static(b"first-after-idle"),
                TrafficClass::Regular,
                2_000_000,
            )
            .is_admitted());
        let assignment = scheduler
            .schedule_next(2_000_000)
            .into_assignment()
            .unwrap();
        scheduler.writer_started(1, &assignment.reservation_ids, 2_000_001);
        scheduler.writer_accepted(1, &assignment.reservation_ids, 2_000_002);

        scheduler.tick(2_001_000);

        assert_eq!(scheduler.leg(1).unwrap().state, LegState::Warmup);
    }

    #[test]
    fn first_wins_off_by_default_issues_no_replica() {
        // With defaults (max_bytes = 0, copies = 1) classify never produces
        // WireGuardSmallTransport, so admit never issues a replica and the
        // first-wins code path is inert — the load-bearing feature-OFF guard.
        let mut scheduler = feedback_scheduler();
        add_feedback_leg(&mut scheduler, 1, 10);
        let leg = scheduler.legs.get_mut(&1).unwrap();
        leg.state = LegState::Healthy;
        leg.progress_samples = 2;

        // Message type 4 (transport) in the first 4 bytes; with max_bytes=0 the
        // classifier is disabled and returns Regular for everything.
        let mut transport = vec![0u8; 128];
        transport[..4].copy_from_slice(&4u32.to_le_bytes());
        assert!(scheduler
            .admit(
                session(1),
                Bytes::from(transport.clone()),
                classify_wireguard_traffic(&transport, 0),
                0,
            )
            .is_admitted());
        // classify_wireguard_traffic(.., 0) returns Regular (max_bytes==0 disables),
        // so exactly one reservation exists, no replica was admitted.
        assert_eq!(scheduler.reservations.len(), 1);
        assert!(scheduler.reservations.values().all(|r| !r.first_wins));
        assert!(scheduler
            .reservations
            .values()
            .all(|r| r.avoid_path_group.is_none()));
    }

    #[test]
    fn enable_first_wins_pair_is_a_noop_unless_two_copies_configured() {
        // Even when a primary is classified latency-sensitive and the leg is
        // first-wins-capable, the pair only forms at copies == 2. With the
        // default copies == 1 the replica (if any) is dropped and the primary
        // is scheduled as a single copy.
        let mut scheduler = Scheduler::new(
            SchedulerConfig {
                scheduler_mode: SchedulerMode::FeedbackDrr,
                wireguard_latency_sensitive_max_bytes: 256,
                wireguard_latency_sensitive_copies: 1, // feature off
                ..SchedulerConfig::default()
            },
            77,
            0,
        );
        add_feedback_leg(&mut scheduler, 1, 10);
        let leg = scheduler.legs.get_mut(&1).unwrap();
        leg.state = LegState::Healthy;
        leg.progress_samples = 2;
        leg.first_wins_capable = true;

        // Latency-sensitive transport payload: message type 4 in the first 4
        // bytes (LE), 128 bytes total — within the max_bytes=256 threshold.
        let mut transport = vec![0u8; 128];
        transport[..4].copy_from_slice(&4u32.to_le_bytes());
        let payload = Bytes::from(transport.clone());
        let class = classify_wireguard_traffic(&transport, 256);
        assert_eq!(class, TrafficClass::WireGuardSmallTransport);
        assert!(scheduler.admit(session(1), payload, class, 0).is_admitted());
        // copies == 1 ⇒ no replica admitted; one reservation, never paired.
        assert_eq!(scheduler.reservations.len(), 1);
        assert!(scheduler.reservations.values().all(|r| !r.first_wins));
    }
}
