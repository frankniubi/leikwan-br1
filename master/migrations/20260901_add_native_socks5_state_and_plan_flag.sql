-- The application-level allow_native_outbound plan flag is stored in
-- service_plans.feature_flags_json
-- and defaults to false in ProvisioningService::defaultFeatureFlags().
ALTER TABLE ultra_lines
    ADD COLUMN IF NOT EXISTS native_socks5_state VARCHAR(16) NOT NULL DEFAULT 'unknown' AFTER native_udp_state;

-- Direction is an administrator-maintained catalog marker. It is kept
-- separate from native_socks5_state because a direction label is not proof
-- that a SOCKS5 endpoint has passed the TCP/UDP probe.
ALTER TABLE ultra_lines
    ADD COLUMN IF NOT EXISTS direction_class VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER native_socks5_state;

UPDATE ultra_lines
SET native_socks5_state = CASE
        WHEN native_socks5_state IN ('verified', 'failed') THEN native_socks5_state
        ELSE 'unknown'
    END;

UPDATE ultra_lines
SET direction_class = CASE
        WHEN direction_class IN ('intl_to_cn', 'cn_to_intl', 'domestic') THEN direction_class
        ELSE 'unknown'
    END;
