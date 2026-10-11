-- Carrier metadata for the Ultra line catalog.
--
-- The 2026-09-11 linelist sweep on the source host (10.10.10.171) exposes the full
-- pool including single-carrier lines (电信/联通/移动). The panel had nowhere to put
-- that: UltraInventoryService::normalizeInventoryLine() is a whitelist, so an
-- unknown field was dropped at the API boundary and never reached MySQL.
ALTER TABLE ultra_lines
    ADD COLUMN IF NOT EXISTS in_line_type INT UNSIGNED NULL AFTER line_type;

ALTER TABLE ultra_lines
    ADD COLUMN IF NOT EXISTS isp VARCHAR(32) NOT NULL DEFAULT 'unknown' AFTER in_line_type;

ALTER TABLE ultra_lines
    ADD COLUMN IF NOT EXISTS provider VARCHAR(64) NULL AFTER isp;

-- route_class gains the two single-carrier classes that were missing
-- (single_telecom already existed).
UPDATE ultra_lines
SET route_class = CASE
        WHEN route_class IN ('bgp', 'single_telecom', 'single_unicom', 'single_mobile', 'wireguard', 'direct', 'relay', 'generic') THEN route_class
        ELSE 'unknown'
    END;

UPDATE ultra_lines
SET isp = CASE
        WHEN isp IN ('telecom', 'unicom', 'mobile', 'generic') THEN isp
        ELSE 'unknown'
    END;

CREATE INDEX idx_ultra_lines_carrier
    ON ultra_lines (isp, route_class, is_source_present, is_enabled);
