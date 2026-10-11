ALTER TABLE ultra_lines
    ADD COLUMN IF NOT EXISTS access_class VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER config_sha256,
    ADD COLUMN IF NOT EXISTS native_udp_state VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER access_class,
    ADD COLUMN IF NOT EXISTS route_class VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER native_udp_state,
    ADD COLUMN IF NOT EXISTS pricing_class VARCHAR(16) NOT NULL DEFAULT 'unknown' AFTER route_class,
    ADD COLUMN IF NOT EXISTS pricing_evidence VARCHAR(255) NULL AFTER pricing_class,
    ADD COLUMN IF NOT EXISTS audit_at DATETIME NULL AFTER pricing_evidence,
    ADD COLUMN IF NOT EXISTS audit_hash CHAR(64) NULL AFTER audit_at;

UPDATE ultra_lines
SET access_class = CASE
        WHEN access_class IN ('native_udp', 'wireguard_only', 'wireguard', 'dynamic', 'dynamic_ingress', 'relay_unreachable') THEN access_class
        ELSE 'unknown'
    END,
    native_udp_state = CASE
        WHEN native_udp_state IN ('verified', 'failed', 'unverified') THEN native_udp_state
        ELSE 'unknown'
    END,
    route_class = CASE
        WHEN route_class IN ('bgp', 'single_telecom', 'wireguard', 'direct', 'relay', 'generic') THEN route_class
        ELSE 'unknown'
    END,
    pricing_class = CASE
        WHEN pricing_class IN ('paid', 'mixed', 'free') THEN pricing_class
        ELSE 'unknown'
    END,
    is_enabled = CASE
        WHEN access_class = 'native_udp' AND native_udp_state = 'verified' THEN is_enabled
        ELSE 0
    END;

CREATE INDEX idx_ultra_lines_quality
    ON ultra_lines (access_class, native_udp_state, pricing_class, is_source_present, is_enabled);
