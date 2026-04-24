# Leikwan Android Next Design

## Goal

Create a new Android app at `C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next` that combines:

- the working connection and runtime behavior from `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client`
- the stronger Compose UI and screen organization from `C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android`

The new app must ship with:

- full parity for the currently working `android-client` flows in v1
- side-by-side installability with the existing apps via a separate app id and package name
- Compose UI as the primary app shell
- a UI structure that closely follows `leikwan-android`
- no backend contract changes for `ss`, `vless`, `vmess`, `wireguard`, import, subscription, service pull, or VPN ownership flows
- a bond path chosen from the latest Linux source instead of inheriting the stale Android bond implementation blindly

## Current Reality

### `leikwan-android`

Strengths:
- better Compose-first UI shell
- cleaner tunnels, proxies, and settings surface
- better navigation model
- better visual quality and app structure

Weaknesses:
- does not currently connect to nodes reliably enough to be the v1 runtime base
- bond and runtime behavior still need verification against live parity expectations

Key reference files:
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/nav/AppNavigation.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/proxies/ProxiesScreen.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/ui/tunnels/TunnelsScreen.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/BondForwardRunner.kt`
- `leikwan-android/app/src/main/java/com/leikwan/bridge/service/TunnelOrchestrator.kt`

### `android-client`

Strengths:
- working runtime and service stack
- working import, subscription, profile, and VPN flow
- proven operational base for direct usage and services

Weaknesses:
- UI is extremely weak
- activity/view architecture is hard to extend cleanly
- current Android bond branch is behind the latest Linux production contract

Key reference files:
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\MainActivity.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\TunnelEngine.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\LeikwanVpnService.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\RuntimeBinaries.kt`
- `C:\Users\frankniubi\Downloads\leikwan-v3-devs\android-client\app\src\main\java\com\leikwan\androidclient\SingBoxSupport.kt`

## Chosen Product Direction

The new app will be built using `android-client` as the runtime foundation and `leikwan-android` as the UI and UX model.

This means:
- keep the parts that already connect well
- replace the parts that look and feel bad
- modernize bond specifically instead of dragging forward an obsolete implementation

## Alternatives Considered

### Option A: Base on `android-client`, rebuild the UI in Compose, and selectively modernize runtime pieces

Chosen.

Why:
- lowest runtime regression risk
- keeps the proven working transport and VPN ownership base
- allows the UI to improve fast without gambling on connectivity first
- gives room to replace only the bond path with the latest-source implementation model

Tradeoff:
- the first implementation pass will bridge older runtime code under a newer app shell

### Option B: Base on `leikwan-android`, then port every missing working feature from `android-client`

Rejected for v1.

Why:
- too much runtime risk for the first replacement build
- starts from the app that currently has the better UI but the weaker real-world connectivity result

### Option C: Build a new shared Android core first, then build a new UI app on top

Rejected for v1.

Why:
- architecturally nice, but too much refactor before proving a shippable app
- increases time-to-parity without improving first-release confidence enough

## New Project Layout

Create a new sibling project:

- `C:\Users\frankniubi\Downloads\leikwan-br1\leikwan-android-next`

It must have:
- a separate package name
- a separate application id
- a separate app label
- separate storage/app sandbox

This allows side-by-side testing against:
- the old working `android-client`
- the current pretty `leikwan-android`
- the new merged app

## Architecture

The app will be split into two major layers.

### 1. Runtime/Core Layer

This layer inherits from `android-client` behavior and remains responsible for:
- install command parsing
- Android import string parsing
- URI import
- subscription import and refresh
- profile storage
- relay/service config pull
- runtime binary installation
- direct protocol operation
- `VpnService` ownership
- sing-box integration
- service orchestration

For v1, this layer must preserve working behavior for:
- `ss`
- `vless`
- `vmess`
- `wireguard`
- service-mode connection
- subscriptions and imports
- full-device VPN flow

### 2. App/UI Layer

This layer is rebuilt in Compose and follows the patterns already shown in `leikwan-android`.

It is responsible for:
- app shell
- navigation
- tunnel selection screens
- proxy/profile management screens
- settings screens
- improved status and error presentation
- cleaner add/import flows
- basic/advanced editing layouts

## Panel Publish Logic Rule

The new app must treat the panel-published contract as authoritative runtime truth.

Authoritative source:
- `master/src/Support/ProvisioningService.php`
- especially `ProvisioningService::buildClientConfig()`

Operational rule:
- the Android app must consume the exact shape the panel publishes instead of re-deriving slave sets, sidecar targets, ingress auth, or bond settings from local guesses
- service mode UI must display what the panel actually published
- runtime launch decisions must be based on the same published fields the existing Linux and agent paths consume

At minimum, the new app parser and runtime must stay aligned with the published values for:
- `mode`
- `transport`
- `listen_port`
- `target`
- `service_status`
- `speed_limit_mbps`
- `bond_remote_host`
- `bond_remote_port`
- `bonding`
- `mptcp`
- `endpoints[]`, including `host`, `port`, `priority`, `weight`, `health_status`, `supports_quic_leg`, `ingress_protocol`, and `ingress_auth_token`

Verification rule:
- when validating service and bond behavior, compare live runtime behavior against the pulled panel-published contract, not just against local assumptions or loopback state
- if panel-published endpoints and live runtime endpoints differ, treat that as a real mismatch, not as a UI-only issue

## Information Architecture

The new app should follow the stronger `leikwan-android` structure.

### Tunnels

Responsibilities:
- select a service forward
- connect or disconnect the VPN
- show active mode, transport, target, and quick health
- make the service path understandable without dumping raw internals everywhere

### Proxies

Responsibilities:
- manage imported and local profiles
- manage subscriptions
- control direct mode
- show the selected proxy/profile state
- provide clean import and edit entry points

### Settings

Responsibilities:
- backend/account/session details
- runtime diagnostics
- app-level controls
- room in the settings layout for future language or theme controls without making them part of the v1 scope

## Proxy Editor Design

The proxy editor must keep all old capability, but stop presenting everything as a giant debug form.

### Basic section

Show the fields needed for the common case:
- name
- protocol
- server and port or relay binding
- required credentials
- primary TLS toggle where relevant

### Advanced section

Keep the less common but still required controls:
- SNI
- insecure toggle
- protocol-specific transport knobs
- WireGuard detail fields
- optional security and compatibility overrides

Constraint:
- no feature loss is allowed just because the UI got cleaner
- advanced protocol fields remain available in v1

## Import and Add Flow

The new app keeps every useful import path from the old app, but routes them through a cleaner Compose surface.

Supported import paths in v1:
- install command parsing
- Android import string parsing
- URI import
- subscription URL import
- QR-based flows where the old app already provides value

The user should experience one clear ?Add / Import? entry point, not several disconnected operational tools.

## Runtime Status Design

The old app exposes useful operational detail but does it in an ugly and noisy way.

The new app should preserve useful information while improving presentation.

Main status surface should show:
- connection state
- selected service or profile
- last sync time
- selected mode
- high-signal errors in readable cards

Detailed diagnostics should still exist, but as expandable diagnostic views, not the default main screen.

## Bond Strategy

Bond is the one area that should not simply inherit `android-client` behavior.

### Decision

Use the latest Linux-aligned bond path, not the old `android-client` bond branch.

### Evidence

Latest source references:
- `wstunnel-main/leikwan-bond/src/main.rs`
- `wstunnel-main/leikwan-bond/src/session.rs`
- `wstunnel-main/leikwan-bond/src/transport.rs`
- `wstunnel-main/leikwan-bond/src/frame.rs`
- `docs/BOND_OPERATIONS.md`
- `client-agent/install_forward_runtime.py`

Observations from the latest Linux bond path:
- current CLI version in source is `0.1.13`
- client flags include newer warmup, bulk, cooldown, interactive, and latency-guard controls
- the transport layer supports richer `tcp://` and `quic://` leg specs with metadata and priority
- session scheduling includes sticky, warmup, and bulk stages plus leg health and cooldown handling
- production contract for current bond usage centers on `client -> slave -> sidecar`
- current production `plain_tcp_mux` behavior depends on ingress helpers and route/auth material, not the older direct-wstunnel-only assumption

### Consequence for Android

The new app should adopt a bond planner/runtime model that is closer to:
- `leikwan-android/app/src/main/java/com/leikwan/bridge/runtime/BondForwardRunner.kt`
- `client-agent/install_forward_runtime.py`
- `docs/BOND_OPERATIONS.md`

It should not carry forward the stale `android-client` bond implementation in `TunnelEngine.kt` as the v1 reference.

### Bond runtime expectations in the new app

The new Android app should support a Linux-aligned bond flow that includes:
- packaged `leikwan-bond`
- packaged ingress helper where required
- current `bonding` block consumption from the existing backend contract
- current flag surface for join timeout, interactive chunking, latency guard, warmup, bulk promotion, and cooldown
- current leg target rendering model, including richer leg metadata where useful
- the present topology rule `client -> slave -> sidecar`

### Compatibility constraint

For `ss`, `vless`, `vmess`, and `wireguard`, keep the backend/runtime contract exactly as-is in v1.

For bond, assess and adopt the latest-source contract where it improves fidelity to current Linux truth.

## Implementation Boundaries

### Port or preserve first from `android-client`

- config pull and install parsing
- profile and subscription storage
- `VpnService` ownership
- sing-box integration
- runtime binary installation
- direct protocol operation
- working service orchestration

### Port first from `leikwan-android`

- Compose app shell
- navigation model
- theme system
- tunnels screen patterns
- proxies list patterns
- settings screen patterns
- cleaner screen flow and status design

### Rewrite instead of blindly copying

These areas should be reshaped, not transplanted line-for-line:
- `MainActivity`
- top-level app shell
- proxy editor screens
- runtime-state presentation
- the glue between service mode and proxy mode

### Dedicated bond module direction

The new app should have a dedicated modern bond runtime/planning slice that consumes:
- latest `leikwan-bond` CLI expectations
- the current contract emitted by `client-agent/install_forward_runtime.py`
- Android-side planning ideas already present in `BondForwardRunner.kt`

This isolates bond modernization from the rest of the working runtime base.

## Success Criteria for v1

The first ship is successful if:
- the app installs alongside the existing Android apps
- `ss`, `vless`, `vmess`, and `wireguard` direct usage works at least as well as old `android-client`
- service mode works at least as well as old `android-client`
- bond works on the chosen latest-source path
- UI quality is clearly at or above `leikwan-android`
- the main user flow no longer feels like a raw internal debug console

## Verification Strategy

Verification must treat the old working app as the oracle.

### Direct profile flow

Verify:
- profile import or creation
- VPN start
- usable routed traffic

### Service flow

Verify:
- config pull from panel
- service selection
- runtime start
- VPN startup
- usable routed traffic

### Bond flow

Verify:
- new bond binary packaging
- ingress-helper and leg launch shape matches the selected contract
- bond startup does not regress the rest of the app
- current topology rule remains `client -> slave -> sidecar`

### UX flow

Verify:
- setup/import
- edit
- connect
- disconnect
- readable errors
- diagnostics still reachable when needed

## Main Risks

### 1. Bond drift risk

The latest Linux bond source is ahead of old Android assumptions. If the new app reuses the stale bond branch, it will freeze that drift into the replacement app.

### 2. Compose migration risk

Rebuilding the UI on top of old runtime state can create a messy state layer if the app shell is only half-converted.

### 3. `VpnService` / sing-box regression risk

The working full-device path in `android-client` is valuable and should be preserved carefully.

### 4. Feature loss risk

A cleaner editor can accidentally delete weird but necessary protocol fields. Basic/Advanced structure is the fix, but only if advanced controls remain complete.

## Execution Rule

Build in this order:
1. create `leikwan-android-next`
2. make it compile and own the new identity
3. preserve runtime parity first
4. replace screens with Compose one slice at a time
5. modernize bond with explicit verification against the latest-source path
6. keep the old app installed as the comparison oracle during testing

## Out of Scope for This Design

This design does not include:
- backend breaking changes for the non-bond protocols
- a full shared-core rewrite before proving the new app works
- dropping advanced protocol controls in the name of simplicity
- changing the topology away from `client -> slave -> sidecar`

## Final Product Statement

`leikwan-android-next` is a new Android app that keeps the old app?s working connectivity, upgrades bond to the current Linux-aligned model where needed, and adopts the stronger Compose UI and navigation patterns from `leikwan-android` so the product is both usable and operationally strong.
