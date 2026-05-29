# Implementation plan: forward-combination static slave-sidecar WG

Date: 2026-05-28

## Phase 1 - Regression tests

1. Add a panel regression proving `slaveSidecarUplinkManagementForPoint()` emits two static lanes per selected combination slave, not legacy `lw-sc-*`, and that publish payloads route bond/MPTCP slave backends to the static WG sidecar address.
2. Add a slave-agent regression proving management `sidecar_static_uplinks` applies/removes static lanes and restarts unhealthy lanes.
3. Add a sidecar-agent regression proving `lws*` state is keyed by interface name and stale `lw-sc-{point}-*` is cleaned when static uplinks exist.

## Phase 2 - Panel contract

1. Add stable static uplink helpers: interface name, /30 addressing, sidecar WG listen port, local UoT/CF port allocation, and selected-lane lookup.
2. Rework sidecar management to build lanes from active forward combinations and selected rented slaves.
3. Add slave-node management `sidecar_static_uplinks` to `/api/node/tasks/pull`.
4. Add per-forward static lane selection so bond and MPTCP slave publish payloads use `relay_target` over WG.

## Phase 3 - Agents

1. Slave agent applies management-driven static uplinks independent of forward publish tasks.
2. Slave agent includes a lightweight watchdog: service active + recent handshake + optional peer ping/TCP target probe; failed lanes are restarted.
3. Sidecar agent keys uplink state by exact interface name and removes stale legacy `lw-sc-{point}-*` once static lanes exist.
4. Preserve existing CF wstunnel backend behavior on sidecar and add slave-side CF/WG client handling when `outer_transport=wstunnel_udp`.

## Phase 4 - Live rollout and verification

1. Inspect sh36 before cleanup; remove only deprecated/stale forward-specific WGs confirmed not needed.
2. Deploy panel first, then sidecar agent, then selected Shanghai/Tencent slaves. Leave Canton IPs untouched.
3. Verify node19/node20 have two static lanes each, point4 sidecar has matching lanes, stale `lw-sc-4-*` is gone, and forward59 backend targets use WG addresses instead of direct CNIX TCP.
4. Run smoke traffic through f59 and confirm handshakes/bytes stay fresh across at least two management cycles.
