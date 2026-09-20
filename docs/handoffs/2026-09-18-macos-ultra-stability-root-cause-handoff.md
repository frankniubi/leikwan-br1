# macOS Ultra stability root-cause handoff (2026-09-18)

## Status

**PARTIAL / NOT DEPLOYED.** The control-plane recovery patch is implemented in the local
worktree and all Core/Mac test suites pass. The Mac data plane still shows a measurable
failure rate, so this work must not be reported as fully fixed until the patched bundle is
deployed and the controlled soak gates below pass.

## Scope

- Worktree: `C:\Users\frankniubi\Downloads\leikwan-br1`
- Branch: `fix/ultra-probe-single-reader`
- HEAD before this handoff: `c5da15b1`
- Test machines:
  - Windows: `172.21.255.4`
  - macOS: `100.103.253.21`
  - Linux: `172.21.255.189`
- Local listener under test: `127.0.0.1:10001`
- Protocol test: Shadowsocks address-first `none`, 2,000,000 bps aggregate target
- Archive: `C:\Users\frankniubi\Downloads\Telegram Desktop\LeiKwan-diagnostics-20260918-210004.tgz`

Credentials are intentionally excluded. They remain in local credential files only.

## Executive verdict

全面根治可行，但需要拆成两条独立链路：

1. **控制面**: policy generation rotation and transient 502/network failures. This is a
   client recovery defect and has a local patch.
2. **数据面**: the Mac runtime has repeated TCP/TLS resets and UDP candidate arm failures.
   This is not proven to be caused by the control-plane defect and needs candidate/path
   isolation before changing the runtime or provider policy.

The server-side `policy_generation_invalid` rejection is doing the correct stale-ticket
guarding. It must not be disabled or made permissive as a workaround.

## Evidence collected

### Mac diagnostic archive, local time 18:00-21:00

- No `[ERR]` or `[FTL]` application records in the requested window.
- `process_group_create_failed` is non-fatal because the supervisor already falls back to
  direct-PID signalling.
- Forward 184 had repeated generic `ultra runtime start failed` warnings. The old log only
  retained `InvalidOperationException`, not the failing phase or inner error.
- `policy_generation_invalid`: 3 occurrences.
- Lease unavailable: 9 occurrences.
- Client-reported 502: 1 occurrence.
- The sampled main thread was in the normal AppKit event loop; the archive does not prove an
  application hang or crash.

### Live panel access/error log correlation

The live panel logs were read read-only. Server timestamps are UTC.

- At `2026-09-18 10:43:41Z` (local 18:43:41), forwards 184/185 received HTTP 409 with
  `policy_generation_invalid`; both received HTTP 200 lease responses around eight seconds
  later.
- At `2026-09-18 11:36:54Z` (local 19:36:54), forward 185 received the same transient
  rejection and later recovered with HTTP 200.
- The client 502 at local 19:41 was not present as an Nginx ingress 502 for that client;
  it is treated as a transient control-plane result, not as proof of a panel outage.
- The same policy-generation rotation pattern is present for other clients, so this is a
  shared control-plane event, not a Mac-only server rule.

### One-hour local soak results

The harness used one worker on each available listener. `errors` count failed HTTP/TLS
attempts, not process exits; every attempt also opens a fresh connection.

| Host | Window (UTC) | Bytes | Attempts | Errors | Error rate | P50 |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Linux `172.21.255.189` | 12:51:16-13:51:16 | 190,341,424 | 5,814 | 21 | 0.36% | 353 ms |
| macOS `100.103.253.21` | 12:51:17-13:51:18 | 213,754,601 | 6,720 | 355 | 5.28% | 425 ms |

Linux completed with a low but non-zero request error rate. Mac is not a stable pass: one
attempt in roughly nineteen failed. The Mac runtime process stayed alive, but that only proves
process liveness, not application-level continuity.

At the end of the Mac soak, the runtime status for forward 170 reported:

- `state=ready`
- `ha_failures=589`
- recent `udp_arm_failed` candidate feedback
- `active_udp.rtt_ms` approximately 30 seconds
- `latest_tcp.rtt_ms` approximately 98 ms
- repeated TCP `eof` events and client-side TLS EOF/reset errors

This separates a healthy TCP control/selection snapshot from an unhealthy UDP/data-plane
path. The Windows host had no TCP or UDP listener on port 10001 and produced no valid soak
summary, so Windows is not an acceptance result.

## Local patch already present

These edits are uncommitted and local-only:

- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`
  - `RequestControlPlaneRecovery(int? targetedForwardId = null)` queues an affected forward
    and triggers an immediate, throttled pull.
  - 409 grant rotation, HTTP 502/503/504/530, and network failures use targeted recovery
    instead of waiting for the normal poll interval.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
  - runtime start warnings now include the exception object.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacProcessSupervisor.cs`
  - logs the `setpgid` errno and explicitly records direct-PID signal fallback.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacUltraRuntimeHost.cs`
  - records startup phase (`port_preflight`, `spawn_runtime`, `wait_control_socket`,
    `deliver_lease`, `wait_listener`, and state persistence).
  - preserves the original startup exception as an inner exception.
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/Ultra/WindowsUltraRuntimeHost.cs`
  (added 2026-09-18 follow-up; cross-platform parity — defect is shared, macOS incidence higher)
  - brings Windows start path to parity with macOS: phase tracking
    (`validate` → `port_preflight` → `stop_previous_runtime` → `spawn_runtime` →
    `deliver_lease` → `wait_listener` → `persist_applied_state`), `Log.Information`
    at `port_preflight complete / previous runtime stop / process started / lease
    delivery begin+result / listener ready`, and `Log.Warning(exception, phase=, ErrorType)`
    with `throw new InvalidOperationException("ultra_runtime_start_failed", inner)`.
  - closes prior diagnostic gap where Windows only emitted a generic catch/rethrow
    without phase or inner exception, while macOS already had it.

Validation already completed:

- `LeikwanBridge.Core.Tests`: `585/585` passed (re-verified 2026-09-18 after Windows parity).
- `LeikwanBridge.Mac.Tests`: `227/227` passed (re-verified 2026-09-18).
- `tools/ultra-client` Go suite: `go vet ./...` clean, `go test -short` PASS,
  plus `TestRetryActiveCandidate*` / HA probe regressions PASS.
- `dotnet build LeikwanBridge.Desktop.sln -c Release`: `Build succeeded, 0 Error(s)`.
- `git diff --check`: passed (prior run; re-check before packaging).

The patch has **not** been packaged or installed on the Mac test host yet.
Windows parity change is likewise local-only and not yet packaged/deployed.

## Root-cause matrix

| Finding | Confidence | Meaning | Required action |
| --- | --- | --- | --- |
| Stale policy-generation 409 is transient and recovers with a fresh lease | Confirmed | Client waited too long before refreshing | Deploy and verify targeted immediate recovery |
| Mac process-group warning is non-fatal | Confirmed | Direct PID fallback keeps stop/restart functional | Keep errno telemetry; do not treat it as the data-plane root cause |
| Mac runtime start failures lacked phase/error detail | Confirmed | Previous diagnostics were insufficient | Deploy phase/inner-exception logging and reproduce once |
| Mac TCP/TLS attempts fail during soak | Confirmed | User-visible retries/resets are possible | Isolate target behavior, candidate, and egress path |
| Mac UDP arm failures and 30s RTT | Confirmed | UDP candidate/path is unhealthy or mis-selected | Run per-candidate UDP canary and compare against provider telemetry |
| Provider relay, Mac egress, MTU, IPv4/IPv6, or target-side close is the sole cause | Unproven | Current evidence cannot distinguish these | Use controlled protocol-specific tests before changing code |

## Root-fix runbook

### P0 - deploy and verify the control-plane fix

1. Build the Mac arm64 bundle from the current worktree.
2. Record bundle hash, embedded `ultra-client` hash, version, and code-sign result.
3. Back up the existing Mac app bundle and ClientAgent state.
4. Install the bundle on `100.103.253.21` and restart only the test client.
5. Reproduce a controlled policy-generation rotation and confirm:
   - one targeted immediate pull,
   - no repeated start/stop loop,
   - the running forward is preserved while the fresh lease is fetched,
   - the new log includes phase and inner exception when a start really fails.

### P1 - isolate the Mac data plane

Run each control independently, preserving the same forward and lease generation:

1. TCP-only to a known HTTP target with a complete response.
2. TCP/TLS to the download target used by the soak.
3. UDP canary with a valid protocol payload and fixed packet size.
4. IPv4 and IPv6 separately, with the selected candidate and relay identity recorded.
5. Repeat once per authorized candidate; record `selection_ref`, candidate hash, lease hash,
   RTT, arm result, and error class.
6. Capture a short packet trace on the owned test host only when needed to distinguish local
   reset, remote reset, TLS close, or timeout.

Do not infer a bad relay from a zero-byte response to a target that intentionally ignores the
probe protocol. Use a known-good protocol/target control for every candidate comparison.

### P1 - inspect runtime selection and health accounting

Compare Mac `status.json` with the panel lease response and runtime logs:

- Verify the selected candidate is still authorized for the current policy generation.
- Confirm that `udp_arm_failed` is not caused by expired material, wrong transport metadata,
  or a stale binary/config.
- Check whether the 30-second UDP RTT is a real path measurement or a stale/unbounded probe
  result; add a bounded timeout if the field can remain stale.
- Confirm TCP EOF from a normal `Connection: close` request is not counted as a path failure,
  while resets before a complete response remain visible.

### P2 - apply the smallest proven data-plane fix

Choose the fix only after P1 identifies the failing layer:

- **Client/runtime bug**: add a focused regression test in `tools/ultra-client`, fix the
  candidate/association/retry state machine, then run the Go test suite and a live canary.
- **Provider/candidate issue**: quarantine or replace only the failing candidate; do not hide
  it by inflating retry counts or suppressing health telemetry.
- **Mac network/MTU/IPv6 issue**: correct the egress or path configuration and repeat the
  same protocol controls; do not add a client retry loop that masks packet loss.
- **Target/probe mismatch**: replace the probe with a protocol-valid control and keep the
  original test as a separate user-workflow test.

### P3 - acceptance

The issue is only complete when all of the following are true:

- Mac, Linux, and Windows each have a confirmed listener on `10001` before the soak.
- Each host completes a 3,600-second, 2 Mbps test with no unexpected runtime/process exit.
- The controlled HTTP/TLS data-plane test has zero failed attempts; any exception requires a
  documented target-side explanation and a passing control target.
- Mac `udp_arm_failed` does not recur during the acceptance window, active UDP RTT remains
  bounded, and the UDP canary has 20/20 valid replies per sample window.
- A controlled policy-generation rotation produces a fresh lease without stopping a healthy
  forward.
- Installed bundle/runtime hashes and logs are read back from every test host.
- Windows gets a real test result; an absent listener is a blocker, not a pass.

## Rollback

- Keep the current Mac app bundle and state backup until the new soak passes.
- If the patched bundle regresses, stop only the test client, restore the recorded bundle and
  state, then verify the listener and a short protocol canary.
- Local source edits are uncommitted. Roll back only the four listed source files after
  reviewing their diff; do not reset the dirty worktree or remove unrelated `tmp/` artifacts.
- Do not alter the panel's stale-generation rejection as a rollback or mitigation step.

## Handoff entry point

Start with P0 deployment and readback. Then run P1 with the same forward and controlled
targets. Do not claim the Mac problem is resolved based on process liveness or a `ready` state
alone; the 5.28% Mac request error rate and UDP telemetry are the current blocking evidence.

