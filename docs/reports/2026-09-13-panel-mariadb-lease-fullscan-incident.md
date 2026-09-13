# Panel MariaDB saturation — expired Ultra grant full scans (2026-09-13)

## Symptom

The panel host felt slow; MariaDB was the top consumer:

    PID USER   %CPU  TIME+     COMMAND
    796 mysql  90.9  32:08.86  mariadbd
    %Cpu(s): 19.0 us, 3.2 sy, 63.5 id, 12.7 wa

Server: 6 vCPU / 15 GB RAM, MariaDB 11.8.6, `v3_leikwanhost_c` (485 MB total).

## Root cause

`ultra_client_leases` held **364,300 rows of which 198 were live** (0.05%).
The table is an ephemeral nonce store — a row dies with the five minute policy
TTL — but three separate defects let it grow into the hottest object on the box:

1. **No retention ever applied.** `cli/cleanup-tasks.php` inherited
   `--retention-days 7` for leases, so it only reclaimed rows whose
   `created_at` was older than seven days. The table was first populated on
   2026-09-09, so on 2026-09-13 the predicate matched **0 rows** and nothing had
   ever been purged:

       mysql> SELECT COUNT(*) FROM ultra_client_leases
              WHERE revoked_at IS NULL AND expires_at < UTC_TIMESTAMP()
                AND created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY;
       0
       journal: would reclaim 0 expired unrevoked Ultra lease rows (>7d)

   At the observed insert rate (1.22 rows/s, 105k/day) a 7 day window settles at
   ~740k rows / 670 MB — it would have kept getting worse.

2. **No index could answer the lease lookups.** Every read filters on
   `client_id` plus a device and/or a time column, but the pre-existing
   indexes were `(client_id, device_hash, forward_id, nonce_hash)`,
   `(forward_id, expires_at)`, `(policy_generation, revoked_at)` and
   `(candidate_key_hash, expires_at)`. The optimizer fell back to a full
   clustered-index scan on **every lease issue**:

       EXPLAIN SELECT DISTINCT device_hash ... :
         range uniq_ultra_lease_nonce rows=345921 Using temporary

   Slow query log, 30 s sample (47 entries, 67.9 s of query time):

       SELECT COUNT(*) AS total FROM ultra_client_leases
         0.19-0.25 s, 364,442-364,459 rows examined   (x ~4/s)
       SELECT DISTINCT device_hash FROM ultra_client_leases
         0.69-1.23 s, 248,662 rows examined           (x ~2/s)

3. **The buffer pool was 128 MB** on a 15 GB box, against 135 MB of table data
   plus 193 MB of indexes. Measured `Innodb_buffer_pool_pages_free = 18/8112`
   — the pool was permanently full, so every one of those scans read from disk.

Measured cost at the time:

    Handler_read_rnd_next          +5,570,000 / 5 s   = 1,114,960 rows/s
    Innodb_buffer_pool_reads        +158,965 / 10 s   =  15,896 pages/s (~250 MB/s)

i.e. roughly a million row visits and sixteen thousand disk page reads per
second to keep track of **198 live grants**.

## Fix

Hot patch (applied live, 07:28-07:31 UTC):

1. Covering indexes:

       ALTER TABLE ultra_client_leases
         ADD INDEX idx_ultra_lease_client_live (client_id, expires_at, revoked_at, device_hash),
         ADD INDEX idx_ultra_lease_client_device_expiry (client_id, device_hash, expires_at),
         ADD INDEX idx_ultra_lease_client_device_issued (client_id, device_hash, issued_at),
         ALGORITHM=INPLACE, LOCK=NONE;       -- 16.3 s

   Plans after:

       liveDeviceHashes  -> idx_ultra_lease_client_live  rows 345921 -> 96   Using index
       liveLeaseCount    -> ..._client_device_expiry     Using index (no PK lookups)

2. Purged dead rows in bounded batches (`revoked_at IS NULL AND expires_at <
   now - 2 hours`), 359,775 rows deleted: **364,570 -> 4,831 rows**.

3. `ANALYZE TABLE` (the old statistics claimed ~346k rows matched `client_id = 3`).

4. `innodb_buffer_pool_size` 128 MB -> 4 GB and `innodb_io_capacity`
   200 -> 2000, persisted in `/etc/mysql/mariadb.conf.d/99-leikwan-limits.cnf`.
   The pool size is not dynamically resizable here, so mariadb was restarted
   (07:30:47, clean shutdown, ~2 s).

Permanent fix (repo + panel):

* `master/migrations/20260913_add_ultra_lease_lookup_indexes.sql` (idempotent
  `CREATE INDEX IF NOT EXISTS`) and the same indexes declared in
  `20260825_add_ultra_policy_leases.sql` so fresh installs never have a
  full-scan window.
* `cli/cleanup-tasks.php`: Ultra grant retention is now hours, not days
  (`--ultra-lease-retention-hours`, default 6); the legacy
  `--ultra-lease-retention-days` is still honoured as days.
* `deploy/systemd/leikwan-cleanup.service` passes `--ultra-lease-retention-hours 6`.

## Verification

    before -> after
    Handler_read_rnd_next     1,114,960 rows/s  ->  509 rows/s
    Innodb_buffer_pool_reads     15,896 pages/s ->  22 pages/s
    mariadbd CPU                       90.9 %   ->  < 1 %  (not in top 8)
    iowait                              12.7 %  ->  0.0 %
    slow queries (>0.1 s, 30 s sample)     47   ->  0  (12 s sample)
    panel home TTFB                    (degraded) -> 0.040 s

## Unrelated incident found while verifying: the WHMCS socket bridge

`buy.leikwanhost.com/frankniubi/` was serving
"Critical Error — Could not connect to the 'buy_leikwanhost' database."
**This was not caused by the database work.** Timeline:

    06:40:13  box rebooted
    06:55:04  systemd-tmpfiles-clean.service ran (daily 10 day /tmp age-out)
    07:28-31  this incident's index/purge/buffer-pool work
    07:30:47  mariadb restarted (socket recreated at the same path)

BT-Panel's PHP 8.1/8.2/8.3 has no `mysqli.default_socket`, so PHP resolves the
mysqli host `localhost` to its built-in default `/tmp/mysql.sock`, while
Debian's MariaDB listens on `/run/mysqld/mysqld.sock`. The only thing making
`localhost` work was an unmanaged symlink in `/tmp`, and the daily tmpfiles
cleanup aged it out at 06:55 — 35 minutes before the mariadb restart, which does
not touch `/tmp` at all:

    mysqli_connect('localhost', ...) -> mysqli_sql_exception: No such file or directory

Fixes applied:

* `/etc/tmpfiles.d/leikwan-mysql-sock.conf` declares
  `L+ /tmp/mysql.sock - - - - /run/mysqld/mysqld.sock`, making the path managed
  so the daily cleanup leaves it alone and a reboot recreates it.
* `buy.leikwanhost.com/configuration.php` `$db_host` `localhost` -> `127.0.0.1`
  (TCP), so the billing site no longer depends on a file in `/tmp`.
  Verified against the DB: 345 clients, 328 tables; `/frankniubi/` and
  `/cart.php` now 302 to their normal login/store redirects.

## Follow-ups

* `GET /artifacts/versions.json` re-hashes every published artifact on every
  request — 59 entries, **647 MB** of SHA-256, 2.7 s of CPU per request, and it
  evicts the page cache. Not fixed yet; it wants a stat-keyed digest cache.
* The lease write rate is 1.22 rows/s for a five minute TTL (~35x more refresh
  traffic than the TTL requires) on four forwards. Worth a look, but it is a
  client cadence question, not a database one.
