# Ultra recovery release 2026.09.24.1

Continues `docs/handoffs/2026-09-24-ultra-recovery-handoff.md`. The six open items are not all closed; what follows is what was diagnosed, what shipped, and what is still open.

## Versions

| Component | Was | Now |
|---|---|---|
| Ultra runtime (all platforms) | `2026.09.19.1.f18d01fb` | `2026.09.24.1.<shortsha>` |
| Linux client agent / installers / renderer | `2026.09.19.1` | `2026.09.24.1` |
| Windows desktop + headless | `0.1.20.52` | `0.1.20.53` |
| macOS app | `1.0.45` | `1.0.46` |

`install/install-external-uot.sh` stays at `2026.09.19.1`: its script did not change, and its manifest entry is not republished.

## What shipped

### 1. PEK -> FRA / PEK -> MOS cold-first-segment stall: productized pilot

Diagnosis (unchanged, see `2026-09-23-pek-fra-root-cause.md` and `2026-09-23-pek-mos-live-test.md`): on the shared target `157.228.138.23:62102`, a cold connection sending its payload as one burst stalls 4/4 streams in ~18 s, while sending the original first 4 bytes, waiting 300 ms, then the rest runs 180 s at ~10 Mbps with the target's cumulative ACK matching the client byte for byte. The cause inside the provider path is not identified; the workaround keeps the payload bytes identical and only splits the first upstream write.

Implementation:

- Panel: `ULTRA_TCP_FIRST_FLIGHT_JSON` in `.env` maps a direction (`PEK -> FRA`, `PEK -> MOS`) to `prefix_bytes`, `delay_ms` and an explicit `candidate_hashes` allowlist of at most 4 line hashes. `ClientAgentController::tcpFirstFlightForGrant` attaches the block only to a live, unexpired policy-v2 grant for that direction. Empty config, any other direction, a missing allowlist or an expired grant attaches nothing. The live `.env` already carries the two directions but **without** `candidate_hashes`, so production stays off until the hashes are filled in.
- Clients (Go runtime, Windows/macOS desktop, Linux renderer) forward the block only when target, policy generation, expiry and at least one allowlisted candidate all match at issue time.
- Runtime (`tools/ultra-client/tcp_first_flight.go`): the split applies to the first upstream write of a new TCP stream whose authenticated candidate is on the list. Short reads are not padded, the wait is cancellable and never replayed, server-first protocols and half-close are unaffected, and native SOCKS / external UoT never pass through it.

One correction to the earlier draft: the runtime reads its config once, while grants and policy generations rotate every few minutes (240 distinct generations observed on forward 170 in 20 h). Gating streams on the issued generation and expiry would have switched the compatibility off within minutes of each start, and rendering those fields would have restarted the Linux forward on every renewal. They are now issue-time checks only; the rendered block carries target, candidate hashes, prefix and delay, and the runtime matches the stream's authenticated candidate.

### 2. Stream close stage in the runtime log, and Windows abort classification

`tcp stream ended` now carries `stage=` naming which operation ended the copy: `local_read` / `remote_write` (upstream) or `remote_read` / `local_write` / `policy` (downstream). `haErrorClass` reports `reset` for WSAECONNRESET (10054) and a new `aborted` for WSAECONNABORTED (10053), which previously collapsed into `io_error`. The historical CAN -> SIN Windows failure (4 streams ending early, `wsasend` established-connection-aborted toward `127.0.0.1`) can now be attributed to a side instead of a truncated socket error. The root cause itself is still not reproduced.

### 3. Desktop failure logging for the Ultra runtime only

`UltraRuntimeFailureLog` promotes failure lines from the `ultra-runtime` child (stream ends with a non-benign reason, setup/candidate/session failures, carrier reconnects, runtime stop) to `Log.Warning`, rate-limited to 5 per forward per kind per minute with the dropped count reported when the window reopens. Addresses are redacted; hashes are kept. Ordinary completions (`complete`, `eof`, `canceled`, `closed`) and all other child processes stay at Debug. Wired into both `ProcessSupervisor` and `MacProcessSupervisor`.

### 4. Linux agent restarts a forward onto a replaced runtime

`bc749ce7` (the enterprise merge) dropped `ultra_runtime_requires_refresh` while keeping its tests, so five tests have failed since 2026-09-21 and a released runtime could be replaced on disk while the old inode kept running. Restored, with one addition the original lacked: a unit pinned to a different binary can never converge, so each installed binary identity forces one restart per forward and stops once that restart produced a new main PID. An unchanged PID means the restart never happened and is retried. An explicit refresh request still always wins.

### 5. Carried over from the previous session's working tree

- Cold start with a missing grant queues a bounded full pull (`ConfigPollingService`) and the panel returns a deterministic pending revision instead of acknowledging the stable one (`pullDeliveryMetadata`), so the client does not wait through a useless 304.
- Native SOCKS tests reserve the TCP and UDP listeners before the server starts and surface an early serve error, fixing the port-steal flake.

## Verification

- `go test -count=1 ./...` in `tools/ultra-client`: PASS, 160.4 s (twice, before and after the gating change). `go vet` clean.
- .NET: Core 664/664, Mac 227/227, App 113/113, all Release.
- PHP: the two new contract tests PASS. Of the 416 `master/tests` scripts, the working tree fails 27 and clean HEAD fails 31; the only failure unique to this tree is `client_app_download_cache_bust_regression_test.php`, which compares the manifest hash of `leikwan-bridge-setup.exe` against a local artifact that was rebuilt on 09-23 and never published. It passes once the manifest matches the published installer.
- Python: `test_ultra_runtime` 9 passed + 5 subtests; `test_ultra_runtime_binary_refresh` 6 passed. The full client-agent suite has 8 failures that also fail on clean HEAD (artifact mirror/manifest drift, sidecar update helper, installer line endings, the PIE assertion on the published non-PIE Linux binary, one mptcp aux-unit restart assertion).

## Still open

- The provider-side mechanism of the FRA/MOS stall is not identified, and the pilot is not activated: the live `.env` lacks `candidate_hashes`.
- No same-build 11-direction x 3-platform acceptance exists yet. The 09-21 matrix remains the baseline, not an acceptance of this build.
- The 2026-09-21 Linux failures stay classified (connect-before-carrier vs read/carrier) but are not re-run per direction.
- CAN -> SIN Windows historical reset is not reproduced.
- This shell is not an Administrator, so the Windows protected install cannot be replaced or read back from here.
- The live panel PHP has drifted from this branch: `ClientAgentController.php`, `AccessPolicyService.php`, `ExternalUoTPool.php`, `UltraCandidateReviewService.php` and `UltraInventoryService.php` differ, and `ClientConfigRevisionService.php`, `LeigodExecutionLeaseService.php`, `LeigodHybridRuntimeService.php` exist only on the server. Copies are in `.tmp/live-panel-20260924/` (not committed). Publishing the controller means reconciling that drift first; it was not overwritten.

## Release publication

Recorded at the bottom of this file after the artifacts are built and the manifest published.

## Progress checkpoint — 2026-09-24 01:15 +08 (panel down, stopped here)

### Committed locally, not pushed

- `906efe97` — the code: first-flight pilot, stream close-stage + Windows abort class, desktop failure logging, restored Linux runtime-inode refresh, pending-grant cold start. Versions bumped (Windows 0.1.20.53, macOS 1.0.46, agent 2026.09.24.1).
- `9889cecb` — `artifacts/versions.json` for the release. Payloads are gitignored, as in every previous release.

The branch has no upstream. `origin/master` is 350 commits behind local `master` plus these 74; do not push without deciding that explicitly.

### Built and verified, sitting in `tmp/release-20260924/` and copied into `artifacts/`

| Artifact | SHA-256 |
|---|---|
| `ultra-client_windows_amd64.exe` | `f01d503a97d25a20779ebf7ba253c87c2ff9e630003caf2f90b0bac094ec740d` |
| `ultra-client_linux_amd64` | `d9041df86523aeb5a2ec6044a5acf8fa486c5ee01f5aae75d69aa07035566217` |
| `ultra-client_linux_arm64` | `2fcc98dbddce53f85a40b22f41bac8b2c9a8911c571dff16006c05a51c68cd2b` |
| `uot-sidecar_linux_amd64` | `c6b716c94fd012265a51713a9e24b5f740eecb7310d9a9c5bddc17c032370229` |
| `uot-sidecar_linux_arm64` | `15b8377fef45f8fa91b522c36572e5f526d694720fde2d27a2808c0be3e2303a` |
| `leikwan-client-agent_linux_amd64` (frozen, PyInstaller 6.16.0 on Debian 12, built twice, byte-identical) | `536e8c212be1af882906a0953ece8e4461721180c97fc33ff47ee6f81de2c9e6` |
| `leikwan-bridge-setup.exe` (0.1.20.53) | `ae9cc42d09f77a64f60787fabdc17bc3ef199cabee4a37f9a2ffebb87417f440` |
| `leikwan-bridge-headless-win-x64.zip` | `b58d848ab5b626e787d683b45e2ea09bf74358b54c5164d472fc91a3adf2df41` |
| `install-client-agent-linux-arm64` (reproducible build) | `907f175472f87d151a71afd27dab2e7acf1434c1ae6bb4e55b27f9f0be0a209e` |

All stamped `2026.09.24.1.906efe97`. The Linux binary was copied to the test host and ran `--version` there. The headless runtime map is in `tmp/release-20260924/windows-build-manifest.json`.

### Publish: NOT done — panel died mid-upload

- `tmp/release-20260924/plan.json` is the validated-shape plan (`release_id=ultra-recovery-20260924-1`, commit pin `9889cecb…`, 15 changed manifest keys, 16 payloads including the `artifacts/client-agent.py` mirror). `stage/` holds the payloads; `parts/` holds the 20 MB splits.
- The stage tarball upload via paramiko was killed at 120 s and left a **truncated** `/root/ultra-recovery-20260924/stage.tar` on the panel (36 MB of 193 MB; `tar` there reports "Unexpected EOF"). A pscp retry also stalled and was interrupted. Nothing was published: `deploy-client-artifacts.py` never ran, so the live manifest and payloads are unchanged.
- To resume: re-upload `stage/` (the `parts/p*` splits, then `cat parts/p* | tar -x`), then `python3 deploy-client-artifacts.py --root /www/wwwroot/v3.leikwanhost.com/leikwan-v3 --stage stage --plan plan.json --validate-only` and, if clean, without `--validate-only`. The publisher installs the manifest last and rolls back on failure.
- The panel was at load average 111 when it stopped answering SSH, 27 when it briefly recovered, then unresponsive again. The user stopped the work here.

### Live state left untouched

- Panel `.env` has `ULTRA_TCP_FIRST_FLIGHT_JSON` for `PEK -> FRA` and `PEK -> MOS` but **without `candidate_hashes`, so the pilot is off in production.** Hashes must be filled in (and php-fpm reloaded) to arm it.
- Linux test host `172.21.255.189` still runs the dirty candidate `2026.09.23.4.498db829-dirty` with the rollback timer armed. Its `OnActiveSec` was reset by something else during this session: it now elapses at **2026-09-24 02:02 +08** instead of the original 00:45. The binary file itself was not touched (mtime 23:45). Leave the timer armed.
- The live panel PHP has drifted from this branch (five files differ, three exist only on the server). Copies are in `.tmp/live-panel-20260924/`. Do not publish `ClientAgentController.php` without reconciling that drift; this release does not.

### Not done

- macOS 1.0.46: needs the build Mac (`leikwan@100.103.253.21`), not reached. Manifest macOS entries stay at 1.0.45.
- Windows install: this shell is not Administrator, so the protected install was not replaced or read back.
- No same-build acceptance matrix. The 11 x 3 matrix, the per-direction 2026-09-21 Linux re-run, and the CAN -> SIN Windows reproduction are all still open.
- Two commits are local only.

## Published — 2026-09-24 01:57 +08

The panel VM came back and the staged release was published with
`deploy-client-artifacts.py` (`release_id=ultra-recovery-20260924-1`,
`status=committed` at 17:56:33 UTC, backup `/root/codex-backups/ultra-recovery-20260924-1`,
health check `php-fpm active` + `/api/health` ok). The plan validated clean
(`STAGED_RELEASE_VALIDATED`) before the commit. Public file hashes were then
read back from disk and match the build:

| Artifact | SHA-256 |
|---|---|
| `artifacts/versions.json` | `2d43d6d2a41549200446b69365a965f1ed57bbcdd8f821f247afb6b433d97dd5` |
| `ultra-client_linux_amd64` | `d9041df86523aeb5a2ec…` |
| `ultra-client_windows_amd64.exe` | `f01d503a97d25a20…` |
| `leikwan-bridge-setup.exe` (0.1.20.53) | `ae9cc42d09f77a64…` |
| `leikwan-client-agent_linux_amd64` | `536e8c212be1af88…` |
| `install_forward_runtime.py` | `792100e605a27503…` |
| `leikwan-bridge-headless-win-x64.zip` | `b58d848ab5b626e7…` |

Two corrections during publish:

- The manifest's `install_forward_runtime.path` said `artifacts/install-forward-runtime.py`; the publisher's canonical contract requires `client-agent/install_forward_runtime.py` (same bytes). Fixed, and the release commit was amended to `ad44577d`.
- The headless zip on disk was still the 0.1.20.52 build (`e707ca35`); the publisher therefore kept it. The 0.1.20.53 zip (`b58d848a`) was copied in afterwards and the manifest hash updated (`93b007b5`).

### Not verified, deliberately

Another session is copying the live MySQL datadir off the panel
(`tar --one-file-system /var/lib/mysql | zstd | ssh root@117.55.193.164`),
holding the database. Every API request currently fails with
`migration_failed` (PDOException) at `MigrationRunner.php:115`, including
client config pulls and node task pulls. That started at the VM reboot and is
the other session's lock, not this publish: the publish touched no PHP and no
table. No database statement was run after noticing it, and no public HTTP
re-check was made, so this release is **not** verified end to end through the
API. Re-check `/api/health` and a real `/artifacts/versions.json` download once
that copy finishes.

### Still open

- The first-flight pilot is published but **not armed**: the panel `.env`
  `ULTRA_TCP_FIRST_FLIGHT_JSON` has `PEK -> FRA` and `PEK -> MOS` without
  `candidate_hashes`, so no grant carries the block until hashes are added and
  php-fpm reloaded.
- Linux test host `172.21.255.189` still runs the dirty candidate
  `2026.09.23.4.498db829-dirty`; its rollback timer elapses at 02:02 +08.
- macOS stays at 1.0.45 (needs the build Mac). The Windows protected install
  was not replaced (no Administrator shell). No same-build acceptance matrix.
- The four local commits are not pushed.

## Panel migration and end-to-end check (2026-09-24)

The production panel moved from `173.208.183.93` to a new server
(`117.55.193.164`, same host key). The migration copied the webroot after this
publish, so nothing had to be re-uploaded:

- The new server's `artifacts/versions.json` is the published file
  (`2d43d6d2…`). For all 73 manifest entries, the file on disk was hashed and
  compared on both servers: 65 match. The same 8 do not match on either server
  (Android APK, the four macOS 1.0.45 files, `gost_linux_amd64`,
  `leigod_linux_amd64`, `cfwg_latency_watchdog`). That gap already existed
  before the migration and is not part of this release.
- Through nginx on the new server, `/api/health` returns 200 and the database is
  up. Downloading the 14 artifacts this release touched through their
  `artifact_url` gives the published hashes. That covers the Ultra runtimes
  (Linux amd64/arm64, Windows), the UoT sidecar, Windows setup/headless
  zip/headless installer, the agent (source and frozen), the three installers,
  `client-agent-update.sh` and `install-forward-runtime.py`.
- Through Cloudflare, `/api/health` returns 200 and the rendered manifest is
  the same one the new server serves (`06c22805…`). The old server's nginx and
  cloudflared are stopped; since 19:22 UTC, live client traffic lands on the
  new server.
