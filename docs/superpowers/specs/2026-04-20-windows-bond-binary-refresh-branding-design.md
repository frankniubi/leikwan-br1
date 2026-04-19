# Windows Bond Binary Refresh And White-Background Branding Design - 2026-04-20

## Overview

Refresh the Windows `leikwan-bond.exe` to match the current Linux-side Rust bond runtime in this repo, then rebuild the Windows desktop package so the local install and generated setup ship the updated bond logic.

At the same time, add a white-background icon asset derived from the provided LeiKwan Host logo and use it for the Windows desktop executable, tray icon, and packaged release assets.

The goal is operational parity plus cleaner packaging, not a Windows-specific redesign of bond behavior.

## Selected Approach

**Selected:** Rebuild the current Rust `leikwan-bond` and `leikwan-ingress` Windows binaries from the latest source in `wstunnel-main`, add a repo-owned `.ico` asset with a white background, wire that icon into the WPF app and tray, then rebuild publish output, refresh the local installed app under `%LOCALAPPDATA%\LeiKwan Bridge`, and generate a new `artifacts/windows/leikwan-bridge-setup.exe`.

This keeps Windows aligned with the Linux bond runtime that now lives in `wstunnel-main/leikwan-bond/src/session.rs`, while preserving the current installer format and local install layout.

## Goals

- Replace the old installed Windows `leikwan-bond.exe` with a build from the latest local Rust source.
- Keep the packaged Windows runtime set internally consistent by rebuilding `leikwan-ingress.exe` alongside `leikwan-bond.exe`.
- Preserve the current Windows desktop topology and installer workflow.
- Add a real white-background icon asset based on the supplied logo.
- Use that icon in the Windows app executable and tray icon instead of the generic system icon.
- Rebuild `artifacts/windows/publish` and `artifacts/windows/leikwan-bridge-setup.exe`.
- Safely refresh the local install at `%LOCALAPPDATA%\LeiKwan Bridge` with backup retention.

## Non-Goals

- Do not redesign bond protocol behavior beyond what already exists in the current Rust source tree.
- Do not change the current setup format away from IExpress in this pass.
- Do not change panel-side Linux artifact publishing as part of this task unless needed only for local Windows packaging metadata.
- Do not refactor unrelated Windows UI flows.

## Current State

The currently installed local Windows bond binary is still the older build previously packaged on 2026-04-18:

- `%LOCALAPPDATA%\LeiKwan Bridge\leikwan-bond.exe`
- local release copy `wstunnel-main/target/release/leikwan-bond.exe`
- both report `leikwan-bond 0.1.0`
- both currently hash to the same file

Meanwhile the current local Rust source has moved forward materially:

- `wstunnel-main/leikwan-bond/src/main.rs` now uses `frame`, `session`, `transport`, and extended scheduler flags
- `wstunnel-main/leikwan-bond/src/session.rs` contains the newer persistent transport, striping, health, and cooldown logic
- `wstunnel-main/leikwan-bond/src/token.rs` now contains richer session claims and protocol handling

So the Windows runtime package is behind the Linux-reference source, even though the source is already present locally.

## Architecture

### Runtime Source Of Truth

Windows bond runtime should be rebuilt directly from:

- `wstunnel-main/leikwan-bond`
- `wstunnel-main/leikwan-ingress`

The latest checked-in Rust source is treated as the authoritative logic source for this update.

### Branding Source Of Truth

Create a repo-owned icon asset generated from the provided LeiKwan Host logo image with these rules:

- white background, no transparency requirement
- centered/cropped for Windows icon readability
- exported as `.ico` with standard sizes for Windows shell usage

The app should load this repo-owned icon asset rather than depend on `SystemIcons.Application`.

### Packaging Flow

Keep the current packaging flow:

1. build Rust Windows runtimes
2. publish the WPF app
3. stage files in `artifacts/windows/_setup_stage`
4. build `artifacts/windows/leikwan-bridge-setup.exe` via `scripts/build-windows-client-setup.ps1`
5. install locally into `%LOCALAPPDATA%\LeiKwan Bridge`

## Planned Changes

### 1. Refresh Rust Windows bond binaries

Rebuild:

- `wstunnel-main/target/release/leikwan-bond.exe`
- `wstunnel-main/target/release/leikwan-ingress.exe`

Then refresh the packaged copies under:

- `artifacts/wstunnel/leikwan-bond_windows_amd64.exe`
- `artifacts/wstunnel/leikwan-ingress_windows_amd64.exe`

### 2. Add white-background icon assets

Add a Windows icon asset directory under the WPF app, for example:

- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Icons/`

Asset outputs should include at least:

- source PNG with white background for reproducibility
- final `.ico` used by the app project

### 3. Wire icon into app build

Update the WPF app project so the generated executable has a real icon.

Expected touch points:

- `LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`

This includes:

- setting the project `ApplicationIcon`
- loading the same icon into the tray icon path instead of `Drawing.SystemIcons.Application`

### 4. Rebuild publish and installer artifacts

Re-run the existing Windows packaging flow so the updated icon and binaries are present in:

- `artifacts/windows/publish`
- `artifacts/windows/leikwan-bridge-setup.exe`

### 5. Refresh local install safely

Update `%LOCALAPPDATA%\LeiKwan Bridge` using the generated installer or equivalent staged install logic, while preserving the existing backup pattern already used by `scripts/build-windows-client-setup.ps1`.

## Verification Plan

- `cargo build --release -p leikwan-bond`
- `cargo build --release -p leikwan-ingress`
- `dotnet test "LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj"`
- `dotnet publish "LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj" -c Release -r win-x64 -p:PublishSingleFile=true -p:SelfContained=true -p:IncludeNativeLibrariesForSelfExtract=true -o artifacts/windows/publish`
- `powershell -ExecutionPolicy Bypass -File .\scripts\build-windows-client-setup.ps1`
- verify local installed hashes and version output for `%LOCALAPPDATA%\LeiKwan Bridge\leikwan-bond.exe`
- verify the tray icon path can load the packaged icon without crashing

## Risks And Mitigations

### Risk: icon looks bad at small sizes

Mitigation:
- generate a white-background icon with multiple standard sizes
- verify the tray path and executable icon visually after publish

### Risk: setup rebuild succeeds but local install still points at stale binaries

Mitigation:
- verify hashes in both `artifacts/windows/publish` payload inputs and `%LOCALAPPDATA%\LeiKwan Bridge`
- verify `leikwan-bond.exe --version` from the installed location after replacement

### Risk: WPF app icon updates but tray still falls back to generic icon

Mitigation:
- explicitly load the `.ico` asset from disk or app base directory in `MainWindow.xaml.cs`
- keep a safe fallback only if custom icon loading fails

## Files Likely In Scope

- `wstunnel-main/leikwan-bond/**`
- `wstunnel-main/leikwan-ingress/**`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Icons/*`
- `scripts/build-windows-client-setup.ps1`
- `artifacts/versions.json`

## Success Criteria

Success means all of the following are true:

- installed Windows `leikwan-bond.exe` is rebuilt from the current local Rust source
- new Windows installer is generated successfully
- local installed app and packaged publish output use the new icon asset with white background
- tray icon uses the new branding instead of the generic system icon
- local install is refreshed with backup retained
