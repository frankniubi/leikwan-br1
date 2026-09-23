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
