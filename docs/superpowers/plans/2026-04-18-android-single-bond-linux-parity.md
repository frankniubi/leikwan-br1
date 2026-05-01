# Android Single/Bond Linux-Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the Android app so it mirrors Linux-supported `single` and `bond` forward behavior, automatically filters unsupported `mptcp` forwards, and produces a reliable `sing-box` config against the selected local runtime.

**Architecture:** Parse the full panel forward contract into Android runtime models, filter unsupported `mptcp` entries before they reach the UI, then branch runtime orchestration into Linux-parity `single` and `bond` launch paths. Build `sing-box` from the selected runtime context instead of today's loose listen-port map so VPN startup depends on a real local transport contract.

**Tech Stack:** Kotlin, Jetpack Compose, Retrofit/Gson, Room, Android `VpnService`, libbox (`sing-box`), ABI-packaged native executables (`wstunnel`, `leikwan-ingress`, `leikwan-bond`).

---

## Autoplan Review Summary

### CEO

- Keep scope tight. Android should support the modes it can honestly run today: `single` and `bond`.
- Do not spend this pass inventing Android `mptcp`. Hide unsupported `mptcp` forwards automatically instead.

### Design

- Filtering should be quiet and safe, not destructive.
- Show only runnable forwards in the list and optionally a small note like "2 unsupported MPTCP forwards hidden" instead of a blocking modal.

### Engineering

- Linux runtime generation in `client-agent/install_forward_runtime.py` is the contract to copy.
- The biggest technical gaps are runtime packaging, mode branching, and `plain_tcp_mux` ingress handling.
- `sing-box` must be rebuilt around a runtime context object, not just a listen port map.

### DX / Ops

- Avoid hidden magic. Runtime failures should say which executable or field is missing.
- Keep login/settings flows stable so verification focuses on tunnel correctness, not UI churn.

## What Already Exists

- Panel pull + caching:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`
- Forward list UI:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt`
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`
- VPN service:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt`
- Current orchestration:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- Current bond launcher:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/bond/BondManager.kt`
- Current `sing-box` builder:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`

## Not In Scope

- Android `mptcp` runtime support
- UDP parity for `bond + plain_tcp_mux`
- a new proxy management UX
- panel API changes
- iOS or desktop changes

## Current Findings That Drive This Plan

1. Android build is currently green with `./gradlew.bat :app:compileDebugKotlin`, so this is not a "fix the broken project first" plan.
2. `master/src/Support/ProvisioningService.php` already ships the fields Android needs:
   - normalized `mode`
   - `bonding`
   - `mptcp`
   - `endpoints[].ingress_protocol`
   - `endpoints[].ingress_auth_token`
3. `TunnelOrchestrator.startForForward()` always enters bond startup, so `single` is not implemented correctly today.
4. Android currently ships only `libleikwan_bond.so`, not executable `wstunnel` or `leikwan-ingress`.
5. Current Android bond launch does not implement Linux `plain_tcp_mux`.
6. There are no Android tests yet for the runtime contract.

## File Map

### Modify

- `leikwan-android/app/build.gradle.kts`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/bond/BondManager.kt`

### Create

- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardMode.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeBinaryInstaller.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ProcessRunner.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/SingleForwardRunner.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/BondForwardRunner.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeContext.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/ForwardFilterTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/SingleForwardRunnerTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/BondForwardRunnerTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt`
- `leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelRepositoryTest.kt`

### Package / Assets

- `leikwan-android/app/src/main/assets/runtime/arm64-v8a/wstunnel`
- `leikwan-android/app/src/main/assets/runtime/arm64-v8a/leikwan-ingress`
- `leikwan-android/app/src/main/assets/runtime/arm64-v8a/leikwan-bond`

## Architecture Diagram

```text
PanelRepository
  -> raw panel JSON cache
  -> ForwardContract parser
  -> ForwardFilter
      -> visibleForwards (`single`, `bond`)
      -> hiddenMptcpCount

TunnelsViewModel
  -> selected supported forward
  -> start/stop requests

TunnelOrchestrator
  -> RuntimeBinaryInstaller
  -> SingleForwardRunner / BondForwardRunner
  -> RuntimeContext
  -> SingboxConfigBuilder
  -> LeikwanVpnService
```

## Task 1: Model The Linux Contract In Android

**Files:**

- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelModels.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardMode.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardContract.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ForwardFilter.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/api/PanelRepositoryTest.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/ForwardFilterTest.kt`

- [ ] Add a Kotlin mode normalizer that matches Linux aliases exactly:
  - `single`
  - `race`
  - `bond`
  - `mptcp`
- [ ] Expand the Android forward contract to parse the runtime-relevant fields Android currently ignores:
  - `bonding.ingress_mode`
  - `bonding.route_key`
  - `bonding.session_token`
  - `bonding.join_timeout_ms`
  - warmup / bulk / cooldown flags
  - `mptcp`
  - endpoint `ingress_protocol`
  - endpoint `ingress_auth_token`
- [ ] Keep a raw-config cache keyed by `forward_id`, but also return a typed catalog object that includes:
  - visible supported forwards
  - hidden unsupported `mptcp` count
  - normalized selected-mode metadata
- [ ] Add unit tests that prove Android normalization matches the Linux alias table from `install_forward_runtime.py` and `ProvisioningService.php`.

## Task 2: Filter Unsupported MPTCP Forwards Before UI Selection

**Files:**

- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/data/Prefs.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/ForwardFilterTest.kt`

- [ ] Change forward refresh so `mptcp` entries are hidden automatically from the selectable tunnel list.
- [ ] If the previously saved `selectedForwardId` now points at an unsupported `mptcp` forward, clear it immediately.
- [ ] Add a small UI note for hidden unsupported rules:
  - example: `Hidden 2 unsupported MPTCP forwards`
- [ ] Prevent tunnel startup if the selected forward id no longer resolves to a visible supported forward.
- [ ] Add tests for:
  - stale selected `mptcp` forward gets cleared
  - supported `single` / `bond` forwards remain visible
  - hidden count is accurate

## Task 3: Package Runtime Executables For Android

**Files:**

- Modify: `leikwan-android/app/build.gradle.kts`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeBinaryInstaller.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/ProcessRunner.kt`
- Package: `leikwan-android/app/src/main/assets/runtime/arm64-v8a/wstunnel`
- Package: `leikwan-android/app/src/main/assets/runtime/arm64-v8a/leikwan-ingress`
- Package: `leikwan-android/app/src/main/assets/runtime/arm64-v8a/leikwan-bond`

- [ ] Stop treating `jniLibs/libleikwan_bond.so` as the executable story for Android runtime launch.
- [ ] Add an installer that copies ABI-specific executables from app assets into an app-owned runtime directory, for example:
  - `${filesDir}/runtime/arm64-v8a/wstunnel`
  - `${filesDir}/runtime/arm64-v8a/leikwan-ingress`
  - `${filesDir}/runtime/arm64-v8a/leikwan-bond`
- [ ] Ensure the installer sets executable permissions and refreshes files when the packaged version changes.
- [ ] Centralize subprocess launch in `ProcessRunner` so later tests can assert exact commands without needing to spawn real binaries.
- [ ] Manual verification target:
  - the installed runtime directory exists
  - each expected binary is present
  - each binary path is what the runners use

## Task 4: Implement Linux-Parity `single` Runtime Launch

**Files:**

- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/SingleForwardRunner.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/SingleForwardRunnerTest.kt`

- [ ] Add a `SingleForwardRunner` that mirrors Linux `single` runtime logic:
  - pick the first usable endpoint
  - build a direct `wstunnel client` command
  - bind the selected local listen port
  - honor `transport`
  - reuse panel `sni` and `path_prefix`
- [ ] Update `TunnelOrchestrator` so `single` no longer falls into bond launch.
- [ ] Make runner output an explicit runtime context object containing:
  - selected forward id
  - local listen port
  - mode = `single`
  - bypass hosts/IPs needed by `sing-box`
- [ ] Add tests that assert the generated `single` command matches Linux shape for:
  - TCP-only forward
  - `tcp+udp` forward when Android ships the needed runtime

## Task 5: Implement Linux-Parity `bond` Runtime Launch

**Files:**

- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/bond/BondManager.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/BondForwardRunner.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/runtime/BondForwardRunnerTest.kt`

- [ ] Replace the current direct-leg Android bond path with Linux `plain_tcp_mux` behavior:
  - start one `leikwan-ingress client` per endpoint
  - pass endpoint host/port, `bonding.route_key`, and endpoint `ingress_auth_token`
  - wait for every local leg port to become ready
  - only then start `leikwan-bond client`
- [ ] Bring `BondManager` up to Linux CLI parity by passing:
  - `--join-timeout-ms`
  - `--warmup-trigger-bytes`
  - `--warmup-slice-bytes`
  - `--warmup-leg-delay-ms`
  - `--bulk-promote-bytes`
  - `--leg-cooldown-ms`
- [ ] Support Linux fallback semantics for non-`plain_tcp_mux` bond ingress by using packaged `wstunnel` if those older forwards still exist.
- [ ] Return a `RuntimeContext` that includes:
  - local listen port
  - local leg ports
  - slave ingress hosts
  - sidecar direct host/port
- [ ] Add unit tests that assert:
  - one ingress helper command is rendered per endpoint
  - `plain_tcp_mux` bond requires auth tokens
  - bond CLI flags match the Linux field set

## Task 6: Rebuild Tunnel Orchestration Around Explicit Runtime Context

**Files:**

- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- Create: `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/RuntimeContext.kt`
- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt`

- [ ] Refactor `TunnelOrchestrator.startForForward()` into:
  - pull configs
  - resolve selected typed forward contract
  - launch `single` or `bond`
  - build `sing-box`
  - start VPN service
- [ ] Ensure stop/start is deterministic:
  - stop existing VPN
  - stop existing child processes
  - clear previous runtime context
  - start new mode-specific runtime
- [ ] Fail early for unsupported states with concrete messages:
  - hidden/unsupported mode selected
  - missing packaged executable
  - missing ingress auth token
  - zero usable endpoints
- [ ] Keep the final runtime context available to the `sing-box` builder and UI status path.

## Task 7: Fix `sing-box` Generation

**Files:**

- Modify: `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`
- Test: `leikwan-android/app/src/test/java/com/leikwan/bridge/singbox/SingboxConfigBuilderTest.kt`

- [ ] Change `SingboxConfigBuilder.build(...)` so it accepts the selected `RuntimeContext`, not only `forwardListenPorts` plus `slaveIps`.
- [ ] Ensure the resulting config explicitly keeps control-plane traffic direct:
  - panel host
  - slave ingress IPs
  - sidecar direct host when needed
  - existing hard-coded LeiKwan control domains
- [ ] Keep the current proxy-node import story, but make the builder mode-aware and selected-forward-aware.
- [ ] Respect `ProxyNode.forwardId` when present, so explicitly bound nodes do not get routed through the wrong selected forward.
- [ ] Add JSON-shape tests for:
  - no proxy nodes fallback
  - selected `single` forward
  - selected `bond` forward with slave bypass rules
  - `forwardId`-bound node selection

## Task 8: Verification And Release Readiness

**Files:**

- Modify: `leikwan-android/app/build.gradle.kts`
- Test: all new JVM tests under `leikwan-android/app/src/test/java/...`

- [ ] Add the missing Android unit-test dependencies needed for JVM tests around Gson/runtime builders.
- [ ] Run:
  - `./gradlew.bat :app:testDebugUnitTest`
  - `./gradlew.bat :app:compileDebugKotlin`
- [ ] Manual smoke checklist:
  - login succeeds
  - tunnel list hides `mptcp` forwards automatically
  - selecting `single` starts local runtime plus VPN cleanly
  - selecting `bond` starts ingress helpers, then bond, then VPN
  - disconnect stops child processes and VPN cleanly
- [ ] Record any remaining blockers explicitly before shipping, especially:
  - missing Android-compatible binaries for additional ABIs
  - any gap between packaged runtime behavior and Linux parity

## Cross-Phase Themes

- Contract honesty beats fake support. The whole plan works only if Android shows and starts only the modes it truly supports.
- Runtime packaging is not an implementation detail. Without it, neither `single` nor `bond` can actually match Linux.
- `sing-box` correctness depends on runtime correctness. Fixing the config builder without fixing local runtime selection would still leave the app in a broken state.
