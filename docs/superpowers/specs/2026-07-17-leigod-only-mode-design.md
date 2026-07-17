# LeiGod-Only Forward Mode, Bond Speed Plus, and Legacy Runtime Retirement — Design Spec

**Date:** 2026-07-17

**Status:** Revised written specification pending user review

**Baseline:** `master`

**Supersedes for active product behavior:** the `current_mode` (classic sidecar/CNIX) and `cf_mode` (163PP/Cloudflare) forward-mode paths, plus native-MPTCP-first LeiGod publication

---

## 1. Goal

Simplify the product so every newly created, edited, published, and delivered Forward combination uses one backend and one TCP aggregation family:

```text
backend_mode = leigod_mode
client_mode = bond
runtime_preference = single
bond_ingress_mode = plain_tcp_mux
```

Here `runtime_preference = single` means “run the requested bond family directly”; it does **not** mean one bond leg. A healthy Forward may use multiple slave legs in one application-layer bond session.

The two legacy backend modes become permanently deprecated:

- `current_mode`: the former classic sidecar/CNIX mode.
- `cf_mode`: the former 163PP/Cloudflare mode.

Deprecation is operational, not cosmetic. The system must:

1. Hide both deprecated modes from ordinary admin, tenant, and client surfaces.
2. Reject every guarded operation defined in section 3.2 for a deprecated-valued combination or Forward.
3. Preserve existing database rows and original mode values for audit and rollback analysis.
4. Disable existing Forwards that use either deprecated backend mode.
5. Remove runtime resources created by those Forwards from every affected client, slave, and sidecar host.
6. Persist cleanup intent so an offline host cleans itself when it reconnects.
7. Preserve every resource still required by an active `leigod_mode` Forward.
8. Preserve and harden multi-slave TCP aggregation under LeiGod using the standalone `leikwan-bond` runtime rather than requiring native MPTCP.

The design removes legacy **product modes and active publication paths**. It does not remove the sidecar subsystem: LeiGod still requires sidecar points, sidecar agents, a sidecar bond server, slave-to-sidecar static WireGuard uplinks, and LeiGod `lguot`/`gost` carriers.

---

## 2. Decisions and non-goals

### 2.1 Locked decisions

- `leigod_mode` is the only supported backend mode.
- Historical legacy identity, relationships, original mode values, and audit evidence remain queryable; only operational lifecycle fields defined by retirement may change.
- Legacy backend rows are not automatically converted to LeiGod.
- Legacy Forwards are disabled and retired, not deleted.
- Cleanup is scoped by exact Forward ownership; it is never a fleet-wide WireGuard or systemd purge.
- Cleanup is at-least-once, authenticated, idempotent, and durable for offline agents.
- Normal operation does not wait for every offline host to acknowledge cleanup.
- Shared LeiGod infrastructure survives whenever any active LeiGod desired state still references it.
- LeiGod TCP delivery uses the standalone application-layer `leikwan-bond` implementation across distinct slave legs.
- LeiGod publication normalizes to `client_mode = bond`, `runtime_preference = single`, and `bond_ingress_mode = plain_tcp_mux`.
- Native MPTCP is not required, selected first, or used as a silent fallback for Speed Plus.
- The separate bond module embedded in `leikwan-mptcp-relay` is not the implementation target because its non-direct legs still create native MPTCP sockets and may silently fall back between runtime families.
- One logical TCP leg is keyed by `(forward_id, node_id, endpoint_id)`. Array position, local port, process ID, and current IP address are attributes, not identity.
- Speed Plus state is derived from fresh bond-native connection and byte-delivery evidence. Configured endpoint count and process liveness alone never prove aggregation.
- If multi-leg aggregation is unavailable or unproductive, each existing application TCP stream remains in the standalone bond session and falls back to the best eligible slave leg. This is single-leg degradation, not a runtime-family or backend fallback.
- TCP continues with one healthy leg when possible. Losing or recovering one leg does not rebuild unrelated leg processes or unrelated Forward runtime.
- UDP may continue through the existing LeiGod compatibility path, but it is never described, displayed, or accepted as bandwidth aggregation.
- The first formal Speed Plus support target is the Linux client agent. Other platforms must pass their own data-path, recovery, telemetry, and throughput gates before showing Speed Plus as supported.

### 2.2 Why legacy rows are not converted

A valid LeiGod combination requires data old backend modes may not possess, including a positive `leigod_line_id`, a valid sidecar point, and usable LeiGod carrier credentials. Inventing those values would create apparently migrated but invalid routes. Operators must create a deliberate replacement LeiGod combination instead.

This differs from normalizing an already-valid `leigod_mode` combination from `mptcp`, `single`, or another client family to `bond`: its backend identity and LeiGod fields already exist, so it may be normalized only after a successful bond-publication preflight.

### 2.3 Non-goals

This change does not:

- Delete historical combinations, Forwards, CF location groups, or audit records.
- Remove sidecar-point administration or the sidecar agent.
- Remove WireGuard used by active LeiGod static sidecar uplinks.
- Rename a deprecated mode to make it appear supported.
- Automatically move customers to guessed LeiGod lines or sidecar points.
- Block normal LeiGod publication while an unrelated host remains offline.
- Guarantee cleanup of unknown resources whose ownership cannot be proven.
- Promise linear `N × single-leg` throughput.
- Claim that multiple configured or connected legs necessarily carried useful bytes.
- Treat native MPTCP as a Speed Plus prerequisite.
- Treat UDP duplication, racing, or reordering as bandwidth aggregation.
- Deploy to production as part of design approval. Deployment requires a separately approved implementation and rollout.

---

## 3. Product behavior

### 3.1 Forward combinations

The create/edit form no longer exposes backend-mode, client-mode, runtime-preference, or bond-ingress selectors. It behaves as a LeiGod form and shows only fields relevant to LeiGod delivery. The server assigns the locked values when fields are absent. One distinct slave node is ordinary single-leg LeiGod; only two to eight scheduler-eligible distinct nodes may display or market Speed Plus.

An explicit request containing `current_mode` or `cf_mode` fails with a stable API error:

```text
deprecated_forward_combination_backend_mode
```

An explicit conflicting client family, runtime preference, or ingress mode fails with a stable normalization error rather than silently accepting a different product:

```text
unsupported_leigod_runtime_contract
```

Unknown values remain invalid input. A legacy combination cannot be edited through the ordinary save path even if the submitted payload omits `backend_mode`; otherwise an edit could accidentally reactivate it as LeiGod. Operators create a new LeiGod combination instead.

Ordinary combination lists return only `leigod_mode`. Historical legacy combinations appear only in an operations-only retirement view clearly marked deprecated. That view is read-only and cannot publish, clone, edit, or reactivate them.

### 3.2 Forwards and routes

The exhaustive guarded operation set is: create; save/update/edit; select; copy/clone; publish/republish/realize/reconcile/reactivate; endpoint reconciliation; and client, slave, or sidecar desired-state construction. Every server path in this set rejects a deprecated-valued combination or Forward. Frontend hiding is not a security or correctness boundary.

A Forward is legacy when either its own persisted `backend_mode` or the persisted mode of its referenced combination is `current_mode` or `cf_mode`. A disagreement between the two persisted values fails closed, records both values in audit/ownership evidence, and follows the same retirement path. Legacy Forwards are:

- Set to `enabled = 0`.
- Excluded from active route and tenant listings.
- Excluded from client configurations and normal slave/sidecar desired state.
- Rejected by every guarded operation above.
- Retained with immutable original mode, identity, relationships, and audit evidence. Retirement may change only `enabled`, endpoint lifecycle, and retirement/audit metadata.

A deprecated combination is not eligible when creating a route. Existing routes pointing to it remain historical and non-publishable.

An active `leigod_mode` Forward is publishable only when its combination passes bond preflight: valid sidecar and LeiGod fields, one to eight usable selected endpoints on distinct slave `node_id` values, valid per-endpoint ingress authentication, a valid TCP bond session token, and a resolvable sidecar bond target. A one-node configuration runs as ordinary LeiGod TCP with `single_leg_only`; two to eight nodes are Speed Plus eligible. Zero, duplicate-node, or oversized sets fail with `invalid_speed_plus_leg_set`. A failure is explicit and does not fall back to MPTCP, CF, WSS, or classic sidecar behavior.

### 3.3 Defaults and migration compatibility

Database and application defaults become:

```text
backend_mode = leigod_mode
client_mode = bond
runtime_preference = single
bond_ingress_mode = plain_tcp_mux
```

Defaults do not rewrite historical legacy backend rows. Migration code identifies them by persisted value rather than treating a missing value as `current_mode`.

A small compatibility vocabulary remains in control-plane and agent code only to:

1. Detect legacy rows.
2. Return explicit deprecation errors.
3. Recognize and remove legacy runtime resources.
4. Read old applied-state manifests during retirement.

The goal is zero active legacy publication, not zero textual occurrences of old names.

### 3.4 Speed Plus: application-layer multi-slave TCP bond

Speed Plus preserves the useful bandwidth-composition property formerly associated with classic multi-slave sidecar delivery, but it runs inside the only supported backend mode.

The authoritative TCP data path is:

```text
local TCP application
        │
        ▼
standalone leikwan-bond client
  ├─ sequenced frames ─► local leg socket A ─► leikwan-ingress client ─► slave A plain_tcp_mux
  ├─ sequenced frames ─► local leg socket B ─► leikwan-ingress client ─► slave B plain_tcp_mux
  └─ sequenced frames ─► local leg socket N ─► leikwan-ingress client ─► slave N plain_tcp_mux
                                                              │
             each slave's authenticated TCP relay             │
                                                              ▼
             that slave's existing LeiGod static sidecar uplink
                                                              │
                                                              ▼
                     sidecar leikwan-bond server
                                                              │
                                  reassembled target TCP stream
                                                              ▼
                                                           target
```

Each selected slave contributes one independent plain TCP ingress and one independent LeiGod carrier to the same authenticated sidecar bond session. The bond client divides application bytes into sequenced frames; the sidecar reorders and reassembles them into one target TCP connection. Reverse traffic follows the same framed session in the opposite direction.

The standalone `leikwan-bond` TCP transport uses ordinary TCP sockets. It does not create `IPPROTO_MPTCP` sockets and therefore does not require kernel MPTCP capability.

#### Initial transport scope

The first LeiGod-only Speed Plus release has one active leg transport:

```text
plain_tcp_mux over selected slaves
```

For `leigod_mode` publication:

- QUIC, WSS, direct-sidecar, CF, and self-leg candidates are not emitted as active bond legs.
- `plain_tcp_mux` ingress requires a non-empty per-endpoint HMAC token and a non-empty route key.
- Each slave's plain mux authenticates the route before opening its backend.
- The slave backend targets that slave's existing LeiGod static-uplink relay target.
- The sidecar bond server verifies the signed bond token and derives the Forward and target from its claims.
- Missing or invalid fields fail publication or that leg's handshake; they never select another backend family.

Legacy QUIC/WSS/self-leg builders may remain temporarily only where needed to recognize and retire old runtime. They are not product fallback paths for a newly published LeiGod Forward.

#### Logical leg identity

Every emitted TCP leg carries:

- `forward_id`
- `node_id`
- `endpoint_id`
- display host and current connection attributes

Its stable logical key is:

```text
bond:<forward_id>:tcp:<node_id>:<endpoint_id>
```

The key survives endpoint ordering changes, local port reallocation, IP changes, agent restart, disconnect, reconnect, cooldown, and process replacement. Duplicate logical keys or two active endpoints resolving to the same `(forward_id, node_id, endpoint_id)` fail publication.

The sidecar currently authenticates the Forward and target through the session token but does not receive trustworthy endpoint/node identity in the bond Hello frame. Therefore the first release uses the authenticated **client device** as the authoritative per-leg observer. Sidecar observations prove server/session availability but cannot infer which slave carried a leg merely from anonymous incoming sockets. A future protocol version may carry signed leg identity, but this release does not depend on it.

#### Session and scheduler behavior

One long-lived TCP bond transport session may multiplex multiple local TCP streams. The first release accepts one to eight selected endpoints, with at most one active leg per distinct slave `node_id`; a larger or duplicate-node set fails publication with `invalid_speed_plus_leg_set`.

Scheduler behavior is versioned desired state rather than an implicit platform default. `leigod_bond_v1` publishes:

```text
warm_window_ms = 1000
second_leg_warm_bytes = 262144
warm_hold_ms = 10000
```

The measured value is unique application-payload bytes offered to the scheduler during the rolling monotonic `warm_window_ms`. The scheduler begins on the lowest-latency eligible leg, warms additional eligible legs when the value is at least `second_leg_warm_bytes`, and retains them until one complete `warm_hold_ms` interval remains below the threshold. A transport-session replacement resets the warm window and hold state. Missing or invalid scheduler fields fail publication rather than selecting platform defaults.

The scheduler also:

- tracks unique acknowledged application-payload bytes, penalties, retransmissions, smoothed RTT, and in-flight bytes per logical leg,
- retransmits a lost frame over another usable leg when possible,
- never counts a connected but cooling, quarantined, or probationary leg as contributing capacity, and
- selects the best eligible single leg deterministically. An ineligible current leg is replaced immediately; an eligible candidate in a lower scheduler-penalty tier replaces it immediately. Within the same penalty tier, a candidate replaces the current leg only when its measured smoothed RTT is both at least 10% and 5 ms better for three consecutive 10-second windows, or when the current leg has no measured RTT while the candidate does for those three windows. Stable logical key breaks an initial or otherwise exact tie but never forces churn between equivalent live legs.

A one-leg session remains a valid TCP Forward but is not Speed Plus aggregation. When only one leg is eligible, or a qualifying useful-byte window shows fewer than two productive legs, every existing and new application TCP stream uses the selected best eligible leg inside the same standalone bond session. Already framed but unacknowledged data is retransmitted on that leg; byte sequence, remote target connection, and application socket stay intact when protocol conditions permit. The runtime reports `single_leg_only` for a one-node desired set or `degraded_single_leg` for a multi-node desired set and does not rewrite desired state. Restoring another leg re-admits it within the existing bond transport session after handshake and probation; it does not recreate the Forward, the other ingress processes, or the sidecar target configuration.

#### Runtime states

Runtime state is per authenticated client-device session and Forward. Evidence from different devices, runtime epochs, or transport sessions is never merged. The master derives the desired distinct-node set from the exact published generation and derives eligible nodes only from canonical per-leg admission phases. Classification uses this ordered decision tree; the first matching rule wins:

1. Missing, stale, malformed, unsupported-platform, out-of-order, desired-generation-mismatched, session-incoherent, or ambiguously mapped evidence is `unknown`.
2. Fresh valid evidence with no live bond transport session or zero eligible slave nodes is `unavailable`.
3. One authoritative desired slave node with one eligible node is `single_leg_only`.
4. Multiple authoritative desired slave nodes with exactly one eligible node is `degraded_single_leg`.
5. At least two eligible distinct slave nodes with no complete traffic window, or with a complete window below 1,048,576 total useful bytes, is `ready_multi_leg`.
6. A complete window with at least 1,048,576 useful bytes but fewer than two nodes at the required share is `degraded_single_leg`; the nonproductive legs enter scheduler quarantine/probation according to section 10.2.
7. At least two eligible distinct slave nodes with a qualifying traffic window is `aggregating`.

A qualifying traffic window is one complete client-computed monotonic interval `[T-10s, T)` wholly within the current runtime epoch and transport-session epoch. It contains at least 1,048,576 total unique application-payload bytes accepted by the remote bond peer/reassembler, credited exactly once to the leg carrying the finally accepted copy; at least two eligible legs on distinct `node_id` values each contribute at least 5% of the declared direction's total. Upload and download are classified separately and are never added together. Retransmissions, framing, ACK/control, attempted wire bytes, duplicates, and cumulative counters do not count. Session change, counter decrease/wrap, sample gap, or an incomplete window can produce `ready_multi_leg` when connectivity is otherwise valid, never `aggregating`.

Evidence is fresh only when effective observation age is less than 15 seconds: master receipt age plus the accepted report's bounded monotonic sample age. Exactly 15 seconds is stale. A delayed upload cannot become fresh merely because it was newly received. These thresholds define honest state reporting, not release acceptance; release acceptance uses the stricter controlled gate in the testing section.

#### Failure, degradation, and recovery

- One failed leg is marked unavailable without terminating healthy leg workers.
- Pending data assigned to a failed leg is retransmitted over another leg when possible.
- If multi-leg establishment or productive aggregation fails while at least one leg remains eligible, the runtime selects the best eligible slave by the scheduler rule above and carries the same application TCP stream on that leg. It does not reconnect the application, replace the sidecar target session, or change runtime family.
- New local TCP streams continue over the selected best remaining leg.
- A recovered leg completes connect, bond Hello/ack, and bounded probation before receiving bulk data.
- Reordering endpoints changes neither logical identity nor healthy worker processes.
- Desired-state removal stops only the removed leg and releases only its owned local port lease after keep-set checks.
- All-leg failure reports `unavailable` and retries in place with bounded backoff; it does not trigger an unbounded whole-Forward systemd restart loop.
- Failure to start the standalone bond runtime itself, authenticate the bond session, or resolve the target is an explicit Forward error because no valid same-family session exists to preserve; it never authorizes MPTCP, CF, WSS, or classic-sidecar fallback.

Speed Plus guarantees the ability to use multiple slave paths concurrently. Throughput remains bounded by client and sidecar CPU, sidecar access, LeiGod UoT carriers, congestion, loss, and target capacity.

#### Protocol and platform capability

TCP is the only aggregation claim. The first-release publication matrix is explicit:

| Platform | `tcp` | `tcp+udp` | `udp` | Speed Plus claim |
|---|---|---|---|---|
| Linux client agent | Publish TCP bond. | Publish TCP bond only when the LeiGod UDP compatibility sidepath passes preflight; otherwise reject the whole publication. | Publish only when that compatibility sidepath passes preflight. | TCP only, subject to fresh evidence. |
| Windows Desktop | Existing bond contract may remain parse/run compatible, but product support is unverified. | Same, with no implicit UDP fallback. | Existing compatibility only. | Never claim until the platform gate passes. |
| Android | Existing bond contract may remain parse/run compatible, but product support is unverified. | Same, with no implicit UDP fallback. | Existing compatibility only. | Never claim until the platform gate passes. |

The release does not publish `leikwan-bond --protocol udp` as Speed Plus and does not call UDP duplication, fanout, or reordering “aggregation.” An unsupported requested platform/protocol combination fails explicitly; it is never silently reduced to a different protocol or runtime family.

Linux is the initial formal Speed Plus target. Windows Desktop and Android must independently pass collision-free allocation, per-leg bounded recovery, authenticated telemetry, one-leg degradation/recovery, and controlled byte-distribution/throughput gates before their capability changes from unverified. Unsupported or unverified platforms must not show an `aggregating` claim.

### 3.5 CF administration

CF location-group navigation and ordinary create/edit controls are removed because no supported backend mode consumes them. Existing records remain for audit and appear only from the operations retirement view when relevant. Direct write requests to legacy CF administration routes return HTTP 410 with a stable deprecated-feature error.

---

## 4. Architecture

The architecture combines a durable retirement ledger with the agents' desired-state machinery, plus a hardened bond runtime on active LeiGod clients.

```text
legacy row scan
      │
      ▼
master retirement transaction
  ├─ disable legacy Forward
  ├─ create permanent Forward tombstone
  ├─ snapshot attributable runtime ownership
  ├─ queue existing endpoint destroy tasks
  └─ create client/slave/sidecar delivery targets
      │
      ├──────── client pull ───────► Forward-scoped teardown ─┐
      ├──────── slave task/pull ───► Forward-scoped teardown ─┤
      └──────── sidecar pull ──────► Forward-scoped teardown ─┤
                                                              ▼
                                             idempotent authenticated ack
                                                              │
                                                              ▼
                                               pending/in_progress/partial/completed
```

```text
active LeiGod desired state
      │
      ▼
locked bond contract + selected slave endpoints
      │
      ├─ client supervisor manifest + port leases
      ├─ per-leg ingress workers
      ├─ standalone leikwan-bond client
      ├─ slave plain_tcp_mux routes over existing static uplinks
      └─ point-shared sidecar bond server
```

The retirement ledger supplies durability, observability, and offline delivery. Existing cleanup functions perform deletion behind one Forward-scoped entry point. Empty desired-state sections remain useful but do not replace the ledger.

---

## 5. Data model

### 5.1 `forward_runtime_retirements`

One permanent tombstone exists for each retired Forward.

| Field | Meaning |
|---|---|
| `id` | Retirement identifier. |
| `forward_id` | Historical Forward; unique for idempotency. |
| `service_id` | Historical service ownership. |
| `backend_mode` | Original deprecated backend mode. |
| `reason` | Stable value such as `backend_mode_deprecated`. |
| `status` | `pending`, `in_progress`, `partial`, or `completed`. |
| `ownership_snapshot_json` | Forward-scoped endpoint, sidecar, interface, unit, path, and applied-state evidence. |
| `retired_at` | Time active publication was disabled. |
| `created_at`, `updated_at` | Audit timestamps. |

The tombstone remains after completion as an audit record and replay source for a previously unseen eligible device.

### 5.2 Retirement client scopes and delivery targets

`forward_runtime_retirement_client_scopes` stores immutable late-discovery authorization with unique key `(retirement_id, client_id)` plus the historical direct/group match reason, created time, and optional revocation time. It contains no device ID, credential, result, or acknowledgement status and never participates in aggregate completion. A scope authorizes materialization only while the same authenticated client identity remains valid; revocation prevents new device targets but preserves audit and existing target receipts.

`forward_runtime_retirement_targets` records delivery and acknowledgement per concrete cleanup-capable agent identity.

| Field | Meaning |
|---|---|
| `id` | Target identifier. |
| `retirement_id` | Parent tombstone. |
| `role` | `client`, `slave`, or `sidecar`. |
| `target_id` | Role-specific database identity. |
| `device_session_id` | Server-issued client-device session identity when `role = client`; normalized empty value for other roles. |
| `reconciliation_generation` | Starts at 1 and increments only for authenticated late reappearance after a prior completion. |
| `status` | Lifecycle within the generation: `pending` or `completed`. |
| `last_attempt_outcome` | `partial`, `failed`, or `success`; diagnostic and retryable independently of lifecycle. |
| `last_attempt_seq`, `last_attempt_id` | Per-target ordering and idempotency; the attempt ID is the master-issued delivery token. |
| `attempt_count` | Number of newly accepted cleanup executions, not HTTP deliveries, lease renewals, or duplicate reports. |
| `next_attempt_at`, `lease_until` | Master-owned retry deadline and renewable single-active-attempt lease. |
| `last_error` | Last actionable error. |
| `result_json` | Bounded counts and resource summaries. |
| `first_seen_at`, `last_attempt_at`, `completed_at` | Delivery lifecycle. |
| `created_at`, `updated_at` | Audit timestamps. |

A unique key covers `(retirement_id, role, target_id, device_session_id)`, and `(target_id, reconciliation_generation, last_attempt_id)` is idempotent. A lower/equal attempt sequence or non-current token cannot replace a newer result; duplicate delivery cannot increase `attempt_count`; `completed` cannot regress within a generation. Target mutation and parent-status recomputation lock the parent and occur in one transaction; activation reruns use insert-if-absent and never reset lifecycle fields. A new generation preserves the prior compact completion receipt.

### 5.3 Client devices and late discovery

A Forward may match multiple clients directly or through groups, and one client token may appear on multiple devices over time. `device_session_id` is an opaque server-issued installation identity bound to authenticated client ownership; it survives credential refresh, cannot be caller-selected, and changes only after revocation/re-enrollment. Retirement uses two layers:

1. At retirement creation, persist deduplicated matching client-identity eligibility, every already-known installation identity, historical endpoint/slave identities with applied/delivery evidence, and every current or historical sidecar point with attributable evidence.
2. When an eligible authenticated installation pulls, insert-if-absent its device-specific delivery target before returning applicable tombstones.

Eligibility seeds authorize bounded late materialization but are not acknowledgeable targets. Completion by one device does not suppress delivery to a newly seen eligible device. Tombstones are never broadcast outside historically matched client identities.

A completed tombstone remains visible to its eligible device as a non-destructive replay check. The agent serializes a bounded exact-ownership inventory and normally sends no result when nothing exists. If legacy evidence reappears after image restore or local-state rollback, it posts an authenticated `legacy_runtime_reappeared` report containing bounded proof but performs no deletion under an old token. The master locks the parent/target, increments `reconciliation_generation`, sets that generation pending, and issues a new leased attempt; cleanup then follows sections 7.2–7.3. Concurrent duplicate reappearance reports coalesce into one generation.

Permanent tombstones retain only minimal redacted replay/audit data. Ownership snapshots contain no credentials or token material and are capped at 256 KiB; overflow fails that Forward's activation transaction with an audit blocker rather than truncating ownership proof. Pending/partial/failed target evidence is retained until reconciliation. Verbose errors/results expire after 30 days; completed targets compact after 30 days to a permanent `(retirement, role, target, device, completed_generation, completed_at)` receipt. A returning installation after compaction still receives the permanent tombstone and performs the cheap inventory/replay behavior above.

### 5.4 Aggregate retirement status

Only delivery targets participate. Aggregate precedence is deterministic:

1. `completed` when at least one delivery target exists and every target is completed.
2. `partial` when any non-completed target's latest accepted outcome is `partial` or `failed`.
3. `in_progress` when any accepted attempt exists and unresolved targets remain.
4. `pending` otherwise, including zero materialized delivery targets.

`completed` means **currently known delivery targets reconciled**, not a proof that the open-ended historical device cohort is globally exhausted. A newly discovered eligible device creates a pending delivery target and atomically reopens the aggregate to `in_progress`. A partial or failed attempt remains retryable and takes precedence over `in_progress`. Global cleanup is never claimable while client eligibility remains open for late discovery; operations expose the last discovery time and unresolved concrete targets.

### 5.5 Latest bond runtime snapshots

A lightweight authenticated endpoint, independent of the 30-second configuration pull/apply cycle, accepts `bond_runtime_snapshots`. The server issues and authenticates a device-session credential bound to the client identity; caller-supplied `device_id` text is diagnostic only and never keys or authorizes storage.

The master stores only the latest accepted snapshot for each `(client_id, device_session_id, forward_id)` in `forward_bond_runtime_snapshots`; it is not an append-only traffic log. Required fields are:

- schema and runtime-contract version,
- Forward ID, active desired-generation ID, and canonical desired-leg-set hash,
- always-present supervisor observer identity,
- persistent monotonically increasing `runtime_epoch` for this device-session/Forward, incremented and durably fenced before each bond-process start,
- nullable diagnostic bond-process UUID when no bond process is live,
- nullable immutable `transport_session_epoch`, incremented on each successful Hello/ack and absent before establishment,
- monotonically increasing sequence within the runtime epoch,
- monotonic sample age at send time,
- transport lifecycle state,
- selected single-leg logical key and stable selection reason when state is `single_leg_only` or `degraded_single_leg`,
- an optional complete traffic window with exact duration/end age, direction, counter epoch, total useful-byte delta, and per-leg useful-byte deltas,
- bounded per-leg current state.

The client-reported desired count is diagnostic only. The master joins the generation/hash to authoritative desired state and derives membership and distinct-node cardinality.

Each leg contains its stable key, `node_id`, `endpoint_id`, diagnostics, and one canonical admission phase:

```text
stopped | lease_wait | retry_wait | connecting | handshaking |
probation | eligible | quarantined | circuit_open
```

The applicable deadline, phase-transition sequence, and separate recovery fields accompany that phase:

- ingress-process consecutive-exit streak, rolling restart ledger count, and process circuit deadline,
- lease-allocation retry deadline when pre-spawn allocation is blocked,
- connector consecutive failures and next connector retry,
- data-plane penalty level, cooldown deadline, probation probe budget, and probation deadline,
- cumulative useful bytes for diagnostics,
- the current window's unique acknowledged application-payload bytes credited exactly once to the final successful leg,
- retransmit/wire/control bytes, RTT, productive-service duration, and last reason as non-authoritative diagnostics.

`connected`, scheduler eligibility, health, and summaries are derived from the canonical phase; if diagnostic compatibility booleans remain, the master rejects combinations inconsistent with that phase.

Cumulative, retransmitted, framing, duplicate, ACK/control, and attempted wire bytes never contribute to `aggregating`. Forward and reverse directions are reported separately; product state uses the direction in which the qualifying test/application traffic was observed and never adds the two directions together.

Ordering and ingestion rules are:

- The active desired generation and desired-leg-set hash must match the master's assignment.
- Accept only a lexicographically newer `(runtime_epoch, sequence)` for the same assignment. A duplicate, lower sequence, retired epoch, old desired generation, old set hash, or transport-session regression does not replace state or refresh receipt time. A traffic window must lie wholly within the reported transport-session epoch.
- `received_at` is generated only by the master database clock after acceptance.
- Schema/authentication/request-size failure rejects the request. Assignment and leg validation are isolated per Forward; valid Forward items commit while rejected items return bounded item-level error codes.
- A keepalive with unchanged state may update sequence and `received_at` without rewriting the large state blob; a state hash makes unchanged payload replacement a no-op.

The device reporter maintains one latest-only slot per Forward and at most one request in flight. A newer sample replaces an unsent one; superseded or offline-queued telemetry is never replayed. Meaningful connection/session/recovery transitions schedule an immediate jittered report. Otherwise healthy active traffic reports every 5 seconds and idle connected runtime every 10 seconds, each with stable device-specific 0–20% jitter. Timeout/retry uses 1–10 second exponential backoff with jitter while retaining only the newest sample. These cadences deliberately remain below the 15-second freshness boundary during healthy control-plane operation.

The local snapshot is ephemeral `/run` state bound to the live writer epoch. It is atomically replaced on meaningful state change and at each completed 10-second traffic window, coalesced to no more than one write per second; idle unchanged state causes no periodic disk write. Orderly stop removes or invalidates it, and the reporter cannot refresh an old file after writer death.

Bounds are deterministic:

- maximum 8 desired/reported legs on distinct slave nodes per Forward,
- maximum 16 KiB decompressed JSON per Forward snapshot,
- maximum 64 Forward snapshots and 512 KiB decompressed JSON per request,
- optional reason text is truncated first; periodic reports contain current state only and no repeated transition-history array,
- a report still over a limit is rejected with a stable item/request error and yields no derived state update.

Ordinary surfaces receive a bounded array keyed by device session with state, receipt time/age, and reason; they do not collapse several devices into one Forward-wide `aggregating` state. Raw snapshots are operations-only. Snapshot rows are deleted on Forward/assignment removal or device-session revocation and garbage-collected after seven days without an accepted report.

A snapshot with configured endpoints but no complete useful-byte window may produce `ready_multi_leg`; it cannot produce `aggregating`.

### 5.6 Client-local bond port leases

The Linux supervisor uses one host-wide persistent lease registry and exclusive allocator lock. A lease is keyed by:

```text
(forward_id, protocol, node_id, endpoint_id, purpose)
```

For this release `protocol` is `tcp`, `purpose` is `ingress_leg`, and the default configurable range is `20000–39999`. The registry records port, owner key, desired generation, last successful bind, and owner liveness. It is local runtime state, not a master database table.

Allocation scans deterministically from a key-derived start under the host lock, rejects registry owners and actual bind conflicts, and holds an exclusive loopback reservation socket until the ingress child assumes the listener through an inherited descriptor or equivalent atomic handoff. A stale lease is reclaimed only after its owner generation is absent and the port is bind-safe. A surviving logical key keeps its lease when safe; a collision reallocates only that key. Registry and supervisor-manifest generation change atomically, or the old generation remains authoritative after a crash. A retired or removed leg releases only its exact lease after active keep-set checks and after the bond runtime has acknowledged removal of the old leg specification.

Formula-only allocation such as `20000 + ((forward_id * 37) % 15000)` is never ownership evidence or the final allocator.

---

## 6. Activation and normalization

### 6.1 Legacy retirement activation

A versioned, idempotent CLI command performs legacy retirement after storage and retirement-capable control-plane code are deployed. Schema migration creates tables and defaults but does not enqueue remote work.

For each Forward matching the section 3.2 legacy predicate, including already-disabled rows that may retain runtime, one database transaction:

1. Locks the Forward and referenced combination rows.
2. Creates or reuses its tombstone by unique `forward_id`.
3. Captures immutable historical fields plus the union of all current or historical ownership/applied-state evidence attributable to the Forward across client identities, slave nodes, sidecar points, interfaces, units, paths, and endpoint tasks. This is bounded evidence, not fleet broadcast.
4. Sets `enabled = 0` without changing original mode, identity, or relationships.
5. Marks active service endpoints deleting and insert-if-absent queues endpoint `destroy` tasks.
6. Creates insert-if-absent delivery targets for concrete slave/sidecar identities and durable eligibility scopes for matching client identities. Previously used sidecar points with attributable evidence are included, not only the currently bound point.
7. Writes an audit event, including both persisted mode values when they disagree.

Remote operations occur only after commit. Forward retirement is the convergence authority: successful role reconciliation also marks encompassed endpoint-destroy work absent/completed without replaying it, while either operation may arrive first. Re-running reports scanned, newly retired, already retired, and failed counts; conflicts are no-ops for existing lifecycle fields and create no duplicate work.

### 6.2 Existing LeiGod normalization

A separate idempotent command handles existing `leigod_mode` combinations and Forwards whose runtime contract differs from the locked bond contract.

For each combination it first performs a read-only preflight for:

- valid LeiGod line and sidecar point,
- usable point-shared sidecar bond server settings,
- one to eight usable selected endpoints on distinct slave `node_id` values; at least two for a Speed Plus claim,
- a compatible Linux device capability cohort and the minimum runtime artifact version/hash,
- valid plain TCP mux route and authentication material,
- valid static sidecar uplink for every emitted slave leg,
- valid TCP bond token and target.

Only a successful preflight permits one transaction to update the combination and denormalized Forward columns to the locked values and queue republish. A preflight failure leaves the active row unchanged, records a blocking audit result, and prevents declaring rollout complete. It never disables a valid LeiGod Forward merely because normalization failed.

Normalization does not publish MPTCP fallback. It does not touch legacy backend rows.

---

## 7. Agent retirement contract

### 7.1 Common retirement object

All roles consume an equivalent object:

```json
{
  "retirement_id": 123,
  "forward_id": 65,
  "reason": "backend_mode_deprecated",
  "ownership_scope": {
    "endpoint_ids": [901, 902],
    "sidecar_point_id": 5,
    "known_interfaces": [],
    "known_units": [],
    "managed_paths": []
  }
}
```

`retirement_id`, `forward_id`, `reason`, and explicit ownership scope are mandatory. Empty arrays mean no extra snapshot evidence; they do not authorize broad deletion.

Before deletion, each agent builds the active keep-set from the same successfully parsed response. If one Forward ID appears in active desired state and retirement state, deletion fails closed with `desired_state_conflict`.

### 7.2 Delivery semantics

- Delivery is at-least-once, but the master is the sole retry scheduler.
- It leases one target attempt only when no lease is live and `now >= next_attempt_at`; the agent serializes execution per target and never starts a second local attempt for the same token.
- A 5-minute lease may be renewed while bounded work is progressing. An expired lease becomes deliverable with a new attempt ID; a result for the expired token is stale.
- Partial/failed attempts schedule `min(30s × 2^(attempt_count-1), 1h)` with stable target-specific 0–20% jitter. One agent cycle starts at most 16 retirement attempts and spends at most 30 seconds before leaving remaining work leased/pending for a later cycle.
- Already-missing exclusive resources count as reconciled success.
- `completed` means exclusive Forward-owned resources and the retired Forward's local references are absent; keep-set-protected shared objects may remain and count as successful preservation. Ambiguous ownership or a keep-set conflict remains `partial`.
- A malformed response or absent retirement section never authorizes cleanup.
- A present empty desired-state section retains its existing role-specific authoritative meaning.
- Normal LeiGod apply and reconciliation continue when one retirement fails.

### 7.3 Acknowledgement

Agents post a bounded result containing:

- Retirement and Forward IDs.
- Agent role and authenticated identity.
- Server-issued device-session identity for client agents.
- Master-issued attempt ID and monotonic target sequence.
- `completed`, `partial`, or `failed` outcome.
- Counts for stopped/disabled units, deleted interfaces/files/leases, removed local references, already-absent resources, keep-set-preserved shared resources, and errors.
- Short resource names and actionable messages under strict count and size limits.

The master derives reporting identity from authentication. One target cannot acknowledge another. Reporting any authenticated result is an acknowledgement; only an accepted `completed` outcome satisfies reconciliation. Duplicate/stale reports are idempotent no-ops.

---

## 8. Forward-scoped cleanup

Each agent exposes one internal entry point:

```text
retire_forward_runtime(forward_id, ownership_scope, active_keep_set)
```

It may call existing helpers but owns ordering, safety checks, idempotency, and result collection.

### 8.1 Ownership rules

An agent may delete a resource only when at least one condition proves exact Forward ownership:

- Its canonical managed name embeds the exact Forward ID.
- It is listed in the retirement ownership snapshot.
- It appears in local applied state or managed paths for that Forward.
- It is a systemd unit whose name and content/environment together prove the exact managed Forward identity.

Every candidate is compared with the active LeiGod keep-set. An active reference wins. Ambiguous resources remain and produce `partial`.

No cleanup implementation may:

- stop every `wg-quick@*` unit,
- delete every WireGuard interface,
- delete all units sharing a generic `leikwan` prefix,
- interpret a missing payload section as purge authorization,
- remove a shared sidecar uplink referenced by active LeiGod desired state, or
- remove the point-shared sidecar bond server because one retired Forward once used bond.

### 8.2 Client cleanup

Client retirement covers every recognized family owned by the retired Forward:

- Forward-level units, supervisor/start scripts, and manifests.
- Per-leg ingress workers and exact local bond port leases.
- Per-Forward bond health snapshots and bounded runtime state.
- Forward-owned bond, MPTCP, UoT, rate-limit, watchdog, and helper units.
- `wg-quick@...` units and exact Forward-owned WireGuard interfaces/configuration.
- Legacy `lw-mptcp-<forward>-*`, `lw-cfwg-<forward>`, `lw-cfsr-<forward>`, and `lw-self-<forward>` resources.
- Forward-owned firewall rules, namespaces, veths, generated files, and applied-state manifests.

Local ownership evidence is retained per resource until that resource is either absent or its retired reference is removed while a keep-set-protected shared object remains. A partial/failed attempt never discards evidence needed by the next retry. After target completion, compact local state to a minimal Forward/generation completion watermark so permanent tombstone replay can detect restored legacy resources.

Active `leigod_mode` bond workers, snapshots, and port leases are in the keep-set and are never classified as legacy merely because their binary name contains `bond`.

### 8.3 Slave cleanup

The slave path adds Forward-level `retire_forward` alongside endpoint `destroy`. It removes exact Forward-owned:

- endpoint ingress, backend, relay, and selector units,
- classic/CF wstunnel, shadow backend, UoT, and MPTCP underlay units,
- WireGuard interfaces and configuration,
- generated route files and local state,
- stale sidecar-uplink references attributable solely to the retired Forward.

A static sidecar uplink referenced by any active LeiGod endpoint remains. Endpoint destroy and Forward retirement may arrive in either order; missing resources are success.

### 8.4 Sidecar cleanup

The sidecar desired-state response gains explicit `runtime_retirements`. Sidecar cleanup covers exact Forward-owned:

- legacy MPTCP transit interfaces and helper state,
- slave-transit CF backends,
- relay and selector units,
- old per-Forward `gost`/`lguot` residue,
- WireGuard units, interfaces, and configuration,
- generated routes and static-uplink references.

The sidecar `leikwan-bond` server is point-shared. It remains whenever any active LeiGod Forward references that point's bond runtime. Retiring one Forward removes only its attributable routes/state; signed tokens expire normally and no active shared service is restarted solely for retirement.

---

## 9. Shared-resource safety

Each agent cycle:

1. Parses and validates active LeiGod desired state.
2. Builds a keep-set of unit names, interface names, files, routes, sidecar uplinks, bond services, supervisor manifests, logical leg keys, and port leases.
3. Builds retirement candidates only from exact ownership evidence.
4. Subtracts the active keep-set.
5. Deletes only the remainder.
6. Reports preserved shared resources separately from failures.

A shared object skipped because of an active reference is not an error. The retired reference is removed from local state while the object remains. Ambiguous ownership remains `partial`.

Critical invariant:

> Retiring every `current_mode` and `cf_mode` Forward leaves the runtime and traffic of all active `leigod_mode` Forwards unchanged.

Bond is a supported active LeiGod transport, not a globally legacy resource family.

---

## 10. Bond runtime hardening

Existing bond code supplies framing, retransmission, health scoring, and late-leg attachment, but the current generated client runtime is not accepted unchanged. The implementation must close the following gaps before Speed Plus release.

### 10.1 Structured per-Forward supervisor

The generated shell pattern:

```bash
trap 'kill $(jobs -p) ...' EXIT
wait -n
```

is replaced for LeiGod bond runtime by one structured per-Forward supervisor manifest and process. Recovery ownership is disjoint:

- The supervisor owns lease allocation, spawn/bind readiness, abnormal process exit, process restart, desired-generation replacement, and child process circuits.
- A live bond process owns network connect and Hello/ack retry. Network failure never asks the supervisor to restart a healthy process.
- The bond scheduler owns post-handshake data-plane quarantine, probation, and productivity penalties. It never charges process or connector counters.

The supervisor starts one ingress worker per logical leg, then the TCP bond worker after at least one required listener is ready. It never exits because one child exits and preserves unrelated healthy workers during endpoint reorder, port reallocation, and single-leg failure.

Each stable child identity has two persisted process ledgers: a consecutive abnormal-exit streak and a rolling deque of restart-launch timestamps. Initial desired-generation launch and an orderly generation replacement are not restarts. After an abnormal exit, the next launch uses 1-second exponential backoff capped at 60 seconds with stable 0–20% jitter derived from device identity, logical key, and restart epoch. Sixty continuous seconds alive resets only the consecutive streak. Before every restart launch, timestamps outside `[now-600s, now)` expire; if ten remain, the eleventh launch is denied and `circuit_open_until = now + 600s`. Equivalent generation and supervisor/systemd replacement preserve the ledgers. The bond child follows the same policy independently of ingress children.

Pre-spawn lease failure uses `lease_wait`, a separate 1–60 second bounded retry owned by the supervisor. It continues while the leg is desired, does not consume process restart budget, and reports `local_port_exhausted` while blocked. Generation-fatal errors such as malformed desired state/token or missing required artifacts remain stopped until desired generation or artifact capability changes. Permanently unsupported platform/protocol requests are rejected before publication. None is treated as an internal supervisor crash.

Desired-generation replacement is fenced and ordered:

1. Mark the old generation draining and cancel/join its outstanding lease/connect attempts.
2. Remove old leg specs from the bond scheduler and require a detach acknowledgement carrying the generation.
3. Stop removed ingress workers.
4. Release their exact leases.
5. Atomically activate the new manifest/registry generation and start additions.

Every lease, connect, attach, detach, and child-readiness message carries desired generation and logical key. The bond worker rejects stale-generation attachment, so an old connector cannot reappear through a reused port.

All-leg unavailability is reported in place; ordinary network failure never terminates the supervisor. The top-level unit uses `Restart=on-failure`, `RestartSec=30`, `StartLimitIntervalSec=600`, and `StartLimitBurst=5`; every systemd start, including the initial start, consumes that systemd burst. Only internal supervisor corruption/exceptions may exit non-zero. If systemd reaches `start-limit-hit`, agent reconciliation is the sole recovery owner: after a persisted, device-jittered 10-minute cooldown it runs `reset-failed` and requests one half-open start. Success for 10 minutes clears this outer recovery level; failure reopens it. No generic timer independently restarts the unit.

### 10.2 Bond connector retry and re-admission

A live bond process drives each leg through the canonical path:

```text
retry_wait -> connecting -> handshaking -> probation -> eligible
```

Connect or Hello/ack failure updates only connector state. Retry starts at 1 second, doubles to a 60-second cap with stable device/logical-key/connector-epoch 0–20% jitter, and resets after 60 continuous seconds of established transport membership whether or not application demand exists. The process remains alive, and snapshot state records connector failures, deadline, and reason.

After successful Hello/ack, data-plane admission is separate:

- first quarantine is 20 seconds; repeated failed probation doubles it to a 5-minute cap,
- only one probation leg per transport session runs at once; due candidates rotate by deadline then stable logical key,
- probation sends three authenticated 4 KiB bond control probes, which the peer acknowledges but never delivers to the target and which never count as application, throughput, or aggregation bytes,
- all three probes must be acknowledged within 5 seconds of the first send to become `eligible`,
- timeout/protocol failure increments only the data-plane penalty, returns the leg to quarantine, and admits the next due candidate,
- a cooling/probation leg receives no normal application bulk data,
- 60 seconds of acknowledged productive application service resets the penalty; lack of application demand pauses this rehabilitation clock rather than failing it.

This probe lane runs even when another healthy leg exists. When all legs are cooling, only the serialized probation candidate probes; bulk traffic is not blindly assigned to cooled legs.

### 10.3 Atomic snapshots

The existing bond snapshot is extended rather than replaced with kernel MPTCP telemetry. It carries the canonical admission phase, transition sequence, and exact useful-byte window defined in section 5.5 rather than treating health scores or cumulative counters as state authority. Writes use temporary file, file sync, and same-filesystem atomic rename; a reader rejects partial JSON, writer-epoch mismatch, and regressed sequence. Local diagnostics may keep at most 20 transitions per leg, but periodic master reports contain only current state and the last bounded reason.

### 10.4 Collision-free ports

Every local leg listener uses the lease registry in section 5.6. Allocation preflights bind availability before changing the active generation. If no port is available in the configured range, only the affected leg fails with `local_port_exhausted`; existing healthy generations remain active. The allocator never kills an unknown listener to reclaim a formula-derived port.

### 10.5 No runtime-family fallback

A failed bond start, missing binary, malformed token, or unsupported platform reports an explicit bond error. It does not invoke `leikwan-mptcp-relay`, switch to `mptcp_preferred_bond_fallback`, restore WSS/CF, or rewrite the backend mode.

---

## 11. Control-plane simplification boundary

### 11.1 Remove from active product code

- Three-way backend-mode selection and legacy mode fields.
- New-save/edit behavior for `current_mode` and `cf_mode`.
- Legacy route selection, publication, republish, and reconciliation.
- CF candidate and classic CNIX builders unreachable from LeiGod retirement.
- CF location-group write UI and navigation.
- Native-MPTCP-first or MPTCP-fallback selection for LeiGod Speed Plus.
- Active LeiGod QUIC/WSS/self-leg bond-leg publication.
- Formula-only local bond port allocation.
- Whole-runtime `wait -n` supervision for LeiGod bond.
- Tests whose only purpose is successful legacy publication.

### 11.2 Preserve or adapt

- Sidecar-point management and validation.
- LeiGod static sidecar uplink realization.
- Point-shared sidecar bond server and token validation.
- Standalone `leikwan-bond` framing, retransmission, scheduling, and health foundation.
- `leikwan-ingress` client/mux/backend plain TCP path.
- Slave/client/sidecar desired-state reconciliation.
- Existing UDP LeiGod compatibility sidepath without an aggregation claim.
- Legacy resource recognizers used by retirement cleanup.
- Read-only historical rows, audit diagnostics, and persisted schema values.
- Stable deprecation errors and guards.

Old active builders may be removed only after implementation proves retirement does not call them. Lightweight read-only teardown recognizers remain for as long as any permanent tombstone and late-device eligibility scope can be replayed; they cannot be pruned merely because currently known targets completed.

---

## 12. Rollout

The rollout prevents old agents from losing cleanup information and prevents active LeiGod traffic from being normalized before the hardened bond runtime is available.

### Phase 1: retirement and bond-capable artifacts

1. Implement all three Forward-scoped cleanup entry points and acknowledgement contracts.
2. Implement the client supervisor, port leases, bounded recovery, generation fencing, and bond snapshot v2.
3. Update source agents and every served mirror, including `artifacts/` and `master/public/artifacts/` where applicable.
4. Bump artifact versions and hashes consistently.
5. Publish artifacts and request fleet updates without changing active Forward contracts.
6. Require authenticated device/role capability reports containing platform, agent/runtime version, artifact content hash, retirement schema, and bond snapshot contract.
7. Confirm representative clients, slaves, and sidecars parse empty retirement lists without changing active runtime.

### Phase 2: pre-enforcement canary and gate

1. Apply retirement/snapshot storage and authenticated reporting code without enabling legacy retirement or global bond normalization.
2. On an isolated Linux canary cohort whose every participating client, slave, and sidecar reports the minimum version/hash/capability, run the controlled gate in section 14.9.
3. Gate failure leaves existing production contracts unchanged and disables the Speed Plus claim; fix artifacts and rerun the full gate rather than selecting favorable runs.
4. Gate success is the entry condition for bond-only publication and normalization.

### Phase 3: LeiGod-only control plane and bounded cutover

1. Deploy server-side LeiGod-only guards, hidden legacy UI, retirement delivery/acknowledgement, and plain TCP mux/bond publication.
2. Before returning a new contract, require every affected online role/device to report the minimum platform/version/hash/capability. A reconnecting offline device updates first; unsupported or stale devices receive an explicit update-required response, not a broken or fallback contract.
3. Run LeiGod normalization preflight and resolve all blocked active combinations.
4. Normalize and republish in bounded batches. Each batch has predeclared traffic/error/restart abort thresholds and rolls that batch back to its prior committed LeiGod contract if post-publish verification fails.
5. Run legacy retirement activation only after its participating cleanup agents report retirement capability.

### Phase 4: verification and pruning

1. Observe accepted completion outcomes for online targets and execute reconnect cleanup for offline targets when they return.
2. Verify on-host resources for representative retired Forwards and active LeiGod traffic before/after cleanup.
3. Verify no high-frequency child, supervisor, agent, or systemd restart loop.
4. Leave undiscovered/offline retirement work durable and replayable.
5. Remove unreachable legacy active builders after cleanup compatibility is proven while preserving teardown recognizers for tombstone lifetime.

No partial artifact set is deployable: source agent, `artifacts/`, served public copies, manifests, versions, hashes, and reported capability must agree. Artifact availability alone never enables enforcement. Production deployment requires separate authorization.

---

## 13. Error handling and observability

- Retirement creation, disabling, ownership snapshot, and initial target creation are atomic per Forward.
- Remote operations never occur inside the database transaction.
- Repeated cleanup and already-absent resources are expected success.
- One target failure does not block active LeiGod publication or other targets.
- Partial cleanup retains retry evidence and exact failed/skipped categories.
- Offline targets remain pending without timeout deletion.
- Authentication determines reporting identity.
- Audit logs record activation batch, Forward, original mode, target counts, and aggregate status transitions.
- LeiGod normalization logs preflight blockers and does not partially update a combination.
- Bond snapshots are authenticated, schema-versioned, atomically written/replaced, count-bounded, and size-bounded.
- State freshness uses master receipt age plus bounded monotonic sample age; delayed telemetry never becomes fresh solely on upload.
- Port collisions, child circuit-open states, reconnect failure counts, and runtime generations are operations-visible.
- An operations-only view reports retirement status without restoring legacy controls.

“Task delivered” is not “cleanup complete.” Only authenticated successful reconciliation completes a retirement target. Likewise, “two endpoints configured” is not “Speed Plus aggregating.” Only fresh per-session byte evidence produces that state.

---

## 14. Testing strategy

### 14.1 Control-plane contracts

- Forms contain no `current_mode`, `cf_mode`, client-family, runtime-preference, or ingress-mode selector.
- LeiGod fields remain available and required; omitted mode fields save the locked contract.
- Explicit legacy backend modes return the deprecation error; conflicting client/runtime/ingress values return `unsupported_leigod_runtime_contract`; unknown values return invalid-input errors.
- Parameterized tests exercise every guarded operation in section 3.2 against a legacy combination, a legacy Forward, and mismatched Forward/combination modes. None can create, mutate, clone, select, publish, realize, reconcile, reactivate, or enter desired state.
- Ordinary combination, route, tenant, and client lists omit deprecated rows; operations views remain read-only.
- CF write surfaces are absent or HTTP 410; sidecar-point administration remains available.

### 14.2 Migration and persistence

- Historical mode, identity, relationships, and audit evidence remain unchanged while allowed lifecycle fields change.
- The section 3.2 legacy predicate disables enabled and already-disabled cases and creates exactly one tombstone.
- Fault injection before each transaction statement proves rollback leaves no half-created tombstone, disabled Forward without ledger, partial ownership snapshot, target, endpoint task, or audit event.
- Rerunning activation creates no duplicate/reset work; endpoint destroy and Forward retirement converge in either order.
- Client eligibility, known installations, every attributable historical slave/endpoint, and every attributable current/historical sidecar point are captured without broadcast or truncation.
- Newly seen eligible installations atomically create a pending target after earlier known-target completion.
- Concurrent final completion, late discovery, duplicate acknowledgement, expired attempt, and reappearance-generation tests leave parent/child state consistent and monotonic.
- Active LeiGod Forwards receive no retirement tombstones.
- Existing valid LeiGod combinations normalize only after preflight; injected failure at each normalization write/queue step leaves persisted values, active runtime, and work visibility unchanged and records no partial success.

### 14.3 Publication and data path

- Deprecated Forwards never enter active client configs, normal slave tasks, sidecar routes, or republish queues.
- LeiGod emits `mode=bond`, `runtime_preference=single`, and `ingress_mode=plain_tcp_mux` and no active QUIC, WSS, CF, self-leg, or native-MPTCP Speed Plus leg.
- Publication accepts one-to-eight distinct slave nodes, labels one node ordinary LeiGod, labels two-to-eight Speed Plus eligible, and rejects zero/duplicate/oversized sets with `invalid_speed_plus_leg_set`.
- Every TCP leg has unique `(forward_id, node_id, endpoint_id)` identity and per-endpoint ingress auth.
- Slave plain mux authenticates route/token before backend connect; each backend uses its existing LeiGod static-uplink relay target; sidecar validation binds session to Forward, target, and protocol.
- Missing bond fields fail explicitly without runtime-family fallback.
- TCP+UDP publication is atomic: a failed UDP compatibility preflight rejects the whole publication, and successful UDP sidepath has no aggregation state.

### 14.4 Bond framing and scheduling

- One application TCP stream and reverse traffic transfer correctly over multiple legs with in-order reassembly.
- Lost frames retransmit over another eligible leg, and useful-byte accounting credits only the finally accepted copy.
- Scheduler thresholds exactly implement `leigod_bond_v1`; small traffic uses one leg and bulk traffic warms additional legs.
- Best-leg selection orders eligible legs by scheduler penalty, measured smoothed RTT, and stable key; the exact 10% + 5 ms + three-window hysteresis prevents idle or noisy measurements from flapping selection.
- Cooling/quarantined/probation legs receive no normal bulk data.
- After Hello/ack, serialized probation sends exactly the bounded control probes, excludes them from target traffic/counters, passes within 5 seconds on three acknowledgements, or rotates the next due candidate after failure.
- Multi-leg establishment failure, one-leg loss, and nonproductive multi-leg windows preserve the same application stream and sidecar target session while pinning payload to the best eligible slave; the state becomes `degraded_single_leg`, not a runtime-family fallback.
- Removing one leg preserves streams when protocol conditions allow; recovery rejoins without restarting healthy workers or unrelated Forwards.
- Standalone TCP legs use ordinary TCP and pass on a kernel without MPTCP support.

### 14.5 Port and supervision regressions

- Concurrent allocators across multiple active Forwards never own the same port; crash between registry/manifest/handoff leaves either the old generation authoritative or a recoverable reservation, never split ownership.
- Endpoint reorder preserves leases; add/remove changes only that key; an unknown listener is never killed or overwritten.
- Temporary port exhaustion enters `lease_wait`, preserves healthy runtime, retries after the listener disappears, and never charges a process restart.
- One ingress crash restarts only that child; network/Hello failure produces zero process restart; unproductive probation charges only data-plane penalty.
- Rolling timestamps survive supervisor and equivalent-generation replacement. After ten restart launches in `[now-600s, now)`, an attempted eleventh launch never starts and opens the child circuit.
- Bond-child crash preserves ingress workers and follows its independent budget; all-leg network failure reports unavailable without supervisor/systemd churn.
- Generation fencing proves stale connect/attach attempts cannot rejoin after detach acknowledgement and port release.
- Five fatal supervisor starts reach the systemd limit; only agent reconciliation performs the delayed `reset-failed` half-open start.
- No generated LeiGod bond runtime contains `wait -n` whole-group supervision or formula-only allocation.

### 14.6 Telemetry and state

- Snapshots carry authenticated device session, desired generation/hash, supervisor identity, runtime epoch/sequence, nullable process/session epochs, canonical leg phase, selected single-leg key/reason during degradation, applicable recovery counters/deadlines, and exact directional 10-second useful-byte window.
- Atomic writer crash tests never expose partial JSON or a stale writer epoch.
- Save/preflight rejects more than eight or duplicate-node legs. Reporting enforces 8 legs, 16 KiB per Forward, 64 Forwards/512 KiB per request, with item-level rejection isolation and no truncation-derived state.
- Authentication rejects unassigned Forward, endpoint, client, device session, desired generation, and set hash.
- Duplicate, old sequence/epoch/session, offline-queued, and observation-age-stale reports neither replace state nor refresh freshness.
- Two devices remain independently summarized.
- The ordered reducer tests every branch, including impossible phase/boolean combinations, no session, zero/one/multiple eligible nodes, insufficient bytes, imbalanced qualifying traffic, qualifying traffic, and exact 15-second staleness.
- Upload and download counters are separate; retransmit/control/probe/duplicate/cumulative bytes cannot produce `aggregating`.
- Reporter tests cover immediate events, 5-second active, 10-second idle, latest-only coalescing, bounded retry, retention, and revoked-device cleanup.

### 14.7 Retirement cleanup

For each agent role:

- Exact and look-alike fixtures cover units, content/environment, interfaces, files, leases, firewall/network resources, and local state. Only proven exact ownership is removed.
- A second leased generation with resources absent succeeds; duplicate/stale/forged tokens and acknowledgements perform no work or state mutation.
- Partial/failed command results preserve per-resource evidence, use master-owned backoff/lease, obey per-cycle work/time bounds, and later complete.
- Reconnect delivers the tombstone, executes serialized cleanup, and posts a result; partial/failed remains durable and retryable.
- Completion means exclusive resources and retired references are absent while proven keep-set shared resources may remain. Ambiguous ownership remains partial.
- Reappearance after completion opens exactly one new reconciliation generation before deletion.
- Malformed/missing retirement or desired-state sections never authorize cleanup; active/retired conflict fails closed.
- Result and ownership-snapshot size/credential-redaction limits and retention/compaction rules are enforced.

Shared-resource regression includes one deprecated and one active LeiGod Forward on the same hosts. Cleanup preserves the active static uplink, plain mux routes, client supervisor, port leases, sidecar bond server, and uninterrupted traffic, with no restart of shared services and no global WireGuard or generic systemd sweep.

### 14.8 Platform and rollout gates

- Linux passes all bond, supervisor, telemetry, and controlled throughput tests before bond-only enforcement.
- Authenticated capability/version/hash gates block stale online devices and require reconnecting offline devices to update before the new contract.
- Windows and Android parse only supported compatibility contracts and cannot receive/show Speed Plus until their independent full gate passes.
- Platform UI/API cannot emit `aggregating` from configured endpoints alone; UDP surfaces contain no aggregation claim.
- Canary gate failure leaves production contracts unchanged; batch failure triggers its predeclared abort/rollback and stops later batches.

### 14.9 Controlled Speed Plus acceptance

The campaign is predeclared and auditable. Freeze one Linux client installation, Forward/configuration generation, two-to-eight distinct-node leg cohort, binaries/hashes, token/route, supervisor process, runtime epoch, transport-session epoch, sidecar, target, and generator settings. Exactly one application TCP stream is used; parallel and bidirectional streams are prohibited. An in-process scheduler allowlist selects `{leg_i}` for an individual condition and the exact union of those same legs for the multi-leg condition. Excluded legs carry zero measured application payload. Republish, process restart, session replacement, transport substitution, or cohort change fails the campaign.

Run separate complete campaigns for upload and download; directions are never added. Reserve the client, participating slaves, sidecar, LeiGod carriers where controllable, and target against competing test/Forward load. Before each measurement, apply the same 10-second unmeasured warm-up and require selected legs `eligible`, outside cooldown/probation, and acknowledging productive traffic; then apply the same 5-second drain.

Use three counterbalanced paired rounds. Each round executes every individual-leg condition and the multi-leg condition once in a precommitted Latin-square-derived order, with at most 120 seconds between adjacent conditions and the multi-leg condition appearing in a different order third each round when the condition count permits. Every measurement sends deterministic counter-mode payload for exactly 60 seconds; it is valid only above 256 MiB. The sender commits final byte count plus SHA-256, and the receiver must match both exactly. Compute each condition's median over all three scheduled measurements; no run can be excluded after seeing throughput, distribution, retransmission, or recovery results.

A measurement fails, and is not replaced, for any runtime-caused payload mismatch, unexpected leg loss, restart, session/generation change, telemetry gap, test timeout, or failure to keep the declared allowlist. Only an independently logged harness/target outage outside all participating runtimes may void a round; the entire paired round is rerun once. A second external invalidation fails the campaign. Predeclared contamination limits are: any participating host above 90% CPU for more than 5% of samples, target/NIC above 90% utilization, or unrelated traffic above 1% of measured bytes. Crossing one voids the whole round under the same one-rerun rule.

Each direction passes only when all are true:

- every one of its three multi-leg measurements has at least two distinct slave nodes each carrying at least 10% of unique acknowledged application bytes,
- the multi-leg median exceeds the best individual-leg median by at least 10%,
- define each round-level improvement as `multi_leg / max(single_leg_i) - 1`; the lower nearest-rank 2.5th percentile over all 27 ordered size-three resamples of the three paired improvements is greater than 0%; this exact finite bootstrap supplements rather than relaxes the 10% median rule,
- every payload hash/count is exact, and runtime retransmit diagnostics remain below the predeclared ceiling of 5% of unique payload bytes.

Failure/removal and recovery are separate required scenarios, not throughput samples. During a 60-second transfer that sends at least 256 MiB, forcibly remove one declared leg: failure must be detected within 5 seconds, the stream must remain correct with no healthy ingress/bond/supervisor/systemd restart, and the leg must return through handshake/probation to `eligible` within its published connector backoff + cooldown + 10 seconds after connectivity is restored. Restart counters must match the injected process faults exactly; a network-only fault produces zero process restarts.

A direction-neutral Speed Plus claim requires both directional campaigns to pass. If CPU, LeiGod UoT, target, distribution, correctness, recovery, or uncertainty prevents the gate, Speed Plus is not accepted and product surfaces do not claim successful aggregation.

### 14.10 Repository verification

- PHP syntax and full PHP tests pass.
- Python agent tests pass.
- Rust bond and ingress tests pass.
- Desktop and Android contract/unit tests pass for any touched compatibility parsing.
- Artifact source and every served copy match in content, version, and hash.
- Documentation diff checks pass.

---

## 15. Acceptance criteria

The feature is complete only when all conditions hold:

1. Every ordinary product path supports only `leigod_mode`, and every section 3.2 guarded operation rejects either deprecated persisted source or a Forward/combination mismatch.
2. Historical legacy mode, identity, relationships, and audit evidence remain available while only defined lifecycle fields change.
3. Every Forward matching the authoritative legacy predicate is disabled and has one durable, bounded, credential-free tombstone plus complete attributable target eligibility.
4. Every currently known online affected client installation, slave, and sidecar target has an authenticated accepted `completed` reconciliation outcome. Partial/failed or mere delivery/acknowledgement does not satisfy this condition.
5. Offline and undiscovered eligible client work remains durable; reconnect performs authenticated delivery, serialized cleanup, and result posting.
6. On completed targets, exclusive Forward-owned WireGuard, systemd, network, lease, and generated-file resources and the retired Forward's shared references are absent. Proven keep-set shared resources may remain; ambiguous ownership is not complete.
7. Active LeiGod resources, shared-service process identity, and traffic survive the same cleanup cycle unchanged.
8. Every new, updated, or normalized LeiGod combination uses `bond + single + plain_tcp_mux` and never silently selects MPTCP, CF, WSS, classic sidecar, or direct self-leg fallback.
9. Publication accepts one-to-eight distinct slave nodes; one is ordinary LeiGod, while only two-to-eight is Speed Plus eligible. Every leg has stable `(forward_id, node_id, endpoint_id)` identity and distinct authenticated ingress over that slave's LeiGod static uplink.
10. The standalone bond runtime reassembles one target TCP stream correctly and works without kernel MPTCP support.
11. Port allocation is host-locked, collision-free against leases/listeners, atomically handed off, and generation-fenced with no formula-only ownership assumption.
12. Supervisor process restart, bond connector retry, and scheduler quarantine/probation have disjoint counters, deadlines, reset rules, persistent budgets, and bounded recovery; one fault cannot cause whole-Forward churn.
13. Multi-leg establishment failure, unproductive aggregation, or network-leg loss degrades the same application TCP stream to the deterministically selected best eligible slave when one remains, with zero application reconnect, target-session replacement, runtime-family fallback, or process restart; recovery does not rebuild unrelated runtime.
14. `aggregating` requires fresh authenticated same-device, desired-generation, runtime-epoch, transport-session, direction, and exact useful-byte-window evidence from at least two distinct slave nodes; invalid/ambiguous evidence is `unknown` and idle valid multi-leg runtime is `ready_multi_leg`.
15. The pre-enforcement controlled Linux canary passes both directional paired campaigns, exact payload integrity, per-node distribution, 10% median improvement, uncertainty, and bounded fault/recovery gates without post-hoc run selection.
16. Windows, Android, unsupported platform/protocol, and UDP surfaces receive no unverified aggregation claim or silent fallback.
17. Source and every served agent artifact, manifest, version, hash, and authenticated device capability agree before enforcement; stale/reconnecting agents update before receiving the contract.
18. Activation/normalization fault-injection, cleanup authentication/race/shared-resource tests, bond/runtime tests, and repository verification all pass.
19. No unresolved normalization blocker or failed cutover batch is hidden; rollout stops or rolls back according to section 12.
20. Production deployment occurs only after separate implementation-plan review and deployment authorization.

Known-target `completed` is open-world reconciliation, not proof that every historical installation is exhausted. While late-device eligibility remains open, operators must not claim fleet cleanup is globally complete; they report last discovery time and unresolved concrete targets instead.
