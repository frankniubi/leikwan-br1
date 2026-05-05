# Windows Manifest Updater Design - 2026-05-06

## Overview

Add a Windows desktop updater that uses the existing panel-hosted artifact manifest in `artifacts/versions.json` as the source of truth.

The Windows app should:

- check for updates automatically on startup
- detect when `windows_client_app.version` is newer than the local app version
- ask the user whether to:
  - install now
  - wait until next restart
- download and cache the installer locally when the user chooses to defer
- apply the cached installer on the next app startup before normal session startup continues

This updater should work with the current release process where the installer EXE and `versions.json` are published to the panel.

## Selected Approach

**Selected:** manifest-driven updater based on panel-hosted `artifacts/versions.json`.

The updater does not require a new dedicated update API. It reuses the already-published Windows installer artifact plus the existing version metadata contract.

This keeps the release flow simple:

1. build new Windows EXE
2. rebuild installer
3. update `artifacts/versions.json`
4. publish both to panel
5. Windows app discovers the update automatically

## Goals

- Automatically check for Windows app updates on startup.
- Compare local app version against panel-published `windows_client_app.version`.
- Ask the user before installing.
- Support two user choices:
  - `Install now`
  - `Wait until next restart`
- Download and cache deferred installers locally.
- Apply deferred installers on next startup.
- Keep panel publish as the single release source of truth.

## Non-Goals

- Do not add a separate backend update-check endpoint in this pass.
- Do not silently install updates without user approval.
- Do not add “skip this version” or staged rollout logic in this pass.
- Do not redesign the existing installer format away from the current Windows EXE packaging flow.

## Current State

The current Windows artifact release flow already publishes:

- `artifacts/windows/leikwan-bridge-setup.exe`
- `artifacts/versions.json`

The Windows client does not currently:

- check `versions.json`
- compare local version with remote version
- cache pending installers
- apply deferred updates on restart

The installer publish path is already tied to the panel artifact route:

- `/artifacts/leikwan-bridge-setup.exe`

## Update Discovery Flow

### Startup

On startup, after the app process begins:

1. check whether a deferred cached update is already pending
2. if no pending update exists, continue normal startup
3. perform the automatic manifest check

The update check should fetch the panel-hosted `artifacts/versions.json` and read:

- `windows_client_app.version`
- `windows_client_app.artifact_url`

The local app version should come from the packaged Windows app version metadata, not from a hardcoded constant elsewhere.

### No update available

If the panel version is less than or equal to the local app version:

- no prompt
- no cached update state change

### Update available

If the panel version is newer:

- show a prompt
- offer:
  - `Install now`
  - `Wait until next restart`

## User Choice Behavior

### Install now

If the user chooses `Install now`:

1. download installer if not already cached
2. verify cached file integrity
3. stop runtime and background services cleanly
4. launch installer
5. exit app

### Wait until next restart

If the user chooses `Wait until next restart`:

1. download installer immediately
2. verify cached file integrity
3. persist pending-update state locally
4. continue normal app use
5. on next startup, apply the cached installer before entering the normal app session

This means the deferral is fast later because the installer has already been downloaded.

## Local Cache Design

### Cache location

Store Windows update files under:

- `%LOCALAPPDATA%\LeiKwan Bridge\updates\`

Suggested contents:

- `updates\leikwan-bridge-setup-<version>.exe`
- `updates\update-state.json`

### Persisted state

Store at least:

- `pending_update_version`
- `pending_update_installer_path`
- `pending_update_sha256`
- `pending_update_downloaded_at`
- `last_update_check_at`

Optional later:

- `ignored_update_version`

### Cache rules

- if cached file already exists for the same version and hash matches, reuse it
- if cached file is missing or hash mismatches, re-download it
- keep one pending installer by default
- remove older cached installers after a newer one is successfully cached

## Integrity Rules

At minimum, the updater must verify:

- downloaded installer exists
- downloaded installer hash matches the expected hash

The expected hash should come from the artifact metadata path derived from `versions.json` and the published installer file.

If integrity fails:

- do not launch installer
- clear or invalidate the pending update state
- show an error and continue normal app flow

## Restart / Apply Lifecycle

### Normal startup

If there is no pending cached update:

1. app starts
2. normal login/client/runtime startup continues
3. update check runs automatically

### Pending update startup

If a pending update exists:

1. app starts
2. updater verifies cached installer path and hash
3. if valid, updater launches installer
4. app exits before normal runtime startup proceeds

The updater should not silently discard a pending update just because a later launch fails.

### Runtime shutdown before update

Before launching installer:

- stop forward runtime
- stop polling and heartbeat
- flush state and logs
- exit cleanly

This avoids file locks and partial runtime state issues.

## Manual Check

Even though update check is automatic on startup, the app should also expose:

- `Check for Updates`

This is a manual fallback for users who want to re-check after a new panel release.

## Panel Publish Contract

The updater depends on panel publish discipline.

Every Windows release must publish:

- `artifacts/windows/leikwan-bridge-setup.exe`
- `artifacts/versions.json`

Release is not complete until:

1. local EXE is rebuilt
2. `versions.json` is updated
3. both are uploaded to the panel
4. remote hashes are verified

This contract must remain part of the Windows release workflow.

## Files Likely In Scope

### Windows app

- `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/StateManager.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentConfig.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MasterApiClient.cs`
- new updater-focused service/model files under `LeikwanBridge.Desktop/src/LeikwanBridge.Core/`

### Release metadata

- `artifacts/versions.json`

### Tests

- `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/**`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/**`

## Test Plan

### App logic tests

- no update when remote version is equal or older
- prompt when remote version is newer
- install-now path launches installer
- wait-until-next-restart path caches installer and persists pending state
- pending cached update is applied on next startup
- invalid cached installer hash clears pending state
- failed download does not create false pending-update state

### Local integration checks

- installer downloads into `%LOCALAPPDATA%\LeiKwan Bridge\updates\`
- pending update survives restart
- next startup applies deferred installer
- app does not continue normal runtime startup once pending update is being applied

### Release verification

For each shipped Windows release:

- full .NET test suite passes
- installer rebuild succeeds
- local Defender scan passes
- remote panel hashes match local hashes
- manual smoke:
  - update detected
  - `Wait until next restart` downloads and defers
  - next startup launches installer
  - `Install now` launches installer immediately

## Success Criteria

Success means:

- the Windows app auto-checks for updates on startup
- users are asked before install
- users can defer to next restart
- deferred updates download immediately and apply later without re-download
- the updater trusts panel-published `versions.json` as the source of truth
- panel publish remains the release contract for Windows updates

## Recommendation

Implement the updater as a narrow manifest-driven feature on top of the current Windows shell.

This is the simplest path that turns your current manual Windows publish flow into a repeatable user-facing update system without adding unnecessary backend surface area.
