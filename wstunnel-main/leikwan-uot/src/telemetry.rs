use std::collections::BTreeMap;
use std::io;

use serde::Serialize;

#[derive(Clone, Copy, Debug, Eq, Ord, PartialEq, PartialOrd)]
pub enum LifecycleStage {
    Offered,
    Admitted,
    Scheduled,
    QueuedToLeg,
    Writing,
    LocalSocketAccepted,
    RemoteDecoded,
    RemoteUdpAccepted,
}

impl LifecycleStage {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::Offered => "offered",
            Self::Admitted => "admitted",
            Self::Scheduled => "scheduled",
            Self::QueuedToLeg => "queued_to_leg",
            Self::Writing => "writing",
            Self::LocalSocketAccepted => "local_socket_accepted",
            Self::RemoteDecoded => "remote_decoded",
            Self::RemoteUdpAccepted => "remote_udp_accepted",
        }
    }
}

#[derive(Clone, Copy, Debug, Eq, Ord, PartialEq, PartialOrd)]
pub enum DropReason {
    QueueDeadline,
    RootQueueFull,
    SessionQueueFull,
    NoHealthyLeg,
    LegWindowFull,
    WriterClosed,
    UdpEgressError,
    InvalidProgress,
}

impl DropReason {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::QueueDeadline => "drop_queue_deadline",
            Self::RootQueueFull => "drop_root_queue_full",
            Self::SessionQueueFull => "drop_session_queue_full",
            Self::NoHealthyLeg => "drop_no_healthy_leg",
            Self::LegWindowFull => "drop_leg_window_full",
            Self::WriterClosed => "drop_writer_closed",
            Self::UdpEgressError => "drop_udp_egress_error",
            Self::InvalidProgress => "drop_invalid_progress",
        }
    }

    pub const fn is_data_drop(self) -> bool {
        !matches!(self, Self::InvalidProgress)
    }
}

#[derive(Clone, Copy, Debug, Eq, Ord, PartialEq, PartialOrd)]
pub enum UdpErrorKind {
    WouldBlock,
    Enobufs,
    Unreachable,
    Closed,
    Other,
}

impl UdpErrorKind {
    pub const fn as_str(self) -> &'static str {
        match self {
            Self::WouldBlock => "udp_error_would_block",
            Self::Enobufs => "udp_error_enobufs",
            Self::Unreachable => "udp_error_unreachable",
            Self::Closed => "udp_error_closed",
            Self::Other => "udp_error_other",
        }
    }

    pub fn from_io_error(error: &io::Error) -> Self {
        if matches!(error.raw_os_error(), Some(105 | 10055)) {
            return Self::Enobufs;
        }
        if matches!(error.raw_os_error(), Some(101 | 113 | 10051 | 10065)) {
            return Self::Unreachable;
        }
        match error.kind() {
            io::ErrorKind::WouldBlock => Self::WouldBlock,
            io::ErrorKind::ConnectionRefused
            | io::ErrorKind::AddrNotAvailable
            | io::ErrorKind::TimedOut => Self::Unreachable,
            io::ErrorKind::BrokenPipe
            | io::ErrorKind::NotConnected
            | io::ErrorKind::ConnectionAborted
            | io::ErrorKind::ConnectionReset => Self::Closed,
            _ => Self::Other,
        }
    }
}

#[derive(Default, Clone, Copy, Debug, Serialize, PartialEq, Eq)]
pub struct TrafficCounter {
    pub packets: u64,
    pub payload_bytes: u64,
    pub wire_bytes: u64,
}

impl TrafficCounter {
    pub fn add(&mut self, packets: u64, payload_bytes: u64, wire_bytes: u64) {
        self.packets = self.packets.saturating_add(packets);
        self.payload_bytes = self.payload_bytes.saturating_add(payload_bytes);
        self.wire_bytes = self.wire_bytes.saturating_add(wire_bytes);
    }
}

#[derive(Clone, Debug)]
pub struct DelayHistogram {
    upper_bounds_ms: Vec<u64>,
    counts: Vec<u64>,
    count: u64,
}

impl DelayHistogram {
    pub fn milliseconds(bounds: &[u64]) -> Self {
        let mut upper_bounds_ms = bounds.to_vec();
        upper_bounds_ms.sort_unstable();
        upper_bounds_ms.dedup();
        if upper_bounds_ms.is_empty() {
            upper_bounds_ms.push(60_000);
        }
        let counts = vec![0; upper_bounds_ms.len()];
        Self {
            upper_bounds_ms,
            counts,
            count: 0,
        }
    }

    pub fn observe(&mut self, value_ms: u64) {
        let index = self
            .upper_bounds_ms
            .iter()
            .position(|bound| value_ms <= *bound)
            .unwrap_or(self.upper_bounds_ms.len() - 1);
        self.counts[index] = self.counts[index].saturating_add(1);
        self.count = self.count.saturating_add(1);
    }

    pub fn percentile(&self, percentile: f64) -> u64 {
        if self.count == 0 {
            return 0;
        }
        let percentile = percentile.clamp(0.0, 1.0);
        let rank = ((self.count as f64 * percentile).ceil() as u64).max(1);
        let mut cumulative = 0_u64;
        for (index, count) in self.counts.iter().enumerate() {
            cumulative = cumulative.saturating_add(*count);
            if cumulative >= rank {
                return self.upper_bounds_ms[index];
            }
        }
        *self.upper_bounds_ms.last().unwrap()
    }

    pub const fn count(&self) -> u64 {
        self.count
    }

    pub fn snapshot(&self) -> DelayPercentiles {
        DelayPercentiles {
            p50_ms: self.percentile(0.50),
            p95_ms: self.percentile(0.95),
            p99_ms: self.percentile(0.99),
        }
    }
}

#[derive(Clone, Debug, Serialize, PartialEq, Eq)]
pub struct TopSessionEntry {
    pub key: String,
    pub value: u64,
}

#[derive(Clone, Debug)]
pub struct TopSessions {
    capacity: usize,
    values: BTreeMap<String, u64>,
}

impl TopSessions {
    pub fn new(capacity: usize) -> Self {
        Self {
            capacity,
            values: BTreeMap::new(),
        }
    }

    pub fn observe(&mut self, key: impl Into<String>, value: u64) {
        if self.capacity == 0 {
            return;
        }
        let key = key.into();
        self.values
            .entry(key)
            .and_modify(|current| *current = current.saturating_add(value))
            .or_insert(value);
        if self.values.len() > self.capacity {
            let remove = self
                .values
                .iter()
                .min_by_key(|(key, value)| (**value, *key))
                .map(|(key, _)| key.clone())
                .unwrap();
            self.values.remove(&remove);
        }
    }

    pub fn snapshot(&self) -> Vec<TopSessionEntry> {
        let mut result = self
            .values
            .iter()
            .map(|(key, value)| TopSessionEntry {
                key: key.clone(),
                value: *value,
            })
            .collect::<Vec<_>>();
        result.sort_by(|left, right| {
            right
                .value
                .cmp(&left.value)
                .then_with(|| left.key.cmp(&right.key))
        });
        result
    }
}

pub fn jain_fairness<I, K>(rates: I) -> f64
where
    I: IntoIterator<Item = (K, u64, bool)>,
{
    let values = rates
        .into_iter()
        .filter_map(|(_, rate, active)| active.then_some(rate as f64))
        .collect::<Vec<_>>();
    if values.is_empty() {
        return 1.0;
    }
    let sum = values.iter().sum::<f64>();
    let sum_sq = values.iter().map(|value| value * value).sum::<f64>();
    if sum_sq == 0.0 {
        1.0
    } else {
        (sum * sum) / (values.len() as f64 * sum_sq)
    }
}

#[derive(Clone, Debug, Serialize, PartialEq, Eq)]
pub struct DelayPercentiles {
    pub p50_ms: u64,
    pub p95_ms: u64,
    pub p99_ms: u64,
}

#[derive(Clone, Debug, Serialize, PartialEq, Eq)]
pub struct LegStatsSnapshot {
    pub leg_id: u64,
    pub state: String,
    pub outstanding_wire_bytes: u64,
    pub progress_age_ms: u64,
    pub progress_srtt_ms: u64,
    pub delivery_rate_ewma_bps: u64,
    pub write_stalls: u64,
    pub transitions: u64,
}

#[derive(Clone, Debug, Serialize)]
pub struct StatsSnapshot {
    pub lifecycle: BTreeMap<String, TrafficCounter>,
    pub drops: BTreeMap<String, TrafficCounter>,
    pub udp_errors: BTreeMap<String, u64>,
    pub scheduler_drop_ratio: f64,
    pub invalid_progress_ratio: f64,
    pub admission_to_schedule: DelayPercentiles,
    pub writer_wait: DelayPercentiles,
    pub scheduler_queue: DelayPercentiles,
    pub active_delivery_rate_bps: u64,
    pub jain_fairness: f64,
    pub feedback_capable_active_legs: usize,
    pub legacy_fallback_active_legs: usize,
    pub queue_bytes: usize,
    pub queue_bytes_high_water: usize,
    pub outstanding_bytes: usize,
    pub outstanding_bytes_high_water: usize,
    pub legs: Vec<LegStatsSnapshot>,
    pub top_sessions: Vec<TopSessionEntry>,
}

#[derive(Clone, Debug)]
pub struct Telemetry {
    lifecycle: BTreeMap<String, TrafficCounter>,
    drops: BTreeMap<String, TrafficCounter>,
    udp_errors: BTreeMap<String, u64>,
    invalid_progress_offered: u64,
    admission_to_schedule: DelayHistogram,
    writer_wait: DelayHistogram,
    scheduler_queue: DelayHistogram,
    top_sessions: TopSessions,
}

impl Default for Telemetry {
    fn default() -> Self {
        const BOUNDS: &[u64] = &[1, 2, 5, 10, 20, 50, 100, 250, 500, 1_000, 5_000, 60_000];
        Self {
            lifecycle: BTreeMap::new(),
            drops: BTreeMap::new(),
            udp_errors: BTreeMap::new(),
            invalid_progress_offered: 0,
            admission_to_schedule: DelayHistogram::milliseconds(BOUNDS),
            writer_wait: DelayHistogram::milliseconds(BOUNDS),
            scheduler_queue: DelayHistogram::milliseconds(BOUNDS),
            top_sessions: TopSessions::new(128),
        }
    }
}

impl Telemetry {
    pub fn record_lifecycle(
        &mut self,
        stage: LifecycleStage,
        payload_bytes: usize,
        wire_bytes: usize,
    ) {
        self.lifecycle
            .entry(stage.as_str().to_string())
            .or_default()
            .add(1, payload_bytes as u64, wire_bytes as u64);
    }

    pub fn record_drop(&mut self, reason: DropReason, payload_bytes: usize, wire_bytes: usize) {
        self.drops
            .entry(reason.as_str().to_string())
            .or_default()
            .add(1, payload_bytes as u64, wire_bytes as u64);
    }

    pub fn record_invalid_progress_input(&mut self) {
        self.invalid_progress_offered = self.invalid_progress_offered.saturating_add(1);
    }

    pub fn record_udp_error(&mut self, kind: UdpErrorKind) {
        let count = self
            .udp_errors
            .entry(kind.as_str().to_string())
            .or_default();
        *count = count.saturating_add(1);
    }

    pub fn observe_admission_to_schedule_ms(&mut self, value: u64) {
        self.admission_to_schedule.observe(value);
    }

    pub fn observe_writer_wait_ms(&mut self, value: u64) {
        self.writer_wait.observe(value);
    }

    pub fn observe_scheduler_queue_ms(&mut self, value: u64) {
        self.scheduler_queue.observe(value);
    }

    pub fn observe_session_delivery(&mut self, key: impl Into<String>, bytes: u64) {
        self.top_sessions.observe(key, bytes);
    }

    pub fn lifecycle_counter(&self, stage: LifecycleStage) -> TrafficCounter {
        self.lifecycle
            .get(stage.as_str())
            .copied()
            .unwrap_or_default()
    }

    pub fn drop_counter(&self, reason: DropReason) -> TrafficCounter {
        self.drops.get(reason.as_str()).copied().unwrap_or_default()
    }

    pub fn snapshot(
        &self,
        session_rates: impl IntoIterator<Item = (String, u64, bool)>,
        active_delivery_rate_bps: u64,
        feedback_capable_active_legs: usize,
        legacy_fallback_active_legs: usize,
        queue_bytes: usize,
        queue_bytes_high_water: usize,
        outstanding_bytes: usize,
        outstanding_bytes_high_water: usize,
        legs: Vec<LegStatsSnapshot>,
    ) -> StatsSnapshot {
        let offered = self.lifecycle_counter(LifecycleStage::Offered).packets;
        let data_drops = DropReason::all_data()
            .iter()
            .map(|reason| self.drop_counter(*reason).packets)
            .sum::<u64>();
        let invalid = self.drop_counter(DropReason::InvalidProgress).packets;
        let top_sessions = self.top_sessions.snapshot();
        let supplied_rates = session_rates
            .into_iter()
            .map(|(_, rate, active)| ((), rate, active))
            .collect::<Vec<_>>();
        let fairness = if supplied_rates.is_empty() {
            jain_fairness(top_sessions.iter().map(|entry| ((), entry.value, true)))
        } else {
            jain_fairness(supplied_rates)
        };
        StatsSnapshot {
            lifecycle: self.lifecycle.clone(),
            drops: self.drops.clone(),
            udp_errors: self.udp_errors.clone(),
            scheduler_drop_ratio: ratio(data_drops, offered),
            invalid_progress_ratio: ratio(invalid, self.invalid_progress_offered),
            admission_to_schedule: self.admission_to_schedule.snapshot(),
            writer_wait: self.writer_wait.snapshot(),
            scheduler_queue: self.scheduler_queue.snapshot(),
            active_delivery_rate_bps,
            jain_fairness: fairness,
            feedback_capable_active_legs,
            legacy_fallback_active_legs,
            queue_bytes,
            queue_bytes_high_water,
            outstanding_bytes,
            outstanding_bytes_high_water,
            legs,
            top_sessions,
        }
    }
}

impl DropReason {
    const fn all_data() -> [Self; 7] {
        [
            Self::QueueDeadline,
            Self::RootQueueFull,
            Self::SessionQueueFull,
            Self::NoHealthyLeg,
            Self::LegWindowFull,
            Self::WriterClosed,
            Self::UdpEgressError,
        ]
    }
}

fn ratio(numerator: u64, denominator: u64) -> f64 {
    if denominator == 0 {
        0.0
    } else {
        numerator as f64 / denominator as f64
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn bounded_histogram_reports_requested_percentiles() {
        let mut histogram = DelayHistogram::milliseconds(&[1, 2, 5, 10, 20, 50]);
        for value in [1, 2, 3, 4, 9, 11] {
            histogram.observe(value);
        }
        assert_eq!(histogram.percentile(0.50), 5);
        assert_eq!(histogram.percentile(0.95), 20);
        assert_eq!(histogram.percentile(0.99), 20);
        assert_eq!(histogram.count(), 6);
    }

    #[test]
    fn jain_fairness_excludes_idle_sessions() {
        let active = [("a", 100_u64, true), ("b", 100, true), ("idle", 0, false)];
        assert!((jain_fairness(active) - 1.0).abs() < 0.000_001);
    }

    #[test]
    fn top_sessions_remains_fixed_capacity() {
        let mut top = TopSessions::new(2);
        top.observe("a", 10);
        top.observe("b", 30);
        top.observe("c", 20);
        assert_eq!(
            top.snapshot()
                .iter()
                .map(|entry| entry.key.as_str())
                .collect::<Vec<_>>(),
            vec!["b", "c"]
        );
    }

    #[test]
    fn stable_metric_names_do_not_drift() {
        assert_eq!(
            LifecycleStage::RemoteUdpAccepted.as_str(),
            "remote_udp_accepted"
        );
        assert_eq!(DropReason::QueueDeadline.as_str(), "drop_queue_deadline");
        assert_eq!(
            DropReason::InvalidProgress.as_str(),
            "drop_invalid_progress"
        );
        assert_eq!(UdpErrorKind::WouldBlock.as_str(), "udp_error_would_block");
        assert_eq!(UdpErrorKind::Enobufs.as_str(), "udp_error_enobufs");
        assert_eq!(UdpErrorKind::Unreachable.as_str(), "udp_error_unreachable");
        assert_eq!(UdpErrorKind::Closed.as_str(), "udp_error_closed");
        assert_eq!(UdpErrorKind::Other.as_str(), "udp_error_other");
    }
}
