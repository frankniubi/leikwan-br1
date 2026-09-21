# Ultra: a target change must re-provision or be refused; enabled-without-lease is a health failure

Follow-up to `2026-09-21-awaiting-policy-wedge.md` and item 5 of
`2026-09-21-SESSION-FINAL-HANDOFF.md`. Panel-only fix; no client-agent change was
needed.

## 1. Root cause (panel)

Ultra carries traffic under a short-lived **lease**, and the lease scope digest
covers the forward's target, transport and port:

- `master/src/Support/LeigodTokenBroker.php:492-512` — `policyGeneration()` folds
  `hash('sha256', $request['target'])` (:497) plus transport and quota.
- `master/src/Support/UltraLeaseService.php:1066` — `loadForwardPolicy()` builds the
  same generation from the forward row.
- `master/src/Support/UltraLeaseService.php:146-147` and `:189-190` — `issueLease()`
  refuses any request whose `policy_generation` or `target_digest` no longer matches,
  and `:220-224` re-checks the sealed ticket's generation.
- `master/src/Controllers/ClientAgentController.php:601-606` — when every candidate is
  refused the endpoint answers HTTP 409 with **no leases**; the agent's only local
  vocabulary for that is `awaiting_policy`.

A target change therefore invalidates every lease and every `ultra_access` grant the
client holds. But the update path never re-provisions the lease plane:

- `master/src/Support/ProvisioningService.php:5163-5226` (`updateForwardNonStructural`)
  writes `target_host/target_port/transport_mode` and bumps `api_revision`
  (:5192-5211 / now :5224-5243), then calls only `revokeForwardEndpoints()` (:7262)
  and `reconcileForwardEndpoints()` (:8720).
- That reconciler is a deliberate **no-op for Ultra**: `:8740` sets
  `desiredCount = 0` and `:8776-8793` zeroes `endpoint_count`. The endpoint plane is
  not the Ultra plane.

So the row stayed `enabled = 1` while the forward had no honourable lease and no
listener — and the panel still said "enabled":

- `master/src/Controllers/TenantController.php:1821-1824` — the tenant Status badge is
  `forward.enabled` alone (`运行`), and `:1844-1847` prints a muted "No endpoint
  assigned yet" for an Ultra forward, which has no endpoints by design.
- `master/src/Controllers/AdminController.php:3209-3210, 3221-3231` — the admin Ultra
  page derives "Online" from heartbeat recency only
  (`UltraClientStatusService::sessionState()`, :822-826) and never rendered the
  ingested `error_code` that `UltraClientStatusService.php:602` had already collected.

## 2. Fix

**Server, re-provision-or-refuse** (`ProvisioningService.php`):

- `ultraLeaseReprovisionRequired()` — true only for an **enabled** Ultra forward whose
  `target_host`, `target_port` or `transport_mode` actually changed (exactly the
  digest inputs; `listen_port` is excluded because it is a runtime re-apply concern
  and does not rotate the generation).
- `reprovisionUltraLeasePlane()` — runs inside the existing transaction, before the
  UPDATE:
  1. `ultraLeaseReprovisionBlocker()` reuses the grant path's own predicate
     (`ultraLineMetadataForAssignedForwards()`, the same query the broker uses: enabled
     line, source present, verified carrier, matching destination zone, unexpired
     material) plus `UltraMaterialService::lineSnapshot()`. If a lease could not be
     issued for the new scope it throws `ultra_reprovision_unavailable` (409) with
     `reason` = `service_not_active` | `no_leaseable_line` | `lease_material_unavailable`.
     The transaction rolls back, so the operator keeps the previous working target.
  2. `UltraLeaseService::revokeForwardLeases()` (new, `UltraLeaseService.php`) revokes
     every non-revoked lease for the forward, so a lease minted for the superseded
     scope can never be honoured again. The panel cannot push a lease (the agent
     pulls), so this plus the fresh grant on the next poll *is* the re-issue.

**Server, health** (`UltraClientStatusService.php`):

- `runtimeHealthVerdict()` (pure, testable): `error_code` present -> unhealthy with that
  code (so `awaiting_policy` is a failure, not a healthy enabled forward); runtime
  `degraded|error|stopped` -> unhealthy; **no unexpired unrevoked lease -> unhealthy
  (`no_live_lease`)**; otherwise healthy.
- `forwardsWithLiveLease()` + `runtimeHealthForForwards()` — panel-side lookups over
  `ultra_client_leases` and `client_device_sessions.ultra_status_json`.
  `sessionsForAdmin()` now carries `runtime_state`, `has_live_lease`, `unhealthy`,
  `health_reason`.

**UI**: tenant forward list renders `已启用但未运行` + the reason for an enabled Ultra
forward that is unhealthy and no longer claims an endpoint; the admin Ultra sessions
table gained a Health column and an explanatory note. The new English badge label
`serving` was added to `master/public/i18n/zh-Hans.json` and `zh-TW.json` — the i18n
coverage test scans controller source for untranslated HTML text and both locales must
carry identical key sets.

**Files changed**

| file | change |
|---|---|
| `master/src/Support/ProvisioningService.php` | guard + `reprovisionUltraLeasePlane()` + `ultraLeaseReprovisionBlocker()` + `ultraLeaseReprovisionRequired()` |
| `master/src/Support/UltraLeaseService.php` | `revokeForwardLeases()` |
| `master/src/Support/UltraClientStatusService.php` | `runtimeHealthVerdict()`, `forwardsWithLiveLease()`, `runtimeHealthForForwards()`, session/health fields in `sessionsForAdmin()` |
| `master/src/Controllers/AdminController.php` | Health column + error code surfaced in the Ultra sessions view |
| `master/src/Controllers/TenantController.php` | enabled-but-not-running state in the forward list |
| `master/public/i18n/zh-Hans.json`, `zh-TW.json` | `serving` label |
| `master/tests/ultra_target_change_reprovision_test.php` | new |
| `master/tests/ultra_enabled_without_lease_health_test.php` | new |

No client-agent (.NET or Python) change was needed: the agent already refuses to
hot-apply a changed target (by design) and defers until a fresh lease exists, so
making the panel always supply one — or refusing the edit — closes the hole.

## 3. Tests

`master/tests/ultra_target_change_reprovision_test.php` — refusal of an unleaseable
target change with the row untouched; the guard is Ultra+enabled scoped; the lease
plane refuses the superseded target/generation and issues the new scope;
`revokeForwardLeases()` semantics; source sentinel that the re-provision hook remains
wired into `updateForwardNonStructural`.

`master/tests/ultra_enabled_without_lease_health_test.php` — `awaiting_policy` and
`no_live_lease` are health failures, healthy stays healthy, the end-to-end lookup
over a fake DB, and sentinels for both UI render sites.

```powershell
cd master; php tests\ultra_target_change_reprovision_test.php
cd master; php tests\ultra_enabled_without_lease_health_test.php
```

Both print `ok`. Full panel suite: 384 pass, 0 real failures (26 apparent failures in
a job-based runner were the harness treating PHP's `date.timezone` startup warning on
stderr as a failure; re-run with `php -d date.timezone=UTC` they pass).

## 4. Live read-back (read-only, verified against the production panel)

```sql
SELECT id,service_id,line_client_id,forward_combination_id,enabled,backend_mode,
       target_host,target_port,transport_mode,listen_port,api_revision,updated_at
  FROM forwards WHERE id IN (158,159,170,171,180,201,202) ORDER BY id;
SELECT id,forward_id,client_id,policy_generation,issued_at,expires_at,consumed_at,revoked_at
  FROM ultra_client_leases WHERE forward_id IN (201,202) ORDER BY id DESC LIMIT 12;
SELECT forward_id,LEFT(device_hash,12) AS dev,error_code,COUNT(*) AS n,
       MIN(created_at) AS first_at,MAX(created_at) AS last_at
  FROM ultra_lease_refusals GROUP BY forward_id,dev,error_code ORDER BY last_at DESC;
SELECT forward_id,COUNT(*) AS live FROM ultra_client_leases
 WHERE revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() GROUP BY forward_id;
```

Result on 2026-09-21 07:44 UTC: 201/202 enabled, target reverted to
`70.36.96.183:62101/62102`, `api_revision=5` (target-change count), and leases were
being issued normally again — consistent with "reverting the target restored it".

**Two live panel-side lease refusals that produce the very same silent state** were
found in `ultra_lease_refusals`, both of which leave an enabled forward with no
lease:

| error_code | device | window (UTC) | count for 201/202 |
|---|---|---|---|
| `lease_device_live_limit` | `b4bca4c4e2af` = macos-client | 2026-09-20 17:21 -> 2026-09-21 04:19 (12:19 PRC) | ~4,070 |
| `lease_runtime_unattested` | `265c978639dd` = legacy-8574ea | 2026-09-20 19:16 -> 2026-09-21 06:39 (14:39 PRC) | 66 |

`lease_runtime_unattested` fired for 201/202 at 04:35:21 and 04:46:09 UTC
(12:35 / 12:46 PRC) — bracketing the 12:48:01 PRC wedge log in the incident note.
Both codes come from `UltraLeaseService::abuseLimitRefusal()` (:502) and
`attestationRefusal()` (:348), which run *before* candidate resolution, so the agent
gets 409 + no lease. The health fix above surfaces both (`no_live_lease`), which the
old UI did not.

## 5. Live verification the operator still needs to run (one mutation, with rollback)

Not executed here — it needs a deployed panel and the operator's window.

1. Baseline (read-only): the four queries above, plus
   `SELECT id,enabled,target_host,target_port,transport_mode,api_revision FROM forwards WHERE id IN (201,202)`.
2. Mutate **forward 201 only**, service 30 / client 3, via the tenant edit or the API:
   `target_host='103.246.219.39'` with the same `target_port`/`listen_port`/`transport_mode`.
3. Expect one of exactly two outcomes — never "enabled with no lease":
   - HTTP 409 `ultra_reprovision_unavailable` (with a `reason`), row unchanged; **or**
   - HTTP 200 and `SELECT ... FROM ultra_client_leases WHERE forward_id=201 AND revoked_at IS NULL`
     empty, then a new generation within one poll (~30 s), and the tenant/admin page
     reporting health rather than `运行`.
4. Rollback: set `target_host` back to `70.36.96.183` on 201 (same path). Do not touch
   158/159/170/171/180.

## 6. Not verified

- The mutation in section 5 was not run: this work never deployed to the panel, and
  the live read-back was read-only by design.
- The fix's refusal path is proven by unit test only; no end-to-end panel run of a
  refused edit exists yet.
- Whether the 201/202 wedge of 12:48 PRC was triggered by the target change alone or
  by the concurrent refusals in section 4 is **not** distinguished by the data I could
  read. Both are real and both are now surfaced; only the target-change path is fixed
  here. The abuse-limit and attestation-enforcement settings that produced those two
  refusals were **not** changed and should be reviewed separately
  (`ULTRA_MAX_LIVE_LEASES_PER_DEVICE`, `ULTRA_ATTESTATION_ENFORCEMENT`).
