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

## Candidate 2026.09.24.2 (commit 5462fcaf): deployment log

- **Panel:** 7 files deployed on 2026-09-24 at about 06:05 +08.
  - Backup and `rollback.sh` are in `/root/codex-backups/panel-offline-validity-20260924/`.
  - `/api/health` returned 200 afterwards; the access log shows no new 5xx.
  - `.env`: `ULTRA_MATERIAL_REFRESH_WINDOW_SECONDS=1200` was set at 06:10 and **reverted at 08:05**. Re-acquiring earlier makes grants look longer. But re-acquisition is what kills an offline client's credential, and at 1200 lines were re-acquired every ~11 min instead of ~21. Both `.env` versions are in the backup directory.
  - The material lifetime on the source agent (`10.10.10.171`) is unchanged at 1800 s. LeiGod's real token lifetime is unconfirmed, so it should not be raised to 3600 s until a token-age test proves the relay still accepts it.
- **Linux test host:** candidate agent `0a72759c…` and runtime `1c1e88c8…`.
  - Backup and `rollback.sh` are in `/root/cand-backup-20260924-2/`.
  - An updater hold is active for 23 h. The agent ignores holds longer than 24 h: the first attempt used 72 h, the hold was ignored, and 2026.09.24.1 was reinstalled.
  - After the move, all 5 forwards were on the new runtime with grants of 1300–1600 s.
- **Windows test host:** headless 0.1.20.54 (zip `c4832ec6…`), installed over the live 0.1.20.53 service with the new installer.
  - 31 s: stop, drain, backup, swap, service update, and a health gate that reported RUNNING and connected. Before this fix, the same reinstall failed with a locked `clrjit.dll` and error 1073.
  - Forwards 158/159/170/171 are running, with 17–22 min grants after the first renew.
- **Correction: the first two Linux blackouts (10 min and a partial 30 min) are invalid as outage tests.** The hosts entries blocked only IPv4, and the agent kept renewing over IPv6 (Cloudflare AAAA records). What those runs do show is that the runtimes stayed up with 0 restarts for 26 min while the agent lost IPv4 to the panel. The retries with `100::1` entries (Linux) and a firewall block on resolved addresses (Windows) leaked too: both clients resolve the panel through their own anti-pollution DoH, and the CDN returns different addresses per resolver, so renewals kept succeeding. Address lists cannot simulate an outage. The valid method cuts the control-plane process itself off the network:
  - Linux: `iptables`/`ip6tables -m cgroup --path system.slice/leikwan-client-agent.service ! -o lo -j REJECT`.
  - Windows: an outbound firewall block for `leikwan-bridge-headless.exe` only; the runtimes are a separate program.

  The data plane (`ultra-forward-*` units, `ultra-client.exe`) is untouched, which is exactly the situation of a dead panel. Each test runs as a systemd unit or SYSTEM scheduled task with a timed auto-unblock. The first Windows attempt died with its SSH session, leaving its (leaky) firewall rule in place for about 32 min; it has since been removed.
- **macOS test host:** 1.0.47 (arm64 DMG `b46ee534…`) installed as a launchd daemon (KeepAlive, 65536 files). The hand-started 1.0.46 headless it replaced is backed up in `~/backups/pre-1.0.47-20260924-061646`.
  - The first install attempt exposed an installer defect: it tried to downgrade to the published 1.0.45 and aborted on the mismatched published DMG. Fixed in `c1fa8663`. The Mac forwards were down for about 2 minutes.
  - SS-none canary 3/3.
- **Fault: SIGKILL of the host process.**

  | Platform | Old runtimes exit | Host back | 10001 serving |
  |---|---|---|---|
  | Windows | 1 s (job object) | SCM restart at 6 s | 11 s |
  | macOS | 2 s (`parent_pid` watch) | launchd respawn 1-126 s | host start + 6-7 s |

  The slow macOS respawns are launchd waiting to reap the killed .NET process, which sat in state `U`. This happened in 3 of 4 runs and is not in our code.
- **Fault: kill one runtime (forward 170).** Windows 11 s, macOS 11 s, then the SS-none canary passes.

## Result: first valid 30-min panel blackout (Linux, agent cgroup cut off, 07:16-07:46 +08)

- 0 renewals reached the agent, so the block held.
- 0 runtime restarts; all 5 runtimes kept their original PIDs.
- Service resumed 18 s after the block lifted, with a fresh grant.
- The canary passed 67 times and failed 30:
  - Serving was clean for the first ~16 min.
  - Failures flickered from 07:32:39, and were continuous from 07:34:08. The policy was still `active` at that point, with 5.5 min left.
  - The policy lapsed on schedule at 07:39:30, listeners stayed bound, and connections failed closed.
- Forward 170's only active candidate is line 325 (`7fb395f1…`). The panel recorded a re-acquisition of line 325's provider material at 07:31:17, 82 s before the first failure. The next re-acquisition came at 07:52:23, a cadence of about 21 min.
- The Mac is on the same line and was online throughout. Its SS-none probe (30 s) saw no failure around either re-acquisition, so online clients receive the replacement credential in time.
- **Conclusion:** the client side now does what W1 asked for: no churn, fail-closed, and self-resume. The offline window, however, is bounded by the provider credential, not by the 30-min grant.
  - Once a line is re-acquired, or once its token reaches roughly 21-22 min of age, the credential an offline client holds stops working. The two causes cannot be told apart yet.
  - An outage starting at a random moment therefore gets between 0 and ~21 min of service.
  - Guaranteeing 30 min needs a provider-side change: either a longer real token lifetime, or two independent sessions per line, alternated, so a re-acquisition never kills the credential clients hold. Both need a controlled experiment on a line no production forward depends on.

- Online check with Linux connected (08:03:22 re-acquisition of line 325): the new policy generation reached the runtime 32 s later and the SS-none canary had no failure. Online clients are therefore unaffected by re-acquisition. Only offline survival is bounded by it.
