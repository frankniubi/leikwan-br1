# LeiKwan macOS Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship usable unsigned LeiKwan desktop clients for Apple Silicon and Intel Macs with Windows-equivalent pull/report/update workflows, strict three-mode support, and memory-only Ultra provider material.

**Architecture:** Keep the panel wire contract and `LeikwanBridge.Core` as the source of truth, but inject platform policy and platform services into the lifecycle coordinator. The macOS Avalonia shell composes Unix process, Keychain, LaunchAgent, updater, and port-probe implementations. Ultra uses policy-v2 pulls, a maximum-300-second selected lease, anonymous bootstrap input, and a private Unix socket; provider material never enters static configuration or persisted state.

**Tech Stack:** .NET 8, Avalonia 11.3.20, CommunityToolkit.Mvvm 8.4.2, Lucide.Avalonia 0.2.19, xUnit, Go 1.22, Rust stable, PHP 8, Bash, GitHub Actions, macOS `hdiutil`/`ditto`.

**Spec:** `docs/superpowers/specs/2026-08-27-macos-client-design.md`

## Global Constraints

- The only accepted Mac forward shapes are `backend_mode=current_mode` with effective client mode `single`, `backend_mode=current_mode` with effective client mode `bond`, and `backend_mode=ultra` satisfying the existing Ultra shape.
- Every other received mode immediately emits exactly: `The mode of this forward is not supported in MacOS binary. 此转发的模式尚未在MacOS客户端支援.`
- A rejected forward is discarded before contract normalization, state creation, runtime application, or health processing. If the same forward was previously supported, its old runtime and old applied-state entry are removed.
- Mac requests use `X-Device-Platform: macos-desktop`, `X-Device-Id: macos-client-<persisted-id>`, `X-Client-Version`, and `User-Agent: Leikwan-macOS-GUI/<version>`.
- Build separate `osx-arm64` and `osx-x64` app bundles, DMGs, and update archives. Do not label either package universal.
- Set `LSMinimumSystemVersion` to macOS 12.0 and fail with a clear OS-version error on older systems.
- Packages are unsigned and unnotarized. Do not remove quarantine attributes or bypass Gatekeeper.
- Preserve `client -> slave -> sidecar -> target` for `current_mode`. Do not add a direct client-to-sidecar path.
- Mac Ultra pulls use policy-v2 scrubbing. The app never calls `/api/client/ultra/materials` and never receives a candidate inventory.
- Ultra provider endpoint, provider credentials, session ticket, and destination target are never written to disk, logs, argv, environment, diagnostics, crash output, or update archives. Live material is memory-only, bounded, and disposed after handoff.
- Ultra leases are nonce-, client-, device-, forward-, and generation-bound, expire in at most 300 seconds, and fail closed.
- Preserve existing Windows behavior and keep all current Windows Core/App tests green.
- Do not modify or publish production. Build outputs remain local or CI artifacts until separately authorized.
- Do not copy project credentials from `leikwan-v3.txt`, `cantons.txt`, `shanghais.txt`, or `sidecars.txt` into source, tests, logs, plans, manifests, or artifacts.

---

## File Map

### Core files to create

- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ClientPlatformIdentity.cs` - immutable request identity and capability values.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ClientAppArtifact.cs` - architecture-aware DMG/update manifest model.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/DesktopClientSnapshot.cs` - UI-neutral connection, forward, update, and notice state.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/IForwardAcceptancePolicy.cs` - pre-normalization forward decision contract.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/UltraForwardPolicy.cs` - shared strict Ultra shape validation used by Windows and Mac.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/MacForwardPolicy.cs` - strict three-shape allowlist and fixed notice.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Platform/IClientPlatformPaths.cs` - config/state/log/bundle/bin paths.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Platform/IProcessSupervisor.cs` - process group lifecycle contract.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Platform/IPortOwnershipProbe.cs` - ownership-aware listen-port probe.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Platform/IAutostartService.cs` - per-user autostart contract.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Platform/ISecretStore.cs` - session and agent-token storage contract.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Platform/IAppUpdater.cs` - discovery, staging, and apply contract.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/IUltraRuntimeHost.cs` - OS-specific Ultra bootstrap/lease host.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/WindowsUltraRuntimeHost.cs` - extracted current Windows behavior.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/UnixUltraLeaseClient.cs` - bounded Unix-socket lease sender.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/DesktopClientController.cs` - login/select/connect/pull/heartbeat/health/lifecycle coordinator.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ClientDiagnosticsService.cs` - bounded redacted diagnostics.

### macOS app files to create

- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/LeikwanBridge.Mac.csproj`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Program.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/App.axaml`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/App.axaml.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/ViewModels/MainWindowViewModel.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/ViewModels/ForwardRowViewModel.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Views/MainWindow.axaml`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Views/MainWindow.axaml.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacPlatformPaths.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacProcessSupervisor.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacPortOwnershipProbe.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacKeychainSecretStore.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacAgentConfigStore.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacAutostartService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacUltraRuntimeHost.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacAppUpdateService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacBundleValidator.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Resources/Strings.axaml`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Resources/Theme.axaml`

### Test files to create

- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/MacForwardPolicyTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/DesktopClientControllerTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ClientDiagnosticsServiceTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/UnixUltraLeaseClientTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/LeikwanBridge.Mac.Tests.csproj`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MacPlatformPathsTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MacProcessSupervisorTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MacPortOwnershipProbeTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MacKeychainSecretStoreTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MacAgentConfigStoreTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MacAutostartServiceTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MacUltraRuntimeHostTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MacAppUpdateServiceTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MainWindowViewModelTests.cs`
- `master/tests/client_agent_macos_ultra_policy_v2_contract_test.php`
- `master/tests/macos_client_artifact_manifest_test.php`
- `master/tests/macos_client_install_surface_test.php`

### Runtime/build files to create

- `tools/ultra-client/config_input.go` and `config_input_test.go` - bounded stdin/file config loader.
- `tools/ultra-client/peer_identity_linux.go` - Linux `SO_PEERCRED` validation.
- `tools/ultra-client/peer_identity_darwin.go` - Darwin `getpeereid` validation.
- `tools/ultra-client/process_security_unix.go` - `RLIMIT_CORE=0` and best-effort memory locking.
- `tools/ultra-client/process_security_windows.go` - no-op compatibility implementation.
- `scripts/build-macos-client.sh` - pinned helper and self-contained app build.
- `scripts/package-macos-client.sh` - app bundle, DMG, update ZIP, and metadata.
- `scripts/test-macos-ultra-leaks.sh` - canary leak scan.
- `.github/workflows/macos-client.yml` - Intel and Apple Silicon build/test jobs.

### Existing files to modify

- Core: `MasterApiClient.cs`, `ConfigPollingService.cs`, `ForwardRuntimeManager.cs`, `ProcessSupervisor.cs`, `HealthCheckService.cs`, `HeartbeatService.cs`, `StateManager.cs`, `AgentConfig.cs`, `UltraLease.cs`, update-manifest models, solution/project files, and focused tests.
- Windows shell: `MainViewModel.cs` and its tests, only to delegate shared lifecycle behavior without changing the WPF surface.
- Ultra runtime: `main.go`, `control.go`, `lease.go`, `policy.go`, and their tests.
- Panel: `ClientAgentController.php`, `ArtifactController.php`, `Bootstrap.php`, `BaseController.php`, `TenantController.php`, `artifacts/versions.json` generation logic, and focused tests.

---

### Task 1: Add Platform Identity and the Strict Mac Mode Gate

**Files:**
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ClientPlatformIdentity.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/IForwardAcceptancePolicy.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/UltraForwardPolicy.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/MacForwardPolicy.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/WindowsForwardPolicy.cs:5-39`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs:12-330`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/StateManager.cs:24-34`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/MacForwardPolicyTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ConfigPollingServiceTests.cs`

**Interfaces:**
- Produces: `ForwardAcceptance Evaluate(ForwardConfig forward)` and `event Action<int,string>? ForwardRejected`.
- Produces: `StateManager.RemoveApplied(int forwardId)`.
- Preserves: the default `WindowsForwardAcceptancePolicy` delegates to `WindowsForwardPolicy.IsSupportedOnWindows`.

- [x] **Step 1: Write the failing raw-mode allowlist tests.**

```csharp
[Theory]
[InlineData("current_mode", "single", "", true)]
[InlineData("current_mode", "bond", "", true)]
[InlineData("ultra", "single", "", true)]
[InlineData("ultra", "bond", "", false)]
[InlineData("current_mode", "mptcp", "", false)]
[InlineData("current_mode", "race", "", false)]
[InlineData("cf_mode", "single", "", false)]
[InlineData("leigod_mode", "single", "", false)]
[InlineData("current_mode", "single", "race", false)]
public void Evaluate_UsesRawClientModeBeforeAnyNormalizer(
    string backend, string mode, string clientMode, bool accepted)
{
    var result = MacForwardPolicy.Instance.Evaluate(new ForwardConfig {
        BackendMode = backend, Mode = mode, ClientMode = clientMode,
        Target = "203.0.113.10:443"
    });
    Assert.Equal(accepted, result.Accepted);
    if (!accepted)
        Assert.Equal(MacForwardPolicy.UnsupportedNotice, result.Notice);
}
```

- [x] **Step 2: Run the focused test and verify RED.**

Run:
```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter FullyQualifiedName~MacForwardPolicyTests
```

Expected: FAIL because `MacForwardPolicy` and `ForwardAcceptance` do not exist.

- [x] **Step 3: Implement the exact policy contract.**

```csharp
public sealed record ForwardAcceptance(bool Accepted, string EffectiveMode, string Notice);

public interface IForwardAcceptancePolicy
{
    ForwardAcceptance Evaluate(ForwardConfig forward);
}

public sealed class MacForwardPolicy : IForwardAcceptancePolicy
{
    public const string UnsupportedNotice =
        "The mode of this forward is not supported in MacOS binary. 此转发的模式尚未在MacOS客户端支援.";
    public static MacForwardPolicy Instance { get; } = new();

    public ForwardAcceptance Evaluate(ForwardConfig forward)
    {
        var backend = (forward.BackendMode ?? "").Trim().ToLowerInvariant();
        var rawMode = !string.IsNullOrWhiteSpace(forward.ClientMode)
            ? forward.ClientMode.Trim().ToLowerInvariant()
            : (forward.Mode ?? "").Trim().ToLowerInvariant();
        var accepted = backend == "ultra" && UltraForwardPolicy.IsSupported(forward)
            || (backend == "current_mode" && rawMode is "single" or "bond");
        return accepted
            ? new(true, backend == "ultra" ? "ultra" : rawMode, "")
            : new(false, "", UnsupportedNotice);
    }
}
```

`UltraForwardPolicy.IsSupported` performs the current strict Ultra checks using
the raw effective mode: mode `single`, no sidecar/aggregation fields, a nonempty
target, an Ultra target contract (or empty legacy contract), and zero endpoint
inventory. Refactor `WindowsForwardPolicy` to call this helper so its accepted
Ultra shapes do not change.

- [x] **Step 4: Add a polling test that proves rejection precedes every side effect.**

Use the existing HTTP-handler fixture to return one `mptcp` forward. Subscribe to
`ForwardRejected` and assert the exact notice, then assert the runtime spy has
zero compute/apply/health calls, `applied.json` has no rejected ID, and
`LatestForwards` is empty. Add a supported-to-rejected revision test asserting
the old process is stopped and `RemoveApplied` deletes the old record.

- [x] **Step 5: Inject the policy into `ConfigPollingService` and filter before normalization.**

The constructor gains an optional `IForwardAcceptancePolicy` with a Windows
default. Iterate the raw `pull.Forwards` once, emit `ForwardRejected` immediately,
and reconcile only accepted active forwards. Removed/rejected IDs call
`StopForwardForPolicy` followed by `StateManager.RemoveApplied`.

- [x] **Step 6: Run focused and Windows policy tests.**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~MacForwardPolicyTests|FullyQualifiedName~ConfigPollingServiceTests|FullyQualifiedName~WindowsForwardPolicyTests"
```

Expected: PASS, including the exact bilingual notice and zero rejected-forward side effects.

- [x] **Step 7: Commit.**

```powershell
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ClientPlatformIdentity.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/IForwardAcceptancePolicy.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/UltraForwardPolicy.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/MacForwardPolicy.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/WindowsForwardPolicy.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/StateManager.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/MacForwardPolicyTests.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ConfigPollingServiceTests.cs
git commit -m "feat(mac): enforce supported forward modes"
```

### Task 2: Make macOS Pulls Policy-v2 and Inventory-free

**Files:**
- Modify: `master/src/Controllers/ClientAgentController.php:172-260,384-487`
- Create: `master/tests/client_agent_macos_ultra_policy_v2_contract_test.php`
- Modify: `master/tests/ultra_policy_lease_contract_test.php`

**Interfaces:**
- Consumes: `X-Device-Platform: macos-desktop`.
- Produces: scrubbed `ultra_access` policy plus one lease from `POST /api/client/ultra/lease`.

- [x] **Step 1: Add a failing panel contract test.**

The fixture must issue the same assigned Ultra forward to `windows-desktop`,
`linux`, and `macos-desktop`. For Mac, assert:

```php
$payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
$encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
foreach (['_ultra_lines', '_ultra_line_ids', 'client_token', 'credential', 'password'] as $forbidden) {
    if (str_contains($encoded, $forbidden)) {
        failMacUltra('Mac pull leaked ' . $forbidden);
    }
}
if (($payload['forwards'][0]['ultra_access']['protocol'] ?? '') !== 'leikwan-ultra-policy-v2') {
    failMacUltra('Mac pull did not select policy-v2');
}
```

- [x] **Step 2: Run and verify RED.**

```powershell
php master/tests/client_agent_macos_ultra_policy_v2_contract_test.php
```

Expected: FAIL because `macos-desktop` does not select the policy-v2 scrub path.

- [x] **Step 3: Centralize the platform decision and scrub before response serialization.**

Add:

```php
private static function usesUltraPolicyV2(string $platform): bool
{
    $platform = strtolower(trim($platform));
    return str_starts_with($platform, 'windows')
        || str_starts_with($platform, 'linux')
        || str_starts_with($platform, 'macos');
}
```

Pass its result to `attachUltraAccessGrants`. Keep
`scrubUltraClientForward` authoritative and do not add a Mac legacy-material
fallback when the broker fails.

- [x] **Step 4: Assert lease scope and TTL.**

Extend the lease test to assert one returned endpoint, no candidate list,
`expires_at <= now + 300`, mismatched device/forward/generation rejection,
nonce replay rejection, and `Cache-Control: no-store`.

- [x] **Step 5: Run panel tests.**

```powershell
php master/tests/client_agent_macos_ultra_policy_v2_contract_test.php
php master/tests/client_agent_linux_ultra_policy_v2_contract_test.php
php master/tests/ultra_policy_lease_contract_test.php
```

Expected: all print `ok` and exit 0.

- [x] **Step 6: Commit.**

```powershell
git add master/src/Controllers/ClientAgentController.php master/tests/client_agent_macos_ultra_policy_v2_contract_test.php master/tests/ultra_policy_lease_contract_test.php
git commit -m "feat(mac): issue scrubbed Ultra v2 policies"
```

### Task 3: Harden the Go Ultra Runtime for macOS Memory-only Bootstrap

**Files:**
- Create: `tools/ultra-client/config_input.go`
- Create: `tools/ultra-client/config_input_test.go`
- Create: `tools/ultra-client/peer_identity_linux.go`
- Create: `tools/ultra-client/peer_identity_darwin.go`
- Create: `tools/ultra-client/process_security_unix.go`
- Create: `tools/ultra-client/process_security_windows.go`
- Modify: `tools/ultra-client/main.go:24-42,598-707`
- Modify: `tools/ultra-client/control.go`
- Modify: `tools/ultra-client/lease.go`
- Modify: `tools/ultra-client/policy.go:171-307`
- Test: `tools/ultra-client/control_test.go`
- Test: `tools/ultra-client/main_test.go`
- Test: `tools/ultra-client/policy_test.go`

**Interfaces:**
- Produces: `loadConfig(configPath string, stdin bool, reader io.Reader) (Config, error)`.
- Produces: `sameUserPeer(*net.UnixConn) bool` with OS-specific implementation.
- Produces: `applyProcessSecurity() error` and best-effort `lockSecret/clearSecret`.

- [ ] **Step 1: Write failing bounded-stdin tests.**

```go
func TestLoadConfigFromStdinNeverCreatesAFile(t *testing.T) {
    raw := []byte(`{"forward_id":7,"listen_host":"127.0.0.1","listen_port":23007,"target":"198.51.100.9:443","control_socket":"/tmp/lk.sock"}`)
    config, err := loadConfig("", true, bytes.NewReader(raw))
    if err != nil { t.Fatal(err) }
    if config.Target != "198.51.100.9:443" { t.Fatalf("target=%q", config.Target) }
}

func TestLoadConfigFromStdinRejectsMoreThan64KiB(t *testing.T) {
    _, err := loadConfig("", true, bytes.NewReader(make([]byte, 64*1024+1)))
    if !errors.Is(err, ErrFrameTooLarge) { t.Fatalf("err=%v", err) }
}
```

- [ ] **Step 2: Run and verify RED.**

```powershell
Push-Location tools/ultra-client
go test ./... -run "TestLoadConfig|TestControl|TestPolicy" -count=1
Pop-Location
```

Expected: FAIL because the stdin loader and peer validation do not exist.

- [ ] **Step 3: Implement mutually exclusive `--config` and `--config-stdin`.**

Read at most 64 KiB plus one byte, reject trailing JSON values, zero the raw
buffer after decode, and never echo the config in errors. `main` calls
`applyProcessSecurity` before reading either source. File mode remains for Linux
and Windows compatibility; the Mac launcher uses stdin exclusively.

- [ ] **Step 4: Enforce same-user Unix control connections.**

`control.go` calls `sameUserPeer` before reading the frame. Linux uses
`SO_PEERCRED`; Darwin uses `getpeereid` through `golang.org/x/sys/unix`. Reject
unknown credentials, keep the socket in a `0700` parent, chmod it `0600`, and
unlink it on every exit path.

- [ ] **Step 5: Apply process and secret-memory hardening.**

On Unix set both soft and hard `RLIMIT_CORE` to zero. `ProviderLease` calls
`lockSecret` on credential slices when accepted, zeros every old/snapshot
credential copy before `Munlock`, clears the provider IP string reference on
revoke, and never logs a lease payload. A failed `mlock` records only
`secret_memory_unlocked` and continues with the same lease validation.

- [ ] **Step 6: Add lifecycle and output tests.**

Cover same-UID accept, wrong-UID reject through an injected peer checker,
duplicate nonce, oversized/partial frame, expiry cleanup, replacement cleanup,
metadata-only status, and stdout/stderr containing zero canary endpoint or
credential matches.

- [ ] **Step 7: Run native tests and Darwin compile gates.**

```powershell
Push-Location tools/ultra-client
go test ./... -count=1
$env:GOOS='darwin'; $env:GOARCH='amd64'; go test -c -o .tmp/ultra-darwin-amd64.test
$env:GOARCH='arm64'; go test -c -o .tmp/ultra-darwin-arm64.test
Remove-Item Env:GOOS,Env:GOARCH
Pop-Location
```

Expected: tests PASS and both Mach-O test binaries compile. The `.tmp` outputs remain ignored.

- [ ] **Step 8: Commit.**

```powershell
git add tools/ultra-client
git commit -m "feat(mac): harden Ultra memory-only runtime"
```

### Task 4: Extract Runtime Interfaces and Add the Mac Ultra Host

**Files:**
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Platform/IProcessSupervisor.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/IUltraRuntimeHost.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/WindowsUltraRuntimeHost.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/UnixUltraLeaseClient.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ProcessSupervisor.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs:12-385`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs:12-60`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UltraLease.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacProcessSupervisor.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacUltraRuntimeHost.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/UnixUltraLeaseClientTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MacProcessSupervisorTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MacUltraRuntimeHostTests.cs`
- Modify: existing runtime manager, health, job, and lease tests.

**Interfaces:**
- `Process IProcessSupervisor.StartProcess(int forwardId, string role, string label, string exePath, string arguments, byte[]? standardInput = null, bool logArguments = true)`.
- `void IProcessSupervisor.StopAll(int forwardId)`, `void StopAll()`, `bool IsRunning(int forwardId)`, `IReadOnlyList<int> GetProcessIds(int forwardId)`, and `IReadOnlyList<string> GetRunningRoles(int forwardId)`.
- `event Action<int,string>? IProcessSupervisor.ProcessExited`.
- `void IUltraRuntimeHost.Start(ForwardConfig forward, UltraLease lease, string contractSignature, string applyReason)`; failure throws a non-secret exception.
- `bool IUltraRuntimeHost.Refresh(ForwardConfig forward, UltraLease lease)`.
- `void IUltraRuntimeHost.Stop(int forwardId)`.

- [ ] **Step 1: Write failing Windows extraction tests.**

Retain every current `ApplyForward_Ultra*` assertion, then inject a recording
`IUltraRuntimeHost` and assert `ForwardRuntimeManager` delegates Ultra
start/refresh/stop while single/bond still use the existing builders. This is
the regression fence before moving current Windows code.

- [ ] **Step 2: Run and verify RED.**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~ForwardRuntimeManagerTests|FullyQualifiedName~WindowsJobGroupTests"
```

Expected: FAIL because the runtime abstractions do not exist.

- [ ] **Step 3: Extract `IProcessSupervisor` and `WindowsUltraRuntimeHost` without behavior changes.**

Move the current config-file/named-pipe Ultra code into the Windows host. Make
`ProcessSupervisor` and test supervisors implement the new interface. When
`standardInput` is non-null, redirect stdin, write/flush/close it immediately,
and zero the supplied byte array in `finally`. When `logArguments=false` log only
forward ID, role, and executable basename.

- [ ] **Step 4: Write failing Unix lease and Mac host tests.**

Use a temporary Unix listener to verify the four-byte frame, one acknowledgement,
64 KiB limit, two-second timeout, exact forward/generation match, and zeroed body
buffer. The Mac host test injects canary target/IP/user/password and asserts:

```csharp
Assert.Equal("--config-stdin", launch.Arguments);
Assert.False(launch.LogArguments);
Assert.DoesNotContain(canaryTarget, Directory.EnumerateFiles(root, "*", SearchOption.AllDirectories)
    .Select(File.ReadAllText).Aggregate("", (left, right) => left + right));
Assert.Equal(Convert.FromHexString(new string('0', lease.Password.Length * 2)), lease.Password);
```

- [ ] **Step 5: Implement `MacProcessSupervisor`.**

Start each helper in its own process group using `setpgid(pid,pid)`. Stop sends
`SIGTERM` to the negative group ID, waits up to three seconds, then sends
`SIGKILL` and disposes every `Process`. Never invoke a shell. Track roles/PIDs
with the same semantics as the Windows supervisor.

- [ ] **Step 6: Implement `MacUltraRuntimeHost`.**

Create `<runtime>/ultra-<forward>-<128-bit-random>/` mode `0700`; choose a random
socket name; keep only the metadata status path on disk. Serialize the bootstrap
to a mutable UTF-8 byte array, start `ultra-client --config-stdin`, zero the
array, wait for the socket, send the lease, then require the local listener.
`Refresh` uses the in-memory socket path. `Stop` kills the process group and
removes the socket directory/status.

- [ ] **Step 7: Run Core and Mac runtime tests.**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~ForwardRuntimeManagerTests|FullyQualifiedName~UnixUltraLeaseClientTests|FullyQualifiedName~HealthCheckServiceTests|FullyQualifiedName~WindowsJobGroupTests"
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/LeikwanBridge.Mac.Tests.csproj --filter "FullyQualifiedName~MacProcessSupervisorTests|FullyQualifiedName~MacUltraRuntimeHostTests"
```

Expected: PASS; Windows tests retain their prior assertions and Mac tests find no canary on disk/argv/log.

- [ ] **Step 8: Commit.**

```powershell
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacProcessSupervisor.cs LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacUltraRuntimeHost.cs LeikwanBridge.Desktop/tests
git commit -m "feat(mac): add Unix process and Ultra runtime hosts"
```

### Task 5: Add macOS Paths, Device Identity, Keychain, Ports, and LaunchAgent

**Files:**
- Create the six Platform interfaces listed in the File Map.
- Create: `MacPlatformPaths.cs`, `MacKeychainSecretStore.cs`, `MacAgentConfigStore.cs`, `MacPortOwnershipProbe.cs`, `MacAutostartService.cs`.
- Modify: `MasterApiClient.cs:10-327`
- Modify: `AgentConfig.cs:1-170`
- Test: matching `LeikwanBridge.Mac.Tests` files and `MasterApiClientDesktopLoginTests.cs`.

**Interfaces:**
- `ClientPlatformIdentity(string Platform, string DeviceId, string DeviceName, string UserAgentProduct, string AppVersion, bool SupportsMptcp)`.
- `Task<string?> ISecretStore.GetAsync(string name, CancellationToken ct)`, `Task SetAsync(string name, string value, CancellationToken ct)`, and `Task DeleteAsync(string name, CancellationToken ct)`.
- `IReadOnlyList<PortOwner> IPortOwnershipProbe.FindOwners(int port)` and `string? ResolveExecutablePath(int pid)`; `PortConflictPreflight` performs the existing managed/foreign classification.
- `Task<bool> IAutostartService.IsEnabledAsync(CancellationToken ct)` and `Task SetEnabledAsync(bool enabled, CancellationToken ct)`.
- `IClientPlatformPaths` exposes `ConfigPath`, `StateDirectory`, `RuntimeDirectory`, `LogDirectory`, `BundleDirectory`, and `BinDirectory`.

- [ ] **Step 1: Write failing path and identity tests.**

```csharp
var paths = new MacPlatformPaths("/Users/tester", "/Applications/LeiKwan Bridge.app");
Assert.Equal("/Users/tester/Library/Application Support/LeiKwan/ClientAgent", paths.Root);
Assert.Equal("/Users/tester/Library/Logs/LeiKwan/ClientAgent", paths.LogDirectory);
Assert.Equal("/Applications/LeiKwan Bridge.app/Contents/Resources/bin", paths.BinDirectory);
Assert.Matches("^macos-client-[a-f0-9]{32}$", identity.DeviceId);
```

Also capture a request and assert all four required Mac headers and no Windows
user agent.

- [ ] **Step 2: Run and verify RED.**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/LeikwanBridge.Mac.Tests.csproj --filter "FullyQualifiedName~MacPlatformPathsTests|FullyQualifiedName~MacKeychainSecretStoreTests|FullyQualifiedName~MacAgentConfigStoreTests|FullyQualifiedName~MacPortOwnershipProbeTests|FullyQualifiedName~MacAutostartServiceTests"
```

- [ ] **Step 3: Implement paths and restrictive modes.**

Create root/state/runtime/log directories as `0700` and config/device ID files as
`0600` using `File.SetUnixFileMode`. Generate 16 random bytes once for the device
ID; never derive it from a hardware serial or MAC address. Mac app composition
sets all `AgentConfig` helper paths explicitly, so Windows `.exe` defaults remain
unchanged.

- [ ] **Step 4: Implement Keychain with a testable native boundary.**

Wrap `SecKeychainFindGenericPassword`, `SecKeychainAddGenericPassword`, and
`SecKeychainItemModifyAttributesAndData` behind `IMacKeychainNative`. Service
`com.leikwan.bridge` stores accounts `desktop-session` and
`client-agent-<id>`. Zero unmanaged/managed password buffers after each call.
If Keychain is unavailable, use a `0600` fallback secret file and emit only the
non-secret code `keychain_unavailable`.

`MacAgentConfigStore` loads session/agent tokens from `ISecretStore`. When
Keychain succeeds it serializes an `AgentConfig` clone with `AgentToken` and
`DesktopSessionToken` empty; when the documented fallback is active it writes
only those tokens to the separate `0600` fallback secret file, never to the
general config JSON. Tests seed canary tokens and assert zero matches in
`config.json`.

- [ ] **Step 5: Implement structured `lsof` and LaunchAgent handling.**

Run `/usr/sbin/lsof` with `ProcessStartInfo.ArgumentList` and `-Fpcu`; validate
the requested port before launch and never use shell interpolation. Generate the
plist with `XmlWriter`, including a ProgramArguments array, `RunAtLoad=true`,
`KeepAlive=false`, and no tokens. Write atomically mode `0600`, then use
`launchctl bootstrap/bootout gui/<uid>`.

- [ ] **Step 6: Run focused tests and a source scan.**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter FullyQualifiedName~MasterApiClientDesktopLoginTests
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/LeikwanBridge.Mac.Tests.csproj --filter "FullyQualifiedName~MacPlatformPathsTests|FullyQualifiedName~MacKeychainSecretStoreTests|FullyQualifiedName~MacAgentConfigStoreTests|FullyQualifiedName~MacPortOwnershipProbeTests|FullyQualifiedName~MacAutostartServiceTests"
rg -n "windows-client|Leikwan-Windows-GUI|\.exe" LeikwanBridge.Desktop/src/LeikwanBridge.Mac
```

Expected: tests PASS; the source scan returns no Mac identity or helper-path regression.

- [ ] **Step 7: Commit.**

```powershell
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services LeikwanBridge.Desktop/tests
git commit -m "feat(mac): add platform services and identity"
```

### Task 6: Add the Shared Desktop Lifecycle, Reporting, and Diagnostics

**Files:**
- Create: `DesktopClientSnapshot.cs`, `DesktopClientController.cs`, `ClientDiagnosticsService.cs`.
- Modify: `HeartbeatService.cs`, `ConfigPollingService.cs`, `ClientMeta.cs`, `MainViewModel.cs`.
- Test: `DesktopClientControllerTests.cs`, `ClientDiagnosticsServiceTests.cs`, `HeartbeatServiceTests.cs`, `MainViewModelTests.cs`.

**Interfaces:**
- Controller methods: `LoginAsync`, `SelectClientAsync`, `ConnectAsync`, `DisconnectAsync`, `StartForwardAsync`, `StopForwardAsync`, `StartAllAsync`, `StopAllAsync`, `SignOutAsync`.
- Events: `SnapshotChanged`, `ForwardsChanged`, `NoticeRaised`, `LogRaised`, `UpdateAvailable`.

- [ ] **Step 1: Write controller state-machine tests.**

Cover successful login, multi-client selection, secret-store persistence,
connect, first pull, `304` no-op, changed revision, start/stop, sign-out,
heartbeat retry, Ultra stop action, and cancellation. Add this rejection
assertion:

```csharp
await controller.ConnectAsync();
Assert.Equal(MacForwardPolicy.UnsupportedNotice, notices.Single());
Assert.Empty(controller.Snapshot.Forwards);
Assert.Equal(0, runtime.ApplyCalls);
Assert.Equal(0, health.UpdateCalls);
```

- [ ] **Step 2: Run and verify RED.**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~DesktopClientControllerTests|FullyQualifiedName~ClientDiagnosticsServiceTests|FullyQualifiedName~HeartbeatServiceTests"
```

- [ ] **Step 3: Implement the controller and start all background loops.**

The controller owns/disposes API, polling, heartbeat, health, runtime, and
supervisor instances. It starts health checks after the first accepted snapshot,
sends heartbeat after each successful pull and on a bounded 30-second timer,
advances usage counters only after a successful response, and cancels/awaits all
loops before stopping process groups.

- [ ] **Step 4: Make heartbeat identity platform-driven.**

Replace hardcoded Windows `AgentVersion`/`SupportsMptcp=true` with
`ClientPlatformIdentity.AppVersion` and `SupportsMptcp`. Mac reports false and
only the single/bond/Ultra capability names. Preserve Windows values through its
identity factory.

- [ ] **Step 5: Implement redacted diagnostics.**

For each accepted forward report only forward ID, local listener state, managed
process count, helper-present booleans, and bounded error codes. Never report
target, endpoint, SNI, route key, auth token, session ticket, provider line, file
contents, or absolute home paths. Cache the last processed diagnostic request
token and submit one result per token without blocking pull/heartbeat.

- [ ] **Step 6: Adapt WPF `MainViewModel` to controller events.**

Keep public properties/commands and XAML bindings stable. Move lifecycle/API
ownership into the controller and translate controller snapshots on the WPF
dispatcher. Existing WPF tests must require no text, command, or update-flow
changes.

- [ ] **Step 7: Run controller, WPF, heartbeat, and diagnostics tests.**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~DesktopClientControllerTests|FullyQualifiedName~ClientDiagnosticsServiceTests|FullyQualifiedName~HeartbeatServiceTests|FullyQualifiedName~ConfigPollingServiceTests"
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj --filter FullyQualifiedName~MainViewModelTests
```

- [ ] **Step 8: Commit.**

```powershell
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs LeikwanBridge.Desktop/tests
git commit -m "refactor: share desktop client lifecycle"
```

### Task 7: Build the Avalonia macOS Shell and Tray Workflow

**Files:**
- Create all `LeikwanBridge.Mac` app/view/view-model/resource files from the File Map.
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/MainWindowViewModelTests.cs`
- Modify: `LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln`

**Interfaces:**
- Consumes only `DesktopClientController` commands/events and platform services.
- Produces no API/runtime decisions inside views.

- [ ] **Step 1: Add the projects and failing headless view-model tests.**

Pin Avalonia packages to `11.3.20` and Lucide.Avalonia to `0.2.19`. Test login
validation, client picker, forward row commands, busy states, update prompt,
close-to-tray preference, and immediate unsupported notice.

```csharp
controller.RaiseNotice(MacForwardPolicy.UnsupportedNotice);
Assert.True(vm.IsNoticeVisible);
Assert.Equal(MacForwardPolicy.UnsupportedNotice, vm.NoticeText);
controller.RaiseForwards([single, bond, ultra]);
Assert.Equal(new[] { "single", "bond", "ultra" }, vm.Forwards.Select(x => x.Mode));
```

- [ ] **Step 2: Run and verify RED.**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/LeikwanBridge.Mac.Tests.csproj --filter FullyQualifiedName~MainWindowViewModelTests
```

- [ ] **Step 3: Implement the app composition root.**

`Program.cs` enables `RLIMIT_CORE=0` on macOS before Avalonia starts, enforces a
single user instance, configures redacted rolling logs, creates platform
services/controller, and supports `--apply-update <request-file>` without opening
the main window.

- [ ] **Step 4: Implement the work-focused window.**

Use a fixed 220 px navigation rail and responsive content region. First launch
shows the real login form, not a landing page. The dashboard shows connection
state, traffic counters, and one compact row per accepted forward with mode,
local port, lifecycle, health, and icon controls. Use Lucide icons with tooltips
for start, stop, refresh, logs, settings, and update. Use no nested cards,
negative letter spacing, decorative gradients, or oversized headings.

- [ ] **Step 5: Implement client selection, settings, log, notice, and update surfaces.**

Passwords never enter view-model logs or persisted bindings. The unsupported
notice is a top-level transient banner/dialog shown as soon as the event arrives.
Settings cover panel URL, language, autostart, close-to-tray, update check, and
sign-out. Keep advanced runtime paths hidden unless a `LEIKWAN_DEBUG` environment
flag is set.

- [ ] **Step 6: Implement the menu-bar lifecycle.**

Use Avalonia `TrayIcon` and `NativeMenu` for Show, Start All, Stop All, and Quit.
Window close hides only when close-to-tray is enabled; Quit awaits
`DisconnectAsync` before terminating. A missing tray implementation leaves the
window closable and never traps the user in a hidden process.

- [ ] **Step 7: Run headless tests and a cross-platform build.**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/LeikwanBridge.Mac.Tests.csproj
dotnet build LeikwanBridge.Desktop/src/LeikwanBridge.Mac/LeikwanBridge.Mac.csproj -c Release
```

Expected: PASS/build exit 0 on Windows; native rendering remains a macOS CI gate.

- [ ] **Step 8: Commit.**

```powershell
git add LeikwanBridge.Desktop/src/LeikwanBridge.Mac LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln
git commit -m "feat(mac): add Avalonia desktop shell"
```

### Task 8: Add Architecture-aware Updates

**Files:**
- Create: `ClientAppArtifact.cs`, `MacAppUpdateService.cs`, `MacBundleValidator.cs`.
- Modify: Core update manifest/service models without changing Windows JSON.
- Test: `MacAppUpdateServiceTests.cs` and existing `WindowsAppUpdateServiceTests.cs`.

**Interfaces:**
- `Task<ClientAppArtifact?> IAppUpdater.CheckAsync(string panelUrl, string currentVersion, CancellationToken ct)`.
- `Task<PreparedAppUpdate> IAppUpdater.PrepareAsync(ClientAppArtifact artifact, CancellationToken ct)`.
- `Task<AppUpdateLaunchResult> IAppUpdater.ApplyAsync(PreparedAppUpdate update, bool deferUntilRestart, CancellationToken ct)`.
- `ClientAppArtifact` includes `Version`, `ArtifactUrl`, `Sha256`, `SizeBytes`, `Architecture`, and `Format`.

- [ ] **Step 1: Write failing manifest and updater tests.**

Cover architecture selection, version comparison, size/hash mismatch, ZIP path
traversal, wrong bundle ID, missing executable, wrong Mach-O architecture,
helper hash mismatch, writable atomic swap rollback, unwritable bundle DMG
fallback, immediate apply, and deferred restart.

- [ ] **Step 2: Run and verify RED.**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/LeikwanBridge.Mac.Tests.csproj --filter FullyQualifiedName~MacAppUpdateServiceTests
```

- [ ] **Step 3: Implement manifest selection and verified download.**

`arm64` selects `macos_client_update_arm64` and `macos_client_app_arm64`;
`x64` selects the x64 pair. Require HTTPS or the configured panel origin,
positive size, 64-lower/upper-hex SHA, exact downloaded size, then SHA-256 before
opening an archive.

- [ ] **Step 4: Validate and stage without path traversal.**

Inspect every ZIP entry with `ZipArchive` before extraction; reject rooted paths,
`..` segments, symlinks outside the root, and multiple app bundles. Validate
`CFBundleIdentifier=com.leikwan.bridge`, version, main executable, runtime helper
manifest, helper hashes, and the expected `arm64`/`x86_64` Mach-O slice.

- [ ] **Step 5: Implement apply/rollback and DMG fallback.**

Write a mode-`0600` request containing only PID, paths, version, size, and hashes.
The updater waits for exit, renames the current bundle to a sibling backup,
renames staged to current, relaunches with `/usr/bin/open -n`, and removes backup
only after launch. Any failure restores backup. If the bundle parent is not
writable, retain the verified DMG and open it for manual replacement; do not
claim automatic success.

- [ ] **Step 6: Run Mac and Windows update tests.**

```powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/LeikwanBridge.Mac.Tests.csproj --filter FullyQualifiedName~MacAppUpdateServiceTests
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter FullyQualifiedName~WindowsAppUpdateServiceTests
```

- [ ] **Step 7: Commit.**

```powershell
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services LeikwanBridge.Desktop/tests
git commit -m "feat(mac): add verified desktop updates"
```

### Task 9: Add Panel Artifact Routes and the Tenant Download Surface

**Files:**
- Modify: `master/src/Controllers/ArtifactController.php:273-409`
- Modify: `master/src/Bootstrap.php:351-392`
- Modify: `master/src/Controllers/BaseController.php`
- Modify: `master/src/Controllers/TenantController.php:1076-1093`
- Create: `master/tests/macos_client_artifact_manifest_test.php`
- Create: `master/tests/macos_client_install_surface_test.php`
- Modify: `artifacts/versions.json` only through the metadata merge script after real artifacts exist.

- [ ] **Step 1: Write failing routed-manifest tests.**

Provide fixture metadata for four app entries and assert absolute/cached URLs,
version, SHA, size, architecture, format, and correct content types. Assert a
missing file is not advertised as downloadable.

- [ ] **Step 2: Run and verify RED.**

```powershell
php master/tests/macos_client_artifact_manifest_test.php
php master/tests/macos_client_install_surface_test.php
```

- [ ] **Step 3: Add explicit routes and a shared cache-busting normalizer.**

Add DMG/update routes for arm64 and x64. Reuse one method to append `v` and
`sha256` to Windows and Mac app entries. Keep `serveRepoFile` path confinement
and range/cache behavior unchanged.

- [ ] **Step 4: Add the Mac tenant download band.**

Render a macOS section beside Windows/Linux with two clearly named downloads:
`Apple Silicon` and `Intel`. Show only the short unsigned first-open note. Do not
embed client IDs, agent tokens, provider nodes, or helper URLs in download
links.

- [ ] **Step 5: Run panel artifact/install regressions.**

```powershell
php master/tests/macos_client_artifact_manifest_test.php
php master/tests/macos_client_install_surface_test.php
php master/tests/windows_client_manifest_relative_artifact_url_regression_test.php
php master/tests/client_install_simplification_regression_test.php
php master/tests/artifact_versions_manifest_consistency_test.php
```

- [ ] **Step 6: Commit.**

```powershell
git add master/src/Controllers/ArtifactController.php master/src/Bootstrap.php master/src/Controllers/BaseController.php master/src/Controllers/TenantController.php master/tests/macos_client_artifact_manifest_test.php master/tests/macos_client_install_surface_test.php
git commit -m "feat(mac): expose desktop client artifacts"
```

### Task 10: Build Native Helpers, App Bundles, DMGs, and Update Archives

**Files:**
- Create: `scripts/build-macos-client.sh`
- Create: `scripts/package-macos-client.sh`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Info.plist.template`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/helper-manifest.schema.json`
- Modify: `artifacts/versions.json` via a deterministic metadata merge mode.

- [ ] **Step 1: Add shell static tests before implementation.**

Use `bash -n` and a `--dry-run` fixture that fails for a missing helper, wrong
architecture, mismatched SHA, PDB/source inclusion, or unexpected bundle path.
The dry run must list no credential/environment files.

- [ ] **Step 2: Build helpers at pinned inputs.**

For the matrix target:

```bash
cargo build --locked --release --target "$RUST_TARGET" -p leikwan-bond -p leikwan-ingress -p leikwan-uot
GOOS=darwin GOARCH="$GO_ARCH" CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o "$STAGE/bin/ultra-client" ./tools/ultra-client
git -C "$WSTUNNEL_SRC" checkout --detach 15372e44f2606f8e2e22a8a90d2e01cc60889836
cargo build --locked --release --target "$RUST_TARGET" --manifest-path "$WSTUNNEL_SRC/Cargo.toml"
```

The pinned wstunnel commit is upstream tag `v10.5.2`. Copy only
`wstunnel`, `leikwan-bond`, `leikwan-ingress`, `leikwan-uot-bond`, and
`ultra-client`. `wg` is optional and is not a supported-mode build dependency.

- [ ] **Step 3: Publish and assemble the app bundle.**

`dotnet publish` self-contained for `osx-arm64` or `osx-x64`. Assemble:

```text
LeiKwan Bridge.app/
  Contents/Info.plist
  Contents/MacOS/LeikwanBridge.Mac
  Contents/MacOS/<managed/native publish files>
  Contents/Resources/AppIcon.icns
  Contents/Resources/bin/<five helpers>
  Contents/Resources/helper-manifest.json
```

Set app/helper executables `0755`, data `0644`, and directories `0755`.
`Info.plist` contains bundle ID, version, icon, minimum macOS version, and no
privileged entitlement/signature claims.

- [ ] **Step 4: Validate Mach-O and package.**

Use `file` and `lipo -archs` to require exactly the matrix architecture for the
main executable and every native helper. Create a compressed DMG with `hdiutil`
and a `ditto` ZIP preserving modes. Emit JSON containing version, URLs, SHA-256,
size, architecture, format, helper hashes, source commit, Go/Rust/.NET versions.

- [ ] **Step 5: Merge only real metadata.**

`--merge-versions artifacts/versions.json` refuses missing files, recomputes
hash/size from bytes, writes through a temp file, and adds the four app/update
keys plus architecture-specific helper keys. Never prefill fake hashes.

- [ ] **Step 6: Run shell checks on Windows through WSL and commit.**

```powershell
wsl bash -n scripts/build-macos-client.sh
wsl bash -n scripts/package-macos-client.sh
wsl bash scripts/build-macos-client.sh --dry-run --rid osx-arm64
wsl bash scripts/build-macos-client.sh --dry-run --rid osx-x64
```

Expected: syntax/dry-run PASS; real DMG creation is deferred to macOS CI.

```powershell
git add scripts/build-macos-client.sh scripts/package-macos-client.sh LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Info.plist.template LeikwanBridge.Desktop/src/LeikwanBridge.Mac/helper-manifest.schema.json
git commit -m "build(mac): package dual architecture clients"
```

### Task 11: Add Dual-architecture CI and Ultra Canary-leak Gates

**Files:**
- Create: `.github/workflows/macos-client.yml`
- Create: `scripts/test-macos-ultra-leaks.sh`
- Modify: Mac/Go tests to expose a test-only canary lifecycle command.

- [ ] **Step 1: Write the failing leak script fixture.**

Create unique fake values at runtime, run start/refresh/stop, and fail if any
value appears in app state, logs, argv/environment snapshots taken while
running, update cache, crash directory, app bundle, DMG mount, or update ZIP.
Never use a real node or credential.

- [ ] **Step 2: Run the fixture and verify RED until the test command exists.**

```bash
CANARY_IP=192.0.2.231 CANARY_USER=lk_canary_7f4a CANARY_PASS=lk_secret_91bc +  bash scripts/test-macos-ultra-leaks.sh --fixture
```

- [ ] **Step 3: Add CI jobs with native smoke tests.**

Each Intel/Apple Silicon job restores exact NuGet/Go/Cargo locks, runs PHP
contracts, all Core/Mac tests, Go tests, Rust tests, native publish, helper
architecture checks, app launch/exit smoke, DMG attach/detach, update ZIP
validation, and canary leak scan. Upload DMG, update ZIP, metadata, test logs,
and screenshots; never upload app state or raw diagnostic bodies. Capture the
login, dashboard, forwards, and settings states at 1440x900 and 1024x768; fail
the smoke test for blank frames, clipped fixed controls, or overlapping text.

- [ ] **Step 4: Add workflow artifact assertions.**

The final job downloads both matrix outputs and rejects equal architecture
labels, missing files, zero sizes, duplicate SHA values for unlike binaries,
manifest/file mismatch, or forbidden high-risk literals. It produces a
credential-free combined metadata file for later publishing.

- [ ] **Step 5: Run local workflow/static validation.**

```powershell
git diff --check
rg -n "leikwan-v3|cantons\.txt|shanghais\.txt|sidecars\.txt" .github scripts LeikwanBridge.Desktop/src/LeikwanBridge.Mac
wsl bash -n scripts/test-macos-ultra-leaks.sh
```

Expected: no credential-file references and all shell syntax checks pass.

- [ ] **Step 6: Commit.**

```powershell
git add .github/workflows/macos-client.yml scripts/test-macos-ultra-leaks.sh LeikwanBridge.Desktop/tests tools/ultra-client
git commit -m "ci(mac): verify dual architecture client artifacts"
```

### Task 12: Full Regression, Native Acceptance, and Delivery Record

**Files:**
- Create: `docs/handoffs/2026-08-27-macos-client-handoff.md`
- Modify only defects exposed by the gates below, each with a failing regression test first.

- [ ] **Step 1: Run the full local Core/App/Mac suite.**

```powershell
dotnet test LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln -c Release
```

- [ ] **Step 2: Run runtime suites.**

```powershell
Push-Location tools/ultra-client
go test ./... -count=1
Pop-Location
Push-Location wstunnel-main
cargo test -p leikwan-bond -p leikwan-ingress -p leikwan-uot --locked
Pop-Location
```

- [ ] **Step 3: Run focused panel gates.**

```powershell
php master/tests/client_agent_macos_ultra_policy_v2_contract_test.php
php master/tests/ultra_policy_lease_contract_test.php
php master/tests/macos_client_artifact_manifest_test.php
php master/tests/macos_client_install_surface_test.php
php master/tests/windows_client_app_version_manifest_regression_test.php
```

- [ ] **Step 4: Run security/static acceptance.**

```powershell
git diff --check
rg -n "The mode of this forward is not supported in MacOS binary\. 此转发的模式尚未在MacOS客户端支援\." LeikwanBridge.Desktop
rg -n "_ultra_lines|_ultra_line_ids|ProviderIp|Password|SessionTicket" LeikwanBridge.Desktop/src/LeikwanBridge.Mac
rg -n "UseShellExecute\s*=\s*true|/bin/(ba)?sh|-c\s" LeikwanBridge.Desktop/src/LeikwanBridge.Mac
```

Review each match: secret names may occur only in in-memory transfer types/tests;
no match may occur in persistence, logging, diagnostics, update, or packaging.

- [ ] **Step 5: Require both native CI artifacts before claiming usable delivery.**

For each downloaded DMG: verify SHA/size against metadata, mount read-only,
inspect `Info.plist` and helper manifest, launch the app, exercise login fixture,
receive the unsupported-mode notice, run one single/bond/Ultra fixture, quit,
and confirm no child process/listener remains. An unrun native gate is recorded
as unproven; it is never described as passing.

- [ ] **Step 6: Write the handoff.**

Record purpose, commits, exact artifact names/hashes/sizes, supported modes,
fixed notice, tests run and outcomes, unsigned Gatekeeper behavior, known
privileged-memory limitation, update behavior, artifact publication status,
rollback, and the explicit statement that production was not deployed.

- [ ] **Step 7: Commit the verified handoff.**

```powershell
git add docs/handoffs/2026-08-27-macos-client-handoff.md
git commit -m "docs: record macOS client acceptance"
```
