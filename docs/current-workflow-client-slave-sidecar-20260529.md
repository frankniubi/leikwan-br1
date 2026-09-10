# LeiKwan client -> slave -> sidecar workflow (2026-05-29 sh36 build)

This document summarizes the current runtime contract after the sh36 slave-preconnect / static sidecar-WG rollout.  It intentionally avoids credentials and public slave IPs.

## 1. Invariants

- The intended production topology for slave-backed forwards is:

  `client -> selected slave(s) -> sidecar -> target`

- A forward combination is treated as desired state.  Once the panel publishes a forward/combination, the slave and sidecar agents should pre-create the needed chain and keep it warm.  Re-publishing identical state must not tear down or recreate WireGuard.
- Slave-side ingress ports for MPTCP and bond remain in the configured slave port range; this project keeps slave ingress bounded to `7000-9000`.
- In slave-preconnect mode, the client must not route directly to the sidecar overlay address.  Client WireGuard configs only carry the client->slave overlay peer address in `AllowedIPs` and routes.
- The slave->sidecar hop is always WireGuard at the logical layer.  The outer dial method differs by combination type:
  - normal/direct mode: slave dials the sidecar endpoint through the direct/CNIX path;
  - CF mode: slave dials a Cloudflare-optimized address and carries the WG UDP stream through the CF/wstunnel path.
- Health/latency observations are runtime facts, not configuration drift.  Client-agent compares an effective rendered contract signature and ignores observation-only churn, so stable panel data no longer causes full runtime re-apply.

## 2. Panel responsibilities

The panel is the desired-state authority.

1. Build candidate pools and selected endpoints for each forward.
2. For forward combinations, publish the slave->sidecar chain as static desired state:
   - sidecar point and endpoint identity;
   - WireGuard addresses/keys;
   - stable interface names and ports;
   - preconnect relay port/token;
   - CF mode metadata when the connection type starts with `CF` or the backend is `cf_mode`.
3. Publish matching management payloads:
   - to sidecar: sidecar uplink/listener state and any CF backend path;
   - to slave: static sidecar uplinks plus per-forward MPTCP/bond publish tasks;
   - to client: runtime config, candidate pool, MPTCP underlays, and artifact hashes.
4. Republish active forwards when combination or node desired state changes.  Republish should be idempotent because agents use stable fingerprints/signatures.

Key code paths:
- `master/src/Support/ProvisioningService.php`
- `master/src/Controllers/SidecarAgentController.php`
- `master/src/Controllers/NodeController.php`
- `master/src/Controllers/ArtifactController.php`

## 3. Sidecar responsibilities

The sidecar is the far end of the slave->sidecar WG chain and the runtime backend for bond/MPTCP traffic.

Normal/direct mode:
1. Sidecar agent receives `sidecar_uplinks` / MPTCP management state.
2. It creates or updates the sidecar WG config and peer for the slave.
3. It exposes the sidecar-side MPTCP/bond runtime listener and routes traffic to the final target.

CF mode:
1. Sidecar creates the same logical WG peer, but also exposes a CF/wstunnel backend path for WG UDP.
2. The sidecar-side CF backend maps the published path prefix to the local sidecar WG endpoint.
3. The slave connects to this path through a selected Cloudflare edge/IP; the WG peer still sees a normal WG packet stream.

Important implementation points:
- Sidecar uplinks are stored as managed state, not ad-hoc shell state.
- Stale sidecar transit interfaces for old point IDs are cleaned by point-aware logic.
- CF backends for slave transit are separate from client self-leg CF-WG routes.

Key code path:
- `sidecar-agent/sidecar_agent.py`

## 4. Slave responsibilities

The slave is the only client-visible relay for slave-backed mode.

For each published forward/underlay:
1. Create/keep the slave WG interface.
   - Peer 1: client overlay address/key.
   - Peer 2: sidecar overlay address/key, unless using a static sidecar uplink abstraction where the sidecar leg is represented by the static uplink.
2. Enable routing/forwarding between client-side WG traffic and sidecar-side WG traffic.
3. Start preconnect relay when `slave_preconnect` is set.
   - Client data connects to the slave relay port.
   - Health probe uses the relay health port.
   - Relay connects onward to the sidecar over the warm WG chain.
4. Start outer transport helpers only when needed:
   - direct/CNIX: direct WG endpoint;
   - CF mode: wstunnel UDP client to selected CF address/path;
   - UoT fallback only where explicitly selected and not colliding with CF relay mux ports.
5. Watch static sidecar uplinks / selector lanes and restart the active lane when inner WG latency degrades beyond policy.

The slave may choose a better CF edge from the configured CF candidate group.  This selection is local to the slave and should not create extra client-facing interfaces.

Key code paths:
- `slave-agent/slave_agent.py`
- `slave-agent/preconnect_relay.py`

## 5. Client responsibilities

The client consumes the panel config and renders local runtime services.

Common flow:
1. Pull config from panel.
2. Build effective runtime config from panel data plus local health state.
3. Compare effective contract signature with `applied.json`.
4. If no effective change and runtime units/interfaces exist, skip re-apply.
5. If changed, render service/unit/config files and restart only the affected forward.
6. Send heartbeat, usage counters, local capability and health observations back to panel.

Slave-preconnect WireGuard rule:
- For a leg such as `10.203.15.201 -> 10.203.15.202 -> 10.203.15.203`, the client WG now keeps only `10.203.15.202/32` in `AllowedIPs` and route state.
- The sidecar overlay IP (`10.203.15.203` in the example) is intentionally absent from the client config because the client only talks to the slave relay.

Key code paths:
- `client-agent/client_agent.py`
- `client-agent/install_forward_runtime.py`
- `client-agent/leikwan_mptcp_client.py`

## 6. Runtime modes

### 6.1 Single / race legacy mode

- Client opens one or more slave endpoints and forwards traffic to the selected backend.
- Health checks are endpoint ready probes.
- Not the main path for the current static slave->sidecar WG design.

### 6.2 Bond mode

Purpose: application-level aggregation/failover through several slave legs.

Flow:
`app -> client bond listener -> selected slave bond ingress -> slave relay -> slave->sidecar WG -> sidecar bond/MPTCP backend -> target`

Details:
- Client uses the bond runtime/helper and candidate pool ordering.
- Slave ingress remains in the allowed slave port range.
- If the combination is normal/direct, slave->sidecar WG dials the direct/CNIX endpoint.
- If the combination is CF, slave->sidecar WG is carried through the CF/wstunnel optimized path.
- Latency-first logic prefers healthy low-RTT legs and can keep a static primary for small/latency-sensitive flows.
- Runtime health snapshots and bond leg reputation are reported but do not count as config drift.

### 6.3 MPTCP mode

Purpose: kernel MPTCP subflows across multiple client->slave WG underlays while the sidecar path remains preconnected.

Flow:
`app -> client MPTCP listener -> MPTCP subflows over lw-mptcp-* WG interfaces -> slave preconnect relay -> slave->sidecar WG -> sidecar MPTCP runtime -> target`

Details:
- Client creates `lw-mptcp-{forward_id}-{index}` interfaces only for selected active legs.
- Client config hides public slave endpoints behind local/loopback transport where configured.
- Client WG `AllowedIPs` contains only the slave overlay peer for slave-preconnect legs.
- `leikwan_mptcp_client.py` sets up WG underlays and MPTCP endpoint policy (`ip mptcp endpoint ...`) when the kernel supports MPTCP.
- If the kernel lacks MPTCP and `allow_tcp_fallback` is enabled, runtime can continue in TCP fallback mode.
- Active leg selection is latency-first and panel-health-aware:
  - panel `health_status != active` prevents stale local health from selecting a pending/dead leg;
  - local latency and relay health choose primary/helper among active legs;
  - one standby preconnect leg may remain rendered where needed so health can recover.

### 6.4 CF-WG self-leg special path

This is separate from slave-preconnect MPTCP/bond.

Flow:
`client lw-cfwg-* -> local wstunnel UDP -> CF edge/path -> sidecar CF WG backend -> sidecar runtime -> target`

Details:
- Used only when the panel selects a CF-WG self-leg candidate.
- Kept isolated from slave MPTCP helper logic.
- Helper slave legs can coexist, but CF-WG self-leg selection must not cause all slave-assisted `lw-mptcp-*` handshakes to disappear.

## 7. Health and latency selection

Client side:
- MPTCP candidate health probes use preconnect relay health (`preconnect_http`) for slave-preconnect legs.
- Inner WG reachability is represented by ping/traffic to the allowed slave overlay IP.
- Health state is stored under client-agent state and used for runtime selection.
- Effective signatures strip observation-only fields (`last_latency_ms`, EWMA, quality score, probe state, etc.) before no-op comparison.

Slave side:
- Static sidecar uplink selector probes the sidecar WG peer/port and can switch or restart a lane when latency is bad.
- CF mode can reselect a better CF candidate based on local probes.

Sidecar side:
- Exposes the backend route and CF path; does not decide client leg ordering.

## 8. sh36 verification state for this build

The deployed sh36 build verified the intended client rendering:

- `lw-mptcp-62-0`: client route only to `10.203.15.206/32`
- `lw-mptcp-62-1`: client route only to `10.203.15.202/32`
- `lw-mptcp-64-0`: client route only to `10.203.24.94/32`
- `lw-mptcp-64-1`: client route only to `10.203.24.90/32`
- old sidecar overlay addresses were absent from client WG configs;
- endpoints were loopback-hidden on the client;
- repeated syncs reported no effective re-apply for f62/f64 after convergence.

## 9. Operational checklist

When debugging a new forward:

1. Panel: confirm the active forward payload and selected candidate pool.
2. Client: check `config_pull_state.json`, `applied.json`, and rendered `/etc/leikwan/mptcp-forward-*.json`.
3. Client: confirm `wg show` handshakes and that `AllowedIPs` only include slave overlay IPs for slave-preconnect legs.
4. Slave: confirm the preconnect relay and static sidecar uplink service are active.
5. Slave: for CF mode, confirm the selected CF dial endpoint/path and wstunnel client service.
6. Sidecar: confirm the sidecar uplink WG config and CF backend path exist.
7. Only after the above, test end-to-end app traffic or iperf.
