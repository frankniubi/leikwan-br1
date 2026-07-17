# LeiGod-Only Forward Mode and Legacy Runtime Retirement — Design Spec

**Date:** 2026-07-17

**Status:** Design approved; pending implementation plan

**Baseline:** `master`
**Supersedes for active product behavior:** the `current_mode` (classic sidecar/CNIX) and `cf_mode` (163PP/Cloudflare) forward-mode paths

---

## 1. Goal

Simplify the product so every newly created, edited, published, and delivered forward combination uses:

```text
backend_mode = leigod_mode
```

The two legacy backend modes become permanently deprecated:

- `current_mode`: the former classic sidecar/CNIX mode.
- `cf_mode`: the former 163PP/Cloudflare mode.

Deprecation is operational, not cosmetic. The system must:

1. Hide both deprecated modes from ordinary admin, tenant, and client surfaces.
2. Reject attempts to create, save, publish, republish, copy, or reactivate them.
3. Preserve existing database rows for audit and rollback analysis.
4. Disable existing forwards that use either deprecated mode.
5. Remove runtime resources created by those forwards from every affected client, slave, and sidecar host.
6. Persist cleanup intent so an offline host cleans itself when it reconnects.
7. Preserve every resource still required by an active `leigod_mode` forward.

The design removes legacy **product modes and active publication paths**. It does not remove the sidecar subsystem: `leigod_mode` still requires a `sidecar_point_id` and uses sidecar agents, WireGuard overlays, static sidecar uplinks, MPTCP transit, and LeiGod `lguot`/`gost` services.

---

## 2. Decisions and non-goals

### 2.1 Locked decisions

- `leigod_mode` is the only supported backend mode.
- Historical legacy rows remain unchanged and queryable for audit.
- Legacy combinations are not automatically converted to `leigod_mode`.
- Legacy forwards are disabled and retired, not deleted.
- Cleanup is scoped by forward ownership; it is never a fleet-wide WireGuard or systemd purge.
- Cleanup is at-least-once and idempotent.
- A durable retirement record remains available to devices that were offline during rollout.
- Normal operation must not wait for every offline host to acknowledge cleanup.
- Shared LeiGod infrastructure survives whenever an active LeiGod desired state still references it.

### 2.2 Why legacy rows are not converted

A valid LeiGod combination requires data the old modes may not possess, including a positive `leigod_line_id`, a valid sidecar point, and stable LeiGod SOCKS credentials. Inventing those values would create apparently migrated but invalid routes. Operators must create a deliberate replacement LeiGod combination instead.

### 2.3 Non-goals

This change does not:

- Delete historical `forward_combinations`, `forwards`, CF location groups, or audit records.
- Remove sidecar-point administration or the sidecar agent.
- Remove WireGuard or MPTCP support used by LeiGod.
- Rename a deprecated mode to make it appear supported.
- Automatically move customers to a guessed LeiGod route.
- Block normal LeiGod publication while an unrelated host remains offline.
- Guarantee cleanup of unknown resources whose ownership cannot be proven.

---

## 3. Product behavior

### 3.1 Forward combinations

The create/edit form no longer exposes a backend-mode selector. It behaves as a LeiGod form and shows only fields relevant to LeiGod delivery. The server assigns `leigod_mode` when the field is absent.

An explicit request containing `current_mode` or `cf_mode` fails with a stable API error such as:

```text
deprecated_forward_combination_backend_mode
```

Unknown values continue to fail as invalid input. A legacy combination cannot be edited through the ordinary save path, even when the submitted payload omits `backend_mode`; otherwise an edit could accidentally reactivate it as LeiGod. The operator must create a new LeiGod combination.

Ordinary combination lists return only `leigod_mode`. Historical legacy combinations appear only in an operations-only retirement view clearly marked deprecated; that view is read-only and cannot publish, clone, edit, or reactivate them.

### 3.2 Forwards and routes

Every path that creates, updates, selects, publishes, republishes, realizes, or builds client configuration for a forward must enforce the LeiGod-only invariant on the server. Frontend hiding is not a security or correctness boundary.

Forwards whose persisted `backend_mode` is `current_mode` or `cf_mode` are:

- Set to `enabled = 0`.
- Excluded from active route and tenant listings.
- Excluded from client configurations and all normal slave/sidecar desired state.
- Rejected by update, republish, endpoint reconciliation, and client-config construction paths.
- Retained with their original mode and relationships for audit.

A deprecated combination is not eligible when creating a route. Existing routes that point to it remain historical and non-publishable.

### 3.3 Defaults and compatibility

Database defaults and application defaults change from `current_mode` to `leigod_mode`. This applies to `forward_combinations`, `forwards`, model defaults, payload normalization, and UI initialization.

Defaults must not rewrite existing rows. Migration code identifies legacy rows by their persisted value rather than treating a missing value as `current_mode`.

A small compatibility vocabulary remains in control-plane and agent code for three purposes only:

1. Detect legacy rows.
2. Return explicit deprecation errors.
3. Recognize and remove legacy runtime resource names.

The goal is zero active legacy publication, not zero textual occurrences of the old mode names.

### 3.4 CF administration

CF location-group navigation and ordinary create/edit controls are removed because no supported forward mode consumes them. Existing tables and records remain for audit and are referenced from the operations-only retirement view when relevant. Direct write requests to legacy CF administration routes return HTTP 410 with a stable deprecated-feature error. No separate editable or ordinary CF location-group view remains.

---

## 4. Architecture

The recommended architecture combines a durable retirement ledger with the agents' existing desired-state and orphan-cleanup machinery.

```text
legacy row scan
      │
      ▼
master retirement transaction
  ├─ disable legacy forward
  ├─ create permanent forward tombstone
  ├─ snapshot attributable runtime ownership
  ├─ queue existing endpoint destroy tasks
  └─ create delivery targets for client/slave/sidecar identities
      │
      ├──────── client pull ───────► forward-scoped teardown ─┐
      ├──────── slave task/pull ───► forward-scoped teardown ─┤
      └──────── sidecar pull ──────► forward-scoped teardown ─┤
                                                              ▼
                                             idempotent result acknowledgement
                                                              │
                                                              ▼
                                               pending/completed/partial/failed
```

The ledger supplies durability, observability, and offline delivery. Existing cleanup functions supply the actual resource deletion. Authoritative empty desired-state sections remain useful, but they do not replace the ledger because the current agents do not share one uniform absence/empty-list contract and cannot otherwise prove eventual cleanup.

---

## 5. Data model

### 5.1 `forward_runtime_retirements`

One permanent tombstone exists for each retired forward.

Required fields:

| Field | Meaning |
|---|---|
| `id` | Retirement identifier. |
| `forward_id` | Historical forward; unique to make migration idempotent. |
| `service_id` | Historical service ownership. |
| `backend_mode` | Original deprecated mode. |
| `reason` | Stable value such as `backend_mode_deprecated`. |
| `status` | Aggregate status: `pending`, `in_progress`, `partial`, or `completed`. |
| `ownership_snapshot_json` | Forward-scoped endpoint, sidecar, interface, unit, and managed-path evidence known at retirement time. |
| `retired_at` | Time active publication was disabled. |
| `created_at`, `updated_at` | Audit timestamps. |

The tombstone is not deleted after completion. It remains an audit record and a replay source for a previously unseen device using an existing client identity.

### 5.2 `forward_runtime_retirement_targets`

This table records delivery and acknowledgement per agent identity.

Required fields:

| Field | Meaning |
|---|---|
| `id` | Target identifier. |
| `retirement_id` | Parent tombstone. |
| `role` | `client`, `slave`, or `sidecar`. |
| `target_id` | Role-specific database identity. |
| `device_id` | Client device identity when known; empty for role-level seed targets. |
| `status` | `pending`, `completed`, `partial`, or `failed`. |
| `attempt_count` | Number of reported attempts. |
| `last_error` | Last actionable error. |
| `result_json` | Counts and bounded resource summaries. |
| `first_seen_at`, `last_attempt_at`, `completed_at` | Delivery lifecycle. |
| `created_at`, `updated_at` | Audit timestamps. |

A unique key covers `(retirement_id, role, target_id, device_id)` using a normalized non-null device identity. Repeated migration and repeated pull therefore do not duplicate targets.

### 5.3 Client devices and late discovery

A Forward may match multiple clients by direct client ID or client-group assignment. A client token can also be used by multiple devices over time. Retirement therefore uses two layers:

1. At retirement creation, snapshot every matching registered client identity, every historical endpoint/slave, and the associated sidecar point.
2. When a client device pulls, materialize or update a device-specific target before returning applicable tombstones.

Completion by one client device does not suppress the tombstone for a newly seen device. The master continues returning the tombstone whenever that device has not acknowledged it. Tombstones remain bounded by the client identities that the historical Forward matched; they are not broadcast to unrelated tenant clients.

### 5.4 Aggregate status

The retirement aggregate is:

- `pending` when no target has attempted cleanup.
- `in_progress` when at least one target has attempted and unresolved targets remain.
- `partial` when a reachable target reports resources it could not safely or successfully remove.
- `completed` when every currently known target is completed.

`completed` does not delete the tombstone. A newly discovered eligible device creates a new pending target and moves the aggregate status back to `in_progress` until that device acknowledges cleanup.

---

## 6. Creating retirements

A versioned, idempotent CLI activation command performs legacy retirement after the schema and retirement-capable control plane are deployed. The schema migration creates storage and changes defaults but does not enqueue remote retirement work. For each `forwards.backend_mode IN ('current_mode', 'cf_mode')`, including already-disabled rows that may still have runtime residue, the activation command runs one database transaction:

1. Locks the Forward row.
2. Creates or reuses its tombstone.
3. Captures ownership evidence before relationships can change.
4. Sets `forwards.enabled = 0`.
5. Marks active service endpoints as deleting and queues the existing endpoint-level `destroy` tasks.
6. Creates retirement targets for all directly assigned clients, clients matching historical group assignments, all historical endpoint nodes, and the bound sidecar point.
7. Writes an audit event.

The transaction does not perform remote operations. Agent delivery happens after commit.

The activation command can be rerun safely. It must report counts for scanned, newly retired, already retired, and failed rows. A failure on one Forward must not silently mark that Forward complete.

Existing endpoint-level `destroy` remains in use because it is already integrated with slave task leasing and acknowledgements. It is treated as one teardown mechanism, not proof that every Forward-owned resource on every role has been removed.

---

## 7. Agent contract

### 7.1 Common retirement object

All roles consume a semantically equivalent object:

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

The actual payload may contain role-specific subsets, but `retirement_id`, `forward_id`, `reason`, and explicit ownership scope are mandatory. Empty arrays mean that no additional snapshot evidence is available; they do not authorize global pattern deletion.

Before deleting anything, each agent builds the active keep-set from the same successfully parsed response. If a Forward ID appears in both active desired state and retirement state, the agent performs no deletion for that ID and reports `desired_state_conflict`.

### 7.2 Delivery semantics

- Delivery is at-least-once.
- A target remains deliverable after failures and partial results.
- Already-missing resources count as successfully reconciled.
- `completed` means the role's known Forward-owned resources are absent and no keep-set conflict exists.
- A malformed response or an absent retirement section never authorizes cleanup.
- An explicitly present empty desired-state section retains its existing role-specific authoritative meaning.
- Normal LeiGod apply/reconcile work continues even when one retirement fails.

### 7.3 Acknowledgement

Agents post a bounded result containing:

- Retirement and Forward IDs.
- Agent role and authenticated identity.
- Device ID for client agents.
- Status: `completed`, `partial`, or `failed`.
- Counts for stopped units, disabled units, deleted interfaces, deleted files, already absent resources, keep-set skips, and errors.
- Short resource names and error messages, with strict size limits.

The master derives role and target identity from authentication rather than trusting IDs in the request body. Acknowledging one target cannot complete another target.

---

## 8. Forward-scoped cleanup

Each agent exposes one internal entry point with equivalent behavior:

```text
retire_forward_runtime(forward_id, ownership_scope, active_keep_set)
```

It may call existing cleanup helpers, but it owns ordering, safety checks, result collection, and idempotency.

### 8.1 Ownership rules

An agent may delete a resource only when at least one condition proves Forward ownership:

- Its canonical name embeds the exact Forward ID according to a recognized managed naming scheme.
- It is listed in the retirement ownership snapshot.
- It appears in the agent's local applied-state manifest or `managed_paths` for that Forward.
- It is a systemd unit whose configuration or environment contains the exact managed Forward identity and whose unit name matches a supported managed prefix.

Every candidate is compared with the active LeiGod keep-set before deletion. An active reference wins. Resources that cannot be attributed safely remain in place and produce `partial`, not a broad best-effort deletion.

No cleanup implementation may:

- Stop every `wg-quick@*` unit.
- Delete every WireGuard interface.
- Delete all units sharing only a generic `leikwan` prefix.
- Interpret a missing payload section as a command to purge.
- Remove a shared sidecar uplink while an active LeiGod desired state references it.

### 8.2 Client cleanup

The client agent extends `stop_forward()` and `cleanup_mptcp_forward_runtime()` to cover all recognized runtime families for the retired Forward:

- Forward-level systemd units and start scripts.
- `wg-quick@...` units.
- `lw-mptcp-<forward>-*`, `lw-cfwg-<forward>`, `lw-cfsr-<forward>`, and `lw-self-<forward>` interfaces and configuration.
- Forward-owned MPTCP and UoT helper units.
- Forward-owned nftables/iptables rules.
- Per-Forward network namespace and veth resources.
- Applied-state manifests and managed configuration paths.
- Legacy CF watchdog state and restart configuration for the Forward.

The agent removes local applied state only after teardown has been attempted, so retries still have ownership evidence after a partial failure.

### 8.3 Slave cleanup

The slave path adds a Forward-level `retire_forward` action alongside existing endpoint `destroy` work. It cleans:

- Endpoint ingress, relay, and selector units.
- CF, wstunnel, shadow-backend, and UoT units.
- MPTCP underlay units and interfaces.
- `wg-quick@...` units, WireGuard interfaces, and configurations.
- Forward-specific generated files and local state.
- Stale sidecar-uplink references attributable to the Forward.

A sidecar uplink shared with an active LeiGod Forward remains in the keep-set. Endpoint `destroy` and Forward retirement may arrive in either order; both paths must treat missing resources as success.

### 8.4 Sidecar cleanup

The sidecar desired-state response gains an explicit `runtime_retirements` section. Sidecar cleanup covers Forward-owned:

- MPTCP transit interfaces and endpoints.
- Slave-transit CF backends.
- Relay and selector units.
- Legacy LeiGod `gost`/`lguot` residue associated with the old Forward.
- WireGuard units, interfaces, and configuration.
- Static-uplink references and generated state.

Existing semantics remain intact: a missing section is not authoritative; a present empty authoritative desired-state list can request orphan reconciliation; explicit retirement objects request targeted Forward cleanup.

---

## 9. Shared-resource safety

Shared infrastructure is reconciled by reference, not by deprecated mode name.

For each agent cycle:

1. Parse and validate active LeiGod desired state.
2. Build a keep-set of interface names, unit names, ports, files, sidecar uplinks, and MPTCP endpoint identities required by that state.
3. Build retirement candidates only from exact Forward ownership evidence.
4. Subtract the active keep-set.
5. Delete only the remainder.
6. Report keep-set skips separately from deletion failures.

A resource skipped because of an active reference is not an error if the shared object itself is still valid; its retired Forward reference is removed from local state and the result records the preservation. A resource with ambiguous ownership is `partial` until attribution becomes safe.

This is the critical invariant:

> Running retirement for every `current_mode` and `cf_mode` Forward must leave the runtime produced by all active `leigod_mode` Forwards unchanged.

---

## 10. Control-plane simplification boundary

### 10.1 Remove from active product code

- The three-way backend-mode selector and legacy mode fields.
- New-save and edit behavior for `current_mode` and `cf_mode`.
- Legacy mode selection in route creation.
- CF candidate and classic CNIX construction paths that cannot be reached by LeiGod.
- Legacy ingress publication and republish branches.
- CF location-group write UI and navigation.
- `current_mode` defaults in PHP, Python, and Desktop models.
- Tests whose only purpose is to assert successful legacy publication.

### 10.2 Preserve or adapt

- Sidecar-point management and validation.
- LeiGod static sidecar uplink realization.
- WireGuard overlay and MPTCP transit used by LeiGod.
- Sidecar/slave/client desired-state reconciliation.
- Legacy resource-name recognizers used by retirement cleanup.
- Read-only historical rows and audit diagnostics.
- Schema compatibility with persisted legacy mode values.
- Stable deprecation errors and guards.

Old active builders may be removed only after the implementation proves that teardown does not call them. Lightweight teardown recognizers remain indefinitely or until operations confirms no relevant historical host can return.

---

## 11. Rollout

The rollout is ordered to ensure old agents do not lose the information required to clean themselves.

### Phase 1: Retirement-capable agents

1. Implement and test all three agent cleanup entry points and acknowledgement contracts.
2. Update the source agent plus every downloadable mirror, including `artifacts/` and `master/public/artifacts/` where applicable.
3. Bump artifact versions and hashes consistently.
4. Publish the agents and request fleet updates.
5. Confirm representative client, slave, and sidecar agents can parse an empty retirement list without changing active runtime.

### Phase 2: LeiGod-only control plane

1. Apply schema additions and defaults.
2. Deploy server-side LeiGod-only guards and hidden legacy UI.
3. Deploy retirement delivery and acknowledgement endpoints.
4. Run the idempotent retirement activation command.
5. Confirm deprecated forwards disappear from every normal pull and publication path.

### Phase 3: Verification and pruning

1. Observe target acknowledgements for all online hosts.
2. Verify on-host unit, interface, and file state for representative legacy Forwards.
3. Verify active LeiGod traffic and runtime before and after cleanup.
4. Leave offline targets pending and replayable.
5. Remove unreachable legacy active-builder branches after cleanup compatibility is proven, without removing legacy teardown recognizers.

The migration must not deploy partially: control-plane enforcement is enabled only after compatible agent artifacts are available. Production deployment itself requires the normal separate deployment authorization and implementation-plan gates.

---

## 12. Error handling and observability

- Retirement creation, disabling, snapshotting, and initial target creation are atomic per Forward.
- Remote operations never occur inside the database transaction.
- Repeated cleanup is expected and does not increase error severity when resources are already absent.
- A single target failure does not block LeiGod publication or other targets.
- Partial cleanup keeps the target retryable and stores the exact skipped/failed category.
- Offline targets remain pending without timeout-based deletion.
- Result payloads are bounded to prevent logs or database rows from growing without limit.
- Authentication determines the reporting agent identity.
- Audit logs record activation batch, Forward ID, original mode, target counts, and aggregate status changes.
- An operations-only retirement view reports counts by role and status without restoring legacy product controls.

The design does not equate “task delivered” with “cleanup complete.” Only an authenticated successful reconciliation acknowledgement completes a target.

---

## 13. Testing strategy

### 13.1 Control-plane contract tests

- Combination forms contain no `current_mode` or `cf_mode` option.
- LeiGod fields remain available and required.
- A save with no backend mode creates `leigod_mode`.
- Explicit deprecated modes return the deprecation error.
- Unknown modes return the invalid-mode error.
- A legacy combination cannot be edited, cloned, selected, published, or republished.
- Ordinary combination, route, tenant, and client lists omit deprecated rows.
- CF write surfaces are absent or return HTTP 410.
- Sidecar-point administration remains available.

### 13.2 Migration and persistence tests

- Existing legacy rows keep their original backend mode and identifying data.
- Every legacy Forward is disabled.
- Both enabled and already-disabled legacy Forwards receive one tombstone.
- Re-running activation creates no duplicate retirement or target rows.
- Matching client identities, endpoint nodes, and sidecar points are snapshotted.
- A newly seen eligible client device receives a device target after aggregate completion.
- Active LeiGod forwards do not receive tombstones.

### 13.3 Publication tests

- Deprecated Forwards never enter client configs, slave tasks except retirement/destroy, sidecar desired state except retirement, route-racing candidates, or republish queues.
- `leigod_mode` still emits its required sidecar, MPTCP, WireGuard, line, and `lguot` fields.
- Missing or malformed retirement sections do not authorize deletion.
- Active/retired Forward ID conflicts fail closed and report a conflict.

### 13.4 Agent cleanup tests

For each agent role:

- A Forward-specific fixture creates representative units, interfaces, files, and local state.
- Retirement removes only those resources.
- A second identical retirement succeeds with everything already absent.
- A simulated command failure returns `partial` or `failed` and preserves retry evidence.
- A later retry completes the target.
- An offline target receives the same tombstone after reconnect.
- Resource and error summaries respect payload limits.

### 13.5 Shared-resource regression tests

- One deprecated Forward and one active LeiGod Forward share a sidecar host.
- Cleanup removes the deprecated runtime while preserving the LeiGod keep-set.
- A shared sidecar uplink remains active when LeiGod references it.
- No global `wg-quick@*`, WireGuard, or generic systemd sweep occurs.
- Client group reassignment after retirement does not lose the original target snapshot.

### 13.6 Repository verification

- PHP syntax and the full PHP test suite pass.
- Python agent tests pass.
- Desktop contract/unit tests pass where model defaults or compatibility parsing change.
- Android contract tests remain green; Android currently has no direct backend-mode branch.
- Agent source and all served artifact copies have matching content, versions, and hashes.

---

## 14. Acceptance criteria

The feature is complete only when all of the following are true:

1. Every ordinary product path supports only `leigod_mode`.
2. `current_mode` and `cf_mode` cannot be newly saved, edited, selected, published, republished, or reactivated.
3. Historical legacy rows remain available for audit.
4. Every legacy Forward has been disabled and has a durable retirement tombstone.
5. Online affected clients, slaves, and sidecars have acknowledged Forward-scoped cleanup.
6. Offline targets remain durably pending and receive cleanup after reconnect.
7. Old Forward-owned WireGuard, systemd, network, and generated-file resources are absent on acknowledged hosts.
8. Active LeiGod resources and traffic survive the same reconciliation cycle unchanged.
9. Agent artifacts are synchronized across every served copy.
10. All relevant automated tests pass.

A fleet may contain pending offline targets after rollout; that does not keep deprecated modes active. It does prevent operators from claiming that fleet cleanup is globally complete.
