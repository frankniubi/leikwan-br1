# 2026-05-24 — f47: Route Both MPTCP Legs Through Cloudflare

## Goal

Make forward 47 work without any required direct UDP path by routing both MPTCP underlay legs through Cloudflare/WSS:

1. `lw-cfwg-47` client/self leg: client -> CF/WSS -> sidecar WG endpoint.
2. `lw-mptcp-47-0` slave helper leg: client -> CF/WSS -> slave WSS mux -> slave local WG UDP endpoint -> slave WG overlay -> sidecar.

The target topology stays strictly:

```text
client/sh36
  -> Cloudflare WSS
  -> slave public WSS mux on managed 7000-9000 port
  -> slave local wstunnel backend
  -> slave local WG UDP endpoint for lwf{forward}n{node}
  -> slave-side WG overlay
  -> sidecar MPTCP/preconnect relay target
```

Do not introduce direct client -> sidecar routing for this helper leg.

## Current status

### Done before this update

- Slave -> sidecar CF path is deployed and verified.
  - Slave underlay uses `outer_transport=wstunnel_udp`.
  - Slave WG peer endpoint is `127.0.0.1:{local_cf_backend_port}` instead of direct sidecar public UDP.
  - Slave-side CF backend service pattern: `leikwan-mptcp-cf-{interface}`.
- Sidecar token-secret propagation was fixed so slave preconnect tokens use the sidecar point token secret.
- Preconnect relay warm-pool behavior was corrected to keep pool entries transport-warm and send session handshake at checkout time.
- Relay filtering patch exists locally so client runtime can avoid stale/dead relay candidates.
- Focused tests already cover the slave -> sidecar CF underlay and token source.

### Done in this implementation pass

Implemented the second layer: client -> slave via Cloudflare/WSS for MPTCP `slave_transit` underlays.

Changed files:

1. `master/src/Support/ProvisioningService.php`
   - For CF-mode MPTCP client runtime helper legs, `mptcpUnderlayLegs()` now switches eligible slave-transit legs from raw UDP to `outer_transport=wstunnel_udp_via_slave` when the selected slave reports `supports_cfwg_relay` and the stable slave WG port remains inside the managed `7000-9000` range.
   - Client runtime leg now uses:
     - `peer_public_host=127.0.0.1`
     - `peer_udp_port={stable local client wstunnel UDP listener}`
     - `slave_dial_host={slave public host}`
     - `slave_dial_port={stable slave WG port}`
     - `slave_sni_host={endpoint/panel SNI}`
     - `slave_path_prefix={autoCfwgPath(forward, mptcp_slave_transit, node)}`
     - `cf_target_host=127.0.0.1`
     - `cf_target_port={stable slave WG port}`
   - The slave publish payload now carries matching `client_cf_relay_*` fields inside `mptcp_underlay`, so the slave agent knows to expose a WSS mux route to its local WG UDP port.
   - Added stable local client CF/WG UDP listener allocation via `stableLocalCfwgUdpPort()`.

2. `client-agent/install_forward_runtime.py`
   - Startup script now scans all rendered underlays, not just self-leg underlays, when launching `wstunnel_udp` / `wstunnel_udp_via_slave` local listeners.
   - This means normal MPTCP helper underlays from panel `mptcp.underlays[]` now get their client-side wstunnel process before `--setup-underlays` runs.

3. `client-agent/leikwan_mptcp_client.py`
   - WG config rendering now treats `wstunnel_udp` and `wstunnel_udp_via_slave` endpoints as localhost endpoints.
   - This prevents `lw-mptcp-47-0.conf` from rendering `Endpoint = 49.235.160.246:7329` and instead renders `Endpoint = 127.0.0.1:{local_wstunnel_udp_port}`.

4. `slave-agent/slave_agent.py`
   - Added `ensure_mptcp_client_cf_relay_service()`.
   - Added service naming: `leikwan-mptcp-client-cf-{interface}`.
   - The slave creates a local wstunnel server backend and registers a mux route:
     - public/listen port: stable slave WG port in `7000-9000`
     - WSS path: `client_cf_relay_path_prefix`
     - backend service: `wstunnel server ws://127.0.0.1:{backend_port}`
     - restrict target: `127.0.0.1:{slave_wg_port}`
   - `apply_mptcp_underlay()` creates/removes this backend based on `client_cf_relay_enabled`.
   - `remove_mptcp_underlay()` and orphan cleanup remove the relay service as part of underlay cleanup.

5. Tests updated/added
   - `master/tests/provisioning_cfwg_relay_path_contract_test.php`
     - Verifies CF-mode client runtime emits `wstunnel_udp_via_slave` for the MPTCP slave-transit helper leg.
     - Verifies client WG endpoint host is localhost and no longer the slave public UDP endpoint.
     - Verifies panel publishes matching slave dial/path/target fields.
   - `master/tests/provisioning_mptcp_sidecar_wireguard_port_regression_test.php`
     - Verifies slave publish `mptcp_underlay` includes `client_cf_relay_*` fields for CF-mode capable slaves.
     - Verifies non-CF mode does not enable client CF relay.
   - `client-agent/tests/test_cfwg_slave_relay_runtime.py`
     - Verifies rendered client start script launches the `wstunnel_udp_via_slave` local listener for normal MPTCP helper underlays.
     - Verifies WG config points to `127.0.0.1:{local_listener}`, not `slave_public_ip:{blocked_udp_port}`.
   - `slave-agent/tests/test_slave_cfwg_relay_safety.py`
     - Verifies slave mux route registration and backend unit for client -> slave CF relay.

## Verification already run locally

```bash
php -l master/src/Support/ProvisioningService.php
php master/tests/provisioning_mptcp_sidecar_wireguard_port_regression_test.php
php master/tests/provisioning_cfwg_relay_path_contract_test.php
py -3 -m py_compile client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py slave-agent/slave_agent.py
py -3 -m unittest client-agent.tests.test_cfwg_slave_relay_runtime slave-agent.tests.test_slave_cfwg_relay_safety -v
```

Result:

- PHP lint passed.
- PHP focused tests passed.
- Python compile passed.
- Python focused tests passed: 21 tests OK.
- PHP emits an environment warning about invalid `date.timezone=UTC+8`; this warning is pre-existing and not a test failure.

## Remaining rollout work

The code is implemented locally, but the task is not complete until deployed and live-verified on f47.

### Phase 1 — Review local diff

Steps:

1. Review the five changed source/test areas listed above.
2. Confirm the new topology remains `client -> slave -> sidecar`.
3. Confirm no credentials/tokens/private keys are present in tracked files.
4. Confirm all new public ingress for the slave helper leg stays inside the managed `7000-9000` range.
5. Confirm `autoCfwgPath(..., 'mptcp_slave_transit', node_id)` creates a stable path independent of endpoint-row churn.

Review criteria:

- Good enough to proceed if:
  - Source changes are limited to panel payload, client runtime rendering, slave mux backend, and focused tests.
  - No direct client -> sidecar helper path was introduced.
  - No direct UDP dependency remains in the rendered client WG endpoint for CF-mode slave-transit legs.
- Not good enough if:
  - The client still renders `Endpoint = {slave_public_ip}:{slave_wg_port}` for `lw-mptcp-47-0`.
  - The slave does not receive `client_cf_relay_*` fields.
  - The slave WSS mux path is missing or collides with an existing path.

Estimated work: 20-40 minutes.

### Phase 2 — Deploy panel change

Steps:

1. Upload/update the panel code containing `ProvisioningService.php`.
2. Run panel PHP lint on the target host.
3. Republish f47 endpoint 499 or run the existing endpoint reconcile flow for f47.
4. Inspect the queued publish payload before/after the slave consumes it.

Expected payload evidence:

```json
{
  "mptcp_underlay": {
    "outer_transport": "wstunnel_udp",
    "client_cf_relay_enabled": true,
    "client_cf_relay_listen_port": 7329,
    "client_cf_relay_path_prefix": "<camouflage path>",
    "client_cf_relay_target_host": "127.0.0.1",
    "client_cf_relay_target_port": 7329
  }
}
```

For the client config, expected `mptcp.underlays[slave_transit].legs[0]` evidence:

```json
{
  "outer_transport": "wstunnel_udp_via_slave",
  "peer_public_host": "127.0.0.1",
  "peer_udp_port": "<local client UDP listener>",
  "slave_dial_host": "49.235.160.246",
  "slave_dial_port": 7329,
  "slave_path_prefix": "<same camouflage path>",
  "cf_target_host": "127.0.0.1",
  "cf_target_port": 7329
}
```

Acceptance criteria:

- Panel publish payload to the slave includes `client_cf_relay_enabled=true`.
- Client runtime JSON for f47 includes `outer_transport=wstunnel_udp_via_slave` for the helper leg.
- No f47 helper leg uses raw `outer_transport=udp` when CF-mode and slave supports cfwg relay.

Estimated work: 30-60 minutes.

### Phase 3 — Deploy slave agent change

Steps:

1. Upload the updated `slave-agent/slave_agent.py` to the active slave for endpoint 499.
2. Restart or let the slave agent pick up the updated code according to the existing deployment workflow.
3. Ensure the slave consumes the new publish task for f47.
4. Inspect slave state/systemd:
   - `lwf47n{node}` WG interface config exists.
   - `leikwan-mptcp-client-cf-lwf47n{node}.service` exists.
   - listener routes for the stable slave WG port include `client_cf_relay_path_prefix`.

Acceptance criteria:

- Slave has a wstunnel server backend unit containing:
  - `wstunnel server ws://127.0.0.1:{backend_port}`
  - `--restrict-to "127.0.0.1:7329"`
  - `--restrict-http-upgrade-path-prefix "<client_cf_relay_path_prefix>"`
- Slave WSS mux route file for port `7329` includes the same path and backend port.
- Slave WG interface remains up after the relay service is added.
- No direct external UDP access to `7329` is required for the client to handshake.

Estimated work: 30-60 minutes.

### Phase 4 — Deploy client agent/runtime change

Steps:

1. Upload updated `client-agent/install_forward_runtime.py` and `client-agent/leikwan_mptcp_client.py` to sh36/client deployment path.
2. Deploy the relay-filter patch in `client-agent/client_agent.py` if not already deployed.
3. Force client runtime refresh for f47.
4. Inspect `/etc/leikwan/mptcp-forward-47.json` and generated WG config.

Acceptance criteria:

- `mptcp-forward-47.json` contains the helper underlay with `outer_transport=wstunnel_udp_via_slave`.
- Startup script contains a wstunnel command like:

```bash
wstunnel client -L udp://127.0.0.1:{local_udp}:127.0.0.1:7329?timeout_sec=0 \
  --tls-sni-override <slave_sni> \
  --http-headers "Host: <slave_sni>" \
  --http-upgrade-path-prefix "<slave_path_prefix>" \
  wss://49.235.160.246:7329
```

- `lw-mptcp-47-0.conf` contains:

```text
Endpoint = 127.0.0.1:{local_udp}
```

- `lw-mptcp-47-0.conf` does not contain:

```text
Endpoint = 49.235.160.246:7329
```

Estimated work: 30-60 minutes.

### Phase 5 — Live connectivity acceptance

Steps:

1. On slave, confirm services:
   - slave agent active
   - `wg-quick@lwf47n{node}` active
   - `leikwan-mptcp-client-cf-lwf47n{node}` active
   - mux service for stable slave WG port active
2. On client/sh36, restart f47 runtime after config refresh.
3. Confirm client local wstunnel UDP listener is bound.
4. Confirm WG handshakes:
   - `lw-cfwg-47` fresh handshake
   - `lw-mptcp-47-0` fresh handshake
5. Confirm no successful path depends on direct UDP from sh36 to `49.235.160.246:7329`.

Acceptance criteria:

- `wg show lw-mptcp-47-0` shows a fresh latest handshake after direct UDP is blocked.
- `wg show lw-cfwg-47` also shows a fresh latest handshake.
- Client-side `lw-mptcp-47-0` peer endpoint is localhost.
- Slave-side mux logs show traffic on the new `client_cf_relay_path_prefix`.
- Slave `lwf47n{node}` interface receives traffic while client `lw-mptcp-47-0` is active.

Estimated work: 30-90 minutes depending on agent polling and service restart timing.

### Phase 6 — Throughput and aggregation acceptance

Steps:

1. Run TCP golden-path test through f47 client listen port, e.g. `127.0.0.1:11035`.
2. Run UDP test through the same port.
3. Check MPTCP subflows during the run.
4. Compare single-leg throughput vs both-leg throughput.

Acceptance criteria for task completion:

- TCP path succeeds through `127.0.0.1:11035`.
- UDP path succeeds through `127.0.0.1:11035`.
- Both `lw-cfwg-47` and `lw-mptcp-47-0` show fresh WG handshakes.
- MPTCP state shows multiple active subflows, not just standby metadata.
- Aggregate throughput is measurably above the better single-leg baseline, or if not, the bottleneck is identified with evidence.
- Direct UDP to the slave public WG port can be blocked and f47 still works.

Definition of done:

- Code merged/deployed to panel, slave, and client.
- f47 republished.
- Client runtime and slave runtime both show CF/WSS helper routing.
- End-to-end TCP and UDP tests pass.
- Evidence captured for WG handshakes, mux route, wstunnel process, and MPTCP subflows.

Estimated work: 1-2 hours after deployment is ready.

## Optimization methods after basic acceptance

### 1. Pool sizing and stale-entry control

Current safe baseline:

- Keep `preconnect_pool_size=2` and `preconnect_uot_pool_size=2` until both CF legs are stable.
- Keep `preconnect_stream_max_idle_s=120` or lower if stale pooled sockets appear.

Optimize by:

1. Track pool checkout failure rate.
2. Track stale idle age at checkout.
3. Increase pool size only if:
   - RSS/FD count remains safe.
   - Slave does not approach prior resource-exhaustion patterns.
   - Checkout latency is the bottleneck.

Good result:

- Pool hit rate improves without increasing stale checkout failures.
- Slave memory and FD count remain stable over at least 30 minutes of repeated tests.

### 2. CF edge selection

Optimize by:

1. Compare client -> slave CF RTT and slave -> sidecar CF RTT separately.
2. Prefer CF IPs with lower end-to-end handshake success and stable RTT, not only raw TCP connect latency.
3. Cache chosen edge briefly, but re-probe on repeated handshake failure.

Good result:

- Both CF legs converge to stable low-loss edges.
- Edge changes reduce loss/RTT without causing frequent reconnect churn.

### 3. Aggregate vs standby behavior

Check whether `lw-mptcp-47-0` is truly contributing traffic or only acting as backup.

Optimize by:

1. Inspect `mptcp_endpoints[]` roles in client runtime JSON.
2. Ensure the helper leg is not accidentally forced to `backup_only=true` when aggregate throughput is the goal.
3. Test with one leg disabled, then both enabled.

Good result:

- Both legs carry traffic during bulk TCP tests.
- Combined throughput exceeds single-leg baseline.

### 4. UDP loss tuning

Optimize by:

1. Test MTU values on both WG interfaces.
2. Compare UDP receiver loss at fixed bitrates: 100M, 200M, 300M.
3. Check socket buffer settings and relay framing overhead.
4. Keep changes incremental; do not mask packet loss with excessive buffering if latency becomes unstable.

Good result:

- UDP receiver loss drops materially at the target bitrate.
- TCP throughput remains stable.
- No increase in handshake failures or stale preconnect entries.

### 5. Resource guardrails

Track after each tuning step:

- Slave RSS.
- File descriptor count.
- Number of wstunnel processes.
- Number of WG interfaces.
- systemd restart count.
- mux route count per public port.

Good result:

- No unbounded service/unit growth after repeated republish cycles.
- Destroy/republish cleans up `leikwan-mptcp-client-cf-*` units.
- Orphan cleanup removes stale relay units when corresponding underlay disappears.

## Failure narrowing tree

If f47 still fails after rollout:

1. Client config wrong:
   - Check `mptcp-forward-47.json`.
   - Check `lw-mptcp-47-0.conf` endpoint.
   - Expected: localhost endpoint and `wstunnel_udp_via_slave`.
2. Client local wstunnel not running:
   - Check start script output and local UDP listener.
   - Expected: local UDP listener on `peer_udp_port`.
3. Slave WSS mux route missing:
   - Check slave route file for `client_cf_relay_path_prefix`.
   - Expected: route points to `leikwan-mptcp-client-cf-*` backend port.
4. Slave backend not running:
   - Check `leikwan-mptcp-client-cf-lwf47n{node}.service`.
   - Expected: wstunnel server restricted to `127.0.0.1:7329`.
5. Slave WG underlay broken:
   - Check `wg show lwf47n{node}`.
   - Expected: client peer and sidecar peer present, sidecar peer fresh handshake.
6. Preconnect relay broken:
   - Check preconnect relay logs and sidecar overlay address/port.
   - Expected: relay connects to sidecar overlay `10.203.x.3:8898`.
7. MPTCP not aggregating:
   - Check MPTCP endpoint roles and subflow state.
   - Expected: multiple active subflows during bulk transfer.

## What is not done yet

- The local code has not been deployed in this pass.
- f47 has not been republished after this local implementation.
- Live sh36/slave/sidecar verification still needs to be run.
- Aggregate throughput proof is still pending.
- Relay-filter patch deployment is still pending if not already applied on sh36.
