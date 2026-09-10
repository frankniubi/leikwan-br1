# Windows app parity with current client -> slave -> sidecar workflow

## Summary

Update `LeikwanBridge.Desktop` so the Windows app matches the **current workflow structure and client-visible contract** described in `docs/current-workflow-client-slave-sidecar-20260529.md`.

This is **not** a Linux client-agent 1:1 port. The Windows app should continue to delegate Linux-specific networking mechanics to the slave and bundled runtime binaries:

- no Windows-managed WireGuard interface lifecycle;
- no Windows kernel MPTCP endpoint policy;
- no attempt to reproduce `lw-mptcp-*` / `lw-cfwg-*` interface plumbing in C#.

Instead, parity is achieved at the **contract and orchestration layer**:

- desired-state config consumption;
- effective-config building;
- contract-signature / no-op apply;
- preconnect relay health awareness;
- panel `health_status` gating;
- relay-first runtime rendering;
- explicit fallback behavior.

The intended Windows-side structure remains:

`Windows app -> local runtime -> slave preconnect relay -> slave -> sidecar`

## Context

### Authoritative upstream

The authoritative panel/mirror endpoint for this work is:

- `https://v3-mirror.leikwanhost.com/`

The current local desktop binding at `/c/Users/frankniubi/AppData/Local/Leikwan/WindowsClientApp/client-binding.json` still points at `https://v3.leikwanhost.com`, so it is treated only as a **local historical/runtime sample**, not the source of truth for this design.

### Verified current state

From direct inspection of the current C# desktop client:

- `ConfigPollingService` pulls config and applies per-forward runtime, but does **not** compute or persist any effective contract signature.
- apply/no-op behavior is currently based only on "forward exists" + "process still running".
- if a payload changes while the forward process remains alive, the Windows app may never re-apply the changed config.
- `WireGuardManager` manages only identity key generation for heartbeat and does **not** configure client WireGuard interfaces.
- `MptcpRuntimeBuilder` already prefers `slave_relay_host` / `slave_relay_port` when present, which is consistent with the current slave-preconnect architecture.
- `MptcpFallbackService.ConvertToBond()` exists but is not wired into the runtime decision path.

From local runtime evidence:

- historical Windows state under `/c/ProgramData/LeikwanClient/forward_14/forward_14.json` shows that panel-published endpoint metadata such as `health_status` has previously existed in Windows-consumed payloads.
- the currently bound client record returns `forwards: []` from `https://v3.leikwanhost.com/api/client/configs/pull`, so it is **not sufficient** to prove the current real payload shape for Windows on the mirror side.

## Goals

1. Make Windows config application follow the same desired-state model as the current workflow.
2. Introduce a deterministic effective-config + contract-signature layer so unchanged payloads do not trigger churn and changed payloads do trigger re-apply.
3. Keep Windows aligned with the slave-preconnect design by treating the slave relay as the Windows-visible transport entrypoint.
4. Consume and act on panel health and preconnect metadata that matter at the Windows client layer.
5. Preserve per-forward isolation and avoid unnecessary restarts.
6. Keep the implementation focused on C# client parity, not Linux network stack parity.

## Non-goals

This design intentionally does **not** include:

- Windows-managed WireGuard interface creation or `AllowedIPs` orchestration;
- kernel MPTCP parity with Linux;
- direct implementation of Linux `ip mptcp endpoint` behavior;
- Windows direct CF-WG self-leg support unless mirror-side live payloads prove it is required;
- usage telemetry repair via `stats_path` in this phase, because current runtime evidence shows that relay-side stats-file production is not yet implemented.

## Execution Gate-0

Before implementation starts, capture a **real mirror-side Windows payload sample** from `https://v3-mirror.leikwanhost.com/` for a Windows client record that currently has at least one assigned forward.

Gate-0 must confirm:

1. whether Windows is currently assigned real `mptcp.slave_relay_host` / `mptcp.slave_relay_port` payloads;
2. whether `mptcp.underlays[].legs[]` includes:
   - `slave_preconnect`
   - `preconnect_relay_port`
   - `health_status`
   - `outer_transport`
   - any CF/slave dial metadata relevant to Windows rendering;
3. whether CF-prefixed combinations continue to reach Windows through the slave relay path;
4. whether any current Windows payload requires true client-side CF-WG self-leg behavior.

This gate exists because the local bound sample is currently empty and cannot determine the live mirror-side contract shape by itself.

## Proposed design

### 1. Data model updates

Only add fields that the Windows client must actually consume.

#### `EndpointConfig`

Add:

- `health_status`

Keep existing local runtime-observation fields such as:

- `health_role`
- `quality_score`
- `leg_role`

Semantics:

- `health_status` = panel-published authoritative availability state
- `health_role` / `quality_score` = local runtime observation and ranking state

`Clone()` must copy any newly added fields.

#### `UnderlayLeg`

Extend the existing structured model in `LeikwanBridge.Core/Models/UnderlayConfig.cs` with fields confirmed by Gate-0, expected to include:

- `slave_preconnect`
- `preconnect_relay_port`
- `health_status`
- any CF/slave dial metadata Windows must preserve or read

The goal is **not** to reimplement Linux WG behavior in C#, but to let Windows:

- identify preconnect-capable legs;
- derive relay data/health ports;
- gate on panel health;
- preserve required metadata in runtime rendering.

#### `ForwardConfig`

Add only after Gate-0 confirms necessity:

- `candidate_pool`
- `runtime_preference`
- self-leg metadata
- any mirror-side fields required for Windows decision-making

#### `AppliedForwardState`

Extend persisted state with:

- `contract_signature`
- optional `rendered_signature`
- optional `last_apply_reason`

Backward compatibility rule:

- if an old `applied.json` entry has no signature, the first sync after upgrade must treat it as changed and re-apply once.

### 2. Effective-config layer

Introduce a dedicated effective-config layer instead of embedding diff logic inside polling or runtime startup code.

#### New helper: `EffectiveForwardConfigBuilder`

Responsibilities:

- take the raw panel `ForwardConfig`;
- merge client-relevant health/selection state;
- apply authoritative health gating before local ranking;
- produce the final runtime-intent object used for rendering and signature.

This becomes the single place where Windows translates raw desired state into the shape it actually intends to run.

#### New helper: `ForwardContractSignature`

Responsibilities:

- take the effective config;
- canonicalize it into stable JSON;
- strip observation-only fields;
- compute the persisted contract signature.

The signature should be derived from the **effective** contract, not blindly from the raw payload.

That means panel health changes only affect the signature when they affect what Windows would actually render or select.

### 3. Signature / no-op apply behavior

Change the application flow from liveness-only gating to signature-aware gating.

Current problem:

- unchanged payloads usually avoid churn only because the forward is already running;
- changed payloads can be missed entirely if the process is still alive.

New rule:

A forward should apply when any of the following is true:

1. there is no prior applied state;
2. there is prior state but no stored signature;
3. the recomputed effective contract signature differs;
4. the runtime for that forward is not healthy/running;
5. the caller explicitly requests a force rebuild.

A forward should skip apply when:

- the effective contract signature matches;
- the runtime is healthy and present;
- the caller is not forcing rebuild.

### 4. Runtime control flow

Target flow:

`pull -> deserialize -> build effective config -> compute signature -> compare -> apply or skip`

#### `ConfigPollingService`

Responsibilities after refactor:

- pull config;
- compute effective config/signature per forward;
- decide apply/skip;
- treat state removal separately from content changes.

It should no longer rely on raw existence + liveness alone.

#### `ForwardRuntimeManager`

Responsibilities after refactor:

- accept an already-decided effective forward config;
- build runtime spec;
- apply it only when requested;
- support a force path for health-driven rebuilds;
- optionally record `last_apply_reason`.

### 5. Preconnect health behavior

Windows should use relay health as its Windows-visible substitute for inner WG reachability.

Health probe priority:

1. if a selected leg exposes `preconnect_relay_port`, use:
   - `slave_relay_host : (preconnect_relay_port + 1)`
2. otherwise use:
   - `slave_relay_host : (slave_relay_port + 1)`

Probe behavior:

- HTTP GET `/health`
- any 2xx response = healthy
- connection failure / non-2xx = unhealthy

This gives Windows a current-workflow-compatible readiness signal without requiring client-side WG plumbing.

### 6. Health-status gating and local ranking

Selection order must be:

1. filter out panel-published `health_status != active`
2. rank the remaining candidates using local signals, including:
   - preconnect relay health
   - local latency data
   - existing bond endpoint role / quality data where applicable

Rules:

- local observations may rank candidates **within** the active set;
- local observations must not override an authoritative non-active panel state.

This applies to both:

- bond endpoint selection;
- MPTCP preconnect-leg selection where Windows has enough structured data to do so.

### 7. Loopback-hiding semantics

Windows does not need to reproduce Linux implementation details, but it should preserve the same client-facing semantics:

- the local app should primarily connect to the local runtime entrypoint;
- runtime rendering should prefer relay-first semantics over direct sidecar semantics;
- if Gate-0 reveals explicit loopback/local-hidden fields in the mirror payload, Windows should honor them directly.

Practical effect:

- `MptcpRuntimeBuilder` should prefer relay-derived targets over direct sidecar exposure;
- Windows should not regress into treating public sidecar endpoints as the primary direct client target where the current workflow expects relay indirection.

Exact mirror-side fields are finalized after Gate-0.

### 8. Fallback behavior

`MptcpFallbackService.ConvertToBond()` must become a real, explicit fallback path.

Fallback may be used when:

- an MPTCP forward lacks a usable relay entrypoint;
- mirror-side payload indicates a runtime preference that allows bond fallback;
- preconnect legs are unusable and the policy explicitly permits degrading.

Fallback requirements:

- the fallback reason must be explicit and diagnosable;
- fallback should not silently mask normal runtime-health issues;
- health-triggered rebuilds must remain distinct from config-level fallback decisions.

### 9. Force rebuild vs signature no-op

A force rebuild path must bypass signature no-op logic.

This is required so that:

- health-driven restarts;
- dead process recovery;
- broken topology rebuilds

still happen even when the desired config has not changed.

Rule:

- config-driven apply uses signature gating;
- health-driven rebuild uses force apply.

## Planned file impact

Primary files expected to change:

### Models

- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UnderlayConfig.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ForwardConfig.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentState.cs`

### Services

- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MptcpRuntimeBuilder.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MptcpFallbackService.cs`

### New helpers

- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/EffectiveForwardConfigBuilder.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/ForwardContractSignature.cs`

## Testing strategy

### Unit tests

1. **Signature stability**
   - same effective config -> same signature
   - target/token/relay-port/selected endpoint change -> different signature
   - observation-only field change -> same signature

2. **Polling no-op behavior**
   - same payload twice -> second sync skips apply
   - changed payload while runtime lives -> apply occurs
   - old state with no signature -> one forced migration apply

3. **Health-status gating**
   - `health_status != active` candidates are never selected
   - lower latency cannot override a non-active panel state

4. **Preconnect health**
   - 2xx `/health` -> healthy
   - non-2xx / connection failure -> unhealthy
   - leg-specific preconnect port takes precedence over top-level relay port

5. **Fallback behavior**
   - unusable MPTCP relay input -> explicit fallback path triggers
   - health-driven rebuild bypasses signature no-op

### Integration-style tests

- fake mirror payload -> effective config -> signature -> apply/skip pipeline
- fake relay health server -> runtime selection changes
- persisted-state migration from old `applied.json`
- MPTCP payload rendering and bond fallback rendering

## Deferred items

### Deferred: runtime usage telemetry

Do not include relay `stats_path`-based usage accounting in this phase.

Reason:

- current evidence shows relay-side stats-file production is not implemented yet;
- fixing it would require runtime-binary changes, not just C# changes.

### Deferred: Windows direct CF-WG self-leg

Do not include direct Windows CF-WG self-leg support in this phase unless Gate-0 proves it is required by real mirror payloads.

If needed later, it becomes a separate effort involving:

- Windows-side tunnel/runtime support;
- CF-WG-specific rendering;
- additional packaging/runtime complexity.

### Deferred: Linux plumbing parity

Do not attempt to replicate:

- WireGuard `AllowedIPs` orchestration
- `lw-mptcp-*` interface creation
- Linux kernel MPTCP endpoint configuration

Those remain outside the Windows client's responsibilities in this design.

## Risks and open questions

1. **Gate-0 payload availability**
   - a real Windows client with active forwards is required to finalize exact mirror-side field usage.

2. **CF field scope**
   - Gate-0 must decide whether Windows only sees relay-mediated CF semantics or whether any true desktop-side CF-WG path is still live.

3. **Loopback-hiding field shape**
   - final implementation depends on what the mirror payload actually publishes.

4. **Signature input boundaries**
   - Windows-relevant runtime inputs must be included in the signature without accidentally introducing observation churn.

## Implementation order

1. Gate-0: capture a real Windows payload sample from `https://v3-mirror.leikwanhost.com/`
2. extend models with confirmed contract fields
3. add `EffectiveForwardConfigBuilder`
4. add `ForwardContractSignature`
5. refactor `ConfigPollingService` to use effective signature apply/skip
6. update `ForwardRuntimeManager` for no-op + force rebuild + fallback integration
7. add preconnect `/health` probing to `HealthCheckService`
8. apply panel `health_status` gating before local ranking
9. update `MptcpRuntimeBuilder` for relay-first and loopback-hiding semantics
10. add and stabilize tests

## Approval state

This spec reflects the approved direction:

- authoritative upstream is `https://v3-mirror.leikwanhost.com/`
- implementation target is Tier 2 client-contract parity
- execution remains paused until the written spec is reviewed and implementation is separately approved
