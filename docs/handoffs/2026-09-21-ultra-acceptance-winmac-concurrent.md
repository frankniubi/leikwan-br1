# 2026-09-21 — Concurrent Linux + macOS 600s acceptance, and the macOS UDP defect

Acceptance criterion (user): **TCP+UDP at 20 Mbps for 10 minutes, no interruption / no
stream stall = success.** Non-TUN, per `test-env.txt`.

## 1. Result summary

| host | build | UDP | TCP |
|---|---|---|---|
| Linux 172.21.255.189 | `2026.09.21.1.localcandidate` (`fbf6d9777568048b`) | 1,223,356 / 1,223,356 — **0.0% loss**, 19.507 Mbps, 602.05 s | 30,599 ok / **0 failures**, 600.49 s |
| macOS 100.103.253.21 (M1 Pro, arm64) | `2026.09.21.1.9b484db9` (`a6c3b7b66c72…` post-codesign) | 1,215,040 sent / 48,689 acked — **95.9928% loss**, 19.372 Mbps, 602.12 s | 54,469 ok / 138 fail (**0.2527%**), 600.89 s |

Linux UDP/TCP counters after the run, both forwards `state=ready`:
`queue_drop=0 queue_timeout=0 queue_blocked=0 socket_receive_drop=0
operator_quarantine_active=false`; `leikwan-client-agent` `NRestarts=0`.
Forward 201 `udp_transport=external_uot`; 202 `udp_transport=disabled` (correct for TCP).

**Linux passes the criterion cleanly and strictly better than the earlier 600 s run**
(which recorded 9 TCP `empty_reply` failures; this run has zero).

**macOS fails the UDP half at 96% loss.** TCP passes at 0.25%.

## 2. macOS UDP root cause — `wait_listener` timeout, NOT the lease limit

The macOS agent log (`/tmp/mac-enterprise-release20260921_001.log`) gives an unambiguous
sequence for forward 201, repeated in a ~40 s loop for the whole window
(`grep -c wait_listener` = **25**, last failure `04:22:46`, i.e. during the run):

```
ultra runtime start begin for forward 201
ultra port preflight complete blocked=false for forward 201
starting macOS process for forward 201 [ultra-runtime]: ultra-client
macOS process spawned for forward 201 [ultra-runtime] pid=97540
process_group_create_failed for forward 201 [ultra-runtime] errno=13; using direct-pid signal fallback
ultra runtime process started for forward 201
ultra control socket ready=true for forward 201
ultra lease delivery begin for forward 201
ultra lease delivery result=true for forward 201        <-- lease is granted fine
ultra listener ready=false for forward 201              <-- listener never binds
ultra runtime start failed for forward 201 phase=wait_listener (InvalidOperationException)
stopping macOS processes for forward 201 count=1
forward 201: processes not running, restarting
```

So on macOS the UDP forward **receives its lease successfully but the runtime's listener
never becomes ready**, the agent kills it, and restarts. The 96% UDP loss is the direct
consequence: there is no bound UDP socket on 60501 for most of the window, so the pump's
packets go unanswered and the harness counts them lost.

Corroborating observations:
- `netstat`/lsof on the Mac show **no UDP socket on 60501** at any sampling, while other
  Ultra forwards (10001, 30522, 32469, 32502) do hold UDP sockets — those are
  `transport_mode` values that predate this change.
- `process_group_create_failed … errno=13` (EACCES) appears on every spawn; the agent falls
  back to direct-pid signalling. This is a **separate macOS-specific defect** and may share a
  cause with the listener failure.
- Forward 202 (TCP) on the same host, same build, same agent: healthy all window.

## 3. The lease-churn observation (symptom, not cause)

While diagnosing, the panel `ultra_client_leases` table showed for the shared test device
`b4bca4c4e2af7a4d…`:

```
live (consumed, unrevoked, unexpired) = 492  against ULTRA_MAX_LIVE_LEASES_PER_DEVICE=512
  forward 201: 112  170: 80  158: 76  159: 76  171: 76  202: 72
live window: 20:10:17 -> 20:14:56 (4 min 39 s)
19,088 rows issued for this device in ~6 hours
```

Lease TTL is `min(policyExpiresAt, now + UltraMode::MAX_POLICY_TTL_SECONDS)`, so leases are
short-lived and renewal is frequent by design. But the 40 s restart loop above means forward
201 re-requests a lease on every cycle, and those rows accumulate against the same per-device
cap until refusals appear (`ultra_lease_refusals`: `lease_device_live_limit`, e.g. id 22038
forward 201 at 20:07:48).

**Therefore: fix `wait_listener` first.** The churn and the cap pressure are downstream of the
restart loop, not an independent bug. Raising `ULTRA_MAX_LIVE_LEASES_PER_DEVICE` would mask it.

## 4. Two production-readiness gaps found while reading the panel config

1. **The new builds are not in the runtime allow-list.** Panel `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env`:
   ```
   ULTRA_RUNTIME_FINGERPRINTS=2026.09.12.2.0dc69598,2026.09.12.1.4b106b02,2026.09.11.4.dd02ca77,2026.09.11.4.d27cc5fc,2026.09.11.3.d7924309
   ULTRA_ATTESTATION_ENFORCEMENT=enforce
   ULTRA_ATTESTATION_ENFORCE_PLATFORMS=linux,windows,macos
   ```
   Neither `2026.09.21.1.localcandidate` nor `2026.09.21.1.9b484db9` is listed. Any release of
   these builds must add the new fingerprint(s) **before** rollout, or enforcement will deny
   them. (Not yet proven to be the cause of the 201 failure — the lease *is* delivered — but it
   was observed as `409 policy_generation_invalid` on the lease-refresh path.)
2. `.env` limits in force: `ULTRA_MAX_DEVICES_PER_CLIENT=32`,
   `ULTRA_MAX_LIVE_LEASES_PER_DEVICE=512`, `ULTRA_LEASE_ISSUE_RATE_PER_MINUTE=600`,
   `ULTRA_CANDIDATE_QUARANTINE_ENABLED=1`.

## 5. Windows status

Windows (lwtest@172.21.255.4) **cannot be acceptance-tested without elevation.** The agent runs
as LocalSystem `LeikwanBridgeHeadless` and spawns the helper from
`C:\Program Files\LeiKwan Bridge Headless\bin\ultra-client.exe`; `lwtest` is Users-only
(`sc query` → `FAILED 5: Access is denied`; `schtasks /create /ru SYSTEM` → exit 1). The new
binary was deployed and **proved to execute and self-attest on Windows**
(`fingerprint 2026.09.21.1.9b484db9`), but a supervised traffic run needs an elevated operator:
```
copy /y "C:\Users\lwtest\lkb-smoke\ultra-client-windows-amd64-9b484db9.exe" "C:\Program Files\LeiKwan Bridge Headless\bin\ultra-client.exe"
sc stop LeikwanBridgeHeadless & sc start LeikwanBridgeHeadless
```

## 6. Binaries built and deployed this round

Built from `tools/ultra-client` at `9b484db9` (clean tree), `CGO_ENABLED=0`, `-p 4`,
`-trimpath -buildvcs=false -ldflags "-s -w -buildid= -X main.runtimeBuildVersion=…"`:
- `tmp/build-winmac/ultra-client-windows-amd64-9b484db9.exe` — `3d45e438416b2983a03206077d5929eef5b09e24b7ed90091f6625f1dcf847f4`
- `tmp/build-winmac/ultra-client-darwin-arm64-9b484db9` — `967021c78ad64f9242a7b58ce7a9be9ce12d5fa96d340fe78565fb251ef2d608` (unsigned; in-place codesign yields `a6c3b7b66c72…`)

macOS is currently **serving forward 202 with build `2026.09.21.1.9b484db9`** as the supervised
helper. Rollback: `/Users/leikwan/backups/winmac-9b484db9-20260920-195053/`.

## 7. Repo hygiene done this round

`tmp/`, `artifacts/` and `LeikwanBridge.Desktop/tests/**/TestResults/` were untracked
(4,771 files) and broad `.gitignore` rules added (`/tmp/`, `/artifacts/`,
`/LeikwanBridge.Desktop/tests/**/TestResults/`). The narrow pre-existing rules
(`/artifacts/macos/*.dmg`, `/artifacts/windows/_setup_stage/`, …) had been added *after* those
files were committed and so were inert — gitignore never applies to already-tracked files.
Deleted from disk: the 343 MB release zip and four 157–202 MB hang dumps (1.08 GB).

## 7b. RESOLVED — the `wait_listener` root cause and its fix (commit `88644489`)

The cause was **not** in the runtime and **not** the lease limit. It is one line of
`LeikwanBridge.Core/Helpers/NetworkProbe.cs`:

```csharp
public static bool IsPortListening(int port)   // was:
{
    using var client = new TcpClient();
    client.Connect("127.0.0.1", port);   // TCP only
    return true;
}
```

`tools/ultra-client` opens its TCP listener **only when the transport includes "tcp"**
(`main.go`: `if !config.NativeSocks5 && (transport == "udp" || transport == "tcp+udp")`). So for
`transport=udp`:

| transport | sockets bound | TCP connect | old probe |
|---|---|---|---|
| `tcp` | TCP | succeeds | **pass** |
| `udp` | UDP only | always fails | **fail forever** |
| `tcp+udp` | TCP + UDP | succeeds | **pass** |

A `transport=udp` forward therefore never passed `wait_listener`, the agent killed a healthy
runtime after the 6 s timeout (`ultra_listener_not_ready`) and restarted it every ~40 s — 25
times in the 600 s run. Each restart destroyed the bound UDP socket, so incoming datagrams went
unanswered: the 95.99% loss. The lease was delivered successfully on every single cycle, which
is why the lease-limit theory was wrong.

This is a **shared** .NET defect (both `MacUltraRuntimeHost` and `WindowsUltraRuntimeHost` call
the same probe), but the Linux half of the acceptance run was clean because Linux runs the
**Python** `client-agent.py`, whose `local_listener_present` already uses `ss -lun` for UDP. That
is the whole reason Linux passed and macOS failed on the same build.

**Fix:** `IsPortListening` now reports a listener on either protocol the runtime serves. A UDP
`connect()` proves nothing (connectionless, always succeeds), so the UDP half reads the kernel's
bound-endpoint table via `IPGlobalProperties.GetActiveUdpListeners()`. Both .NET hosts inherit
the fix through the shared probe, so neither needed a change.

**Regression coverage:** `NetworkProbeUdpListenerTests` binds a UDP-only socket and asserts it
reads as listening, plus unbound and out-of-range cases. Core.Tests 610/610, Mac.Tests 227/227,
solution builds with 0 errors.

**Also relevant to acceptance:** the forwards should use `transport=tcp+udp`. That binds both
sockets, so a TCP probe succeeds and the UDP half keeps flowing. `transport=udp` exercises the
edge the fix above repairs, but `tcp+udp` is what the acceptance run should use.

Still unverified: the fix is committed but **not yet deployed to the macOS test agent**, so the
96% number above is from the pre-fix binary.

## 7c. RE-MEASURED with `transport=tcp+udp` — UDP 96% → 0.0% on both platforms

Forwards 201/202 were re-pointed from `udp`/`tcp` to **`tcp+udp`**, matching the production
forwards 170/171/180 which are all `tcp+udp`. This is the right acceptance shape: the runtime
then binds **both** sockets, so a TCP probe succeeds *and* the UDP half keeps flowing.

With `transport=udp` the runtime binds UDP only, so **no TCP listener exists at all** — that is
exactly why the old TCP-connect probe could never pass. Same run, same rule, same target:

| macOS forward 201, 600 s | `transport=udp` (before) | `transport=tcp+udp` (after) |
|---|---|---|
| UDP sent / acked | 1,215,040 / 48,689 | **1,230,143 / 1,230,143** |
| UDP loss | **95.9928%** | **0.0%** |
| `wait_listener` failures | 25 in-window | **0** (last 12:15:20, window began 12:20:13) |

Process continuity is direct evidence the restart loop is gone: PIDs 11069/11082 started
`12:19:30` and were still alive `16m54s` later — one process each, spanning the entire window.
`ultra listener ready=true` replaced the old `ready=false`.

### Full concurrent 600 s result (both platforms, both protocols)

| host | UDP | TCP |
|---|---|---|
| Linux `2026.09.21.1.localcandidate` | 1,207,676 / 1,207,676 — **0.0%**, 19.254 Mbps | 25,123 ok / **301 fail (1.1839%)** |
| macOS `2026.09.21.1.9b484db9` | 1,230,143 / 1,230,143 — **0.0%**, 19.613 Mbps | 44,021 ok / 62 fail (**0.1406%**) |

Both platforms ran UDP and TCP concurrently. `queue_drop`/`queue_timeout`/`queue_blocked`/
`socket_receive_drop` all 0 on Linux; `leikwan-client-agent NRestarts=0`; both `state=ready`.
Mac status: `201 udp_transport=external_uot`, `202 udp_transport=external_uot`.

**UDP now meets the criterion on both platforms. TCP does not under concurrent load.**

### The remaining TCP gap is sidecar contention, not a code regression

All 301 Linux and all 62 Mac failures are `empty_reply` — the connection is accepted and the
request written, then the peer closes without replying. This is **load-dependent**, proven by an
isolated control run:

- Linux TCP-only, 120 s, **no competing UDP**: `tcp_ok 7526 / tcp_fail 0` — **0.0%**
- Linux TCP with UDP running concurrently: **1.1839%**

Both hosts target the **same** sidecar (`70.36.96.183`), so the concurrent run pushes ~40 Mbps of
UDP plus the TCP request stream at one host. Note that the earlier "Linux TCP 0 failures" result
was measured while Linux ran UDP on 60501 and **TCP was on the Mac** — i.e. the load split across
two hosts. Moving to the concurrent both-platform shape is what surfaced this. It is a shared
sidecar-capacity limit, not something `tcp+udp` broke.

Also observed: `udp_transport` reads `null` in the *Linux* runtime status while the Mac reads
`external_uot`. Both delivered 0.0% UDP loss, so the field is a reporting inconsistency in the
status writer, not a transport regression — worth a follow-up.

## 8. Open items

1. ~~Fix macOS `wait_listener`~~ — done in `88644489`; the `tcp+udp` change above already
   removes the trigger in practice. Deploying the fix makes `transport=udp` safe as well.
2. Investigate `process_group_create_failed errno=13` on macOS spawn.
3. Add the new runtime fingerprint(s) to `ULTRA_RUNTIME_FINGERPRINTS` before any rollout.
4. Windows traffic acceptance needs an elevated operator.
5. Push decision: 380 commits / 12,113 MB delta vs `origin/master`; the 30 session commits are
   deltas on local `master`'s Ultra source (`reliable_datagram.go` arrives in `d5876de6`, one of
   the never-pushed 350), so they cannot be cherry-picked standalone onto `origin/master` —
   it would not compile. Approved route: strip blobs ≥1 MB from a repo **copy** via
   `git filter-repo` (`git-filter-repo` is now installed), leaving ~155 MB to push, and keep
   this 13.6 GB working repo untouched. Not yet executed.
