# Forward-combination static slave-sidecar WG design

Date: 2026-05-28
Branch/worktree: current `leikwan-br1` debug worktree

## Approved outcome

When a forward combination exists, the panel must pre-create the slave-to-sidecar backhaul for that combination instead of creating per-forward/deprecated `lw-sc-*`, `lw-cfsr-*`, or `lw-mptcp-*` sidecar legs on demand.

For every selected rented slave in a combination:

- create exactly two persistent slave-to-sidecar WireGuard lanes: `primary` and `backup`
- publish those lanes to both the slave agent and the sidecar agent as management state
- put the lanes under agent watchdog/restart supervision
- route both bond and MPTCP slave backends over the static WG lane
- normal/current mode dials the sidecar CNIX IP directly
- connection/backend types starting with `CF` (`cf_mode`) still use WG, but the WG UDP packet path is carried by the Cloudflare-optimized/wstunnel path

## Invariants

- Preserve `client -> slave -> sidecar`.
- Do not use direct client-to-sidecar.
- Do not touch Canton slave IPs for this rollout.
- Slave public ingress stays in `7000-9000`.
- Static slave-sidecar WG names must be point/combo/node/lane scoped and must not collide with legacy `lw-sc-*`, per-forward `lw-mptcp-*`, or CFWG self-leg names.
- Per-forward traffic must reuse the static lane; it must not open a new slave-sidecar connection per client session.

## Contract shape

Panel emits two management surfaces:

- sidecar heartbeat management: `sidecar_uplinks[]`
- slave task-pull management: `sidecar_static_uplinks[]`

Each uplink includes:

- `interface_name`
- `forward_combination_id`
- `sidecar_point_id`
- `node_id`
- `lane` (`primary` or `backup`)
- `role`
- `local_address`
- `peer_address`
- sidecar/slave public keys
- direct or CF outer transport fields
- `relay_target`, the sidecar service address reached over the WG lane
- watchdog fields

## Naming

Use short Linux-safe interface names:

- `lws{point}c{combo}n{node}{p|b}` when <= 15 chars
- hashed fallback if ids make that longer

Example for point 4, combo 2, node 19:

- `lws4c2n19p`
- `lws4c2n19b`

## Data plane

For a forward using a combination, panel picks the static primary lane for that slave endpoint and sets the slave backend target to the lane's `relay_target`:

- bond/current mode: sidecar static WG address + bond listen port
- mptcp mode: sidecar static WG address + MPTCP listen port
- CF mode: same WG semantics, but the WG endpoint is through the sidecar CF/wstunnel path

The backup lane is created and watched, but not selected until failover policy chooses it.

## Cleanup

On sidecar, once static `lws*` uplinks are present for a point, the agent may remove deprecated `lw-sc-{point}-*` transit interfaces. Client-side forward-specific `lw-*` cleanup remains scoped to the client agent and must not blindly remove unrelated active forwards.
