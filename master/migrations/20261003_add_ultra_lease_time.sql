-- 2026-10-03 server-side Ultra "connected time"
-- (docs/plans/2026-10-02-line-protection-security-remediation-plan.md A.3-A.4).
--
-- Ultra clients connect to LeiGod directly, so traffic bytes are self-reported
-- (heartbeat ultra_usage -> ProvisioningService::recordUltraUsage) and a
-- modified client can suppress them. The only measure the panel observes
-- itself is lease issuance: every /api/client/ultra/lease issue covers a
-- window [issued, expires) for one client + device + forward.
-- ultra_client_leases rows are purged after a few hours by cleanup-tasks.php,
-- so this table is the durable ledger.
--
-- One row per client + device + forward + UTC day. leased_seconds is the
-- UNION of the lease windows inside that day (UltraLeaseTimeLedger):
-- covered_until (epoch seconds) is the end of the furthest window seen, and a
-- new lease only adds the part of its window past it, so overlapping renewals
-- and the up-to-four candidate leases of one request are never double
-- counted. Windows crossing UTC midnight are split across the two day rows.
-- lease_count counts issued leases (candidates included). service_id is
-- attribution for billing roll-ups; NULL when the issuing path did not know it.

CREATE TABLE IF NOT EXISTS ultra_lease_time (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NULL,
    device_hash CHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    period_day DATE NOT NULL,
    leased_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
    lease_count INT UNSIGNED NOT NULL DEFAULT 0,
    covered_until BIGINT NOT NULL DEFAULT 0,
    first_issued_at DATETIME NOT NULL,
    last_issued_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_ultra_lease_time_day (client_id, device_hash, forward_id, period_day),
    INDEX idx_ultra_lease_time_client_day (client_id, period_day),
    INDEX idx_ultra_lease_time_service_day (service_id, period_day),
    INDEX idx_ultra_lease_time_forward_day (forward_id, period_day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
