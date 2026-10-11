ALTER TABLE ultra_client_leases
    ADD COLUMN candidate_key_hash CHAR(64) NULL AFTER lease_hash,
    ADD INDEX idx_ultra_lease_candidate_key (candidate_key_hash, expires_at);
