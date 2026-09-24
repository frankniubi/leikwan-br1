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

## Result: enforced Windows blackout (07:49-08:19 +08) and the root cause of the credential gap

- Windows, blocked with the program-scoped firewall rule and the Public profile enabled:
  - 0 policy-generation changes, so the block held.
  - Original runtime PIDs throughout.
  - TCP failed from 08:02:59 with the policy still `active` (542 s left). UDP over the existing UoT carrier held until about 08:05.
  - The policy lapsed at 08:12. Service resumed 55 s after unblock; the control-plane backoff was at its 60 s cap.
- Line 325 was re-acquired at 07:52:23 and again at 08:03:22 (the panel upload time; the source-side acquisition is ~20-30 s earlier). The Windows failure began when the credential was 11 minutes old. **Re-acquiring a line invalidates the credential previously issued for it.** Token age is not the cause.
- Online impact: Linux was online at the 08:23:31 re-acquisition. One canary failed at 08:23:52, and the new generation reached the runtime at about 08:24:00. **Every re-acquisition leaves online clients a ~30-60 s window** in which new TCP connections on that line fail. This happens about every 20 min per line. It is present in production today, is independent of this candidate, and plausibly explains the periodic `tcp_http_response_invalid` clusters and the macOS probe's 88.9 % availability.
- A fix lives on the material source side, not in the clients:
  1. Minimum: acquire, upload and wake clients per line in one step, to shrink the gap to seconds.
  2. Proper: keep two independent provider sessions per line and hand clients both as HA candidates (the runtime already accepts up to 4). Re-acquisitions alternate, so a live credential always exists. This removes the online gap and gives offline survival of up to two re-acquisition intervals.
- Whether option 2 needs a second LeiGod account depends on whether one account can hold two concurrent credentials for the same line.

## Fix deployed: publish each re-acquired line credential immediately (commit 91a9d7c8)

- **Decision (2026-09-24):** shrink the gap first; defer dual sessions.
- **Panel:** `UltraMaterialService.php` and `UltraSourceController.php` deployed at about 11:12 +08.
  - Backup and `rollback.sh` are in `/root/codex-backups/panel-per-line-upload-20260924/`.
  - A ticket now admits several uploads, each line at most once. The jobs ticket also covers review lines.
- **Source agent** 2026.09.24.1 on `10.10.10.171` (`/usr/local/bin/ultra-source-agent.py`, sha `cbc04361…`).
  - The previous copy matched git `86c87831` exactly.
  - Backup and `rollback.sh` are in `/root/codex-backups/source-agent-per-line-20260924/`.
  - Each line is uploaded right after it is acquired. A review uploads its fresh material before the probe runs; previously review material was never uploaded, which left clients on a dead credential for up to ~20 min.

## Final candidate artifacts (tmp/candidate-20260924-3, source 5e96069c + runtime 2026.09.24.2.5462fcaf)

| Artifact | Version | SHA-256 |
|---|---|---|
| Windows setup (GUI) | 0.1.20.55 | `3b78e53a2e319dd52910adda6b31b1b1d2d6f6f797eceae8e2440858e0ff3b99` |
| Windows headless zip | 0.1.20.55 | `0c1d87b1002a99f2b9a928932acc7de5e9fce86fc7807d989f3fcedee5f6657c` |
| macOS arm64 / x64 DMG + update zips | 1.0.48 | built on the remote Mac (`~/mac-build-1.0.48/artifacts/macos/`) |
| Android APK | 0.2.31 (33) | `741f209cf8226b49fee8dd47409b735d9c1ff6ecb04eb7489b9cb3fdbbc54d60`; v3.1 release cert `700adc0a…`, v2/v3.0 legacy `2fde6e1e…` |
| Linux frozen agent | 2026.09.24.2 | `0a72759c…` (two byte-identical builds) |
| Ultra runtime Linux amd64 / Windows | 2026.09.24.2.5462fcaf | `1c1e88c8…` / `77d2aa44…` |

Installed on the test hosts: Linux (agent + runtime, updater hold), Windows 0.1.20.55 (upgraded over 0.1.20.54 by the new installer in 31 s), and macOS 1.0.48 (launchd daemon).

## Acceptance matrix on the final candidate (2026-09-24, 180 s, 10+10 Mbps, 4+4 streams, serial)

Raw data is in `tmp/matrix-20260924/`.

- 23 of 33 cells pass; 8 of 11 directions pass on all three platforms. On 09-21 it was 1 of 11.
  - 47, 48, 52, 53 and 54 went from fail or blocked to pass on every platform.
  - No crash, restart or listener churn in the candidate.
- Failures:
  - **49 PEK→FRA and 50 PEK→MOS, TCP, all platforms:** cold-send stall. Fixed by the pilot, see below.
  - **56 HKG→mainland, all platforms:** its only provider line is broken (probe timeouts, the relay stops reading, UoT hello fails). The same target passes from 46 and 51. This is a provider/line issue, not a client one.
  - **55 SHA→LAX, Windows UDP:** 13.9 % loss from External-UoT queue timeouts under carrier backpressure. Linux and macOS on the same carrier: 0 %. This is a runtime fix, in progress.
- Candidate defects found and being fixed:
  - A dead relay stream (write stall) is not scored, so there is no failover.
  - A stuck stream is not torn down after the local client closes.
  - The Windows UoT queue drops packets.
  - The desktop hosts logged `process_dead` for runtimes that were only waiting for a policy (fixed in `e04c8187`).
- Time to listener:
  - Warm lines: macOS/Windows 2–10 s, Linux 30–45 s (poll interval). SHA→TYO was 3.6 / 5.1 / 34.5 s.
  - Cold lines wait for on-demand material, which added 29–211 s.
- **First-flight pilot A/B** (armed 06:47 UTC for target `157.228.138.23:62102` only, with 4 FRA and 4 MOS TCP candidates):
  - 49 and 50 go from 6/6 TCP FAIL to 6/6 PASS: 9.99–10.00 Mbps, 0 idle windows, 0 stalls, ~1 MB of target replies.
  - On the same candidate `d414cb74`, replies went from 0–14 B to about 1.07 MB.
  - Linux and Windows logged `tcp first-flight compatibility` once per stream. macOS gets the config over stdin, so the evidence there is behavioural.
  - UDP was clean in both arms.
  - Next: decide whether to widen the target allowlist. The evidence so far is one target reached through two directions.

## Regression on candidate 2026.09.24.3 (Windows 0.1.20.56, macOS 1.0.49, Linux agent 2026.09.24.3)

- Confirmed fixes:
  - A stuck relay stream now ends after 10 s without progress (`stage=remote_write reason=stall`, `remote_write_stall` HA events). On 56 the longest stream lasted 43 s; before, streams lingered 452–673 s.
  - FRA and MOS pass on all platforms under the direction-wide rule.
  - Controls 46 and 54 pass; the one 54 Windows failure is explained below and passed on repeat.
  - No restarts; all 20 PID checks matched.
- Found and being fixed before release:
  1. The first-flight list was built from lines with fresh material at pull time; MOS got 4–6 of its 8 lines. Fixed in `f2216c17` (all enabled lines, cap 16) and deployed at about 17:40 +08.
  2. The Desktop `ToBootstrapPayload` drops the pilot for the runtime's whole lifetime when the starting lease is not on the list. This is the likely cause of one unexplained macOS FRA stall (runs went pass, fail, pass). Removing the gate is in progress.
  3. After a line re-acquisition, the Windows client kept a dead credential for about 130 s; it renews only on `renew_after`. Failure-driven renewal on Desktop and Android is in progress.
  4. Windows External-UoT on SHA→LAX still loses 7.93 % (was 13.9 %), with 8,636 queue drops and `carrier_sndbuf=2097152`. The same carrier on macOS lost 0.055 %. Root-cause work is in progress.

## Decisions (2026-09-24 evening)

- **Offline survival target: 10 min** (lowered from 30).
  - Current behaviour: a line is re-acquired about every 20 min, and each re-acquisition invalidates the credential clients hold for that line. An outage starting at a random moment therefore gets 0–20 min on a single-line forward, about 10 min on average; 16 min was measured.
  - Multi-line forwards last longer, because the new write-stall detection moves streams to lines whose re-acquisition is at a different phase.
  - A guaranteed 10-minute floor would need dual sessions per line. **Accepted by the owner (option A):** about 10 min on average, longer on multi-line forwards, and close to 0 in the worst case on a single-line forward. No credential-side change in this release.
- **HKG→mainland (combo 56) deferred.** It is excluded from the acceptance gate. Its only line is broken, which is a provider/line issue, not a client one.

## Final acceptance on candidate 2026.09.24.4 (Windows 0.1.20.57, macOS 1.0.50, Linux runtime 2026.09.24.4.64fc750e)

- **Phase 1** (180 s, 10+10 Mbps, 4+4 streams, serial; 10 directions × 3 platforms, HKG→mainland deferred): **30 of 30 cells PASS on the first run**, with no re-runs.
  - Every cell: TCP 9.99–10.00 Mbps, 0 idle windows. UDP loss was ≤ 0.01 %.
  - This includes SHA→LAX on Windows (was 7.9 / 13.9 %), and PEK→FRA/MOS with the direction-wide first-flight rule.
  - Raw data is in `tmp/matrix-20260924/<combo>-<os>-v4.json`.
- **Phase 2** (release gate, 600 s at 20+20 Mbps; 46 SHA→HKG, 54 SHA→TYO and 49 PEK→FRA × 3 platforms): the first attempt was cut off, leaving test forwards 226/227 enabled. They were disabled at 14:21 UTC, and the gate is being re-run with the Phase 1 runner.
- **Phase 2 result (2026-09-24 14:25–16:23 UTC):** 8 of 9 PASS (TCP 20.0 Mbps, 0 idle windows, 0 % UDP loss). 46 SHA→HKG passed on all three platforms; Linux passed only on its re-run. 54 SHA→TYO passed on all three. 49 PEK→FRA passed on Linux and macOS.
  - **46 Linux, first run:** all four TCP streams were reset 1 s in. The direction's only line (818) was re-acquired in that same second. The harness does not reconnect, so every window after it counted as idle. This is the accepted single-line rotation limitation, not a new defect.
  - **49 Windows:** UDP loss was 24.7 %, and 46.0 % on the re-run. Root cause and fix are below; the release is held for them.

## UoT carrier lag cascade (found by the 49 Windows gate, fixed in 04cb156c)

- One Frankfurt-side relay (candidate 6580fe8e) really does fall behind. The runtime correctly left it.
- It then condemned every replacement carrier within 1–2 s of attach: `for 0s … worst 2–6 s, carrier age 1 s`. The result was 155 failovers bouncing between the two verified UDP candidates, and 330 k queue drops.
- **Cause:** a replacement first replays the resend window (up to 2 MiB). The queue it inherits waited through the previous carrier's drain, the HELLO and that replay, and overflowed meanwhile. The writer charged that wait to the new carrier, and the overflow bypassed the 10 s attach grace.
- **Fix:** a datagram's wait counts only from when this carrier's own work starts (attach, or the end of its replay). The overflow baseline is taken at the same point. A carrier whose own writes fall behind is still left.
- **Test:** the regression test reproduces the production signature before the fix and passes after it. The full ultra-client suite passes.
- Linux and macOS passed only because they started on the healthy sibling (747b53e3) and never lagged.

## Full line sweep from the source host (2026-09-24/25)

- **Method:**
  - The sweep ran from the mainland source host (10.10.10.171).
  - For every relay of every enabled line (59 in use or candidate lines, 162 relays) it ran the client's own stream handshake, then 2 paced TCP streams at 20 Mbps for 12 s. Each line pushed to the acceptance pump in its own destination region.
  - It used the panel's current material for lines in use, and a local acquisition, never uploaded, for idle lines. No client credential was touched.
  - Scripts: `tmp/matrix-20260924/linesweep.py`, `lk-line-export.php`, `run_sweep.sh`.
- **Result:** 153 of 162 relays sustain ≥ 17 Mbps.
- **Genuinely weak:**
  - Line 41 (FOC→TPE, its only line): all relays 3–4 Mbps, measured from Shanghai.
  - Line 653 (SHA→TYO): 2 of 3 relays at about 5.5 Mbps.
  - Line 380 (北京-德国【WireGuard模式】): the stream handshake is refused on every attempt.
  - One relay each on lines 347, 696 and 598 runs at 8–14 Mbps.
- **Test-infrastructure faults found and fixed:**
  - The first pass used the HK pump for every direction, which made Korea and US lines look slow.
  - The LA and Frankfurt pumps had wedged. Frankfurt held 128 half-open connections, one parked thread each, and every PEK→FRA relay looked dead.
  - Fix in 8c377468: 60 s idle timeout per pump connection, deployed to all six sidecars (`lk-pump.service`). The production UoT services were not touched.
- **Applied to the panel 2026-09-25 with the owner's approval:**
  - Backup lines for the single-line directions:
    - 46 SHA→HKG: add 15, 684, 197.
    - 47 SHA→SIN: add 399, 464, 433, 463.
    - 53 FOC→TPE: add 120, 467, 625, 695, 900.
  - Line 653 demoted (`admin_dispatch_class=low`).
  - Backup and one-command rollback: `/root/codex-backups/backup-lines-20260925/rollback.sh`.

## Credential lifetime (experiments on the source host)

- Line 10's credential was rejected ("line not live") between 25.7 and 30.2 min after a single acquisition. Nothing re-acquired it in between.
- Line 120's credential still authenticated at 40 min. A stream opened at acquisition stayed alive, heartbeating every 10 s, for the whole 42 min.
- **So the lifetime is not a fixed 30 min, and an established stream outlives at least 40 minutes of credential age.** The rotation resets come from re-acquisition.
- Alternating two provider accounts per line would therefore allow make-before-break rotation. This needs a second account and is the owner's decision.

## Stopping point (2026-09-25 ~02:10 +08) — release NOT published

- **Candidate 2026.09.25.1:** runtime `c10e11f1`, Windows 0.1.20.58, macOS 1.0.51, Android 0.2.34 (versionCode 36, same signers).
  - Staged on the panel at `/root/release-ultra-recovery-20260925-1` with `STAGED_RELEASE_VALIDATED`, but held.
- **Gate on it:**
  - 49 PEK→FRA: PASS on all three OS. Windows UDP loss 0.001 %, was 25–46 %; the lag-cascade fix is confirmed.
  - 46 SHA→HKG: PASS on Linux and macOS. **Windows FAIL twice:** 2.4 % UDP loss with TCP at 18.8 Mbps, then 10.6 % UDP loss.
  - The failures began after the candidate interleave (e1766df3) went live, which is now rolled back on the panel and reverted (42652e2e).
  - The loss was a lag burst over every new line in the first minute of the run.
  - 54 SHA→TYO was still running unattended; the script disables its test forwards when it ends.
- **Also deployed tonight:** the quota-bucketed policy generation (c6f9aeee), which ends generation churn on every usage report.
- **Not deployed:** d49d1fc0 (leave expired services out of client configs).
  - Root cause of the lease storm: service 11 expired at 14:43 but keeps `status=active`. Its 4 forwards get no grant, so clients re-pull and re-lease everything every 2–12 s.
- **Next:**
  1. Re-run 46 and 54 on Windows without the interleave.
  2. If clean, deploy d49d1fc0 and publish the staged release (re-validate first).
  3. Then verify D2: reinstall Windows 0.1.20.57 and macOS 1.0.50 and watch them self-update within 10 min.
  4. Fault injection: kill the runtime, then a 10-min panel block.
  5. Revisit the interleave only with a Windows-specific test.

## Re-run without the interleave (2026-09-25 02:28-03:02 +08) — release still held

- The interleave rollback landed on the panel at 18:07:59 UTC (the deployed `UltraMaterialService.php` matches 42652e2e byte for byte). Both runs below started after it. Windows was on 0.1.20.58; the HK and TYO pumps answered `LKSTAT`.
- **54 SHA→TYO, Windows (gate5, the cell cut off last night): PASS.** TCP 19.996 Mbps, UDP loss 0.0105 %. 54 now passes on all three OS. Test forwards 242/243, left enabled by the interrupted run, were disabled when it ended.
- **46 SHA→HKG, Windows (gate6): FAIL twice** without the interleave. UDP loss 5.36 %, then 8.24 %; TCP 20.00, then 18.96 Mbps. **The interleave was not the cause.**
  - Signature: the same UoT lag cascade as before. Carriers on both UDP candidates (`551e7a4c`, `96027ab4`) are condemned 2–7 s after attach with `every datagram waited at least 1s … worst 1.7–6.7 s`. It comes in bursts (the first minute, then 02:45–02:47 in run 1; 02:52–02:58 in run 2) and is clean in between.
  - `carrier_sndbuf=65536` is not the cause: every Windows run reports it, including those that passed, because Windows sizes the buffer dynamically by design.
- **Confound, present during every run tonight:** service 11 expired at 14:43 UTC but keeps `status=active`. Its enabled forwards 158/159/170/171 are on the Windows and Linux test hosts. The Windows host re-reconciles every 2–3 s (`preparing_routes 0/7→7/7`, stop/start of those 4 forwards). The panel issued ~1,870 leases each for 226 and 227 in 11.5 min across 4 devices; the last 20 k requests were 6,697 lease and 5,753 config pulls. Linux has the same forwards and passed 46, so this is not proven to cause the Windows loss, but the gate is not clean while it runs. d49d1fc0 removes it; it uses the broker's own expiry test, so it only omits forwards the broker already refuses.
- Side finding, not changed: `LeigodTokenBroker::parseServiceExpiry` uses `strtotime()` under PRC. If `services.expire_at` is UTC (the DB session is UTC), the broker refuses a service 8 h before it expires. d49d1fc0 copies that test on purpose, so config and broker agree.
- **Next:** deploy d49d1fc0, then re-run 46 Windows. If it still fails, investigate the Windows carrier on the HK relays (per-candidate A/B, send-queue timeline) before any publish.
