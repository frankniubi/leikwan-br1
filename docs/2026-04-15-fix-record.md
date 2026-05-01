# 2026-04-15 Fix Record

## Summary

This record captures the code and operational fixes completed during the 2026-04-15 repair session for:

- `forward 23` bond downlink behavior
- `forward 24` MPTCP/sidecar runtime behavior
- deleted forward `19/20` stale WireGuard cleanup on client and slave

The main goal of this session was to move fixes into panel-managed downlink logic instead of relying on one-off host-side manual repair.

## Completed Fixes

### 1. Panel bond QUIC downlink gating

`forward 23` was confirmed to be a panel downlink issue rather than a stale client issue.

Implemented:

- Added a single `nodeSupportsBondQuic(...)` decision point in `master/src/Support/ProvisioningService.php`
- `supports_quic_leg` now uses that helper
- adaptive bond join-timeout QUIC leg counting now uses that helper
- bond slave publish payloads now emit `leg_relay_protocols=['quic']` only when the node is explicitly trusted for bond QUIC

Behavior change:

- slave nodes are treated as QUIC-capable only when:
  - `supports_quic=true`
  - `bond_protocols` contains `"quic"`
  - `agent_version >= 2026.04.15.2`

Result:

- panel `buildClientConfig(23)` now generates `supports_quic_leg=false` for the Guangzhou slave pool
- client `leikwan-client-start-23.sh` now uses only `tcp://127.0.0.1:*` bond legs and no longer emits `quic://...` primary legs

### 2. Slave authoritative capability defaults

Implemented in `slave-agent/slave_agent.py`:

- `AGENT_VERSION` bumped to `2026.04.15.2`
- default `supports_quic=False`
- default `bond_protocols=["tcp"]`

Implemented in `artifacts/versions.json`:

- `slave_agent.version` bumped to `2026.04.15.2`

This ensures future heartbeats converge panel state away from stale `supports_quic=true` assumptions.

### 3. Sidecar multi-point state isolation

Implemented in `master/src/Controllers/SidecarAgentController.php`:

- sidecar heartbeat management now includes `config_patch` with point-specific:
  - `service_name`
  - `state_dir`
  - `runtime_config_path`

Implemented in `sidecar-agent/sidecar_agent.py`:

- sidecar agent now applies `config_patch` before writing runtime assets

Result:

- sidecar points no longer need to share a single `/var/lib/leikwan-sidecar`
- point `2` and point `3` can converge onto separate state directories and runtime config paths through normal panel heartbeat flow

### 4. MPTCP UoT local port fix

Implemented in `master/src/Support/ProvisioningService.php`:

- panel now emits `local_uot_udp_port` for each MPTCP TCP-fallback leg

Implemented in:

- `client-agent/leikwan_mptcp_client.py`
- `sidecar-agent/leikwan_mptcp_sidecar.py`

Behavior:

- runtime uses panel-authored `local_uot_udp_port` first
- deterministic fallback is retained only when panel omitted the field

Result:

- `forward 24` no longer reuses a single local UDP port for all 5 UoT legs
- the earlier `9968` local port collision class was eliminated

### 5. Sidecar runtime fail-fast and point-safe cleanup

Implemented in `sidecar-agent/sidecar_agent.py`:

- `start-sidecar.sh` no longer swallows `--setup-only` failures with `|| true`

Implemented in `sidecar-agent/leikwan_mptcp_sidecar.py`:

- cleanup of stale `lw-sc-*` interfaces is now scoped to the current point prefix instead of every `lw-sc-*` interface on the machine
- `wg-quick` start/restart is now validated so missing interfaces fail early
- `ensure_uot_client_service()` avoids unnecessary repeated restart churn when the unit is already enabled and active

Result:

- point `2` and point `3` no longer mutually delete each other’s `lw-sc-*` underlays
- sidecar runtime failures now surface at the actual failing underlay rather than being silently masked

### 6. MPTCP allow-TCP-fallback propagation

Implemented in `master/src/Support/ProvisioningService.php`:

- `buildMptcpClientConfig()` now defaults `allow_tcp_fallback=true`

Implemented in `sidecar-agent/sidecar_agent.py`:

- sidecar default `allow_tcp_fallback=True`

Implemented in `client-agent/install_forward_runtime.py`:

- `allow_tcp_fallback` is now written into `/etc/leikwan/mptcp-forward-<id>.json`

Implemented in `client-agent/client_agent.py`:

- semantic fingerprint now includes `install_forward_runtime.py` SHA so helper changes force runtime regeneration

Result:

- panel truth for `forward 24` now includes TCP fallback
- client runtime config regeneration is no longer missed when only the helper changes

### 7. Deleted forward stale WG cleanup on client

Implemented in `client-agent/client_agent.py`:

- `stop_forward(..., delete_files=True)` now explicitly cleans MPTCP runtime assets
- added global orphan MPTCP runtime cleanup during sync

Cleanup covers:

- `wg-quick@lw-mptcp-*`
- `/etc/wireguard/lw-mptcp-*.conf`
- `leikwan-uot-lw-mptcp-*`

Observed result on the client host:

- stale `lw-mptcp-19-*` and `lw-mptcp-20-*` interfaces were removed
- only live `lw-mptcp-24-*` interfaces remained

### 8. Deleted forward stale WG cleanup on slave

Implemented in `slave-agent/slave_agent.py`:

- `remove_mptcp_underlay()` now honors the real `interface_name` from metadata when available
- added `cleanup_orphan_mptcp_underlays()`
- orphan cleanup now:
  - stops/disables `wg-quick@...`
  - runs `wg-quick down`
  - runs `ip link delete dev ...`
  - removes related UoT server units
  - resets failed `wg-quick@...` units

Observed result on the sampled slave host:

- `lwf19*` stale runtime was cleared
- `lwf20n5` WireGuard interface disappeared from `wg show interfaces`
- the remaining `wg-quick@lwf20n5.service failed` unit residue was identified as a systemd failure-state artifact and reset

## Verification Performed

### Automated checks

Ran successfully:

- `python -m unittest client-agent/tests/test_mptcp_bond_regressions.py`
- `python -m unittest slave-agent/tests/test_slave_mptcp_cleanup.py`
- `php master/tests/bond_quic_capability_regression_test.php`
- `php master/tests/sidecar_downlink_regressions_test.php`
- full local `master/tests/*.php` run during panel downlink work
- `python -m py_compile client-agent/client_agent.py`
- `python -m py_compile client-agent/install_forward_runtime.py`
- `python -m py_compile sidecar-agent/sidecar_agent.py`
- `python -m py_compile sidecar-agent/leikwan_mptcp_sidecar.py`
- `python -m py_compile slave-agent/slave_agent.py`

### Panel truth checks

Verified on panel:

- `buildClientConfig(23)` now returns `supports_quic_leg=false` for endpoints `169-173`
- latest slave publish tasks for nodes `4/5/6/7/8` now carry `leg_relay_protocols=[]`
- `buildClientConfig(24)` includes unique `local_uot_udp_port` values
- `buildClientConfig(24)` now defaults `allow_tcp_fallback=true`

### Host-side observations

Verified on client:

- `leikwan-client-start-23.sh` switched from QUIC legs to WSS/TCP-only bond legs
- stale `lw-mptcp-19-*` and `lw-mptcp-20-*` interfaces were removed after orphan cleanup

Verified on sidecar:

- point `3` runs from `/var/lib/leikwan-sidecar-3/start-sidecar.sh`
- point `3` runtime uses `/etc/leikwan-sidecar-3/runtime.json`
- point `3` relay binds `0.0.0.0:14969`

## Current Known State After Fixes

### `forward 23`

Status:

- panel downlink bug is fixed
- client is no longer trying QUIC primary legs

Remaining symptom:

- `iperf3` / ready-probe data plane still did not pass during this session even after QUIC was removed

Interpretation:

- the original QUIC-downlink root cause is resolved
- any remaining `forward 23` issue is now a separate WSS/TCP data-plane problem, not the old panel QUIC decision bug

### `forward 24`

Status:

- configuration-generation issues were materially improved
- sidecar point `3` runtime/state isolation is fixed in code
- MPTCP subflow endpoints now register correctly on the client
- `lw-sc-3-*` underlay convergence on sidecar progressed after sidecar runtime fixes and cleanup

Remaining symptom:

- latest client checks still showed `MPTCP connect failed ... falling back to TCP`
- then `client connection error: Operation timed out`
- client probes to sidecar overlay addresses `10.203.103.115/119/123/111/127:14969` still timed out during the session

Interpretation:

- the session removed several code/config blockers and stale-runtime conflicts
- but end-to-end `forward 24` traffic was still not fully restored by the end of the session
- the remaining problem was narrowed to live UoT/TCP-fallback or overlay data-plane behavior rather than the earlier config/state-dir/downlink bugs

## Files Changed During This Session

- `master/src/Support/ProvisioningService.php`
- `master/src/Controllers/SidecarAgentController.php`
- `client-agent/client_agent.py`
- `client-agent/install_forward_runtime.py`
- `client-agent/leikwan_mptcp_client.py`
- `sidecar-agent/sidecar_agent.py`
- `sidecar-agent/leikwan_mptcp_sidecar.py`
- `slave-agent/slave_agent.py`
- `artifacts/versions.json`
- `client-agent/tests/test_mptcp_bond_regressions.py`
- `slave-agent/tests/test_slave_mptcp_cleanup.py`
- `master/tests/sidecar_downlink_regressions_test.php`
- `master/tests/bond_quic_capability_regression_test.php`

## 2026-04-16 Follow-up

### Additional code fixes

Implemented:

- tightened bond QUIC capability gating in `master/src/Support/ProvisioningService.php`
- `nodeSupportsBondQuic(...)` now rejects nodes whose `wireguard_outer_transport` is `tcp`
- fixed `client-agent/leikwan_uot.py` client mode so TCP-fallback return traffic is sent back through the same bound UDP listen socket instead of a fresh ephemeral UDP socket

Added regression coverage:

- `client-agent/tests/test_mptcp_bond_regressions.py`
  - UoT client replies now assert source port stays pinned to the configured `--listen-udp` port
- `master/tests/bond_quic_capability_regression_test.php`
  - TCP outer-transport nodes now assert `supports_quic_leg=false`
  - TCP outer-transport nodes now assert `leg_relay_protocols=[]`

### Verified after deploy

Automated:

- `python -m unittest client-agent.tests.test_mptcp_bond_regressions`
- `python -m unittest slave-agent/tests/test_slave_mptcp_cleanup.py`
- `php master/tests/bond_quic_capability_regression_test.php`
- `php master/tests/sidecar_downlink_regressions_test.php`
- `python -m py_compile client-agent/leikwan_uot.py`

Live `forward 23` observations after panel/client deploy:

- `buildClientConfig(23)` now returns `supports_quic_leg=false` for all endpoints `169-173`
- live `leikwan-client-start-23.sh` now renders only `tcp://127.0.0.1:*` bond legs
- live `leikwan-bond client --protocol tcp/udp` process args now use only TCP/WSS legs
- sampled slave ready probes from the client now return `HTTP/1.1 200 OK` for:
  - `https://8.163.90.132:7000/_leikwan/readyz/dcfa28eea4bf6b3b`
  - `https://8.163.90.235:7000/_leikwan/readyz/dcfa28eea4bf6b3b`

Live `forward 24` observations after panel/client deploy:

- client WG peer endpoints remain pinned to the configured localhost UoT ports:
  - `lw-mptcp-24-0 -> 127.0.0.1:58149`
  - `lw-mptcp-24-1 -> 127.0.0.1:58150`
  - `lw-mptcp-24-2 -> 127.0.0.1:58152`
  - `lw-mptcp-24-3 -> 127.0.0.1:58148`
  - `lw-mptcp-24-4 -> 127.0.0.1:58151`
- before this fix, the live peer endpoint could drift to a random localhost port after the first return packet; after the fix, that drift was no longer observed
- client UoT units are active and hold established TCP sessions to every slave fallback listener on `:9968`
- sidecar point `3` still shows healthy `lw-sc-3-*` WireGuard handshakes to all 5 slaves

### Current interpretation after follow-up

`forward 23`:

- the QUIC-misclassification regression was real and is now fixed in code and in live panel/client config
- the old `quic handshake timeout` failure mode is no longer part of the current rendered startup path
- sampled slave ready probes now pass
- end-to-end `iperf3` through local listener `11021` was still not confirmed cleanly in this follow-up window, so final traffic acceptance for `forward 23` remains open

`forward 24`:

- the client-side UoT return-path port-learning bug was real and is now fixed
- after this fix, the remaining live failure is later in the path:
  - client `lw-mptcp-24-*` peers still show sent bytes without meaningful received bytes
  - client probes to sidecar overlay addresses `10.203.103.115/119/127/111/123:14969` still time out
  - relay still reports:
    - `MPTCP connect failed ... falling back to TCP`
    - `client connection error: Operation timed out`
- inference from the available evidence:
  - panel leg metadata is now consistent
  - client UoT localhost endpoint handling is now consistent
  - sidecar `lw-sc-3-*` half is healthy
  - the remaining `forward 24` failure is now narrowed further to the slave-transit / client-to-sidecar underlay return path rather than the earlier client-side UoT endpoint drift

## 2026-04-16 Additional Follow-up

### Additional code fixes

Implemented:

- `sidecar-agent/sidecar_agent.py`
  - bond-only sidecar runtime now switches to `/usr/local/bin/leikwan-bond server`
  - `allow_tcp_fallback` stays enabled for bond-only listener runtime
- `master/src/Controllers/SidecarAgentController.php`
  - sidecar heartbeat management now emits `mode='bond'` when a point has no active MPTCP underlays/endpoints
  - sidecar heartbeat management keeps `mode='mptcp'` only when active MPTCP runtime state exists

Added regression coverage:

- `client-agent/tests/test_mptcp_bond_regressions.py`
  - bond-only sidecar runtime now asserts `leikwan-bond server` launch path
- `master/tests/sidecar_downlink_regressions_test.php`
  - sidecar heartbeat management now asserts the `bond` / `mptcp` mode branch exists in controller source

### Additional live actions performed

Deployed:

- updated `client-agent/leikwan_uot.py` to the client host
- updated `client-agent/leikwan_uot.py` to the sidecar host because point `3` also runs UoT clients
- updated `sidecar-agent/sidecar_agent.py` to panel repo and sidecar host
- updated `master/src/Controllers/SidecarAgentController.php` to panel repo
- updated client `/usr/local/bin/leikwan-bond` to match the current sidecar/local build SHA:
  - old client SHA: `30c1728b1dc402922c4d5c2f2fe354d3c683adf38049cd1640a024795e6a6cfb`
  - current sidecar/local SHA: `77754e74ca398192cc7587dc29015415768928588a356f42a6dd57573d59a516`

Requested live node updates from panel for stale Guangzhou nodes:

- node `4`
- node `6`
- node `7`
- node `8`

### Additional verified observations

`forward 24`:

- after updating sidecar UoT runtime, client logs changed from pure timeout to successful sidecar connection:
  - `connected MPTCP to 10.203.103.115:14969`
  - `sent handshake (301 bytes)`
- sample relay transfer now shows real payload movement instead of only timeout:
  - `relay done: in=64791576 bytes, out=37 bytes`
- live client WG stats now show at least one leg receiving traffic:
  - `lw-mptcp-24-0` received bytes increased after sidecar UoT update
- remaining symptom:
  - traffic is still concentrated mostly on leg `0`
  - `iperf3` still ends with control-channel failure instead of a clean completion
  - the path behaves as “usable but degraded” rather than fully healthy multipath

Interpretation:

- `forward 24` is no longer blocked at the initial overlay connect stage
- the remaining problem is now more specifically:
  - incomplete multipath fan-out across the other 4 Guangzhou slaves, and/or
  - stale slave runtime versions still preventing all subflows from converging

`forward 23`:

- point `2` runtime was confirmed to have been running the wrong server path for bond-only traffic before this follow-up
- point `2` now runs:
  - `/usr/local/bin/leikwan-bond server --listen 0.0.0.0:14961 --token-secret ...`
- despite this correction, live `forward 23` still does not complete the bond hello phase
- current client symptom remains:
  - `timed out waiting for bond hello-ack from tcp://127.0.0.1:*`
  - then `Error: no bond legs acknowledged the TCP transport`
- sampled sidecar connection checks during those retries still did not show stable inbound `14961` sessions from slaves

Interpretation:

- the earlier point-2 runtime-mode bug was real and is now fixed
- but `forward 23` still has an unresolved live path problem after the slave WSS ingress and before or during the target-side bond leg handshake
- the next likely remaining branches are:
  - stale slave runtime versions on nodes `4/6/7/8`
  - or a remaining slave-side relay/backend path issue that is not visible from the sidecar host alone

## Changelog

### 2026-04-16

- fixed slave auto-update pipeline:
  - `install/install-slave.sh` now supports `--check-update`
  - `install/install-slave.sh` now supports `--update`
  - `install/install-slave.sh` now supports `--config`
  - installer now writes back `installer_version`
- bumped `artifacts/versions.json`:
  - `slave_installer.version -> 2026.04.16.1`
- verified slave auto-update landed on Guangzhou nodes:
  - node `4` -> `2026.04.15.2`
  - node `6` -> `2026.04.15.2`
  - node `7` -> `2026.04.15.2`
  - node `8` -> `2026.04.15.2`
- fixed bond-only sidecar runtime selection:
  - point `2` now runs `leikwan-bond server`
  - point `3` still runs `leikwan-mptcp-relay server`
- fixed client bond runtime rendering:
  - WSS-only bond path now keeps panel-authored `join_timeout_ms=400`
  - client `leikwan-client-start-23.sh` now renders `--join-timeout-ms 400`
- aligned client bond binary with sidecar/local current build SHA:
  - client `/usr/local/bin/leikwan-bond` now matches SHA `77754e74ca398192cc7587dc29015415768928588a356f42a6dd57573d59a516`
- forward `24` current live behavior:
  - reverse `iperf3 -R` can complete 10 seconds
  - observed receive throughput is roughly `27-31 Mbps`
  - traffic still heavily prefers `lw-mptcp-24-0`
  - forward-direction `iperf3` still stalls after the first burst and ends abnormally
- forward `23` current live behavior:
  - still no stable `bond hello-ack`
  - local listener `11021` is up
  - end-to-end traffic still not restored

## 待修复问题

### P0

- `forward 24` still has incomplete multipath convergence.
  Current evidence:
  - reverse `iperf3` runs but only at about `27-31 Mbps`
  - `wg show lw-mptcp-24-* transfer` shows most RX bytes on leg `0`
  - other legs contribute little or almost nothing
  - forward-direction `iperf3` still ends with reset / abnormal close

- sidecar point `3` still has stale / incomplete MPTCP endpoint state.
  Current evidence:
  - `ip mptcp endpoint show` on sidecar was missing `10.203.103.111 id 34 dev lw-sc-3-3`
  - stale `if*` signal endpoints still existed
  - local code fix for stale endpoint cleanup is done but has not yet been deployed to the sidecar host because sidecar SSH access became unstable during the session

- `forward 23` still fails before traffic enters a healthy bonded session.
  Current evidence:
  - client keeps logging `timed out waiting for bond hello-ack`
  - later it reports `no bond legs acknowledged the TCP transport`
  - point `2` is already running the correct `leikwan-bond server`
  - client bond binary is already updated
  - remaining issue is still somewhere in slave WSS ingress / slave relay / target-side bond handshake path

### P1

- client auto-update path is still incomplete.
  Current evidence:
  - `leikwan-client-agent.service` logs `update helper missing: /usr/local/bin/leikwan-client-agent-update`
  - this does not block forward rendering, but it prevents clean self-update behavior on the client host

- sidecar auto-update path should be rechecked after runtime deployment.
  Current evidence:
  - panel now serves the updated sidecar controller / agent logic
  - but the latest `leikwan_mptcp_sidecar.py` cleanup fix was not yet confirmed on-host because direct and jump-host SSH access to the sidecar became unreliable

### 建议下一步

- deploy latest `sidecar-agent/leikwan_mptcp_sidecar.py` to sidecar point `3`
- restart `leikwan-bond-sidecar-3` and re-check:
  - `ip mptcp endpoint show`
  - `wg show lw-sc-3-*`
  - `ss -M -tin`
  - `iperf3 -c 127.0.0.1 -p 11022 -R`
  - `iperf3 -c 127.0.0.1 -p 11022 -t 10`
- if `forward 24` still stays single-leg after stale endpoint cleanup, inspect subflow advertisement / acceptance on both client and sidecar during a live reverse test
- for `forward 23`, inspect slave-side runtime on nodes `4/5/6/7/8` during an active `11021` test:
  - listener route / backend unit state
  - wstunnel backend connectivity to `161.248.136.183:14961`
  - whether bond hello frames reach point `2`

## 2026-04-17 maintenance record

### Scope

This round covered four production-facing issues:

1. admin diagnostics node status stayed `error` even after the real problem was gone
2. slave publish tasks kept failing with `path_prefix conflict on public_port=7000`
3. client MPTCP/UoT units kept failing with:
   - `OSError: [Errno 98] Address already in use`
   - `asyncio.exceptions.IncompleteReadError: 0 bytes read on a total of 4 expected bytes`
4. existing machines needed to recover without requiring full reinstall

### Root causes

#### 1. Diagnostics false red state

- `AdminController::diagnosticsNodeState()` treated historical `failed_tasks` totals as a current-health signal
- nodes with `Active > 0` and `Issues = 0` still showed `error` only because they had many old failed tasks in the database

#### 2. Slave `path_prefix conflict`

- panel-side `ProvisioningService::republishActiveEndpointsForNode()` queued malformed publish tasks
- in those tasks, `endpoint_id` was sometimes written as the `forward_id` (`23` / `26`) instead of the real `service_endpoints.id` (`169-173` / `184-188`)
- slave nodes then treated the wrong endpoint/service identity as a new publish and collided with already-running `wstunnel-svc-*` entries on the same `public_port` and `path_prefix`
- slave runtime also had a second issue: old `wstunnel-svc-*` metadata for the same `forward_id` could remain active after endpoint rotation

#### 3. Client UoT crashes

- `client-agent/leikwan_uot.py` treated normal remote TCP EOF as a fatal error and exited instead of reconnecting
- stale UoT processes could remain bound to the old local UDP port, so new systemd restarts saw `Address already in use`
- on at least one live client, local runtime files were stale enough that:
  - `install_forward_runtime.py` did not inject `local_uot_udp_port`
  - regenerated `/etc/leikwan/mptcp-forward-25.json` fell back to shared `peer_tcp_fallback_port=9975` for every leg
  - `leikwan-mptcp-client.py` also needed compatibility for `--setup-underlays`

### Code changes applied

#### Panel

- `master/src/Controllers/AdminController.php`
  - diagnostics node state now reflects current health only
  - failed task count on node diagnostics is now `Failed Tasks (1h)` instead of lifetime totals
- `master/src/Support/ProvisioningService.php`
  - `republishActiveEndpointsForNode()` now uses the correct `service_endpoint_id`
  - MPTCP slave underlay payload now emits stable `local_uot_udp_port`
  - `allow_tcp_fallback` default restored to `true`

#### Slave

- `slave-agent/slave_agent.py`
  - `publish_task()` now removes superseded active services for the same `forward_id` before re-publishing
  - `reconcile_runtime()` now also cleans stale duplicate services for the same `forward_id`
- `install/install-slave.sh`
  - now supports `--update`
  - now supports `--check-update`
  - now supports `--config`
  - writes back `installer_version`

#### Client

- `client-agent/leikwan_uot.py`
  - long-lived client loop now reconnects after remote disconnect
  - EOF / reset / broken pipe on the TCP session no longer crashes the process
- `client-agent/leikwan_mptcp_client.py`
  - now cleans stale `leikwan-uot-*` units for rotated endpoints
  - now kills conflicting orphan UoT client processes bound to the same local UDP port before restart
  - now accepts `--setup-underlays` for compatibility with existing generated start scripts

### Live production actions performed

- deployed updated panel files to `v3.leikwanhost.com`
- purged malformed queued publish tasks for `forward 23` / `26` where `endpoint_id IN (23,26)`
- triggered fresh republish after the panel-side hotfix
- triggered slave node updates for the affected nodes
- directly connected to the live client host `172.21.255.189`
- manually updated:
  - `/usr/local/bin/leikwan-uot.py`
  - `/usr/local/bin/leikwan-mptcp-client.py`
- restarted `leikwan-client-agent.service` on the client so it would pull fresh config
- forced regeneration of `/etc/leikwan/mptcp-forward-25.json`
- restarted the affected client services/UoT units until runtime converged on the corrected config

### Verified production results

#### Diagnostics

- Guangzhou nodes with `Active > 0` and `Issues = 0` now show `healthy`
- node diagnostics table now labels the failure column as `Failed Tasks (1h)`

#### Slave republish

- new `provision_tasks` for `forward 23` / `26` now use real endpoint ids:
  - `169-173`
  - `184-188`
- after purging the malformed backlog and re-republishing, new `path_prefix conflict` task generation dropped to `0`

#### Client `forward 25`

On host `172.21.255.189`, after refresh:

- `/etc/leikwan/mptcp-forward-25.json` now includes unique `local_uot_udp_port` values per leg:
  - `179 -> 51510`
  - `180 -> 51527`
  - `181 -> 51561`
  - `182 -> 51544`
  - `183 -> 51493`
- all five units were observed in `active running` state:
  - `leikwan-uot-lw-mptcp-25-0-179.service`
  - `leikwan-uot-lw-mptcp-25-1-180.service`
  - `leikwan-uot-lw-mptcp-25-2-181.service`
  - `leikwan-uot-lw-mptcp-25-3-182.service`
  - `leikwan-uot-lw-mptcp-25-4-183.service`
- `ss -lunp` showed separate local UDP listeners on:
  - `51510`
  - `51527`
  - `51561`
  - `51544`
  - `51493`
- no shared local `9975` listener remained for the `forward 25` UoT client legs

### Operational conclusions

1. If a node diagnostics page shows `error` while `Issues = 0` and `Active > 0`, check whether diagnostics logic is still using historical failed task totals. That is a UI/data interpretation problem, not necessarily a live data-plane problem.
2. If slave publish errors say `path_prefix conflict ... already used by wstunnel-svc-*`, verify queued `provision_tasks.endpoint_id` first. If it equals the `forward_id`, the panel republish path is stale/broken.
3. If a client UoT unit shows `Address already in use`, do not assume the displayed unit is the only owner of that port. First inspect:
   - `systemctl list-units 'leikwan-uot-lw-mptcp-*.service' --type=service --all`
   - `ss -lunp`
   - `pgrep -f 'leikwan-uot.py client'`
4. If `/etc/leikwan/mptcp-forward-<id>.json` is missing `local_uot_udp_port`, panel-side `ProvisioningService.php` or the local `install_forward_runtime.py` is stale. In that case, fixing only `leikwan_uot.py` is not enough.
5. Do not manually run `/usr/local/bin/leikwan-uot.py client ...` while the corresponding systemd unit is already active. That creates a false duplicate-bind symptom.

### Remaining caution

- client self-update path is still not fully trustworthy on every older host because some hosts previously lacked `/usr/local/bin/leikwan-client-agent-update`
- if an old client still does not converge after panel-side fixes, check these local files directly on-host:
  - `/usr/local/bin/leikwan-uot.py`
  - `/usr/local/bin/leikwan-mptcp-client.py`
  - `/usr/local/bin/install_forward_runtime.py`
  - `/etc/leikwan/mptcp-forward-<id>.json`
