-- Runtime attestation + abuse limits for Ultra client leases (2026-09-12).
--
-- 1. Runtime attestation. The Go runtime now publishes a bounded
--    "runtime_attestation" object inside its Ultra status report. It is
--    observational reporting only: absence (older clients) is "unknown" and
--    never a failure, mismatches are reported but nothing is denied yet.
--    Every value is validated before it is written by
--    UltraClientStatusService::normalizeRuntimeAttestation(), so a NULL column
--    always means "unknown / absent / rejected", never a raw client string.
--    Column <-> JSON mapping: ultra_runtime_fingerprint <- fingerprint,
--    ultra_runtime_self_sha256 <- self_sha256, ultra_runtime_debugger <- debugger,
--    ultra_runtime_instrumented <- instrumented.
--
-- 2. Lease refusal audit. The Ultra lease abuse limits
--    (ULTRA_MAX_DEVICES_PER_CLIENT, ULTRA_MAX_LIVE_LEASES_PER_DEVICE,
--    ULTRA_LEASE_ISSUE_RATE_PER_MINUTE) default to 0 = unlimited, i.e. today's
--    behaviour. When an operator enables a cap, every refusal is recorded here
--    and surfaced in the admin Ultra operations page ("Refusals" tab).

ALTER TABLE client_device_sessions
    ADD COLUMN ultra_runtime_fingerprint VARCHAR(64) NULL AFTER ultra_status_updated_at,
    ADD COLUMN ultra_runtime_self_sha256 CHAR(64) NULL AFTER ultra_runtime_fingerprint,
    ADD COLUMN ultra_runtime_debugger TINYINT(1) NULL AFTER ultra_runtime_self_sha256,
    ADD COLUMN ultra_runtime_instrumented TINYINT(1) NULL AFTER ultra_runtime_debugger,
    ADD COLUMN ultra_runtime_attested_at DATETIME NULL AFTER ultra_runtime_instrumented;

CREATE INDEX idx_client_device_sessions_runtime_fingerprint
    ON client_device_sessions (ultra_runtime_fingerprint, last_seen_at);

CREATE TABLE IF NOT EXISTS ultra_lease_refusals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_hash CHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    error_code VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_ultra_lease_refusal_created (created_at),
    INDEX idx_ultra_lease_refusal_client (client_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
