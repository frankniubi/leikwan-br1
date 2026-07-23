# LeiGod-Only Forward Mode, Platform Aggregation, and Legacy Runtime Retirement — Design Spec

**Date:** 2026-07-17

**Status:** Revised written specification pending implementation-plan review

**Baseline:** `master`

**Supersedes for active product behavior:** the `current_mode` (classic sidecar/CNIX) and `cf_mode` (163PP/Cloudflare) forward-mode paths, plus platform-agnostic LeiGod publication that ignored client aggregation capability and proof

---

## 1. Goal

Simplify the product so every newly created, edited, published, and delivered Forward combination uses one backend while selecting TCP aggregation by client platform capability:

```text
backend_mode = leigod_mode
aggregation_policy = platform_default
bond_ingress_mode = plain_tcp_mux
```

`platform_default` resolves to the standalone application-layer `leikwan-bond` family for Android and Windows. On Linux clients with verified kernel MPTCP support, it resolves first to native MPTCP and automatically falls back to standalone bond when fresh evidence fails to prove speed aggregation. Linux clients without verified MPTCP capability use standalone bond directly. The fallback is an explicit same-backend aggregation-family switch; it does **not** reactivate CF, WSS, classic sidecar, or direct self-leg delivery.

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
8. Preserve and harden multi-slave TCP aggregation under LeiGod using platform-appropriate aggregation: standalone `leikwan-bond` for Android and Windows, native MPTCP first on capable Linux, and standalone bond fallback when Linux MPTCP fails to aggregate speed.

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
- LeiGod TCP aggregation is platform-specific, not one-size-fits-all.
- Android and Windows use the standalone application-layer `leikwan-bond` implementation across distinct slave legs for normal aggregation.
- Linux clients with verified kernel MPTCP capability use native MPTCP first. If fresh evidence fails to prove MPTCP speed aggregation within the configured probation window, the same LeiGod Forward switches to standalone bond fallback and reports that family explicitly.
- Linux clients without verified kernel MPTCP capability use standalone bond directly.
- LeiGod publication normalizes backend and ingress to `backend_mode = leigod_mode` and `bond_ingress_mode = plain_tcp_mux`, while the client aggregation family is resolved from platform capability.
- Native MPTCP is permitted only for verified Linux MPTCP clients. It is never selected for Android or Windows and is never used as an undocumented fallback.
- The separate bond module embedded in `leikwan-mptcp-relay` is not the standalone bond implementation target because its non-direct legs still create native MPTCP sockets and may silently fall back between runtime families.
- One logical TCP leg is keyed by `(forward_id, node_id, endpoint_id)`. Array position, local port, process ID, and current IP address are attributes, not identity.
- Speed Plus state is derived from fresh family-native connection and byte-delivery evidence. Configured endpoint count and process liveness alone never prove aggregation.
- If standalone bond multi-leg aggregation is unavailable or unproductive, each existing application TCP stream remains in the standalone bond session and falls back to the best eligible slave leg. This is single-leg degradation, not a backend fallback.
- TCP continues with one healthy leg when possible. Losing or recovering one leg does not rebuild unrelated leg processes or unrelated Forward runtime.
- UDP may continue through the existing LeiGod compatibility path, but it is never described, displayed, or accepted as bandwidth aggregation.
- Speed Plus support is gated per platform/family: Android and Windows standalone bond, Linux native MPTCP first, Linux MPTCP-to-bond fallback, and Linux standalone bond each require their own data-path, recovery, telemetry, and throughput gates before showing Speed Plus as supported.

### 2.2 Why legacy rows are not converted

A valid LeiGod combination requires data old backend modes may not possess, including a positive `leigod_line_id`, a valid sidecar point, and usable LeiGod carrier credentials. Inventing those values would create apparently migrated but invalid routes. Operators must create a deliberate replacement LeiGod combination instead.

This differs from normalizing an already-valid `leigod_mode` combination to `platform_default`: its backend identity and LeiGod fields already exist, so it may be normalized only after a successful platform-aggregation preflight for the affected platform/family cohort.

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
- Treat native MPTCP as a Speed Plus prerequisite for Android, Windows, or Linux clients without verified MPTCP capability.
- Treat UDP duplication, racing, or reordering as bandwidth aggregation.
- Deploy to production as part of design approval. Deployment requires a separately approved implementation and rollout.

---

## 3. Product behavior

### 3.1 Forward combinations

The create/edit form no longer exposes backend-mode, client-mode, runtime-preference, or bond-ingress selectors. It behaves as a LeiGod form and shows only fields relevant to LeiGod delivery. The server assigns the backend and ingress defaults when fields are absent, then resolves the client aggregation family from the authenticated client platform and capability report. One distinct slave node is ordinary single-leg LeiGod; only two to eight scheduler-eligible distinct nodes may display or market Speed Plus.

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

An active `leigod_mode` Forward is publishable only when its combination passes platform aggregation preflight: valid sidecar and LeiGod fields, one to eight usable selected endpoints on distinct slave `node_id` values, valid per-endpoint ingress authentication, a resolvable aggregation target, and the required credentials/tokens for the selected family. Android and Windows require a valid TCP bond session token and sidecar bond target. Linux MPTCP-capable clients require valid native MPTCP underlay material plus the standalone bond fallback contract. A one-node configuration runs as ordinary single-leg LeiGod; two to eight nodes are Speed Plus eligible. Zero, duplicate-node, or oversized sets fail with `invalid_speed_plus_leg_set`. A failure is explicit and does not fall back to CF, WSS, classic sidecar, or direct self-leg behavior.

### 3.3 Defaults and migration compatibility

Database and application defaults become:

```text
backend_mode = leigod_mode
aggregation_policy = platform_default
bond_ingress_mode = plain_tcp_mux
```

The resolved client runtime is not guessed from missing legacy values. Android and Windows resolve to standalone bond. Linux resolves to native MPTCP first only when the client reports verified MPTCP capability and the publication includes a verified bond fallback contract; otherwise Linux resolves directly to standalone bond. Defaults do not rewrite historical legacy backend rows. Migration code identifies them by persisted value rather than treating a missing value as `current_mode`.

A small compatibility vocabulary remains in control-plane and agent code only to:

1. Detect legacy rows.
2. Return explicit deprecation errors.
3. Recognize and remove legacy runtime resources.
4. Read old applied-state manifests during retirement.

The goal is zero active legacy publication, not zero textual occurrences of old names.

### 3.4 Speed Plus: platform aggregation under LeiGod

Speed Plus preserves the useful bandwidth-composition property formerly associated with classic multi-slave sidecar delivery, but it runs inside the only supported backend mode. The aggregation family is platform-specific: Android and Windows use standalone bond for normal aggregation; Linux with verified kernel MPTCP uses native MPTCP first and falls back to standalone bond when MPTCP does not prove speed aggregation; Linux without verified MPTCP uses standalone bond directly.

#### Standalone bond data path

The authoritative standalone bond TCP data path is:

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

#### Linux native MPTCP first path

A Linux client may select native MPTCP first only when all of the following are true:

- the authenticated client capability report proves kernel MPTCP support, required sysctls, endpoint/subflow limits, and runtime artifact version/hash;
- the selected slave endpoints have valid MPTCP underlay material, addresses, routes, and authentication;
- the sidecar and slave desired state can realize the MPTCP underlays without colliding with active LeiGod static uplinks;
- the same publication also contains a complete standalone bond fallback contract over `plain_tcp_mux` for the same Forward, target, sidecar point, selected slave set, and LeiGod carrier fields.

Native MPTCP aggregation must produce fresh MPTCP-family connection, subflow, and byte-delivery evidence before it may be shown as `aggregating`. If the probation window expires without proving speed aggregation, or if native MPTCP falls back to one useful subflow under load, the client switches to the standalone bond fallback and reports the active family as `bond_fallback`. This fallback is expected behavior for Linux MPTCP, not a failure of the LeiGod backend and not a fallback to CF/WSS/classic sidecar.

#### Initial standalone bond transport scope

The first LeiGod-only standalone bond release has one active leg transport:

```text
plain_tcp_mux over selected slaves
```

For standalone bond publication:

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

Runtime state is per authenticated client-device session, Forward, and active aggregation family. Evidence from different devices, runtime epochs, transport sessions, or families is never merged. Every snapshot declares one active family: `bond`, `mptcp`, or `bond_fallback`. The master derives the desired distinct-node set from the exact published generation and derives eligible nodes only from canonical family-specific admission phases. Classification uses this ordered decision tree; the first matching rule wins:

1. Missing, stale, malformed, unsupported-platform, family-incoherent, out-of-order, desired-generation-mismatched, session-incoherent, or ambiguously mapped evidence is `unknown`.
2. Fresh valid evidence with no live active-family transport session or zero eligible slave nodes/subflows is `unavailable`.
3. One authoritative desired slave node with one eligible node/subflow is `single_leg_only`.
4. Multiple authoritative desired slave nodes with exactly one eligible node/subflow is `degraded_single_leg`.
5. At least two eligible distinct slave nodes/subflows with no complete traffic window, or with a complete window below 1,048,576 total useful bytes, is `ready_multi_leg`.
6. A complete window with at least 1,048,576 useful bytes but fewer than two nodes/subflows at the required share is `degraded_single_leg`; standalone-bond nonproductive legs enter scheduler quarantine/probation according to section 10.2, while Linux MPTCP nonaggregation enters its bond-fallback path.
7. At least two eligible distinct slave nodes/subflows with a qualifying traffic window is `aggregating`.

A qualifying traffic window is one complete client-computed monotonic interval `[T-10s, T)` wholly within the current runtime epoch and transport-session epoch. It contains at least 1,048,576 total unique application-payload bytes accepted by the active-family remote peer, credited exactly once to the family leg or MPTCP subflow carrying the finally accepted copy; at least two eligible legs/subflows on distinct `node_id` values each contribute at least 5% of the declared direction's total. Upload and download are classified separately and are never added together. Retransmissions, framing, ACK/control, attempted wire bytes, duplicates, and cumulative counters do not count. Session change, family change, counter decrease/wrap, sample gap, or an incomplete window can produce `ready_multi_leg` when connectivity is otherwise valid, never `aggregating`.

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
| Android | Publish standalone TCP bond over `plain_tcp_mux`. | Publish standalone TCP bond only when the LeiGod UDP compatibility sidepath passes preflight; otherwise reject the whole publication. | Publish only when that compatibility sidepath passes preflight. | Bond TCP only, subject to fresh bond evidence. |
| Windows Desktop | Publish standalone TCP bond over `plain_tcp_mux`. | Publish standalone TCP bond only when the LeiGod UDP compatibility sidepath passes preflight; otherwise reject the whole publication. | Publish only when that compatibility sidepath passes preflight. | Bond TCP only, subject to fresh bond evidence. |
| Linux with verified MPTCP capability | Publish native MPTCP first plus mandatory standalone bond fallback over `plain_tcp_mux`. | Publish native MPTCP first plus bond fallback only when the LeiGod UDP compatibility sidepath passes preflight; otherwise reject the whole publication. | Publish only when that compatibility sidepath passes preflight. | MPTCP TCP when proven; bond fallback TCP when MPTCP does not prove aggregation. |
| Linux without verified MPTCP capability | Publish standalone TCP bond over `plain_tcp_mux`. | Publish standalone TCP bond only when the LeiGod UDP compatibility sidepath passes preflight; otherwise reject the whole publication. | Publish only when that compatibility sidepath passes preflight. | Bond TCP only, subject to fresh bond evidence. |

The release does not publish `leikwan-bond --protocol udp` as Speed Plus and does not call UDP duplication, fanout, or reordering “aggregation.” An unsupported requested platform/protocol combination fails explicitly; it is never silently reduced to a different protocol or backend family. The only allowed runtime-family fallback is Linux verified-MPTCP to standalone bond when MPTCP fails to prove speed aggregation.

Android and Windows are formal standalone-bond Speed Plus targets after their platform gates pass. Linux has two formal gates: native-MPTCP-first with bond fallback for verified MPTCP clients, and standalone bond for non-MPTCP clients. Each platform/family must independently pass collision-free allocation, per-leg or per-subflow bounded recovery, authenticated telemetry, one-leg degradation/recovery, and controlled byte-distribution/throughput gates before product surfaces show `aggregating` for that platform/family.

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
platform aggregation contract + selected slave endpoints
      │
      ├─ Android/Windows/Linux-non-MPTCP standalone bond contract
      ├─ Linux verified-MPTCP native contract + mandatory bond fallback
      ├─ client supervisor manifest + port leases where bond is active or fallback-ready
      ├─ per-leg ingress workers for standalone bond
      ├─ native MPTCP underlay material for Linux MPTCP
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

### 5.5 Latest aggregation runtime snapshots

A lightweight authenticated endpoint, independent of the 30-second configuration pull/apply cycle, accepts `aggregation_runtime_snapshots`. The server issues and authenticates a device-session credential bound to the client identity; caller-supplied `device_id` text is diagnostic only and never keys or authorizes storage.

The master stores only the latest accepted snapshot for each `(client_id, device_session_id, forward_id, active_family)` in `forward_aggregation_runtime_snapshots`; it is not an append-only traffic log. Required fields are:

- schema and runtime-contract version,
- Forward ID, active desired-generation ID, active aggregation family, fallback reason when active family is `bond_fallback`, and canonical desired-leg-set hash,
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

The client-reported desired count and active family are diagnostic until checked against the authoritative platform contract. The master joins the generation/hash to authoritative desired state and derives membership, family eligibility, fallback eligibility, and distinct-node cardinality.

Each standalone bond leg contains its stable key, `node_id`, `endpoint_id`, diagnostics, and one canonical admission phase:

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

Each native MPTCP observation contains the same stable `forward_id/node_id/endpoint_id` mapping, subflow endpoint identity, subflow state, MPTCP token/session identity, byte-delivery deltas credited by subflow, fallback-probation deadline, and reason when the family must switch to bond fallback. The master rejects Linux MPTCP evidence that lacks the mandatory bond fallback contract for the same generation.

Cumulative, retransmitted, framing, duplicate, ACK/control, and attempted wire bytes never contribute to `aggregating`. Forward and reverse directions are reported separately; product state uses the direction in which the qualifying test/application traffic was observed and never adds the two directions together.

Ordering and ingestion rules are:

- The active desired generation, active family, fallback permission, and desired-leg-set hash must match the master's assignment.
- Accept only a lexicographically newer `(runtime_epoch, sequence)` for the same assignment and family. A duplicate, lower sequence, retired epoch, old desired generation, old set hash, family regression, or transport-session regression does not replace state or refresh receipt time. A traffic window must lie wholly within the reported transport-session epoch.
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

A separate idempotent command handles existing `leigod_mode` combinations and Forwards whose runtime contract differs from the platform aggregation contract.

For each combination it first performs a read-only preflight for:

- valid LeiGod line and sidecar point,
- usable point-shared sidecar bond server settings,
- one to eight usable selected endpoints on distinct slave `node_id` values; at least two for a Speed Plus claim,
- platform capability cohorts and minimum runtime artifact version/hash for Android, Windows, Linux MPTCP, and Linux bond;
- valid plain TCP mux route and authentication material for every standalone bond or bond-fallback leg,
- valid static sidecar uplink and LeiGod carrier fields for every emitted slave leg,
- valid TCP bond token and target for Android, Windows, Linux non-MPTCP, and Linux MPTCP fallback,
- valid native MPTCP underlay, endpoint/subflow limits, and telemetry contract for Linux MPTCP-first publication.

Only a successful preflight permits one transaction to update the combination and denormalized Forward columns to the platform aggregation contract and queue republish. A preflight failure leaves the active row unchanged, records a blocking audit result, and prevents declaring rollout complete. It never disables a valid LeiGod Forward merely because normalization failed.

Normalization may publish Linux native MPTCP first only with a complete standalone bond fallback contract. It does not touch legacy backend rows.

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
- Forward-owned bond, MPTCP, UoT, rate-limit, watchdog, and helper units, timers, restart configuration, restart manifests, and watchdog state files.
- `wg-quick@...` units and exact Forward-owned WireGuard interfaces/configuration.
- Legacy `lw-mptcp-<forward>-*`, `lw-cfwg-<forward>`, `lw-cfsr-<forward>`, and `lw-self-<forward>` resources.
- Forward-owned firewall rules, namespaces, veths, generated files, and applied-state manifests.

Local ownership evidence is retained per resource until that resource is either absent or its retired reference is removed while a keep-set-protected shared object remains. A partial/failed attempt never discards evidence needed by the next retry. After target completion, compact local state to a minimal Forward/generation completion watermark so permanent tombstone replay can detect restored legacy resources.

Active `leigod_mode` bond workers, snapshots, and port leases are in the keep-set and are never classified as legacy merely because their binary name contains `bond`.

### 8.3 Slave cleanup

The slave path adds Forward-level `retire_forward` alongside endpoint `destroy`. It removes exact Forward-owned:

- endpoint ingress, backend, relay, and selector units,
- classic/CF wstunnel, shadow backend, UoT, and MPTCP underlay units, interfaces, addresses, routes, and generated configuration,
- WireGuard interfaces and configuration,
- generated route files and local state,
- stale sidecar-uplink references attributable solely to the retired Forward.

A static sidecar uplink referenced by any active LeiGod endpoint remains. Endpoint destroy and Forward retirement may arrive in either order; missing resources are success.

### 8.4 Sidecar cleanup

The sidecar desired-state response gains explicit `runtime_retirements`. Sidecar cleanup covers exact Forward-owned:

- legacy MPTCP transit interfaces, MPTCP endpoints, endpoint addresses/routes, and helper state,
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

The existing bond snapshot contract becomes the aggregation snapshot contract rather than being replaced by unrelated kernel MPTCP telemetry. It carries the active family, canonical bond admission phase or MPTCP subflow state, transition sequence, fallback reason when applicable, and exact useful-byte window defined in section 5.5 rather than treating health scores or cumulative counters as state authority. Writes use temporary file, file sync, and same-filesystem atomic rename; a reader rejects partial JSON, writer-epoch mismatch, family mismatch, and regressed sequence. Local diagnostics may keep at most 20 transitions per leg/subflow, but periodic master reports contain only current state and the last bounded reason.

### 10.4 Collision-free ports

Every local leg listener uses the lease registry in section 5.6. Allocation preflights bind availability before changing the active generation. If no port is available in the configured range, only the affected leg fails with `local_port_exhausted`; existing healthy generations remain active. The allocator never kills an unknown listener to reclaim a formula-derived port.

### 10.5 Runtime-family fallback boundary

A failed standalone bond start, missing binary, malformed token, unsupported platform, or unsupported family reports an explicit error for that platform/family. It does not invoke `leikwan-mptcp-relay`, switch to `mptcp_preferred_bond_fallback`, restore WSS/CF, rewrite the backend mode, or invent another runtime. The only runtime-family fallback permitted by this design is a prepublished Linux verified-MPTCP contract switching to its authenticated standalone bond fallback after MPTCP fails to prove speed aggregation.

---

## 11. Control-plane simplification boundary

### 11.1 Remove from active product code

- Three-way backend-mode selection and legacy mode fields.
- New-save/edit behavior for `current_mode` and `cf_mode`.
- Legacy route selection, publication, republish, and reconciliation.
- CF candidate and classic CNIX builders unreachable from LeiGod retirement.
- CF location-group write UI and navigation.
- Native-MPTCP-first selection outside verified Linux MPTCP clients, and any MPTCP fallback not paired with explicit standalone bond fallback telemetry.
- Active LeiGod QUIC/WSS/self-leg bond-leg publication.
- Formula-only local bond port allocation.
- Whole-runtime `wait -n` supervision for LeiGod bond.
- Tests whose only purpose is successful legacy publication.

### 11.2 Preserve or adapt

- Sidecar-point management and validation.
- LeiGod static sidecar uplink realization.
- Point-shared sidecar bond server and token validation.
- Standalone `leikwan-bond` framing, retransmission, scheduling, and health foundation.
- Verified Linux native MPTCP underlay realization, telemetry, and explicit bond fallback selection.
- `leikwan-ingress` client/mux/backend plain TCP path.
- Slave/client/sidecar desired-state reconciliation.
- Existing UDP LeiGod compatibility sidepath without an aggregation claim.
- Legacy resource recognizers used by retirement cleanup.
- Read-only historical rows, audit diagnostics, and persisted schema values.
- Stable deprecation errors and guards.

Old active builders may be removed only after implementation proves retirement does not call them. Lightweight read-only teardown recognizers remain for as long as any permanent tombstone and late-device eligibility scope can be replayed; they cannot be pruned merely because currently known targets completed.

---

## 12. Rollout

The rollout prevents old agents from losing cleanup information and prevents active LeiGod traffic from being normalized before the platform aggregation runtimes and telemetry are available.

### Phase 1: retirement and aggregation-capable artifacts

1. Implement all three Forward-scoped cleanup entry points and acknowledgement contracts.
2. Implement the client supervisor, port leases, bounded recovery, generation fencing, aggregation snapshot v2, standalone bond telemetry, Linux MPTCP telemetry, and Linux MPTCP-to-bond fallback control.
3. Update source agents and every served mirror, including `artifacts/` and `master/public/artifacts/` where applicable.
4. Bump artifact versions and hashes consistently.
5. Publish artifacts and request fleet updates without changing active Forward contracts.
6. Require authenticated device/role capability reports containing platform, OS MPTCP capability, agent/runtime version, artifact content hash, retirement schema, aggregation snapshot contract, and fallback capability.
7. Confirm representative clients, slaves, and sidecars parse empty retirement lists without changing active runtime.

### Phase 2: pre-enforcement canaries and platform gates

1. Apply retirement/snapshot storage and authenticated reporting code without enabling legacy retirement or global platform aggregation normalization.
2. On isolated Android and Windows canary cohorts whose every participating client, slave, and sidecar reports the minimum version/hash/capability, run the standalone bond controlled gate in section 14.9.
3. On an isolated Linux MPTCP canary cohort, run the native-MPTCP-first gate and the forced MPTCP-failure bond-fallback gate in section 14.9.
4. On Linux clients without verified MPTCP capability, run the standalone bond gate in section 14.9.
5. Gate failure leaves existing production contracts unchanged and disables the affected platform/family Speed Plus claim; fix artifacts and rerun the full affected gate rather than selecting favorable runs.
6. Gate success is the entry condition for platform aggregation publication and normalization for that platform/family only.

### Phase 3: LeiGod-only control plane and bounded cutover

1. Deploy server-side LeiGod-only guards, hidden legacy UI, retirement delivery/acknowledgement, and platform aggregation publication.
2. Before returning a new contract, require every affected online role/device to report the minimum platform/version/hash/capability. A reconnecting offline device updates first; unsupported or stale devices receive an explicit update-required response, not a broken or unauthorized fallback contract.
3. Run LeiGod normalization preflight and resolve all blocked active combinations.
4. Normalize and republish in bounded batches per platform/family. Each batch has predeclared traffic/error/restart abort thresholds. If post-publish verification fails, rollback returns only that batch to its prior committed LeiGod contract as an emergency pre-feature state, disables that platform/family Speed Plus claim, records a blocker, and prevents acceptance until the batch is successfully normalized again.
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
- Client eligibility, known installations, every attributable historical slave/endpoint, and every attributable current/historical sidecar point are captured without broadcast or truncation. A regression retires a Forward while a client is matched through a group, then changes group membership before a previously unseen eligible installation reconnects; that installation still receives and completes the snapshotted retirement.
- Newly seen eligible installations atomically create a pending target after earlier known-target completion.
- Concurrent final completion, late discovery, duplicate acknowledgement, expired attempt, and reappearance-generation tests leave parent/child state consistent and monotonic.
- Active LeiGod Forwards receive no retirement tombstones.
- Existing valid LeiGod combinations normalize only after preflight; injected failure at each normalization write/queue step leaves persisted values, active runtime, and work visibility unchanged and records no partial success.

### 14.3 Publication and data path

- Deprecated Forwards never enter active client configs, normal slave tasks, sidecar routes, republish queues, route-selection sets, route-racing sets, or candidate-builder sets.
- LeiGod emits `backend_mode=leigod_mode`, platform-resolved aggregation family, and `ingress_mode=plain_tcp_mux` for standalone bond or bond fallback. Android and Windows emit standalone bond. Linux verified-MPTCP emits native MPTCP first plus a complete standalone bond fallback. Linux non-MPTCP emits standalone bond. No active QUIC, WSS, CF, self-leg, or unauthorized MPTCP leg is emitted.
- Publication accepts one-to-eight distinct slave nodes, labels one node ordinary LeiGod, labels two-to-eight Speed Plus eligible, and rejects zero/duplicate/oversized sets with `invalid_speed_plus_leg_set`.
- Every TCP leg or MPTCP subflow has unique `(forward_id, node_id, endpoint_id)` identity and per-endpoint ingress/auth material.
- Standalone bond publication includes sidecar point, LeiGod line/carrier configuration, static WireGuard-uplink identity, relay target, required `lguot`/`gost` material, sidecar bond target, route key, HMAC token, and signed bond token. Intentionally retired native-MPTCP fields are absent from Android/Windows bond payloads.
- Linux MPTCP publication includes native MPTCP underlay, endpoint/subflow limits, telemetry fields, and the complete standalone bond fallback payload for the same target and selected slave set.
- Slave plain mux authenticates route/token before backend connect; each backend uses its existing LeiGod static-uplink relay target; sidecar validation binds session to Forward, target, and protocol.
- Missing required fields fail explicitly without unauthorized runtime-family or backend fallback.
- TCP+UDP publication is atomic: a failed UDP compatibility preflight rejects the whole publication, and successful UDP sidepath has no aggregation state.

### 14.4 Bond framing and scheduling

- One application TCP stream and reverse traffic transfer correctly over multiple legs with in-order reassembly.
- Lost frames retransmit over another eligible leg, and useful-byte accounting credits only the finally accepted copy.
- Scheduler thresholds exactly implement `leigod_bond_v1`; small traffic uses one leg and bulk traffic warms additional legs.
- Best-leg selection orders eligible legs by scheduler penalty, measured smoothed RTT, and stable key; the exact 10% + 5 ms + three-window hysteresis prevents idle or noisy measurements from flapping selection.
- Cooling/quarantined/probation legs receive no normal bulk data.
- After Hello/ack, serialized probation sends exactly the bounded control probes, excludes them from target traffic/counters, passes within 5 seconds on three acknowledgements, or rotates the next due candidate after failure.
- Standalone-bond multi-leg establishment failure, one-leg loss, and nonproductive multi-leg windows preserve the same application stream and sidecar target session while pinning payload to the best eligible slave; the state becomes `degraded_single_leg`, not a runtime-family fallback.
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

- Snapshots carry authenticated device session, active aggregation family, Linux MPTCP fallback reason when applicable, desired generation/hash, supervisor identity, runtime epoch/sequence, nullable process/session epochs, canonical bond leg phase or native MPTCP subflow state, selected single-leg key/reason during degradation, applicable recovery counters/deadlines, and exact directional 10-second useful-byte window.
- Atomic writer crash tests never expose partial JSON or a stale writer epoch.
- Save/preflight rejects more than eight or duplicate-node legs. Reporting enforces 8 legs, 16 KiB per Forward, 64 Forwards/512 KiB per request, with item-level rejection isolation and no truncation-derived state.
- Authentication rejects unassigned Forward, endpoint, client, device session, desired generation, set hash, unauthorized active family, and Linux MPTCP evidence lacking the mandatory bond fallback contract.
- Duplicate, old sequence/epoch/session, offline-queued, and observation-age-stale reports neither replace state nor refresh freshness.
- Two devices remain independently summarized.
- The ordered reducer tests every branch, including impossible phase/boolean combinations, no session, zero/one/multiple eligible nodes, insufficient bytes, imbalanced qualifying traffic, qualifying traffic, and exact 15-second staleness.
- Upload and download counters are separate; bond retransmit/control/probe/duplicate/cumulative bytes and MPTCP retransmit/control/duplicate/cumulative counters cannot produce `aggregating`.
- Reporter tests cover immediate events, 5-second active, 10-second idle, latest-only coalescing, bounded retry, retention, and revoked-device cleanup.

### 14.7 Retirement cleanup

For each agent role:

- Exact and look-alike fixtures cover units, timers, content/environment, interfaces, MPTCP endpoints, MPTCP underlay addresses/routes, files, leases, watchdog restart state, firewall/network resources, and local state. Only proven exact ownership is removed.
- A second leased generation with resources absent succeeds; duplicate/stale/forged tokens and acknowledgements perform no work or state mutation.
- Partial/failed command results preserve per-resource evidence, use master-owned backoff/lease, obey per-cycle work/time bounds, and later complete.
- Reconnect delivers the tombstone, executes serialized cleanup, and posts a result; partial/failed remains durable and retryable.
- Completion means exclusive resources and retired references are absent while proven keep-set shared resources may remain. Ambiguous ownership remains partial.
- Reappearance after completion opens exactly one new reconciliation generation before deletion. A post-completion watchdog/reconcile cycle proves no retired CF/MPTCP runtime is recreated from leftover timers, manifests, or restart state.
- Malformed/missing retirement or desired-state sections never authorize cleanup; active/retired conflict fails closed.
- Result and ownership-snapshot size/credential-redaction limits and retention/compaction rules are enforced.

Shared-resource regression includes one deprecated and one active LeiGod Forward on the same hosts. Cleanup preserves the active static uplink, plain mux routes, client supervisor, port leases, sidecar bond server, and uninterrupted traffic, with no restart of shared services and no global WireGuard or generic systemd sweep.

### 14.8 Platform and rollout gates

- Android and Windows each pass standalone bond, supervisor, telemetry, one-leg degradation/recovery, and controlled throughput tests before their Speed Plus claim is enabled.
- Linux verified-MPTCP clients pass native-MPTCP-first telemetry and throughput tests, plus an injected MPTCP-nonaggregation case that switches to standalone bond fallback and then passes the bond gate.
- Linux clients without verified MPTCP capability pass the standalone bond gate and never receive native MPTCP publication.
- Authenticated capability/version/hash gates block stale online devices and require reconnecting offline devices to update before the new contract.
- Platform UI/API cannot emit `aggregating` from configured endpoints alone; UDP surfaces contain no aggregation claim; Android and Windows cannot receive native MPTCP; Linux MPTCP cannot receive a native-first contract without a verified bond fallback payload.
- Canary gate failure leaves production contracts unchanged for the affected platform/family; batch failure triggers its predeclared abort/rollback, records a blocker, disables the affected claim, and stops later batches.

### 14.9 Controlled Speed Plus acceptance

Each platform/family campaign is predeclared and auditable. Freeze one client installation, Forward/configuration generation, two-to-eight distinct-node leg cohort or MPTCP subflow cohort, binaries/hashes, token/route, supervisor process, runtime epoch, transport-session epoch, active aggregation family, sidecar, target, and generator settings. Exactly one application TCP stream is used; parallel and bidirectional streams are prohibited. An in-process scheduler or MPTCP endpoint allowlist selects `{leg_i}` for an individual condition and the exact union of those same legs for the multi-leg condition. Excluded legs carry zero measured application payload. Republish, process restart, session replacement, unauthorized family substitution, or cohort change fails the campaign.

Run separate complete campaigns for upload and download; directions are never added. Reserve the client, participating slaves, sidecar, LeiGod carriers where controllable, and target against competing test/Forward load. Before each measurement, apply the same 10-second unmeasured warm-up and require selected legs `eligible`, outside cooldown/probation, and acknowledging productive traffic; then apply the same 5-second drain.

Use three counterbalanced paired rounds. Each round executes every individual-leg condition and the multi-leg condition once in a precommitted Latin-square-derived order, with at most 120 seconds between adjacent conditions and the multi-leg condition appearing in a different order third each round when the condition count permits. Every measurement sends deterministic counter-mode payload for exactly 60 seconds; it is valid only above 256 MiB. The sender commits final byte count plus SHA-256, and the receiver must match both exactly. Compute each condition's median over all three scheduled measurements; no run can be excluded after seeing throughput, distribution, retransmission, or recovery results.

A measurement fails, and is not replaced, for any runtime-caused payload mismatch, unexpected leg loss, restart, session/generation change, telemetry gap, test timeout, or failure to keep the declared allowlist. Only an independently logged harness/target outage outside all participating runtimes may void a round; the entire paired round is rerun once. A second external invalidation fails the campaign. Predeclared contamination limits are: any participating host above 90% CPU for more than 5% of samples, target/NIC above 90% utilization, or unrelated traffic above 1% of measured bytes. Crossing one voids the whole round under the same one-rerun rule.

Each direction passes only when all are true:

- every one of its three multi-leg measurements has at least two distinct slave nodes each carrying at least 10% of unique acknowledged application bytes,
- the multi-leg median exceeds the best individual-leg median by at least 10%,
- define each round-level improvement as `multi_leg / max(single_leg_i) - 1`; the lower nearest-rank 2.5th percentile over all 27 ordered size-three resamples of the three paired improvements is greater than 0%; this exact finite bootstrap supplements rather than relaxes the 10% median rule,
- every payload hash/count is exact, and runtime retransmit diagnostics remain below the predeclared ceiling of 5% of unique payload bytes.

Failure/removal and recovery are separate required scenarios, not throughput samples. During a 60-second transfer that sends at least 256 MiB, forcibly remove one declared leg/subflow: failure must be detected within 5 seconds, the stream must remain correct with no healthy ingress/bond/supervisor/systemd restart for bond, and no unauthorized backend-family switch for MPTCP. Bond legs must return through handshake/probation to `eligible` within their published connector backoff + cooldown + 10 seconds after connectivity is restored. MPTCP subflows must either recover within the published MPTCP probation window or switch to the prepublished bond fallback and then pass the bond recovery gate. Restart counters must match the injected process faults exactly; a network-only fault produces zero process restarts.

Linux MPTCP-first acceptance also injects a nonaggregation case: native MPTCP establishes but fails the byte-distribution or 10% improvement rule while at least two bond fallback legs remain usable. The client must switch to `bond_fallback`, preserve the application stream when protocol conditions allow, emit the fallback reason in telemetry, and then satisfy the standalone bond controlled gate without CF/WSS/classic-sidecar/self-leg fallback.

A direction-neutral Speed Plus claim requires both directional campaigns to pass for the advertised platform/family. If CPU, LeiGod UoT, target, distribution, correctness, recovery, fallback behavior, or uncertainty prevents the gate, Speed Plus is not accepted and product surfaces do not claim successful aggregation for that platform/family.

### 14.10 Repository verification

- PHP syntax and full PHP tests pass.
- Python agent tests pass.
- Rust bond and ingress tests pass.
- Desktop and Android shared Forward publication contract/unit tests pass regardless of whether platform files were touched.
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
8. Every new, updated, or normalized LeiGod combination uses `backend_mode=leigod_mode`, `aggregation_policy=platform_default`, and `plain_tcp_mux` for standalone bond or bond fallback. Android and Windows resolve to standalone bond. Linux verified-MPTCP resolves to native MPTCP first with mandatory bond fallback. Linux without verified MPTCP resolves to standalone bond. No path silently selects CF, WSS, classic sidecar, direct self-leg, or unauthorized MPTCP fallback.
9. Publication accepts one-to-eight distinct slave nodes; one is ordinary LeiGod, while only two-to-eight is Speed Plus eligible. Every bond leg or MPTCP subflow has stable `(forward_id, node_id, endpoint_id)` identity and distinct authenticated ingress or underlay over that slave's LeiGod static uplink/carrier.
10. The standalone bond runtime reassembles one target TCP stream correctly and works without kernel MPTCP support; Linux native MPTCP aggregation proves useful multi-subflow byte delivery before claiming aggregation and switches to bond fallback when it does not.
11. Port allocation is host-locked, collision-free against leases/listeners, atomically handed off, and generation-fenced with no formula-only ownership assumption.
12. Supervisor process restart, bond connector retry, and scheduler quarantine/probation have disjoint counters, deadlines, reset rules, persistent budgets, and bounded recovery; one fault cannot cause whole-Forward churn.
13. Standalone-bond multi-leg establishment failure, unproductive aggregation, or network-leg loss degrades the same application TCP stream to the deterministically selected best eligible slave when one remains, with zero application reconnect, target-session replacement, unauthorized runtime-family fallback, or process restart; recovery does not rebuild unrelated runtime. Linux native MPTCP nonaggregation switches only to the prepublished authenticated bond fallback and reports `bond_fallback`.
14. `aggregating` requires fresh authenticated same-device, desired-generation, active aggregation family, runtime-epoch, transport-session, direction, and exact useful-byte-window evidence from at least two distinct slave nodes/subflows in the same family; invalid/ambiguous/family-incoherent evidence is `unknown` and idle valid multi-leg runtime is `ready_multi_leg`.
15. Pre-enforcement controlled gates pass for Android standalone bond, Windows standalone bond, Linux verified-MPTCP native-first, Linux forced MPTCP-nonaggregation bond fallback, and Linux non-MPTCP standalone bond. Each gate proves both directional paired campaigns, exact payload integrity, per-node/subflow distribution, 10% median improvement where aggregation is claimed, uncertainty, and bounded fault/recovery behavior without post-hoc run selection.
16. Android and Windows receive standalone bond aggregation only and never native MPTCP; Linux without verified MPTCP receives standalone bond only; Linux verified-MPTCP receives native-first only with a complete bond fallback payload; unsupported platform/protocol and UDP surfaces receive no unverified aggregation claim or silent backend fallback.
17. Source and every served agent artifact, manifest, version, hash, and authenticated device capability agree before enforcement; stale/reconnecting agents update before receiving the contract.
18. Activation/normalization fault-injection, cleanup authentication/race/shared-resource tests, bond/runtime tests, and repository verification all pass.
19. No unresolved normalization blocker or failed cutover batch is hidden; rollout stops or rolls back according to section 12.
20. Production deployment occurs only after separate implementation-plan review and deployment authorization.

Known-target `completed` is open-world reconciliation, not proof that every historical installation is exhausted. While late-device eligibility remains open, operators must not claim fleet cleanup is globally complete; they report last discovery time and unresolved concrete targets instead.
