# Enterprise UoT execution record

**Historical first-attempt record.** Continued repairs and current evidence:
`2026-09-19-enterprise-uot-continuation.md`. Live-state and completion statements
below describe the end of the earlier attempt, not an ongoing continuation.

Date: 2026-09-18 UTC / 2026-09-19 Asia/Taipei.

## Verdict

**INCOMPLETE / NOT ACCEPTED. P0 + P1 + P2 are not all finished.**

Operator clarification: **all UDP must use UoT and the external sidecar**:
`client -> leased Ultra provider relay -> external UoT sidecar -> UDP target`.
There is no direct client-to-target UDP comparison in the executed Go harness:
it sends UDP only to the local Shadowsocks listener. The term `raw` means
best-effort delivery *inside UoT*, not UDP bypass. Future acceptance must verify
each platform's external-UoT configuration and sidecar session evidence; a
missing route attestation is not a pass.

There is a locally tested P0/P1 transport candidate, three completed 12-minute
existing-release baselines, and a successful short Linux reliable-mode canary.
The upgraded Linux long run failed its deployment gate and was aborted. macOS
and Windows were not upgraded. No enterprise zero-loss or completed fleet
acceptance claim is justified.

All temporary remote test services have been stopped, and Linux has been
restored to the original runtime. No panel, public release manifest, or desktop
package was published.

## Source and preservation

- Original dirty checkout was preserved on `fix/ultra-probe-single-reader`.
- Implementation worktree: `C:\tmp\leikwan-enterprise-uot-20260919`.
- Implementation branch: `feat/enterprise-uot-20260919`.
- Base: `master` at `72b086224ac3b5078fd1e536b0ee33056de64745`.
- Existing selected-egress compatibility changes in `tools/ultra-client` were
  carried from the original checkout's HEAD; unrelated desktop/homepage work
  was not imported.
- No commit or push was made. The initial incomplete ACK draft in the original
  checkout was removed before deployment; it is not the candidate implementation.
- Local evidence: `tmp/enterprise-uot-20260919/`.
- Machine-readable result: `tmp/enterprise-uot-20260919/verdict.json`.

## Implemented locally

### P0 candidate

- Default client/sidecar queues: 4,096 packets, with a 16 MiB byte budget.
- Cancellation-aware, 250 ms bounded admission instead of immediate silent
  full-channel discard.
- Explicit queue blocked / timeout / drop accounting.
- Requested 16 MiB UDP receive/send buffers, with effective-value readback.
- Linux per-socket receive-drop sampling; unsupported measurements remain
  unknown (`null`) rather than being reported as zero.
- Tests for deadline expiry, cancellation while blocked, byte reservations,
  oversize admission, and monotonic sidecar queue counters.

Remaining P0 gaps: macOS/Windows per-socket drop measurements, process-wide
memory admission, full loaded-host acceptance, and complete sidecar/admin
exposure of the new counters.

### P1 candidate

- Explicit HELLO negotiation of `reliable_datagram_v1`; legacy raw mode retains
  its frame order and HELLO_ACK encoding.
- Independent bidirectional data sequences; cumulative ACK plus 64-bit
  selective ACK.
- Bounded retained send window, timeout retransmission, reconnect replay and
  delivery-boundary deduplication.
- Retry/age exhaustion fails explicitly. Unknown reliable peer capability and
  loss of an already-attached peer session do not silently fall back to raw.
- A single reader/writer per carrier; client reconnection joins the old reader.
- Tests exercise selective ACK, lost downstream ACK, reconnect replay, stable
  sidecar UDP source port, duplicate suppression, failed UDP write not ACKed,
  forbidden mode downgrade, byte-window exhaustion and retry exhaustion.
- Additional TCP diagnostics preserve first/last setup failures and attach
  candidate/lease/relay/target hashes to relevant logs.

Remaining P1 gaps: successful 12-minute upgraded runs, all-platform integration,
  fault-injection and policy-rotation acceptance, concurrent-reattach stress,
  provider connection-pool investigation, and retry-jitter evaluation.

### P2 status

- `raw` and opt-in `reliable_datagram_v1` semantics are documented and enforced
  in the Go candidate.
- `guaranteed_delivery` is rejected rather than falsely promising delivery.
- **Owned destination application-ACK/stream adapter is not implemented.**
- Panel/desktop configuration delivery and a compatible canary artifact channel
  are not integrated.
- Successful UDP `Write` is the reliable tunnel delivery boundary, not proof
  that the receiving application consumed a packet.

### FEC and performance

FEC was not implemented, enabled or compared. There is no claim that it improves
this TCP-carried tunnel.

A Windows microbenchmark of the local mechanisms showed:

| Mechanism | Time | Allocated bytes/op | Allocs/op |
| --- | ---: | ---: | ---: |
| Reliable enqueue + ACK | 1,241–1,253 ns/op | 2,000 | 11 |
| Raw byte-budget enqueue | 605–661 ns/op | 1,392 | 2 |

These are isolated 1,200-byte single-entry microbenchmarks, **not** network
throughput, loaded-window CPU measurements, or a FEC comparison. A valid
before/after runtime CPU comparison was not completed.

## Completed 12-minute existing-release tests

Each receiver used its own `127.0.0.1:10001` Shadowsocks `none` listener.
TCP and UDP each requested 20 Mbps concurrently (40 Mbps combined), with 128
TCP workers. Data came from a temporary authenticated owned target, not a public
HTTP test service. TCP response length and SHA-256 were verified.

| Platform | TCP received | TCP successful / failed | UDP received | UDP unique / requested |
| --- | ---: | ---: | ---: | ---: |
| Linux | 20.248 Mbps | 13,568 / 0 | 8.521 Mbps | 639,095 / 1,500,000 |
| macOS | 18.443 Mbps | 12,345 / 6,608 | 8.566 Mbps | 642,426 / 1,500,000 |
| Windows | 20.150 Mbps | 13,480 / 392 | 8.487 Mbps | 636,541 / 1,500,000 |

macOS/Windows TCP failures were classified as `headers_eof`, not HTTP 502.
Their failure rates were 34.865% and 2.826%, respectively.

Measurement windows were 720 seconds; final receipts include connection drain:

- Linux: `2026-09-18T19:31:45Z` to final receipt `19:43:58Z`.
- macOS: `2026-09-18T19:31:46Z` to final receipt `19:43:59Z`.
- Windows: `2026-09-18T19:36:38Z` to final receipt `19:48:51Z`.

### Important baseline limitation

The initial harness did not record actual UDP sender counts or periodically
refresh its idempotent UDP control request. Thus UDP throughput is a measured
receiver result, but missing-versus-requested packets **cannot be attributed
specifically to the tunnel or used as a measured tunnel loss rate**. Target send
errors, association expiry, policy limits and path loss were not separated.
These baselines are not reliable-mode tests.

The retained harness source was subsequently improved with per-run identifiers,
10-second idempotent control refresh, sender completion counts and server-side
sent/error receipts. That version passed the short canaries, but did not
complete a new all-platform 12-minute acceptance cohort.

Windows initially lost a detached test process when SSH closed. That attempt
was discarded; the reported Windows test retained its SSH session for the full
duration.

## Linux candidate deployment and why it was aborted

1. Staged a separate candidate sidecar on Tokyo port `7444`, allowing only
   forward 170, with a runtime TTL and memory limit. Original `7443` was not
   replaced.
2. First Linux service override used `/root`; `ProtectHome=yes` correctly
   prevented execution. The original service was restored immediately.
3. Retried from `/usr/local/lib`, retaining `ProtectHome`.
4. Short reliable-mode canary passed: TCP **16/16**, UDP **1,041/1,041**, with
   the owned sender also confirming **1,041 sent, 0 send errors**. Linux
   effective socket buffer readback was **33,554,432 bytes** in each direction.
5. The long run could not remain stable: the agent compares the running inode
   against `/usr/local/bin/ultra-client`, causing `binary_current=False` and
   forced restarts about every 30 seconds.
6. A backed-up canonical-path retry was also rejected by the management plane:
   automatic updating restored the published binary hash. The candidate-only
   configuration then produced `runtime configuration unavailable`.
7. Both long candidate attempts were aborted. Scheduled live carrier-cut
   acceptance was cancelled; no successful policy-rotation/fault-injection
   acceptance is claimed.

Live short-canary client hash:
`94f2e12dcf3dc9113dae5c5975ccd947157fac0610ea709bdfae5cacbb151703`.

Live short-canary sidecar hash:
`5952ff0b0f02f97861bd184f828ead91d33fe94dc3345c14ad28eddf75f0644f`.

Final source includes later TCP diagnostic additions; those additions were not
part of the live short-canary artifact. Final local builds are separately
identified in `final-builds.json`.

Do not work around this by disabling runtime identity checks or inventing an
official artifact identity. The next deployment needs a real pinned canary
release/desired-state channel compatible with the agent updater.

## Recovery evidence

- Linux forwards 158, 159, 170, 171 and 180 were all read back active, each
  running the original SHA-256:
  `7f9f8d6c3e84105bb41cbee19e48a4c102a2e906805efdf08ee10529e789aa24`.
- Candidate service override was removed. Rollback timers were stopped after
  explicit restoration.
- Post-rollback Linux data-plane canary: TCP **16/16**, UDP **1,041/1,041**, with
  sender completion count verified.
- Original Tokyo sidecar remained active, PID `3072564`, `NRestarts=0`, on 7443;
  candidate 7444 was stopped. Original binary SHA-256:
  `bdc18d8efc81d28341449ef51c29792edcd6e5d32206fff470edae3715a32b09`.
- Both temporary owned target servers were stopped.
- macOS listener PID remained `66281`; Windows listener PID remained `11052`.
- Management connectivity did not require restarting workstation `conf32`.

## Verification and remaining work

Test receipts are under the evidence directory:

- `client-final-race.json` / `.log`
- `sidecar-final-race.json` / `.log`
- `transport-benchmark.json` / `.log`
- `linux-fleet-restored.jsonl`
- `sidecar-final-logs-and-recovery.txt`
- `linux-baseline-720.json`, `mac-baseline-720.json`, `win-baseline-720.json`

Final local verification: both Go modules passed their complete `go test -race
-count=1` suites. Both modules and the standalone acceptance harness passed
`go vet`. Ten shared transport files passed byte-for-byte parity verification.
Linux amd64, macOS arm64 and Windows amd64 client builds, and the Linux amd64
sidecar build, completed successfully. This is local verification, not upgraded
three-platform field acceptance.

The first full race run encountered the existing game-simulation report writer
because this was a sparse worktree without `.tmp`; after creating the scratch
directory, the full race suite was rerun. This was not treated as a green run.

Next execution order:

1. Integrate explicit, pinned canary artifact/config delivery with the Linux and
   desktop supervisors; preserve the normal attestation/update safeguards.
2. Complete per-platform metrics and process-wide resource bounds.
3. Re-run the corrected owned-target harness: baseline and candidate, exact same
   load, sender counts, socket drops, runtime hashes/PIDs, CPU/RSS and lease data.
4. Resolve macOS/Windows control-target EOFs separately from UDP.
5. Test carrier reconnect and policy rotation with zero unacknowledged residual
   window and no duplicate application delivery.
6. Implement an owned application-ACK endpoint before exposing guaranteed
   delivery, then optionally benchmark FEC against a stable non-FEC baseline.

Until these pass, keep the candidate out of the public release channel.
