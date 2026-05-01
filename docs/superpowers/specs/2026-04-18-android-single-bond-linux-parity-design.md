# Android Single/Bond Linux-Parity Design

## Goal

Upgrade the Android app so it behaves like the Linux client for the modes Android should actually support today:

- `single`
- `bond`

In the same pass:

- automatically filter unsupported `mptcp` forwards out of the Android control surface
- fix the `sing-box` integration so it is built from the selected local runtime instead of today's half-wired assumptions

## What Already Exists

- Android app login and forward pull flow:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/api/PanelRepository.kt`
- tunnel selection UI:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsViewModel.kt`
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`
- Android VPN/libbox service:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/service/LeikwanVpnService.kt`
- current Android tunnel orchestrator:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`
- current Android bond launcher:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/bond/BondManager.kt`
- current `sing-box` JSON builder:
  - `leikwan-android/app/src/main/java/com/leikwan/bridge/singbox/SingboxConfigBuilder.kt`
- Linux truth sources:
  - `client-agent/install_forward_runtime.py`
  - `master/src/Support/ProvisioningService.php`
  - `docs/BOND_OPERATIONS.md`

## Current Problems

1. Android currently displays every pulled forward, including `mptcp`, even though there is no Android `mptcp` runtime.
2. `TunnelOrchestrator.startForForward()` always calls bond startup, so `single` and `mptcp` both flow into the wrong runtime path.
3. Android does not currently ship the Linux-equivalent executables needed for parity:
   - no `wstunnel`
   - no `leikwan-ingress`
   - only `libleikwan_bond.so`, which is being treated like an executable
4. The Android bond path does not implement Linux `plain_tcp_mux` ingress. It uses older `bond_leg` / `relay_host` assumptions instead of current `bonding.route_key` plus `ingress_auth_token`.
5. `BondManager` is behind the current Linux CLI surface and omits newer flags such as join timeout and warmup/bulk/cooldown tuning.
6. `SingboxConfigBuilder` is not built from a runtime contract object. It only knows about proxy nodes and a listen port map, so it cannot reason cleanly about:
   - selected forward mode
   - control-plane bypass hosts
   - slave ingress hosts
   - sidecar direct targets
   - unsupported forward modes
7. There is no Android test coverage for panel parsing, mode filtering, runtime command generation, or `sing-box` config generation.

## Design Decisions

### 1. Support only Linux-parity `single` and `bond` on Android

Android should not fake `mptcp` support.

If the pulled rule set contains `mptcp` forwards:

- exclude them from the selectable tunnel list
- clear a stale selected `mptcp` forward
- surface a small UI note that unsupported `mptcp` forwards were hidden

This keeps the control surface honest.

### 2. Mirror Linux runtime branching

Android should branch the selected forward by the same normalized mode names Linux uses:

- `single`
- `bond`
- `mptcp`
- aliases normalized the same way as Linux

Behavior:

- `single`: launch a direct `wstunnel` client against the first usable endpoint
- `bond`: launch one ingress helper per endpoint, wait for local leg readiness, then launch `leikwan-bond`
- `mptcp`: never enter runtime launch on Android in this pass

### 3. Treat executable packaging as a first-class part of the fix

The Android app cannot reach Linux parity without local executables.

Ship ABI-specific executables for the Android app and install them into an app-owned runtime directory on first use:

- `wstunnel`
- `leikwan-ingress`
- `leikwan-bond`

Do not rely on executing `libleikwan_bond.so` directly from `jniLibs`.

### 4. Build `sing-box` from a runtime context object

`SingboxConfigBuilder` should stop accepting only raw `forwardListenPorts` plus `slaveIps`.

Instead, build from a selected runtime context that explicitly includes:

- selected forward id
- selected local listen port
- normalized mode
- direct-bypass IP/domain set for panel + slave + sidecar/control plane
- active local runtime tags or ports

That makes `sing-box` configuration deterministic and mode-aware.

### 5. Keep scope tight

This design does not include:

- Android `mptcp` runtime implementation
- Android UDP parity for `bond + plain_tcp_mux`
- proxy-node-to-forward reassignment UX redesign
- cross-device sync for imported proxy nodes

## Proposed Architecture

```text
PanelRepository
  -> ForwardConfigNormalizer
      -> supported forward summaries (`single`, `bond`)
      -> hidden unsupported count (`mptcp`)
      -> raw runtime contract map

TunnelsViewModel
  -> visible supported forwards
  -> selected forward id
  -> hidden-mptcp notice

TunnelOrchestrator
  -> RuntimeBinaryInstaller
  -> SingleForwardRunner OR BondForwardRunner
  -> SingboxConfigBuilder
  -> LeikwanVpnService
```

### Runtime flow: `single`

```text
pull config
  -> normalize mode = single
  -> install/extract `wstunnel`
  -> start local `wstunnel` listener for selected forward
  -> build `sing-box` JSON against selected local listen port
  -> start libbox VPN
```

### Runtime flow: `bond`

```text
pull config
  -> normalize mode = bond
  -> install/extract `leikwan-ingress` + `leikwan-bond`
  -> start one ingress helper per endpoint
  -> wait for local leg ports to listen
  -> start `leikwan-bond client` with Linux-parity flags
  -> build `sing-box` JSON against selected local listen port
  -> start libbox VPN
```

## Testing Strategy

- JVM unit tests for mode normalization and filtering
- JVM unit tests for single and bond runtime command generation
- JVM unit tests for `sing-box` JSON shape and bypass rules
- one Android smoke pass:
  - login
  - confirm `mptcp` forwards are hidden
  - start a `single` forward
  - start a `bond` forward
  - confirm VPN service starts and disconnects cleanly

## Approval Notes

This design intentionally chooses honesty over feature count:

- Android supports what it can truly run now
- Android hides what it cannot
- Android copies Linux runtime behavior instead of inventing a third contract
