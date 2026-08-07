# LeiGod Fixed-Endpoint Throughput Optimization Design

Date: 2026-08-05

Status: draft for review

Baseline: `master` / `codex/sidecar-shared-wg-peers` at `e1561cc`

Related evidence:
`docs/superpowers/handoffs/2026-08-05-sha-tencent-hk-sidecar-forward-throughput.md`
section 11.

## 1. Problem

The live fixed-endpoint benchmark proved that all three user-visible modes can keep one public
slave tuple while using different LeiGod behavior behind it:

```text
client -> one slave ip:port -> LeiGod line(s) -> overseas bond service or target
```

With the same endpoint and target, the measured receiver medians were:

| Mode | P4 Mbps | Reverse Mbps |
| --- | ---: | ---: |
| `single` | 0.904 | 1.048 |
| `race` | 0.940 | 1.071 |
| `bond` | 172.858 | 118.803 |

`single` and `race` both used line `451`. Race selected it because its TCP connect latency was
lowest, even though its sustained throughput was degraded. The benchmark bond avoided that
failure by opening five independent sessions, but its temporary front door assigned accepted
connections to lines by arrival-order round robin. Arrival order is not a stable identity: a
reconnect, delayed handshake, or concurrent connection can silently move a logical bond leg to a
different line.

The first bond reverse run also started before all five logical legs were ready and delivered only
`0.629 Mbps`. The remaining runs recovered without a transport reconnect, service restart, or
cooldown. This makes cold-start readiness and per-leg observability part of the optimization, not
just demultiplexing.

## 2. Goals

1. Preserve the assigned public slave `host:port` across `single`, `race`, and `bond`.
2. Replace fixed-endpoint bond round robin with deterministic logical-leg-to-line routing.
3. Let one authenticated client ingress helper carry all logical bond connections to that one
   public endpoint.
4. Give race an opt-in sustained-throughput signal while preserving one selected line per TCP
   connection.
5. Wait for the preferred initial bond leg set before exposing a cold transport, with a bounded
   minimum-quorum fallback.
6. Reuse the existing bond scheduler, ACK feedback, penalties, and cooldown rather than adding a
   second weighting system.
7. Add enough state to explain cold-start, missing-leg, stall, retry, and line-selection behavior.
8. Roll out locally first, then to the server canary, then synchronize committed artifacts to the
   panel/server runtime.

## 3. Non-Goals

- No direct client-to-sidecar connection. The topology remains `client -> slave -> sidecar` for
  aggregation.
- No new public endpoint and no listener on port `443`.
- No slave bond or MPTCP ingress outside `7000-9000`.
- No per-line public port and no per-line client ingress process.
- No attempt to migrate an established TCP connection between LeiGod lines.
- No high-rate active bandwidth probe against an application target. The prior iperf test proved
  that empty target probes can interfere with single-session services.
- No claim that the relay can observe carrier TCP retransmits. Its SOCKS socket is loopback; the
  provider-owned external socket is outside the relay process. Race scoring therefore uses
  delivered bytes and stalls, not fabricated retransmit data.
- Android is not part of the first live canary. The wire and panel contracts remain portable, and
  Android parity is required before broad enablement.

## 4. Considered Designs

### 4.1 Integrated `leigod_forward` bond demultiplexer (recommended)

Add `mode=bond` to the existing loopback-only relay. Each bond connection carries a stable route
identity in its first `LBND` hello header. The relay maps that identity to one configured LeiGod
candidate, opens SOCKS `CONNECT` through that candidate, and forwards the original hello bytes
unchanged.

Advantages:

- one public endpoint, one authenticated ingress helper, and one relay unit;
- no accept-order dependency;
- the existing candidate validation and systemd lifecycle remain authoritative;
- a route can reconnect without changing lines;
- no extra public or loopback listener per line.

### 4.2 Separate demultiplexer plus one single relay per line

A new daemon could inspect the hello and forward to five line-specific `leigod_forward` units.
This isolates code paths but adds a new binary, five relay units, five loopback ports, more restart
ordering, and another desired-state cleanup surface. It recreates the temporary benchmark shape
without solving its operational complexity.

### 4.3 Keep arrival-order round robin

This requires no protocol change, but it cannot preserve line identity across reconnects and can
assign two logical legs to one line while leaving another unused. It is suitable only as benchmark
scaffolding and is rejected for production.

## 5. Selected Topology

For fixed-endpoint bond:

```text
leikwan-bond client
  -> N logical TCP legs, each with route_id
  -> one local leikwan-ingress client listener
  -> one public slave host:port
  -> existing authenticated leikwan-ingress mux
  -> one loopback leigod_forward relay in mode=bond
  -> route_id 447 -> SOCKS 20447 ----+
  -> route_id 451 -> SOCKS 20451 ----|
  -> route_id 453 -> SOCKS 20453 ----+-> sidecar bond service -> target
  -> route_id 455 -> SOCKS 20455 ----|
  -> route_id 456 -> SOCKS 20456 ----+
```

The physical endpoint remains one `service_endpoints` row. Logical legs are an additive bonding
contract and do not pretend to be additional physical endpoints.

## 6. Wire Contract

`leikwan-bond` wire version 2 has a 32-byte frame header. For a `Hello` frame, header bytes
`24..28` (`stream_id`) are currently written as zero and ignored by the current decoder. Reuse
that field as a hello-only `route_id`:

```rust
pub struct HelloFrame {
    pub session_id: Uuid,
    pub expected_links: u16,
    pub route_id: u32,
    pub token: String,
}
```

Every client leg builds its own hello from its parsed `LegSpec.leg_route_id`. All hellos retain the
same session ID, expected-link count, and token. The payload layout and wire version do not change.

Compatibility is intentionally asymmetric and safe:

- new client to old bond server: works; the old server ignores hello `stream_id`;
- old client to new ordinary bond server: works; decoded `route_id` is zero and unused;
- old client to fixed-endpoint bond relay: rejected because `route_id=0` is not routable;
- new client without a configured fixed route: sends zero and retains current behavior.

`LegSpec` gains an optional `leg_route_id` query parameter separate from `endpoint_id`. Endpoint
ID continues to identify the physical panel endpoint for health and accounting; leg route ID
identifies a logical line behind that endpoint. On the wire it becomes `HelloFrame.route_id`. It is
never the panel's `forward_route_id`; conflating those three identities would corrupt routing and
endpoint observability.

## 7. Slave Relay Contract

### 7.1 Configuration

The additive runtime payload is:

```json
{
  "leigod_forward": {
    "mode": "bond",
    "relay_port": 48003,
    "target": "<sidecar-bond-host>:18501",
    "candidates": [
      {
        "combination_id": 21,
        "line_id": 447,
        "leg_route_id": 447,
        "socks_host": "127.0.0.1",
        "socks_port": 20447
      }
    ]
  }
}
```

For the first implementation, `leg_route_id` equals the already unique and bounded `line_id`. It
is still explicit in the generated relay config so validation, logs, and future mappings do not
rely on an undocumented coincidence.

Bond relay validation requires:

- TCP carrier transport;
- between two and eight candidates;
- unique nonzero route IDs, line IDs, candidate keys, and SOCKS endpoints;
- the existing exact loopback SOCKS mapping `127.0.0.1:(20000 + line_id)`;
- fresh local line health and matching config SHA-256;
- a loopback relay listener and a fixed configured target.

### 7.2 Connection handling

For each accepted TCP connection, the relay:

1. reads exactly the 32-byte bond header under a short timeout;
2. validates `LBND`, wire version 2, frame kind `Hello`, a bounded payload length, and nonzero
   wire `route_id`;
3. looks up the exact configured candidate;
4. opens SOCKS `CONNECT` through only that candidate to the fixed target;
5. writes the original 32-byte header upstream and starts bounded bidirectional copy.

Unknown IDs, malformed headers, timeouts, or candidate connect failures close only that
connection. Bond mode never falls back to another line because that would violate the logical leg
identity. The bond client reconnect loop is responsible for retrying the same route.

Periodic target `CONNECT` probes are disabled in bond mode. Candidate eligibility comes from the
existing line-health input, and actual route connections update runtime success/failure state.
This avoids sending unauthenticated empty sessions to the bond server or application target.

`single` and latency-based `race` behavior remains unchanged unless the new race policy is
explicitly enabled.

## 8. Panel And Reconciliation Contract

The first canary reuses existing persistence instead of adding a schema migration:

- `backend_mode=leigod_mode`;
- `client_mode=bond`;
- a valid sidecar point whose `agent_config_json.fixed_endpoint_leigod_bond=true`;
- `custom_endpoint_ids_json.specific_node_id` pins the physical slave;
- the forward's existing public port remains unchanged.

Only this complete shape enables fixed-endpoint bond. Without the point gate or node pin, current
multi-slave LeiGod bond behavior is preserved.

Reconciliation must honor the explicit node pin before normal combination-wide slave selection,
including after heartbeats and membership edits. It must then verify that the pinned node is
eligible and has at least two fresh candidates. A missing or ineligible pinned node makes the
forward unavailable; it must not silently allocate a replacement endpoint and change the public
IP.

The publish target for the slave ingress becomes the stable loopback relay port. The relay's real
target is the existing sidecar bond target. Mode changes republish the endpoint in place; they do
not delete and recreate the `service_endpoints` row or allocate a different public port.

The client config keeps one physical endpoint and adds logical legs under `bonding`:

```json
{
  "endpoints": [
    {
      "endpoint_id": 184,
      "host": "<fixed-slave-host>",
      "port": 7003,
      "ingress_auth_token": "<redacted>"
    }
  ],
  "bonding": {
    "fixed_endpoint": true,
    "fixed_endpoint_legs": [
      {"leg_route_id": 447, "priority": 10},
      {"leg_route_id": 451, "priority": 10}
    ],
    "preferred_initial_legs": 5,
    "minimum_initial_legs": 2,
    "initial_leg_wait_ms": 3000,
    "leg_reconnect_backoff_ms": 5000,
    "leg_reconnect_backoff_max_ms": 60000
  }
}
```

The real list contains every eligible configured candidate. The panel rejects duplicate route IDs,
an empty list, more than eight legs, and a preferred/minimum count outside the list bounds.

## 9. Client Runtime

Linux starts one `leikwan-ingress client` for the physical endpoint. That helper already accepts
concurrent local connections. The runtime then renders one bond `LegSpec` per
`fixed_endpoint_legs` entry, all pointing to the same local listener but carrying distinct
`leg_route_id` query values.

The bond client changes cold startup as follows:

1. start every configured leg connection concurrently;
2. keep collecting acknowledged legs until all preferred legs are ready or
   `initial_leg_wait_ms` expires;
3. start the transport when at least `minimum_initial_legs` are ready;
4. fail the attempt when the minimum is not met, rather than exposing a one-leg bond;
5. continue adding missing routes to the same session;
6. retry a missing fixed-endpoint route using jittered exponential backoff between the configured
   initial and maximum values rather than the current hard-coded 30-minute retry; a successful
   hello acknowledgment resets that route's backoff.

Defaults preserve current behavior for non-fixed bonds. Only the fixed-endpoint contract publishes
the new readiness and retry values in the canary.

The existing scheduler remains authoritative for per-leg ACK/RTT EWMA, warmup, bulk promotion,
penalties, cooldown, and retransmit selection. Route identity guarantees that these observations
remain attached to the intended line after reconnect.

## 10. Throughput-Aware Race

Connect latency remains useful for reachability, but it is not a throughput signal. Add an opt-in
race policy, for example `selection_signal=throughput_ewma`, while retaining the current
`connect_latency` default.

The relay records a qualifying sample only when a connection transfers at least 1 MiB in one
direction and remains active for at least two seconds. A sample contains:

- dominant-direction delivered Mbps;
- one-second zero-delivery interval ratio after payload starts;
- completion/error state;
- sample time and connection count;
- existing SOCKS connect latency as a tie-breaker.

Per candidate, the relay maintains separate bounded upload/download EWMA goodput values and stall
ratios. Balanced mode uses the lower valid directional score; a candidate with only one sampled
direction receives a discount until the other direction is sampled. Direction-specific objectives
may be added later without changing the sample format. The balanced selection score is:

```text
score = min(valid directional ewma Mbps) * max(0.10, 1 - worst ewma stall ratio)
```

Selection rules:

1. a healthy candidate without a qualifying sample is reserved as `sampling_in_flight` when a new
   connection is assigned, so concurrent P4 connections explore different lines instead of all
   choosing the same unsampled candidate;
2. once sampled, new connections use the highest score, then lower connect latency, then configured
   order as tie-breakers;
3. every bounded exploration interval selects the stalest healthy candidate so a recovered line
   can prove itself;
4. failures use the existing failure threshold and recovery hysteresis;
5. existing connections remain pinned;
6. state is written atomically, bounded to the configured candidates, and expires after a short
   TTL so yesterday's speed does not dominate a changed route.

Short control connections do not affect throughput score. The per-line benchmark precedes the
canary and sets the operator ordering for cold start, but benchmark numbers are not hard-coded in
the product.

`single` stays truthful: it uses the first configured healthy candidate with availability failover.
The measured best line may be placed first, but single does not explore or stripe.

## 11. Observability

The relay state file adds bounded per-route entries:

```text
leg_route_id, line_id, state, active_connections, connect_successes, connect_failures,
bytes_up, bytes_down, ewma_delivered_mbps, ewma_stall_ratio, last_sample_at,
last_error
```

The bond snapshot adds:

```text
leg_route_id, initial_connect_ms, hello_ack_ms, initial_ready, added_late,
reconnect_count, scheduled_bytes, retransmit_frames, cooldown_count,
last_cooldown_reason
```

Startup logs report only counts and IDs, never tokens or config bytes. The formal benchmark must
archive client snapshot, relay state, unit restart counters, and socket counts beside each iperf
JSON so a reverse outlier can be attributed rather than guessed at.

## 12. Failure And Security Properties

- The public listener remains the existing authenticated ingress; the LeiGod relay remains
  loopback-only.
- Route IDs select only a prevalidated local candidate and can never alter target host or port.
- A missing route fails closed and cannot spill onto another line.
- A missing pinned node fails closed and cannot change the customer-facing IP.
- Header reads have a timeout and fixed maximum; no unbounded pre-routing buffer is permitted.
- Reload atomically replaces the route map for new connections and drains existing connections.
- Single/race state does not share candidate throughput values across a different forward or
  target.
- Tokens, TOML, sessions, and credentials remain excluded from logs, state, tests, and handoffs.

## 13. Test Strategy

### 13.1 Local automated tests

Panel tests prove:

- the gated fixed-bond shape keeps exactly one pinned endpoint;
- reconcile does not replace the pinned node for a combination-backed forward;
- slave payload uses `mode=bond`, loopback runtime target, sidecar real target, and unique routes;
- client config keeps one physical endpoint and publishes two to eight logical legs;
- malformed, duplicate, stale, or one-leg candidate sets fail closed;
- ungated and unpinned LeiGod bonds retain current behavior.

Slave/relay tests prove:

- valid fragmented hello headers are buffered and routed to the exact SOCKS candidate;
- route identity is independent of accept order;
- unknown/zero route, wrong magic/version/kind, oversized length, and timeout are rejected;
- connect failure does not fall back to a different line;
- single/race regressions remain green;
- throughput EWMA ignores short flows, explores unsampled/stale lines, penalizes stalls, and expires
  stale state.

Rust tests prove:

- hello route ID round-trips in header bytes `24..28` without changing payload layout;
- zero remains backward compatible for ordinary sessions;
- every TCP and UDP leg, including reconnects, sends its own configured route ID;
- fixed startup waits for preferred legs, requires minimum quorum, and times out boundedly;
- missing-leg reconnect uses the configured backoff;
- existing scheduler, frame, session, and 0.1.30 backpressure regressions remain green.

Linux runtime tests prove one ingress process/listener and N distinct bond leg URIs. Desktop and
Android parsers/builders require contract tests before broad enablement, even though the first live
canary is Linux-only.

### 13.2 Live validation order

1. Benchmark every candidate line individually through the fixed endpoint, five P4 and five
   reverse runs per line.
2. Deploy committed panel and artifacts with the fixed-endpoint point gate off.
3. Deploy the slave relay and bond binary, then verify ordinary single/race/bond smoke tests.
4. Enable the point gate only for the pinned canary forward.
5. Verify the same public tuple in client config, sockets, and packet capture metadata for all
   three modes.
6. Run race warmup, then the formal race comparison.
7. Run five bond P4 and five reverse tests from a cold transport and again from a warm transport.
8. Disable/delete the canary, converge all destroy tasks, and verify zero transient units,
   listeners, sockets, and target processes.

## 14. Acceptance Criteria

The canary is accepted only if:

- the client-facing endpoint is unchanged across the tested modes;
- all configured bond routes map to their intended line regardless of connection order;
- five preferred legs are normally ready before the first application stream, and a bounded
  timeout never exposes fewer than the configured minimum;
- bond P4 receiver median is at least `150 Mbps` over five normal runs;
- all five bond reverse receiver results exceed `100 Mbps`;
- formal bond runs contain zero zero-throughput intervals;
- the clean benchmark has zero transport reconnects, unit restarts, and cooldown events;
- post-warmup throughput-aware race no longer prefers a materially degraded line merely because
  it has the lowest connect latency;
- post-warmup race receiver throughput is at least 80% of the best current per-line control for the
  same traffic direction;
- final cleanup returns panel, client, slave, sidecar, and target to their pre-test state.

Failure of any threshold keeps the gate off. Rollback disables the point gate and republishes the
same endpoint through the existing runtime path; it does not change the endpoint row, public port,
sidecar port, or WireGuard topology.

## 15. Implementation Phases

1. Add wire route identity and deterministic bond relay handling under tests.
2. Add the gated panel, slave validation, and one-helper/N-logical-leg Linux contract.
3. Add bounded initial readiness, reconnect policy, and observability.
4. Run individual line controls before enabling throughput-aware race.
5. Add the opt-in passive race learner and verify it against those controls.
6. Build committed static artifacts, deploy locally then remotely, run formal acceptance, clean up,
   and synchronize the accepted committed release to the panel/server artifact store.
