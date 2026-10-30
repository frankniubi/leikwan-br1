# Release clients-20261031-1 (2026-10-31) — Android background/cellular stability

Android 0.2.47 (versionCode 49). Runtime binaries unchanged from 0.2.46
(`2026.10.26.1`, arm64 `2a65c9c6`, x86_64 `0039f32b`): no attestation rollover.
Not forced: no `client_update` change, no minimum version.

## What ships

| Component | Before | After |
|---|---|---|
| Android APK | 0.2.46 (48) `8420d79b` | **0.2.47 (49)** `86e54b2f`, signed v2/v3 with the release key + lineage |

Only the APK moved; Windows/headless/macOS/Linux/iOS are untouched.

## Fixes (branch fix/android-bg-cellular-stability)

User-visible problem: the Android app still dropped the tunnel in the
background (battery pressure) and was unstable on 4G/5G.

1. **Wi-Fi <-> cellular switch auto-recovery** (`beef2437`). On a
   default-interface change while the tunnel is up: re-apply the underlying
   network set, call libbox `CommandServer.resetNetwork()` so established
   outbound sockets rebind to the new interface, wait 4 s for the new network
   to validate, then probe the tunnel with one UDP DNS query to the TUN
   resolver. A failed probe rebuilds the VPN in place through the orchestrator
   (`reconnectSaved()`; runtimes + TUN only, service stays foreground).
   Skipped while a post-kill recovery runs; pending check cancelled on
   stop/destroy.
2. **Multi-network underlying set** (`ca2f4526`). `setUnderlyingNetworks`
   now receives every physical internet network (preferred first) instead of
   one pinned network, so the kernel fails over during a handover instead of
   blackholing until the next `onLost` re-pin. `UnderlyingNetworkSelector
   .selectAll()` added; the old `select()` remains for the pinned-first pick.
3. **Cellular callback burst debounce** (`ca2f4526`). Every network callback
   republished the default interface to libbox; on cellular they arrive in
   repeated bursts (signal changes, revalidation). Collapsed into one refresh
   after 500 ms.
4. **Longer post-kill recovery** (`ca2f4526`). `VpnRecovery` went from 4
   attempts (~50 s) to 6 attempts (~3 min 50 s, +60 s/+120 s waits), so a
   kill that lands in Doze or an outage rebuilds once connectivity returns
   instead of giving up.

## Verification

- Unit tests: 501 total, 499 pass. The single failure
  (`AndroidNetworkMonitorTest` `networkCallbacks.isEmpty()` assertion) also
  fails on unmodified master on this machine — a pre-existing local
  Robolectric environment issue, not a regression. New tests: burst collapse,
  default-interface change listeners, multi-network selection (5 added).
- CI on sanitized snapshot `43418d9e` (Android + Windows + Linux + macOS).
- Emulator (API 34): final signed APK installs fresh (0.2.47) and launches
  with no crashes; the QA build likewise. The emulator has no usable
  internet from this network, so live-panel functional checks move to real
  devices.
- Device test: install from the panel, connect, toggle Wi-Fi <-> airplane
  mode/cellular, background the app for 10+ minutes, verify the tunnel
  returns on its own (`Default interface changed` / `rebuilding it` lines in
  logcat).

## Rollback

Republish the previous APK (0.2.46, `8420d79b`, backup on the panel at
`/root/codex-backups/clients-20261026-5`) and restore `versions.json`.
