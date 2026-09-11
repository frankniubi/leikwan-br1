-- Tencent-marked entries are dispatched last (operator instruction 2026-09-11).
--
-- The marker can live in provider (腾讯云) or only in the source title, so the
-- classification is materialized at inventory time instead of being re-derived in
-- every ordering query. UltraInventoryService::dispatchClass() owns the rule.
ALTER TABLE ultra_lines
    ADD COLUMN IF NOT EXISTS dispatch_class VARCHAR(16) NOT NULL DEFAULT 'normal' AFTER provider;

UPDATE ultra_lines
SET dispatch_class = CASE
        WHEN provider LIKE '%腾讯%' OR source_title LIKE '%腾讯%' THEN 'low'
        ELSE 'normal'
    END;

CREATE INDEX idx_ultra_lines_dispatch
    ON ultra_lines (dispatch_class, is_source_present, is_enabled);
