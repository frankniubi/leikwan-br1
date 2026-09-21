# 2026-09-21 — SESSION FINAL HANDOFF: Ultra TCP/UoT enterprise-HA work

Written at the end of a long session (goal rounds 1-40). This is the entry point for whoever
picks this up next. Everything below is evidenced in the `2026-09-21-*` handoff files in this
directory.

---

## 1. THE CODE IS PUSHED — this was the session's biggest win

```
remote   https://github.com/frankniubi/leikwan-br1.git
branch   integration/ultra-enterprise-ha
commit   633f417de947eb1c36f81530b3c171a1d86ef0bf
PR       https://github.com/frankniubi/leikwan-br1/pull/new/integration/ultra-enterprise-ha
```

**How the 12 GB problem was solved.** The push had been stalled all session because the branch's
delta against `origin/master` was **12,113 MB** (356 blobs ≥1 MB: 51 zips, 36 dmgs, 53 exes,
100 extensionless binaries, 11 apks, 4 hang dumps). Those blobs live in **350 commits on local
`master` that had never been pushed**, so they could not simply be skipped — the 30 session
commits are deltas *on top of* that source and cannot stand alone (cherry-picking them onto
`origin/master` produces code that does not compile: `reliable_datagram.go` arrives in
`d5876de6`, one of the never-pushed 350).

Solution (approved by the operator), run on a hardlinked **mirror clone** so the 13.6 GB working
repo was never touched:

```bash
git clone --mirror --no-hardlinks <repo>/.git  C:/Users/frankniubi/lk-clean/repo.git
git -C repo.git fetch <repo> refs/heads/fix/ultra-probe-single-reader:refs/heads/integration/ultra-enterprise-ha
python -m git_filter_repo --strip-blobs-bigger-than 1M --force --partial
git reflog expire --expire=now --all && git gc --prune=now
```

Result: **13.62 GB → 53.69 MiB**, 0 blobs over 1 MB on the branch, 1,146 commits, 2,232 files.
Push took **10.6 s**. Source integrity was verified before pushing: `reliable_datagram.go`,
`ha_tcp.go`, `server_reliable.go`, `client_agent.py`, `UltraLeaseService.php`,
`NetworkProbe.cs` all present, and both fixes (`IsUdpPortListening`,
`ultra_runtime_is_serving`) found in the rewritten tree.

**Two gotchas that cost time:**

1. A `--mirror` clone sets `remote.origin.mirror=true` and points `origin` at the **local path**.
   The first "successful" push went to the local repo, not GitHub, and `--mirror` rejects
   refspecs. Fix: `git remote set-url origin <github>` **and** `-c remote.origin.mirror=false`.
2. `git cat-file` per object on this repo takes many minutes. Use
   `git rev-list --objects | git cat-file --batch-check='%(objecttype) %(objectsize) %(rest)'`
   in one pass instead.

The repo's 350-commit history still contains the binaries on the original local `master`; only
the pushed branch is clean. Reclaiming the local 13.6 GB needs a separate decision.

---

## 2. TWO REAL DEFECTS FOUND AND FIXED

Both are committed and in the pushed branch. Tests: Core 610/610, Mac 227/227, client-agent 460
passed (13 pre-existing failures that also fail at HEAD).

### 2a. `wait_listener`: a UDP-only forward was probed with a TCP connect — `88644489`

`NetworkProbe.IsPortListening` was a bare `TcpClient.Connect`. `tools/ultra-client` opens its TCP
listener **only when the transport includes "tcp"`** (main.go:
`if !config.NativeSocks5 && (transport=="udp" || transport=="tcp+udp")`). So for
`transport=udp` no TCP listener exists and the probe could **never** succeed. The agent then
timed out, killed a healthy runtime with `ultra_listener_not_ready` and restarted it every ~40 s
(25 times in one 600 s run), destroying the bound UDP socket each time.

**Measured impact:** macOS forward 201, same target, same 600 s — UDP loss **95.9928%**
(48,689/1,215,040) with the defect, **0.0%** (1,230,143/1,230,143) after switching the forward to
`transport=tcp+udp`. On Linux the same defect never appeared because Linux runs the **Python**
agent, whose `local_listener_present` already used `ss -lun` for UDP. **The macOS/.NET and
Linux/Python agents diverge — this bit twice.**

Fix: `IsPortListening` now reports a listener on either protocol; UDP reads
`IPGlobalProperties.GetActiveUdpListeners()` (a UDP `connect()` proves nothing). Both .NET hosts
inherited the fix through the shared probe. Regression test:
`NetworkProbeUdpListenerTests`.

### 2b. `awaiting_policy` wedge: changing a forward's target un-provisions it — `efd3f967`

Observed: forwards 201/202 `state=degraded error_code=awaiting_policy`, **nothing bound**, panel
still reporting them enabled. The agent logged
`Ultra static runtime change deferred because no previous lease snapshot is available`
indefinitely.

First fix attempt (the deferral guard) was **wrong and I falsified it myself**: deployed it, the
forward still did not recover. The listener check passed (runtime genuinely not serving) and the
deferral came from the other clause — **no fresh lease exists**. Verified by comparing every
config on the host: `ultra_access=False` on the healthy forwards too, so the lease is never in
the on-disk render. Healthy forwards log `independent Ultra lease renewal delivered`; the wedged
ones never do.

**Actual root cause:** changing `target_host` bumps `api_revision`, invalidates the lease policy
for the old target, and **the panel stops issuing leases for that forward**. The restart then has
no lease to hand the new runtime, so it defers forever. **Removing the guard would not help.**
Recovery: reverting the target restored both forwards within one poll cycle.

**A target change is a re-provisioning event, not a config edit.** The panel must issue a fresh
lease for the new target or refuse the edit. The guard fix (`efd3f967`) is retained because it
correctly stops the agent deferring for a runtime that is provably not serving
(`ultra_runtime_is_serving()`), but it is necessary-not-sufficient.

---

## 3. ACCEPTANCE STATUS

Criterion: **TCP+UDP at 20 Mbps for 10 minutes, no interruption, no stream break.**

| requirement | Linux | macOS | Windows |
|---|---|---|---|
| Candidate deployed & attested | yes | yes | **yes** (this round) |
| 10-minute run | **met** (600 s) | **met** (600 s) | not run |
| UDP ~20 Mbps | **0.0% loss** | **0.1068% loss** | not run |
| TCP streaming throughput | **150.6 Mbps** | **35.07 Mbps** | not run |
| Connection failures | **none** (`{}`) | **none** (`{}`) | — |
| Delivery independently accounted | **yes** (12.97 GB at target) | no | no |
| "No interruption" | **metric undefined** | **metric undefined** | — |

**What is solid:** UDP 0.0% loss reproduced across four configurations and two platforms. TCP
carries 150 Mbps (Linux) / 35 Mbps (macOS) with **zero** connection-level errors over ten
minutes on both. 12.97 GB was counted arriving at the target by sampling the sidecar's own
`eth0` counters — measured on the receiving host, not inferred from the sender.

**What is NOT established, stated plainly:**

1. **"No interruption" has no trustworthy metric.** `lk_stream.py` flags a stall when a single
   `sendall` blocks past 250 ms. Against a saturating sender that is ordinary backpressure, not a
   break — `error_classes` was empty on both platforms. But macOS showed `stall_max_ms 7682` and
   `stall_total_ms 1,950,084` (81% of aggregate stream-time), which is *not* ordinary buffer
   oscillation the way Linux's 751 ms was. **Do not report the stall count as "interruptions".**
   A real metric (windowed throughput stability, or a gap threshold justified against observed
   buffer behaviour) still needs defining, then both platforms re-run under it.
2. **macOS UDP lost 0.1068%** — the only non-zero UDP result all session. Either real path loss at
   19.3 Mbps or the harness's 2 s drain window undercounting acks. **Not distinguished.**
3. **Neither streaming run was rate-limited to 20 Mbps.** They show capability *above* 20 Mbps,
   not behaviour *at* 20 Mbps.
4. The sidecar `eth0` delta may include unrelated production traffic on that host.
5. Windows: candidate attests and both listeners bind, but forwards 201/202 report
   `degraded / tcp_connect_failed` and **no traffic acceptance has been run**.

---

## 4. THE ACCEPTANCE HARNESS — what to use and what to avoid

Three harnesses exist; using the wrong one produced most of this session's confusion.

| file | what it does | use it for |
|---|---|---|
| `tmp/lk_uot.py` | new TCP connection **per 1,200-byte request**; raw payloads | **UDP** rate/loss. For TCP it measures per-request reliability ONLY |
| `tmp/lk_stream.py` | one connection per stream, streams continuously, never reads | **TCP throughput** |
| `tmp/lk_throughput.py`, `tmp/lk_ss_harness.py` | encode **WRONG Shadowsocks framing** | **never** |

- The sidecar dials the forward's target directly as a plain UDP endpoint. **Send RAW payloads**;
  there is no Shadowsocks framing on this path. SS framing was the root cause of every earlier
  "100% loss" reading.
- `lk_uot.py`'s TCP payload rate is capped near `1200 B × req/s` (~0.5 Mbps at ~54 req/s)
  **regardless of link speed**. Every TCP number from it was misread as a throughput result.
- The target responder (`tmp/lk_target_pump.py`) replies **only when a 64 KiB read STARTS with
  LKT1**. A persistent client that reads 64 bytes per 1,200-byte frame leaves ACK bytes buffered,
  desynchronises, and sees an `empty_reply` storm (178,282 connection openings). A streaming
  client must **not read at all**.

**Mandatory run discipline (learned the hard way):** assert `sha256` + `--version` immediately
**before, during and after** every run, and refuse to report a result whose identity changed. A
run in this session was mis-attributed because the agent's auto-update replaced the candidate
mid-session. The hold mechanism (`LEIKWAN_UPDATE_HOLD_UNTIL` in
`/etc/systemd/system/leikwan-client-agent.service.d/zz-acceptance-hold.conf`) **expires** — re-arm
it before each window. Deploy with `cp` to a temp name then `mv` (a running binary gives
`Text file busy`).

**File transfer:** the chunked base64-pipe upload **silently produces 0-byte files**. Use
paramiko **SFTP** and verify size+sha256 as read back on the host.

---

## 5. PLATFORM STATE (verified this round)

| host | runtime | version / hash | agent |
|---|---|---|---|
| Linux 172.21.255.189 | `/usr/local/bin/ultra-client` | `2026.09.21.1.localcandidate` `fbf6d977…` | fixed `f6d7d945…` |
| macOS 100.103.253.21 | app helper | `2026.09.21.1.9b484db9` `a6c3b7b6…` | .NET 1.0.46 |
| Windows 172.21.255.4 | `C:\Program Files\LeiKwan Bridge Headless\bin\ultra-client.exe` | `2026.09.21.1.9b484db9` `3d45e438…` | service, RUNNING |

Windows proof: every runtime status file reports
`runtime_attestation.fingerprint=2026.09.21.1.9b484db9`,
`self_sha256=3d45e438416b2983a03206077d5929eef5b09e24b7ed90091f6625f1dcf847f4` (matches the file
on disk), and both `127.0.0.1:60501` and `127.0.0.1:60502` are LISTENING. Backups:
`C:\Users\lwtest\lk-backup\ultra-client.exe.old`.

**`lwtest` is now an administrator** (`BUILTIN\Administrators`, enabled) — the Windows blocker
that stopped an earlier subagent is gone.

Forward config: 201 (UDP, 60501) and 202 (TCP, 60502) both `transport=tcp+udp`, target
`70.36.96.183`. Production forwards on this client are 158/159/170/171 (service 11) and 180 —
**do not disturb them**.

---

## 6. STILL OPEN — in priority order

1. **Define the "no interruption" metric and re-run both platforms under it.** This is the only
   piece of the acceptance criterion never demonstrated. Decide whether macOS's 7.68 s max stall
   is real impairment or saturating-sender queueing (likely the longer path to the shared sidecar;
   the runtime reports rtt ~44 ms on macOS).
2. **Run Windows traffic acceptance.** The binary is deployed and attested; forwards 201/202 are
   `tcp_connect_failed` and need diagnosis (the Windows path to the test sidecar differs from
   Linux/macOS).
3. **Rate-limit a streaming TCP run to exactly 20 Mbps** for the literal criterion.
4. **Resolve macOS's 0.1068% UDP loss** — extend the drain window, or compare against a
   target-side received-packet counter.
5. **Panel/agent: a target change must issue a fresh lease or be refused** (see 2b). Also surface
   `awaiting_policy` as a health failure — today the panel shows such a forward as enabled while
   it has no listener, which is a silent outage.
6. **400 × `headers_eof` attribution** — from the 2026-09-20 handoff, untouched this session.
7. **All-directions E2E matrix** (`2026-09-20-uot-all-directions-test-matrix.md`) — only
   client→target was exercised.
8. **Sidecar binding auto-sync** — two pool gaps were fixed (`2eb02505`) but automatic binding
   sync was not addressed.
9. **Deploy the `wait_listener` fix to the macOS .NET agent** so `transport=udp` is safe there
   too. It is committed but the macOS host still runs the pre-fix agent; the `tcp+udp` config
   currently masks it.
10. **Local repo is still 13.6 GB** with the binaries in `master`'s 350-commit history.
11. Windows: `sc stop` returned `FAILED 1061` before succeeding — worth checking the service's
    shutdown handling.

---

## 7. Session commit log (branch `integration/ultra-enterprise-ha`)

```
633f417d (pushed tip)
88644489 fix(ultra): a UDP-only forward must not be probed with a TCP connect
efd3f967 fix(client-agent): stop stranding an Ultra forward that is not actually serving
792140ef docs(acceptance): transport=tcp+udp re-measure - UDP 95.99% -> 0.0% loss on macOS
d4a2de6b docs(acceptance): record the wait_listener root cause and its fix
7685b13e docs(acceptance): concurrent Linux+macOS 600s
2731519b docs(incident): Linux Ultra forward wedged in awaiting_policy with no listener
456f3d03 docs(incident): correct the awaiting_policy cause - a target change un-provisions it
98fc724a docs(warning): the Linux test host no longer runs the candidate build
81187c97 docs(acceptance): verified 600s on the pinned candidate
dfe75934 docs(acceptance): streaming TCP carries 173 Mbps
e026ff94 docs(acceptance): 600s streaming - TCP 150.6 Mbps, UDP 0.0% loss
7afab199 docs(acceptance): macOS 600s streaming - TCP clean, UDP 0.1% loss
9b484db9 chore(repo): stop tracking scratch/build output under tmp/, artifacts/, TestResults/
eb29c041 chore: codify Ultra Mode hard redline and refresh local artifact digests
```

## 8. Hard constraints that MUST be preserved

- **Never involve LeiKwan slave/CDN hosts** in Ultra work. Ultra path:
  `client -> leased Ultra provider relay -> target`. Do not read `cantons.txt`/`shanghais.txt`.
- Never commit or print credentials (`test-env.txt`, `uot-sidecars.txt`, `leikwan-v3.txt` are
  gitignored — keep them so).
- Do not bulk-copy this repo; a 5.59 GB clone twice froze the operator's machine.
