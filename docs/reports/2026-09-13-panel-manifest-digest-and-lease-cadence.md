# Panel manifest re-hashing + client lease churn (2026-09-13)

Two follow-ups from the MariaDB saturation incident
(`docs/reports/2026-09-13-panel-mariadb-lease-fullscan-incident.md`).

## 1. GET /artifacts/versions.json re-hashed the whole artifact tree

`ArtifactController::versionsManifest()` answers with a sha256 for every
published artifact, and it recomputed each one from disk on **every request**:

    59 entries with a path, 647 MB of files
    -> ~2.7 s of SHA-256 CPU per request
    -> plus 647 MB of page-cache churn on a box that is trying to keep a
       database in memory

Measured before: `curl /artifacts/versions.json` = 2.696 s, 2.705 s (and it is
polled by every client that checks for updates).

Fix: `storage/cache/artifact-digests.json`, keyed by **path -> (size, mtime,
ctime)**. A republished artifact invalidates its own entry: an ordinary overwrite
moves mtime and ctime, and even a `cp -p` that preserves mtime moves ctime. The
cache is written once per request behind a `rename()`, and every write failure
is ignored, so a read-only or missing cache directory can only cost CPU, never
correctness. `versionsManifest()` still publishes no digest for a missing file
rather than a guess.

Measured after:

| | before | after |
|---|---|---|
| cold (first request after a publish) | 2.696 s | 2.673 s |
| warm | 2.705 s | **0.039 s** |

Correctness check on the live panel: after deploying, all **61 published digests
were recomputed with Python and compared byte-for-byte with the served manifest
- 0 mismatches**. The unit test additionally pins invalidation on a size change
and on a same-size + same-mtime rewrite (ctime), plus the missing-file case.

## 2. Every config poll minted a fresh lease batch for every forward

The grant table was taking **1.05 inserts/s** for grants that live five minutes.
The client's own log shows why: `native outbound capability updated` fires on
every config poll - **one every 16 seconds** - and each callback ran
`ScheduleNativeLeaseRefresh()`.

The guard inside `RefreshNativeLeaseIfNeededAsync` was:

    var expiresAt = proxyService.ActiveNativeLeaseExpiresAt;
    if (dataPlaneRunning && !NativeLeaseRenewalLoop.IsRefreshDue(expiresAt, now))
        return;

`WindowsProxyConnectionService.ActiveNativeLeaseExpiresAt` is only populated
when the data plane lives **inside** the GUI process. With the direct runtime
(the installed setup, where each forward is a child `ultra-client.exe`) it is
always `null`, and `IsRefreshDue(null, now)` is `true` by definition - so the
guard never fired and every poll minted a fresh **four-candidate batch** per
forward. The panel saw the result as bursts of 3-4 rows in the same second, one
burst per forward per ~16 s:

    issued_at            ttl   gap_s
    07:49:14             297   0
    07:49:14             297   0        <- one request, four candidates
    07:49:14             297   0
    07:49:28             283  14        <- next poll
    07:49:42             269  14

Two independent defects, both fixed:

* **Unknown expiry is not "due now".** The client now remembers the expiry of
  the batch it minted itself (`_nativeLeaseBatchExpiresAt`) and prefers the
  in-process value only when the service actually owns the lease. It is cleared
  whenever the renewal loop is cancelled, so a restart inside the old window
  still fetches a fresh batch. With a 300 s grant and the loop's 90 s lead time
  the refresh cadence becomes ~210 s instead of ~16 s.
* **Native-outbound forwards have no `UltraAccess` policy by design** (the
  entitlement is the `NativeOutbound` capability and their grants come from the
  native path), but `NeedsUltraPolicyRefresh()` read that absent policy as
  "expired" and forced a **full config pull on every poll**. It now skips them.

Tests: Core **502/502**, App **106/106**.

## 3. Windows client 0.1.20.42

| | |
|---|---|
| version | 0.1.20.42 |
| sha256 | `59cd7649b232c944a529a28a07d9d0825dbda989e050b82441249ebf19fbd55f` |
| size | 69,546,973 B |
| runtime pin | unchanged, `ecbee8f7e315e6fb` (embedded `ultra-client.exe` verified byte-identical) |

Verified on the live panel: only the `windows_client_app` entry changed (the
other 62 manifest entries compare equal), **both entrances** serve
`0.1.20.42 / 59cd7649b232 / 69546973`, and the **bytes served over HTTPS hash to
the published value** (so a client that downloads it will verify cleanly).

## Expected effect

The grant table settles at the batch rate instead of the poll rate. The panel
side is already live (the digest cache); the lease reduction lands as clients
update to 0.1.20.42 - the same rollout that already picked up 0.1.20.41 earlier
today.

## Still open

* `ReconcileForwardAsync` and `ResolveUltraLeasesCoreAsync` still log
  "Ultra lease skipped for forward N; policy missing" for native forwards (2967
  times on 2026-09-13). It is harmless - the reconcile path returns before it
  can act on native forwards - but the line is noise and reads like a fault.
* Grant write rate is a product decision as much as a bug: a five minute TTL
  still does not need a four-candidate batch every two minutes per forward.
