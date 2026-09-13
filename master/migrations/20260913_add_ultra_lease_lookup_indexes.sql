-- Lease lookup indexes (2026-09-13).
--
-- ultra_client_leases is an ephemeral table: a row is a one-shot nonce that
-- dies with the five minute policy TTL. It is written on every lease refresh
-- (~1.2 rows/s in production) and every read filters on client_id plus a
-- device and/or a time column, yet none of the pre-2026-09-13 indexes could
-- answer those predicates from the index:
--
--   liveDeviceHashes()   SELECT DISTINCT device_hash ... client_id + revoked_at + expires_at
--   liveLeaseCount()     SELECT COUNT(*) ... client_id + device_hash + expires_at
--   recentIssueCount()   SELECT COUNT(*) ... client_id + device_hash + issued_at
--
-- The optimizer therefore fell back to a full clustered-index scan on *every*
-- lease issue. Measured on the production panel with 364k rows / 328 MB:
--   4 x COUNT(*)        -> 0.19-0.25 s each, 364,442 rows examined
--   2 x DISTINCT device -> 0.69-1.23 s each, 248,662 rows examined
-- i.e. 1.11M rows scanned/s and 15,894 physical page reads/s, with mariadbd
-- pinned at 90.9% CPU, 12.7% iowait and an InnoDB buffer pool (128 MB) that
-- could not hold the table. Each index below makes one shape index-only:
-- EXPLAIN goes from "rows 345921, Using temporary" to "rows 96, Using index".
--
-- CREATE INDEX IF NOT EXISTS is MariaDB syntax and keeps this migration
-- re-runnable, which matters because the indexes were hot-patched onto the
-- live panel before the migration was written.
CREATE INDEX IF NOT EXISTS idx_ultra_lease_client_live
    ON ultra_client_leases (client_id, expires_at, revoked_at, device_hash);

CREATE INDEX IF NOT EXISTS idx_ultra_lease_client_device_expiry
    ON ultra_client_leases (client_id, device_hash, expires_at);

CREATE INDEX IF NOT EXISTS idx_ultra_lease_client_device_issued
    ON ultra_client_leases (client_id, device_hash, issued_at);
