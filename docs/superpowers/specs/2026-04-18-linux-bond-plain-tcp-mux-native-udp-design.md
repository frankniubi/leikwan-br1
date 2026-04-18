# Linux Bond Plain TCP Mux Native UDP Alignment Design

Date: 2026-04-18

## Goal

Align the Linux production `client -> slave -> sidecar` path with the current panel contract and [`docs/BOND_OPERATIONS.md`](C:/Users/frankniubi/Downloads/leikwan-br1/docs/BOND_OPERATIONS.md), so that `bond + plain_tcp_mux`:

- uses one native multi-leg runtime shape for both TCP and UDP
- remains compatible with the current panel payloads
- recovers automatically after disconnects or partial runtime failure
- can be validated against the slave set the panel actually published
- stays within the existing slave ingress port constraint `7000-9000`

This design is intentionally limited to the Linux production path:

- panel provisioning contract
- Linux `client-agent`
- Linux `slave-agent`
- Linux `sidecar-agent`
- Linux `leikwan-bond` / `leikwan-ingress` orchestration

It does not change the topology away from `client -> slave -> sidecar`.

## Non-Goals

- no direct `client -> sidecar` topology
- no Windows-specific runtime work
- no Android-specific runtime work
- no redesign of MPTCP behavior beyond maintaining compatibility where it shares code paths or operational assumptions
- no new slave ingress port ranges outside `7000-9000`

## Problem Summary

The current repository already contains more native `bond` UDP capability than the long-lived operations documentation currently describes:

- panel emits `bonding.session_token` and `bonding.udp_session_token`
- `client-agent/install_forward_runtime.py` already generates native:
  - `leikwan-bond client --protocol tcp`
  - `leikwan-bond client --protocol udp`
- `wstunnel-main/leikwan-bond/src/session.rs` already contains real UDP client/server session logic

The main remaining gap is therefore not "invent native UDP from zero." The gap is that the operational contract, runtime supervision, and live verification story are not consistently aligned across:

- panel published forward contract
- client runtime generation
- slave ingress behavior
- sidecar supervision
- health/restart decisions
- live verification discipline

This mismatch makes it too easy to:

- run TCP and UDP through subtly different orchestration rules
- misdiagnose a published/runtime endpoint mismatch as a transport bug
- misdiagnose clock skew or token freshness problems as network instability
- trust performance tests even when the client is not connected to the same slave set the panel published

## Design Overview

The design standardizes `bond + plain_tcp_mux` around one canonical Linux runtime shape:

1. panel publishes one explicit native `bond` contract for both TCP and UDP
2. client launches one ingress helper per slave leg
3. client launches one native `leikwan-bond client --protocol tcp`
4. client launches one native `leikwan-bond client --protocol udp` when forward transport includes UDP
5. slave stays a simple ingress/relay carrier and does not aggregate legs
6. sidecar stays the single reconvergence point
7. health and restart logic become topology-aware rather than "one port exists"
8. every formal live test must prove that panel-published slaves match the slaves actually in use

## Section 1: Contract And Runtime Shape

### Canonical Contract

For `mode = bond` and `bonding.ingress_mode = plain_tcp_mux`, panel remains the source of truth and must publish a complete forward contract that includes:

- `transport` / `transport_mode`
- `listen_port`
- `mode = bond`
- `bonding.ingress_mode = plain_tcp_mux`
- `bonding.session_token`
- `bonding.udp_session_token` when UDP is enabled
- `bonding.route_key`
- native `bond` tuning fields already used by Linux runtime generation
- endpoint list with:
  - `endpoint_id`
  - `host`
  - `port`
  - `ingress_auth_token`
  - `ingress_protocol = plain_tcp_mux`

No legacy fallback path should be treated as the primary production contract for `bond + plain_tcp_mux`.

### Canonical Client Runtime

For every selected slave endpoint, client launches exactly one ingress helper:

```text
leikwan-ingress client --listen 127.0.0.1:<leg_port> --remote <slave_host>:<slave_port> --route-key <route_key> --auth-token <ingress_auth_token>
```

Above those legs, client launches:

- `leikwan-bond client --protocol tcp`
- `leikwan-bond client --protocol udp` when transport includes UDP

This means TCP and UDP share:

- the same slave endpoint set
- the same ingress mode
- the same route/auth semantics
- the same multi-leg transport family

The runtime must not prefer old `wstunnel` fallback pieces over the native path for current production `bond + plain_tcp_mux`.

### Canonical Slave Runtime

Slave remains intentionally simple:

- accept authenticated ingress legs on the published `plain_tcp_mux` listener
- match `route_key` and `ingress_auth_token`
- relay the accepted leg to the configured backend target
- do not perform aggregation
- do not terminate the application protocol

All bond aggregation remains between client and sidecar.

### Canonical Sidecar Runtime

Sidecar remains the single reconvergence point:

- accept one native bond leg per slave
- validate bond session tokens
- reassemble and forward to the target
- carry both TCP and UDP under the same native bond runtime family

## Section 2: Auto-Recovery And Health

### Topology-Aware Health Model

`client-agent` must evaluate the health of the full forward topology, not just "systemd says active" or "one port listens."

For `bond + plain_tcp_mux`, health should include:

- bond TCP listener present when TCP transport is enabled
- bond UDP listener present when UDP transport is enabled
- all expected local ingress leg listeners present
- slave ready probes still succeed for the published endpoints
- recent bond runtime output does not show persistent fatal transport symptoms such as:
  - repeated reset loops
  - broken pipe loops
  - no-active-leg conditions
  - persistent UDP session failure patterns

### Recovery Rule

Any critical child or required listener failure should rebuild the entire forward.

The system should prefer declarative full-forward reconstruction over partial repair. Examples of critical failure:

- bond process exited
- required ingress helper exited
- required TCP listener missing
- required UDP listener missing
- endpoint set drifted after refresh

The recovery unit remains the forward service, not an individual ad hoc child patch-up.

### Recovery Backoff

Recovery should have explicit cooldown/backoff behavior:

- fast restart on the first hard failure
- increasing delay after repeated failures
- continued probing during cooldown without restart storms

This keeps the runtime from thrashing when the underlying fault is external or persistent.

### UDP-Specific Health

UDP health must not be defined only as "a UDP socket exists."

UDP health should combine:

- local UDP listener presence
- slave leg reachability via the same published endpoints
- bond runtime UDP error counters or fatal session evidence

This avoids false healthy states where the UDP process is technically bound but the path is functionally broken.

### Slave And Sidecar Recovery Semantics

`slave-agent` and `sidecar-agent` should match the same operational philosophy:

- prefer rebuilding the coherent runtime shape over leaving half-applied state
- guarantee that config files, routes, units, and listeners stay in sync
- detect version/config drift and reconcile it

## Section 3: Published-vs-Live Slave Consistency Verification

Performance and stability tests are only meaningful if the live slave set matches the slave set the panel actually published.

### Required Comparison Views

For a forward under test, the system must be able to compare:

1. panel-published endpoint set
2. client-generated runtime endpoint set
3. live client-established remote slave connections
4. live sidecar-observed source slave connections

The comparison key should be based on endpoint identity and concrete target host/port, using fields such as:

- `endpoint_id`
- `host`
- `port`
- `route_key`

### Mismatch Handling

If published and live sets do not match, the forward should be marked with an explicit mismatch condition such as:

- `published_runtime_mismatch`

When this condition is present:

- performance tests are not considered valid
- health output should explain the mismatch rather than blame transport quality
- recovery may rebuild the forward using the current published set

### Validation Timing

This published-vs-live comparison should run at least:

- after client pulls new rules
- after a forward rebuild
- after any explicit version sync to client/slave/sidecar
- before any formal `iperf3` validation run

## Section 4: Testing, Version Sync, Deployment, And Acceptance

### Execution Order

Execution order must be fixed:

1. regression tests
2. version sync
3. topology consistency check
4. `iperf3` validation

This prevents misleading conclusions from mixed-version or mixed-endpoint runtime states.

### Required Test Areas

Regression coverage should be added for:

- panel provisioning:
  - native `bond + plain_tcp_mux` TCP/UDP contract output
  - endpoint/auth/transport consistency
- client runtime generation:
  - TCP and UDP native bond startup shape
  - ingress-helper generation
  - runtime restart/backoff decisions
  - published-vs-live mismatch detection
- slave agent:
  - route rendering
  - mux/backend service consistency
  - `plain_tcp_mux` transport expectations
- sidecar agent:
  - bond sidecar supervision
  - transport-aware runtime management
  - version/config drift reconciliation

### Version Sync Rules

Version sync must treat client/slave/sidecar as one coherent production path.

A release is not considered valid if only one role is updated while the others still run incompatible logic.

At minimum, sync should cover:

- panel contract/artifacts
- client agent and bond/ingress runtime
- slave agent and ingress runtime
- sidecar agent and bond runtime

### Live Validation Matrix

Formal runtime validation should include:

- TCP:
  - `iperf3 -c 127.0.0.1 -p 11024 -R -b 400M`
- UDP:
  - `iperf3 -c 127.0.0.1 -p 11024 -R -b 400M -u`

Each validation run must first record:

- panel-published slave endpoints
- client runtime slave endpoints
- client live connected slave endpoints
- sidecar live observed slave endpoints

If those sets are not consistent, the test result is invalid for performance/stability conclusions.

### Acceptance Criteria

The change is acceptable only if all of the following are true:

- panel-published and client-live slave sets match for the tested forward
- TCP native bond runtime starts and passes basic validation
- UDP native bond runtime starts and passes basic validation
- critical runtime failure triggers automatic recovery
- recovery does not leave persistent partial-leg or false-healthy states
- slave ingress ports remain within `7000-9000`

## Section 5: Clock-Skew Validation And NTP Provisioning

### Why Time Validation Matters

Clock skew is operationally relevant in this stack because:

- bond session tokens are time-bounded
- update and management flows rely on freshness windows
- readiness/auth failures can be misdiagnosed when the root cause is time drift

### Required Clock-Skew Checks

The design adds explicit time validation:

- installation-time validation
- runtime validation before or during token-sensitive management/heartbeat flows

Each Linux role should be able to report:

- whether local time synchronization is active
- whether local clock appears synchronized
- whether local skew relative to panel exceeds the allowed threshold

### Installer Behavior

Linux install/update flows should ensure a time-sync service is present and enabled.

The implementation should be idempotent:

- detect an already-available time-sync service first
- if missing, install and enable an NTP-capable service
- if present but stopped, enable/start it
- verify synchronization state after setup

The user specifically requested automatic NTP provisioning when this feature is adopted, so installers should enforce this rather than leaving it as manual guidance.

### Runtime Health Thresholds

Recommended thresholds:

- drift above 3 seconds:
  - warning/degraded state
- drift above 10 seconds:
  - block token-sensitive operations or flag the node as unsafe until time is corrected

Clock-skew faults should be surfaced distinctly from transport faults to prevent wasted network debugging.

## File Map

Expected implementation touch points:

- panel:
  - `master/src/Support/ProvisioningService.php`
  - related panel regression tests
- client:
  - `client-agent/install_forward_runtime.py`
  - `client-agent/client_agent.py`
  - related client-agent tests
- slave:
  - `slave-agent/slave_agent.py`
  - related slave-agent tests
- sidecar:
  - `sidecar-agent/sidecar_agent.py`
  - related sidecar-agent tests
- runtime:
  - `wstunnel-main/leikwan-bond/src/main.rs`
  - `wstunnel-main/leikwan-bond/src/session.rs`
  - only if contract or observability adjustments are required to support the agreed health model
- installers:
  - Linux client/slave/sidecar install/update scripts for NTP assurance

## Risks And Guardrails

- Do not change the topology away from `client -> slave -> sidecar`.
- Do not move slave ingress ports outside `7000-9000`.
- Do not trust live throughput numbers until published-vs-live slave consistency passes.
- Do not silently preserve partial/legacy fallback behavior as the primary production path for `bond + plain_tcp_mux`.
- Do not collapse transport faults and clock-skew faults into one generic "network issue" bucket.

## Rollout Shape

Recommended rollout order:

1. tests and contract cleanup
2. client runtime generation and health logic
3. slave consistency behavior
4. sidecar supervision updates
5. installer NTP support
6. version sync to panel/client/slave/sidecar
7. topology verification
8. TCP and UDP live validation
