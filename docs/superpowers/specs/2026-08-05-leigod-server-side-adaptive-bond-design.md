# LeiGod Server-Side Adaptive Bond Design

Date: 2026-08-05

Status: approved for implementation

Decision record: the user approved all three LeiGod optimizations, then required the primary
throughput gate to use Reverse single-stream traffic without `-P 4`, and required aggregation to
run on the slave/sidecar rather than burdening the end-user client.

## 1. Problem

The fixed-endpoint 0.1.31 benchmark proved deterministic line identity and reached a
`196.436 Mbps` P4 median and `185.334 Mbps` Reverse median with manually admitted routes
`447/451/453`. It also proved three remaining gaps:

1. The current fixed-endpoint product starts the bond transport on the user client. This adds
   multiple persistent connections, scheduling, retransmit bookkeeping, and reassembly to the
   client path.
2. Bond health reacts to failures and retransmits, but its delivered-byte EWMA is a chunk-size
   signal rather than a time-based throughput signal. A connected but slow line can remain in the
   bulk pool.
3. The existing sticky/warmup/bulk stages distinguish interactive and bulk traffic, but bulk
   admission is not tied to measured line throughput.

The next release must preserve one public slave endpoint while moving aggregation off the client,
automatically excluding or down-weighting weak LeiGod lines, and preserving low latency for small
flows without allowing a sub-10 Mbps line to block bulk delivery.

## 2. Goals

1. Keep the assigned public slave `host:port` unchanged.
2. Keep the client-facing connection ordinary TCP: one application flow and one ingress helper,
   with no client-side `leikwan-bond` process for this placement.
3. Run the bond client on the selected slave and the bond server on the selected sidecar.
4. Measure ACK-confirmed throughput independently at whichever bond peer is sending, so upload and
   Reverse decisions can differ.
5. Automatically quarantine severely slow or stalled lines, down-weight slower stable lines, and
   probe quarantined lines for recovery without hard-coding line IDs.
6. Keep interactive/short flows sticky on a healthy low-RTT line and promote sustained/backlogged
   flows to a throughput-weighted bulk pool.
7. Demonstrate Reverse single-stream receiver throughput above `200 Mbps` under the same endpoint,
   or produce segment-level evidence identifying an external capacity ceiling that software cannot
   exceed.
8. Implement locally first, then isolated server canaries, then committed production synchronization.

## 3. Non-Goals

- No direct client-to-sidecar connection.
- No application-protocol rewriting, HTTP range synthesis, or transparent conversion of one TCP
  stream into independent application requests.
- No public per-line ports. Slave bond and ingress ports remain in `7000-9000`.
- No hard-coded production preference for line IDs observed in one benchmark window.
- No claim that a single flow can exceed the slave or target provider bandwidth cap.
- UDP keeps its existing product path in this release. TCP Reverse single-stream is the required
  acceptance path; the shared health primitives remain usable by UDP.

## 4. Considered Placements

### 4.1 Slave plus sidecar aggregation (selected)

```text
application client
  -> one ordinary TCP connection
  -> one ingress client helper
  -> fixed slave ip:port
  -> slave plain TCP mux/backend
  -> slave-local leikwan-bond client
  -> N route-tagged legs through line-specific LeiGod SOCKS
  -> sidecar leikwan-bond server
  -> target
```

Reverse data is split by the sidecar bond server, reassembled by the slave bond client, and then
written to the client over the original ordinary TCP connection. The client does not schedule or
reassemble bond frames.

This placement preserves generic TCP semantics, keeps topology `client -> slave -> sidecar`, and
allows one Reverse application stream to use multiple LeiGod lines.

### 4.2 Client-created independent parallel application flows

This can improve protocols that already support multiple connections or segmented downloads, but
it cannot transparently preserve arbitrary TCP stream semantics. It remains an optional future
protocol-aware accelerator and is not the non-P4 acceptance path.

### 4.3 Client-side transparent bond

This is the 0.1.31 fixed-endpoint implementation. It remains supported for existing contracts but
is not selected for the new placement because it adds client CPU, connection, and reassembly work.

## 5. Contract

The additive bonding contract contains:

```json
{
  "aggregation_placement": "slave_sidecar",
  "adaptive_line_admission": true,
  "throughput_min_mbps": 10.0,
  "throughput_relative_floor": 0.35,
  "throughput_min_sample_bytes": 1048576,
  "throughput_min_sample_ms": 1000,
  "throughput_bad_windows": 3,
  "throughput_recovery_windows": 2,
  "throughput_quarantine_ms": 60000,
  "throughput_probe_interval_ms": 30000,
  "throughput_probe_bytes": 1048576
}
```

The panel publishes the full line set only to the slave runtime. The user client receives one
physical endpoint, the ingress token, the route key, and the placement marker; it does not receive
logical LeiGod line IDs for this placement.

Unknown placement values fail closed. Existing contracts without `aggregation_placement` retain
the 0.1.31 client-side behavior.

## 6. Slave Runtime

The slave publish payload adds a `server_side_bond` object containing the stable local frontend
port, line route IDs, sidecar bond target, session token, scheduler settings, and lifecycle limits.
The existing `leigod_forward.mode=bond` relay remains loopback-only and maps each LBND route ID to
one validated local LeiGod SOCKS candidate.

For each enabled forward, the slave agent creates:

1. the existing public plain TCP mux;
2. the existing per-forward backend, targeting the slave-local bond listener;
3. one per-forward `leikwan-bond client --protocol tcp` service;
4. one loopback `leigod_forward` bond relay.

All bond legs connect to the one loopback relay and carry distinct `leg_route_id` values. The relay
then dials the sidecar bond server through the exact associated LeiGod SOCKS service. Process names,
ports, config paths, and metadata are deterministic, collision-checked, and removed on destroy or
mode change.

The slave installer and update helper must install the manifest-pinned static `leikwan-bond`
artifact before the agent activates a server-side bond payload.

## 7. Sidecar Runtime

The existing authenticated `leikwan-bond server` remains the aggregation peer and target dialer.
No new public sidecar listener is introduced. Session-token claims remain authoritative for target,
forward identity, protocol, and expiry.

The sidecar is the sender for Reverse data, so its per-leg ACK feedback drives Reverse throughput
weights and quarantine decisions. The slave independently scores upload delivery when it is the
sender. A line can therefore remain upload-eligible while being excluded from Reverse bulk traffic.

## 8. Adaptive Line Admission

Each TCP leg maintains a bounded delivery window:

```text
window_started_at
window_delivered_bytes
throughput_mbps_ewma
qualifying_samples
consecutive_bad_windows
consecutive_good_windows
bulk_admission_state
quarantine_until
next_probe_at
probe_budget_bytes
```

Only ACK-confirmed, first-transmission bytes sent while the stream is in warmup or bulk mode count
toward a throughput sample. A window qualifies after both the minimum bytes and minimum duration
are met. Demand-limited short flows do not lower a line's throughput score.

For each direction, the current best fresh line defines the relative reference:

```text
bulk floor = max(throughput_min_mbps, best_fresh_mbps * throughput_relative_floor)
```

- A fresh line below the floor is degraded and receives a reduced proportional weight.
- Three consecutive bad qualifying windows quarantine it from ordinary bulk scheduling.
- Transport penalties or stalls can quarantine earlier through the existing health state machine.
- A quarantined line receives at most `throughput_probe_bytes` when its probe interval expires.
- Two consecutive good qualifying probe windows restore it.
- If every line is excluded, the best writable line remains available so bounded transport credit
  cannot deadlock.

Bulk selection minimizes normalized queued/in-flight load divided by the line's current throughput
weight. This yields proportional scheduling rather than equal chunks on lines with unequal service
rates.

## 9. Interactive And Bulk Scheduling

The existing per-stream stages remain:

```text
Sticky -> Warmup -> Bulk
```

- Sticky traffic uses a healthy low-RTT leg within `interactive_rtt_slack_ms`.
- Warmup samples at most two eligible lines with bounded slices.
- Bulk uses only admitted lines and throughput-proportional scheduling.
- A low-RTT line below `10 Mbps` may remain interactive-only when it has no loss/stall penalty, but
  it never receives normal bulk chunks.
- Control and ACK frames prefer a healthy responsive leg and are never intentionally delayed for a
  throughput probe.

Classification uses stream bytes, outstanding bytes, and backlog. It does not infer application
intent from an individual IP packet size.

## 10. Capacity Diagnosis And Performance Gate

Before attributing a `~200 Mbps` plateau to bond scheduling, isolated non-P4 controls measure:

1. client <-> fixed slave raw TCP capacity;
2. slave <-> sidecar/target capacity outside LeiGod where topology permits;
3. each LeiGod line in Reverse single-stream mode;
4. adaptive server-side bond in Reverse single-stream mode.

Every formal group uses five normal JSON runs. The primary command is:

```bash
iperf3 -4 -c <fixed-endpoint> -p <port> -R -b 400M -t 20 --json
```

Primary success requires:

- five of five normal runs;
- receiver median greater than `200 Mbps`;
- zero zero-throughput intervals;
- no bond transport rebuild, process restart, or uncontrolled reconnect;
- one ordinary application connection at the client-facing endpoint.

The stretch gate is receiver median at least `220 Mbps` with every run above `200 Mbps`. If the raw
fixed-slave control cannot exceed `210 Mbps`, the release may still pass correctness and admission
gates, but the handoff must identify the external capacity ceiling and must not claim aggregated
throughput above it.

## 11. Failure And Security Properties

- The client never receives a sidecar address or line credential.
- All sidecar access remains through the selected slave path.
- Relay route IDs select only prevalidated line candidates and cannot change the target.
- Unknown routes, missing binaries, fewer than two valid legs, expired tokens, and port collisions
  fail before the public route is activated.
- Runtime files never contain credentials in world-readable paths; unit logs exclude token values.
- Reload replaces admission configuration for new sessions and drains established sessions.
- Destroy and rollback remove the server-side bond unit, relay unit, route metadata, and temporary
  listeners before restoring the previous backend target.

## 12. Test Strategy

Rust tests cover time-qualified throughput windows, relative floors, bad-window quarantine,
bounded probes, recovery hysteresis, per-direction decisions, weighted bulk selection, fallback to
the last writable line, and latency-only handling below `10 Mbps`.

Slave-agent tests cover strict payload validation, deterministic ports and units, exact route IDs,
manifest-pinned bond binary requirements, idempotent publish, in-place mode change, and complete
destroy cleanup.

Panel tests cover the placement gate, one physical endpoint, server-only logical legs, unchanged
public tuple, sidecar requirement, token generation, and legacy client-side contract compatibility.

Client-agent tests prove one ingress helper and zero `leikwan-bond` processes for
`aggregation_placement=slave_sidecar`, while legacy fixed-endpoint contracts retain their current
runtime.

Process integration runs a real slave-side bond client, real relay, real sidecar bond server, and a
single ordinary application socket in both normal and Reverse directions.

## 13. Rollout And Rollback

1. Add failing local tests before each behavior change.
2. Build a new static versioned bond artifact and synchronize its manifest and mirrors.
3. Run complete Rust, Python, PHP, artifact, and process suites.
4. Deploy committed artifacts to isolated slave and sidecar canary units only.
5. Run segment capacity controls, then adaptive Reverse single-stream tests.
6. Enable one panel canary only after isolated tests pass.
7. Back up panel, database, slave runtime, sidecar runtime, and client runtime before synchronization.
8. Roll back by restoring the previous publish target and 0.1.31 artifacts, then verifying zero
   server-side bond units and unchanged public endpoint state.

## 14. Acceptance

The implementation is accepted when all local gates pass, the client uses one ordinary ingress
connection with no client-side bond process, weak lines are automatically excluded and later
recovered under deterministic tests, interactive traffic stays on a healthy low-RTT leg, and the
formal Reverse non-P4 benchmark either exceeds `200 Mbps` median or proves a lower external segment
capacity with reproducible controls. Production synchronization additionally requires committed
source, backups, zero pending migrations, exact artifact hashes, live HTTP verification, and clean
post-canary runtime state.
