use std::collections::VecDeque;
use std::time::Duration;

use bytes::Bytes;
use leikwan_uot::config::{SchedulerConfig, SchedulerMode};
use leikwan_uot::progress::{AuthAck, Direction, LegProgress};
use leikwan_uot::scheduler::{
    LegRegistration, LegState, ScheduleOutcome, Scheduler, SchedulerScopeKey, SchedulerSessionKey,
    SentTotals, TrafficClass,
};
use leikwan_uot::telemetry::{DropReason, LifecycleStage};

const PROCESS_EPOCH: u64 = 77;
const ADMISSION_BATCH_US: u64 = 1_000;
const BASE_LINK_DELAY_US: u64 = 1_000;
const MIN_LINK_RATE_BPS: u64 = 100_000_000;

#[derive(Clone, Copy, Debug, Eq, PartialEq)]
enum Impairment {
    Normal,
    DelayMs(u64),
    LossPermille(u64),
    StallMs(u64),
    DisconnectReconnect,
    FeedbackDelayMs(u64),
}

#[derive(Clone, Debug)]
struct SchedulerLoad {
    duration: Duration,
    offered_rate_bps: u64,
    sessions: usize,
    synthetic_inner_flows: usize,
    legs: usize,
    datagram_bytes: usize,
    impairment: Impairment,
}

impl SchedulerLoad {
    fn new() -> Self {
        Self {
            duration: Duration::from_secs(60),
            offered_rate_bps: 300_000_000,
            sessions: 64,
            synthetic_inner_flows: 1,
            legs: 5,
            datagram_bytes: 16_000,
            impairment: Impairment::Normal,
        }
    }

    fn duration(mut self, duration: Duration) -> Self {
        self.duration = duration;
        self
    }

    fn offered_rate_bps(mut self, offered_rate_bps: u64) -> Self {
        self.offered_rate_bps = offered_rate_bps;
        self
    }

    fn sessions(mut self, sessions: usize) -> Self {
        self.sessions = sessions;
        self
    }

    fn synthetic_inner_flows(mut self, synthetic_inner_flows: usize) -> Self {
        self.synthetic_inner_flows = synthetic_inner_flows;
        self
    }

    fn legs(mut self, legs: usize) -> Self {
        self.legs = legs;
        self
    }

    fn datagram_bytes(mut self, datagram_bytes: usize) -> Self {
        self.datagram_bytes = datagram_bytes;
        self
    }

    fn impairment(mut self, impairment: Impairment) -> Self {
        self.impairment = impairment;
        self
    }

    fn run(self) -> LoadResult {
        assert!(!self.duration.is_zero());
        assert!(self.offered_rate_bps > 0);
        assert!(self.sessions > 0);
        assert!(self.synthetic_inner_flows > 0);
        assert!(self.legs > 0);
        assert!((1..=65_535).contains(&self.datagram_bytes));

        VirtualLoad::new(self).run()
    }
}

#[derive(Clone, Debug)]
struct DataEvent {
    due_us: u64,
    epoch: u64,
    sent: SentTotals,
    deliveries: Vec<(usize, usize, usize)>,
}

#[derive(Clone, Copy, Debug)]
struct FeedbackEvent {
    due_us: u64,
    epoch: u64,
    sent: SentTotals,
}

#[derive(Debug)]
struct LinkModel {
    leg_id: u64,
    epoch: u64,
    rate_bps: u64,
    extra_delay_us: u64,
    feedback_delay_us: u64,
    next_wire_available_us: u64,
    last_data_due_us: u64,
    data_events: VecDeque<DataEvent>,
    feedback_events: VecDeque<FeedbackEvent>,
}

impl LinkModel {
    fn new(leg_id: u64, rate_bps: u64, impairment: Impairment) -> Self {
        let extra_delay_us = if leg_id == 1 {
            match impairment {
                Impairment::DelayMs(delay_ms) => delay_ms.saturating_mul(1_000),
                _ => 0,
            }
        } else {
            0
        };
        let feedback_delay_us = if leg_id == 1 {
            match impairment {
                Impairment::FeedbackDelayMs(delay_ms) => delay_ms.saturating_mul(1_000),
                _ => 0,
            }
        } else {
            0
        };
        Self {
            leg_id,
            epoch: 10_000 + leg_id,
            rate_bps,
            extra_delay_us,
            feedback_delay_us,
            next_wire_available_us: 0,
            last_data_due_us: 0,
            data_events: VecDeque::new(),
            feedback_events: VecDeque::new(),
        }
    }

    fn enqueue(
        &mut self,
        now_us: u64,
        wire_bytes: usize,
        sent: SentTotals,
        deliveries: Vec<(usize, usize, usize)>,
        impairment: Impairment,
        stall_start_us: u64,
    ) {
        let service_numerator = (wire_bytes as u128).saturating_mul(8_000_000);
        let service_us = service_numerator
            .saturating_add(u128::from(self.rate_bps.saturating_sub(1)))
            / u128::from(self.rate_bps);
        let service_us = service_us.max(1).min(u128::from(u64::MAX)) as u64;
        let service_start_us = now_us.max(self.next_wire_available_us);
        self.next_wire_available_us = service_start_us.saturating_add(service_us);
        let mut due_us = self
            .next_wire_available_us
            .saturating_add(BASE_LINK_DELAY_US)
            .saturating_add(self.extra_delay_us);

        if self.leg_id == 1 {
            if let Impairment::StallMs(stall_ms) = impairment {
                let stall_us = stall_ms.saturating_mul(1_000);
                let stall_end_us = stall_start_us.saturating_add(stall_us);
                if due_us >= stall_start_us && now_us < stall_end_us {
                    due_us = due_us.saturating_add(stall_us);
                }
            }
        }
        due_us = due_us.max(self.last_data_due_us.saturating_add(1));
        self.last_data_due_us = due_us;
        self.data_events.push_back(DataEvent {
            due_us,
            epoch: self.epoch,
            sent,
            deliveries,
        });
    }

    fn reset_for_reconnect(&mut self, now_us: u64) {
        self.epoch = self.epoch.saturating_add(1_000);
        self.next_wire_available_us = now_us;
        self.last_data_due_us = now_us;
        self.data_events.clear();
        self.feedback_events.clear();
    }

    fn next_event_us(&self) -> Option<u64> {
        self.data_events
            .front()
            .map(|event| event.due_us)
            .into_iter()
            .chain(self.feedback_events.front().map(|event| event.due_us))
            .min()
    }
}

#[derive(Debug)]
struct SkewHistogram {
    upper_bounds_us: [u64; 9],
    counts: [u64; 9],
    total: u64,
}

impl Default for SkewHistogram {
    fn default() -> Self {
        Self {
            upper_bounds_us: [100, 250, 500, 1_000, 2_000, 5_000, 10_000, 20_000, 60_000],
            counts: [0; 9],
            total: 0,
        }
    }
}

impl SkewHistogram {
    fn observe(&mut self, value_us: u64) {
        let index = self
            .upper_bounds_us
            .iter()
            .position(|bound| value_us <= *bound)
            .unwrap_or(self.upper_bounds_us.len() - 1);
        self.counts[index] = self.counts[index].saturating_add(1);
        self.total = self.total.saturating_add(1);
    }

    fn p99(&self) -> Duration {
        if self.total == 0 {
            return Duration::ZERO;
        }
        let rank = ((self.total as u128 * 99).saturating_add(99) / 100) as u64;
        let mut cumulative = 0_u64;
        for (index, count) in self.counts.iter().enumerate() {
            cumulative = cumulative.saturating_add(*count);
            if cumulative >= rank.max(1) {
                return Duration::from_micros(self.upper_bounds_us[index]);
            }
        }
        Duration::from_micros(*self.upper_bounds_us.last().unwrap())
    }
}

#[derive(Debug)]
struct LoadResult {
    useful_rate_bps: u64,
    queue_delay_p99: Duration,
    finish_skew_p99: Duration,
    scheduler_drop_ratio: f64,
    jain_fairness: f64,
    zero_rate_seconds: usize,
    memory_bound_violations: u64,
    udp_send_errors: u64,
    cross_session_stall: bool,
    active_legs: usize,
    active_inner_flows: usize,
    feedback_capable_active_legs: usize,
    legacy_fallback_active_legs: usize,
    unhealthy_leg_assignments: u64,
    observed_slow_leg_state: bool,
    stale_progress_rejections: u64,
    offered_packets: u64,
    remote_accepted_packets: u64,
    state_transitions: u64,
}

impl LoadResult {
    fn signature(&self) -> String {
        format!(
            "rate={} queue_p99_us={} finish_p99_us={} drops={:.9} fairness={:.9} zero={} memory={} udp={} cross_stall={} active_legs={} active_inner_flows={} feedback_legs={} legacy_legs={} unhealthy_assignments={} slow_state={} stale_rejections={} offered={} accepted={} transitions={}",
            self.useful_rate_bps,
            self.queue_delay_p99.as_micros(),
            self.finish_skew_p99.as_micros(),
            self.scheduler_drop_ratio,
            self.jain_fairness,
            self.zero_rate_seconds,
            self.memory_bound_violations,
            self.udp_send_errors,
            self.cross_session_stall,
            self.active_legs,
            self.active_inner_flows,
            self.feedback_capable_active_legs,
            self.legacy_fallback_active_legs,
            self.unhealthy_leg_assignments,
            self.observed_slow_leg_state,
            self.stale_progress_rejections,
            self.offered_packets,
            self.remote_accepted_packets,
            self.state_transitions,
        )
    }
}

struct VirtualLoad {
    input: SchedulerLoad,
    config: SchedulerConfig,
    scheduler: Scheduler,
    links: Vec<LinkModel>,
    delivered_by_session: Vec<u64>,
    delivered_by_flow: Vec<u64>,
    delivered_by_second: Vec<u64>,
    delivered_by_session_second: Vec<Vec<u64>>,
    delivered_by_leg: Vec<u64>,
    payload: Bytes,
    offered_credit: u128,
    offered_packets: u64,
    loss_eligible_frames: u64,
    memory_bound_violations: u64,
    max_outstanding_bytes: usize,
    unhealthy_leg_assignments: u64,
    observed_slow_leg_state: bool,
    stale_progress_rejections: u64,
    removed_leg_transitions: u64,
    skew: SkewHistogram,
}

impl VirtualLoad {
    fn flow_count(input: &SchedulerLoad) -> usize {
        input
            .sessions
            .saturating_mul(input.synthetic_inner_flows)
            .max(1)
    }

    fn new(input: SchedulerLoad) -> Self {
        let duration_seconds = input.duration.as_secs().max(1) as usize;
        let flow_count = Self::flow_count(&input);
        let config = SchedulerConfig {
            scheduler_mode: SchedulerMode::FeedbackDrr,
            aggregate_rate_bps: input.offered_rate_bps,
            ..SchedulerConfig::default()
        };
        config.validate(input.datagram_bytes).unwrap();
        let mut scheduler = Scheduler::new(config.clone(), PROCESS_EPOCH, 0);
        let link_rate_bps = MIN_LINK_RATE_BPS.max(
            input
                .offered_rate_bps
                .saturating_mul(2)
                .div_ceil(input.legs as u64),
        );
        let mut links = Vec::with_capacity(input.legs);
        for leg_index in 0..input.legs {
            let link = LinkModel::new(leg_index as u64 + 1, link_rate_bps, input.impairment);
            scheduler.register_leg(LegRegistration {
                leg_id: link.leg_id,
                scope_key: scope(),
                connection_epoch: link.epoch,
                progress_direction: Direction::ClientToSidecar,
                weight: 1,
                now_us: 0,
            });
            scheduler
                .acknowledge_leg(link.leg_id, &AuthAck::leg_progress(link.epoch), 0)
                .unwrap();
            links.push(link);
        }
        Self {
            delivered_by_session: vec![0; input.sessions],
            delivered_by_flow: vec![0; flow_count],
            delivered_by_second: vec![0; duration_seconds],
            delivered_by_session_second: vec![vec![0; duration_seconds]; input.sessions],
            delivered_by_leg: vec![0; input.legs],
            payload: Bytes::from(vec![0_u8; input.datagram_bytes]),
            input,
            config,
            scheduler,
            links,
            offered_credit: 0,
            offered_packets: 0,
            loss_eligible_frames: 0,
            memory_bound_violations: 0,
            max_outstanding_bytes: 0,
            unhealthy_leg_assignments: 0,
            observed_slow_leg_state: false,
            stale_progress_rejections: 0,
            removed_leg_transitions: 0,
            skew: SkewHistogram::default(),
        }
    }

    fn run(mut self) -> LoadResult {
        let end_us = self.input.duration.as_micros().min(u128::from(u64::MAX)) as u64;
        let stall_start_us = end_us / 3;
        let reconnect_us = end_us / 2;
        let mut reconnect_pending =
            matches!(self.input.impairment, Impairment::DisconnectReconnect);
        let mut next_admission_us = 0_u64;
        let mut scheduler_wake_us = None;
        let wire_bytes = self.input.datagram_bytes + 24;
        let frame_credit = (wire_bytes as u128).saturating_mul(8_000_000);
        let mut now_us = 0_u64;

        while now_us < end_us {
            if reconnect_pending && now_us >= reconnect_us {
                self.reconnect_first_leg(now_us);
                reconnect_pending = false;
            }
            self.process_data_events(now_us, end_us);
            self.process_feedback_events(now_us);
            self.scheduler.tick(now_us);
            self.observe_slow_leg_state();

            if now_us >= next_admission_us {
                self.offered_credit = self.offered_credit.saturating_add(
                    u128::from(self.input.offered_rate_bps)
                        .saturating_mul(u128::from(ADMISSION_BATCH_US)),
                );
                while self.offered_credit >= frame_credit {
                    self.offered_credit -= frame_credit;
                    self.admit_one(now_us);
                }
                next_admission_us = next_admission_us.saturating_add(ADMISSION_BATCH_US);
            }

            scheduler_wake_us = self.dispatch(now_us, stall_start_us, scheduler_wake_us);
            self.check_memory_bounds();

            let next_link_event = self.links.iter().filter_map(LinkModel::next_event_us).min();
            let next_reconnect = reconnect_pending.then_some(reconnect_us);
            let next_us = [
                (next_admission_us < end_us).then_some(next_admission_us),
                scheduler_wake_us.filter(|wake| *wake < end_us),
                next_link_event.filter(|event| *event < end_us),
                next_reconnect.filter(|event| *event < end_us),
                Some(end_us),
            ]
            .into_iter()
            .flatten()
            .filter(|candidate| *candidate > now_us)
            .min()
            .unwrap_or(end_us);
            now_us = next_us;
        }

        self.finish(end_us)
    }

    fn admit_one(&mut self, now_us: u64) {
        let session_index = self.offered_packets as usize % self.input.sessions;
        self.scheduler.admit_frame(
            session(session_index as u64 + 1),
            self.offered_packets,
            self.payload.clone(),
            TrafficClass::Regular,
            now_us,
        );
        self.offered_packets = self.offered_packets.saturating_add(1);
    }

    fn dispatch(
        &mut self,
        now_us: u64,
        stall_start_us: u64,
        mut scheduler_wake_us: Option<u64>,
    ) -> Option<u64> {
        if scheduler_wake_us.is_some_and(|wake| wake > now_us) {
            return scheduler_wake_us;
        }
        scheduler_wake_us = None;
        let mut dispatches = 0_usize;
        loop {
            dispatches += 1;
            assert!(
                dispatches <= 100_000,
                "scheduler did not quiesce at {now_us}"
            );
            let outcome = self.scheduler.schedule_next(now_us);
            match outcome {
                ScheduleOutcome::Assignment(assignment) => {
                    let leg_index = assignment.leg_id as usize - 1;
                    let selected_state = self.scheduler.leg(assignment.leg_id).unwrap().state;
                    if !matches!(
                        selected_state,
                        LegState::Warmup | LegState::Healthy | LegState::Probe
                    ) {
                        self.unhealthy_leg_assignments =
                            self.unhealthy_leg_assignments.saturating_add(1);
                    }

                    self.scheduler.writer_started(
                        assignment.leg_id,
                        &assignment.reservation_ids,
                        now_us,
                    );
                    let mut accepted_ids = Vec::with_capacity(assignment.reservation_ids.len());
                    let mut lost_ids = Vec::new();
                    let mut deliveries = Vec::with_capacity(assignment.datagrams.len());
                    let mut accepted_wire_bytes = 0_usize;
                    for datagram in &assignment.datagrams {
                        let lost = self.should_drop_on_link(assignment.leg_id);
                        if lost {
                            lost_ids.push(datagram.reservation_id);
                        } else {
                            accepted_ids.push(datagram.reservation_id);
                            let session_index = datagram.session.session_id as usize - 1;
                            let flow_in_session = datagram.sequence as usize / self.input.sessions
                                % self.input.synthetic_inner_flows;
                            let flow_index = session_index
                                .saturating_mul(self.input.synthetic_inner_flows)
                                .saturating_add(flow_in_session);
                            deliveries.push((session_index, flow_index, datagram.payload.len()));
                            accepted_wire_bytes =
                                accepted_wire_bytes.saturating_add(datagram.wire_bytes);
                        }
                    }
                    if !lost_ids.is_empty() {
                        self.scheduler.writer_failed(assignment.leg_id, &lost_ids);
                    }
                    let sent =
                        self.scheduler
                            .writer_accepted(assignment.leg_id, &accepted_ids, now_us);
                    if !accepted_ids.is_empty() {
                        self.links[leg_index].enqueue(
                            now_us,
                            accepted_wire_bytes,
                            sent,
                            deliveries,
                            self.input.impairment,
                            stall_start_us,
                        );
                    }
                    self.observe_finish_skew(now_us);
                }
                ScheduleOutcome::WaitUntilUs(wake_us) => {
                    scheduler_wake_us = Some(wake_us.max(now_us.saturating_add(1)));
                    break;
                }
                ScheduleOutcome::Idle => break,
            }
        }
        scheduler_wake_us
    }

    fn should_drop_on_link(&mut self, leg_id: u64) -> bool {
        if leg_id != 1 {
            return false;
        }
        let Impairment::LossPermille(permille) = self.input.impairment else {
            return false;
        };
        let interval = 1_000 / permille.max(1);
        self.loss_eligible_frames = self.loss_eligible_frames.saturating_add(1);
        self.loss_eligible_frames % interval == interval / 2
    }

    fn process_data_events(&mut self, now_us: u64, end_us: u64) {
        for leg_index in 0..self.links.len() {
            loop {
                let due = self.links[leg_index]
                    .data_events
                    .front()
                    .map(|event| event.due_us);
                if !due.is_some_and(|due_us| due_us <= now_us) {
                    break;
                }
                let event = self.links[leg_index].data_events.pop_front().unwrap();
                if event.epoch != self.links[leg_index].epoch {
                    continue;
                }
                if now_us < end_us {
                    let second = (now_us / 1_000_000) as usize;
                    for (session_index, flow_index, payload_bytes) in event.deliveries {
                        let payload_bytes = payload_bytes as u64;
                        self.delivered_by_session[session_index] =
                            self.delivered_by_session[session_index].saturating_add(payload_bytes);
                        self.delivered_by_second[second] =
                            self.delivered_by_second[second].saturating_add(payload_bytes);
                        self.delivered_by_session_second[session_index][second] = self
                            .delivered_by_session_second[session_index][second]
                            .saturating_add(payload_bytes);
                        self.delivered_by_flow[flow_index] =
                            self.delivered_by_flow[flow_index].saturating_add(payload_bytes);
                        self.delivered_by_leg[leg_index] =
                            self.delivered_by_leg[leg_index].saturating_add(payload_bytes);
                    }
                }
                let feedback_due_us =
                    now_us.saturating_add(self.links[leg_index].feedback_delay_us);
                self.links[leg_index]
                    .feedback_events
                    .push_back(FeedbackEvent {
                        due_us: feedback_due_us,
                        epoch: event.epoch,
                        sent: event.sent,
                    });
            }
        }
    }

    fn process_feedback_events(&mut self, now_us: u64) {
        for leg_index in 0..self.links.len() {
            loop {
                let due = self.links[leg_index]
                    .feedback_events
                    .front()
                    .map(|event| event.due_us);
                if !due.is_some_and(|due_us| due_us <= now_us) {
                    break;
                }
                let event = self.links[leg_index].feedback_events.pop_front().unwrap();
                if event.epoch != self.links[leg_index].epoch {
                    continue;
                }
                let progress = LegProgress::accepted(
                    Direction::ClientToSidecar,
                    event.epoch,
                    event.sent.cumulative_frames,
                    event.sent.cumulative_wire_bytes,
                    event.sent.cumulative_frames,
                    event.sent.cumulative_wire_bytes,
                );
                self.scheduler
                    .on_progress(self.links[leg_index].leg_id, &progress, now_us)
                    .unwrap();
            }
        }
    }

    fn reconnect_first_leg(&mut self, now_us: u64) {
        let old_epoch = self.links[0].epoch;
        self.removed_leg_transitions = self.removed_leg_transitions.saturating_add(
            self.scheduler
                .leg(self.links[0].leg_id)
                .map(|leg| leg.transitions)
                .unwrap_or(0),
        );
        self.scheduler.disconnect_leg(self.links[0].leg_id);
        self.links[0].reset_for_reconnect(now_us);
        self.scheduler.register_leg(LegRegistration {
            leg_id: self.links[0].leg_id,
            scope_key: scope(),
            connection_epoch: self.links[0].epoch,
            progress_direction: Direction::ClientToSidecar,
            weight: 1,
            now_us,
        });
        self.scheduler
            .acknowledge_leg(
                self.links[0].leg_id,
                &AuthAck::leg_progress(self.links[0].epoch),
                now_us,
            )
            .unwrap();

        let stale = LegProgress::accepted(Direction::ClientToSidecar, old_epoch, 0, 0, 0, 0);
        assert!(
            self.scheduler
                .on_progress(self.links[0].leg_id, &stale, now_us)
                .is_err(),
            "a prior connection epoch must not mutate the replacement leg"
        );
        self.stale_progress_rejections = self.stale_progress_rejections.saturating_add(1);
    }

    fn observe_slow_leg_state(&mut self) {
        if matches!(self.input.impairment, Impairment::StallMs(_)) {
            self.observed_slow_leg_state |= self
                .scheduler
                .leg(1)
                .is_some_and(|leg| matches!(leg.state, LegState::Lagging | LegState::Quarantined));
        }
    }

    fn observe_finish_skew(&mut self, now_us: u64) {
        let mut minimum = u64::MAX;
        let mut maximum = 0_u64;
        for link in &self.links {
            let finish = link.last_data_due_us.saturating_sub(now_us);
            minimum = minimum.min(finish);
            maximum = maximum.max(finish);
        }
        if minimum != u64::MAX {
            self.skew.observe(maximum.saturating_sub(minimum));
        }
    }

    fn check_memory_bounds(&mut self) {
        let total_outstanding = self
            .links
            .iter()
            .map(|link| self.scheduler.window_reserved_bytes(link.leg_id) as usize)
            .sum::<usize>();
        self.max_outstanding_bytes = self.max_outstanding_bytes.max(total_outstanding);
        let violation = self.scheduler.queue_bytes() > self.config.global_queue_bytes
            || self.scheduler.queue_packets() > self.config.global_queue_packets
            || self.scheduler.queue_bytes_high_water() > self.config.global_queue_bytes
            || total_outstanding > self.config.max_total_outstanding_bytes
            || self.links.iter().any(|link| {
                self.scheduler.window_reserved_bytes(link.leg_id)
                    > self.config.max_leg_window_bytes as u64
            });
        if violation {
            self.memory_bound_violations = self.memory_bound_violations.saturating_add(1);
        }
    }

    fn finish(self, end_us: u64) -> LoadResult {
        let useful_bytes = self.delivered_by_session.iter().sum::<u64>();
        let useful_rate_bps = u128::from(useful_bytes)
            .saturating_mul(8_000_000)
            .checked_div(u128::from(end_us))
            .unwrap_or(0)
            .min(u128::from(u64::MAX)) as u64;
        let feedback_legs = self.scheduler.feedback_capable_active_legs();
        let legacy_legs = self.scheduler.legacy_fallback_active_legs();
        let snapshot = self.scheduler.telemetry().snapshot(
            self.delivered_by_session
                .iter()
                .enumerate()
                .map(|(index, bytes)| (format!("session-{}", index + 1), *bytes, true)),
            useful_rate_bps,
            feedback_legs,
            legacy_legs,
            self.scheduler.queue_bytes(),
            self.scheduler.queue_bytes_high_water(),
            self.links
                .iter()
                .map(|link| self.scheduler.window_reserved_bytes(link.leg_id) as usize)
                .sum(),
            self.max_outstanding_bytes,
            Vec::new(),
        );
        let cross_session_stall = self.delivered_by_session_second.iter().any(|seconds| {
            seconds
                .iter()
                .zip(&self.delivered_by_second)
                .any(|(session_bytes, aggregate_bytes)| *aggregate_bytes > 0 && *session_bytes == 0)
        });
        let state_transitions = self.removed_leg_transitions.saturating_add(
            self.links
                .iter()
                .filter_map(|link| self.scheduler.leg(link.leg_id))
                .map(|leg| leg.transitions)
                .sum::<u64>(),
        );

        LoadResult {
            useful_rate_bps,
            queue_delay_p99: Duration::from_millis(snapshot.scheduler_queue.p99_ms),
            finish_skew_p99: self.skew.p99(),
            scheduler_drop_ratio: snapshot.scheduler_drop_ratio,
            jain_fairness: snapshot.jain_fairness,
            zero_rate_seconds: self
                .delivered_by_second
                .iter()
                .filter(|bytes| **bytes == 0)
                .count(),
            memory_bound_violations: self.memory_bound_violations,
            udp_send_errors: snapshot.udp_errors.values().copied().sum(),
            cross_session_stall,
            active_legs: self
                .delivered_by_leg
                .iter()
                .filter(|bytes| **bytes > 0)
                .count(),
            active_inner_flows: self
                .delivered_by_flow
                .iter()
                .filter(|bytes| **bytes > 0)
                .count(),
            feedback_capable_active_legs: feedback_legs,
            legacy_fallback_active_legs: legacy_legs,
            unhealthy_leg_assignments: self.unhealthy_leg_assignments,
            observed_slow_leg_state: self.observed_slow_leg_state,
            stale_progress_rejections: self.stale_progress_rejections,
            offered_packets: self
                .scheduler
                .telemetry()
                .lifecycle_counter(LifecycleStage::Offered)
                .packets,
            remote_accepted_packets: self
                .scheduler
                .telemetry()
                .lifecycle_counter(LifecycleStage::RemoteUdpAccepted)
                .packets,
            state_transitions,
        }
    }
}

fn scope() -> SchedulerScopeKey {
    SchedulerScopeKey::Client { forward_id: 9 }
}

fn session(session_id: u64) -> SchedulerSessionKey {
    SchedulerSessionKey {
        scope_key: scope(),
        session_id,
        direction: Direction::ClientToSidecar,
        process_epoch: PROCESS_EPOCH,
    }
}

fn run_impaired(impairment: Impairment) -> LoadResult {
    // Keep the core acceptance case at 1,200 bytes; the impairment matrix uses
    // larger datagrams to exercise the same byte-rate and control paths without
    // making debug CI spend minutes on millions of tiny virtual packets.
    SchedulerLoad::new()
        .duration(Duration::from_secs(60))
        .offered_rate_bps(300_000_000)
        .sessions(64)
        .legs(5)
        .datagram_bytes(16_000)
        .impairment(impairment)
        .run()
}

#[test]
fn sixty_four_sessions_sustain_fair_bounded_virtual_load() {
    let result = SchedulerLoad::new()
        .duration(Duration::from_secs(60))
        .offered_rate_bps(300_000_000)
        .sessions(64)
        .legs(5)
        .datagram_bytes(1_200)
        .run();

    assert!(result.useful_rate_bps >= 270_000_000);
    assert!(result.queue_delay_p99 <= Duration::from_millis(10));
    assert!(result.scheduler_drop_ratio <= 0.001);
    assert!(result.jain_fairness >= 0.90);
    assert_eq!(result.zero_rate_seconds, 0);
    assert_eq!(result.memory_bound_violations, 0);
    assert_eq!(result.active_legs, 5);
    assert_eq!(result.active_inner_flows, 64);
    assert_eq!(result.feedback_capable_active_legs, 5);
    assert_eq!(result.legacy_fallback_active_legs, 0);
    assert!(result.finish_skew_p99 <= Duration::from_millis(10));
    eprintln!("normal-64 {}", result.signature());
}

#[test]
fn one_tunnel_session_uses_all_healthy_legs_without_cross_session_stall() {
    let result = SchedulerLoad::new()
        .duration(Duration::from_secs(60))
        .offered_rate_bps(300_000_000)
        .sessions(1)
        .synthetic_inner_flows(32)
        .legs(5)
        .run();

    assert!(result.active_legs >= 2);
    assert_eq!(result.active_inner_flows, 32);
    assert_eq!(result.zero_rate_seconds, 0);
    assert!(result.queue_delay_p99 <= Duration::from_millis(10));
    assert!(!result.cross_session_stall);
    assert!(result.finish_skew_p99 <= Duration::from_millis(10));
    eprintln!("normal-tunnel32 {}", result.signature());
}

#[test]
fn delayed_stalled_and_reconnected_legs_are_bounded() {
    let baseline = run_impaired(Impairment::Normal);
    for impairment in [
        Impairment::DelayMs(1),
        Impairment::DelayMs(5),
        Impairment::DelayMs(10),
        Impairment::LossPermille(1),
        Impairment::LossPermille(5),
        Impairment::StallMs(500),
        Impairment::DisconnectReconnect,
        Impairment::FeedbackDelayMs(10),
    ] {
        let result = run_impaired(impairment);
        assert_eq!(result.zero_rate_seconds, 0, "{impairment:?}");
        assert_eq!(result.memory_bound_violations, 0, "{impairment:?}");
        assert_eq!(result.udp_send_errors, 0, "{impairment:?}");
        assert!(!result.cross_session_stall, "{impairment:?}");
        assert_eq!(result.unhealthy_leg_assignments, 0, "{impairment:?}");
        assert!(
            result.useful_rate_bps >= baseline.useful_rate_bps.saturating_mul(9) / 10,
            "{impairment:?}: baseline={} impaired={}",
            baseline.useful_rate_bps,
            result.useful_rate_bps,
        );
        match impairment {
            Impairment::StallMs(_) => assert!(result.observed_slow_leg_state),
            Impairment::DisconnectReconnect => {
                assert_eq!(result.stale_progress_rejections, 1)
            }
            _ => assert_eq!(result.stale_progress_rejections, 0),
        }
        eprintln!("{impairment:?} {}", result.signature());
    }
}

#[test]
fn queue_deadline_expires_before_later_capacity_pressure() {
    let mut config = SchedulerConfig {
        scheduler_mode: SchedulerMode::FeedbackDrr,
        queue_deadline_ms: 10,
        global_queue_packets: 1,
        per_session_queue_packets: 1,
        global_queue_bytes: 2_400,
        per_session_queue_bytes: 2_400,
        ..SchedulerConfig::default()
    };
    config.control_queue_packets = 1;
    let mut scheduler = Scheduler::new(config, PROCESS_EPOCH, 0);
    let reservation = scheduler
        .admit(
            session(1),
            Bytes::from(vec![0_u8; 1_200]),
            TrafficClass::Regular,
            0,
        )
        .reservation_id()
        .unwrap();

    scheduler.tick(10_000);

    let replacement = scheduler.admit(
        session(2),
        Bytes::from(vec![0_u8; 1_200]),
        TrafficClass::Regular,
        10_000,
    );

    assert!(replacement.is_admitted());
    assert_eq!(scheduler.queue_packets(), 1);
    assert_eq!(scheduler.queue_bytes(), 1_200);
    assert_eq!(scheduler.drop_packets(DropReason::QueueDeadline), 1);
    assert_eq!(
        scheduler.reservation_stage(reservation),
        Some(leikwan_uot::scheduler::ReservationStage::Dropped(
            DropReason::QueueDeadline
        ))
    );
}
