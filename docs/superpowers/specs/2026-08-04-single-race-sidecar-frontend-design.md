# Single/Race Sidecar Frontend Design

**Status:** Approved for implementation and a gated `yt-hk-a` rollout.

## Problem

`current_mode` forwards in `single` and `race` are currently classified as
`direct_forward_node`. Their slave publish payload therefore contains the final target, not the
sidecar bond target, and no static slave-to-sidecar WireGuard uplink is selected for the route.
The measured result is a healthy client-to-slave connection followed by an unusable
mainland-to-overseas target path.

The existing `bond` route already proves the intended production topology and throughput:

```text
client -> slave ingress (7000-9000) -> shared WG peer -> yt-hk-a:18501 -> endpoint
```

The WG listener remains one shared `lwsp<point>` interface on port `18502`, with one peer per
slave. No client connects directly to the sidecar and no service uses port `443`.

## Locked Decisions

1. Reuse the existing bond sidecar service on `18501`; do not add an egress service port.
2. Reuse the existing shared WireGuard listener on `18502`; do not create per-slave WG ports.
3. Keep the user-facing modes truthful:
   - `single`: one slave leg, no aggregation.
   - `race`: multiple connected slave legs, but one productive leg per application flow.
   - `bond`: multi-leg aggregation remains unchanged.
4. Implement the first two modes as a bond frontend transport because it already provides target
   tokens, target-side reconvergence, TCP and UDP support, and a single target connection.
5. Gate the behavior with sidecar point config
   `agent_config_json.single_race_via_sidecar=true`. The default is off.
6. A local application or target socket reset is not evidence that a transport leg is unhealthy.
   It must close/reset only that application stream and must not impose the 30-minute leg cooldown.
7. Linux artifacts must be static musl builds and therefore run on Debian 12 without requiring a
   newer glibc.

## Control-Plane Contract

For an enabled point and a forward satisfying all of the following:

- `backend_mode=current_mode`
- `client_mode=single|race`
- a valid `bond_sidecar_point_id`
- point config `single_race_via_sidecar=true`

the panel publishes the existing sidecar aggregation transport plus these explicit fields:

```json
{
  "sidecar_required": true,
  "aggregation_enabled": false,
  "active_family": "sidecar_aggregation",
  "target_contract": "sidecar_aggregation",
  "bonding": {
    "frontend_mode": "single",
    "strategy_locked": true,
    "ingress_mode": "plain_tcp_mux"
  }
}
```

`frontend_mode` is `single` or `race`. `aggregation_enabled` stays false because this field
describes user-visible payload aggregation, not use of the bond carrier protocol. The existing
`sidecar_aggregation` family name is retained for compatibility with current clients and policy
checks.

The slave task is published with `publish_bond_runtime=true`, a sidecar target resolved through
the static shared WG peer, and route authentication for `plain_tcp_mux`. The sidecar heartbeat
management response includes these forward IDs in `bond_runtime.forward_ids`, so `18501` remains
active even if a point currently has only single/race forwards.

## Client Runtime Semantics

All clients consume `bonding.frontend_mode` before the top-level display mode:

- `single`: retain only the first healthy endpoint and run the existing bond frontend with one
  ingress helper.
- `race`: retain all healthy endpoints, keep the bond strategy fixed at `latency_best`, and omit
  the mutable per-forward strategy file. The persistent transport can observe all legs, while each
  application stream stays pinned to one best responsive leg.
- `bond`: preserve the existing mutable strategy and multi-leg aggregation behavior.

For `plain_tcp_mux`, every leg remains:

```text
leikwan-ingress client --remote <slave>:<7000-9000> --route-key <route> --auth-token <token>
```

The bond session token is the only target authority. The client never receives or dials a sidecar
overlay address.

## Failure Handling

The bond transport distinguishes two failure domains:

- transport-leg/control failure: may penalize or cool down that leg;
- local application/target stream failure: resets only that stream and leaves leg health and
  cooldown unchanged.

This fixes the observed back-to-back test failure where an application-side
`Connection reset by peer` cooled a healthy leg for 1,800 seconds.

## Rollout And Acceptance

1. Ship panel, client-agent, desktop, Android, and bond runtime changes with the point gate off.
2. Publish static Debian-compatible Linux artifacts and matching platform artifacts.
3. Enable `single_race_via_sidecar` only on `yt-hk-a` after confirming no incompatible active
   client remains on that point.
4. Republish temporary TCP forwards for `single`, `race`, and `bond` to the test client.
5. Prove on the slave that single/race targets are the WG-side `18501`, not the final endpoint.
6. Prove on the sidecar that WG counters increase and the final target connection originates there.
7. Run cold and immediate-repeat iperf3 tests. Immediate repeat must not enter a 1,800-second
   cooldown.
8. Remove all temporary forwards and listeners after evidence collection.

