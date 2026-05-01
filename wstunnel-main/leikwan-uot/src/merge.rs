use std::collections::{BTreeMap, VecDeque};

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct MergeItem {
    pub session_id: u64,
    pub seq: u64,
    pub payload: Vec<u8>,
}

#[derive(Debug, Clone, Default)]
pub struct SessionMergeState {
    pub next_expected_seq: u64,
    pub buffered: BTreeMap<u64, Vec<u8>>,
    pub gap_started_at: Option<u64>,
    pub buffered_bytes: usize,
}

impl SessionMergeState {
    fn new() -> Self {
        Self {
            next_expected_seq: 1,
            buffered: BTreeMap::new(),
            gap_started_at: None,
            buffered_bytes: 0,
        }
    }
}

#[derive(Debug, Clone)]
pub struct MergeBuffer {
    packet_limit: usize,
    byte_limit: usize,
    timeout_ms: u64,
    pub session_state: BTreeMap<u64, SessionMergeState>,
    pub skipped_gap_packets: u64,
}

impl MergeBuffer {
    pub fn new(packet_limit: usize, byte_limit: usize, timeout_ms: u64) -> Self {
        Self {
            packet_limit,
            byte_limit,
            timeout_ms,
            session_state: BTreeMap::new(),
            skipped_gap_packets: 0,
        }
    }

    fn enforce_limits(&self, state: &mut SessionMergeState) {
        if self.packet_limit == 0 && self.byte_limit == 0 {
            return;
        }
        while !state.buffered.is_empty() {
            let remove_seq = if self.packet_limit > 0 && state.buffered.len() > self.packet_limit {
                state.buffered.keys().next_back().copied()
            } else if self.byte_limit > 0 && state.buffered_bytes > self.byte_limit {
                state.buffered.keys().next_back().copied()
            } else {
                None
            };
            let Some(seq) = remove_seq else { break };
            if let Some(removed) = state.buffered.remove(&seq) {
                state.buffered_bytes = state.buffered_bytes.saturating_sub(removed.len());
            }
        }
    }

    pub fn push(&mut self, session_id: u64, seq: u64, payload: Vec<u8>) -> Vec<MergeItem> {
        let mut ready = Vec::new();
        let packet_limit = self.packet_limit;
        let byte_limit = self.byte_limit;
        let state = self
            .session_state
            .entry(session_id)
            .or_insert_with(SessionMergeState::new);

        if seq < state.next_expected_seq {
            return ready;
        }

        if seq == state.next_expected_seq {
            ready.push(MergeItem {
                session_id,
                seq,
                payload,
            });
            state.next_expected_seq += 1;
            while let Some(body) = state.buffered.remove(&state.next_expected_seq) {
                state.buffered_bytes = state.buffered_bytes.saturating_sub(body.len());
                ready.push(MergeItem {
                    session_id,
                    seq: state.next_expected_seq,
                    payload: body,
                });
                state.next_expected_seq += 1;
            }
            state.gap_started_at = None;
            return ready;
        }

        if !state.buffered.contains_key(&seq) {
            state.buffered_bytes += payload.len();
            state.buffered.insert(seq, payload);
            if state.gap_started_at.is_none() {
                state.gap_started_at = Some(0);
            }
            let helper = MergeBuffer {
                packet_limit,
                byte_limit,
                timeout_ms: self.timeout_ms,
                session_state: BTreeMap::new(),
                skipped_gap_packets: 0,
            };
            helper.enforce_limits(state);
        }

        ready
    }

    pub fn flush_timeouts(&mut self, now_ms: u64) -> Vec<MergeItem> {
        let mut released = Vec::new();
        self.skipped_gap_packets = 0;
        let session_ids = self.session_state.keys().copied().collect::<Vec<_>>();
        for session_id in session_ids {
            let Some(state) = self.session_state.get_mut(&session_id) else {
                continue;
            };
            let Some(gap_started_at) = state.gap_started_at else {
                continue;
            };
            if now_ms.saturating_sub(gap_started_at) < self.timeout_ms {
                continue;
            }
            if state.buffered.is_empty() {
                state.gap_started_at = None;
                continue;
            }
            let old_expected = state.next_expected_seq;
            let next_seq = *state.buffered.keys().next().expect("buffered not empty");
            state.next_expected_seq = next_seq;
            if next_seq > old_expected {
                self.skipped_gap_packets += next_seq - old_expected;
            }
            while let Some(body) = state.buffered.remove(&state.next_expected_seq) {
                state.buffered_bytes = state.buffered_bytes.saturating_sub(body.len());
                released.push(MergeItem {
                    session_id,
                    seq: state.next_expected_seq,
                    payload: body,
                });
                state.next_expected_seq += 1;
            }
            state.gap_started_at = None;
        }
        released
    }

    pub fn total_buffered_bytes(&self) -> usize {
        self.session_state.values().map(|state| state.buffered_bytes).sum()
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct LegState {
    pub endpoint_id: u64,
    pub weight: usize,
    pub active: bool,
}

impl LegState {
    pub fn new(endpoint_id: u64, weight: usize, active: bool) -> Self {
        Self {
            endpoint_id,
            weight,
            active,
        }
    }
}

#[derive(Debug, Clone)]
pub struct WeightedLegSelector {
    legs: Vec<LegState>,
    sequence: VecDeque<usize>,
}

impl WeightedLegSelector {
    pub fn new(legs: Vec<LegState>) -> Self {
        let mut selector = Self {
            legs,
            sequence: VecDeque::new(),
        };
        selector.refresh();
        selector
    }

    pub fn refresh(&mut self) {
        self.sequence.clear();
        for (index, leg) in self.legs.iter().enumerate() {
            for _ in 0..leg.weight {
                self.sequence.push_back(index);
            }
        }
    }

    pub fn pick_leg(&mut self) -> Option<&LegState> {
        if self.sequence.is_empty() {
            return None;
        }
        for _ in 0..self.sequence.len() {
            let index = self.sequence.pop_front()?;
            self.sequence.push_back(index);
            if self.legs.get(index).is_some_and(|leg| leg.active) {
                return self.legs.get(index);
            }
        }
        None
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn merge_buffer_releases_in_order_and_skips_gap_after_timeout() {
        let mut buffer = MergeBuffer::new(16, 4096, 1);
        assert!(buffer.push(4, 2, b"two".to_vec()).is_empty());
        let ready = buffer.push(4, 1, b"one".to_vec());
        assert_eq!(ready.len(), 2);
        assert_eq!(ready[0].seq, 1);
        assert_eq!(ready[1].seq, 2);
        assert!(buffer.push(4, 4, b"four".to_vec()).is_empty());
        buffer.session_state.get_mut(&4).unwrap().gap_started_at = Some(1);
        let skipped = buffer.flush_timeouts(11);
        assert_eq!(skipped.len(), 1);
        assert_eq!(skipped[0].seq, 4);
    }

    #[test]
    fn weighted_selector_honors_weight_and_skips_inactive() {
        let mut selector = WeightedLegSelector::new(vec![
            LegState::new(184, 2, true),
            LegState::new(185, 1, false),
            LegState::new(186, 1, true),
        ]);
        let picks = (0..6)
            .map(|_| selector.pick_leg().unwrap().endpoint_id)
            .collect::<Vec<_>>();
        assert_eq!(picks, vec![184, 184, 186, 184, 184, 186]);
    }
}
