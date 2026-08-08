# UoT Feedback-Driven Fair Scheduler Design

**Date:** 2026-08-08
**Status:** Design approved; intentionally left uncommitted at the user's request
**Base:** `master` at `11550550b3c6c36d0878443c79dd5814aa260a48`
**Branch:** `codex/uot-feedback-scheduler-20260808`
**Worktree:** `C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\uot-feedback-scheduler-20260808`

---

## 1. Goal

Build a UoT scheduler that serves both of these workloads at the same time:

1. many independent WireGuard/UoT sessions; and
2. many inner TCP/UDP flows inside one WireGuard session.

The scheduler must preserve multi-leg aggregation while bounding queue delay and
loss. It must not use single-leg session affinity as the default speed mode.

The approved 60-second acceptance load is:

- 64 concurrent UoT sessions;
- 32 inner flows in the single-WireGuard-session test;
- 300 Mbps aggregate offered load;
- at least 270 Mbps useful aggregate throughput;
- scheduler-added p99 queue delay at or below 10 ms;
- scheduler queue drops at or below 0.1%;
- zero UDP socket send errors;
- no complete zero-throughput second; and
- Jain fairness index at or above 0.90.

When demand exceeds proven path capacity, the degradation order is locked:

1. keep queue delay bounded;
2. shed excess load fairly and explicitly;
3. preserve control traffic and healthy sessions;
4. use all remaining path capacity;
5. never trade unbounded memory or latency for an attempt at zero drops.

## 2. Hard Constraints

- Preserve topology `client -> slave -> sidecar`.
- Do not add direct client-to-sidecar connectivity.
- Slave MPTCP and bond ingress ports remain inside `7000-9000`.
- Port `20013` must remain continuously available during any future canary or
  rollback.
- Production is not changed by this design/spec phase.
- The feature is off by default and requires explicit stage configuration.
- Existing legacy peers must continue to connect without receiving an unknown
  frame type.
- FEC remains disabled by default and is not part of the first implementation.
- Relay-level replay/retransmission is out of scope for the first implementation.

## 3. Validated Baseline And Evidence Strength

The existing experimental branch
`codex/leigod-unordered-dgram-bond-20260807` confirms these code-level facts:

- the current selector sees local queue occupancy, not remote delivery;
- queue credit is released before a TCP write completes;
- independent TCP legs create independent head-of-line blocking domains;
- unordered delivery exposes cross-leg arrival differences to WireGuard;
- final UDP `send()`/`send_to()` results are not consistently checked;
- equal empty local leg queues rotate traffic across legs;
- TCP leg sockets use `TCP_NODELAY` in the experimental branch; and
- a single central dispatch path remains a potential, but unprofiled, hot path.

The reported 197 Mbps UDP result and 106-117 Mbps inner-TCP results are historical
handoff observations. The branch does not retain authoritative raw JSON, pcap,
CPU profile, socket-drop capture, or a repeated randomized A/B data set. They are
motivation and baseline candidates, not proof that one specific bottleneck is the
sole root cause.

The more accurate causal model is:

```text
per-datagram striping across independent TCP legs
  + per-leg path delay and TCP head-of-line variance
  + unordered immediate delivery
  + insufficient remote progress information
  -> reordering and stale delivery exposed to WireGuard
  -> inner TCP retransmission/congestion response
  -> unstable useful throughput and latency
```

Remote progress feedback is therefore a high-value control signal, not a claim
that feedback alone can make independent TCP connections arrive in order.

The clean `master` worktree baseline was verified before writing this spec:
`cargo test -p leikwan-uot` passed 11 tests with 0 failures.

## 4. Chosen Approach

The chosen design is a feedback-driven bounded aggregator with a legacy local
queue fallback.

### Rejected: local shaping only

Local hierarchical pacing and DRR improve fairness and bound userspace queues,
but they still cannot distinguish a leg whose local socket accepted data from a
leg whose receiver actually decoded and delivered it.

### Rejected: full SACK/NACK and relay retransmission first

This adds replay caches, deduplication, retry timers, and a second reliability
layer underneath WireGuard/inner TCP. It raises the risk of stale delivery,
duplicate work, memory growth, and new head-of-line dependencies before the
scheduler has basic delivery telemetry.

### Selected: feedback-driven DRR and bounded outstanding windows

The selected design keeps unordered multi-leg delivery, adds negotiated per-leg
progress telemetry, schedules contiguous chunks using predicted completion time,
and bounds both queue age and remote outstanding bytes.

## 5. Master-Based Development Baseline

New work starts from `master`, not the 80-commit experimental topic branch.

The implementation plan may selectively port these already-tested UoT commits,
in this order, after verifying each cherry-pick against `master`:

1. `5714195` - unordered WireGuard delivery contract;
2. `a8cf5d5` - unordered delivery primitives;
3. `7446343` - WireGuard unordered runtime and bounded queue behavior;
4. `7aa8c88` - bounded reorder timeout flushing;
5. `6531145` - session IDs separated across process epochs; and
6. `e44c987` - `TCP_NODELAY` on leg sockets.

The FEC commits beginning at `fe0a7df` are not ported in the first phase. Agent
and generated-config integration is implemented against current `master` under
new tests rather than importing unrelated topic-branch history.

If any listed commit depends on FEC or unrelated runtime history during an actual
cherry-pick, the plan must reproduce only the required behavior with test-first
changes instead of expanding the import set.

## 6. Architecture

```text
UDP receiver(s)
  -> bounded per-session admission queues
  -> deficit round robin (DRR)
  -> aggregate token-bucket pacer
  -> feedback-driven contiguous-chunk scheduler
  -> per-leg bounded writer queue
  -> TCP leg
  -> remote frame decode
  -> remote UDP socket acceptance
  -> negotiated LegProgress feedback
  -> scheduler updates delivery rate, RTT, outstanding, and leg state
```

The scheduler is an actor that exclusively owns session queues, leg scheduling
state, outstanding accounting, and aggregate statistics. UDP receivers and leg
writers communicate with it using bounded channels. The hot path does not take a
shared `Arc<Mutex<...>>` for every packet.

The first implementation uses one scheduler actor per UoT runtime. Its root rate
is therefore per runtime/path group. Two independent runtimes must not share one
physical LeiGod path unless control-plane quotas ensure that their configured
aggregate rates sum to no more than the path budget. A cross-process global pacer
is not part of this first implementation.

## 7. Session And Path Identity

A scheduler session key is never a bare `session_id` or peer address. It is:

```text
SchedulerSessionKey {
    scope_key,
    session_id,
    direction,
    process_epoch,
}
```

`scope_key` retains the existing isolation inputs: `forward_id`, target host,
target port, and token fingerprint. The client side uses the equivalent forward
scope plus local peer mapping. A reconnect creates a new leg connection epoch;
an application session restart creates a new process epoch.

This prevents counters, rate limits, feedback, and queue state from leaking
between tenants, forwards, directions, or reused numeric IDs.

## 8. Capability Negotiation

### 8.1 Extended Auth payload

The existing JSON Auth payload is extended without removing legacy fields:

```json
{
  "token": "existing-token",
  "endpoint_id": 184,
  "weight": 8,
  "protocol_version": 2,
  "capabilities": ["leg-progress-v1"],
  "connection_epoch": 123456789
}
```

Legacy sidecars already parse known JSON keys and ignore additional keys. A new
client may therefore send this payload to an old sidecar safely.

### 8.2 New frame types

Two frame types are added after existing values:

```text
AuthAck     = 6
LegProgress = 7
```

Frame type `5` remains reserved for `FecParity` compatibility with the prior
experimental protocol even though FEC is not ported in the first implementation.
The generic `DataFrame.version` envelope remains version 1; capability and
progress versions are carried inside their validated payloads.

A new sidecar sends `AuthAck` only when the authenticated peer explicitly
requested `leg-progress-v1`. An old client never requests it and therefore never
receives an unknown frame type.

The client starts in legacy scheduling without blocking data. Receipt of a valid
`AuthAck` with the matching epoch enables feedback mode. If no valid Ack arrives
within 1 second, that leg remains in legacy mode. A later valid Ack may enable
feedback without reconnecting.

`process_epoch` is local scheduler identity and is not transmitted. It remains
stable for one runtime process. `connection_epoch` is wire-visible and changes
for every TCP leg reconnect so delayed Ack/progress from an older connection
cannot mutate the new leg.

Feedback activation is fail-closed per scheduling scope: all currently active
legs for that scope must negotiate `leg-progress-v1` before bulk traffic uses the
feedback scheduler. If any active leg is legacy/unacknowledged, the whole scope
uses the unchanged legacy selector. A late valid Ack may atomically promote the
scope once all active legs qualify; an unacknowledged reconnect demotes it back
to legacy. This avoids mixing feedback-comparable and opaque legs in one
predicted-finish calculation. Per-leg capability counts remain observable.

`AuthAck` is one-time JSON because it is not a hot-path message:

```json
{
  "protocol_version": 2,
  "capabilities": ["leg-progress-v1"],
  "connection_epoch": 123456789
}
```

### 8.3 LegProgress payload

`LegProgress` uses a fixed 44-byte network-byte-order binary payload:

```text
u8  progress_version       = 1
u8  direction              = 0 (client-to-sidecar) or 1 (sidecar-to-client)
u16 reserved               = 0
u64 connection_epoch
u64 decoded_frames
u64 decoded_wire_bytes
u64 udp_accepted_frames
u64 udp_accepted_wire_bytes
```

The enclosing `DataFrame` uses `session_id=0` and `seq=0`. The connection itself
identifies the leg. Progress values are cumulative and monotonic for one epoch.

Invalid version, reserved bits, direction, epoch, regression, or counters beyond
locally sent totals are rejected and counted as `invalid_progress` without
changing scheduler state.

### 8.4 Feedback timing and RTT

Each receiver emits progress after the first of:

- 64 KiB newly decoded wire bytes;
- 32 newly decoded data frames; or
- 5 ms since the previous progress frame while progress exists.

Control frames use a dedicated bounded high-priority queue. They cannot wait
behind the bulk data queue, but the queue remains bounded so a faulty peer cannot
create unbounded control memory.

No synchronized clocks are required. The sender stores timestamp checkpoints for
its cumulative sent-byte positions. When progress crosses a checkpoint, elapsed
local monotonic time becomes a progress RTT sample.

Feedback is scheduler telemetry. It does not acknowledge end-user application
receipt, gate unordered delivery, or trigger automatic data replay.

## 9. Scheduler Queues And Fairness

### 9.1 Admission

Incoming datagrams first reserve both global and per-session packet/byte capacity.
Admission records `enqueue_time`, `deadline`, payload bytes, wire bytes, session
identity, and WireGuard control classification.

Approved initial targets:

```text
queue_target_ms              = 5
queue_deadline_ms            = 10
global_queue_bytes           = 1 MiB
global_queue_packets         = 8192
per_session_queue_bytes      = 256 KiB
per_session_queue_packets    = 512
max_sessions                 = 4096
```

The deadline, not queue capacity, is the latency boundary. Larger memory limits
must not permit delivery of data older than 10 ms.

### 9.2 Deficit round robin

Active session queues are served with DRR using wire bytes as cost. The initial
quantum is `16 KiB * session_weight`; the default weight is 1. Empty sessions do
not accumulate unbounded deficit. Deficit is capped at four quanta.

This provides fairness across many sessions without preventing one session from
using idle capacity. Jain fairness is calculated over active sessions with
offered demand during the measurement window, excluding idle sessions.

### 9.3 Root pacing

Feedback mode uses a root token bucket:

```text
aggregate_rate_bps = 300,000,000
burst_bytes        = max(max_wire_frame_bytes, rate * 2 ms)
```

For the approved target this is approximately 75 KiB, but the actual value must
always fit one maximum permitted wire frame. Tokens are consumed when a frame is
scheduled to a leg. A timer wakes the scheduler at the next refill time; the
scheduler never busy-waits.

Per-session hard rate caps are optional control-plane policy and are not enabled
by default. DRR supplies fairness; the root bucket bounds aggregate offered load.

## 10. Feedback-Driven Multi-Leg Aggregation

### 10.1 Contiguous chunks

A single session remains eligible for multiple legs. The scheduler groups
consecutive session frames into bounded chunks:

```text
chunk_min_bytes     = 16 KiB
chunk_target_bytes  = 32 KiB
chunk_max_bytes     = 64 KiB
```

Chunk thresholds count payload bytes. DRR and pacing always charge full wire
bytes. A UDP datagram is never split merely to meet a chunk boundary, so one
maximum 65,535-byte payload remains valid while its framing overhead is charged
separately.

A chunk closes at the target size, session queue exhaustion, deadline pressure,
or a state change in the assigned leg. It never waits for more data past the next
packet deadline.

### 10.2 Outstanding windows

For a feedback-capable leg:

```text
target_window = clamp(
    delivery_rate_ewma * (progress_srtt + 10 ms),
    256 KiB,
    4 MiB
)
```

The sum of all per-leg outstanding windows is capped at 8 MiB by default. These
windows are independent of the short ingress queue and token-bucket burst.

Dynamic windows activate only after two valid progress/RTT samples. Before that,
the leg is in `Warmup` with a 256 KiB window and small probe chunks.

`decoded_wire_bytes` measures transport progress. `udp_accepted_wire_bytes` is
the primary credit release point because it proves that the remote UDP socket
accepted the datagram. The distinction is preserved in metrics so UDP egress
pressure can be separated from TCP/LeiGod path pressure.

### 10.3 Leg score

Eligible legs are ranked using predicted completion time:

```text
service_time = outstanding_wire_bytes / max(delivery_rate_ewma, bootstrap_rate)
predicted_finish = now + service_time + progress_srtt / 2 + write_stall_penalty
```

The scheduler assigns a new chunk to the lowest predicted finish time whose
window has room. It does not select a leg if its predicted finish exceeds the
healthy-leg median by more than the configured finish-skew budget (initially
2 ms), unless no other leg can accept the chunk before its deadline.

This bounds avoidable cross-leg skew while still permitting parallel aggregation.
It does not promise perfect arrival order.

## 11. Leg State Machine

```text
Connecting -> Warmup -> Healthy -> Lagging -> Quarantined -> Probe -> Healthy
```

Initial rules are:

- `Warmup`: send 16 KiB probe chunks until two valid progress samples exist.
- `Healthy`: normal chunk scheduling and dynamic outstanding window.
- `Lagging`: stop new bulk chunks; retain control/probe credit.
- `Quarantined`: no data allocation; retain connection and bounded control input.
- `Probe`: permit one 16 KiB chunk per probe interval.

A leg becomes `Lagging` when any of these persists for three feedback periods:

- predicted finish is more than 10 ms behind the healthy-leg median;
- write stall exceeds 10 ms;
- outstanding is at the window limit without progress; or
- progress age exceeds `max(2 * srtt, 100 ms)`.

A leg becomes `Quarantined` on a write/read failure or when progress age exceeds
`max(4 * srtt, 500 ms)`. Probe begins after a 1-second cooldown. Three consecutive
probe progress events with no renewed finish-skew violation restore `Healthy`.

All thresholds are configurable and must be validated with deterministic delay,
loss, stall, and reconnect tests before stage use.

## 12. Overload And Drop Semantics

UDP input cannot exert reliable upstream backpressure. When demand remains above
capacity, bounded drops are unavoidable.

The scheduler applies this order:

1. remove already-expired data from the affected session;
2. remove other globally expired data;
3. reject the newly offered regular data frame when its session/global quota is
   still exhausted;
4. preserve a small independent quota for negotiated protocol control and
   recognizable WireGuard handshake packets.

The scheduler does not claim to recognize encrypted inner TCP ACKs. WireGuard
transport data remains ordinary data even when it contains an inner ACK.

Data accepted by a local TCP socket but not confirmed by remote progress is not
automatically replayed after a leg failure in version 1. Blind replay could create
duplicates and stale traffic. WireGuard/inner TCP recovery remains authoritative.

## 13. Reservation Lifecycle

The implementation uses explicit/RAII reservations with these states:

```text
admitted -> queued -> writing -> local_socket_accepted
         -> remote_decoded -> remote_udp_accepted
```

A channel close, task cancellation, write error, rejected progress event, or leg
disconnect releases only the resources owned by that lifecycle stage. Moving a
frame out of a userspace queue does not pretend that remote delivery completed.

`queued + writing` is named `userspace_pending`, not `inflight`. Remote
outstanding is derived from locally sent cumulative wire bytes minus matching
remote progress counters for the current connection epoch.

## 14. UDP Error Handling

Every UDP `send()` and `send_to()` result is checked. Counters increment only
after the corresponding operation succeeds.

Errors are classified as:

```text
udp_error_would_block
udp_error_enobufs
udp_error_unreachable
udp_error_closed
udp_error_other
```

Data send errors are not retried blindly. The error is counted, the affected
datagram lifecycle is closed, and persistent errors feed the leg/runtime health
state. Control feedback may retry only through its existing reliable TCP leg and
bounded control queue.

## 15. Metrics Contract

The ambiguous `datagrams_out` metric is retained only for backward-compatible
output and is accompanied by explicit counters:

```text
offered
admitted
scheduled
queued_to_leg
writing
local_socket_accepted
remote_decoded
remote_udp_accepted
```

Each stage records packets, payload bytes, and wire bytes where applicable.

Drop reasons are fixed and mutually attributable:

```text
drop_queue_deadline
drop_root_queue_full
drop_session_queue_full
drop_no_healthy_leg
drop_leg_window_full
drop_writer_closed
drop_udp_egress_error
drop_invalid_progress
```

The stats JSON adds:

- queue delay p50/p95/p99 using fixed bounded histogram buckets;
- admission and drop ratios;
- aggregate and active-session delivery rates;
- Jain fairness index;
- per-leg outstanding bytes, progress age, progress RTT, delivery EWMA, state,
  write stalls, and transitions;
- UDP error categories;
- feedback-capable and legacy-fallback leg counts; and
- configured/global memory usage and high-water marks.

Scheduler queue delay is measured from admission enqueue time until the frame is
accepted by the selected local TCP socket. Separate histograms retain admission
to scheduling time and writer wait time. The scheduler data-drop ratio excludes
invalid control/progress frames, which have their own denominator and counter.

Per-session detail uses a fixed-capacity active/top-N view. Global and per-leg
counters remain complete; stats reporting cannot allocate unbounded memory as
session count grows.

## 16. Configuration Defaults

The feature is explicitly gated:

```text
scheduler_mode = "legacy" | "feedback_drr"
```

`legacy` is the default. Stage configuration must opt into `feedback_drr`.

Approved initial `feedback_drr` values are:

| Key | Initial value |
| --- | ---: |
| `aggregate_rate_bps` | `300000000` |
| `queue_target_ms` | `5` |
| `queue_deadline_ms` | `10` |
| `global_queue_bytes` | `1048576` |
| `global_queue_packets` | `8192` |
| `per_session_queue_bytes` | `262144` |
| `per_session_queue_packets` | `512` |
| `drr_quantum_bytes` | `16384` |
| `chunk_min_bytes` | `16384` |
| `chunk_target_bytes` | `32768` |
| `chunk_max_bytes` | `65536` |
| `min_leg_window_bytes` | `262144` |
| `max_leg_window_bytes` | `4194304` |
| `max_total_outstanding_bytes` | `8388608` |
| `progress_interval_bytes` | `65536` |
| `progress_interval_frames` | `32` |
| `progress_interval_ms` | `5` |
| `finish_skew_budget_ms` | `2` |
| `probe_interval_ms` | `1000` |
| `max_sessions` | `4096` |
| `control_queue_packets` | `256` |

Validation rejects zero capacities, a `chunk_max_bytes` value smaller than one
maximum datagram payload, a computed burst smaller than one maximum wire frame,
`queue_target_ms > queue_deadline_ms`, minimums above maximums, and memory/window
totals outside the process hard bounds. `chunk_min_bytes` and
`chunk_target_bytes` may be smaller than one maximum datagram because a datagram
is never split and may close an oversized chunk by itself.

## 17. Code Boundaries

The implementation should introduce focused modules instead of adding all state
to the existing large runtime files:

```text
leikwan-uot/src/progress.rs
    capability/auth extension types, progress codec, validation, RTT checkpoints

leikwan-uot/src/scheduler.rs
    admission queues, DRR, pacer, chunk assignment, windows, leg state machine

leikwan-uot/src/telemetry.rs
    lifecycle counters, bounded histograms, fairness and JSON snapshot types
```

`client_runtime.rs` and `sidecar_runtime.rs` remain responsible for socket/task
lifecycle and translate runtime events into scheduler commands. `frame.rs` owns
only frame-kind and generic envelope encoding. `config.rs` owns validation and
defaults.

Agent/config-generator changes must expose exactly the validated config contract
and preserve legacy output when `scheduler_mode=legacy`.

## 18. Test Strategy

Implementation follows strict red-green-refactor TDD. Each behavior receives a
failing test before production code.

### 18.1 Protocol unit tests

- legacy plain-token Auth remains accepted;
- legacy JSON Auth remains accepted;
- old client to new sidecar receives no new frame;
- new client to old sidecar remains legacy;
- new-to-new enables feedback only after matching `AuthAck`;
- `LegProgress` fixed-width round trip;
- epoch mismatch, regression, invalid direction, reserved bits, and counters
  above sent totals are rejected without state mutation.

### 18.2 Scheduler deterministic tests

- 64 continuously backlogged equal-weight sessions achieve Jain fairness >=0.90;
- one session uses multiple healthy legs;
- idle sessions do not retain unbounded DRR deficit;
- deadline expiry precedes capacity-based rejection;
- root and per-session memory never exceed configured bounds;
- chunk sizes and boundaries respect min/target/max/deadline rules;
- predicted-finish selection avoids a delayed leg;
- outstanding credits release only on matching remote progress;
- feedback stale, write stall, quarantine, cooldown, and three-probe recovery;
- cancellation and writer failure release reservations exactly once; and
- legacy mode preserves existing selector behavior.

All timing tests use a paused/virtual Tokio clock and deterministic link events.

### 18.3 Integration matrix

- old client <-> new sidecar;
- new client <-> old sidecar;
- new client <-> new sidecar;
- both traffic directions;
- 1, 2, 4, 5, and 6 legs;
- leg reconnect with a new epoch;
- 64 synthetic UoT source peers;
- one source peer carrying 32 concurrent inner flows; and
- simultaneous multi-session and single-tunnel pressure.

### 18.4 Deterministic impairment

Run separate 60-second cases with:

- one leg at +1 ms, +5 ms, and +10 ms delay;
- one leg at 0.1% and 0.5% loss;
- one leg stalled for 500 ms;
- one leg disconnected and reconnected; and
- feedback/control traffic delayed independently from data.

Linux netns and `tc netem` provide the end-to-end impairment layer. A pure Rust
deterministic scheduler harness remains mandatory so CI does not depend only on
kernel/network timing.

### 18.5 Sixty-second acceptance runs

Each acceptance run lasts 60 seconds, per user direction. At minimum:

1. 64 synthetic UoT sessions at 300 Mbps aggregate;
2. one WireGuard tunnel with 32 inner flows at 300 Mbps;
3. both workloads concurrently at 300 Mbps aggregate; and
4. the approved impaired-leg cases.

Passing gates for the normal-path runs are:

```text
useful throughput             >= 270 Mbps
scheduler queue p99           <= 10 ms
scheduler queue drop ratio    <= 0.1%
UDP socket send errors        == 0
complete zero-rate seconds    == 0
Jain fairness                 >= 0.90
memory hard-bound violations  == 0
feedback-capable active legs  == all active legs
legacy-fallback active legs   == 0
```

Each feedback-mode run has a matched 60-second legacy-mode run with the same
topology, direction, offered load, datagram size, and test order randomized at
the pair level. In addition to the absolute gates:

- receiver net loss must not regress from the matched legacy run and has a target
  of at most 0.5%;
- loaded end-to-end RTT p50/p95/p99 and jitter are recorded concurrently;
- a feedback-mode end-to-end p99 regression greater than 10 ms versus its matched
  legacy run blocks a canary; and
- inner-TCP retransmissions must not regress from the matched legacy run.

Path-caused loss above 0.5% is reported separately, but the feature is not called
successful until scheduler-caused loss is within its gate and total receiver loss
is no worse than legacy.

Impaired runs must have no zero-rate second, no memory violation, no UDP send
error, and no cross-session stall. Their useful-throughput result is compared to
the same topology's unimpaired 60-second baseline; a loss larger than 10% requires
investigation and blocks a canary.

Raw JSON, process stats, socket stats, and test configuration are retained under
a local QA artifact directory with hashes. A handoff may summarize those files
but must not replace them as evidence.

## 19. Stage And Rollback Boundary

This implementation phase stops at code, tests, static artifact construction,
and isolated-stage validation. Production deployment requires a separate explicit
request.

Any future stage/canary must:

1. use slave ingress ports only inside `7000-9000`;
2. preserve `client -> slave -> sidecar`;
3. use an endpoint isolated from production;
4. back up binaries and JSON configs before replacement;
5. record artifact SHA-256 and pinned static-musl build inputs;
6. verify port `20013` before, during, and after the operation;
7. verify WireGuard handshake, ping, scheduler stats, and application traffic;
8. never replace production merely because a newer stage artifact exists; and
9. restore the prior artifact/config and re-verify all listeners on any rollback
   signal.

Canary rollback signals include:

- any UDP socket send error;
- queue p99 above 10 ms for a sustained measurement window;
- scheduler drop ratio above 0.1%;
- any complete zero-throughput second;
- feedback capability mismatch or counter regression;
- unbounded or hard-limit memory growth;
- a feedback-capable lagging leg continuing to receive bulk chunks; or
- loss of `20013`, WireGuard handshake, or the required slave-hop topology.

## 20. Documentation Completion

After implementation and stage evidence exist:

- replace the damaged local `findings20260808.md` with a credential-free report
  using valid worktree paths and separating confirmed facts from hypotheses;
- replace `maybesolution1.md` workflow chatter with the final implemented design,
  defaults, limitations, and measured results;
- link raw QA artifact hashes; and
- state explicitly whether 270 Mbps, 10 ms p99, 0.1% scheduler loss, zero UDP
  errors, and 0.90 fairness passed or failed.

No throughput or root-cause claim is upgraded from hypothesis to fact without the
corresponding raw measurement.

## 21. Risks And Limits

- If the physical/shared LeiGod path cannot deliver 270 Mbps, a scheduler cannot
  create that capacity. Capacity failure must be reported separately from queue,
  fairness, or scheduler failure.
- Progress proves remote decoder or UDP socket acceptance, not final WireGuard or
  end-application receipt.
- Independent TCP legs can still reorder even with accurate predicted completion.
- UDP overload necessarily drops after bounded queue/deadline limits.
- The scheduler actor must be profiled at roughly 25-30k datagrams/s; if CPU is a
  proven bottleneck, session sharding is a later optimization, not a speculative
  first change.
- Root pacing is process-local in version 1. Multiple runtimes sharing one path
  need explicit non-overlapping quotas.
- Feedback control uses the existing authenticated leg trust boundary; this
  design does not add transport encryption to the UoT frame protocol.
- First-version failure recovery relies on WireGuard/inner transport reliability;
  bounded NACK/replay remains a separately reviewed future feature.

## 22. Definition Of Done

The feature is complete only when:

1. all baseline and new focused tests pass;
2. legacy compatibility is proven in both mixed-version directions;
3. deterministic scheduler and impairment tests pass;
4. the 60-second normal-path acceptance gates pass;
5. raw evidence and hashes are retained;
6. static artifacts are reproducibly built;
7. isolated stage verification passes without affecting production or `20013`;
8. damaged local handoffs are rewritten with valid evidence; and
9. no production deployment occurs without a separate explicit instruction.
