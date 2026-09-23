# Enterprise HA / self-healing plan before the next release

Goal: no new client release until the same build, on Windows, Linux, macOS and
Android, survives the failures below by itself and passes the acceptance
matrix. 2026.09.24.1 (published 2026-09-24 01:57 +08) stays as the current
release until then.

## Defects found on 2026-09-24 (live test clients)

| # | Defect | Evidence | Platforms |
|---|---|---|---|
| D1 | A panel outage of a few minutes stops every Ultra forward. The runtime stops itself after `policy has been missing for 1m30s`; the agent then restarts units that are idle on purpose. | Linux journal 01:5x-02:26 and 03:2x-03:30 +08, all 5 forwards down twice | all |
| D2 | The Windows headless service never updates itself. It only has a manual `leikwan update`, and 0.1.20.49 does not have even that. A broken build stays broken. | Windows test host on 0.1.20.49 / runtime 2026.09.21.1 with every forward down | Windows headless (macOS headless: TBD) |
| D3 | Windows 0.1.20.49 probed the listener once, immediately after lease delivery: start, kill, restart, forever. 0.1.20.53 waits 6 s, and that is still too short on a cold start. The runtime runs a location lookup (up to 3.5 s) before it binds, and on Windows/macOS it has no usable location cache path (it defaults to `/var/lib/ultra/location.json`). | 0.1.20.53 cold start: 4/4 `listener_not_ready`, came up on the second pass 53 s later | Windows, macOS |
| D4 | `install-windows-headless.ps1` is not idempotent. It copies over a running service (file locked), then `--install-service` fails with 1073 when the service exists, and it keeps no backup. Upgrade by reinstall is impossible. | Windows test host, 2026-09-24 04:05 | Windows headless |
| D5 | The frozen Linux agent restarts about every 6 minutes. It hashes `install_forward_runtime.py` / `cfwg_latency_watchdog.py` next to its PyInstaller unpack dir (they do not exist there), so it always asks for an update. A manual panel token and an auto token overwrite each other's "completed" record. The updater restarts the agent even when nothing changed. | Linux journal 03:30-04:01, root-caused with file:line | Linux |
| D6 | The repo manifest disagrees with the published one for `cfwg_latency_watchdog`, and 8 entries (Android APK, macOS 1.0.45 x4, gost, leigod, cfwg) do not match their on-disk file. The publisher does not refuse this. | verification on both panels | release pipeline |

## Carried-over issues from the 2026-09-24 handoff

1. PEK -> FRA cold first-segment stall: the pilot is shipped (Windows/Linux runtime only) but not armed and not tested.
2. PEK -> MOS: same as 1.
3. CAN -> SIN historical Windows early close: logging is shipped, but there is no repro.
4. SHA -> TYO slow start / 10001: related to D3; needs a cold-start benchmark.
5. Linux 2026-09-21 failures: need a per-direction, per-stage re-run.
6. Same-build, all-direction x all-platform acceptance.

## Workstreams

- **W1 Control-plane outage survival (D1).** Bounded last-known-good operation while the panel is unreachable, within the lease validity the relays enforce. Renewal through mirror URLs. Health checks must not restart a runtime that is idle on purpose. Design follows the lease/policy TTL investigation.
- **W2 Update convergence (D2, D4, D5).**
  - Windows/macOS headless: periodic check, staged download with sha verification, detached apply, health-gated rollback, and an idempotent installer.
  - Linux: frozen helper paths, a per-token update ledger with backoff, and no restart when nothing changed.
- **W3 Runtime start readiness (D3).**
  - The runtime binds its listeners before the location lookup, and the lookup gets a per-OS cache path.
  - Hosts wait on a runtime readiness signal with process-exit awareness, instead of a fixed 6 s.
- **W4 Release integrity (D6).**
  - Correct the manifest entries.
  - The publisher refuses to publish while any entry's sha differs from its file.
  - The repo manifest is pinned to what is served.
- **W5 The six carried-over issues:**
  - arm the FRA/MOS pilot per candidate and A/B it on real lines;
  - reproduce CAN -> SIN with stage logging;
  - run the TYO cold-start benchmark;
  - re-run the Linux 9/21 failures per stage.
- **W6 Fault-injection suite, on each platform, same build:**
  - kill the runtime
  - kill or block the UoT sidecar
  - blackhole the active relay candidate
  - make the panel unreachable for 10 and 30 min
  - long run across several lease renewals (>= 30 min)
  - reboot the host
  - apply an update while traffic runs

  Pass = forwards recover without a human, within stated bounds, with no restart storms.
- **W7 Build and release.** One build ID for Windows (GUI + headless), Linux (agent + runtime), macOS (app + runtime, built on the remote Mac) and Android (runtime + APK). Canary test clients first, then publish.

## Acceptance gate for the release

- W6 passes on Windows, Linux and macOS (Android: runtime-level subset).
- 11 directions x 3 OS, 180 s / 10 Mbps, TCP + External-UoT UDP, serial per direction, same build, recorded per stage.
- Update from the currently published build to the candidate converges on every platform without a manual step, and rollback works.

## W1 design (decided 2026-09-24: 30 min offline validity)

Contract, backward compatible in both directions:

- A client that can hold a long grant sends the header
  `X-Leikwan-Ultra-Offline-Validity: <seconds>` on the config pull and on the
  Ultra lease resolve / renew requests. The value is the longest validity the
  client accepts: 1800 for this release.
- Panel:
  - Effective validity = min(header, `ULTRA_OFFLINE_VALIDITY_SECONDS` (default
    1800, clamped 300..3600), material expiry, service expiry).
  - `renew_after` = issue + `ULTRA_RENEW_INTERVAL_SECONDS` (default 240), so
    online clients renew exactly as often as today, and revocation and
    suspension still land on the next pull.
  - Lease `expires_at` = min(policy expiry, now + effective validity).
  - Each long grant carries a quota slice instead of the full remaining quota,
    so an offline device can overspend by at most one slice.
  - Without the header, grants stay exactly as today (<= 300 s).
- Runtime (Go), Desktop (C#) and Android accept a policy/lease validity of up to
  3600 s.
- The runtime never closes its listeners because a policy lapsed. It fails each
  connection closed, reports `policy_lapsed` in its status file, and tears
  down only on revocation.
- Agents and desktop hosts do not restart a runtime whose status says
  `awaiting_policy` or `policy_lapsed`.
- Control-plane retries back off exponentially with jitter (5 s -> 60 s), so a
  fleet does not stampede the panel when it comes back.
- Rollout: ship clients first. The panel change is inert until clients send the
  header.

Other items from the macOS run, added to W2/W3/W6:

- macOS headless is not supervised by launchd (no KeepAlive, auto_start=false).
- `MacProcessSupervisor` calls `setpgid` after exec, which fails with EACCES
  every time, so orphaned runtimes survive a host crash.
- An HA TCP stream that gets no response after the first upstream write is not
  retried on another candidate (about 25 cases in 12 h).
- A Windows host that did not pass `location_cache_path` made every runtime
  start wait about 3.5 s before binding. That, plus a single or 6 s listener
  probe, is the root cause of D3.

## W5 prep: PEK -> FRA / MOS TCP candidates (panel DB, 2026-09-24, read-only)

All candidates are on `source-home-171` and all are `healthy`. The pilot allowlist holds at most 4 hashes per direction, so pick them from per-candidate cold-send A/B results on the new build rather than guessing.

- FRA (tcp):
  - line 9 `d3250bd7…`
  - line 380 `3f870798…`
  - line 455 `feb4fba3…`
  - line 493 `a34b32ac…`
  - line 598 `8287a81d…`
  - line 629 `2703da6d…`
  - line 693 `d414cb74…`
- MOS (tcp):
  - line 10 `4dfd715b…`
  - line 344 `14d4c18c…`
  - line 372 `a47cf22b…`
  - line 456 `2da1c442…`
  - line 599 `965c56c8…` (the 09-23 live-test candidate)
  - line 600 `50b7695b…`
  - line 646 `735fba4a…`
  - line 692 `a17b81d6…`

The stall reproduced through both the FRA relay and the MOS relay against the same target (`157.228.138.23:62102`). If the A/B shows that every candidate toward that target stalls, the allowlist cap of 4 is the wrong shape. The pilot should then be keyed on the target alone, which is a small panel/runtime change.

## W7 prep: macOS build on the remote Mac

The remote Mac (`leikwan@100.103.253.21`) has Go 1.26.8 at `~/.go126/bin` and the .NET SDK 8.0.425 at `~/.sdk` (set `DOTNET_ROOT=~/.sdk`). The 09-19 procedure is `tmp/release-20260919/mac-build.py`:

1. Transfer a `git ls-files` tarball of `LeikwanBridge.Mac`, `LeikwanBridge.Core`, `scripts` and `tools/ultra-client`, and extract it into a fresh `~/mac-build-<version>`.
2. For each of `osx-arm64` and `osx-x64`:
   - reuse the helpers from the previous update zip;
   - build `ultra-client` with the release `-X main.runtimeBuildVersion`;
   - run `dotnet publish` self-contained.
3. Sign (ad-hoc, rcodesign) and repackage on Windows with `scripts/build-macos-client-bundle.py`. Build the DMG with `package-macos-client.sh` (hdiutil) on the Mac.

Disk space: 2.7 GB free (93% used). A build needs about 1.4 GB. The old `~/mac-build-1.0.27`, `1.0.43` and `1.0.45` directories take about 1.5 GB and can be removed if space runs out; they are old build outputs, and the installed app does not depend on them.
