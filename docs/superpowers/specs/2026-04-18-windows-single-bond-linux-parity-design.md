# Windows Single/Bond Linux Parity Design - 2026-04-18

## Overview

Bring the `.NET` Windows app backend in `LeikwanBridge.Desktop` into parity with the current Linux client runtime behavior for `single` and `bond`.

The goal is not to invent a Windows-specific transport model. The goal is to make Windows consume the same panel contract and launch the same effective runtime topology that Linux uses today:

- `single` stays a direct `wstunnel` runtime
- `bond + plain_tcp_mux` becomes `N ingress helpers -> readiness wait -> leikwan-bond`
- automatic recovery rebuilds the whole forward when any critical child process or listener fails

This design explicitly uses the current Linux client path as the contract source:

- `client-agent/install_forward_runtime.py`
- `master/src/Support/ProvisioningService.php`
- `docs/BOND_OPERATIONS.md`

## Selected Approach

**Selected:** Approach A (Linux-parity runtime orchestration)

This approach keeps `single` behavior aligned with Linux, replaces the legacy Windows bond WSS-leg model with the current Linux production bond chain, and makes recovery operate on the forward as a whole instead of on disconnected child processes.

This is the most reliable way to stay compatible with the current panel and production bond topology.

## Goals

- Make Windows `single` mode launch and supervise the same effective runtime shape Linux uses today.
- Make Windows `bond` mode consume the same panel JSON and honor the same `bonding` contract fields Linux uses today.
- Support current production `bond + plain_tcp_mux` semantics on Windows:
  - one ingress helper per endpoint
  - one local leg listener per helper
  - one `leikwan-bond.exe client` on top of those legs
- Ensure runtime startup order matches Linux:
  - child legs first
  - readiness wait second
  - bond runtime last
- Make disconnect recovery safe and predictable by rebuilding the entire forward when a critical child fails.
- Add regression tests for builder output, orchestration order, and restart behavior.

## Non-Goals

- Do not modify the old Tauri Windows prototype in `wstunnel-main/leikwan-client-win`.
- Do not implement native Windows MPTCP; existing Windows `mptcp -> bond` fallback remains acceptable.
- Do not add UDP support for `bond + plain_tcp_mux` before Linux production supports it end to end.
- Do not redesign the Windows UI.
- Do not change the production topology away from `client -> slave -> sidecar`.

## Current Gaps

The current `.NET` Windows implementation diverges from Linux production in four important ways:

1. `BondRuntimeBuilder.cs` still models bond legs as per-endpoint `wstunnel` processes.
2. `EndpointConfig.cs` and `BondingConfig.cs` do not represent the full current panel contract for `plain_tcp_mux` bond ingress.
3. `AgentConfig.cs` has no path/config slot for `leikwan-ingress.exe`.
4. Health and supervision currently only answer "is anything still running?" instead of "is the full forward runtime topology still healthy?"

## Architecture

### Runtime Modes

#### Single

Windows `single` remains Linux-parity:

- one `wstunnel.exe client`
- direct listen on the forward listen port
- no ingress helper layer

#### Bond

Windows `bond` becomes mode-aware:

- if `bonding.ingress_mode == plain_tcp_mux`
  - spawn one `leikwan-ingress.exe client` per endpoint
  - each helper listens on a deterministic local leg port
  - wait until all required local leg ports are ready
  - spawn one `leikwan-bond.exe client` referencing only those local leg ports
- if a future or legacy payload uses a non-`plain_tcp_mux` ingress mode
  - retain a compatibility branch that still builds bond legs using `wstunnel`

#### MPTCP

Windows may continue falling back from `mptcp` to `bond`, but that fallback must feed the new Linux-parity bond runtime builder rather than the old WSS-leg implementation.

#### Race

`race` remains out of scope for behavioral redesign in this pass. The requirement is simply that the bond refactor must not regress the current `race` path.

## Config Model Changes

### `LeikwanBridge.Core.Models.EndpointConfig`

Add the fields needed to consume Linux-era bond endpoint metadata directly from panel JSON:

- `route_key`
- `ingress_auth_token`
- `ingress_protocol`
- `backend_protocol`
- `dynamic_priority`

These fields are required so the Windows app can launch `plain_tcp_mux` ingress helpers without inventing local-only conventions.

### `LeikwanBridge.Core.Models.BondingConfig`

Add the missing current Linux/panel bond fields:

- `ingress_mode`
- `route_key`
- `warmup_trigger_bytes`
- `warmup_slice_bytes`
- `warmup_leg_delay_ms`
- `bulk_promote_bytes`
- `leg_cooldown_ms`

Keep the existing base bond fields:

- `remote_target`
- `session_token`
- `udp_session_token`
- `chunk_size`
- `max_window_bytes`
- `retransmit_ms`
- `connect_timeout_ms`
- `join_timeout_ms`
- `interactive_chunk_bytes`
- `latency_guard_bytes`
- `interactive_rtt_slack_ms`

### `LeikwanBridge.Core.Models.AgentConfig`

Add:

- `bond_ingress_bin`
- `GetEffectiveBondIngressBin()`

Default path should mirror the existing bond/wstunnel helpers and resolve under the app's packaged `bin` directory.

### `LeikwanBridge.Core.Models.AppliedForwardState`

Extend persisted applied state to include enough runtime topology for diagnostics and recovery decisions:

- `ingress_mode`
- `endpoint_count`
- `leg_ports`
- `process_roles`
- `process_labels`

The persisted state should describe the last applied runtime shape, not merely the listen port.

## Runtime Planning

### New Runtime Plan Shape

Refactor the runtime builder so it returns structured process roles rather than only opaque `(exe, args, label)` tuples.

Recommended plan shape:

- `ForwardRuntimePlan`
  - `mode`
  - `listen_port`
  - `ingress_mode`
  - `leg_ports`
  - `processes[]`
- `ForwardRuntimeProcess`
  - `role`
  - `label`
  - `exe_path`
  - `arguments`
  - `required_for_readiness`
  - `critical_for_forward_health`

This lets the supervisor and health layer understand which processes are helpers, which are primary runtimes, and which failures require rebuilding the whole forward.

### Deterministic Local Leg Ports

Windows should keep Linux-style deterministic per-forward leg port derivation so:

- the runtime plan is reproducible
- health checks know which ports must exist
- persisted state can describe the topology clearly

### Bond Runtime Arguments

When `bond + plain_tcp_mux` is selected, `leikwan-bond.exe client` should receive the same effective core flags Linux uses today:

- `--protocol`
- `--listen`
- `--legs`
- `--session-token`
- `--chunk-size`
- `--max-window-bytes`
- `--retransmit-ms`
- `--connect-timeout-ms`
- `--join-timeout-ms`
- `--interactive-chunk-bytes`
- `--latency-guard-bytes`
- `--interactive-rtt-slack-ms`
- `--warmup-trigger-bytes`
- `--warmup-slice-bytes`
- `--warmup-leg-delay-ms`
- `--bulk-promote-bytes`
- `--leg-cooldown-ms`

When `plain_tcp_mux` is active, Windows should match Linux and skip the UDP bond runtime for now.

## Orchestration Flow

### Single

For `single`:

1. Stop existing forward processes.
2. Start one `wstunnel.exe` runtime.
3. Persist applied runtime state.

### Bond + `plain_tcp_mux`

For `bond + plain_tcp_mux`:

1. Stop existing forward processes.
2. Start one `leikwan-ingress.exe client` per endpoint.
3. Wait for every expected local leg port to begin listening.
4. If any required leg port does not become ready within timeout, stop the started children and fail the apply.
5. Start `leikwan-bond.exe client` on top of the local leg ports.
6. Persist applied runtime state, including ingress mode, endpoint count, leg ports, and process roles.

### Compatibility Branch

If Windows receives a bond ingress mode other than `plain_tcp_mux`, allow a compatibility branch that still builds per-leg `wstunnel` processes. This branch exists only to avoid breaking non-production or old payloads. It is not the target production shape.

## Recovery Model

### Recovery Principle

Treat each forward as one runtime unit.

If any critical child process or required listener fails, rebuild the entire forward rather than trying to patch one child in place.

### Critical Failures

The following should trigger whole-forward rebuild:

- `single` runtime exit
- any `bond` ingress helper exit
- bond runtime exit
- missing forward listen port
- missing required local leg listener after the forward was previously healthy

### Cooldown

Retain a restart cooldown similar to the current health service so the app does not thrash. Cooldown should apply to whole-forward rebuild, not child-specific patches.

### Process Exit Handling

Connect `ProcessSupervisor.ProcessExited` to forward-aware recovery logic. That handler should:

- identify the process role
- decide whether the role is critical
- schedule a whole-forward rebuild if appropriate

This is more reliable than waiting for the next coarse polling loop to notice missing listeners.

## Health Checks

### Single

Keep simple checks for `single`:

- runtime process present
- forward listen port is listening

### Bond

Make bond checks topology-aware:

- number of running ingress helpers matches the number of required endpoints
- all expected local leg ports are listening
- the bond listen port is listening
- the bond primary runtime process is alive

If any required piece is missing, mark the forward unhealthy and rebuild it as a unit.

## File-Level Design

### Modify

- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/BondingConfig.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentConfig.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentState.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ProcessSupervisor.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`

### Add

- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/...`
  - runtime builder tests
  - orchestration tests
  - recovery tests

If test project scaffolding does not already exist, add a minimal test project for the Core layer only.

## Testing Plan

### Builder / Config Tests

Add tests that prove:

- `single` still renders a direct `wstunnel` runtime
- `bond + plain_tcp_mux` renders one ingress helper per endpoint plus one bond process
- `bond + plain_tcp_mux` does not render UDP runtime
- `mptcp` fallback routes through the new bond builder
- panel JSON for endpoint ingress metadata deserializes correctly
- warmup/bulk/cooldown fields reach the final bond CLI arguments

### Orchestration Tests

Add tests that prove:

- ingress helpers start before bond runtime
- bond runtime does not start before all required local leg listeners are ready
- failed readiness causes cleanup instead of partial success
- applied state records the final runtime topology

### Recovery Tests

Add tests that prove:

- critical child exit triggers whole-forward rebuild
- missing listen port triggers rebuild
- missing ingress helper / leg listener triggers rebuild
- restart cooldown prevents tight crash loops

### Manual Verification

Manual validation on Windows should cover:

1. Pull a `single` config and confirm only `wstunnel.exe` is launched.
2. Pull a `bond + plain_tcp_mux` config and confirm:
   - one ingress helper per endpoint
   - expected local leg ports appear
   - bond runtime starts only after helpers are ready
3. Kill one ingress helper and confirm the full forward is rebuilt.
4. Kill the bond runtime and confirm the full forward is rebuilt.
5. Restart the app and confirm persisted state remains readable and diagnostics remain coherent.

## Risks

- Packaged Windows builds must include `leikwan-ingress.exe`; otherwise Linux-parity bond orchestration cannot start.
- The current health service is polling-based; blending event-driven exit handling with polling must avoid double-restart races.
- Testability will improve if runtime planning is separated from process launching; otherwise orchestration tests may become brittle.

## Rollout Guidance

- Land config-model and builder changes first, guarded by tests.
- Then land orchestration and recovery changes.
- Keep the compatibility branch for non-`plain_tcp_mux` bond ingress until the panel contract is known to be fully normalized.

## Summary

The Windows `.NET` app should stop treating bond as a special Windows WSS-leg invention.

Instead, it should mirror the current Linux contract:

- `single` is `wstunnel`
- `bond + plain_tcp_mux` is `ingress helpers -> readiness wait -> leikwan-bond`
- automatic recovery rebuilds the whole forward when any critical piece fails

That is the shortest path to a Windows client that matches the current panel and is safe to operate.
