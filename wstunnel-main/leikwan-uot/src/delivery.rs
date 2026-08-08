use crate::{
    config::DeliveryMode,
    merge::{MergeBuffer, MergeItem},
};

#[derive(Debug, Clone)]
pub struct DatagramDelivery {
    ordered: Option<MergeBuffer>,
}

impl DatagramDelivery {
    pub fn new(
        delivery_mode: DeliveryMode,
        reorder_window_packets: usize,
        reorder_window_bytes: usize,
        reorder_timeout_ms: u64,
    ) -> Self {
        Self {
            ordered: matches!(delivery_mode, DeliveryMode::Ordered).then(|| {
                MergeBuffer::new(
                    reorder_window_packets,
                    reorder_window_bytes,
                    reorder_timeout_ms,
                )
            }),
        }
    }

    pub fn ingest(&mut self, session_id: u64, seq: u64, payload: Vec<u8>) -> Vec<MergeItem> {
        let now_ms = std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap_or_default()
            .as_millis() as u64;
        self.ingest_at(session_id, seq, payload, now_ms)
    }

    pub fn ingest_at(
        &mut self,
        session_id: u64,
        seq: u64,
        payload: Vec<u8>,
        arrived_at_ms: u64,
    ) -> Vec<MergeItem> {
        match &mut self.ordered {
            Some(merge) => merge.push_at(session_id, seq, payload, arrived_at_ms),
            None => vec![MergeItem {
                session_id,
                seq,
                payload,
            }],
        }
    }

    pub fn flush_timeouts(&mut self, now_ms: u64) -> Vec<MergeItem> {
        self.ordered
            .as_mut()
            .map(|merge| merge.flush_timeouts(now_ms))
            .unwrap_or_default()
    }

    pub fn buffered_bytes(&self) -> usize {
        self.ordered
            .as_ref()
            .map(MergeBuffer::total_buffered_bytes)
            .unwrap_or(0)
    }

    pub const fn is_unordered(&self) -> bool {
        self.ordered.is_none()
    }

    pub fn skipped_gap_packets(&self) -> u64 {
        self.ordered
            .as_ref()
            .map(|merge| merge.skipped_gap_packets)
            .unwrap_or(0)
    }

    pub fn remove_session(&mut self, session_id: u64) {
        if let Some(merge) = &mut self.ordered {
            merge.session_state.remove(&session_id);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::DatagramDelivery;
    use crate::config::DeliveryMode;

    #[test]
    fn unordered_delivery_emits_packets_in_arrival_order() {
        let mut delivery = DatagramDelivery::new(DeliveryMode::Unordered, 16, 4096, 25);

        let later = delivery.ingest(7, 2, b"two".to_vec());
        let earlier = delivery.ingest(7, 1, b"one".to_vec());

        assert_eq!(later[0].seq, 2);
        assert_eq!(later[0].payload, b"two");
        assert_eq!(earlier[0].seq, 1);
        assert_eq!(earlier[0].payload, b"one");
        assert_eq!(delivery.buffered_bytes(), 0);
    }
}
