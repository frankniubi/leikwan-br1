# Enterprise UoT Zero-Loss Upgrade Plan Handoff (2026-09-19)

## Handoff Status

Latest continuation: `2026-09-19-enterprise-uot-continuation.md`.

Execution follow-up: `2026-09-19-enterprise-uot-execution-report.md` records a
partial implementation, three existing-release 12-minute baselines, aborted
upgrade canaries, and verified rollback. The upgrade remains **not accepted**.

**Original handoff status: diagnosis complete; remediation had not yet been
implemented or deployed. See the execution follow-up above for subsequent work.**

This handoff starts the next conversation. The current worktree is dirty and contains
unrelated local work. Do not reset, clean, or overwrite it. Treat `master` as the default
baseline for new work unless the operator explicitly chooses another branch.

## Scope and Topology

Operator clarification: all UDP in this upgrade and its acceptance must use
external UoT through the leased Ultra provider relay and sidecar. Do not run a
direct-UDP bypass baseline. The historical term "raw" below describes delivery
semantics inside UoT, not permission to bypass the sidecar.

- Repository: `C:\Users\frankniubi\Downloads\leikwan-br1`
- Current branch at handoff: `fix/ultra-probe-single-reader`
- Test hosts:
  - Linux JSTC: `172.21.255.189`
  - macOS: `100.103.253.21`
  - Windows: `172.21.255.4` / local machine was previously requested, but no valid listener
    acceptance result exists yet
- Local test listener: port `10001`
- Test protocol: Shadowsocks address-first, `none` encryption, aggregate target `10 Mbps`
- Ultra external-UoT topology is strictly:
  `client -> leased Ultra provider relay -> external UoT sidecar -> UDP target`
- Do not introduce LeiKwan slave/CDN hops into Ultra work.
- Credentials remain in local credential files and must not be copied into this document,
  commits, logs, or normal user-facing output.

## Confirmed Evidence

### 10-minute TCP + UDP test, 2026-09-18

| Host | TCP | TCP failures | Tunnel UDP loss | Direct UDP baseline |
| --- | ---: | ---: | ---: | ---: |
| Linux `172.21.255.189` | 7,407 / 7,680 OK | 273 / **3.555%** | **2.154%** | **16.7472%** |
| macOS `100.103.253.21` | 7,402 / 7,610 OK | 208 / **2.733%** | **0.2717%** | **15.5226%** |

TCP failures were primarily HTTP `502` and header timeouts. These are user-visible
data-plane failures even though TCP can retransmit individual segments.

### Linux local-drop evidence

Read-back artifacts:

- `tmp/10mbps-linux-20260918-combinedfix/summary.json`
- `tmp/10mbps-linux-20260918-combinedfix/snapshots.jsonl`
- `tmp/status-linux-after-combinedfix.json`

Observed during the run:

- `udp_queue_drops=1227`
- Linux UDP `RcvbufErrors` increased by approximately `266,562`
- `ultra-forward-170.service` stayed `active/running`, `NRestarts=0`

### macOS read-back artifacts

- `tmp/10mbps-mac-20260918-combinedfix/summary.json`
- `tmp/10mbps-mac-20260918-combinedfix/tcp-events.json`
- `tmp/10mbps-mac-20260918-combinedfix/snapshots.jsonl`
- `tmp/status-mac-after-combinedfix.json`

The macOS client was `1.0.44`, connected, and Forward 170 was running. The lower tunnel
loss does not establish zero-loss behavior; direct UDP still lost 15.5226%.

## Root Cause Assessment

### Confirmed implementation defects

1. Client-side external-UoT association uses a bounded channel and silently drops when full:
   `tools/ultra-client/external_uot.go`, `externalUoTAssociation.enqueue`.
2. Go sidecar uses the same non-blocking queue-drop behavior:
   `tools/uot-sidecar/server.go`, `uotSession.enqueueUDP`.
3. UDP socket receive buffers are insufficient under the measured burst/concurrency profile;
   Linux kernel counters prove local receive-buffer loss.
4. Queue drops are counted but are not recovered with sequence ACK/retransmission.

### Fundamental transport boundary

Raw Internet UDP cannot promise end-to-end zero loss. Enlarging buffers can remove local
loss but cannot guarantee delivery across relays, NATs, providers, or the target host.
If the product requirement is truly zero application loss, the product needs a reliable
datagram mode or a stream transport (QUIC/TCP), plus an application-level acknowledgement
from the destination side.

### Separate TCP issue

The 2.7%-3.6% TCP failure rates are not explained by UDP loss. Investigate relay/provider
502 generation, connection-pool saturation, candidate switching, and header timeouts as a
separate workstream. Do not hide them with unlimited retries.

## Upgrade Plan

### P0 - Stop silent local loss

1. Replace `default: drop` in client and sidecar queues with bounded backpressure or a
   deadline-based enqueue. A deadline must return an explicit error/metric, never silently
   discard the packet.
2. Add byte-based queue limits in addition to packet-count limits. Start with at least
   4,096 packets and a bounded memory budget; tune from measured queue occupancy.
3. Set and verify UDP socket `SO_RCVBUF`/`SO_SNDBUF` (Linux target 16-64 MiB where permitted).
   Add the equivalent macOS socket settings and record the effective kernel values.
4. Add counters and status fields:
   `queue_blocked`, `queue_timeout`, `queue_drop`, `socket_receive_drop`,
   `sequence_gap`, `retransmit_count`, `reconnect_replay_count`.
5. Add unit tests for full queues, deadline expiry, shutdown while blocked, and counter
   monotonicity.

### P1 - Reliable tunnel datagrams

1. Add a monotonically increasing sequence to every UDP data frame in both directions.
2. Add cumulative ACK plus selective ACK frames on the carrier.
3. Retain unacknowledged frames in a bounded send window; retransmit on timeout and after
   reattach. Resume from the last acknowledged sequence after carrier reconnect.
4. Deduplicate at the sidecar and client delivery boundary before writing to UDP sockets.
5. Expose window size, retry count, oldest unacknowledged age, and unrecoverable-frame count.
6. Make failure explicit: an exhausted retry budget must fail the reliable session, not report
   success or silently discard data.

### P1 - TCP reliability workstream

1. Classify every `502`, headers timeout, EOF, reset, and connect timeout by relay,
   candidate, lease hash, and target.
2. Verify provider relay connection-pool and concurrency limits under the same 128-worker
   test profile.
3. Bound retries with jitter and circuit breaking; preserve the first failure cause.
4. Add a known-good HTTP control target so target-side closes are not misclassified as relay
   failures.

### P2 - End-to-end product modes

- **Raw UDP mode:** report measured loss honestly; never label it zero-loss or reliable.
- **Reliable datagram mode:** use the P1 sequence/ACK/retransmit protocol. Zero loss is
  asserted only for the tunnel/application sequence, not an unacknowledged raw UDP target.
- **Guaranteed delivery mode:** use QUIC/TCP stream or deploy an owned reliable UDP shim at
  both endpoints with application ACKs.

## Acceptance Gates

Do not call the upgrade complete until all gates pass on Linux and macOS, then Windows:

1. 1-hour test at 10 Mbps TCP + UDP, with 128 concurrent TCP workers and the same packet
   profile used above.
2. Reliable-mode application sequence gaps: **0**.
3. Client/sidecar `queue_drop`, `queue_timeout`, and socket receive drops: **0**.
4. Reliable-mode retransmissions may be non-zero, but every frame is eventually ACKed or the
   session fails explicitly; no silent loss.
5. TCP `502` and header-timeout count: **0** against the known-good control target.
6. No unexpected process exit or service restart; read back process identity and runtime
   hashes before and after the run.
7. Repeat after a forced carrier reconnect and a policy-generation rotation; verify replay,
   deduplication, and no sequence gap.
8. Keep raw-UDP loss as a separate diagnostic result; it is not a reliable-mode pass/fail.

## Test and Evidence Layout

Create a new timestamped directory per run, containing:

- `summary.json`
- `tcp-events.json`
- `snapshots.jsonl`
- client and sidecar logs
- before/after socket buffer and kernel drop counters
- runtime status and binary hashes
- a machine-readable verdict with separate `raw_udp`, `reliable_udp`, and `tcp_control`
  sections

Never overwrite the existing `tmp/10mbps-*-20260918-combinedfix` evidence.

## Rollback and Safety

- Back up installed client bundles, sidecar binaries, configs, and service units before P0/P1
  deployment.
- Deploy to Linux first, then macOS, then Windows only after read-back and short canary.
- Roll back only the newly deployed artifacts if a canary regresses; do not reset the dirty
  worktree or delete unrelated `tmp/` evidence.
- Keep the current control-plane recovery changes separate from the reliable-UDP change set.

## Next Conversation: First Actions

1. Re-open this file and inspect `git diff` for the current dirty tree.
2. Run focused tests for `tools/ultra-client` and `tools/uot-sidecar` before editing.
3. Implement P0 queue/backpressure and socket-buffer instrumentation first.
4. Add deterministic unit tests, build artifacts, and perform a short Linux canary.
5. Only then design and implement P1 sequence/ACK/retransmit; do not claim zero loss from
   buffer tuning alone.

## Final Handoff Verdict

The current system is **not enterprise-grade zero-loss**. Linux has proven local UDP queue and
kernel receive-buffer loss; macOS has measurable tunnel loss and severe raw-UDP baseline loss;
both hosts have non-zero TCP application failures. The correct upgrade is a staged change from
silent-drop best-effort UDP to instrumented backpressure, then reliable framed datagrams, with
explicit end-to-end semantics for any true zero-loss promise.
