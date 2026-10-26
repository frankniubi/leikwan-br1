-- 2026-10-21 External UoT sidecar enforcement
-- (docs/plans/2026-10-21-external-uot-sidecar-enforcement.md, C7).
--
-- external_uot_hmac_secret is the per-combination HMAC key for per-forward
-- sidecar tokens; the panel fills it lazily the first time the combination is
-- rendered or issued. NULL keeps the legacy static token.
--
-- external_uot_usage_counters holds the last cumulative UDP byte counters each
-- sidecar process (source + instance) reported per forward, so the next report
-- can be turned into a delta. external_uot_usage_daily accumulates those deltas
-- per forward and UTC day; the hourly reconcile CLI bills whatever the sidecar
-- measured beyond what the client reported.
--
-- No foreign keys on purpose (see 20261012_add_client_hosted_nodes.sql): a
-- failing FK would fail the auto-migration and with it every request.
ALTER TABLE forward_combinations ADD COLUMN external_uot_hmac_secret VARCHAR(128) NULL;

CREATE TABLE IF NOT EXISTS external_uot_usage_counters (
    source VARCHAR(191) NOT NULL,
    instance_id CHAR(32) NOT NULL,
    forward_id BIGINT NOT NULL,
    to_target_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    from_target_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (source, instance_id, forward_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS external_uot_usage_daily (
    forward_id BIGINT NOT NULL,
    usage_date DATE NOT NULL,
    service_id BIGINT NULL,
    sidecar_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    client_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    billed_extra_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    reconciled_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (forward_id, usage_date),
    INDEX idx_external_uot_usage_daily_pending (reconciled_at, usage_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
