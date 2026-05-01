# MPTCP Refactor Blueprint

Status: design only.

Scope: lawful private-network interconnect, multi-WAN aggregation, and service forwarding on infrastructure you control.

This document describes how to refactor the current Leikwan Bridge data plane into an MPTCP-centered architecture while preserving the existing panel and agent split where possible.

## 1. What exists today

The current repo already has an application-layer "bond" mode:

- `master/src/Support/ProvisioningService.php` builds a client config with `mode`, `transport`, `endpoints`, and a `bonding` block.
- `client-agent/install_forward_runtime.py` renders `bond` forwards by starting multiple `wstunnel` legs plus a local `leikwan-bond client`.
- `slave-agent/slave_agent.py` publishes per-endpoint listeners and optional QUIC relay helpers.

In other words, today's "bond" mode is not kernel MPTCP. It is a user-space multipath forwarder layered on top of per-slave WebSocket/QUIC relays.

## 2. Why native MPTCP is not a drop-in replacement

Native MPTCP only works when both ends of the TCP connection speak MPTCP. In the current topology, each slave terminates or relays transport for the client, so the end-to-end data path is not yet shaped like a normal client<->sidecar MPTCP connection.

That means:

- We should not try to "teach each slave to do MPTCP" for the same end-to-end stream.
- We should make the slave act like a routed transit leg, not the owner of the aggregated TCP session.
- The actual MPTCP endpoints should be the client-side runtime and the sidecar runtime.

This also matches the Linux MPTCP model, where subflows are negotiated between the two hosts that own the socket, and additional addresses are managed by the path manager.

## 3. Recommended target architecture

Use a three-layer data plane:

1. Underlay tunnel per slave
2. End-to-end MPTCP between client and sidecar
3. Service proxy / forwarder on top

The key change is that slaves stop being "application forward owners" for bond mode and become "transit gateways" that provide distinct network paths.

### 3.1 Roles

- `master`
  - keeps orchestration, inventory, auth, plan binding, and artifacts
  - provisions leg groups, tunnel profiles, and sidecar policies
- `client-agent`
  - Linux full mode: owns the MPTCP socket and local port forward listener
  - Windows/Android compatibility mode: consumes the same control plane, but uses a portable fallback described later
- `slave-agent`
  - manages one transit leg per client/sidecar pair
  - sets up tunnel endpoints, routing, health probes, and accounting
  - no longer owns the aggregated TCP stream in MPTCP mode
- `sidecar-agent`
  - new runtime role
  - terminates MPTCP sessions
  - exposes the final proxy service to the target backend

### 3.2 Per-leg model

For each slave `N`, provision one independent leg:

- client has a tunnel-facing source IP for leg `N`
- sidecar has a tunnel-facing destination IP for leg `N`
- slave routes only that leg's traffic between the two

Example logical model:

- leg 1: client `100.96.1.2` -> slave 1 -> sidecar `100.97.1.2`
- leg 2: client `100.96.2.2` -> slave 2 -> sidecar `100.97.2.2`

The client runtime opens one MPTCP connection to the sidecar and advertises or joins additional subflows using those per-leg addresses.

### 3.3 Data path

The new TCP path should be:

`local app -> client local listener -> MPTCP socket -> per-slave tunnel legs -> sidecar MPTCP listener -> target backend`

This is the important inversion versus the current design:

- current `bond`: app-layer striping over many relays
- target `mptcp`: one transport connection with many subflows

## 4. Underlay recommendation

### 4.1 Preferred: WireGuard underlay

Use WireGuard for the per-leg underlay unless you need native enterprise IKEv2 integration.

Why:

- simple operational model
- available on Linux, Windows, and Android
- fast enough for a slave-transit role
- good fit for creating distinct per-leg routed addresses

In this design, WireGuard provides encrypted per-leg reachability. MPTCP above it provides TCP aggregation and failover.

### 4.2 Alternate: IKEv2/IPsec underlay

Use IKEv2/IPsec when you need tighter OS-native VPN integration, certificate-based deployment, or mobility handling through standard enterprise stacks.

MOBIKE is useful for path changes and address migration, but IPsec by itself does not replace MPTCP's TCP subflow aggregation. Treat it as the secure underlay, not the aggregation layer.

### 4.3 What to avoid

Do not build the MPTCP path directly on top of the current per-leg `wstunnel` termination model. That preserves too much application-layer relay behavior and leaves the slave in the wrong place in the stack.

Keep the current WSS relay mode only as:

- legacy compatibility mode
- environments where only a generic TCP egress relay is available
- a fallback for non-Linux direct clients

## 5. Linux-first runtime design

Linux should be the first-class path because native MPTCP support is Linux-oriented.

### 5.1 Client runtime

Add a new client runtime mode:

- `single`
- `race`
- `bond-legacy`
- `mptcp`

In `mptcp` mode, the client runtime should:

- create a local TCP listener for the forwarded service
- open `IPPROTO_MPTCP` sockets to the sidecar
- register leg addresses with the in-kernel path manager or a userspace path manager
- maintain per-leg policy and health state
- expose counters per subflow and per forward

Do not keep the current `leikwan-bond client` contract for this mode. Native MPTCP wants a new runtime boundary.

### 5.2 Sidecar runtime

Create a dedicated `sidecar-agent/` with two responsibilities:

- tunnel-leg management
- MPTCP service termination

For each published forward, the sidecar runtime should:

- bind the MPTCP listener
- authenticate the forward/session
- proxy accepted TCP sessions to the final backend
- export per-subflow stats back to `master`

### 5.3 Slave runtime

The slave agent should gain a new operating profile:

- `relay_mode = app`
- `relay_mode = transit`

For `relay_mode = transit`, the slave should:

- create or rotate tunnel peers
- route packets between client leg and sidecar leg
- measure health, latency, loss, and byte counters
- avoid terminating the MPTCP stream

That is the biggest conceptual change in the refactor.

## 6. UDP strategy

MPTCP only aggregates TCP. So UDP needs a separate policy.

### 6.1 Preferred UDP policy

Use native UDP through the secure underlay:

- WireGuard or IPsec transports the packets
- sidecar runs a normal UDP proxy/forwarder
- client and sidecar keep UDP outside the MPTCP engine

This is the best-performing and least surprising option.

### 6.2 Compatibility fallback: UDP over stream

If you still need a "UDP over TCP-like" compatibility mode, keep it explicit and separate:

- `udp_mode = native`
- `udp_mode = stream-compat`

For `stream-compat`, use a length-prefixed datagram framing protocol over a reliable stream. That stream may be:

- an MPTCP socket on Linux
- a plain TCP/TLS stream in compatibility mode

Important operational rule:

- `stream-compat` should be opt-in and default-off
- it will suffer from head-of-line blocking
- it should be marketed as "reachability fallback", not "high-performance UDP"

## 7. Cross-platform stance

This repo can be cross-platform at the control-plane level, but the data-plane feature set should be tiered.

### 7.1 Linux

Linux gets the full design:

- native MPTCP sockets
- per-leg routing
- multiple subflows
- first-class observability

### 7.2 Windows

Recommended Windows strategy:

- use WireGuard or IKEv2/IPsec for the secure underlay
- if full native MPTCP behavior is required, place a small Linux edge gateway near the Windows host and let Windows forward through that gateway
- otherwise run a portable non-MPTCP client mode and accept reduced behavior

Inference from official Linux MPTCP docs: because `IPPROTO_MPTCP` and the socket workflow are explicitly Linux-specific there, this project should not assume identical native MPTCP semantics on Windows.

### 7.3 Android

Recommended Android strategy:

- use the same underlay profiles as Windows
- prefer WireGuard for packaging simplicity
- use a nearby Linux aggregation gateway if you need true end-to-end MPTCP behavior

Again, the control plane can stay unified even if the direct device data plane is not identical.

## 8. Control-plane schema changes

The current schema centers on `client_mode`, `transport_mode`, `bond_remote_host`, `bond_remote_port`, and a `bonding` blob. Keep those only for legacy mode.

Add a new model centered on leg groups and overlay type.

Suggested forward fields:

- `forward_mode`
  - `single`
  - `race`
  - `bond_legacy`
  - `mptcp`
- `underlay_type`
  - `wireguard`
  - `ikev2_ipsec`
  - `legacy_wss`
- `udp_mode`
  - `native`
  - `stream_compat`
- `sidecar_point_id`
- `leg_group_id`
- `mptcp_policy_json`
- `underlay_profile_id`

Suggested leg record fields:

- `leg_id`
- `forward_id`
- `slave_node_id`
- `client_leg_ip`
- `sidecar_leg_ip`
- `weight`
- `backup_only`
- `health_status`
- `latency_ms`
- `loss_ppm`
- `bytes_in`
- `bytes_out`

Suggested policy fields inside `mptcp_policy_json`:

- subflow limit
- backup leg flags
- path-manager mode
- stale-loss threshold
- connect timeout
- per-leg weight hints

## 9. Repo changes by module

### 9.1 `master`

Update `master/src/Support/ProvisioningService.php` so that:

- `buildClientConfig()` can emit `mptcp` forwards
- leg allocation is explicit, not implied by endpoint count
- sidecar and transit credentials are separated
- client config contains leg IPs and underlay peer data

Add new APIs or extend existing ones for:

- sidecar registration / heartbeat
- underlay artifact manifests
- per-leg health and per-subflow stats

### 9.2 `client-agent`

Split runtime generation into backends:

- `render_single_runtime`
- `render_race_runtime`
- `render_legacy_bond_runtime`
- `render_mptcp_runtime`

The current `install_forward_runtime.py` is a good entry point for that split, but the new `mptcp` path should not shell out to the old bond binary.

### 9.3 `slave-agent`

Keep existing app-relay support for legacy services, but add:

- tunnel interface manager
- route/rule manager
- transit-leg accounting
- leg health probe endpoints

### 9.4 `sidecar-agent`

Add a new directory and artifact family:

- `sidecar-agent/sidecar_agent.py` or a new Rust binary if performance is the priority
- install scripts
- systemd units
- artifact manifest entries

## 10. Migration plan

### Phase 1

- keep current `single`, `race`, `bond` working
- rename current `bond` internally to `bond_legacy`
- add schema support for `mptcp`

### Phase 2

- introduce `sidecar-agent`
- introduce per-leg underlay provisioning on slave and sidecar
- no customer traffic on MPTCP yet

### Phase 3

- add Linux client `mptcp` runtime
- enable one lab service end to end
- verify subflow creation, failover, and observability

### Phase 4

- add native UDP forwarding over underlay
- add `udp_mode = stream_compat` only if still required

### Phase 5

- add Windows/Android compatibility packaging
- decide whether they connect directly in portable mode or via a Linux edge gateway

### Phase 6

- deprecate `bond_legacy` after operational burn-in

## 11. Testing matrix

Minimum matrix:

- Linux client + Linux sidecar + 2 slaves
  - TCP single stream throughput
  - many concurrent TCP sessions
  - leg failure during active transfer
  - asymmetric RTT between legs
- Linux client + Linux sidecar + native UDP
  - DNS-like short flows
  - RTP-like sustained datagrams
- Windows host via Linux edge gateway
  - TCP forwarding
  - reconnect behavior
- Android via Linux edge gateway
  - roaming between local interfaces
  - tunnel reconnect behavior

Observability you want before production:

- per-forward bytes
- per-leg bytes
- per-leg RTT and loss
- active subflow count
- TCP fallback detection
- sidecar accept backlog

## 12. Recommended implementation order for this repo

If this repo is the starting point, the lowest-risk order is:

1. Add `mptcp` as a new forward mode without deleting old `bond`
2. Add `sidecar-agent`
3. Teach `slave-agent` transit mode
4. Teach `client-agent` Linux-native MPTCP
5. Add underlay artifact and key distribution
6. Add portable compatibility mode for Windows/Android

Do not try to replace the current `bond` code in one shot. Ship the new path beside it, validate it, then retire the legacy path.

## 13. Key design decisions

- MPTCP should be end to end between client and sidecar, not hop by hop on slaves.
- Slaves should become transit gateways for MPTCP mode.
- WireGuard is the simplest preferred underlay; IKEv2/IPsec is the enterprise-native alternate.
- Native UDP over the underlay should be the default UDP design.
- UDP-over-stream should stay an explicit compatibility mode.
- Linux is the first-class MPTCP endpoint; Windows and Android should be treated as compatibility clients unless you insert a Linux edge gateway.

## 14. External references

These sources were used to validate the design assumptions:

- Linux kernel MPTCP documentation: https://www.kernel.org/doc/html/latest/networking/mptcp.html
- MPTCP for Linux project docs: https://www.mptcp.dev/
- MPTCP setup notes: https://www.mptcp.dev/setup.html
- WireGuard installation and platform support: https://www.wireguard.com/install/
- strongSwan MOBIKE documentation: https://docs.strongswan.org/docs/latest/features/mobike.html
