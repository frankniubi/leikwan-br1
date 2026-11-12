-- 2026-11-11 User-level online device limit override.
--
-- A per-user override for the max online devices, set by admin on the tenant
-- edit page. NULL = fall back to the client's own limit (plan-seeded). The
-- effective limit is resolved in ClientOnlineSessionService via
-- UserDeviceLimit::effectiveForClient. Never kick existing devices; only
-- refuse new ones. Rollback = ALTER TABLE users DROP COLUMN online_device_limit.
ALTER TABLE users
    ADD COLUMN online_device_limit INT NULL AFTER apple_id_submitted_at;
