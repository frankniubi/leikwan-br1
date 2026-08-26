# LeiKwan macOS Client Design

Date: 2026-08-27
Status: Approved in chat; pending written-spec review

## 1. Problem and baseline

The production desktop client is `LeikwanBridge.Desktop`. Its WPF shell is
Windows-only, while most of the API, state, polling, heartbeat, diagnostics,
and runtime orchestration code already lives in the `net8.0`
`LeikwanBridge.Core` project. The repository also contains an older Tauri
prototype, but it does not implement the current Windows contract completely.

The panel client contract is already revision-aware and device-session based:

- `POST /api/client/login`
- `POST /api/client/select`
- `GET /api/client/configs/pull` (including long-poll and `304`)
- `POST /api/client/heartbeat`
- `POST /api/client/diagnostics/result`
- `POST /api/client/ultra/lease`
- `GET /artifacts/client-manifest.json`

The current panel has Windows-specific artifact entries and only grants Ultra
access to requests whose platform header starts with `windows`. The macOS
release must extend those contracts rather than create a parallel protocol.

## 2. Goals

The first macOS release must be a usable desktop client, not a UI prototype.
It must provide separate packages for Apple Silicon (`arm64`) and Intel
(`x86_64`) and support:

1. email/password login, owned-client selection, client switching, and sign out;
2. revision-aware config pulling, incremental reconciliation, and `304` handling;
3. `single`, `bond`, and Ultra forwards using the current panel contract;
4. start, stop, restart, health state, logs, and local usage counters;
5. heartbeat, usage accounting, Ultra status/actions, and diagnostics upload;
6. automatic and manual update checks, hash-verified download, immediate install,
   and deferred install on the next restart;
7. tray/menu-bar operation, close-to-tray, and per-user LaunchAgent autostart;
8. an unsigned DMG that runs after the normal macOS Gatekeeper confirmation.

The client must preserve the topology `client -> slave -> sidecar -> target`.
It must never turn a public sidecar address into a direct client path.

## 3. Non-goals and explicit platform limits

- No Apple Developer signing or notarization is assumed for this release.
- No native macOS kernel MPTCP implementation is promised. Native `mptcp`
  assignments are rejected unless the panel explicitly marks a supported TCP
  fallback; the fallback result is visible in the forward status.
- No forward creation or billing workflow is added to the desktop app.
- No production panel deployment is part of implementation. Artifact publication
  remains a separately authorized operation.
- Windows behavior must not regress. Shared refactoring is allowed only when the
  existing Windows tests continue to pass.

## 4. Selected architecture

### 4.1 Shared orchestration layer

Extract the UI-independent lifecycle currently spread across `MainViewModel`,
`ConfigPollingService`, and `MainWindow.xaml.cs` into a shared
`DesktopClientController` in `LeikwanBridge.Core`. It owns:

- authentication/session and active-client state;
- config pull, revision persistence, and forward reconciliation;
- runtime start/stop/restart requests;
- heartbeat scheduling and usage commit semantics;
- diagnostics request detection and redacted result submission;
- update discovery and pending-update state transitions.

The controller receives platform services through small interfaces instead of
calling WPF or Windows APIs directly:

- `IClientPlatformPaths` for config, state, log, runtime, and bundle paths;
- `IProcessSupervisor` for process-tree lifecycle and exit notifications;
- `IPortOwnershipProbe` for ownership-aware port preflight;
- `IAutostartService` for enable/disable and status;
- `IAppUpdater` for package download, hash verification, and replacement;
- `ISecretStore` for optional OS-backed token storage.

The existing `LeikwanBridge.Core` models and HTTP services remain the wire
contract source of truth. The WPF shell is adapted to call the controller, and
the new macOS shell uses the same controller.

### 4.2 macOS shell

Create `LeikwanBridge.Desktop/src/LeikwanBridge.Mac` as a .NET 8 Avalonia
desktop application. It provides:

- the login/client-picker surface;
- dashboard, forward list/detail, log, and settings views;
- tray/menu-bar menu with show, start-all, stop-all, and quit actions;
- close-to-tray behavior honoring the saved setting;
- localized strings matching the current Windows resources.

The shell must not duplicate API or runtime decisions. View models translate
controller state into Avalonia bindings and dispatch commands back to the
controller.

### 4.3 macOS platform services

The macOS implementation supplies:

- state: `~/Library/Application Support/LeiKwan/ClientAgent`;
- logs: `~/Library/Logs/LeiKwan/ClientAgent`;
- runtime binaries: the app bundle's `Contents/Resources/bin`;
- per-user autostart: `~/Library/LaunchAgents/com.leikwan.bridge.plist`;
- process groups: Unix process-group termination with graceful SIGTERM followed
  by bounded SIGKILL cleanup;
- port ownership: `lsof` with an argument-list API (no shell interpolation);
- local file permissions: state and credential files created with user-only
  permissions where the platform permits it;
- stable device identity: a generated random identifier persisted in state and
  prefixed with `macos-client-` (no raw hardware serial is uploaded).

The macOS shell uses the system Keychain through `ISecretStore` when available.
If Keychain access is unavailable, it falls back to the existing config schema
with restrictive file permissions and records a non-secret warning in logs.

## 5. Runtime contract

### 5.1 Bundled helpers

Each architecture bundle contains Mach-O helpers with deterministic names:

```text
Contents/Resources/bin/wstunnel
Contents/Resources/bin/leikwan-bond
Contents/Resources/bin/leikwan-ingress
Contents/Resources/bin/leikwan-uot-bond
Contents/Resources/bin/ultra-client
Contents/Resources/bin/wg
```

The build fails if a helper required by the selected runtime is missing or has
an unexpected SHA-256. Every helper is launched with an absolute path inside
the app bundle or an explicit user override from settings.

### 5.2 Supported modes

- `single`: one local `wstunnel` runtime, following the same SNI, Host, path,
  and target rules as Windows.
- `bond`: one `leikwan-bond` runtime plus the required `leikwan-ingress` or
  WSS leg helpers. Slave-preconnect and plain-TCP mux metadata remain relay-
  first; the client does not configure a sidecar-facing WireGuard interface.
- `ultra`: direct provider runtime with one-shot panel leases, local-only
  listener, status file, and per-forward control IPC. On macOS the control
  endpoint is a Unix domain socket rather than a Windows named pipe.

For `mptcp` and `race`, `MacForwardPolicy` applies an explicit rule:

1. accept a panel-declared, supported TCP fallback and record the fallback code;
2. otherwise keep the forward visible as unsupported and do not start a partial
   runtime.

No mode silently falls through to a direct sidecar connection.

### 5.3 Shutdown and recovery

Before a replacement or update, the controller stops polling, heartbeat, and
all forward process groups, flushes state/logs, and verifies listeners are gone.
Health recovery is per-forward and signature-aware, matching the Windows
desired-state boundary. User-stopped forwards are not immediately reanimated.

## 6. Panel and HTTP changes

### 6.1 Request identity

Every macOS request uses:

```text
X-Device-Platform: macos-desktop
X-Device-Id: macos-client-<persisted-id>
X-Device-Name: LeiKwan Bridge on <machine name>
X-Client-Version: <app version>
User-Agent: Leikwan-macOS-GUI/<app version>
```

Login and selection keep the existing desktop-session flow. Pull, heartbeat,
diagnostics, and Ultra lease requests use the selected client's agent token.

### 6.2 Pull and heartbeat

The shared controller sends `since_revision` and bounded `wait_seconds`, treats
`304` as a successful no-change cycle, and applies only changed effective
forward contracts. Heartbeats include:

- app version and `supports_mptcp=false`;
- WireGuard identity public key when the helper is available;
- total byte deltas and Ultra usage sequences;
- redacted runtime health/reputation snapshots;
- no provider credentials or raw secret tokens.

The panel must treat `macos-desktop` like `windows-desktop` for Ultra grant
attachment. This is a required server-side change; otherwise a macOS client
can pull ordinary forwards but cannot obtain an Ultra lease.

### 6.3 Diagnostics

When a pull response contains a diagnostic request token, the controller runs
the same bounded local checks as Windows, removes credentials and internal
addresses from result fields, and posts to
`/api/client/diagnostics/result`. A failed diagnostic run is reported as an
error result without interrupting heartbeat or the data plane.

## 7. Update design

### 7.1 Manifest entries

Add architecture-specific entries to `artifacts/versions.json` and the routed
client manifest:

```text
macos_client_app_arm64
macos_client_app_x64
macos_client_update_arm64
macos_client_update_x64
leikwan_bond_macos_arm64
leikwan_bond_macos_x64
leikwan_ingress_macos_arm64
leikwan_ingress_macos_x64
wstunnel_macos_arm64
wstunnel_macos_x64
ultra_client_runtime_macos_arm64
ultra_client_runtime_macos_x64
```

The app entries point to user-downloadable DMGs. The update entries point to a
hash-pinned archive containing the `.app` bundle, which allows an automatic
replacement without requiring a signed Apple update feed. The manifest records
version, URL, SHA-256, byte size, architecture, and archive format.

### 7.2 Immediate and deferred update

`DesktopAppUpdateService` selects the entry matching `RuntimeInformation.OSArchitecture`,
downloads to the app-state updates directory, verifies size and SHA-256, and
rejects mismatches before extraction. A bundled updater mode then:

1. records the current app PID and bundle path;
2. waits for the app to exit;
3. extracts to a sibling staging directory;
4. validates the staged `.app` and helper manifest;
5. atomically swaps the bundle when writable;
6. relaunches the new app and removes staging files.

If the bundle is not writable (for example, an admin-owned `/Applications`
installation), the updater leaves the verified archive in place, opens the DMG,
and reports a manual-install action instead of claiming success. Pending state
is retained across restarts until the hash and version checks succeed.

No update is installed silently without the existing user confirmation flow.

## 8. Panel artifact and tenant surface

Update `ArtifactController` and `Bootstrap` to route the macOS DMGs, update
archives, and helper binaries. Extend `clientManifest()` with the new entries
and preserve the existing cache-busting version/SHA query parameters.

Update the tenant client-install view to show a macOS download block with both
architecture choices and a short unsigned-app first-open note. Keep Windows,
Linux, and Android blocks unchanged. Add regression tests for:

- macOS entries present in the routed manifest;
- relative URLs expanded to the artifact base URL;
- SHA/size metadata matching the served files;
- macOS install links rendered without exposing credentials;
- Ultra grants attached for `macos-desktop` pulls.

## 9. Build and distribution

Add a macOS CI workflow with two jobs:

- `macos-13` for `x86_64-apple-darwin`;
- `macos-14` for `aarch64-apple-darwin`.

Each job runs Core/App tests, builds the Rust helpers, publishes a self-
contained Avalonia app, creates a DMG and update archive, emits a machine-
readable helper manifest, and computes SHA-256/size metadata. The workflow
must fail closed when a helper or manifest field is missing.

The current Windows workstation cannot execute Xcode or produce a genuine
macOS DMG. Local verification therefore covers shared .NET tests, Rust tests,
manifest/schema checks, and packaging-script validation; the macOS runner is
the authority for final Mach-O and DMG smoke tests.

## 10. Test strategy and acceptance gates

### Shared/core tests

- stable macOS device ID generation and header construction;
- macOS paths and helper resolution without `.exe` suffixes;
- mode policy and explicit MPTCP/Race fallback/rejection;
- Unix process-group cleanup and port-owner classification;
- login/select/switch/sign-out state transitions;
- pull `304`, revision change, failed pull backoff, and no-op apply;
- heartbeat byte deltas, Ultra sequence accounting, and action handling;
- diagnostic redaction and request-token matching;
- update version comparison, cache reuse, hash mismatch rejection, pending
  restart state, and unwritable-bundle fallback.

### Shell tests

- login and multi-client picker;
- dashboard/forward lifecycle and user-stop behavior;
- tray close/show/quit and autostart setting;
- update prompt, immediate install, and deferred restart state.

### CI smoke

On each macOS runner:

1. `dotnet test` for Core and Mac projects;
2. `cargo test` for all bundled Rust helper crates;
3. self-contained publish and helper SHA verification;
4. DMG attach, app-bundle inspection, and launch/exit smoke;
5. manifest generation and a redacted artifact scan.

Acceptance is not complete until both architecture packages pass those gates,
the Windows Core/App suites remain green, and the panel PHP regression tests
pass. No live production release is implied by CI success.

## 11. Risks and mitigations

- **Missing upstream macOS helper:** build jobs fail before publishing; do not
  substitute a Linux or Windows binary.
- **No native MPTCP:** reject or explicitly downgrade only when the panel marks
  the fallback; expose the reason in the UI and heartbeat.
- **Gatekeeper warning:** document the unsigned first-open confirmation; do not
  disguise or bypass system security controls.
- **Bundle permissions:** retain a verified archive and open the DMG when an
  in-place swap is impossible.
- **Dirty shared worktree:** touch only scoped files and preserve unrelated user
  changes; production deployment remains a separate authorized step.

## 12. Implementation order

1. Add shared platform interfaces and extract controller behavior without
   changing Windows semantics.
2. Add macOS path/process/port/autostart/secret/update services and unit tests.
3. Add the Avalonia macOS shell and shell tests.
4. Add macOS runtime helper resolution and mode policy.
5. Add panel manifest/routes, Ultra platform grant handling, install UI, and PHP
   regression tests.
6. Add dual-architecture helper builds, app publishing, DMG/update packaging,
   and CI smoke jobs.
7. Run all local gates, then macOS CI gates; stage artifacts locally only.
