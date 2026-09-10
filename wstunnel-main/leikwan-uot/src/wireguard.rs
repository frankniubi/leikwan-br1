pub fn is_duplicate_eligible(payload: &[u8]) -> bool {
    if payload.len() < 4 {
        return false;
    }
    matches!(
        u32::from_le_bytes([payload[0], payload[1], payload[2], payload[3]]),
        1 | 2 | 3
    )
}

/// Returns true only for a bounded WireGuard transport-data datagram (message
/// type 4) small enough to be worth racing as a first-wins latency-sensitive
/// copy. `max_bytes == 0` disables the classification entirely (returns false),
/// which is the default and the load-bearing feature-OFF guard.
pub fn is_latency_sensitive_transport(payload: &[u8], max_bytes: usize) -> bool {
    const MIN_TRANSPORT_BYTES: usize = 32;

    if max_bytes == 0 || payload.len() < MIN_TRANSPORT_BYTES || payload.len() > max_bytes {
        return false;
    }

    u32::from_le_bytes([payload[0], payload[1], payload[2], payload[3]]) == 4
}

#[cfg(test)]
mod tests {
    use super::{is_duplicate_eligible, is_latency_sensitive_transport};

    #[test]
    fn only_wireguard_handshakes_are_duplicate_eligible() {
        assert!(is_duplicate_eligible(&1u32.to_le_bytes()));
        assert!(is_duplicate_eligible(&2u32.to_le_bytes()));
        assert!(is_duplicate_eligible(&3u32.to_le_bytes()));
        assert!(!is_duplicate_eligible(&4u32.to_le_bytes()));
        assert!(!is_duplicate_eligible(&[1, 0, 0]));
    }

    #[test]
    fn only_bounded_wireguard_transport_data_is_latency_sensitive() {
        let mut small_transport = vec![0; 128];
        small_transport[..4].copy_from_slice(&4u32.to_le_bytes());
        let mut large_transport = vec![0; 512];
        large_transport[..4].copy_from_slice(&4u32.to_le_bytes());
        let mut handshake = vec![0; 128];
        handshake[..4].copy_from_slice(&1u32.to_le_bytes());

        assert!(is_latency_sensitive_transport(&small_transport, 256));
        assert!(!is_latency_sensitive_transport(&small_transport, 0));
        assert!(!is_latency_sensitive_transport(&large_transport, 256));
        assert!(!is_latency_sensitive_transport(&handshake, 256));
        assert!(!is_latency_sensitive_transport(&4u32.to_le_bytes(), 256));
    }
}
