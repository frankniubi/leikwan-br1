# Multi-Slave-Group + Private Self-Leg CF Combination Design

**Date:** 2026-05-14  
**Status:** Approved design  
**Scope:** LeiKwan panel + provisioning + Linux client runtime, phase-one support for `backend_mode = cf_mode` with hybrid-capable `bond` and `mptcp` execution

## Goal

Allow one tenant-facing **forward combination** to realize through:

- multiple ordered **rented slave groups** for helper legs
- one optional **private per-device self-leg** for the owner's own use
- one shared **Cloudflare-backed sidecar path**

The feature should improve latency while saving rented traffic when the owner's own direct Cloudflare path is better than using rented slaves.

## Hard Rules

- Keep the existing sidecar-centered architecture.
- Rented helper traffic keeps topology:
  - `client -> rented slave -> CF -> sidecar -> target`
- The optional owner self-leg is a special override and may use:
  - `client(self-leg executor) -> CF -> sidecar -> target`
- Sidecar remains mandatory for all phase-one traffic.
- Target details remain hidden from tenants.
- Tenants choose **forward combination only**.
- This design is **CF-mode only** in phase one.
- The self-leg is private to the exact **device/session** that proved it is better.
- The self-leg is a **latency anchor**, not a default bulk carrier.
- Helper rented groups join only when the self-leg misses the declared latency target.
- Admin keeps explicit control over helper-group ordering and cap.
- No direct client-to-target bypass.
- No shared global "client as slave" inventory row.

## Product Summary

The system should let a single forward combination behave like a policy package:

- choose one sidecar point
- choose one CF location group / approved upstream identity
- define multiple eligible rented slave groups in order
- optionally allow a private device-scoped self-leg
- prefer MPTCP when valid, but fall back to bond safely
- escalate from self-leg-only to rented helpers when latency intent is missed

The tenant still sees only one clean route choice such as:

- `Tokyo low-latency`
- `Japan gaming route`
- `Low-cost self-optimized route`

The tenant does **not** see:

- slave groups
- self-leg internals
- CF location-group internals
- sidecar topology details
- runtime transport family switching

## Recommended Approach

Use the existing **forward combination** model as the control-plane entry point and extend it directly.

This is the smallest architecture change that still gives:

- explicit admin control
- clean tenant abstraction
- reusable provisioning output for both bond and MPTCP
- low-risk phased rollout on the current system

## Phase-One Scope

### In scope

- `backend_mode = cf_mode` only
- one combination may reference multiple ordered rented slave groups
- one per-device private self-leg candidate
- self-leg as latency anchor only
- adaptive helper admission based on existing forward-chain latency intent
- `mptcp` preferred when valid, `bond` fallback when needed
- self-leg admission only for the exact current device/session
- admin observability for self-leg / helper admission / runtime family

### Out of scope

- non-CF `current_mode`
- self-leg as a true MPTCP underlay in phase one
- tenant-visible policy controls
- cross-device self-leg sharing
- throughput-floor-based escalation
- Windows / Android implementation in phase one
- direct client-to-target shortcuts

## Control-Plane Model

### Forward combination extensions

Add policy fields to `forward_combinations`:

- `runtime_preference`
  - phase-one value: `mptcp_preferred_bond_fallback`
- `allow_private_self_leg`
- `self_leg_scope`
  - phase-one value: `device_session`
- `self_leg_role`
  - phase-one value: `latency_anchor`
- `helper_group_cap`
- `helper_escalation_policy`
  - phase-one value: `desired_latency_only`

Keep existing fields relevant:

- `backend_mode = cf_mode`
- `sidecar_point_id`
- `cf_location_group_id`
- forward-chain latency intent fields such as `desired_latency_ms`

### Runtime-family authority

For this feature path, **`runtime_preference` becomes the authoritative runtime selector**, not a fixed `client_mode` pin.

That means:

- the combination declares a hybrid-capable policy
- the resolved session may execute as `mptcp` or `bond`
- the concrete live family is chosen per device/session during provisioning/runtime qualification

Existing `client_mode` fields may still exist for compatibility, UI, or legacy forwards, but they must not overrule `runtime_preference = mptcp_preferred_bond_fallback` for this phase-one feature.

### Ordered helper-group attachment

Add a new relation table so one combination may reference several rented groups in explicit priority order.

Suggested table:

`forward_combination_helper_groups`

Suggested fields:

- `id`
- `forward_combination_id`
- `node_group_key`
- `sort_order`
- `is_active`
- `created_at`
- `updated_at`

Example ordered list:

1. `shanghai-a`
2. `shanghai-b`
3. `guangzhou-a`

### Private self-leg model

The self-leg is **not** a normal slave row.

It is a private synthetic candidate created only during provisioning for the exact current device/session.

Properties:

- owner-only
- device/session scoped
- never reusable by another device
- never visible to other users
- omitted entirely if qualification fails

## Provisioning Boundary

The resolved config boundary remains the client config generator.

Conceptually, provisioning must evolve from:

- `assignedForwardConfigsForClient(client_id)`

into a device-aware resolution step such as:

- `assignedForwardConfigsForClient(client_id, device_session_id, device_capabilities)`

This resolution should produce one **per-device candidate pool**.

### Resolved output must include

#### 1. Shared forward policy block

- `backend_mode`
- `runtime_preference`
- `desired_latency_ms`
- latency evaluation / exceed window controls
- `helper_group_cap`
- sidecar identity
- CF identity

#### 2. Flat rented candidate pool

Each rented candidate should carry:

- `candidate_kind = rented_group_leg`
- `origin_group_key`
- `origin_group_order`
- route identity
- transport capabilities
- existing endpoint metadata needed by bond / MPTCP runtimes

#### 3. Optional synthetic self-leg candidate

Suggested fields:

- `candidate_kind = self_leg`
- `session_id`
- `device_id`
- `runtime_capabilities = bond_only`
- same route identity as the rented CF path
- same sidecar identity as the rented CF path
- local probe cache key / freshness metadata

The runtime should consume one unified candidate pool instead of branching into unrelated self-leg logic.

## Runtime Family Policy

### Runtime choice

For each live session:

1. prefer `mptcp` if:
   - kernel/runtime support is healthy
   - MPTCP setup succeeds
   - active underlays are usable
2. otherwise fall back to `bond`

This preserves the existing desired behavior:

- use MPTCP when available
- do not hard-fail if MPTCP is unavailable

### Important phase-one constraint

The private self-leg cannot be a true phase-one MPTCP underlay.

Reason:

- rented MPTCP legs are modeled as `client -> slave -> sidecar` underlays
- the self-leg is explicitly `client -> CF -> sidecar`
- that is a different transport shape, not a drop-in slave-underlay leg

Therefore phase-one must enforce:

- rented helper candidates may support `bond` and `mptcp`
- self-leg supports **bond only**
- if the winning primary candidate is the self-leg, the session runs in the **bond family**
- if self-leg is not admitted and rented candidates are healthy, the session may use **MPTCP**

This keeps the design technically honest while still shipping bond + MPTCP support from day one.

## Runtime Candidate Flow

### Startup

After provisioning builds the candidate pool:

- evaluate self-leg qualification
- evaluate rented candidate health
- rank candidates by real one-leg interactive RTT after readiness / quarantine filters

#### If self-leg wins

- start with **self-leg only**
- make self-leg the initial primary latency anchor
- do not admit rented helper groups yet
- run in bond family

#### If self-leg loses or is ineligible

- start with the best rented candidate set
- use MPTCP if healthy and supported
- otherwise use bond

### Adaptive escalation

If self-leg is primary:

- measure effective latency against `desired_latency_ms`
- reuse existing forward-chain window / exceed rules
- if latency stays above target long enough, admit the next best rented helper group
- stop admitting when:
  - latency is recovered, or
  - `helper_group_cap` is reached

### De-escalation

If latency later recovers and stays stable:

- helper groups may be removed again
- helper removal must respect hysteresis / cooldown
- self-leg may regain sole ownership only after a stable healthy window

## Qualification and Scoring Rules

### Self-leg qualification gates

A self-leg candidate exists only if all are true:

- combination allows `allow_private_self_leg = true`
- current device/session is the owner
- combination is `backend_mode = cf_mode`
- local runtime can execute the required family safely
- the device completes a real CF-path probe for this forward
- probe result is fresh enough for this session

If any gate fails, the self-leg is omitted entirely.

### Common scoring principle

Rank both self-leg and rented legs using the same primary signal:

- **real one-leg interactive RTT on the real forward identity**

Do not rank self-leg only by raw TCP connect latency while rented legs use a stronger probe.

Phase-one score inputs:

1. readiness
2. interactive RTT
3. failure / quarantine state
4. cooldown / hysteresis state

### Admission rule

The self-leg becomes primary only if it actually beats the best rented candidate after the normal switching threshold / hysteresis logic.

The self-leg is not auto-preferred just because it is cheaper.

## Admin Observability

For each affected session / forward, admin observability should surface:

- selected runtime family: `bond` or `mptcp`
- whether self-leg was admitted
- selected self-leg CF IP when used
- admitted rented helper groups
- escalation reason, for example:
  - `latency_target_exceeded`
  - `self_leg_quarantined`
  - `mptcp_unavailable_fell_back_to_bond`
- current helper count vs helper cap

Without this, the feature will be too hard to debug live.

## Data Flow Diagram

```text
Tenant selects one forward combination
  -> panel resolves sidecar point + CF identity + helper-group policy
  -> provisioning resolves per-device candidate pool
      -> optional private self-leg candidate
      -> ordered rented helper-group candidates
  -> client runtime chooses family
      -> MPTCP preferred if healthy and self-leg not primary
      -> bond if MPTCP unavailable or self-leg is primary
  -> if self-leg starts alone and latency misses target
      -> admit rented helper groups in order until target recovers or cap is reached
```

## Failure Modes and Expected Behavior

| Scenario | Expected behavior |
|---|---|
| self-leg probe fails | omit self-leg from candidate pool |
| current device changes network / session expires | invalidate self-leg and rebuild candidate pool |
| MPTCP setup fails on rented-candidate session | fall back to bond using the same rented pool |
| self-leg is primary but target latency is missed | admit rented helpers adaptively |
| helper cap reached and target still missed | stay degraded but stable; do not exceed cap |
| self-leg repeatedly flaps | quarantine / demote it like any other bad candidate |

## Rollout Plan Boundary

### First live implementation target

Phase one should land only in:

- panel schema + admin UI
- provisioning / config resolution
- Linux client-agent + Linux client runtime

Windows and Android can adopt the same resolved model later, but they should not block the first architecture landing.

## Final Recommendation

Ship this as a **forward-combination extension**, not a new larger asset system.

That gives the smallest viable architecture for:

- multiple rented helper slave groups
- one private per-device self-leg
- CF-mode only
- sidecar-centered forwarding
- bond + MPTCP day-one support with an honest self-leg limitation

The most important non-obvious design decision is this:

> **Self-leg-primary sessions must run in the bond family in phase one, even though the overall feature supports bond + MPTCP from day one.**

That is the key boundary that keeps the design implementable without inventing a fake MPTCP self-underlay model.
