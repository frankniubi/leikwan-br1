# CF Bond Client Health Scoring Design

## Goal

Apply the existing normal bond-mode client health scoring behavior to CF bond slave-leg selection, without changing slave-side CF IP selection, so that bad CF bond slave legs are deprioritized or quarantined on the next render/restart.

## Problem

Current `bond + cf_mode` behavior still routes through the standard client bond runtime, and that runtime emits useful per-leg health state into the local bond snapshot. However, that runtime state is not being folded back into client-side endpoint selection strongly enough for CF bond forwards.

In practice, weak CF bond slave legs can continue to appear in the next generated runtime even after they have already shown negative signals such as:

- `stream_open_timeout`
- `selected_but_unproductive`
- `recv_reset`
- `control_socket_closed_unexpectedly`

This causes the client to keep retrying or overusing bad CF slave legs after restart, even though the same general health model already exists for normal bond endpoint selection.

## Approved scope

This design implements client-side health scoring for CF bond slave legs only.

It does **not**:

- change slave-side `cf_mode` IP selection logic
- change panel provisioning format for CF bond
- change UDP behavior
- hot-mutate a running bond transport in place

## Architecture

The boundary stays simple:

- **Client owns slave-leg health scoring**
- **Slave owns CF IP selection within a location group**
- **Panel remains unchanged for this first pass**

Flow:

1. `slave-agent` continues to choose the CF IP behind each slave leg.
2. `leikwan-bond` runtime on the client continues to emit per-leg runtime health into `/run/leikwan/bond-leg-health-forward-<forward_id>.json`.
3. `client-agent` reads that snapshot for CF bond forwards and projects the runtime leg state into the same endpoint-health model already used by normal bond mode.
4. On the next render/restart, existing endpoint selection logic omits or deprioritizes bad CF slave legs.

This keeps the health decision at the slave-leg layer, which matches the approved scope.

## Behavior

### Eligible forwards

The new behavior applies only when both are true:

- `mode == bond`
- `backend_mode == cf_mode`

Other forward types must remain unchanged.

### Runtime input

Use the local bond snapshot:

- `/run/leikwan/bond-leg-health-forward-<forward_id>.json`

Per leg, use:

- `endpoint_id`
- `tcp.state`
- `tcp.last_reason`
- `recent_events`

The client only needs the TCP side for this pass because UDP is explicitly out of scope.

### Mapping to existing health model

Do not invent new client-visible states. Map runtime bond state into the existing endpoint health cache used by `select_bond_runtime_endpoints()`.

Mapping:

- runtime `healthy` -> client `healthy`
- runtime `degraded` -> client `probation` / degraded priority penalty
- runtime `probation` -> client `probation`
- runtime `quarantined` -> client `quarantined`

Negative runtime reasons that should count as bad-leg signals:

- `stream_open_timeout`
- `selected_but_unproductive`
- `recv_reset`
- `control_socket_closed_unexpectedly`

This should not replace the existing probe-based health cache. It should enrich or reconcile it for CF bond forwards so the next rendered runtime reflects actual recent runtime leg quality.

### Decision timing

This affects only:

- next forward render
- next client restart
- explicit refresh/re-apply path

It does **not** hot-edit a currently running bond transport.

### Selection behavior

After mapping runtime state into the existing client endpoint health cache, continue using the current normal bond selector behavior:

- healthy legs keep normal priority
- probation/degraded legs get lower preference
- quarantined legs are omitted unless all legs are bad
- if all legs are bad, keep one fallback leg

This is intentionally the same model as normal bond mode.

## Code changes

### Production code

#### `client-agent/client_agent.py`

Add the feature here. This module already owns:

- endpoint probe result recording
- bond endpoint health cache
- endpoint ordering
- forward render/reapply behavior

Expected additions:

1. Helper to load the current forward bond snapshot by `forward_id`
2. Helper to extract runtime health rows keyed by `endpoint_id`
3. Helper to reconcile CF bond runtime state into the existing endpoint health cache
4. Hook that reconciliation into the render/reapply path before `select_bond_runtime_endpoints()` is used

The implementation must be narrow and not refactor unrelated health code.

### No first-pass code changes

#### `slave-agent/slave_agent.py`

Do not change:

- `select_cf_location_ip()`
- candidate probing
- CF IP cache TTL/cooldown behavior

#### `master/src/Support/ProvisioningService.php`

Do not change panel provisioning for this pass.

#### `sidecar-agent/*`

No sidecar logic changes are required for this design.

## Testing

### Required regression coverage

Add or extend client-agent tests to prove:

1. CF bond snapshot with a quarantined leg causes that endpoint to be omitted from selected runtime endpoints
2. CF bond snapshot with degraded/probation state causes that endpoint to be deprioritized
3. If all CF bond legs are bad, selector still keeps one fallback endpoint
4. Non-CF bond behavior remains unchanged

### Primary verification command

- `python -m unittest client-agent.tests.test_mptcp_bond_regressions`

## Live verification

After deployment of the client-agent/runtime change:

1. force a fresh render/restart of `forward 47`
2. inspect generated `leikwan-client-start-47.sh`
3. confirm bad CF legs are omitted or deprioritized relative to healthier legs
4. run exact TCP verification:
   - `iperf3 -c 127.0.0.1 -p 11035 -R -b 300M`
5. compare throughput against:
   - current warmed multi-leg baseline
   - single-leg bakeoff baseline

## Success criteria

This pass is successful if:

- CF bond bad slave legs stop dominating the rendered runtime after restart
- TCP throughput improves measurably versus current CF multi-leg behavior
- UDP remains untouched
- slave-side CF IP selection remains unchanged

## Non-goals

This pass does **not**:

- make UDP work
- redesign slave CF location probing
- add live in-place runtime mutation
- introduce a separate CF-only scoring model

## Recommendation

Implement the minimal client-side version first. If throughput remains poor even after bad CF slave legs are deprioritized, that will confirm the remaining bottleneck is the underlying slave/network path rather than missing client leg selection logic.
