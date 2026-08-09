#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct LegQueueBudget {
    packet_limit: usize,
    byte_limit: usize,
    queued_packets: usize,
    queued_bytes: usize,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct QueueCandidate {
    pub endpoint_id: u64,
    pub active: bool,
    pub weight: u16,
    pub queued_packets: usize,
    pub queued_bytes: usize,
    pub can_accept: bool,
}

pub fn select_least_backlogged(
    candidates: impl IntoIterator<Item = QueueCandidate>,
) -> Option<u64> {
    select_least_backlogged_after(candidates, 0)
}

pub fn select_least_backlogged_after(
    candidates: impl IntoIterator<Item = QueueCandidate>,
    next_preferred_endpoint_id: u64,
) -> Option<u64> {
    candidates
        .into_iter()
        .filter(|candidate| candidate.active && candidate.can_accept)
        .min_by_key(|candidate| {
            (
                candidate.queued_bytes / usize::from(candidate.weight.max(1)),
                candidate.queued_packets,
                candidate.endpoint_id < next_preferred_endpoint_id,
                candidate.endpoint_id,
            )
        })
        .map(|candidate| candidate.endpoint_id)
}

impl LegQueueBudget {
    pub fn new(packet_limit: usize, byte_limit: usize) -> Self {
        Self {
            packet_limit,
            byte_limit,
            queued_packets: 0,
            queued_bytes: 0,
        }
    }

    pub fn try_reserve(&mut self, payload_bytes: usize) -> bool {
        if !self.can_reserve(payload_bytes) {
            return false;
        }
        self.queued_packets += 1;
        self.queued_bytes += payload_bytes;
        true
    }

    pub fn can_reserve(&self, payload_bytes: usize) -> bool {
        self.queued_packets < self.packet_limit
            && payload_bytes <= self.byte_limit.saturating_sub(self.queued_bytes)
    }

    pub fn release(&mut self, payload_bytes: usize) {
        self.queued_packets = self.queued_packets.saturating_sub(1);
        self.queued_bytes = self.queued_bytes.saturating_sub(payload_bytes);
    }

    pub const fn queued_packets(&self) -> usize {
        self.queued_packets
    }

    pub const fn queued_bytes(&self) -> usize {
        self.queued_bytes
    }
}

#[cfg(test)]
mod tests {
    use super::{
        select_least_backlogged, select_least_backlogged_after, LegQueueBudget, QueueCandidate,
    };

    #[test]
    fn queue_admission_rejects_only_the_excess_datagram() {
        let mut queue = LegQueueBudget::new(2, 100);

        assert!(queue.try_reserve(40));
        assert!(queue.try_reserve(40));
        assert!(!queue.try_reserve(40));
        queue.release(40);
        assert!(queue.try_reserve(40));
    }

    #[test]
    fn selection_skips_full_leg_and_uses_less_backlogged_healthy_leg() {
        let selected = select_least_backlogged([
            QueueCandidate {
                endpoint_id: 1,
                active: true,
                weight: 8,
                queued_packets: 512,
                queued_bytes: 4 * 1024 * 1024,
                can_accept: false,
            },
            QueueCandidate {
                endpoint_id: 2,
                active: true,
                weight: 1,
                queued_packets: 1,
                queued_bytes: 64,
                can_accept: true,
            },
            QueueCandidate {
                endpoint_id: 3,
                active: false,
                weight: 1,
                queued_packets: 0,
                queued_bytes: 0,
                can_accept: true,
            },
        ]);

        assert_eq!(selected, Some(2));
    }

    #[test]
    fn selection_rotates_equal_empty_legs_from_the_cursor() {
        let candidates = || {
            [1, 2, 3].into_iter().map(|endpoint_id| QueueCandidate {
                endpoint_id,
                active: true,
                weight: 1,
                queued_packets: 0,
                queued_bytes: 0,
                can_accept: true,
            })
        };

        assert_eq!(select_least_backlogged_after(candidates(), 1), Some(1));
        assert_eq!(select_least_backlogged_after(candidates(), 2), Some(2));
        assert_eq!(select_least_backlogged_after(candidates(), 3), Some(3));
        assert_eq!(select_least_backlogged_after(candidates(), 4), Some(1));
    }
}
