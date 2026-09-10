# LeiKwan Br1 Windows Client Technical Structure and Logic Handoff

Generated: 2026-05-20  
Inspected checkout: `codex/cfwg47-inline-fix-20260520` at `92403f8`  
Scope: current source under `LeikwanBridge.Desktop/`. This is a technical handoff for Windows implementers; it does not cover product/visual design except where GUI code drives runtime behavior.

## 1. Executive summary

The current Windows client is a .NET 8 WPF desktop/tray application. It is split into:

- `LeikwanBridge.App`: WPF shell, setup/login/client picker, dashboard/forwards/log/settings views, tray behavior, Windows app update prompts.
- `LeikwanBridge.Core`: panel API client, config/state models, process supervisor, forward runtime renderer, health/restart logic, update manifest helpers.

It is not a Windows service. The GUI process supervises runtime child processes directly:

- `wstunnel.exe` for single mode and WSS fallback legs.
- `leikwan-ingress.exe` for `plain_tcp_mux` ingress helpers.
- `leikwan-bond.exe` for TCP/UDP bond runtime.
- `leikwan-uot-bond.exe` for selected UDP-over-TCP sidepath forwards.
- `wg.exe` only for generating/reporting a local WireGuard identity key in heartbeat; the app does not bring up Windows WireGuard interfaces.

Default panel URL is `https://v3-mirror.leikwanhost.com`.

Normal polling accepts only `single` and `bond` forwards. There is a code path to convert `mptcp` to bond fallback, but the polling path filters MPTCP out before it reaches the runtime manager.

## 2. Key source map

```text
LeikwanBridge.Desktop/
  LeikwanBridge.Desktop.sln
  src/
    LeikwanBridge.App/
      App.xaml(.cs)                         WPF bootstrap, single instance, logging, config load
      AppDefaults.cs                        default/legacy panel URLs
      Views/MainWindow.xaml(.cs)            login, client switch, updates, tray, settings, view switching
      ViewModels/MainViewModel.cs           main UI/runtime state, connect/disconnect, forward controls
      ViewModels/ForwardViewModel.cs        forward row/card projection
      Services/InstalledRuntimeCleanupService.cs
      Services/TrayWindowLifecycle.cs
      Resources/Strings/*.xaml              en-US / zh-CN strings
      Resources/Styles/Theme.xaml           WPF theme
    LeikwanBridge.Core/
      Models/*.cs                           panel/config/state/update DTOs
      Helpers/ModeNormalizer.cs             mode alias normalization
      Helpers/WindowsForwardPolicy.cs       Windows-supported mode filter
      Services/MasterApiClient.cs           panel HTTP API client
      Services/ConfigPollingService.cs      pull/reconcile loop
      Services/ForwardRuntimeManager.cs     apply/stop/restart forwards
      Services/BondRuntimeBuilder.cs        runtime command renderer
      Services/ProcessSupervisor.cs         child process lifecycle
      Services/HealthCheckService.cs        restart/probe watchdog logic
      Services/BondEndpointHealthService.cs latency-first endpoint role state
      Services/StateManager.cs              JSON state persistence
      Services/WireGuardManager.cs          local key identity for heartbeat
      Services/HeartbeatService.cs          heartbeat sender
      Services/WindowsAppUpdateService.cs   app manifest/download/hash helpers
  tests/
    LeikwanBridge.Core.Tests/
    LeikwanBridge.App.Tests/
```

## 3. Startup and persisted layout

`App.xaml.cs` is the process entrypoint.

Startup sequence:

1. Creates single-instance mutex `LeikwanBridge_SingleInstance`.
2. Creates log directory `C:\ProgramData\Leikwan\ClientAgent\logs\`.
3. Configures Serilog daily rolling file logs named `leikwan-gui-.log`.
4. Loads config from `C:\ProgramData\Leikwan\ClientAgent\config.json`.
5. Applies language resource dictionary.
6. Opens the WPF main window.

Default state directory if not overridden:

```text
C:\ProgramData\Leikwan\ClientAgent\state\
```

Important state files managed by `StateManager`:

```text
applied.json                    per-forward applied runtime/process state
config_pull_state.json          last pull attempt/success/error and applied IDs
usage_state.json                byte counters reported by heartbeat
bond-endpoint-health.json       per-forward/per-endpoint role, latency, quarantine/probation
runtime/*.json                  generated runtime configs, e.g. UOT sidepath config
wireguard/private.key           Windows client local WG identity key
wireguard/public.key            public key reported in heartbeat
```

## 4. Login and client selection logic

The setup wizard is in `MainWindow.xaml` and `MainWindow.xaml.cs`.

### 4.1 Email login

`OnConnect_Click` reads master URL, email, and password. It calls:

```text
POST /api/client/login
body: { email, password }
```

via `MasterApiClient.DesktopLoginAsync`.

`MainViewModel.CompleteDesktopLogin` stores:

- `desktop_session_token`
- signed-in user id/username/email
- available client choices

### 4.2 Client selection

If login returns one client, the app auto-selects it. If login returns multiple clients, it shows a client picker.

`ActivateSelectedClientAsync` calls:

```text
POST /api/client/select
body: { session_token, client_id }
```

The selection response stores:

- `client_id`
- `active_client_id`
- `active_client_name`
- `agent_token`

Then config is saved, the VM is reinitialized, and the normal connect flow starts.

### 4.3 Sign out

`OnSignOut_Click` disconnects, clears session/client fields, saves config, clears password/email UI fields, and returns to setup state.

## 5. Panel API contract used by Windows

Implemented in `MasterApiClient.cs`.

| Purpose | Method | Endpoint | Notes |
|---|---:|---|---|
| Email login | `POST` | `/api/client/login` | no bearer token required |
| Select client profile | `POST` | `/api/client/select` | desktop session token in JSON body |
| Pull forward configs | `GET` | `/api/client/configs/pull` | bearer `agent_token` |
| Heartbeat | `POST` | `/api/client/heartbeat` | bearer `agent_token` |
| Diagnostics result | `POST` | `/api/client/diagnostics/result` | code exists; not a major GUI path |
| Windows app update manifest | `GET` | `/artifacts/client-manifest.json` | handled by `WindowsAppUpdateService` |

The GUI connect flow constructs `MasterApiClient` with:

```text
X-Device-Id: windows-client-<clientId>-<sha256(machine) prefix>
X-Device-Name: Leikwan Bridge on <machine>
X-Device-Platform: windows-desktop
User-Agent: Leikwan-Windows-GUI/2026.04.09.1
Authorization: Bearer <agent_token>
```

The stable Windows device id is generated from `windows|<clientId>|<machineName>`.

`BuildApiErrorMessage` special-cases `client_online_limit_reached` and surfaces active/limit counts when present.

## 6. Config and model contract

### 6.1 AgentConfig

`AgentConfig` stores both login/session information and runtime paths/settings:

- panel auth: `master_url`, `agent_token`, `client_id`
- desktop session: `desktop_session_token`, signed-in user identity, available clients, active client
- update state: pending version/path/hash and last check timestamp
- runtime options: `state_dir`, poll/health intervals, log level
- runtime binary overrides: `bond_bin`, `bond_ingress_bin`, `wstunnel_bin`, `uot_bond_bin`, `wg_bin`
- WireGuard heartbeat ports: `wireguard_listen_port`, `wireguard_fallback_tcp_port`
- UI settings: `language`, `auto_start`, `minimize_to_tray`
- authoritative panel sidepath block: `udp_uot_sidepath`

Effective default binaries resolve relative to `AppContext.BaseDirectory`:

```text
bin/leikwan-bond.exe
bin/leikwan-ingress.exe
bin/wstunnel.exe
bin/leikwan-uot-bond.exe
bin/wg.exe
```

`ApplyAuthoritativeConfig` currently reads only `agent_config_json.udp_uot_sidepath`; other panel-side agent config fields are not applied by the Windows client.

### 6.2 ForwardConfig

The pulled forward contract is represented by `ForwardConfig`:

- identity/status: `forward_id`, `service_status`
- routing shape: `mode`, `listen_port`, `target`, `transport`
- WSS metadata: `sni`, `path_prefix`, endpoints
- panel/backend metadata: `backend_mode`, `speed_limit_mbps`
- mode-specific blocks: `bonding`, `mptcp`

### 6.3 EndpointConfig

Endpoint fields used for runtime rendering and latency-first state:

- `endpoint_id`, `host`, `port`, `cdn_host`
- `route_key`, `ingress_auth_token`, `ingress_protocol`, `backend_protocol`
- dynamic health/routing: `dynamic_priority`, `health_role`, `quality_score`, `leg_role`, `supports_quic_leg`

### 6.4 BondingConfig

Important fields used by `BondRuntimeBuilder`:

- ingress: `ingress_mode`, `route_key`, `remote_target`
- auth: `session_token`, `udp_session_token`
- bond transport/tuning: chunk/window/retransmit/connect/join timeouts, interactive/warmup/bulk/cooldown fields
- QUIC/WSS selection: `quic_enabled`, `quic_via_slave`, `active_leg_limit`, `quic_active_leg_limit`, `wss_standby_enabled`, `quic_server_name`, priorities
- latency policy: `policy_mode`, `udp_primary_only`, `primary_reselect_on_failure_only`, adaptive join timeout fields

## 7. Connect / reconcile state machine

Implemented mainly by `MainViewModel.ConnectAsync` and `ConfigPollingService.SyncOnceAsync`.

Connect flow:

1. Construct `MasterApiClient` with current `master_url`, `agent_token`, and stable Windows device headers.
2. Test connection by calling `PullConfigsAsync`.
3. Create state, process, runtime, polling, health, cleanup, WireGuard identity, and heartbeat services.
4. Generate/load WireGuard identity for heartbeat reporting.
5. Kill stale installed runtime processes under the user install root.
6. Start the config polling loop.

Polling/reconcile flow:

1. Pull `/api/client/configs/pull`.
2. Apply authoritative `client.agent_config_json` into `AgentConfig` (currently UOT sidepath only).
3. Filter forwards with `WindowsForwardPolicy.FilterSupportedForwards` and `service_status == active`.
4. Save `config_pull_state.json` with status, timestamps, agent version, total count, applied IDs.
5. Stop locally applied forwards that disappeared from the active panel set.
6. For each active forward:
   - skip if local state has `user_stopped = true`
   - apply if not previously applied or no longer running
7. Publish `ForwardsUpdated` after applying runtimes.
8. GUI sends heartbeat asynchronously after a forward snapshot update.

Important behavior: runtime apply happens before `ForwardsUpdated`, so the UI snapshot should not show stale `Stopped` state immediately after successful pull/apply.

## 8. Supported modes and current filtering

`WindowsForwardPolicy.IsSupportedOnWindows` returns true only for normalized modes:

```text
single
bond
```

`race` and `mptcp` are filtered out in normal config polling.

There are still code paths for:

- `BuildRace` in `BondRuntimeBuilder`, using the first endpoint with wstunnel.
- `MptcpFallbackService.ConvertToBond`, converting MPTCP contract fields into a bond config.

But because normal polling filters unsupported modes first, MPTCP-to-bond fallback is not reached for panel-pulled MPTCP forwards. If Windows should show/operate MPTCP as bond fallback, change `WindowsForwardPolicy` or the polling flow intentionally.

## 9. Runtime rendering model

Runtime rendering is split:

- `ForwardRuntimeManager` decides apply/stop/restart, process ordering, readiness waits, state save.
- `BondRuntimeBuilder` converts a `ForwardConfig` into a `RuntimeSpec` with command lines and generated files.
- `ProcessSupervisor` starts and kills child processes.

`ForwardRuntimeManager.ApplyForwardCore` steps:

1. Normalize mode.
2. Convert MPTCP to bond fallback only if mode reaches this function.
3. Stop existing forward processes.
4. Wait up to 3 seconds for previous listen/leg ports to be released.
5. Build a runtime spec.
6. Start `RequiredForReadiness` processes first.
7. Wait up to 15 seconds for required leg ports to listen.
8. Write generated runtime config files.
9. Start non-readiness processes.
10. Save applied state with mode, listen port, ingress mode, endpoint count, leg ports, process roles/labels/PIDs, and `user_stopped = false`.

### 9.1 Single mode

`BuildSingle` uses the first usable endpoint and starts one `wstunnel.exe` process.

TCP shape:

```text
wstunnel.exe client -L tcp://[::]:<listen_port>:<target> \
  --tls-sni-override <sni> \
  --http-headers "Host: <sni>" \
  --http-upgrade-path-prefix "<path_prefix>" \
  wss://<endpoint.host>:<endpoint.port>
```

UDP adds:

```text
-L udp://[::]:<listen_port>:<target>?timeout_sec=0
```

### 9.2 Race mode

`BuildRace` exists but is not normally reached due to `WindowsForwardPolicy`. Current implementation is a simple first-endpoint wstunnel, not a true multi-endpoint race selector.

### 9.3 Bond mode with WSS ingress

For non-`plain_tcp_mux` ingress, per-endpoint WSS legs are rendered with `wstunnel.exe` on local loopback leg ports. The bond process then consumes `tcp://127.0.0.1:<legPort>` leg targets when WSS legs are active.

### 9.4 Bond mode with `plain_tcp_mux`

For `plain_tcp_mux`, each endpoint gets an ingress helper first:

```text
leikwan-ingress.exe client \
  --listen 127.0.0.1:<legPort> \
  --remote <endpoint.host>:<endpoint.port> \
  --route-key <route_key> \
  --auth-token <endpoint.ingress_auth_token>
```

Then `leikwan-bond.exe` starts for TCP and/or UDP:

```text
leikwan-bond.exe client --protocol <tcp|udp> \
  --listen 0.0.0.0:<forward.listen_port> \
  --legs "tcp://127.0.0.1:<legPort>?priority=<p>&endpoint_id=<id>&display_host=<host>&role=<primary|helper>,..." \
  --session-token "<token>" \
  --chunk-size ... --max-window-bytes ... --retransmit-ms ...
```

Leg base port is deterministic:

```text
baseLeg = 20000 + ((forward_id * 37) % 15000)
TCP legs: baseLeg + endpointIndex
UDP legs: baseLeg + 200 + endpointIndex
```

`BondRuntimeBuilder` also inserts active QUIC leg URLs when enabled and supported:

```text
quic://<endpoint.host>:<endpoint.port>?priority=<p>&server_name=<name>&insecure=1
```

If active QUIC legs exist and WSS standby is enabled, WSS helpers may be started as standby without being included as active bond legs.

### 9.5 UDP UOT sidepath

For `tcp+udp` + `plain_tcp_mux`, UDP can be handled by `leikwan-uot-bond.exe` when `AgentConfig.UdpUotSidepath.EnabledForwardIds` contains the forward id.

Generated file shape:

```text
<state_dir>/runtime/uot-bond-forward-<forwardId>.json
<state_dir>/runtime/uot-bond-forward-<forwardId>.stats.json
```

The runtime payload includes `listen_udp`, route key plus `.uot` suffix, session token, reorder settings, stats path, and per-endpoint leg host/port/auth/priority/weight.

## 10. Process supervision

`ProcessSupervisor` keeps an in-memory map from forward id to child processes.

Features:

- starts child processes with redirected stdout/stderr
- emits output lines back to GUI log through `onOutput`
- records `ProcessExited` with `(forwardId, role)`
- `StopAll(forwardId)` kills the entire process tree for each child
- `StopAll()` kills all tracked runtimes
- `IsRunning(forwardId)` checks whether any process for that forward is alive
- `GetRunningRoles(forwardId)` is used by health topology checks

`ForwardRuntimeManager` persists process ids in `applied.json`, but `ProcessSupervisor` only tracks processes started in the current GUI process. After app restart, old child processes can exist outside its in-memory map; installed runtime cleanup is meant to remove stale processes under the install family.

## 11. Health and restart logic

`HealthCheckService` contains three watchdog families:

1. process-exit restarts for critical roles
2. local listener/leg topology checks
3. CF `plain_tcp_mux` endpoint probing and quarantine/selection

Restart cooldown is 30 seconds per forward.

### 11.1 Process exit recovery

The constructor subscribes to `ProcessSupervisor.ProcessExited`. If the exited role was in persisted `process_roles`, the forward is not user-stopped, and cooldown permits, it calls `ForwardRuntimeManager.RestartForward`.

### 11.2 Local topology recovery

During a check cycle, it restarts when:

- no process is running for an active forward
- local listen port is not listening
- a bond leg port from `applied.json` is not listening
- a process role recorded in `applied.json` is no longer running

### 11.3 CF bond endpoint health

`BondEndpointHealthService.IsCfPlainTcpBond` selects forwards where:

```text
mode == bond
backend_mode == cf_mode
ingress_mode == plain_tcp_mux
```

For those, `HealthCheckService.CheckCfBondEndpointHealth` probes each endpoint. The default probe starts a short-lived `leikwan-bond.exe` process with one leg pointed at the already-rendered local ingress leg port, then waits for the probe listen port to accept a loopback TCP connection.

On success it records latency/quality with `RecordProbeResult`.

On failure it currently calls `ForceQuarantine`, which quarantines the endpoint immediately for 30 minutes. `BondEndpointHealthService.RecordProbeResult` has a softer 3-failure threshold, but the current CF probe path bypasses that threshold on failed probe.

Endpoint selection for latency-first bond:

- healthy endpoints sorted by `quality_score desc`, then `dynamic_priority`, then `endpoint_id`
- CF mode limits healthy selected set to best 3
- first selected endpoint becomes `leg_role=primary`; rest become helpers
- probation fallback uses one probation endpoint as primary
- total failure falls back to first sorted endpoint

### 11.4 Important current wiring gap

In the inspected GUI connect flow, `MainViewModel.ConnectAsync` constructs `HealthCheckService` and attaches `HealthIssue`, but it does not call:

```csharp
_healthCheckService.Start(forwards, _config.HealthCheckIntervalSeconds)
```

`OnForwardsUpdated` calls only `_healthCheckService.UpdateForwards(forwards)`.

Therefore, in the current GUI runtime, process-exit recovery has a path through the subscribed event, but periodic local port checks and CF endpoint probe/quarantine checks are not guaranteed to run unless another caller invokes `Start` or `RunCheckOnceAsync`. This should be fixed if Windows needs the same latency/health watchdog behavior as Linux.

## 12. Heartbeat and identity

`WireGuardManager` ensures a local keypair under `state/wireguard/` if `wg.exe` exists.

`HeartbeatService.SendHeartbeatAsync` posts:

- `agent_version`
- delta `usage_bytes_in/out` from `usage_state.json`
- `supports_mptcp = false`
- `wireguard_public_key`
- `wireguard_listen_port`
- `wireguard_fallback_tcp_port`

Windows does not report kernel MPTCP support. WireGuard key generation is identity/reporting only, not interface bring-up.

Current code has a usage-state model, but no Windows runtime byte sampler was seen in the inspected app; heartbeat deltas depend on whatever updates `usage_state.json`.

## 13. App update flow

`MainWindow` checks update state at load:

1. `TryApplyPendingUpdateAsync` validates pending version/path/hash and launches cached installer if current version is older.
2. `TryAutoConnect` may connect automatically.
3. `CheckForUpdatesAsync` fetches manifest from `<master_url>/artifacts/client-manifest.json`.

Expected manifest shape:

```json
{
  "component": "...",
  "manifest": {
    "windows_client_app": {
      "version": "...",
      "artifact_url": "...",
      "sha256": "...",
      "size_bytes": 0
    }
  }
}
```

If remote version is newer, the UI prompts:

- Install now: download/cache/hash-check, clear pending state, launch installer, exit app.
- Wait until next restart: download/cache/hash-check and store pending version/path/hash in config.

Installer cache path:

```text
%LOCALAPPDATA%\LeiKwan Bridge\updates\leikwan-bridge-setup-<version>.exe
```

## 14. Stale process cleanup

`InstalledRuntimeCleanupService` kills stale managed runtime processes.

Managed executable names:

```text
leikwan-client-win.exe
leikwan-bond.exe
leikwan-ingress.exe
leikwan-uot-bond.exe
wstunnel.exe
```

It kills:

- processes under the current install root, excluding current GUI PID
- managed executables whose path contains `LeiKwan Bridge`

This is intentionally narrower than matching every path containing `leikwan`, to avoid killing unrelated developer/build processes.

## 15. UI structure

The WPF UI is a single-window MVVM-ish app:

- setup wizard overlay for first login and update prompt
- left navigation buttons: Dashboard, Forwards, Log, Settings
- Dashboard and Forwards share the same main panel; selecting a forward shows details
- Forward cards bind to `VisibleForwards`
- mode filters are built dynamically from current forwards
- Log view is an in-memory list capped at 5000 lines
- Settings panel edits master URL, poll/health intervals, language, auto-start, minimize-to-tray
- tray icon supports show/exit and hide-on-close when enabled

`SettingsViewModel` exists and is tested, but the live `MainWindow` settings save path mostly edits `App.Config` directly from named WPF controls.

## 16. Current tests and verification commands

Test projects:

```text
LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/
LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/
```

Major covered areas:

- bond runtime argument rendering
- CF endpoint health/quarantine/selection
- config polling event ordering and sidepath config
- forward runtime apply/state/topology
- health check restarts/cooldowns/user-stopped guard
- desktop login/client selection/device identity headers
- update manifest/download helper logic
- tray lifecycle and stale process cleanup
- forward UI filtering and update prompt VM state

Recommended verification before changing Windows client runtime:

```powershell
dotnet test LeikwanBridge.Desktop\LeikwanBridge.Desktop.sln
```

For runtime packaging changes, also verify the published app contains:

```text
bin/leikwan-bond.exe
bin/leikwan-ingress.exe
bin/wstunnel.exe
bin/leikwan-uot-bond.exe
bin/wg.exe
Resources/Icons/leikwan-host.ico
```

## 17. Portability guidance for the Windows team

### Keep shared across platforms

- Panel auth and client profile selection semantics.
- Forward config parser and mode normalization.
- Bond runtime command-generation rules.
- Latency-first endpoint role semantics: healthy/probation/quarantined, quality score, primary/helper roles.
- Deterministic leg port derivation unless intentionally redesigned.
- Direct panel host bypass / control-plane traffic safety.

### Windows-specific decisions

- No kernel MPTCP support: keep `supports_mptcp=false`; use bond fallback only if product wants MPTCP-like service visibility.
- Decide whether Windows should remain a port-forward tray app or grow a TUN/VPN layer like Android.
- If it remains a port-forward app, expose local listeners intentionally (`0.0.0.0` vs `127.0.0.1`) and document firewall expectations.
- If adding TUN/VPN, reuse the Android sing-box architecture rather than forcing all traffic through raw port forwards.

### Current high-priority logic gaps to resolve

1. Start `HealthCheckService` periodically from the GUI connect flow if Windows watchdog behavior is expected.
2. Decide and implement the intended policy for MPTCP forwards: hide them, show as unsupported, or convert to bond fallback. Current code has partial fallback but normal polling hides MPTCP first.
3. Add explicit runtime version/hash reporting if Windows needs to prove child binary parity with panel/Linux.
4. Wire real usage accounting if heartbeat byte deltas matter for billing/observability.
5. If CF latency watchdog should match Linux, review whether immediate `ForceQuarantine` on first CF probe failure is too aggressive.

## 18. One-page architecture summary

```text
WPF MainWindow
  -> MainViewModel.ConnectAsync
    -> MasterApiClient.TestConnection / PullConfigs
    -> StateManager(state dir)
    -> ProcessSupervisor
    -> ForwardRuntimeManager
      -> BondRuntimeBuilder
      -> ProcessSupervisor.StartProcess
    -> ConfigPollingService.Start
      -> PullConfigs every N sec
      -> Filter Windows supported forwards
      -> Stop removed forwards
      -> Apply new/restarted forwards
      -> Save pull/applied state
      -> ForwardsUpdated event
    -> HeartbeatService.SendHeartbeat after sync
    -> HealthCheckService currently constructed, but periodic Start not wired

Data plane:
  single:
    local listen port -> wstunnel -> endpoint WSS -> target
  bond/plain_tcp_mux:
    local listen port -> leikwan-bond -> local ingress legs -> slave ingress -> sidecar/target
  optional UDP sidepath:
    local UDP listen -> leikwan-uot-bond -> endpoint shadow route
```
