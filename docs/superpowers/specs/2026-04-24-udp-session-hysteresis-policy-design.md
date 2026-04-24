# UDP Session Throughput Hysteresis Policy Design

## Summary

Add a panel-published UDP sidepath policy that keeps each UDP session on one stable leg at low observed throughput, then promotes the session to a bounded multi-leg mode only after sustained higher measured throughput. The policy is enforced by the active Python UDP runtimes on the client and sidecar, while the panel becomes the authoritative source for thresholds and caps.

This is meant to preserve small-packet stability under roughly 200 Mbps and only pay the reordering cost of multi-leg fanout when a session is clearly bulk traffic.

## Problem

The current `bond + plain_tcp_mux + udp_uot_sidepath` path can authenticate and carry reverse UDP, but sustained high-rate reverse traffic (`iperf3 -u -R`) still degrades after the first few seconds.

Observed live behavior in this environment:

- auth failures were fixed by correcting slave sidepath config and replacing the broken sidecar `leikwan-udp-uot-merge` runtime with the working Python-backed runtime
- after auth was fixed, the sidecar continued to send steadily while the client reorder buffer grew and `udp-uot reorder skip dropped=...` events appeared
- reducing from 8 legs to 4 legs reduced reorder skips substantially, but did not fully remove the throughput cliff

This shows the remaining issue is not panel publish, not slave sync, and not sidecar auth. It is the cost of distributing a single UDP session over too many legs when the session does not actually need that much parallelism.

## Goals

- Keep low-rate UDP sessions stable on one very good leg
- Promote to multi-leg only when the session's live measured throughput stays above the configured threshold long enough to justify it
- Demote back to single-leg only after a lower sustained threshold, so the session does not flap around 200 Mbps
- Make the policy authoritative in panel-published config so it can be rolled out and tuned centrally
- Apply the same policy on the client and sidecar runtimes so reverse UDP behavior matches forward-session policy
- Keep the initial rollout small and reversible

## Non-Goals

- No new tenant/admin UI in this phase
- No panel-side live throughput feedback loop; the panel only publishes policy, it does not drive per-session switching
- No change to TCP bond behavior
- No attempt to use all healthy legs in UDP multi-leg mode; bounded fanout is intentional
- No Rust parity work in this phase. The current live environment is using the Python sidecar wrapper, and the local Rust `leikwan-uot` build is currently blocked by a compiler ICE. This design targets the active Python runtimes first.

## User Requirement

The accepted behavior requirement is:

- below the live throughput cutoff, keep one very stable leg so small packets remain stable
- above the cutoff, use multiple legs to balance load
- do not switch on a hard threshold; use hysteresis based on live measured throughput

Approved control model:

- throughput source: live measured per-session throughput
- switching model: mid-session hysteresis
- recommended initial rollout: one stable leg below threshold, two-leg fanout above threshold

## Recommended Policy

Use these published defaults for the first rollout:

- `promote_threshold_mbps = 220`
- `demote_threshold_mbps = 180`
- `hold_seconds = 3`
- `multi_leg_cap = 2`
- `single_leg_selection = best_healthy`
- `initial_mode = single_leg`

Rationale:

- the 220/180 split gives a 40 Mbps hysteresis band around the user's 200 Mbps intent
- 3 seconds is long enough to ignore short spikes but short enough to respond during a real bulk session
- 2 legs is intentionally conservative because 4 legs still showed reorder pressure under real reverse UDP load

## Published Panel Contract

The panel remains the authoritative source of policy and publishes it through existing agent config plumbing under `udp_uot_sidepath`.

### Proposed schema extension

Add a nested object under the existing `udp_uot_sidepath` block:

```json
{
  "udp_uot_sidepath": {
    "enabled_forward_ids": [26],
    "shadow_route_suffix": ".uot",
    "sidecar_port": 8999,
    "reorder_window_packets": 4096,
    "reorder_window_bytes": 16777216,
    "reorder_timeout_ms": 25,
    "session_leg_policy": {
      "mode": "throughput_hysteresis",
      "initial_mode": "single_leg",
      "single_leg_selection": "best_healthy",
      "promote_threshold_mbps": 220,
      "demote_threshold_mbps": 180,
      "hold_seconds": 3,
      "multi_leg_cap": 2
    }
  }
}
```

### Compatibility rule

If `session_leg_policy` is absent, runtimes keep the current behavior.

This avoids silently changing UDP behavior for existing environments that have not opted into the new policy yet.

## Architecture

### Panel

Files in scope:

- `master/src/Support/ProvisioningService.php`
- any panel config defaults source used to build the existing `udp_uot_sidepath` block

Responsibilities:

- publish `session_leg_policy` as part of authoritative sidepath config
- keep rollout centralized and tunable
- do not make per-session decisions

### Client runtime

Primary file in scope:

- `client-agent/leikwan_uot_bond.py`

Responsibilities:

- start each UDP session in single-leg mode
- measure live per-session throughput
- promote/demote according to hysteresis policy
- keep single-leg mode pinned to one stable healthy leg
- keep multi-leg mode bounded by `multi_leg_cap`
- preserve current reconnect/penalty logic for individual legs

### Sidecar runtime

Primary file in scope:

- `sidecar-agent/leikwan_udp_uot_merge.py`

Responsibilities:

- apply the same per-session policy for sidecar-to-client delivery
- keep reverse-path leg usage aligned with the same hysteresis model
- preserve current endpoint penalty / suppression behavior

### Runtime generator / installer

Primary file in scope:

- `client-agent/install_forward_runtime.py`

Responsibilities:

- render the published `session_leg_policy` into `/etc/leikwan/uot-bond-forward-<id>.json`
- preserve old config format when policy is absent

## Detailed Runtime Behavior

### Session state additions

Each UDP session gains a small policy state object containing:

- current mode: `single_leg` or `multi_leg`
- currently pinned stable leg id / endpoint id (for single-leg mode)
- rolling throughput samples for recent seconds
- promote window start timestamp
- demote window start timestamp

This state is session-scoped and cleared when the session expires.

### Throughput measurement

Use live measured per-session throughput over a rolling window derived from actual bytes successfully emitted by the runtime for that session.

For the client runtime, this should reflect the session's outbound framed UDP bytes toward the sidecar path.

For the sidecar runtime, this should reflect actual UDP bytes pulled from the target-side UDP socket and emitted back toward the client side of the session.

The implementation should use second-sized buckets or an equivalent rolling measurement that can answer:

- has this session stayed above `promote_threshold_mbps` for `hold_seconds`?
- has this session stayed below `demote_threshold_mbps` for `hold_seconds`?

### Initial mode

On session creation:

- enter `single_leg` mode
- choose the best healthy leg and pin the session to it

### Stable leg selection

`best_healthy` means:

1. only consider currently active, non-suppressed legs
2. prefer the healthiest leg according to the runtime's current health ordering / weight ordering
3. use a deterministic tie-breaker so a session does not bounce between equally-ranked legs

For the initial rollout, deterministic endpoint order should be enough once current health ordering is applied.

### Promote rule

While in `single_leg` mode:

- if measured throughput stays above `promote_threshold_mbps` for at least `hold_seconds`, switch to `multi_leg`
- when switching, choose up to `multi_leg_cap` best healthy legs

### Demote rule

While in `multi_leg` mode:

- if measured throughput stays below `demote_threshold_mbps` for at least `hold_seconds`, switch back to `single_leg`
- immediately repin to the best currently healthy stable leg

### Multi-leg bounded fanout

`multi_leg_cap` is a hard upper bound.

For the first rollout:

- `multi_leg_cap = 2`
- even if 8 legs are healthy, a high-rate session should still only use the best 2

### Leg failure handling

- if a pinned single leg fails, immediately choose the next best healthy leg and stay in `single_leg`
- if multi-leg mode loses one leg, continue with the remaining healthy legs up to the cap
- if all legs fail, preserve existing reconnect behavior
- endpoint suppression/quarantine still applies; suppressed legs must not be chosen as pinned or promoted legs

### Hysteresis anti-flap rule

- only one transition may be active at a time
- crossing the opposite threshold clears the current candidate timer
- short spikes and drops must not switch modes by themselves

## Data Flow

1. Panel publishes `udp_uot_sidepath.session_leg_policy`
2. Client agent pulls config and writes the policy into the rendered UoT runtime config
3. Client UDP runtime starts sessions in single-leg mode
4. Sidecar agent publishes the same policy to the sidecar runtime
5. Sidecar UDP runtime starts reverse delivery in single-leg mode
6. Either side promotes its own session direction only after sustained measured throughput justifies it
7. Both sides demote after sustained low throughput

## Error Handling

- missing or malformed policy: log once and fall back to current behavior
- thresholds inverted or invalid: clamp or reject to safe defaults before runtime start
- `multi_leg_cap < 1`: clamp to `1`
- missing healthy legs: preserve existing `no active legs available` behavior
- policy transitions must never crash the session; if selection logic fails, stay on the current valid mode and log the issue

## Rollout Strategy

### Phase 1

Implement and enable the policy only for the active Python runtimes:

- `client-agent/leikwan_uot_bond.py`
- `sidecar-agent/leikwan_udp_uot_merge.py`
- `client-agent/install_forward_runtime.py`
- panel-published config path

### Phase 2 (optional, later)

Add equivalent logic to the Rust `leikwan-uot` runtime once the Rust workspace/build issue is resolved.

## Verification Plan

### Automated

Add tests for:

- panel-published config contains the new `session_leg_policy` block
- client runtime starts sessions in `single_leg`
- client runtime promotes only after sustained threshold crossing
- client runtime demotes only after sustained lower threshold crossing
- `multi_leg_cap` is enforced
- sidecar runtime mirrors the same policy behavior
- leg failure during single-leg mode repins to another healthy leg
- missing policy preserves current all-active-leg behavior

### Live validation

Use the current `forward 26` environment for validation:

1. verify sidecar auth still succeeds on `8999`
2. run reverse UDP at a low rate (for example `10M`) and prove the session stays pinned to one leg
3. run reverse UDP at a high rate (for example `400M`) and prove the session promotes to 2 legs after the hold window
4. compare:
   - receiver throughput
   - receiver loss
   - client reorder buffer growth
   - `datagrams_dropped_gap`
   - sidecar `live_legs`
5. confirm no regression for TCP path

## Success Criteria

The change is successful when all of the following are true:

- low-rate UDP sessions stay on one stable leg
- high-rate UDP sessions promote only after hysteresis delay
- reverse UDP no longer shows the same early reorder-collapse pattern caused by over-wide fanout
- client reorder skip counts are materially reduced versus the current 8-leg baseline
- panel is the authoritative source of the policy values
- environments without the new policy keep existing behavior

## Scope Decision

This spec is intentionally focused enough for one implementation plan:

- panel config publication
- client Python runtime behavior
- sidecar Python runtime behavior
- tests and live verification

It does not include UI work or Rust parity work.
