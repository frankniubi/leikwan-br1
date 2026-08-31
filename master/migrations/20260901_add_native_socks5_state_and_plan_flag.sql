-- The application-level allow_native_outbound plan flag is stored in
-- service_plans.feature_flags_json
-- and defaults to false in ProvisioningService::defaultFeatureFlags().
ALTER TABLE ultra_lines
    ADD COLUMN IF NOT EXISTS native_socks5_state VARCHAR(16) NOT NULL DEFAULT 'unknown' AFTER native_udp_state;

UPDATE ultra_lines
SET native_socks5_state = CASE
        WHEN native_socks5_state IN ('verified', 'failed') THEN native_socks5_state
        ELSE 'unknown'
    END;
