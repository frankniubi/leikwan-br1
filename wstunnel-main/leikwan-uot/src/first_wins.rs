use std::collections::{HashMap, VecDeque};

#[derive(Clone, Debug)]
pub struct FirstWinsCache {
    ttl_ms: u64,
    capacity: usize,
    committed: HashMap<(u64, u64), u64>,
    order: VecDeque<(u64, u64, u64)>,
    high_water: usize,
    evictions: u64,
}

impl FirstWinsCache {
    pub fn new(ttl_ms: u64, capacity: usize) -> Self {
        Self {
            ttl_ms: ttl_ms.max(1),
            capacity: capacity.max(1),
            committed: HashMap::new(),
            order: VecDeque::new(),
            high_water: 0,
            evictions: 0,
        }
    }

    pub fn is_committed(&mut self, session_id: u64, sequence: u64, now_ms: u64) -> bool {
        self.expire(now_ms);
        self.committed.contains_key(&(session_id, sequence))
    }

    pub fn commit(&mut self, session_id: u64, sequence: u64, now_ms: u64) {
        self.expire(now_ms);
        let key = (session_id, sequence);
        self.committed.insert(key, now_ms);
        self.order.push_back((now_ms, session_id, sequence));
        while self.committed.len() > self.capacity {
            if self.remove_oldest_current() {
                self.evictions = self.evictions.saturating_add(1);
            }
        }
        self.high_water = self.high_water.max(self.committed.len());
    }

    pub fn remove_session(&mut self, session_id: u64) {
        self.committed
            .retain(|(entry_session, _), _| *entry_session != session_id);
        self.order
            .retain(|(_, entry_session, _)| *entry_session != session_id);
    }

    pub fn entries(&self) -> usize {
        self.committed.len()
    }

    pub const fn high_water(&self) -> usize {
        self.high_water
    }

    pub const fn evictions(&self) -> u64 {
        self.evictions
    }

    fn expire(&mut self, now_ms: u64) {
        loop {
            let Some((committed_at, session_id, sequence)) = self.order.front().copied() else {
                break;
            };
            if now_ms.saturating_sub(committed_at) < self.ttl_ms {
                break;
            }
            self.order.pop_front();
            if self.committed.get(&(session_id, sequence)) == Some(&committed_at) {
                self.committed.remove(&(session_id, sequence));
            }
        }
    }

    fn remove_oldest_current(&mut self) -> bool {
        while let Some((committed_at, session_id, sequence)) = self.order.pop_front() {
            let key = (session_id, sequence);
            if self.committed.get(&key) == Some(&committed_at) {
                self.committed.remove(&key);
                return true;
            }
        }
        false
    }
}

#[cfg(test)]
mod tests {
    use super::FirstWinsCache;

    #[test]
    fn commits_only_after_success_and_suppresses_later_copy() {
        let mut cache = FirstWinsCache::new(10_000, 16);

        assert!(!cache.is_committed(7, 11, 100));
        assert!(!cache.is_committed(7, 11, 101));

        cache.commit(7, 11, 102);
        assert!(cache.is_committed(7, 11, 103));
        assert_eq!(cache.entries(), 1);
        assert_eq!(cache.high_water(), 1);
    }

    #[test]
    fn cache_is_bounded_expires_and_cleans_up_sessions() {
        let mut cache = FirstWinsCache::new(10, 2);
        cache.commit(1, 1, 0);
        cache.commit(1, 2, 1);
        cache.commit(2, 1, 2);

        assert_eq!(cache.entries(), 2);
        assert_eq!(cache.evictions(), 1);
        assert!(!cache.is_committed(1, 1, 2));

        cache.remove_session(1);
        assert_eq!(cache.entries(), 1);
        assert!(!cache.is_committed(2, 1, 13));
        assert_eq!(cache.entries(), 0);
    }

    #[test]
    fn session_churn_keeps_the_expiry_queue_bounded() {
        let mut cache = FirstWinsCache::new(10_000, 2);

        for session_id in 1..=100 {
            cache.commit(session_id, 1, session_id);
            cache.remove_session(session_id);
        }

        assert_eq!(cache.entries(), 0);
        assert_eq!(cache.order.len(), 0);
    }
}
