# Enterprise UoT continuation

Evidence timestamps use 2026-09-18 UTC / 2026-09-19 Asia/Taipei.

## Current status

**Update, September 19:** the repaired Mac canary now passes the requested
720-second TCP + external-UoT UDP 20 Mbps gate. See
`2026-09-19-macos-uot-final-debug.md` for exact scope, source commits, failed
attempts, passing evidence, and cleanup. This does not imply a public release
or completion of the broader P2/FEC/fleet acceptance work.

**Further repairs implemented; final target-patched acceptance and cleanup completed.**
This document supersedes the live-state statements in the previous execution
record. It does not declare P0/P1/P2 or three-platform acceptance complete.

All new UDP data-plane tests use:
`localhost:10001 -> leased Ultra provider relay -> external UoT sidecar -> Shadowsocks-none target -> owned UDP test endpoint`.
No LeiKwan slave/CDN host or direct client-to-target UDP comparison is involved.

Source worktree remains `C:\tmp\leikwan-enterprise-uot-20260919`,
branch `feat/enterprise-uot-20260919`, based on master
`72b086224ac3b5078fd1e536b0ee33056de64745`. The original dirty checkout is preserved.

## Repairs added in this continuation

1. Verified that the deployed frozen Linux agent contains the existing
   `LEIKWAN_UPDATE_HOLD_UNTIL` implementation. A bounded root-local hold plus
   an independent rollback timer keeps the canonical canary binary installed
   without disabling runtime identity checks.
2. Fixed duplicate session creation during concurrent HELLOs, pending carrier
   replacement that could wait behind an unclosed carrier, and the sidecar's
   concurrent mutation of its UDP dialer. Added race regression tests.
3. Reliable counters now aggregate live and retired client associations.
   A one-packet health probe cannot replace the main data stream's counters.
4. Correctly reject an auth ACK followed immediately by EOF/reset as a failed
   handshake, before reading application bytes. Preserve valid short early
   application payloads. Added bounded candidate retry jitter.
5. Reset reconnect outage budgets only after a successful HELLO; merely opening
   TCP and failing HELLO no longer resets exponential backoff.
6. Desktop grant parsing carries `delivery_mode` into bootstrap and includes
   it in the runtime contract fingerprint. Unsupported explicit modes reach
   the new runtime for fail-closed rejection rather than dropping the sidecar
   grant. These managed-host changes are locally tested, not packaged/published.
7. Added a scoped patch to the owned target's sing-box 1.13.12 UDP output:
   4,096 packets / 16 MiB, bounded 250 ms admission, explicit failure instead
   of a silent successful drop, socket buffer requests, and shutdown cleanup.

### Why the target repair matters

The macOS 720-second run before this target repair sent 1,500,000 data packets
and received 1,499,967. The sidecar's corresponding final cumulative ACK was
1,499,972: exactly the received data plus five completion receipts. The reliable
window drained, with no queue timeout, unrecoverable frame, or sequence gap.
The missing 33 packets therefore precede the sidecar's reliable send window.

The target identifies as sing-box 1.13.12, revision
`1086ab2563320e0da0c23b3a491d8dfa0939dff4`. That upstream version's
`common/listener/listener_udp.go` silently drops on a full 64-packet output queue.
Its Shadowsocks inbound calls this writer. The queue defect is confirmed in
source; attributing every historical missing packet specifically to that queue
still requires the target-patched comparison.

Two initial packet captures were invalid because the diagnostic allocated
large scratch buffers per packet and dropped capture packets. They are **not**
loss evidence. The corrected bounded, kernel-filtered capture recorded 32,272
packets on each UDP leg, zero interior sequence mismatch and zero capture drops,
but covers only a short part of the run. Do not extrapolate it to all 720 seconds.

## Completed upgraded-client runs before the target patch

Each row is 720 seconds, 128 TCP workers, TCP and UDP requesting 20 Mbps each.

| Platform | TCP success / failure | TCP Mbps | UDP received / sent | UDP Mbps |
| --- | ---: | ---: | ---: | ---: |
| Linux, revision 3 | 13,568 / 0 | 20.248 | 1,500,000 / 1,500,000 | 19.995 |
| macOS, revision 3 | 13,440 / 0 | 20.116 | 1,499,967 / 1,500,000 | 19.993 |

An earlier revision-2 Linux run had 2,968 TCP EOF failures and 427 missing UDP
packets. Preserve that failed result; it is not the repaired revision-3 verdict.
The macOS revision-3 run included diagnostic capture load on the target.

## Artifact identities

| Component | SHA-256 |
| --- | --- |
| Linux client revision 3 | `4724efc251b3d9625a913cb5bef86a9cd18c3aa069c6eb00ebe0eab16fcbacc9` |
| macOS arm64 client revision 3 | `34063140a30941af2374c65c75fd3b4bbcedffa27a8cffbe43b78f55461f549b` |
| Windows client revision 3 | `b6f997d874743d80286c47409c86368ad372a6271e928249b4e6bb935b85753b` |
| Linux sidecar revision 3 | `45140c566c59158dbd16c6c41273f9f5836eb69b0565187101878b9e9b93474a` |
| Scoped patched SS target | `606559fc9225e27470f3068a2d7bc51ffceb838e0df6e18865551c763b311b67` |

The SS target source patch and tests are exported under
`tools/uot-acceptance/patches/` in the implementation worktree.

## Windows route and managed-host corrections

`lwtest` cannot replace the administrator-owned IPv4 port-10001 helper. That
existing runtime has no external-UoT grant and remains untouched. Its historical
baseline is not sidecar route evidence.

The canary instead binds **[::1]:10001**, using the actual Windows client device
identity and its own authentic panel leases. This is the same requested port,
not an alternate-port test, but it is **not acceptance of the existing managed
IPv4 listener**. The panel requires `X-Device-Id` on both pull and lease requests.
The isolated driver refreshes leases every 30 seconds, samples CPU/RSS/status,
and removes its private bootstrap and stops its own processes in `finally`.

The real API attaches `external_uot` to `forwards[].ultra_access` on pull, but
omits it from the lease response. Desktop `MasterApiClient` now caches that
material only in memory, binds it to forward/generation/target/expiry, and
clones it into leases. Tokens are zeroed on replacement/disposal. An enabled
invalid lease grant now fails closed instead of reverting to native UDP.
Raw-mode bootstrap preserves legacy strict-decoder compatibility; non-raw
modes remain explicit and require the upgraded runtime.

Native Windows transport/socket tests pass. A 25-second isolated sidecar
preflight passed TCP 512/0 and UDP 52,083/52,083. The repaired 720-second test passed TCP 13,568/0 and UDP
1,500,000/1,500,000. The first long attempt aborted on an HTTP 409 lease-scope
race; that failed attempt is preserved. The test driver now performs at most
three fresh-pull/new-nonce attempts for HTTP 409 only. The successful rerun did
not exercise that retry (zero `LEASE_SCOPE_REFRESH` events).

## Revision 4 and renewal starvation

External-UoT lease renewals now revalidate authenticated sidecar HELLOs even
within the same generation. Only verified candidates enter the fallback pool;
a failed candidate probe cannot become a later TCP fallback. Sticky healthy
paths remain preferred. Client hashes:

- Linux: `97fc459dc96a57566a053c84e142ba22e555c8d0246ea1cad238e69a1729c44d`
- macOS: `79cb04ba6688bff65a95df73c5e0fd69a9a4cc0743e38f71d93cde623b665d46`
- Windows: `5491d4e6eab9caa8480ca5f512ac17038c86ec8b7fd4d1ea9c0041da1da7005e`

The target-patched revision-3 Mac run recovered all 1,500,000 UDP packets but
had 18,130 TCP header EOF failures during candidate rotation. It failed.
The first revision-4 Mac run also recovered all UDP packets but had 691 TCP
failures during the overlapping Windows preflight. That overlap is a possible
confounder, not proof of a provider concurrency limit.

A subsequent strictly sequential Mac run failed without Windows load:
TCP 11,520 successes / 61,175 failures and UDP 378,226 received versus 1,500,000
requested, with no final sender receipt. At 05:55:54 Asia/Taipei the runtime
logged `policy has been missing for 1m30s; stopping the runtime` and was later
restarted by its host. That run is a failure, not a zero-loss sample.

The desktop polling code could indefinitely skip an unchanged healthy sibling
while other forwards repeatedly triggered targeted recovery. New desired
policies did not imply new leases were delivered to the skipped runtime.
The fix tracks the deadline of the last **successful runtime delivery** and
never skips a sibling whose actual installed lease is due. The deadline keeps
one minute for polling/probing and honors an earlier policy renewal point.
A controlled-clock regression covers both skipping a not-yet-due sibling and
refreshing a due sibling. Full Core suite: **584 passed** (one pre-existing
xUnit duplicate-case warning). The master-only Core build passed those tests, but swapping it into the
installed 1.0.44 executable failed first on AssemblyVersion and then on missing
logging types. Neither failed deployment started a long-run test. The private
canary build therefore includes the exact installed-source compatibility delta
from `c5da15b1d53c4ad755f7a13f8054cb23a6fb1732`, kept in a separate patch rather
than mixed into the master feature. Its full Core suite also passed 584 tests.
The compatible Core DLL SHA-256 is
`79f4e2d0148bd36d4641b64577e43e87276444ba1b9c48f682d56e202b962e50`.
The copied canary bundle started successfully and passed its 25-second preflight.
The original signed App was not overwritten.

## Verification and remaining gates

### Revision 5: retain a healthy path across authorization rotation

The compatible-Core Mac 720-second run no longer exited on policy starvation:
PID 90578 stayed stable and UDP delivered 1,500,000/1,500,000. It still failed
TCP: 12,461 successes / 5,407 header EOF errors, 18.604 Mbps. All 5,411 recorded
zero-byte downstream resets in that window belonged to candidate `b2baf35...`,
after rotation away from `977887...`; the ordinary short clean EOF probes
are distinct from those resets. This is preserved as `mac-corefix-720.json`,
not relabeled a pass.

Revision 5 keeps an already healthy physical/logical path across a new policy
generation only if the **newly authorized lease** passes its current HELLO
probe and target digest, transport, route scope and route policy remain
identical. It never retains withdrawn paths or old credentials. Tests cover
rotation, withdrawal and changed target scope; the full client and sidecar
race suites pass.

- Linux client: `a7164ae3782a35fb5d1f77e04279fdfbce2b4df36b0eeee1a858eefdc8653fe3`
- macOS client: `7a42b635442ed54a983edbcdc5137516c3b68b0b668dbc9d14e59f4f6040c8ca`
- Windows client: `97bc896ecb0958eba89443ae4eb03191f95837540da3b4a440dc88f363740fe6`

### Forced carrier reconnect, revision 4

At `2026-09-18T22:57:02Z`, `ss -K` reset only connections to the canary sidecar
port 7445 during a 60-second Linux test. The sidecar process/session state was
not restarted. TCP passed 1,152/0; UDP passed 125,000/125,000. The interrupted
session retained 96 frames / 115,872 bytes, replayed 96 frames, performed 64
retransmissions, reached ACK 125,005 (including completion records), and
drained its window with zero unrecoverable frames. No native-UDP bypass was
used. See `carrier-fault-counters.json` and `sidecar-ssfix-fault-stats.txt`.

### Remaining acceptance work

- Full Linux race suites: client and sidecar PASS.
- Sidecar concurrent/reconnect focused stress and new regression tests PASS.
- Managed-host full Core suite: 584 PASS; see revision-4 section above.
- Windows and macOS native transport/handshake tests PASS.
- SS target queue regression suite: `go test -race -count=5 ./common/listener` PASS.
- All ten intentionally shared Go transport files remain byte-identical.
- P2 guaranteed application delivery adapter is still unimplemented:
  `guaranteed_delivery` is rejected, never silently downgraded.
- FEC is not enabled or compared.
- Per-socket receive-drop counters on macOS/Windows remain unknown, not zero.
- Production artifact-channel publication, policy-generation rotation, and
  full three-platform acceptance remain open.

Final target-patched long-run results, CPU/RSS summaries, forced-carrier tests,
and cleanup/readback are to be appended below after execution.

## Revision 7 continuation: separate user TCP from UoT health

Revision 5's long runs passed on Linux and isolated Windows, but macOS still
failed: 13,084 TCP successes / 7,544 errors, approximately 19.55 Mbps, while
UDP delivered all 1,500,000 packets. Keeping an authorized healthy candidate
across policy renewal does not fix starting on a failing provider candidate.

Revision 6 temporarily quarantined candidate
`b2baf35de58c8a5b056a494fcaeb9692ab197fe22a69da56f0e96772114c947e`
for both protocols. This was **not successful**: it also removed a usable UoT
path. The macOS run was aborted at host-recorded `2026-09-18T23:43:06Z` after
a reliable-window admission failure (one unrecoverable frame event). It is
not a 720-second sample; Linux and Windows revision-6 long tests did not run.
The failed experiment remains in `mac-v6-aborted.json`.

Revision 7 adds a bounded, forward-local **TCP-only** operator quarantine and
separates logical UDP/UoT candidate health from user TCP health. The UoT
carrier remains TCP through the leased provider and external sidecar:
there is no native UDP fallback. Successful UoT HELLO no longer clears user
TCP failure state or overwrites its active-path display. New carrier logs
tie the sidecar session ID to the actual candidate, lease and target hashes.
Both primary dialing and active-path rescue respect the TCP quarantine;
its expiry is at most 24 hours and no candidate hash is hard-coded in product
source. A passing run with this setting is conditional on that restricted TCP
pool, **not proof that the excluded provider path was repaired**.

The sidecar also stops its UDP reader after fatal reliable admission failure.
Previously that reader could continue producing a warning per packet after the
session was already irrecoverable. Socket closure and reader completion are
separated to avoid the reader waiting on itself. Race regressions cover this,
protocol-specific quarantine, expired scope, native CONNECT, active rescue,
and HELLO feedback attribution.

Latest full race tests: client PASS (159.855 s), sidecar PASS (2.609 s).
The separate sidecar repeated race suite also passed five iterations.
The compatible managed Core remains the 584-test-passing build described above.

Revision-7 deployed binary hashes:

| Component | SHA-256 |
|---|---|
| Linux client | `e4847aebcc41354d4f07c3d2aa42def0195d6155db1d2f6d0cff2818cd75f740` |
| macOS client | `3879cafd934502836e68e3c96da9b0aa77cb2d4e11ee6823133f63b90d0b7dcf` |
| Windows staged client | `959e59aa7be1cfc16cc72665d255e8743f2a691b05713954dce3a232b22371f3` |
| Private sidecar | `d7523e9db259a92f6eed92c930d86ce315c963790bc86cb1dec5439a54aed0b4` |

The private transient sidecar/endpoint units disappeared after being stopped;
the first restart attempt failed explicitly. They were recreated with the
same private endpoints, bounded 90-minute lifetime and sidecar memory ceiling.
This did not modify the public sidecar. Independent rollback timers were
rearmed before the new short test.

### Revision 7 short test and revision 8 setup retry

The revision-7 macOS 120-second test was **FAIL**, not promoted to long-run
acceptance: UDP 250,000/250,000 with zero reliable queue failures, but TCP
2,303 successes / one header-stage connection reset. The failed request lasted
3,002 ms. Runtime logs also recorded a setup timeout on the remaining candidate;
no conclusion about the provider's underlying timeout cause is made.

Code inspection and a failing regression exposed a local recovery gap:
with exactly one eligible TCP candidate, one capped setup timeout returned
failure even when the original total setup deadline still had spare budget.
Revision 8 allows **one** jittered setup retry on that same authorized path,
only for timeout errors, before reading user payload. It does not retry EOF
or protocol rejection, extend the total deadline, reuse revoked authority, or
replay established application traffic. Tests reproduce the former one-attempt
failure, then cover recovery, bounded exhaustion, non-timeout errors and
revocation. This change is not a fix for arbitrary midstream provider resets.


## Revision 8 final acceptance and cleanup

Revision 8 includes the bounded single-candidate TCP setup retry described above.
The new client and sidecar race suites passed: client 159.835s, sidecar 2.594s.
Shared transport verification passed (10 files byte-identical), and export patch
reverse-check passed (`candidate-final-manifest.json`, SHA-256
`0b77ed659f58af4d69071c6d0a949c4a478d9848b5b9257187ba54d35a0341da`).

The requested 720-second runs used `localhost:10001`, with every UDP flow
forced through external UoT: leased provider relay -> private sidecar -> owned
UDP endpoint. No native UDP or direct client-to-sidecar path was used.

| Platform | TCP | UoT UDP | Result |
|---|---:|---:|---|
| macOS | 7,907 successes / 153,916 header EOF/errors, 11.731 Mbps | 1,500,000 / 1,500,000, 19.994 Mbps | **FAIL TCP** |
| Linux | 13,568 / 0 errors, 20.244 Mbps | 1,500,000 / 1,500,000, 19.995 Mbps | **PASS** |
| Windows isolated `[::1]:10001` | 13,568 / 0 errors, 20.252 Mbps | 1,500,000 / 1,500,000, 19.996 Mbps | **PASS (isolated scope)** |

Linux runtime remained one PID with zero queue drops/timeouts, sequence gaps,
unrecoverable frames and socket drops; effective UDP buffers were 32 MiB.
Linux client CPU was 117.8% of one core and peak RSS 85.95 MiB. Private sidecar
CPU was 12.48% of one core and peak RSS 41.72 MiB. Windows runtime metrics
were not available after the host cleanup query failed; its application summary
and target-side metrics passed. macOS socket receive-drop counters remain unknown.

A 60-second forced carrier reconnect after the long runs passed: TCP 1,152/0,
UDP 125,000/125,000, with zero reliable queue/sequence/unrecoverable counters.

Cleanup completed. The original macOS signed app was restored (hashes
`cfd542e9fb69a84446c3a0552fcf6041406b004401dba3b0cb9d64a5cc2f3209` and
`4032d3bc5cd69e7fc18d0b957d20dd8e9e7182d8f95f5f90fdb43389704abff0`). Linux
restored the original client (`7f9f8d6c3e84105bb41cbee19e48a4c102a2e906805efdf08ee10529e789aa24`);
all five forwards were active with `NRestarts=0`. Tokyo restored the original
Shadowsocks/UoT binaries and both services were active with `NRestarts=0`.
The Windows private pull file was removed; the post-cleanup CIM listener query
was unavailable (`CimJob_BrokenCimSession`), so Windows listener readback is
not claimed. `conf32` stayed up throughout; no WireGuard restart was necessary.

Overall status remains **NOT_FLEET_ACCEPTED**: macOS TCP is unresolved,
Windows process/socket counters are incomplete, P2 guaranteed application ACK
mode is not implemented, and FEC was neither implemented nor benchmarked.

## Linux/Windows commit and Mac quarantine experiment

The Linux/Windows-capable source was committed in the separate master-based
worktree as `d5876de6` (`feat(uot): harden enterprise UoT client and sidecar`).
It was not pushed and does not include operational scripts, credentials,
temporary acceptance output or Mac-only runtime artifacts. Verification on that
worktree passed ultra-client, uot-sidecar and Desktop Core (584/584) tests.

To isolate the remaining Mac failure, a private Mac canary was rebuilt with the
operator TCP quarantine removed while keeping UDP external-UoT through the same
sidecar and owned target. The result changed materially:

| Mac experiment | TCP | UDP |
|---|---:|---:|
| TCP-only quarantine, 720 s | 7,907 OK / 153,916 errors, 11.731 Mbps | 1,500,000 / 1,500,000 |
| No operator quarantine, 720 s | 13,440 OK / 18 header EOF, 20.091 Mbps | 1,499,965 / 1,500,000 |

This is strong evidence that the TCP-only quarantine was too aggressive for
Mac's observed candidate behavior: excluding `b2baf35...` removed capacity or a
needed fallback path, while the unrestricted candidate pool restored nearly
full TCP throughput. It is not evidence that the excluded relay is always
healthy, nor is the no-quarantine run a pass because it still had 18 TCP EOFs
and 35 UDP target losses. The experiment was stopped and the original Mac
runtime restored after the run.

A follow-up 720-second no-quarantine attempt was invalid as a product verdict.
The temporary `exp2` sidecar/target units were started, but the run ended with
`tcp_ok=9477`, `tcp_failures={body_eof:2555, connect_error:33054,
headers_eof:2083, headers_error:41696}`, `tcp_received_bps=16.417 Mbps`, and
only `1712/1500000` UDP packets received. Tokyo target logs confirmed all
`1500000` UDP packets were sent. The exp2 units were stopped and
`rollback-mac.sh` restored the Mac process; readback confirmed the restored
LeiKwan Bridge instance and `127.0.0.1:10001` listener. No source or
Linux/Windows commit was changed based on this invalid harness run.
