pub fn is_duplicate_eligible(payload: &[u8]) -> bool {
    if payload.len() < 4 {
        return false;
    }
    matches!(
        u32::from_le_bytes([payload[0], payload[1], payload[2], payload[3]]),
        1 | 2 | 3
    )
}

#[cfg(test)]
mod tests {
    use super::is_duplicate_eligible;

    #[test]
    fn only_wireguard_handshakes_are_duplicate_eligible() {
        assert!(is_duplicate_eligible(&1u32.to_le_bytes()));
        assert!(is_duplicate_eligible(&2u32.to_le_bytes()));
        assert!(is_duplicate_eligible(&3u32.to_le_bytes()));
        assert!(!is_duplicate_eligible(&4u32.to_le_bytes()));
        assert!(!is_duplicate_eligible(&[1, 0, 0]));
    }
}
