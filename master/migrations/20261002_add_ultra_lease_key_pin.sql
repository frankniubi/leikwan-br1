-- 2026-10-02 sealed Ultra leases (docs/specs/2026-10-02-ultra-sealed-lease-v1.md).
--
-- A device that sends X-Leikwan-Lease-Key gets its lease material sealed to that
-- P-256 key. The panel remembers which key a device id is using so that, with
-- ULTRA_LEASE_SEALING=require, a second party holding the same agent_token and
-- device id but another key cannot pull leases while the real device is live.
-- The pin follows the device session row: a tenant kick deletes it, and a key
-- idle for ULTRA_LEASE_KEY_REPIN_SECONDS may be replaced.

ALTER TABLE client_device_sessions
    ADD COLUMN ultra_lease_key_sha256 CHAR(64) NULL AFTER ultra_runtime_attested_at,
    ADD COLUMN ultra_lease_key_seen_at DATETIME NULL AFTER ultra_lease_key_sha256;
