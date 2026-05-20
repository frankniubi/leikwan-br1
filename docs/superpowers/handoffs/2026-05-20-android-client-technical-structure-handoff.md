# LeiKwan Br1 Android Client Technical Structure and Logic Handoff

Generated: 2026-05-20  
Inspected checkout: `codex/cfwg47-inline-fix-20260520` at `92403f8`  
Scope: current source under `leikwan-android/` in this checkout. This document describes the current Android app structure and runtime logic, not older/stale Android worktrees unless explicitly noted.

## 1. Executive summary

The current Android client is a native Kotlin app using:

- Jetpack Compose UI
- Room for proxy/subscription storage
- EncryptedSharedPreferences for account/session/preferences
- Retrofit/OkHttp for panel API calls
- sing-box `libbox.aar` for VPN/TUN routing
- packaged arm64 runtime binaries for LeiKwan service transports

The app has two connection modes:

1. `SERVICES`: user selects a panel forward and a proxy node. The app starts local LeiKwan runtime processes for the selected forward, then points sing-box proxy outbound at `127.0.0.1:<forward.listen_port>`.
2. `DIRECT`: user selects an imported proxy node. sing-box connects directly to that proxy without panel forward runtime processes.

Current Android forward support is intentionally narrower than Linux:

- visible/supported: `single`, `bond`
- hidden/unsupported: `mptcp`, `race`

There is no current Android MPTCP implementation. The app hides MPTCP forwards instead of falling back to bond. There is also no latency watchdog/quarantine loop equivalent to the current Linux client-agent or the Windows `BondEndpointHealthService`; Android supervises child processes and waits for local ready ports, but it does not periodically score CF legs by RTT.

Default panel URL is `https://v3-mirror.leikwanhost.com`.

## 2. Key source map

```text
leikwan-android/
  settings.gradle.kts
  build.gradle.kts
  app/
    build.gradle.kts
    libs/libbox.aar                         sing-box libbox Android AAR
    src/main/AndroidManifest.xml
    src/main/assets/runtime/arm64-v8a/      executable fallback assets
      leikwan-bond
      leikwan-ingress
      wstunnel
    src/main/jniLibs/arm64-v8a/             packaged native-library executable names
      libleikwan_bond.so
      libleikwan_ingress.so
      libwstunnel.so
    src/main/java/com/leikwan/bridge/
      LeikwanApp.kt
      api/                                  panel API models/repository
      data/                                 Room entities/DAOs and encrypted prefs
      proxy/                                proxy/subscription import parsers
      runtime/                              forward contract parser and child runtime planners
      service/                              VPN service, tunnel orchestrator, TUN planning
      singbox/                              sing-box config builder
      ui/                                   Compose screens/viewmodels/navigation
    src/test/java/com/leikwan/bridge/       unit tests
```

Current manifest package/application id:

```text
com.leikwan.bridge
```

The prior `android-next` memory mentions package `com.leikwan.androidnext` for a separate worktree/device investigation. This handoff reflects the current inspected `leikwan-android` tree.

## 3. Build and packaging structure

Gradle configuration:

- Android Gradle plugin: `8.5.1`
- Kotlin: `2.0.0`
- Compose enabled
- compile/target SDK: `34`
- min SDK: `26`
- Java/Kotlin target: `17`
- versionName: `0.1.0`

Main dependencies:

- Compose Material3/navigation/lifecycle
- Retrofit + Gson converter
- OkHttp
- Room + KSP
- AndroidX security crypto
- CameraX + ML Kit barcode scanning
- sing-box `libbox.aar` if present
- Kotlin coroutines
- JUnit/coroutines test for unit tests

Runtime binaries are currently arm64 only:

```text
app/src/main/assets/runtime/arm64-v8a/leikwan-bond
app/src/main/assets/runtime/arm64-v8a/leikwan-ingress
app/src/main/assets/runtime/arm64-v8a/wstunnel
app/src/main/jniLibs/arm64-v8a/libleikwan_bond.so
app/src/main/jniLibs/arm64-v8a/libleikwan_ingress.so
app/src/main/jniLibs/arm64-v8a/libwstunnel.so
```

`RuntimeBinaryInstaller` prefers the `jniLibs` native-library directory path and falls back to extracting asset binaries into `filesDir/runtime/<abi>/` with executable permissions.

## 4. Permissions and Android components

Manifest permissions:

```text
INTERNET
FOREGROUND_SERVICE
FOREGROUND_SERVICE_SPECIAL_USE
POST_NOTIFICATIONS
CAMERA
ACCESS_NETWORK_STATE
```

Components:

- `LeikwanApp`: application class, currently empty.
- `MainActivity`: launcher, singleTop, handles text share imports.
- `LeikwanVpnService`: non-exported `VpnService`, foreground service type `specialUse`, bound by Android VPN permission.

Network security config allows cleartext only to `127.0.0.1`, needed for local runtime/sing-box loopback behavior. App-wide `usesCleartextTraffic` is false.

## 5. App startup and navigation

`MainActivity` is the UI entrypoint.

Startup flow:

1. Enables edge-to-edge UI.
2. Processes incoming `ACTION_SEND text/plain` intents as import candidates.
3. Checks `Prefs.isLoggedIn()`.
4. Shows `LoginScreen` when not logged in.
5. Shows `MainScreen` when logged in.

`MainScreen` has bottom navigation:

- `Tunnels`: panel forward list and service VPN start/stop.
- `Proxies`: imported proxy nodes, subscriptions, direct mode start.
- `Settings`: DNS/bypass settings, client switching, logout.

`MainActivity` also:

- requests Android VPN permission through `VpnService.prepare`
- watches clipboard on resume and emits importable links through a snackbar path
- receives share-intent imports through a `MutableSharedFlow`

## 6. Persistence model

### 6.1 Encrypted preferences

`Prefs` wraps `EncryptedSharedPreferences` named `leikwan_secure_prefs`.

Important keys:

```text
panel_url                 default https://v3-mirror.leikwanhost.com
token                     bearer token from panel login
username, email
password                  stored encrypted to support client switching
client_id, client_name
clients_json              cached login response clients
selected_forward_id
selected_proxy_id
proxy_connection_mode     services/direct
dns_server                default 1.1.1.1
dns_fallback              default 8.8.8.8
dns_strategy              default prefer_ipv4
bypass_lan                default true
bypass_china              default false
custom_bypass
```

`isLoggedIn()` is true when both `token` and `clients_json` exist.

`logout()` removes auth/session/client/proxy-selection/mode fields but keeps DNS/bypass settings.

### 6.2 Room database

Room database name:

```text
leikwan_bridge.db
```

Entities:

`ProxyNode`:

- id, name, type
- original server/port
- protocol-specific `configJson`
- optional `subscriptionId`
- optional `forwardId` binding
- enabled flag, created timestamp

`Subscription`:

- id, name, URL
- last updated timestamp
- auto-refresh settings

DAOs provide list/insert/update/delete and subscription-node replacement operations.

## 7. Panel API logic

Implemented by `PanelApi` and `PanelRepository`.

| Purpose | Method | Endpoint | Notes |
|---|---:|---|---|
| Login | `POST` | `api/client/login` | body includes email/password and optional `client_id` |
| Pull configs | `GET` | `api/client/configs/pull` | bearer token |
| Heartbeat | `POST` | `api/client/heartbeat` | bearer token; method exists but no scheduled caller was seen |
| Fetch one forward | `GET` | `api/client/config/{forwardId}` | method exists; current main flow uses pull catalog cache |

`PanelRepository.login(panelUrl, email, password)`:

1. Saves `panelUrl`.
2. Rebuilds Retrofit API with that base URL.
3. Calls `api/client/login`.
4. Stores token, username, email, encrypted password, selected client id/name, clients JSON.
5. Clears selected forward id.

`PanelRepository.switchClient(clientId)`:

1. Reuses stored email/password.
2. Calls the same login endpoint with `client_id` set.
3. Stores new token/client id/name.
4. Clears selected forward id and cached forward configs/contracts.

Unlike the Windows app, Android currently does not send stable `X-Device-Id` / `X-Device-Name` / `X-Device-Platform` headers during pull/config calls. If panel-side device accounting depends on those headers, Android needs parity work.

## 8. Login and settings screens

### 8.1 Login

`LoginViewModel` calls `PanelRepository.login`.

If more than one client is returned, it shows a client picker. `selectClient(clientId)` then calls `repo.switchClient(clientId)`.

If a single client is returned, it enters the logged-in state directly.

### 8.2 Settings

`SettingsViewModel` exposes:

- DNS server/fallback/strategy
- LAN/China/custom bypass settings
- username/current client info
- cached list of client choices
- client switching through `repo.switchClient`
- logout

The Settings screen is also the current place for client switching after login.

## 9. Proxy import and proxy storage

The app can import:

- `ss://`
- `vmess://`
- `vless://`
- HTTP/HTTPS subscription URLs
- multi-line mixed input
- base64 subscription-like payloads

Entry points:

- share intent text into `MainActivity.pendingImport`
- clipboard detection into snackbar action
- manual Add Proxy dialog
- QR scan screen
- subscription URL add/refresh

`ImportRouter` classifies input as proxy nodes, subscription URL, mixed, or empty.

`ProxyUriParser` supports:

- Shadowsocks, including flexible base64 userinfo, plugin/plugin_opts, network, UDP-over-TCP flag, multiplex flag.
- VMess base64 JSON.
- VLESS URI with query params into config JSON.

`SubscriptionFetcher` downloads subscription URLs with OkHttp and parses body through `ProxyUriParser.parseMultiple`.

## 10. Connection modes

`ProxyConnectionMode`:

```text
SERVICES  -> use selected panel forward runtime plus selected proxy node
DIRECT    -> use selected proxy node directly, no panel forward runtime
```

### 10.1 Services mode

Start path is from Tunnels screen.

Requirements:

- selected visible forward id
- selected enabled proxy node
- if proxy node has `forwardId`, it must match the selected forward

Data path:

```text
device apps
  -> Android VPN TUN
  -> libbox/sing-box
  -> proxy outbound to 127.0.0.1:<forward.listen_port>
  -> local LeiKwan forward runtime
  -> slave/sidecar/service path
```

### 10.2 Direct mode

Start path is from Proxies screen, not Tunnels screen.

Data path:

```text
device apps
  -> Android VPN TUN
  -> libbox/sing-box
  -> selected proxy original server:port
```

When switching back to Services mode, `ProxiesViewModel.setConnectionMode` stops any existing direct orchestrator session.

### 10.3 Proxy selection policy

`ProxySelectionPolicy.validate` is intended to reject:

- no selected proxy
- selected proxy no longer exists
- disabled selected proxy
- service-bound proxy that does not match selected forward in Services mode

Current code has a small logic bug: the `selectedProxyId == 0L` branch constructs an error but does not return it. The next lookup still fails and returns `Selected proxy no longer exists`, so behavior remains a failure but the user-facing message is less precise.

## 11. Forward catalog parser and support filter

`ForwardContractParser` reads panel forward JSON into `ForwardContract`.

Parsed fields include:

- `forward_id`, `service_name`, `target`, `listen_port`
- `mode`, `transport`, `service_status`, `endpoint_mode`
- `sni`, `path_prefix`, `speed_limit_mbps`
- endpoints with host/port/auth/priority/health/support flags
- `bonding` block with ingress/auth/tuning/QUIC/WSS/adaptive timeout fields
- a minimal `mptcp` block with sidecar host/port

`ForwardMode.normalize` aliases:

```text
single/default -> SINGLE
race/failover/multi-path/lb -> RACE
bond/bonding/aggregate/aggregated -> BOND
mptcp/mptcp-wireguard/mptcp-ipsec/multipath-tcp -> MPTCP
```

`ForwardFilter.buildCatalog` currently:

- shows `SINGLE` and `BOND`
- hides `MPTCP` and increments `hiddenUnsupportedMptcpCount`
- hides `RACE`
- clears stale selected forward if it is no longer visible

This is the main reason Android currently cannot start MPTCP/race forwards from normal UI.

## 12. Tunnels flow

`TunnelsViewModel` owns Tunnels screen state:

- current VPN status
- visible forward list
- selected forward id
- hidden unsupported MPTCP count
- connection mode
- loading/error state

On init and refresh:

1. Calls `PanelRepository.pullCatalog(selectedForwardId)`.
2. Saves returned selected id into prefs.
3. Updates visible list and hidden MPTCP count.

`toggleVpn()` behavior:

- If currently connected/connecting: set disconnected state and call `TunnelOrchestrator.stop()`.
- If disconnected in Direct mode: reject with `Direct mode starts from the Proxies screen`.
- If disconnected in Services mode with no selected forward: reject.
- If selected forward is no longer visible/supported: clear it and reject.
- Otherwise call `TunnelOrchestrator.startForForward(selectedId)` and set connected on success.

## 13. TunnelOrchestrator end-to-end start logic

`TunnelOrchestrator.startForForward` is the central runtime startup function.

Steps:

1. Read `proxyConnectionMode`, all proxy nodes, selected proxy id.
2. Clear selected proxy id if the selected node no longer exists.
3. Pull fresh forward catalog from panel.
4. In Services mode, require selected forward to exist in visible catalog.
5. Validate selected proxy with `ProxySelectionPolicy`.
6. Stop existing VPN/runtime with `stop()`.
7. If Services mode:
   - load cached `ForwardContract`
   - prepare runtime binary paths
   - build `RuntimeContext` with `SingleForwardRunner` or `BondForwardRunner`
   - start runtime processes with `ProcessRunner`
8. Build sing-box JSON from prefs, selected proxy node, runtime context, and connection mode.
9. Write debug copy to `filesDir/latest-singbox-config.json`.
10. Set `LeikwanVpnService.configJson` and start the foreground VPN service.
11. Wait up to 10 seconds for `LeikwanVpnService.startupState` to become RUNNING.
12. If VPN startup fails/times out, stop started runtime processes and throw the error.

## 14. Runtime binary location and process supervision

### 14.1 Binary installer

`RuntimeBinaryInstaller.prepareBinaryPaths()` chooses ABI from `Build.SUPPORTED_ABIS.firstOrNull()`.

For each runtime role it first checks native library directory:

```text
libwstunnel.so
libleikwan_ingress.so
libleikwan_bond.so
```

If native library file is absent and asset exists, it extracts:

```text
assets/runtime/<abi>/wstunnel        -> files/runtime/<abi>/wstunnel
assets/runtime/<abi>/leikwan-ingress -> files/runtime/<abi>/leikwan-ingress
assets/runtime/<abi>/leikwan-bond    -> files/runtime/<abi>/leikwan-bond
```

### 14.2 ProcessRunner and GuardedProcessPool

`ProcessRunner.start(forwardId, runtimeContext)`:

1. Stops existing pool for the same forward.
2. Runs `AndroidRuntimeJanitor.cleanupStaleRuntimeProcesses()`.
3. Starts each `RuntimeProcessPlan` through `GuardedProcessPool`.
4. Waits for `readyPort` on plans that require local readiness, typically ingress helpers.
5. Stores the pool by forward id.

`GuardedProcessPool`:

- starts child processes using `ProcessBuilder`
- pipes stdout/stderr to Android log
- restarts a process after a healthy-lived exit
- treats too-fast exit as fatal through `onFatal`
- destroys/forcibly destroys processes on close

`AndroidRuntimeJanitor` parses `ps -A` and kills stale processes whose command lines contain runtime names (`libleikwan_ingress.so`, `libleikwan_bond.so`, `libwstunnel.so`, `leikwan-ingress`, `leikwan-bond`, `wstunnel`) excluding current PID.

## 15. Runtime plan rendering

### 15.1 RuntimeContext

`RuntimeContext` contains:

- forward id
- mode
- local listen port
- local leg ports
- process plans
- bypass hosts to force direct routing in sing-box

`RuntimeProcessRole` values:

```text
SINGLE
INGRESS
BOND
```

### 15.2 Single mode

`SingleForwardRunner.buildPlan` renders one `wstunnel` command.

TCP shape:

```text
wstunnel client \
  -L tcp://127.0.0.1:<listen_port>:<target> \
  --tls-sni-override <sni> \
  --http-headers "Host: <sni>" \
  --http-upgrade-path-prefix <path_prefix> \
  wss://<endpoint.host>:<endpoint.port>
```

UDP adds:

```text
-L udp://127.0.0.1:<listen_port>:<target>?timeout_sec=0
```

Bypass hosts include endpoint host and optional CDN host.

### 15.3 Bond mode protocol segments

`BondForwardRunner` supports transports:

- `tcp`: one TCP bond runtime
- `udp`: one UDP bond runtime
- `tcp+udp`: both TCP and UDP runtimes

Token requirements:

- TCP uses `bonding.session_token`
- UDP uses `bonding.udp_session_token`, falling back to TCP token

Leg base port matches Windows/Linux convention:

```text
basePort = 20000 + ((forward_id * 37) % 15000)
TCP segment offset = 0
UDP segment offset = 200
localPort = basePort + segmentOffset + endpointIndex
```

### 15.4 Bond `plain_tcp_mux` ingress

For `ingress_mode == plain_tcp_mux`, each endpoint starts:

```text
leikwan-ingress client \
  --listen 127.0.0.1:<localPort> \
  --remote <endpoint.host>:<endpoint.port> \
  --route-key <bonding.route_key or path_prefix> \
  --auth-token <endpoint.ingress_auth_token>
```

This requires packaged `leikwan-ingress` and per-endpoint ingress auth tokens.

### 15.5 Bond WSS fallback ingress

For other ingress modes, each endpoint starts `wstunnel` to `bonding.remote_target`:

```text
wstunnel client \
  -L tcp://127.0.0.1:<localPort>:<bonding.remote_target> \
  --tls-sni-override <forward.sni> \
  --http-headers "Host: <forward.sni>" \
  --http-upgrade-path-prefix <forward.path_prefix> \
  wss://<endpoint.host>:<endpoint.port>
```

### 15.6 Bond runtime command

For each protocol segment:

```text
leikwan-bond client \
  --protocol <tcp|udp> \
  --listen 127.0.0.1:<forward.listen_port> \
  --legs <legTargets> \
  --session-token <token> \
  --chunk-size ... \
  --max-window-bytes ... \
  --retransmit-ms ... \
  --connect-timeout-ms ... \
  --join-timeout-ms ... \
  --interactive-chunk-bytes ... \
  --latency-guard-bytes ... \
  --interactive-rtt-slack-ms ... \
  --warmup-trigger-bytes ... \
  --warmup-slice-bytes ... \
  --warmup-leg-delay-ms ... \
  --bulk-promote-bytes ... \
  --leg-cooldown-ms ...
```

Android listens on `127.0.0.1:<listen_port>`, unlike the current Windows renderer which listens on `0.0.0.0:<listen_port>`.

### 15.7 QUIC/WSS active-leg logic

`BondForwardRunner` can render active QUIC leg URLs when endpoint and bonding flags allow:

```text
quic://<endpoint.host>:<endpoint.port>?priority=<p>&endpoint_id=<id>&display_host=<host>&server_name=<name>&insecure=1
```

If selected QUIC legs exist and WSS standby is enabled, WSS local ingress processes can be started but not included as active bond legs. If no QUIC legs are selected, WSS legs become active.

Adaptive join timeout mirrors Windows/Linux style:

- configured default at least 40 ms
- floor default 35 ms
- per-leg default 20 ms
- ceiling default min(configured, 220)
- if no QUIC legs, use configured timeout with a wider ceiling

## 16. sing-box VPN config generation

`SingboxConfigBuilder` converts app preferences, selected proxy node, and optional `RuntimeContext` into JSON passed to libbox.

### 16.1 Inbound

Always creates a TUN inbound:

```json
{
  "type": "tun",
  "tag": "tun-in",
  "address": ["172.19.0.1/30", "fdfe:dcba:9876::1/126"],
  "auto_route": true,
  "strict_route": true,
  "stack": "mixed"
}
```

### 16.2 DNS

DNS servers:

- `remote`: UDP to configured DNS server; detours through proxy in Services mode and direct in Direct mode.
- `direct-dns`: UDP to fallback server direct.

Panel domains are forced to direct DNS.

### 16.3 Outbounds

For proxy nodes, supported outbound protocols are:

- Shadowsocks
- VMess
- VLESS

Services mode rewrites proxy server/port to:

```text
127.0.0.1:<runtimeContext.localListenPort>
```

Direct mode uses the node original server/port.

If multiple active proxy nodes are included, it creates a selector outbound. In the common start path, `TunnelOrchestrator` passes only the selected proxy node.

### 16.4 Routing

Route rules include:

- sniff action
- DNS hijack
- panel domains direct
- runtime bypass hosts direct (endpoint hosts, CDN hosts, SNI/QUIC server name)
- optional LAN private IP bypass
- optional China geo rule sets
- custom bypass rules split into IP CIDR and domains
- final outbound is selected proxy tag/selector/direct fallback

`panelDirectDomains()` always includes `v3-mirror.leikwanhost.com` and the host parsed from configured panel URL.

## 17. VPN service and TUN setup

`LeikwanVpnService` implements Android `VpnService`, sing-box `PlatformInterface`, and `CommandServerHandler`.

Start flow in `onStartCommand`:

1. If action is `STOP`, call `stopVpn()`.
2. Require static `configJson` from `TunnelOrchestrator`.
3. Set startup state to `STARTING`.
4. Start foreground notification as `Connecting...`.
5. Call `Libbox.setup` with base/working/temp paths.
6. Create and start `CommandServer`.
7. Call `startOrReloadService(config, OverrideOptions())`.
8. Set `isRunning = true`, `startupState = RUNNING`, update notification.
9. On exception, set `FAILED`, store error, and stop.

`openTun(options)`:

1. Converts libbox TUN options to `TunOptionSnapshot`.
2. `TunSetupPlanner` supplies fallback addresses/routes/DNS if libbox did not provide them.
3. Creates Android VPN `Builder`, session name `LeiKwan Bridge`, MTU from libbox.
4. Binds underlying networks to active non-VPN internet network when available.
5. Adds IPv4/IPv6 addresses/routes and DNS servers.
6. Establishes `ParcelFileDescriptor` and returns fd to libbox.

`autoDetectInterfaceControl(fd)` calls `protect(fd)` to keep underlying sockets outside the VPN loop.

Foreground notification has a Disconnect action that sends service `STOP`.

## 18. Startup monitor and failure cleanup

`VpnStartupMonitor.await` polls `LeikwanVpnService.startupSnapshot()` until one of:

- RUNNING
- FAILED with error
- timeout

`TunnelOrchestrator` uses a 10-second timeout. If startup fails or times out after starting local runtime processes, it stops those processes to avoid orphaned child transports.

## 19. Current tests and verification commands

Main unit-test coverage areas:

- forward contract parser keeps bond ingress fields
- forward mode normalization and unsupported MPTCP hiding
- single/bond runtime command shape
- runtime binary location fallback behavior
- guarded process restart/fatal behavior
- stale runtime process PID parser
- proxy URI parser/import/editor/duplicator/list sections
- proxy selection policy
- sing-box config builder for services/direct modes
- TUN setup planner
- underlying network selector
- VPN startup monitor

Recommended command before changing Android client logic:

```powershell
cd leikwan-android
.\gradlew.bat testDebugUnitTest
```

For runtime binary changes, also verify APK contents and run on a real device, because unit tests do not prove Android process execution or VpnService startup.

## 20. Current high-priority gaps for Android developers

1. MPTCP/race are hidden, not supported. If Android is expected to match Linux low-latency MPTCP, this needs a real design, not just UI enablement.
2. No latency watchdog or endpoint quarantine loop is wired for bond/CF legs. Current protection is process supervision plus local ready-port waits.
3. No stable device identity headers are sent to the panel. Windows has this; Android may need parity to avoid online-session ambiguity.
4. `PanelRepository.heartbeat()` exists but is not scheduled from the inspected tunnel lifecycle.
5. `ProxySelectionPolicy` should return immediately on `selectedProxyId == 0L` to show the intended error message.
6. Runtime binaries are packaged only for arm64 in the inspected tree.
7. There is no Android app update path comparable to the Windows manifest/installer flow.
8. The process janitor matches runtime names in `ps -A`; keep it narrow if more binaries/processes are added.
9. Services mode currently requires a selected proxy node even though the panel forward runtime itself opens a local relay. Confirm this is intended product behavior for Windows/Android parity.

## 21. Portability guidance for Windows and Android teams

### Keep shared

- Panel login/client selection semantics.
- Forward contract parser and mode filtering decisions.
- Bond command renderer rules: leg port derivation, ingress mode handling, QUIC/WSS active/standby logic, adaptive join timeout.
- Runtime process supervision expectations: start ingress first, wait ready ports, start bond, stop children on VPN/runtime failure.
- Direct-routing of panel/control-plane/runtime endpoint hosts to avoid VPN recursion.

### Android-specific

- VPN/TUN integration belongs in `LeikwanVpnService` + `SingboxConfigBuilder`.
- Runtime binaries can be shipped as `.so` files in `jniLibs` and executed by path, with asset extraction fallback.
- Use `protect(fd)` / underlying network binding to avoid routing transport sockets back through the VPN.
- Foreground service and notification behavior are mandatory for stable Android operation.

### Windows-specific if copying Android ideas

- If Windows needs full-device proxying like Android, add a clear TUN/sing-box/Wintun layer instead of trying to overload the current local-listener-only architecture.
- If Windows remains a local listener manager, do not copy Android `127.0.0.1` bind blindly; choose loopback vs LAN exposure intentionally.

## 22. One-page architecture summary

```text
MainActivity
  -> if Prefs.isLoggedIn(): MainScreen
     else LoginScreen

LoginScreen/LoginViewModel
  -> PanelRepository.login
  -> encrypted prefs: token/client/client list

MainScreen
  -> TunnelsScreen/TunnelsViewModel
     -> pullCatalog
     -> select visible SINGLE/BOND forward
     -> request VPN permission
     -> TunnelOrchestrator.startForForward
  -> ProxiesScreen/ProxiesViewModel
     -> import/edit/duplicate/select proxy nodes
     -> Direct mode starts TunnelOrchestrator.startForForward(0)
  -> SettingsScreen/SettingsViewModel
     -> DNS/bypass/client switch/logout

TunnelOrchestrator.startForForward
  -> validate selected proxy and selected forward
  -> build runtime plan for SINGLE/BOND in Services mode
  -> ProcessRunner starts wstunnel/ingress/bond children
  -> SingboxConfigBuilder creates TUN config
  -> LeikwanVpnService starts libbox CommandServer
  -> VpnStartupMonitor waits for RUNNING or failure

Services mode data path:
  app traffic -> Android VPN TUN -> sing-box -> selected proxy outbound
    -> 127.0.0.1:<forward.listen_port> -> LeiKwan runtime -> slave/sidecar/service

Direct mode data path:
  app traffic -> Android VPN TUN -> sing-box -> selected proxy original server:port
```
