# Windows Client Implementation And Maintenance Log

Date: 2026-04-18

Scope:

- Windows `.NET` client backend Linux-parity work
- Windows UX / tray / control-surface fixes
- local install replacement
- Windows setup packaging
- panel artifact publishing

## 1. What Was Implemented

### 1.1 Linux-Parity Runtime Behavior

Windows client runtime behavior was aligned with the current Linux `single` / `bond` model:

- `single` stays on direct `wstunnel`
- `bond + plain_tcp_mux` now uses:
  - one `leikwan-ingress.exe` per slave leg
  - readiness-gated startup
  - one `leikwan-bond.exe` above those legs
- runtime topology is persisted so the app can reason about recovery
- whole-forward recovery is used instead of patching individual children in place

Main files:

- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ProcessSupervisor.cs`

### 1.2 Windows UX / Shell Fixes

The following UX issues were fixed:

- post-login blank white window
  - cause: invalid inverse visibility binding in WPF
- hide-on-close with no real tray restore path
  - cause: window `Hide()` on close while tray icon did not exist
- dashboard felt too raw and forward cards were not actionable
- startup did not auto-connect when valid config was already present

Main files:

- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Converters/Converters.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/App.xaml.cs`

### 1.3 Windows Rule Policy

Windows client now keeps only the rule types intended for this app:

- keep: `bond`, `single`
- remove from Windows pull usage: `mptcp`, `race`

Main files:

- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/WindowsForwardPolicy.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`

### 1.4 URL Default

Default Windows master URL was changed to:

- `https://v3-mirror.leikwanhost.com`

Legacy saved config using:

- `https://v3.leikwanhost.com`

is migrated to the mirror URL on startup.

Main files:

- `LeikwanBridge.Desktop/src/LeikwanBridge.App/AppDefaults.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`

### 1.5 Stop / Restart Freeze Fix

User-driven Stop operations were fixed so the app does not feel frozen and does not immediately reanimate the stopped forward.

Implemented changes:

- Start / Stop / Restart actions moved off the UI thread
- stopped forwards are marked `user_stopped`
- health checks and config sync skip user-stopped forwards
- restart logic is de-duplicated and cooled down earlier

Main files:

- `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentState.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`

## 2. Test Coverage Added

### Core tests

- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondRuntimeBuilderTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsForwardPolicyTests.cs`

### App tests

- `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/ViewModels/MainViewModelTests.cs`

Latest verified totals:

- Core tests: `11 passed`
- App tests: `5 passed`

## 3. Packaging / Installer

Reusable packaging helper:

- `scripts/build-windows-client-setup.ps1`

What it does:

- stages publish output and required runtimes
- includes:
  - `leikwan-client-win.exe`
  - `bin/wstunnel.exe`
  - `bin/leikwan-bond.exe`
  - `bin/leikwan-ingress.exe`
- builds a self-extracting Windows setup exe using `iexpress.exe`
- writes an install manifest after installation
- creates timestamped local backups before replacement

## 4. Local Install State

Local install path:

- `C:\Users\frankniubi\AppData\Local\LeiKwan Bridge`

Current installed app:

- `C:\Users\frankniubi\AppData\Local\LeiKwan Bridge\leikwan-client-win.exe`
- SHA256: `D9A82BA1CF262FC8E9587262FC4C3395FB687801F261A94D81E2C33306BE705C`

Current local install manifest:

- `C:\Users\frankniubi\AppData\Local\LeiKwan Bridge\install-manifest.json`

Current local config:

- `C:\ProgramData\Leikwan\ClientAgent\config.json`
- `master_url = https://v3-mirror.leikwanhost.com`

Recent local backups:

- `backup-20260418-165532`
- `backup-20260418-165513`
- `backup-20260418-164042`
- `backup-20260418-164021`
- `backup-20260418-161130`

## 5. Published Artifact State

Repo artifact metadata:

- `artifacts/versions.json`
- `windows_client_app.version = 0.1.5`

Current setup artifact:

- `artifacts/windows/leikwan-bridge-setup.exe`
- SHA256: `511D45417E90B6A69AF4AAA2E338102DADB1B1649620840792C28CE6F7F54BA6`

Windows runtime artifacts currently kept in repo:

- `artifacts/wstunnel/leikwan-bond_windows_amd64.exe`
- `artifacts/wstunnel/leikwan-ingress_windows_amd64.exe`

## 6. Panel Publish State

Panel host:

- `10.0.10.196`

Panel repo root:

- `/www/wwwroot/v3.leikwanhost.com/leikwan-v3`

Published Windows artifact state verified:

- `WINDOWS_VERSION = 0.1.5`
- panel setup SHA256 = `511d45417e90b6a69af4aaa2e338102dadb1b1649620840792c28ce6f7f54ba6`

## 7. Maintenance Notes

### 7.1 Expected Windows behavior now

- app auto-connects if valid saved config exists
- app defaults to `https://v3-mirror.leikwanhost.com`
- tray icon is the primary hidden-state recovery path
- only `bond` / `single` rules are kept for Windows use
- forward cards are clickable and show a detail panel
- manual Stop should no longer immediately re-trigger runtime recovery

### 7.2 Known operational caveats

- the app still logs `wg.exe` missing unless Windows WireGuard runtime is installed or configured
- some runtime logs may still look noisy for unsupported or degraded remote paths; this does not automatically mean the shell is broken
- Windows file replacement is sensitive to lingering hidden instances, so packaging/upgrade flows must stop existing `leikwan-client-win.exe` first

### 7.3 Safe upgrade workflow

1. Build and verify:
   - `dotnet test ...Core.Tests...`
   - `dotnet test ...App.Tests...`
   - `dotnet build ...sln`
2. Publish:
   - `dotnet publish ... -o artifacts/windows/publish`
3. Package:
   - `powershell -ExecutionPolicy Bypass -File .\\scripts\\build-windows-client-setup.ps1`
4. Stop installed Windows processes before replacing local install
5. Replace local install via staged `install.ps1`
6. Update `artifacts/versions.json`
7. Publish `versions.json` and `artifacts/windows/leikwan-bridge-setup.exe` to panel
8. Read back panel version + setup SHA before claiming release is done

## 8. Recommended Next Checks

- Live-click verification of:
  - single-forward Stop
  - tray restore after `X`
  - forward-detail selection
- Optional polish:
  - improve Windows file/product version metadata so Explorer version matches panel artifact version
  - add richer forward diagnostics in the selected-forward detail panel
## 2026-04-20 Session (Windows Bond Runtime Refresh / Branding / Panel Republish)
- User asked for four concrete Windows follow-ups on top of the existing desktop app work:
  - read the current Linux bond logic of this version
  - update the local Windows bond binary/runtime to match it
  - generate a new installer
  - add a white-background logo/icon to the executable surface
- Re-audited the current local Windows state before changing anything:
  - installed bond binary at `%LOCALAPPDATA%\\LeiKwan Bridge\\leikwan-bond.exe`
  - local release copy at `wstunnel-main/target/release/leikwan-bond.exe`
  - both still reported `leikwan-bond 0.1.0`
  - both hashed to the same older file
- Cross-checked the latest Rust source and confirmed the Windows runtime was behind the Linux-reference source already in the repo:
  - `wstunnel-main/leikwan-bond/src/main.rs` now uses `frame`, `session`, `transport`
  - `wstunnel-main/leikwan-bond/src/session.rs` contains the newer persistent transport, warmup/bulk striping, health, and cooldown logic
  - `wstunnel-main/leikwan-bond/src/token.rs` contains richer session claim parsing
- Wrote and committed the design for this refresh:
  - `docs/superpowers/specs/2026-04-20-windows-bond-binary-refresh-branding-design.md`
  - commit: `1f30951 docs: add windows bond refresh and branding design`
- Wrote the implementation plan used for the execution pass:
  - `docs/superpowers/plans/2026-04-20-windows-bond-binary-refresh-branding.md`

### App-side branding work
- Added a small app-side branding helper so tray icon loading is deterministic:
  - `LeikwanBridge.Desktop/src/LeikwanBridge.App/Services/BrandingAssets.cs`
- Added app-level tests for packaged icon resolution:
  - `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/Services/BrandingAssetsTests.cs`
- Added a generated Windows branding asset set:
  - generator: `scripts/generate-windows-logo-assets.py`
  - generated source PNG: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Icons/leikwan-host-app.png`
  - generated `.ico`: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Icons/leikwan-host.ico`
- Wired the icon into the desktop app shell:
  - `LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj`
    - now sets `ApplicationIcon`
    - now includes the icon resources
    - now stamps desktop app version metadata:
      - `Version = 0.1.6`
      - `AssemblyVersion = 0.1.6.0`
      - `FileVersion = 0.1.6.0`
  - `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
    - now sets the window icon to the packaged LeiKwan icon
  - `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
    - tray icon no longer uses `SystemIcons.Application`
    - tray icon now loads the packaged icon path or falls back to the app executable icon

### Rust runtime refresh
- Added a regression test asserting the refreshed bond CLI version:
  - `wstunnel-main/leikwan-bond/src/main.rs`
  - new test: `cli_reports_refreshed_version`
- Bumped the Rust bond crate version:
  - `wstunnel-main/leikwan-bond/Cargo.toml`
  - `version = 0.1.13`
- Added Windows resource embedding to the Rust Windows runtimes:
  - `wstunnel-main/leikwan-bond/build.rs`
  - `wstunnel-main/leikwan-ingress/build.rs`
  - both now embed the same generated LeiKwan icon and set Windows file metadata
- Added `winres` build dependencies to both crates:
  - `wstunnel-main/leikwan-bond/Cargo.toml`
  - `wstunnel-main/leikwan-ingress/Cargo.toml`
- Fresh runtime verification after the rebuild:
  - `wstunnel-main/target/release/leikwan-bond.exe --version`
    - `leikwan-bond 0.1.13`
  - Windows file metadata on rebuilt bond binary:
    - `FileVersion = 0.1.13`
    - `ProductVersion = 0.1.13`
    - `FileDescription = LeiKwan multi-leg bond runtime`
    - `ProductName = LeiKwan Bond`

### Packaging / installer work
- Improved the Windows installer helper to stop all running LeiKwan-installed processes before file replacement, not only `leikwan-client-win.exe`:
  - `scripts/build-windows-client-setup.ps1`
  - install script now enumerates running processes rooted under `%LOCALAPPDATA%\\LeiKwan Bridge` and stops them before copy
- Rebuilt the Windows runtime artifacts:
  - `artifacts/wstunnel/leikwan-bond_windows_amd64.exe`
  - `artifacts/wstunnel/leikwan-ingress_windows_amd64.exe`
- Rebuilt the desktop publish output:
  - `artifacts/windows/publish/LeikwanBridge.App.exe`
- Rebuilt the setup executable:
  - `artifacts/windows/leikwan-bridge-setup.exe`
- Updated artifact metadata in:
  - `artifacts/versions.json`
  - `windows_client_app.version = 0.1.6`
  - `leikwan_bond_windows_amd64.version = 0.1.13`

### Local install replacement
- First install attempt failed because the old installer logic did not stop runtime child processes and `leikwan-client-win.exe` stayed locked.
- Fixed the installer helper, rebuilt setup, and re-ran the local install successfully.
- New local backup created:
  - `%LOCALAPPDATA%\\LeiKwan Bridge\\backup-20260420-031606`
- Current local install state after replacement:
  - `%LOCALAPPDATA%\\LeiKwan Bridge\\leikwan-client-win.exe`
    - `FileVersion = 0.1.6.0`
    - `ProductVersion = 0.1.6+1f3095129eaa123a72bcf4adddee640ef5b67e8f`
  - `%LOCALAPPDATA%\\LeiKwan Bridge\\leikwan-bond.exe`
    - `leikwan-bond 0.1.13`
    - `FileVersion = 0.1.13`
  - `%LOCALAPPDATA%\\LeiKwan Bridge\\bin\\leikwan-bond.exe`
  - `%LOCALAPPDATA%\\LeiKwan Bridge\\bin\\leikwan-ingress.exe`
- Installed bond SHA256 now matches packaged Windows bond artifact exactly:
  - `39AA1917EA4B038AD863BFAA00E31EC02891EB3A081943C474769903AAEBBAF2`
- Final setup SHA256 after the installer-helper fix:
  - `40414A702DAADAE93A80006EC3BEDDE5CBFE9CF0CC50DC6E40945B0D535BF3E4`
- Local smoke verification:
  - launched `%LOCALAPPDATA%\\LeiKwan Bridge\\leikwan-client-win.exe`
  - process stayed alive for the 5-second verification window before manual stop

### Panel republish
- Read panel access details from local project credentials and used WSL-first SSH for the publish step.
- Backed up the currently published Windows artifacts on panel host `10.0.10.196` into:
  - `/root/codex-backups/20260420-windows-client-publish`
- Uploaded refreshed files to:
  - `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/versions.json`
  - `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/windows/leikwan-bridge-setup.exe`
  - `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/wstunnel/leikwan-bond_windows_amd64.exe`
  - `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/wstunnel/leikwan-ingress_windows_amd64.exe`
- Verified remote published hashes:
  - `versions.json` SHA256 `b2bb2be4fdb446edf3bc296129f621fb0fd81005deb1e0bd3533be73b5ca7caa`
  - setup SHA256 `40414a702daadae93a80006ec3bedde5cbfe9cf0cc50dc6e40945b0d535bf3e4`
  - bond SHA256 `39aa1917ea4b038ad863bfaa00e31ec02891eb3a081943c474769903aaebbaf2`
  - ingress SHA256 `3432636a3e4b8bb5b03601ec01ee605381b8c6bec531223cc037ba513f5eb0b0`
- Verified published panel versions:
  - `windows_client_app.version = 0.1.6`
  - `leikwan_bond_windows_amd64.version = 0.1.13`
  - `leikwan_ingress_windows_amd64.version = 0.1.0`

### Fresh verification commands run for this release
- `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\LeikwanBridge.App.Tests.csproj"`
  - `Passed: 8, Failed: 0`
- `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj"`
  - `Passed: 11, Failed: 0`
- `cargo test -p leikwan-bond -- --nocapture`
  - `29 passed, 0 failed`
- `cargo build --release -p leikwan-bond`
  - passed
- `cargo build --release -p leikwan-ingress`
  - passed
- `dotnet publish "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\LeikwanBridge.App.csproj" -c Release -r win-x64 -p:PublishSingleFile=true -p:SelfContained=true -p:IncludeNativeLibrariesForSelfExtract=true -o "artifacts\\windows\\publish"`
  - passed
- `powershell -ExecutionPolicy Bypass -File '.\\scripts\\build-windows-client-setup.ps1'`
  - passed, final setup SHA256 `40414A702DAADAE93A80006EC3BEDDE5CBFE9CF0CC50DC6E40945B0D535BF3E4`

### Current maintenance note
- The app, tray, bond runtime, and ingress runtime now share a consistent LeiKwan-branded Windows icon surface with a white background.
- The IExpress wrapper format itself was intentionally left unchanged in this pass.
