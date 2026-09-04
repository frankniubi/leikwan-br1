-- Native outbound is opt-in at the plan level.  The forward marker is kept
-- separate from the ordinary target so older clients continue to deserialize
-- existing forwards unchanged.
ALTER TABLE forwards
    ADD COLUMN IF NOT EXISTS native_outbound_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER filter_profile,
    ADD COLUMN IF NOT EXISTS native_direction_key VARCHAR(64) NULL AFTER native_outbound_enabled;

-- NULL keys are allowed for ordinary forwards.  Native rows clear the key
-- when disabled/deleted, so this index enforces one active native row per
-- service and canonical direction while the mutation transaction performs a
-- friendly conflict check.
ALTER TABLE forwards
    ADD UNIQUE KEY uniq_forwards_native_direction (service_id, native_direction_key);
