ALTER TABLE forward_combinations
    ADD COLUMN IF NOT EXISTS external_uot_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER ultra_fallback_policy,
    ADD COLUMN IF NOT EXISTS external_uot_sidecar_target VARCHAR(255) NULL AFTER external_uot_enabled,
    ADD COLUMN IF NOT EXISTS external_uot_token VARCHAR(8192) NULL AFTER external_uot_sidecar_target,
    ADD COLUMN IF NOT EXISTS external_uot_queue_packets INT NOT NULL DEFAULT 256 AFTER external_uot_token,
    ADD COLUMN IF NOT EXISTS external_uot_keepalive_seconds INT NOT NULL DEFAULT 10 AFTER external_uot_queue_packets,
    ADD COLUMN IF NOT EXISTS external_uot_reconnect_grace_seconds INT NOT NULL DEFAULT 30 AFTER external_uot_keepalive_seconds,
    ADD COLUMN IF NOT EXISTS external_uot_frame_payload_max INT NOT NULL DEFAULT 65507 AFTER external_uot_reconnect_grace_seconds;
