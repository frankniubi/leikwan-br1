# 2026-05-15 Shanghai -> Tokyo high-loss / self-leg handoff

## Scope

- User asked to solve the Tokyo-direction path only: Shanghai slaves, leave Canton slaves alone.
- Reported symptom: `555 packets transmitted, 418 received, 24.6847% packet loss`, RTT `min/avg/max/mdev = 81.429/82.636/86.253/0.646 ms`.
- Feature goal added during investigation: support `client-as-itself's-slave` when the client's direct Cloudflare path is better than rented slave paths.
- Required topology constraints still apply:
  - normal rented-helper topology stays `client -> slave -> sidecar`;
  - sidecars should not be accessed directly except the new self-leg design, which is explicitly direct client -> Cloudflare/wstunnel -> sidecar for the client's own private leg;
  - slave MPTCP/bond ingress ports must remain in `7000-9000`.

## Current local worktree state

This checkout is dirty and contains an in-progress implementation/debug patch set. It is not clean/committed.

Important modified files in the current patch set:

- `client-agent/client_agent.py`
  - MPTCP capability detection and fallback logic.
  - Runtime-family selection (`mptcp_preferred_bond_fallback`).
  - Candidate-pool sorting and self-leg probing.
  - MPTCP health/quarantine refresh handling.
- `client-agent/install_forward_runtime.py`
  - Bond runtime rendering for a selected self-leg as direct CF wstunnel.
  - Self-leg endpoint normalization and rendering without `leikwan-ingress` slave endpoint.
- `client-agent/leikwan_mptcp_client.py`
  - Local cleanup/retry changes for WireGuard transit underlays and stale UoT client services.
  - Intended behavior: failed underlay setup should not crash the whole MPTCP runtime; stale/conflicting interfaces should be cleaned.
- `master/src/Support/ProvisioningService.php`
  - Forward-combination candidate-pool work.
  - Self-leg candidate generation using CF upstream realization fields.
  - MPTCP slave port range helper logic.
  - Compatibility shims for newer node/sidecar heartbeat payloads.
- `master/src/Controllers/NodeController.php`, `master/src/Controllers/SidecarAgentController.php`
  - Heartbeat/runtime payload compatibility changes.
- `slave-agent/slave_agent.py`, `sidecar-agent/sidecar_agent.py`, `sidecar-agent/leikwan_mptcp_sidecar.py`
  - CF/plain TCP mux and WG/uplink cleanup/runtime work.
- New/focused tests include:
  - `client-agent/tests/test_highloss_self_leg_runtime_regression.py`
  - `master/tests/provisioning_mptcp_slave_port_range_regression_test.php`

## What was observed live

### Client / forward 47

- The real Tokyo client in use during investigation was `114.80.33.50`, client id `3`.
- Forward 47 was the Tokyo MPTCP test path with local listener `11035`.
- The client agent was active and pulling config successfully from the mirror.
- The client could reach the mirror, Cloudflare edge, Tokyo sidecar bond/mptcp ports, and `m-jp-4.scipnet.info:8443` by TCP probe.
- The client could not reach panel SSH `10.0.10.196:22` during the latest probe (`panel22 FAIL`). Local direct SSH to panel also timed out in one run.
- `/etc/leikwan/mptcp-forward-47.json` on the client still showed:
  - `listen_port: 11035`
  - `remote_host: 10.203.201.183`
  - `remote_port: 8898`
  - `subflow_limit: 4`
  - `policy_mode: latency_first`
  - endpoints sorted as expected by measured health: endpoint `320` first (`8.133.170.123`, `82.8 ms`), then `318`, `317`, `316`.
- However, live underlays were still not aligned with that sorted order:
  - `lw-mptcp-47-0` carried endpoint `316`
  - `lw-mptcp-47-1` carried endpoint `317`
  - `lw-mptcp-47-2` carried endpoint `318`
  - `lw-mptcp-47-3` carried endpoint `320`
- Stale interfaces were also still present:
  - `lw-mptcp-47-4` for endpoint `320`
  - `lw-mptcp-47-5` for an older/non-Shanghai leg
- `leikwan-client-47.service` was restart-looping/failing with:
  - `RuntimeError: transit interface did not appear after wg-quick up: lw-mptcp-47-3`
- This means the deployed client MPTCP runtime was still using the old crashing behavior from `/usr/local/bin/leikwan-mptcp-client.py`; the local repo has a candidate fix, but it was not fully deployed/verified live.

### Direct CF / self-leg probe observations

From the client:

- Direct CF edge probes to `162.159.39.7:2053` and `162.159.39.4:2053` were much more stable than the named/origin routes in the same sample:
  - `cf39.7`: about `29-69 ms`, all TCP probes ok in the sample.
  - `cf39.4`: about `28-56 ms`, all TCP probes ok in the sample.
  - `m-jp-4.scipnet.info:8443`: mixed, with several `~1.1-1.3s` connects and one timeout.
  - `185.220.238.143:2053`: mixed, with `~69-86 ms` successes and several `~1.0-1.1s` connects/timeouts.
- From Shanghai slaves, CF edge probes also showed that selected edge IP matters a lot:
  - `162.159.39.7:2053` often around `33-57 ms` from some slaves.
  - `m-jp-4.scipnet.info:8443` and origin sidecar CF had repeated `~1s` spikes/timeouts from some slaves.
- Conclusion: the self-leg logic should not simply use the origin sidecar host or named hostname; it should use the approved CF upstream realization / selected low-latency CF edge identity, with correct SNI/Host/path.

### Forward 59 / bond evidence

- `leikwan-client-59.service` was active but logs showed repeated:
  - `invalid bond frame magic`
  - `no bond legs acknowledged the TCP transport`
- This points to a protocol mismatch on at least some bond legs: the client bond runtime is connecting to a leg whose backend is not speaking the expected bond frame protocol, or it is using the wrong route/backend path.
- This must be investigated separately from the MPTCP WG crash, but still within Shanghai -> Tokyo only.

### Panel / combination state evidence

Older same-day panel evidence showed Tokyo combination id `1` still had:

- `runtime_preference: single`
- `allow_private_self_leg: 0`
- `self_leg_scope: none`
- `self_leg_role: none`
- `helper_group_cap: 0`

That may be stale because the latest panel SSH check timed out, so re-verify live before changing it. If still current, it explains why the self-leg is not active in the client payload.

## Tests run and results

### Successful / partially successful

- Python syntax compile was run for the changed Python runtime files before the unit suite:
  - `client-agent/client_agent.py`
  - `client-agent/install_forward_runtime.py`
  - `client-agent/leikwan_mptcp_client.py`
  - `sidecar-agent/sidecar_agent.py`
  - `sidecar-agent/leikwan_mptcp_sidecar.py`
  - `slave-agent/slave_agent.py`
- The combined Python unit command ran `27` tests and had one failure, so the other tests in that command did not fail.
- Self-leg selection/rendering tests exercised these expected behaviors locally:
  - self-leg candidate can win when direct CF probe is faster than slave probes;
  - self-leg primary forces bond-family runtime for `mptcp_preferred_bond_fallback`;
  - rendered self-leg uses direct CF wstunnel and does not require a slave endpoint.
- Live probes confirmed the client can make TCP connections to direct CF and Tokyo sidecar ports, but with significant latency spikes depending on target.

### Failed / unresolved

- Local unit suite failure:
  - Test: `test_panel_mptcp_endpoint_payload_carries_endpoint_id_for_health_remap`
  - Failure: expected panel PHP to include endpoint id in MPTCP endpoint payload: `'endpoint_id' => (int) ($leg['endpoint_id'] ?? 0)`
  - Root issue: `buildMptcpClientConfig()` currently maps `mptcp_endpoints` without carrying `endpoint_id`, so client health remapping can misalign endpoints/underlays.
- Live `leikwan-client-47.service` still failed with `RuntimeError: transit interface did not appear after wg-quick up: lw-mptcp-47-3`.
- Live stale WG interfaces (`lw-mptcp-47-4`, `lw-mptcp-47-5`) remained after the config moved back to `0-3`.
- Live forward 59 bond runtime showed repeated `invalid bond frame magic`.
- Panel SSH was unreachable during the latest local/client checks, so final panel DB/config state was not refreshed.

## Current root-cause hypotheses

1. **Immediate outage / no useful client-to-slave MPTCP path**
   - The client has stale/conflicting WG state and the deployed `leikwan-mptcp-client.py` still crashes when one underlay fails.
   - Because service 47 restarts, there is no stable local listener/path to evaluate loss properly.

2. **MPTCP path selection misalignment**
   - Client endpoint health says endpoint `320` is fastest, but the live MPTCP underlay config did not reorder underlays correctly.
   - Missing `endpoint_id` in panel `mptcp_endpoints` is a concrete local test failure and likely contributes to remap/selection drift.

3. **Self-leg not actually enabled in panel/runtime yet**
   - Older panel evidence showed Tokyo combination self-leg fields disabled.
   - `approvedCfUpstreamRealizations()` exists locally but appears not fully wired into the returned client config; `selfLegCfTarget()` expects `bond_sidecar_approved_upstream_realizations` / `bond_sidecar_cf_listen_port`, but these fields are not clearly present in `buildClientConfig()` return payload yet.

4. **Forward 59 bond protocol mismatch**
   - `invalid bond frame magic` means the client is not talking to a bond-speaking backend on some/all legs.
   - Likely candidates: wrong ingress mode (`plain_tcp_mux` vs `wss_path_mux`), wrong route key/path, or slave wstunnel target hitting sidecar plain wstunnel/bond port incorrectly.

5. **High loss / latency is route-dependent, not just generic ICMP loss**
   - Direct edge IPs `162.159.39.7/39.4:2053` can be low-latency from the client.
   - Named/origin CF routes and sidecar public ports show repeated `~1s` connect spikes/timeouts.
   - The self-leg should use direct measured CF edge/SNI/path, not just origin host or sidecar public IP.

## Recommended next plan

1. **Keep cleanup complete**
   - Temp files from the repo root should stay removed.
   - Keep this markdown as the durable handoff artifact.

2. **Fix the local failing test first**
   - Add `endpoint_id` into `buildMptcpClientConfig()` `mptcp_endpoints` mapping.
   - Add/adjust regression coverage so MPTCP endpoint remap cannot lose endpoint identity.
   - Re-run at least:
     - `python -m unittest client-agent/tests/test_highloss_self_leg_runtime_regression.py`
     - `python -m unittest client-agent/tests/test_mptcp_setup_and_cleanup_regressions.py`

3. **Finish self-leg panel payload wiring**
   - In `buildClientConfig()`, expose sidecar CF listen/SNI and approved upstream realization fields needed by `selfLegCfTarget()`.
   - Add a panel regression that the self-leg candidate contains:
     - `probe_transport: cf_direct_wss`
     - direct CF `dial_host` / `dial_port`
     - correct `cf_sni_host`
     - `path_prefix`
     - sidecar CF target metadata
   - Ensure the candidate does not point at the private MPTCP sidecar port or normal bond sidecar port.

4. **Re-verify and enable Tokyo combination only**
   - Once panel SSH is reachable, inspect combo id `1` live.
   - If still disabled, set Tokyo combo only:
     - `runtime_preference = mptcp_preferred_bond_fallback`
     - `allow_private_self_leg = 1`
     - `self_leg_scope = device_session`
     - `self_leg_role = latency_anchor`
     - set a sane `helper_group_cap` for Shanghai helper escalation.
   - Do not touch Canton combo/slaves.

5. **Deploy runtime fixes to the real client and clean stale WG state**
   - Sync the fixed `client-agent/client_agent.py`, `client-agent/install_forward_runtime.py`, and `client-agent/leikwan_mptcp_client.py` to `114.80.33.50`.
   - Stop `leikwan-client-47.service` briefly.
   - Remove only forward-47 stale transit artifacts:
     - `wg-quick@lw-mptcp-47-*`
     - `/etc/wireguard/lw-mptcp-47-*.conf`
     - `ip link` devices `lw-mptcp-47-*`
     - stale `leikwan-uot-lw-mptcp-47-*` units.
   - Restart `leikwan-client-agent.service` and let it render fresh config.

6. **Verify forward 47 before touching forward 59**
   - Confirm `leikwan-client-47.service` is active and stable.
   - Confirm local listener `:11035` is present.
   - Confirm no stale `lw-mptcp-47-4/5` unless the fresh config explicitly wants them.
   - Confirm underlay order and `mptcp_endpoints` order match selected health.
   - Run traffic/ping/iperf through the actual client-facing port and record loss/RTT.

7. **Then investigate forward 59 bond frame mismatch**
   - Inspect the rendered client start script and each Shanghai slave metadata for forward 59.
   - Check whether each leg is using `leikwan-ingress client` for `plain_tcp_mux` or raw wstunnel for `wss_path_mux` consistently.
   - On each Shanghai slave, check the target backend is the bond-speaking sidecar endpoint, not an HTTP/wstunnel endpoint that returns non-bond bytes.
   - Fix only Shanghai/Tokyo endpoints.

8. **Final acceptance checks**
   - Client forward 47/59 services stable for at least several minutes.
   - No `RuntimeError: transit interface did not appear` in new logs.
   - No repeated `invalid bond frame magic` for the fixed path.
   - Bond/MPTCP health snapshots show the intended primary/self/helper legs.
   - Packet loss materially below the reported `24.6847%`; target should be near zero before claiming fixed.
   - RTT should be compared against direct CF edge probes and fastest Shanghai slave path before declaring the self-leg decision correct.

## Cleanup performed in this handoff turn

- A root-level temp-file inventory found `1102` files matching `.tmp_*`, `tmp-*`, or `tmp_*`, totaling about `8.38 MB`.
- These files were created as interactive diagnostics/logs/scripts during prior live debugging and are not needed after this markdown captured the useful evidence.
- Cleanup should delete only untracked root-level temp files whose resolved paths stay inside `C:\Users\frankniubi\Downloads\leikwan-br1`.
