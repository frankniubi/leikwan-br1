# Windows UX / Tray / Control Surface Design - 2026-04-18

## Overview

Fix the first real usability failures in the Windows client after the Linux-parity backend release:

1. The post-login blank main window
2. The hidden-on-close behavior with no tray affordance
3. The overly raw control surface that makes the app feel unfinished
4. The visible restart thrash after critical bond-leg failure

This is a focused Windows app UX and shell pass, not a new transport design. The transport/backend work from the Linux-parity release remains the foundation.

## Root Cause Summary

### Blank Window

The blank page is caused by invalid visibility wiring in:

- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`

The setup overlay is bound directly to `ShowSetupWizard`. The main layout tries to invert that same value using WPF's built-in `BooleanToVisibilityConverter` plus `ConverterParameter=inverse`.

That does not work. The built-in converter ignores that parameter. So once setup completes, both layers can become hidden and the window turns into a white sheet.

### "Already Running" After Clicking X

The close behavior is caused by:

- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`

When `MinimizeToTray` is enabled, `OnClosing()` cancels close and calls `Hide()`.

That would be acceptable if there were a real tray icon and tray menu. There is not. So the app disappears, keeps the single-instance mutex, and the next launch correctly says "already running" even though the user has no way to restore it.

### Runtime Thrash

The current log shows a second-order problem:

- after a critical ingress-leg exit, the app rebuilds the whole forward immediately
- old leg and bond sockets are still releasing
- the next start attempt can hit `os error 10048`

This is not the blank-page root cause, but it directly hurts the user experience and should be smoothed in the same release.

## Goals

- Make the app show a usable main window immediately after setup succeeds.
- Add a real system tray entry point with restore and exit actions.
- Make closing the window predictable:
  - hide to tray when tray mode is enabled and tray support is active
  - fully exit when tray mode is disabled or tray setup fails
- Replace the current sparse shell with a clearer control page for day-to-day use.
- Add an automatic `MPTCP` filter state when forward rules are retrieved, so MPTCP-tagged rules are immediately easy to inspect and control.
- Reduce visible restart flapping when a critical bond subprocess exits.

## Non-Goals

- Do not redesign the backend transport contract again.
- Do not add native Windows MPTCP.
- Do not introduce a second app shell or embedded web UI.
- Do not remove the existing view model structure unless needed to support the fixes.

## Selected Approach

**Selected:** Fix the shell first, then improve the control page within the existing WPF structure.

This means:

- keep the current WPF app
- keep `MainViewModel` as the central state owner
- repair the visibility logic
- add tray support in the application/window layer
- reshape the dashboard into a more intentional operations console instead of bolting on a brand new navigation system

## Design

### 1. Fix The Setup-To-Main Visibility Transition

Replace the fake inverse-visibility binding with a real inverse boolean visibility converter or equivalent explicit view-state binding.

Expected behavior:

- `ShowSetupWizard = true`
  - setup overlay visible
  - main app shell hidden
- `ShowSetupWizard = false`
  - setup overlay hidden
  - main app shell visible

This must not depend on unsupported converter parameters.

### 2. Add Real Tray Support

Add a real tray icon and menu in the app shell layer.

Expected behavior:

- tray icon appears once the main window is initialized
- tray menu includes:
  - `Show Window`
  - `Exit`
- double-clicking the tray icon restores and focuses the main window
- clicking `X`:
  - hides to tray only when tray mode is enabled and tray icon setup succeeded
  - otherwise exits cleanly

If tray initialization fails:

- log the failure
- disable hide-to-tray behavior for that run
- prefer a normal exit over trapping the user in a hidden process

### 3. Improve The Main Control Surface

Keep the existing data sources, but present them as a cleaner operational control page.

The new main surface should emphasize:

- connection state
- recent sync state
- active forward count
- transport health summary
- quick actions
- forward list with obvious status and controls
- mode filter controls, including `All`, `MPTCP`, `Bond`, `Race`, and `Single`
- log access without making logs the primary surface

Visual direction:

- keep the existing WPF stack and theme resources
- remove the "blank business form" feel
- make the dashboard feel like a real controller, not a developer scaffold
- no dramatic rebrand or entirely new IA

Practical layout target:

- top status band
- quick action row
- forward filter row
- primary forward table/card section
- secondary diagnostics/log panel
- settings remain accessible, but not the first thing the user falls into

### 3.1 Add MPTCP Auto Filter On Forward Retrieval

When the app retrieves forward rules from panel, it should automatically derive the available mode filters from the raw forward list.

Expected behavior:

- if any retrieved forward has canonical mode `mptcp`
  - show an `MPTCP` quick filter in the control surface
  - auto-select that `MPTCP` filter after the retrieval completes
- if no `mptcp` forwards are present
  - fall back to the default `All` filter

Important boundary:

- this is a control-surface filter, not a sync-layer delete
- the app must still pull and retain the full forward set
- non-`mptcp` forwards remain available when the user switches back to `All`, `Bond`, `Race`, or `Single`

This keeps the UX aligned with the request for automatic `MPTCP` filtering without silently suppressing other rules from the local model or runtime pipeline.

### 4. Smooth Restart Recovery

Keep the whole-forward rebuild behavior, but add a slightly safer recovery cadence after critical child exit.

The first pass should:

- ensure the previous forward is fully stopped before rebuild begins
- avoid immediate rebinding while sockets are still releasing
- keep the cooldown and whole-forward semantics already introduced

This is a small stability pass, not a new scheduler.

## Files In Scope

### Modify

- `LeikwanBridge.Desktop/src/LeikwanBridge.App/App.xaml.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/ForwardViewModel.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`

### Likely Add

- one or more WPF converters or shell helpers for inverse visibility / tray support
- UI-focused test coverage where practical in Core/App-adjacent logic

## Testing Plan

### Regression

- prove the main shell becomes visible after setup completes
- prove tray-enabled close no longer strands the user without a restore path
- prove tray-disabled close exits normally
- prove `MPTCP` auto-filter becomes selected after retrieving a rule set containing MPTCP forwards
- prove the full forward set is still retained when the auto-filter is active
- prove a critical process exit still rebuilds the forward, but with less bind thrash

### Manual

Manual verification on Windows should cover:

1. Launch app with no config, complete setup, confirm dashboard appears instead of a white window
2. Click `X`, confirm tray icon appears and restore works
3. Use tray `Exit`, confirm the process actually ends
4. Retrieve a mixed rule set, confirm the view auto-switches to `MPTCP` when MPTCP forwards exist
5. Switch back to `All`, confirm non-MPTCP forwards are still present
6. Re-launch app, confirm no false "already running" trap remains after a real exit
7. Trigger or simulate a forward restart path and confirm the app no longer visibly flaps as hard on port reuse

## Summary

This release should make the Windows client feel like an actual product shell.

The app must:

- stop going blank after setup
- stop hiding without a tray affordance
- give the user a real place to control forwards
- automatically focus the operator on `MPTCP` rules when those rules are present in the latest pull
- behave more calmly when bond subprocesses fail

That is the smallest meaningful UX release after the backend parity work.
