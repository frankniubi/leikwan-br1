# 2026-05-24 LeiKwan sh36 / forward 47 / slave MPTCP+UOT chat handoff

This file summarizes what was done in this chat and what is still left to do. It intentionally avoids copying credentials, tokens, private keys, or host passwords.

## Scope kept during the chat

- Preserved required topology: `client -> slave -> sidecar`.
- Did not use direct client-to-sidecar as the intended topology.
- Kept slave MPTCP/bond/preconnect ingress in the managed `7000-9000` port range.
- Treated `leikwan-v3.txt`, `cantons.txt`, and `shanghais.txt` as local-only operational inputs.

## What has been done

### 1. Restored and verified `sh36` forward 47 on `lw-cfwg-47`

- Investigated why `sh36` could not start `lw-cfwg-47` for forward 47 in CF-MPTCP / client-as-a-slave mode.
- Found the runtime failure pattern where `wg-quick@lw-cfwg-47` could fail because the interface already existed.
- Verified after recovery that:
  - `leikwan-client-agent.service` was active.
  - `leikwan-client-47.service` was active.
  - `lw-cfwg-47` existed and had a fresh WireGuard handshake.
  - Local forward `127.0.0.1:11035` worked.
  - Sidecar over `lw-cfwg-47` to `10.204.53.42:8898` worked.
- User later confirmed: `Now lw-cfwg-47 is working.`

Primary handoff already written for this phase:

- `docs/superpowers/handoffs/2026-05-23-sh36-cfwg47-slave-resource-fix.md`

### 2. Diagnosed slave node resource exhaustion after SSH login

Root cause established from runtime evidence:

- UOT process RSS could grow until small-memory slave nodes became unstable.
- At least one slave had kernel OOM evidence killing UOT, and later `systemctl` under `ssh.service`, matching the symptom: SSH login succeeds but commands stop responding.
- Reboot temporarily fixed it because it reset bloated processes and failed transient updater state.
- Separate contributing issue: slave update/install retry churn from stale helpers, CRLF shell scripts, and failed transient update units.

Fixes implemented:

- `client-agent/leikwan_uot.py`
  - Added bounded datagram queues.
  - Added safe datagram drops when queues are full.
- `slave-agent/slave_agent.py`
  - Added systemd memory/restart guards for UOT, preconnect relay, mux/listener processes.
  - Added update throttling and safer self-update state handling.
  - Invokes update helper through `bash` to avoid CRLF shebang failure.
  - Uses `systemd-run --collect` and avoids rapid repeated transient unit churn.
- `install/install-slave.sh`
  - Normalizes downloaded shell artifacts to LF.
  - Ensures self-signed TLS assets exist when needed.
- `install/leikwan-slave-update`
  - Normalizes downloaded installer to LF.
  - Runs installer through `bash`.
- `master/src/Controllers/ClientAgentController.php`
  - Publishes desired UOT runtime metadata.
  - Accepts and preserves reported `uot_runtime_sha256`.
- Related tests were added/updated for UOT bounded queues, installer LF normalization, restart/memory guards, failed update throttling, and CRLF-safe helper execution.

Verification already recorded:

- Local unit/lint commands passed for the resource-fix subset.
- Panel/public artifacts were synced.
- Slaves checked after rollout had active slave agents, current UOT hash, no new post-rollout OOM evidence, and lower UOT RSS.

### 3. Wrote the first detailed incident handoff

Created:

- `docs/superpowers/handoffs/2026-05-23-sh36-cfwg47-slave-resource-fix.md`

That file contains the detailed root cause, code changes, rollout evidence, and re-check commands for:

- `sh36` / `lw-cfwg-47` recovery.
- Slave UOT/resource exhaustion.
- Slave update churn and CRLF installer problems.

### 4. Made and started implementing the debugging plan for `lw-mptcp-47-0` slave helper failure

The plan targeted two questions:

1. Why example `lw-mptcp-47-0` slave helper/preconnect connection always failed.
2. Why `iperf3 -R -u -b 300M` still did not work, especially whether UoT logic on `sh36` was missing or broken.

Implemented code-path changes to make the client/slave preconnect path closer to client-as-a-slave pre-establishment behavior.

#### Panel/provisioning changes

Changed `master/src/Support/ProvisioningService.php` so MPTCP runtime config now carries more complete preconnect state:

- Added `effectiveMptcpTransport(...)` so MPTCP forwards that need UoT/private self-leg behavior can receive `tcp+udp` instead of silently staying TCP-only.
- Added `slave_relays` list into MPTCP config, not just a single `slave_relay_host` / `slave_relay_port`.
- Added per-underlay preconnect settings:
  - `preconnect_pool_size`
  - `preconnect_uot_pool_size`
  - `preconnect_refill_interval_s`
  - `preconnect_retry_delay_s`
  - `preconnect_stream_max_idle_s`
- Added exclusion support for MPTCP slave nodes/hosts:
  - `MPTCP_SLAVE_EXCLUDED_NODE_IDS`
  - `MPTCP_SLAVE_EXCLUDED_PUBLIC_HOSTS`
- Propagated effective transport into slave preconnect config and client runtime config.

#### Client agent changes

Changed `client-agent/client_agent.py` and related code:

- Client heartbeat now reports `uot_runtime_sha256`.
- Client compares desired UOT runtime hash from the panel and can trigger update mismatch handling.
- MPTCP health/probe path now maps slave relay candidates by endpoint/node from `slave_relays` instead of always probing sidecar-style MPTCP addresses.
- Added heartbeat republish gate for WireGuard identity changes so changed client WG identity can trigger active forward republish without flooding the queue.

#### Python MPTCP runtime changes

Changed `client-agent/leikwan_mptcp_client.py`:

- Added multi-relay candidate handling for slave preconnect:
  - TCP candidates from `slave_relays[].host/port`.
  - UDP/UoT candidates from `slave_relays[].uot_host/uot_port`, falling back to host/port when needed.
- Added candidate rotation for TCP and UDP so a dead first relay does not permanently break the client.
- TCP relay now uses `open_slave_relay_candidate_stream(...)`.
- UDP association now uses slave UoT preconnect candidates when available.
- Kept slave preconnect override available for self-leg-primary cases, matching the user note that slave legs should use the same MPTCP pre-establishment logic as client-as-a-slave.

#### Rust MPTCP relay changes

Changed `wstunnel-main/leikwan-mptcp-relay/src/client.rs` and `src/config.rs`:

- Added `slave_relays` to `ClientConfig`.
- Added TCP/UDP slave relay candidate selection and rotation.
- `open_tcp_stream_to_remote(...)` now tries all slave preconnect candidates before failing.
- UDP path uses slave UoT preconnect when allowed and does not send the sidecar handshake directly in that mode.
- Added tests for:
  - multiple slave preconnect relays;
  - TCP/UDP rotation;
  - self-leg primary still allowing slave preconnect override;
  - `lw-mptcp-47-0`-style slave leg smooth-preconnect behavior.

#### Windows desktop config propagation

Changed `LeikwanBridge.Desktop` files:

- `MptcpConfig.cs` now includes `SlaveRelays`.
- `MptcpRuntimeBuilder.cs` writes `slave_relays` into runtime JSON.
- Runtime manager test verifies `slave_relays` are preserved.

#### Slave preconnect relay changes

Changed `slave-agent/preconnect_relay.py`:

- Added warm preconnected sidecar stream pool support:
  - TCP pool via `preconnect_pool_size`.
  - UDP/UoT pool via `preconnect_uot_pool_size`.
  - refill/retry intervals.
  - optional max idle pruning.
- Starts maintenance before accepting clients.
- Health/status now includes preconnected pool counters.
- Checkout path reuses warm sidecar streams when possible and creates a fresh one as fallback.

This is intended to match the desired behavior: when the client uses a slave as an MPTCP leg, the expensive slave-to-sidecar part can already be warm, giving the client a smoother connection.

### 5. Added or updated tests

Touched/added tests include:

- `client-agent/tests/test_leikwan_uot_runtime.py`
- `client-agent/tests/test_mptcp_preconnect_runtime.py`
- `client-agent/tests/test_client_agent_mptcp_health_grace.py`
- `client-agent/tests/test_mptcp_bond_regressions.py`
- `client-agent/tests/test_mptcp_setup_and_cleanup_regressions.py`
- `client-agent/tests/test_self_leg_cf_wg_runtime.py`
- `client-agent/tests/test_cfwg_latency_watchdog.py`
- `client-agent/tests/test_artifact_versions_consistency.py`
- `slave-agent/tests/test_preconnect_relay.py`
- `slave-agent/tests/test_slave_installer_helper.py`
- `slave-agent/tests/test_slave_mptcp_cleanup.py`
- `master/tests/agent_udp_uot_sidepath_management_test.php`
- `master/tests/client_heartbeat_republish_gate_regression_test.php`
- `master/tests/provisioning_sidecar_dual_port_regression_test.php`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs`

Observed test output saved in temporary files includes:

- `tmp_current_preconnect_tests_20260523.out`: focused Python preconnect tests ran 12 tests and passed.

### 6. Updated artifacts/version state

Dirty tree shows updated runtime binaries and version manifest:

- `artifacts/versions.json`
- `artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64`
- `artifacts/wstunnel/leikwan-mptcp-relay_windows_amd64.exe`

These appear related to the Rust relay changes, but final publication/deployment still needs a clean review and verification pass.

## Current diagnosis status

### `lw-mptcp-47-0` slave helper/preconnect failure

Current evidence points to a mixed runtime/config issue, not just one broken line:

- Slave TCP preconnect listener for forward 47/node 3 was reachable locally on the slave in one fresh check:
  - TCP listen on `10.203.201.138:7265` was OK.
  - health on `127.0.0.1:7267` was OK.
  - sidecar MPTCP/TCP-fallback connection to `10.203.201.139:8898` was OK.
- The same config showed `transport: tcp`, and the UoT listener check failed:
  - `listen_uot_self FAIL 10.203.201.138 7266 ConnectionRefusedError`.
- So the old live state did not have a UDP/UoT preconnect listener for that leg. That explains why UDP reverse testing could still fail even though TCP preconnect looked healthy.
- Code has now been changed to publish effective `tcp+udp` when MPTCP/UoT behavior is required and to carry all `slave_relays` to the runtime, but this needs live redeploy/restart verification.

### `iperf3 -R -u -b 300M` failure

Likely current state before final live verification:

- The prior live config was TCP-only for the relevant preconnect relay, so UDP/UoT was not listening on the slave side.
- Python and Rust runtime code now include UoT slave-preconnect candidate logic, but the final end-to-end proof is still pending.
- Need verify that `sh36` receives a new forward 47 config with:
  - `transport: tcp+udp` where required;
  - populated `slave_relays`;
  - nonzero `slave_uot_relay_port` or per-relay `uot_port`;
  - runtime artifact hash matching the newly built relay.
- Need verify the selected slave actually starts the UoT preconnect listener and that traffic passes during `iperf3 -R -u -b 300M`.


## Latest concerns and deployment plan update

The `docs/superpowers/handoffs/20260524.txt` review adds several rollout gates that should be treated as part of the active plan, not optional polish:

- Pool size 1 is too thin for smooth operation. After TCP and UDP are proven, raise `preconnect_pool_size` to 2-3 and `preconnect_uot_pool_size` to 2, then re-check refill behavior and memory use.
- Warm streams need stale-entry handling. Treat aged checkout candidates as disposable, with an explicit `preconnect_stream_max_idle_s` target around 120 seconds plus keepalive or probe-based refresh.
- Relay selection should be latency-aware, not only failure-rotating. Prefer the lowest observed RTT to sidecar when health probes are available.
- The slave-to-sidecar hop needs a quality metric, not just up/down state. Track checkout-to-first-byte latency and expose a field like `sidecar_latency_ms` in health.
- Verification must prove aggregate bond behavior, not only a single leg. `lw-cfwg-47` and `lw-mptcp-47-0` should both be active before the `iperf3` proof.
- Client-as-a-slave should use the same MPTCP pre-establishment logic as the slave helper path, so both sides benefit from the same warm-connection behavior.
- `iperf3 -R -u -b 300M` still needs a narrow failure tree: client relay selection -> slave UOT listener -> slave forwarding -> sidecar accept -> reverse data return -> MTU/buffer tuning.

Updated deployment sequence:

1. Reconfirm live runtime shape on sh36 and the active slave helper.
2. Verify the slave preconnect path is warm for both TCP and UDP, not just present in config.
3. If the warm pools are still at 1, bump them only after a baseline capture.
4. Re-check resource behavior after any pool change so we do not recreate the earlier OOM or resource-runaway problem.
5. Only after the warmth check passes, run the aggregate proof with both legs active.
6. Run `iperf3 -R -u -b 300M` only after the warm-path gate is green.
7. If UDP still fails, use the explicit narrowing tree before touching unrelated code paths.

## Current dirty files / artifacts to review

`git status --short` currently shows a large dirty tree, including these relevant areas:

- `client-agent/`
- `slave-agent/`
- `master/src/Support/ProvisioningService.php`
- `master/src/Controllers/ClientAgentController.php`
- `master/tests/`
- `install/`
- `wstunnel-main/leikwan-mptcp-relay/`
- `LeikwanBridge.Desktop/`
- `artifacts/versions.json`
- built relay binaries under `artifacts/wstunnel/`
- many `tmp_*` evidence files

Do not commit or deploy blindly; the tree contains both intended code changes and temporary investigation artifacts.

## What is left to do

### A. Local review/verification before another live rollout

1. Review the dirty diff and separate intended source changes from temporary files.
2. Re-run focused tests:

```powershell
py -3 -m unittest client-agent.tests.test_leikwan_uot_runtime slave-agent.tests.test_slave_mptcp_cleanup slave-agent.tests.test_slave_installer_helper
py -3 -m unittest client-agent.tests.test_mptcp_preconnect_runtime slave-agent.tests.test_preconnect_relay
py -3 -m py_compile client-agent\leikwan_uot.py client-agent\leikwan_mptcp_client.py slave-agent\preconnect_relay.py slave-agent\slave_agent.py
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/ClientAgentController.php
```

3. Re-run relevant PHP tests for provisioning and heartbeat republish.
4. Re-run Rust relay tests and rebuild artifacts if source changed after the last build.
5. Confirm `artifacts/versions.json` hashes match the built binaries and scripts.

### B. Panel/artifact sync

1. Sync the intended panel PHP changes.
2. Sync client/slave Python runtime artifacts.
3. Sync rebuilt Rust MPTCP relay binaries.
4. Confirm panel/public artifact hashes for:
   - client agent;
   - slave agent;
   - UOT runtime;
   - preconnect relay;
   - Rust MPTCP relay;
   - installer/update scripts.

### C. Live rollout / runtime refresh

1. Trigger or force forward 47 republish so `sh36` receives the new MPTCP config.
2. Restart/refresh `leikwan-client-47.service` on `sh36` only after confirming the new config is present.
3. Update/restart the selected slave preconnect relay unit(s).
4. Confirm selected slave config has:

```json
{
  "transport": "tcp+udp",
  "preconnect_pool_size": 1,
  "preconnect_uot_pool_size": 1,
  "listen_port": 7265,
  "uot_listen_port": 7266
}
```

Values may differ by node/forward, but the important parts are `tcp+udp`, populated TCP and UoT ports, and active pool maintenance.

### D. Live proof for `lw-mptcp-47-0` and UoT

Need a final clean proof from runtime:

1. On slave:
   - TCP preconnect listener is listening.
   - UoT preconnect listener is listening.
   - health endpoint is OK.
   - preconnected TCP and UDP pool counters are nonzero or refill logs show success.
2. On `sh36`:
   - runtime JSON contains `slave_relays` and `slave_uot_relay_port`/`uot_port`.
   - MPTCP runtime hash matches expected artifact.
   - logs show connection via slave preconnect candidate, not direct sidecar.
3. End-to-end:
   - TCP test still passes.
   - `iperf3 -R -u -b 300M` works or produces a new decisive failure with packet path evidence.

### E. Cleanup and commit boundary

1. Keep the useful handoff files:
   - `docs/superpowers/handoffs/2026-05-23-sh36-cfwg47-slave-resource-fix.md`
   - `docs/superpowers/handoffs/2026-05-24-chat-progress-and-remaining.md`
2. Delete or move temporary `tmp_*` evidence files after any needed details are copied into the handoff.
3. Decide whether to commit one combined fix or split into logical commits:
   - resource exhaustion / updater hardening;
   - MPTCP slave preconnect / UoT logic;
   - Rust relay artifacts;
   - panel provisioning/heartbeat republish;
   - Windows desktop config propagation.
4. After commit, push/sync as required and re-run live verification.

## Key evidence files from this chat

- `tmp_current_preconnect_tests_20260523.out`
- `tmp_fresh_sh36_slave3_uot_preconnect_20260523_202255.out`
- `tmp_live_mptcp47_uot_collect_20260523_063017.out`
- `tmp_all_slaves_update_20260524_004823.out`
- `tmp_update_sh36_lf_20260524_005316.out`
- `tmp_panel_probe47_current_20260524_005417.out`
- `tmp_search_preconnect_context_20260524_001241.out`

## Short next-step command checklist

```powershell
# 1) Review current diff
git status --short
git diff --stat

# 2) Focused local tests
py -3 -m unittest client-agent.tests.test_mptcp_preconnect_runtime slave-agent.tests.test_preconnect_relay
py -3 -m py_compile client-agent\leikwan_mptcp_client.py slave-agent\preconnect_relay.py
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/ClientAgentController.php

# 3) After sync/restart, live-check forward 47 config and UoT listener
# Use the local environment files for SSH details; do not paste credentials into tracked files.
```

## Final goal: target structure and functions

This is the target end state for the remaining work. Do not treat the task as finished until both the structure and the functions below are proven from live runtime.

### Target structure

- The topology stays strictly:

```text
client sh36 -> slave node/preconnect relay -> sidecar
```

- No direct client-to-sidecar path should be introduced as the normal design.
- `sh36` forward 47 keeps two cooperating paths:
  - Primary CF-WG path through `lw-cfwg-47` for the client-as-a-slave mode.
  - Slave-backed MPTCP leg such as `lw-mptcp-47-0` for smooth helper connectivity.
- Slave nodes host the helper/preconnect services inside the managed slave ingress range `7000-9000`.
- Each selected slave preconnect config should have both TCP and UoT/UDP capability when the forward needs UDP reverse testing:
  - TCP preconnect listener, for example `listen_port`.
  - UoT preconnect listener, for example `uot_listen_port`.
  - health listener.
  - sidecar target for the slave-to-sidecar leg.
- Panel provisioning should publish all required relay metadata to the client runtime:
  - `transport` resolved to `tcp+udp` when UoT/UDP is required.
  - `slave_relay_host` / `slave_relay_port` for backward compatibility.
  - `slave_uot_relay_host` / `slave_uot_relay_port` for UoT.
  - full `slave_relays[]` list so the runtime can rotate/fail over instead of depending on one stale helper.
- Client runtimes, Windows desktop runtime JSON, Python runtime, and Rust relay config should all preserve the same `slave_relays` structure.
- The slave preconnect relay should maintain warm sidecar streams before client traffic arrives:
  - TCP warm pool for normal MPTCP pre-establishment.
  - UDP/UoT warm pool for reverse UDP tests.
  - bounded refill/retry behavior so it does not recreate the old resource-exhaustion problem.

### Required functions

- `lw-cfwg-47` on `sh36` starts cleanly even if a stale interface/unit exists, and remains usable after client-agent refresh.
- A client TCP connection using the slave as an MPTCP leg should not wait for a fresh slave-to-sidecar setup on the hot path. The slave should already have the pre-established sidecar leg ready, matching the client-as-a-slave pre-establishment logic.
- If the first slave helper is down or stale, the client runtime should rotate through `slave_relays[]` candidates for both TCP and UDP/UoT.
- UoT/UDP must be implemented end-to-end for the slave leg:
  - panel emits UDP-capable config;
  - slave starts the UoT listener;
  - client chooses the slave UoT relay for UDP associations;
  - Rust/Python runtime avoids the wrong direct-sidecar handshake in slave-preconnect mode;
  - packet flow survives `iperf3 -R -u -b 300M` or fails with a new narrow packet-path cause.
- Slave resource behavior must stay bounded:
  - UOT queues are bounded and drop safely under overload;
  - systemd `MemoryMax`, restart delay, and start-limit guards are active;
  - update retry failures do not create a tight loop;
  - SSH remains responsive under normal slave-agent/UOT/preconnect load.
- Heartbeat/config refresh should republish active forwards when a client WireGuard identity changes, but should not flood the provision queue on ordinary heartbeats.
- Final verification must include all of the following:
  - `sh36` has fresh forward 47 config with `slave_relays` and UDP/UoT fields.
  - selected slave has active TCP and UoT preconnect listeners plus healthy warm-pool status.
  - `lw-cfwg-47` and `lw-mptcp-47-0` are both consistent with the intended topology.
  - TCP test passes.
  - `iperf3 -R -u -b 300M` passes, or the remaining failure is captured with decisive live evidence.



---

## Update 2026-05-24 03:30 local: latest progress, concerns, and deployment plan

### Latest progress since the last handoff update

Local verification was completed for the current dirty source tree before doing another live rollout:

- Resource/update hardening tests passed:
  - `tmp_verify_resource_20260524_032046.out`
  - Result: `Ran 26 tests ... OK`
- Slave/client preconnect tests passed:
  - `tmp_verify_preconnect_20260524_032046.out`
  - Result: `Ran 13 tests ... OK`
- Python compile passed for the touched client/slave runtimes:
  - `client-agent/leikwan_uot.py`
  - `client-agent/leikwan_mptcp_client.py`
  - `client-agent/client_agent.py`
  - `slave-agent/preconnect_relay.py`
  - `slave-agent/slave_agent.py`
- PHP syntax checks passed:
  - `master/src/Support/ProvisioningService.php`
  - `master/src/Controllers/ClientAgentController.php`
- PHP provisioning/heartbeat regression tests passed:
  - `master/tests/agent_udp_uot_sidepath_management_test.php`
  - `master/tests/client_heartbeat_republish_gate_regression_test.php`
  - `master/tests/provisioning_sidecar_dual_port_regression_test.php`
  - `master/tests/provisioning_active_endpoint_cfwg_transport_regression_test.php`
- Rust relay tests passed:
  - `tmp_verify_rust_mptcp_relay_20260524_032115.out`
  - Result: `15 passed; 0 failed`
- Broader Python focused client tests passed:
  - `tmp_verify_python_broader_20260524_032115.out`
  - Result: `Ran 106 tests ... OK`
- Windows desktop runtime propagation test passed:
  - `tmp_verify_dotnet_forward_runtime_20260524_032138.out`
  - Result: `Passed: 11, Failed: 0`

Panel/live progress observed from the newest artifacts:

- The panel/runtime artifact sync had already succeeded in `tmp_sync_panel_runtime_20260524_025512.out`, including hash/version entries for:
  - `client_agent`
  - `mptcp_client_runtime`
  - `install_forward_runtime`
  - `cfwg_latency_watchdog`
  - `uot_runtime`
  - `slave_agent`
  - `slave_preconnect_relay`
  - `leikwan_mptcp_relay_linux_amd64`
  - `leikwan_mptcp_relay_windows_amd64`
- A first republish check still produced a TCP-only preconnect task:
  - `tmp_republish499_transport_check_20260524_031126.out`
  - `transport_mode: tcp`
  - `underlay_transport: tcp`
  - This matched the earlier live symptom: TCP relay existed, but UoT was not actually enabled in the slave preconnect runtime.
- After fixing/syncing the panel query path, the newer republish check produced the expected UDP-capable task:
  - `tmp_republish499_transport_check_20260524_031323.out`
  - `REPUBLISH_OK=1`
  - pending task id: `3945736`
  - `transport_mode: tcp+udp`
  - `underlay_transport: tcp+udp`
  - `slave_preconnect: true`
  - `preconnect_relay_port: 7265`
  - `preconnect_uot_port: 7266`
  - `preconnect_pool_size: 1`
  - `preconnect_uot_pool_size: 1`

### Current concerns before the next live rollout step

These are the issues I am deliberately not hand-waving away:

1. **The newest correct panel task still needs to be consumed live.**
   - The decisive task is now `3945736`, and it is still only proven as a pending panel task in the latest evidence.
   - Need prove the slave agent applies this exact `tcp+udp` payload and rewrites `/var/lib/leikwan-slave/preconnect-2-499.json` accordingly.

2. **The previously observed slave runtime was still TCP-only.**
   - Earlier slave evidence showed:
     - TCP listener on `10.203.201.138:7265` existed.
     - health on `127.0.0.1:7267` existed.
     - UoT listener on `10.203.201.138:7266` refused connections.
     - preconnect JSON had `transport: tcp` and `preconnect_*` pool fields were null.
   - This is not acceptable for `iperf3 -R -u -b 300M`; it must become `transport: tcp+udp` with UoT listener active.

3. **Need verify sh36 receives the matching client runtime config, not only the slave task.**
   - Required fields on sh36:
     - `mptcp.transport = tcp+udp`
     - `slave_relays[]` populated
     - `slave_uot_relay_host` / `slave_uot_relay_port` populated
     - relay candidate for node 3 / endpoint 499 points at `10.203.201.138:7265` and UoT at `10.203.201.138:7266`
   - If sh36 still has stale JSON, the client runtime can still use old TCP-only or wrong candidate behavior even after the panel is corrected.

4. **The repo working tree remains broad and dirty.**
   - The intended changes span panel PHP, client Python, slave Python, Rust relay, Windows config propagation, installer/update helpers, version JSON, and built relay artifacts.
   - There are many `tmp_*` files. Do not commit them accidentally.
   - Deployment can proceed from the tested dirty tree, but final Git commit should be split or reviewed carefully.

5. **Potential live behavioral gap: preconnected pool semantics.**
   - Local tests prove the code path and warm pool counters, but live proof must show the slave actually logs/serves pre-established TCP and UDP/UoT sidecar streams before sh36 traffic arrives.
   - The final proof should show either `preconnected_tcp >= 1` and `preconnected_udp >= 1` or fresh refill logs immediately before connection checkout.

6. **`iperf3 -R -u -b 300M` must not be declared fixed from config alone.**
   - The prior failure was explained by TCP-only live preconnect, but the final status must be determined by packet/runtime evidence after applying `tcp+udp`.
   - If it still fails, the next root cause should be narrowed to one of:
     - sh36 not selecting slave UoT relay;
     - slave UoT listener not actually bound;
     - sidecar UDP stream handshake/token rejection;
     - reverse UDP path/framing issue inside Rust/Python runtime;
     - iperf3 target/listener mismatch.

### Deployment plan from here

#### Step 1: Freeze the deploy set mentally before running live commands

Deploy only the verified intended files/artifacts:

- Panel:
  - `master/src/Support/ProvisioningService.php`
  - `master/src/Controllers/ClientAgentController.php`
  - related artifact/version files already used by the panel artifact endpoint
- Client runtime:
  - `client-agent/client_agent.py`
  - `client-agent/leikwan_mptcp_client.py`
  - `client-agent/install_forward_runtime.py`
  - `client-agent/cfwg_latency_watchdog.py`
  - `client-agent/leikwan_uot.py`
  - `install/client-agent-update.sh`
  - `install/install-client-agent.sh`
  - Linux `leikwan-mptcp-relay` artifact
- Slave runtime:
  - `slave-agent/slave_agent.py`
  - `slave-agent/preconnect_relay.py`
  - `install/install-slave.sh`
  - `install/leikwan-slave-update`
  - `client-agent/leikwan_uot.py` as the shared UoT runtime artifact

Do not deploy random `tmp_*` files or unrelated dirty files.

#### Step 2: Apply the newest pending panel task to the selected slave

Target the node-3 / endpoint-499 preconnect path used by example `lw-mptcp-47-0`:

- Restart or poke `leikwan-slave-agent.service` on the selected slave so it pulls task `3945736`.
- Confirm task status becomes completed.
- Confirm `/var/lib/leikwan-slave/preconnect-2-499.json` now contains:

```json
{
  "transport": "tcp+udp",
  "listen_host": "10.203.201.138",
  "listen_port": 7265,
  "uot_listen_host": "10.203.201.138",
  "uot_listen_port": 7266,
  "preconnect_pool_size": 1,
  "preconnect_uot_pool_size": 1
}
```

Exact extra fields may differ, but those keys must be correct.

#### Step 3: Verify slave preconnect service live

On the selected slave:

- `systemctl show leikwan-preconnect-2-499.service -p ActiveState -p SubState -p ExecMainStatus -p NRestarts`
- `ss -ltnp | grep -E '(:7265|:7266|:7267)'`
- Query health on `127.0.0.1:7267` and confirm:
  - `status: ok`
  - `transport: tcp+udp`
  - `tcp_listen_port: 7265`
  - `uot_listen_port: 7266`
  - `preconnect_pool_size: 1`
  - `preconnect_uot_pool_size: 1`
  - `preconnected_tcp` and `preconnected_udp` nonzero, or logs showing successful refill.
- Check recent journal for:
  - `pre-established tcp sidecar stream`
  - `pre-established udp sidecar stream`
  - no tight restart loop.

#### Step 4: Refresh sh36 runtime config and verify selection

On sh36:

- Update client agent/runtime artifacts if the local hash differs from panel public artifact hash.
- Restart or refresh `leikwan-client-agent.service` so forward 47 config is regenerated.
- Confirm `/etc/leikwan/mptcp-forward-47.json` contains:
  - `transport: tcp+udp`
  - `slave_relays[]` with node 3 / endpoint 499
  - TCP relay candidate `10.203.201.138:7265`
  - UoT relay candidate `10.203.201.138:7266`
- Confirm `leikwan-client-47.service` is active and logs show slave-preconnect candidate use, not direct sidecar use, for the helper leg.

#### Step 5: Live functional proof

Run the proof in this order:

1. TCP sanity:
   - local forward check on `127.0.0.1:11035`
   - short `iperf3 -R` TCP test through forward 47
2. Helper leg proof:
   - observe slave preconnect journal while sh36 connects
   - confirm connection checkout uses pre-established stream where possible
3. UDP/UoT proof:
   - run `iperf3 -R -u -b 300M` through `127.0.0.1:11035`
   - at the same time watch:
     - sh36 `leikwan-client-47.service` journal
     - slave `leikwan-preconnect-2-499.service` journal
     - health counters and socket counters
4. If UDP still fails, capture the narrow failure point immediately instead of changing more code.

#### Step 6: Handoff/commit cleanup after live proof

After live proof:

- Append the final proof or remaining narrow blocker to this markdown file.
- Clean or ignore temporary evidence files.
- Prepare commit boundaries carefully:
  1. slave resource/update hardening;
  2. slave preconnect + UoT runtime logic;
  3. panel provisioning/heartbeat republish;
  4. Rust relay artifacts;
  5. Windows runtime JSON propagation.

### Current task status

- Local code verification: **passed**.
- Panel emission of `tcp+udp` preconnect task: **passed in latest panel evidence**.
- Slave consumption of the newest `tcp+udp` task: **pending live proof**.
- sh36 runtime refresh with `slave_relays` + UoT fields: **pending live proof**.
- `iperf3 -R -u -b 300M`: **not yet proven fixed**.


---

## Update 2026-05-24 03:45 local: changes after reading `20260524.txt`

I read `docs/superpowers/handoffs/20260524.txt` and amended the execution plan. The current plan is no longer just ?make TCP+UDP listen?; it now has an explicit warmth/quality gate before throughput testing.

### Accepted changes from `20260524.txt`

1. **Pool size `1` is only a smoke-test state, not the final smooth state.**
   - Immediate rollout still proves `tcp+udp` with pool `1` first, because the latest pending task already has that shape and it is the safest minimal change.
   - After that works, bump the target to:
     - `preconnect_pool_size: 2` initially, then `3` if resource headroom stays good.
     - `preconnect_uot_pool_size: 2`.
     - `preconnect_stream_max_idle_s: 120`.
   - Verify memory/FD/socket headroom after the bump so the old slave resource-exhaustion failure does not return.

2. **Add a mandatory connection-warmth gate before `iperf3`.**
   - On the slave, prove warm state before traffic:
     - `pre-established tcp sidecar stream` in journal.
     - `pre-established udp sidecar stream` in journal.
     - health shows `preconnected_tcp >= 1` and `preconnected_udp >= 1`.
     - `ss` shows established slave-to-sidecar streams to `10.203.201.139:8898`.
   - On sh36, prove the runtime chooses the slave relay/UoT candidate, not the direct sidecar path.
   - First setup should be warm; if first setup is slow/cold, debug the pool before running throughput tests.

3. **Add explicit UDP failure narrowing.**
   If `iperf3 -R -u -b 300M` still fails, narrow in this order:
   1. sh36 log shows slave UoT relay candidate selected?
      - No: client config or candidate-selection bug.
      - Yes: continue.
   2. slave journal shows UDP/UoT preconnected checkout or accepted UoT stream?
      - No: sh36 cannot reach slave UoT listener, wrong port, firewall, or stale config.
      - Yes: continue.
   3. slave logs show UDP sidecar stream established/forwarding?
      - No: slave UoT framing/stream path bug.
      - Yes: continue.
   4. sidecar accepts UDP association/token?
      - No: token/sidecar UDP enablement mismatch.
      - Yes: continue.
   5. reverse data returns sidecar -> slave -> sh36?
      - No: reverse routing/NAT/UoT reassembly problem.
      - Yes: throughput/MTU/buffer issue.

4. **Add multi-path aggregate proof after basic TCP/UDP passes.**
   - Verify `lw-cfwg-47` and `lw-mptcp-47-0` are both present and coherent.
   - Confirm MPTCP/subflow visibility with `ss` / `ip mptcp` tooling where available.
   - Run TCP aggregate proof, for example `iperf3 -R -b 500M`, and compare against single-leg behavior.
   - Verify one leg can degrade/drop without fully interrupting service.

5. **Keep client-as-a-slave warm/pre-establishment as a final-goal requirement.**
   - The slave helper path must use the same style of pre-establishment expected from client-as-a-slave.
   - For the current rollout, the live proof focuses on the slave helper leg and UoT. A follow-up implementation item remains to give the `lw-cfwg-47` client-as-a-slave side the same warm-link treatment after brief WG/CF blips.

6. **Record latency/path-quality work as required for IEPL-like behavior, but not as a blocker for the immediate TCP+UDP rollout.**
   - Near-term verification collects health/connect latency from each relay.
   - Follow-up implementation should make relay selection latency-aware instead of simple rotation.
   - Follow-up implementation should report sidecar path latency/degraded state from the slave health endpoint.
   - Adaptive pool sizing is a post-rollout enhancement: grow under burst load, shrink when idle.

### Revised execution order from this point

1. **Baseline and pending-task proof.**
   - Confirm the newest panel task for endpoint `499` / forward `47` is `tcp+udp` and pending/completed.
   - Confirm no older TCP-only state is still authoritative.

2. **Apply minimal `tcp+udp` task to slave node 3.**
   - Force or poke slave agent to consume task `3945736`.
   - Verify `/var/lib/leikwan-slave/preconnect-2-499.json` becomes `transport: tcp+udp`.
   - Verify listeners `7265`, `7266`, and `7267` are bound.

3. **Warmth gate with pool=1.**
   - Confirm health and journals show pre-established TCP and UDP/UoT sidecar streams.
   - If warmth is not proven, stop and debug before `iperf3`.

4. **Refresh sh36.**
   - Ensure `/etc/leikwan/mptcp-forward-47.json` includes `transport: tcp+udp`, populated `slave_relays[]`, and UoT relay fields.
   - Ensure `leikwan-client-47.service` is active.

5. **Functional proof.**
   - TCP sanity through `127.0.0.1:11035`.
   - UDP proof with `iperf3 -R -u -b 300M`.
   - If UDP fails, follow the explicit narrowing tree above.

6. **Bump warm pool after minimal proof.**
   - Republish with TCP pool `2` and UoT pool `2`, plus `preconnect_stream_max_idle_s=120`.
   - Verify warmth and resource headroom again.
   - Only consider TCP pool `3` after memory/FDs stay healthy.

7. **Multi-path aggregate proof.**
   - Verify `lw-cfwg-47` + `lw-mptcp-47-0` operate together.
   - Run aggregate TCP proof and record whether both paths contribute.

8. **Final handoff and commit cleanup.**
   - Append decisive live evidence or a narrow blocker to this file.
   - Keep temporary files out of commits.

---

## Execution start 2026-05-24 04:05:36 +08:00 local after re-reading 20260524.txt

I re-read docs/superpowers/handoffs/20260524.txt before executing. The execution gate for this pass is:

1. Re-confirm the latest panel/pending task and slave runtime state for endpoint 499 / forward 47.
2. Apply or re-apply the minimal tcp+udp preconnect task to slave node 3 and prove the slave config/listeners/health.
3. Do the mandatory warmth gate before any throughput claim.
4. Refresh/verify sh36 forward 47 runtime config uses slave_relays[] plus UoT relay fields.
5. Run TCP sanity, then iperf3 -R -u -b 300M; if UDP fails, use the narrowing tree from the prior section instead of guessing.
6. Only after the minimal proof, consider bumping warm pools above 1 and checking resource headroom.

Sensitive credentials remain local-only and must not be copied into this markdown.

---

## Execution resume 2026-05-24 04:24:24 +08:00 local

Read docs/superpowers/handoffs/20260524.txt again for this pass and will execute step by step against the revised gates:

1. Baseline panel task/slave/sh36 runtime state.
2. Apply or re-apply the minimal tcp+udp preconnect task to slave node 3 / endpoint 499.
3. Prove slave TCP+UoT listeners plus warm-pool health before throughput testing.
4. Refresh/verify sh36 forward 47 runtime config selects slave_relays[] and UoT relay fields.
5. Run TCP sanity, then iperf3 -R -u -b 300M; if UDP fails, capture the narrow runtime branch before any new code changes.
6. Record the live evidence or blocker here.

Credentials remain local-only and are not copied into this handoff.

---

## Execution resume 2026-05-24 04:38:20 +08:00 local: after user requested read/update/execute

Re-read docs/superpowers/handoffs/20260524.txt from the same handoff directory and confirmed the tracker already contained the major accepted changes: warm-pool sizing beyond smoke-test, mandatory warmth gate, UDP/UoT narrowing tree, multi-path aggregate proof, and final client-as-a-slave warm-preestablishment goal.

This pass will execute step by step and update this file after each decisive result:

1. Baseline panel task, slave node 3 / endpoint 499 runtime state, and sh36 forward 47 config.
2. Apply or re-apply the minimal tcp+udp preconnect task if the slave is stale.
3. Prove slave listeners and warm TCP+UDP/UoT pool before throughput testing.
4. Refresh/verify sh36 selects slave_relays[] with UoT relay fields.
5. Run TCP sanity then iperf3 -R -u -b 300M; if UDP fails, record the exact branch in the narrowing tree.
6. After minimal proof, only then consider pool-size bump and aggregate lw-cfwg-47 + lw-mptcp-47-0 proof.

Sensitive values remain local-only and are not copied into this handoff.


---

## Execution note 2026-05-24 04:50 +08:00: root cause branch before code change

Baseline execution reached the mandatory warmth gate and then narrowed the failure:

- Slave node 3 / endpoint 499 is running `transport: tcp+udp` with listeners `10.203.201.138:7265`, `10.203.201.138:7266`, health `127.0.0.1:7267`.
- Health and journal prove both warm streams exist and are checked out:
  - `pre-established tcp sidecar stream`
  - `pre-established udp sidecar stream`
  - `using pre-established tcp sidecar stream`
  - `using pre-established udp sidecar stream`
- sh36 config is `transport: tcp+udp`, includes `slave_relays[]`, and can connect to node-3 TCP and UoT relay ports in about 6 ms.
- sh36 `iperf3 -R` and `iperf3 -R -u -b 300M` both failed with `control socket has closed unexpectedly`.
- Decoded sh36 runtime token payload locally from `/etc/leikwan/mptcp-forward-47.json`: both TCP and UDP tokens target `127.0.0.1:5201` on the sidecar. From the slave, direct TCP to sidecar overlay `10.203.201.139:5201` works and a direct reverse TCP iperf from slave to sidecar succeeds around 259 Mbit/s.

Root-cause hypothesis before change: `slave-agent/preconnect_relay.py` sends the sidecar auth handshake when the warm stream is created. The sidecar runtime immediately verifies the token and connects to the token target (`127.0.0.1:5201`), before any real client bytes arrive. For iperf TCP, that leaves an idle or already-advanced iperf server-side control session in the warm pool. When sh36 later checks out that stream, the iperf protocol state is no longer at connection start, so the control socket closes. This also explains why bare TCP connect probes and zero-byte checkouts look healthy while real iperf fails.

Next implementation step: change warm-pool semantics so preconnected streams are only transport-warm to sidecar; send the TCP/UDP sidecar handshake at checkout time, immediately before relaying the real client stream. Add a regression test that a warm stream does not send a handshake until checkout.

---

## Latest progress 2026-05-24 05:16 +08:00

I re-read `docs/superpowers/handoffs/20260524.txt` and the investigation moved one layer deeper:

- slave node 3 is now confirmed to have warm TCP and UDP sidecar streams available
- sh36 still fails at the protocol handoff stage, not at basic reachability
- `tmp_sidecar_handshake_timeout_probe_20260524_051555.out` shows delayed send/read timeout behavior against `10.203.201.139:8898`
- the evidence fits a stream that is being advanced too early, before real client bytes arrive

### Current concern

- `slave-agent/preconnect_relay.py` likely sends the sidecar handshake when the warm stream is created instead of when the stream is checked out for a live client
- that makes the pool protocol-warm rather than transport-warm, which can poison the iperf control session before the user traffic arrives
- this now looks more likely than a missing UoT listener on its own
- the immediate user-visible symptom remains `control socket has closed unexpectedly` on both `iperf3 -R` and `iperf3 -R -u -b 300M`

### Deployment plan

1. Change warm-pool semantics so preconnected streams stay transport-warm only.
2. Move the TCP and UDP sidecar handshake to checkout time, immediately before relaying the real client stream.
3. Add a regression proving that a warm stream does not emit handshake traffic until checkout.
4. Re-run the focused preconnect, UoT, provisioning, and relay tests.
5. Rebuild and refresh artifact hashes if any runtime binary changes.
6. Redeploy the updated slave and client runtime, then re-run the warmth gate before `iperf3 -R -u -b 300M`.
7. Keep the client-as-a-slave warm-preestablishment goal as the next follow-up after the immediate fix is stable.

### Latest execution order

1. Baseline current slave and sh36 config state.
2. Implement and verify the handshake deferral fix.
3. Recheck warm pool health and connect latency.
4. Retry TCP sanity, then `iperf3 -R -u -b 300M`.
5. Only then consider enlarging pool sizes or tuning latency-aware relay selection.

---

## Execution resume 2026-05-24 10:41:55 +08:00 local

Re-read docs/superpowers/handoffs/20260524.txt and rechecked the immediate code surface before deployment. Current local slave-agent/preconnect_relay.py already has the intended first fix shape:

- warm-pool creation logs pre-established ... sidecar stream (transport-warm) and does **not** call send_handshake(...) while filling the pool;
- checkout_sidecar_stream(...) sends the TCP/UDP sidecar handshake only when a live client checks out a stream;
- slave-agent/tests/test_preconnect_relay.py includes a regression that the warm TCP stream emits no sidecar handshake until checkout.

Execution from here is therefore verification-first, then deployment:

1. Run focused local tests/compile for preconnect, UoT, provisioning, and relay changes.
2. If tests pass, sync the updated slave/client/panel files and artifact manifest where required.
3. Restart/trigger the slave node 3 preconnect relay and sh36 forward 47 runtime.
4. Re-run the warmth gate, then TCP sanity, then iperf3 -R -u -b 300M.
5. If UDP still fails, capture the exact branch in the UoT narrowing tree instead of making another broad change.

Sensitive values remain local-only.


---

## Execution resume 2026-05-24 13:41:46 +08:00 local: post-deployment failure narrowed again

After re-reading `docs/superpowers/handoffs/20260524.txt` and reviewing the latest execution evidence, the previous handshake-deferral fix is present in the live relay, but the end-to-end failure remains.

### Latest proven evidence

- `slave-agent/preconnect_relay.py` is now running the intended transport-warm behavior:
  - warm pool creation logs `pre-established ... sidecar stream (transport-warm)`;
  - checkout logs `using pre-established ... sidecar stream`;
  - the local regression proves warm streams do not emit the sidecar handshake before checkout.
- The selected slave node 3 / endpoint 499 still has the expected live shape:
  - `transport: tcp+udp`;
  - TCP listener `10.203.201.138:7265`;
  - UoT listener `10.203.201.138:7266`;
  - health `127.0.0.1:7267`;
  - warm TCP and UDP pool counters available.
- New protocol-narrow evidence from `tmp_f47_protocol_narrow_20260524_122022.out`:
  - direct slave -> sidecar overlay `10.203.201.139:5201` TCP reverse iperf succeeds, so the sidecar-side target is reachable over the slave-sidecar overlay;
  - slave -> preconnect TCP `10.203.201.138:7265` still fails with `control socket has closed unexpectedly`;
  - sh36 local `127.0.0.1:11035` TCP and UDP reverse tests still fail with the same control socket close;
  - relay logs show the client connects and a warm stream is checked out, but the sidecar direction EOFs immediately (`remote->local eof after 0 bytes`).
- Current sh36 token decode from `tmp_decode_f47_sidecar_access_20260524_043255.out` shows both TCP and UDP session tokens target:
  - `target_host: 127.0.0.1`;
  - `target_port: 5201`;
  - `target_protocol: tcp/udp`.
- Direct sh36 probes to the sidecar target addresses returned connection refused, while slave-to-sidecar overlay `10.203.201.139:5201` works. That makes the current strongest root-cause branch: the preconnect sidecar runtime is receiving a sidecar-local token target of `127.0.0.1:5201`, but the live target is reachable as the sidecar overlay address `10.203.201.139:5201`, not loopback.

### Current concern

The earlier warm-pool bug was real and has been fixed, but it is not the only blocker. The remaining blocker appears to be a **token target mismatch for CF-mode MPTCP/preconnect**:

- `ProvisioningService::buildBondSessionToken(...)` rewrites CF-mode tokens to `127.0.0.1` when the forward target host equals the sidecar host.
- That is valid only if the target service is actually reachable from the sidecar runtime on loopback.
- In this live forward 47 path, evidence says the reachable target for the MPTCP/preconnect leg is `10.203.201.139:5201`.
- Therefore sidecar accepts the preconnect handshake, then immediately closes when its target connect fails, which matches the observed zero-byte EOF and iperf control-close behavior.

### Next deployment/debugging step

Before changing panel token generation, run a minimal A/B proof:

1. Generate temporary TCP/UDP tokens for the same forward and token secret with target A = `127.0.0.1:5201` and target B = `10.203.201.139:5201`.
2. Start a temporary slave preconnect relay on unused managed ports inside `7000-9000` using the B token only.
3. Run `iperf3 -c <slave-overlay> -p <temp-port> -R` from the slave itself.
4. If target B succeeds while the current target A fails, implement a narrow provisioning fix so MPTCP/slave-preconnect tokens use the sidecar overlay target when CF-mode target-loopback is not valid.
5. Then sync panel/runtime, republish forward 47, restart slave preconnect and sh36 runtime, and re-run the mandatory warmth gate plus TCP/UDP `iperf3` proof.

No success claim should be made until the A/B token-target test and the refreshed live forward both pass.


---

## Execution update 2026-05-24 13:53:27 +08:00 local: A/B proof changed root-cause branch

The A/B token-target test was run before changing code.

### Decisive result

- Temporary preconnect relay with pool disabled (`preconnect_pool_size=0`) works for TCP iperf through the same slave/sidecar path.
- Both test token targets worked for TCP:
  - target A `127.0.0.1:5201` succeeded around 80 Mbps receiver in `tmp_ab_token_target_preconnect_20260524_134342.out`;
  - target B `10.203.201.139:5201` also succeeded around 119 Mbps receiver.
- Therefore the previous token-target mismatch hypothesis is **not** the current TCP root cause.

### New root cause branch

The current official slave preconnect config contains stale session tokens:

- `tmp_official_token_validity_20260524_135035.out` shows:
  - slave `tcp_session_token` and `udp_session_token`: `valid: false` against the current panel sidecar-point secret;
  - sh36 `tcp_session_token` and `udp_session_token`: `valid: true`;
  - slave token issue window is older (`issued_at: 1779494400`), while sh36 has newer tokens (`issued_at: 1779580800`).
- This explains the official relay symptom exactly:
  - official slave relay accepts the client and sends a handshake;
  - sidecar rejects the stale/invalid token and closes immediately;
  - sh36 sees `remote->local eof after 0 bytes`; iperf reports `control socket has closed unexpectedly`.

### Next execution step

Do not make a new source change yet. First force endpoint 499 / node 3 to consume the latest panel preconnect task or republish it, then verify:

1. `/var/lib/leikwan-slave/preconnect-2-499.json` has fresh valid tokens matching current panel secret.
2. `leikwan-preconnect-2-499.service` restarts cleanly and keeps TCP+UDP listeners.
3. Official preconnect TCP iperf succeeds without temporary config.
4. Then rerun sh36 `127.0.0.1:11035` TCP and UDP tests.

If token refresh fixes TCP but UDP still times out, continue with the UDP-specific narrowing tree.

---

## Execution update 2026-05-24 14:12 +08:00 local: refreshed tokens still invalid, panel context bug found

The latest republish attempt created endpoint-499 task `3945738` and slave node 3 consumed it, but the official preconnect relay still failed. The decisive branch changed again:

- The slave config token issue window refreshed to match sh36 (`issued_at: 1779580800`, `expires_at: 1780185600`), so this is no longer an old-task consumption problem.
- A direct validity check against the sidecar point secret still reported both refreshed slave preconnect tokens invalid.
- A matrix using the official refreshed tokens failed for `tcp_pool0`, `tcp_pool1`, `tcpudp_pool0`, and `tcpudp_pool1`; each relay sent only the iperf client preamble (`out=38`) and then the sidecar closed. This removes warm-pool behavior as the remaining TCP blocker.
- Temporary A/B tokens generated explicitly with the sidecar point secret still succeed for TCP, proving the sidecar path and preconnect relay transport are OK when the token is signed with the correct secret.

Root cause now identified in panel code:

- `buildMptcpSlaveUnderlayPayload(...)` calls `bondTokenSecret($forward)`.
- The republish/publish contexts used for slave `mptcp_underlay` did not reliably include `sp.token_secret AS bond_sidecar_point_token_secret`.
- Therefore slave preconnect tokens could be generated from the service token or env fallback while the sidecar validates with the sidecar point `token_secret`.
- sh36 client config had valid tokens because the client-side query path already included the sidecar-point token secret.

Local fix started:

- Added a regression in `master/tests/provisioning_mptcp_sidecar_wireguard_port_regression_test.php` that enables slave preconnect and proves the underlay TCP token validates with the sidecar point token secret.
- Patched `master/src/Support/ProvisioningService.php` so the active endpoint/republish/reconcile query paths carry `sp.token_secret AS bond_sidecar_point_token_secret`, and added a fallback lookup in `bondTokenSecret(...)` by `bond_sidecar_point_id`.
- Focused local checks now pass:
  - `php -l master/src/Support/ProvisioningService.php`
  - `php master/tests/provisioning_sidecar_dual_port_regression_test.php`
  - `php master/tests/provisioning_mptcp_sidecar_wireguard_port_regression_test.php`

Next execution steps:

1. Run the remaining focused local verification bundle for preconnect/UoT/panel paths.
2. Sync patched `ProvisioningService.php` to panel.
3. Republish endpoint 499 again and force slave node 3 to consume the new task.
4. Verify slave `tcp_session_token` and `udp_session_token` are valid against the sidecar point secret.
5. Restart `leikwan-preconnect-2-499.service`, verify listeners/warm pools, then run official TCP and UDP iperf from slave.
6. Refresh/verify sh36 and rerun `iperf3 -R` then `iperf3 -R -u -b 300M` through `127.0.0.1:11035`.

---

## Execution update 2026-05-24 14:25 +08:00 local: sidecar-token-secret fix deployed and minimal TCP/UDP proof passed

The panel fix was synced and verified live.

### What changed live

- Synced patched `master/src/Support/ProvisioningService.php` to the panel.
- Republished endpoint `499` / node `3` / forward `47` again.
- Forced slave node 3 to consume the new task and restarted `leikwan-preconnect-2-499.service`.

### Decisive live proof

- Slave preconnect config now has refreshed tokens that validate against the sidecar point secret:
  - TCP token: `valid: true`.
  - UDP token: `valid: true`.
- Slave preconnect relay is active and warm:
  - TCP listener `10.203.201.138:7265`.
  - UoT listener `10.203.201.138:7266`.
  - health `status: ok`.
  - `preconnected_tcp: 1`, `preconnected_udp: 1`.
- Official slave TCP preconnect proof now passes without temporary configs:
  - `iperf3 -c 10.203.201.138 -p 7265 -R -t 3` returned `OFFICIAL_SLAVE_TCP_RC=0`.
- sh36 forward 47 proof now passes through the real local forward:
  - TCP: `iperf3 -c 127.0.0.1 -p 11035 -R -t 3` returned `SH36_TCP_RC=0`, receiver about `93.7 Mbit/s`.
  - UDP: `iperf3 -c 127.0.0.1 -p 11035 -R -u -b 300M -t 3` returned `SH36_UDP300_RC=0`, sender exactly about `300 Mbit/s`, receiver about `87.5 Mbit/s`, loss about `12.3%`.
- sh36 logs prove the intended slave paths were used:
  - `tcp relay ... connected via slave preconnect relay=10.203.201.138:7265`.
  - `udp association ... connected via slave UoT preconnect relay=10.203.201.138:7266`.

### Remaining nuance

- The slave-local UDP iperf to UoT port with `-b 50M` timed out under `timeout` (`OFFICIAL_SLAVE_UDP50_RC=124`) while sh36 UDP through the real runtime succeeded. That looks like a test-harness/iperf reverse-UDP termination issue rather than the original runtime failure, because the actual sh36 UoT path now connects and completes with `SH36_UDP300_RC=0`.
- The `300M` UDP proof is functionally working but not clean at full throughput: receiver was around `87.5 Mbit/s` with about `12.3%` loss. Throughput/loss tuning remains separate from the original missing/broken UoT logic.

### Next steps

1. Do the planned post-minimal-proof bump: `preconnect_pool_size=2`, `preconnect_uot_pool_size=2`, `preconnect_stream_max_idle_s=120`.
2. Republish/consume endpoint `499` again.
3. Verify warm counters and memory/socket headroom on slave node 3.
4. Run the aggregate `lw-cfwg-47` + `lw-mptcp-47-0` proof and record whether both legs contribute.


---

## Execution resume 2026-05-24 16:20 +08:00 local: proceeding after minimal TCP/UDP proof

Re-read `docs/superpowers/handoffs/20260524.txt` and the latest live handoff state. The immediate runtime blocker is no longer "missing UoT on sh36": sh36 TCP and `iperf3 -R -u -b 300M` both passed through the real local forward after the sidecar-token-secret fix, and logs showed the slave TCP and UoT preconnect relays were selected.

### Current concerns to carry into execution

- The `300M` UDP proof is functional but not clean: the last known receiver side was about `87.5 Mbit/s` with about `12.3%` loss, so throughput/loss tuning remains open even though UoT is now implemented end-to-end.
- Warm pools are still at the conservative smoke-test size (`1`/`1`). Per `20260524.txt`, this is too thin for smooth IEPL-like behavior after the first checkout; a second warm stream should exist while the first one is in use/refilling.
- Increasing pool size must be paired with resource checks because the earlier slave outage root cause was UOT/preconnect resource growth and update churn.
- Aggregate proof still needs to verify the intended final structure: `lw-cfwg-47` as the client-as-a-slave primary path plus `lw-mptcp-47-0` as a slave helper leg, not just a single local-forward success.
- The slave-local reverse UDP test timing out under `timeout` remains a diagnostic nuance; the real sh36 UDP path passing is stronger evidence, but if it resurfaces the UDP narrowing tree should be used rather than guessing.

### Deployment plan now being executed

1. Capture the current diff/status boundary so the large dirty tree is not accidentally broadened.
2. Run focused local verification for the preconnect/UoT/provisioning/runtime changes.
3. Deploy the post-minimal-proof pool/idle settings:
   - `MPTCP_PRECONNECT_POOL_SIZE=2`
   - `MPTCP_PRECONNECT_UOT_POOL_SIZE=2`
   - `MPTCP_PRECONNECT_STREAM_MAX_IDLE_S=120`
4. Republish/consume endpoint `499` for forward `47` on slave node `3`.
5. Verify live slave state before traffic:
   - config shows `transport: tcp+udp`, pool `2/2`, max idle `120`;
   - TCP and UoT listeners are up;
   - health shows warm TCP and UDP counters refilled;
   - memory and FD/socket headroom remain safe.
6. Restart/refresh sh36 forward 47 runtime if required, then rerun:
   - TCP reverse iperf through `127.0.0.1:11035`;
   - UDP reverse iperf at `300M` through `127.0.0.1:11035`.
7. Run aggregate proof for final structure:
   - `lw-cfwg-47` and `lw-mptcp-47-0` exist and have the expected routes/handshakes;
   - logs prove TCP and UDP selected slave preconnect paths when using the helper;
   - MPTCP/subflow state is captured, or if only one path contributes, record the exact live reason.

No final success claim should be made until the pool bump and aggregate proof are verified live.

---

## Execution update 2026-05-24 16:43:35 +08:00 local: pool bump exposed sidecar MPTCP listener regression

After re-reading `docs/superpowers/handoffs/20260524.txt`, I resumed from the post-minimal-proof pool bump path.

### Current live evidence

- Focused local verification passed for the active code surface:
  - Python compile and 44 focused Python tests passed.
  - `ProvisioningService.php` and `ClientAgentController.php` linted cleanly.
  - Focused provisioning/UoT/heartbeat PHP regressions passed.
  - Output: `tmp_verify_focused_resume_20260524_162216.out`.
- Panel runtime gate now shows the intended post-minimal-proof settings:
  - `MPTCP_PRECONNECT_POOL_SIZE=2`.
  - `MPTCP_PRECONNECT_UOT_POOL_SIZE=2`.
  - `MPTCP_PRECONNECT_STREAM_MAX_IDLE_S=120`.
  - Active MPTCP forward 47 still has four slave-transit underlays in the panel sample.
  - Output: `tmp_panel_mptcp_runtime_gate_20260524_163412.out`.
- Slave node 3 consumed the pool-bumped config:
  - `transport: tcp+udp`.
  - TCP listener `10.203.201.138:7265`.
  - UoT listener `10.203.201.138:7266`.
  - `preconnect_pool_size: 2`, `preconnect_uot_pool_size: 2`, `preconnect_stream_max_idle_s: 120`.
- New blocker: the slave relay cannot currently refill or checkout warm streams because the sidecar MPTCP listener is refusing connections:
  - `CONNECT_FAIL 10.203.201.139 8898 ConnectionRefusedError`.
  - relay health is `degraded`, `preconnected_tcp: 0`, `preconnected_udp: 0`.
  - journal repeats `preconnect tcp/udp refill failed: failed to connect to sidecar 10.203.201.139:8898: [Errno 111] Connection refused`.
- The sidecar overlay itself is not fully dead: slave can still reach `10.203.201.139:5201`, but `8898` is refused. This points at a sidecar runtime/listener issue, not a generic slave-side WG route outage.

### Concern

The pool-size change itself is not proven broken. The current decisive branch is that the sidecar-side MPTCP/preconnect listener on overlay port `8898` is down or not bound after sidecar/runtime updates. Until that listener is restored, all preconnect pool and iperf checks will fail regardless of client/slave logic.

### Execution plan from here

1. Inspect the sidecar only through the slave hop, preserving `client -> slave -> sidecar` topology.
2. Verify on the sidecar host/service:
   - sidecar agent status and logs;
   - whether anything is listening on `0.0.0.0:8898` / overlay `10.203.201.139:8898`;
   - whether the sidecar MPTCP runtime process/unit was stopped, failed, or rendered with the wrong bind/listen port;
   - whether recent sidecar-agent changes around managed iperf3 or ports disabled/overrode the MPTCP listener.
3. Apply the narrowest recovery:
   - restart/re-render the sidecar MPTCP listener if config is correct;
   - otherwise patch the sidecar runtime/listener generation and sync it.
4. After `10.203.201.139:8898` accepts again, rerun the mandatory warmth gate before any throughput claim:
   - health `status: ok`;
   - `preconnected_tcp >= 2`, `preconnected_udp >= 2` after refill;
   - memory/FD/socket headroom remains safe.
5. Then rerun sh36 TCP reverse iperf, sh36 `iperf3 -R -u -b 300M`, and the final aggregate structure/function proof.

### Final goal still being driven

- **Structure:** forward 47 uses `lw-cfwg-47` as the client-as-a-slave primary CF-WG MPTCP path and `lw-mptcp-47-0` as the slave-helper MPTCP leg, preserving `client -> slave -> sidecar` for sidecar access.
- **Functions:** slave-helper legs use the same pre-establishment/warm-connection behavior as client-as-a-slave mode, including TCP and UoT/UDP preconnect, bounded resources, pool refill/stale handling, and verifiable smooth client connection behavior under `iperf3` TCP and UDP reverse tests.

---

## Execution update 2026-05-24 18:20 +08:00 local: pause for technical discussion

This update records the latest state before making another live deployment. Sensitive values remain local-only.

### What I just did

- Re-ran the post-pool-bump live proof after the earlier sidecar `8898` listener issue had recovered.
- Verified slave node 3 / endpoint 499 is again usable as the warm preconnect helper:
  - `leikwan-preconnect-2-499.service`: `active/running`, `NRestarts=0`.
  - Health endpoint: `status: ok`.
  - Warm pools refilled around the test window: `preconnected_tcp` and `preconnected_udp` returned to `2/2` after traffic.
  - Memory stayed small, around 14-17 MB for the preconnect relay; FD count stayed around 12-13.
  - TCP and UoT listeners were present on `10.203.201.138:7265` and `10.203.201.138:7266`.
- Verified sh36 forward 47 still has the intended two-interface structure:
  - `lw-cfwg-47` as primary CF-WG/client-as-a-slave leg.
  - `lw-mptcp-47-0` as the slave-backed helper/standby MPTCP leg.
  - Runtime config still has `transport: tcp+udp` and the selected relay host/ports for endpoint 499.
- Ran TCP reverse iperf through the real local forward:
  - `iperf3 -c 127.0.0.1 -p 11035 -R -t 3` returned success.
  - Receiver was about `108 Mbit/s` in `tmp_sh36_pool2_iperf_verify_20260524_181241.out`.
- Ran the focused UDP reverse proof through the real local forward:
  - `iperf3 -c 127.0.0.1 -p 11035 -R -u -b 300M -t 4 -V` returned `IPERF_UDP_VERBOSE_RC=0`.
  - Sender hit the configured `300 Mbit/s` rate.
  - Receiver was about `82.6 Mbit/s` with high loss in this latest run (`1960/3221`, about `61%`).
  - Logs proved the real UoT path was selected: `udp association ... connected via slave UoT preconnect relay=10.203.201.138:7266`.
- Captured the decisive current failure pattern:
  - sh36 runtime still contains four `slave_relays[]`: endpoint `499` plus stale/unreachable endpoints `500`, `501`, and `564`.
  - Candidate probes showed only endpoint `499` (`10.203.201.138`) is reachable from sh36.
  - The other candidates time out on both TCP and UoT ports:
    - `10.203.201.174:7598/7599`
    - `10.203.201.178:7635/7636`
    - `10.203.201.182:7672/7673`
  - sh36 logs show it tries these dead relay candidates before falling back to endpoint `499`, adding avoidable setup delay and jitter.

### New local code fix prepared, not deployed yet

I stopped before deploying this because you asked for a technical discussion.

Root cause for the latest smoothness issue:

- `ClientAgent.apply_mptcp_runtime_health_policy(...)` filtered active `mptcp_endpoints` and `underlays` down to the selected runtime endpoints, but it did **not** filter `mptcp.slave_relays[]`.
- Result: the rendered runtime had only `lw-mptcp-47-0` as the selected helper interface, but still carried stale relay candidates for unselected/dead endpoints. The data path then wasted time trying those dead relays.

TDD fix done locally:

- Added failing regression:
  - `client-agent/tests/test_highloss_self_leg_runtime_regression.py::test_mptcp_runtime_filters_slave_relays_to_selected_endpoints`
- Watched it fail as expected:
  - expected `slave_relays == [499]`, actual was `[499, 500, 501, 564]`.
- Patched:
  - `client-agent/client_agent.py`
- New behavior:
  - After runtime health selection, `mptcp.slave_relays[]` is filtered to selected endpoint/node IDs.
  - `slave_relay_host` / `slave_relay_port` are reset to the first selected relay.
  - `slave_uot_relay_host` / `slave_uot_relay_port` are reset to the first selected UoT relay.
- Focused local verification passed:

```powershell
py -3 -m unittest client-agent.tests.test_highloss_self_leg_runtime_regression client-agent.tests.test_mptcp_preconnect_runtime client-agent.tests.test_client_agent_mptcp_health_grace -v
```

Result:

- `Ran 37 tests ... OK`
- Expected warning logs from negative relay tests were present, but tests passed.

Current local hashes after this narrow patch:

- `client-agent/client_agent.py`: `aee07dde3330a9a245e569eba7cd2c6d6a9ba22fcce4d56601e2a3b843df2519`
- `client-agent/tests/test_highloss_self_leg_runtime_regression.py`: `2f574a1cc8068e09179b428eff53c0ad962410902cbbadf23c7845779d082312`

### What is working now

- UoT is no longer missing from sh36. The real sh36 runtime selected the slave UoT preconnect path and completed `iperf3 -R -u -b 300M` with exit success.
- The sidecar MPTCP/preconnect listener on `10.203.201.139:8898` is back to accepting connections, because the slave relay can refill warm TCP/UDP streams again.
- Warm pool size `2/2` is live on endpoint `499` and did not recreate the earlier resource exhaustion in this short proof window.
- The `client -> slave -> sidecar` topology is preserved.

### What failed / is still not good enough

- The first pool-bump attempt failed because sidecar overlay port `10.203.201.139:8898` was refusing connections. That was a real blocker at 16:43, but later checks show it recovered and warm pools refill again.
- The latest sh36 runtime still tries dead relay candidates before the good one because `slave_relays[]` is not filtered in the deployed client agent yet.
- One UDP run in `tmp_sh36_pool2_iperf_verify_20260524_181241.out` hit `the server is busy running a test. try again later`; the focused UDP rerun succeeded, so I treat that as a test sequencing/iperf harness issue, not as the decisive UoT failure.
- Sidecar log collection through the slave hop failed with `Permission denied` / script-BOM fallout in the narrow script. This did not block the runtime proof because the sidecar listener was proven indirectly by successful warm refill and traffic.
- UDP at `-b 300M` is functional but not performant yet:
  - latest receiver around `82.6 Mbit/s` with high loss;
  - prior successful runs varied around `39-87 Mbit/s` receiver with different loss levels.
  - So the logic path works, but throughput/loss tuning is not solved.

### Current concerns for technical discussion

1. **Runtime relay list ownership**
   - My current recommendation: panel may publish all candidate relay metadata, but the active runtime must filter `slave_relays[]` to the selected endpoints before rendering `/etc/leikwan/mptcp-forward-47.json`.
   - Otherwise every new runtime connection can pay timeout cost for inactive/dead helpers.

2. **Standby vs aggregate MPTCP semantics**
   - Current live config marks `lw-mptcp-47-0` as `backup_only: true` / `leg_role: standby` while `lw-cfwg-47` is primary.
   - That is good for smooth failover, but it may prevent real aggregate throughput contribution from the slave helper leg.
   - If the final goal is “both legs contribute throughput”, we probably need to revisit `backup_only` semantics after the relay-filter fix.

3. **What does `iperf3 -R -u -b 300M is working` mean?**
   - If it means process exits successfully and UoT path is selected: yes, that is now true.
   - If it means receiver gets close to 300 Mbit/s with low loss: not yet true; that is a separate performance tuning phase.

4. **Dead helper endpoints**
   - Endpoints `500`, `501`, and `564` timed out from sh36 on both TCP and UoT preconnect ports.
   - We can either:
     - exclude/quarantine them until their preconnect relays are fixed; or
     - leave them in the panel candidate pool but ensure the active runtime does not try them when they are not selected.
   - The local patch implements the second option.

5. **Deployment risk from dirty tree**
   - The repo has many pending changes and generated artifacts.
   - The narrow latest patch is only in `client-agent/client_agent.py` plus one test file, but deploying it still requires updating artifact metadata and syncing carefully so sh36 sees the new client-agent hash.

### Proposed deployment plan after discussion

If we agree with the relay-filter approach, the next safe deployment should be narrow:

1. Keep the just-added regression and `client_agent.py` patch.
2. Run a focused local verification bundle again:

```powershell
py -3 -m py_compile client-agent\client_agent.py client-agent\install_forward_runtime.py client-agent\leikwan_mptcp_client.py
py -3 -m unittest client-agent.tests.test_highloss_self_leg_runtime_regression client-agent.tests.test_mptcp_preconnect_runtime client-agent.tests.test_client_agent_mptcp_health_grace -v
```

3. Update `artifacts/versions.json` for the new `client_agent` hash/size only, unless another artifact is intentionally changed.
4. Sync only the required client-agent artifact and manifest to panel/public artifact paths.
5. Trigger sh36 client-agent update and restart/poll forward 47.
6. Verify sh36 rendered runtime now has only the selected relay in the active runtime path:

```json
"slave_relays": [
  { "endpoint_id": 499, "host": "10.203.201.138", "port": 7265, "uot_port": 7266 }
]
```

7. Re-run candidate probe from sh36:
   - expected: no attempts to `10.203.201.174`, `10.203.201.178`, or `10.203.201.182` during the data path.
8. Re-run warmth gate:
   - slave health `status: ok`;
   - `preconnected_tcp >= 2` and `preconnected_udp >= 2` after refill;
   - memory/FD headroom safe.
9. Re-run TCP and UDP proof:
   - TCP reverse iperf must pass.
   - `iperf3 -R -u -b 300M` must select `10.203.201.138:7266` immediately.
10. Only after that, decide whether to tune throughput/loss or change standby/backup-only behavior for true aggregate MPTCP contribution.

### Suggested discussion decision points

Before I continue deploying, I think we should decide these explicitly:

1. Should the active runtime filter `slave_relays[]` to selected endpoints, while keeping all candidates only as panel-side candidate data? My recommendation: **yes**.
2. Should `lw-mptcp-47-0` remain `backup_only/standby` for smooth failover, or should it actively contribute alongside `lw-cfwg-47` for aggregate throughput? This changes the MPTCP policy goal.
3. Is the immediate acceptance bar for UDP `300M` “UoT path works and iperf exits 0”, or “receiver reaches near 300 Mbit/s with low loss”? The second requires performance tuning after correctness.
4. Should dead relay endpoints `500/501/564` be fixed/excluded now, or deferred while endpoint `499` is the selected helper?


---

## Cleanup and ideal target update 2026-05-24 18:28 +08:00 local

### Temp cleanup performed

- Cleaned repo-root temporary investigation artifacts only.
- Deleted `552` repo-root temp artifacts matching bounded names such as `tmp_*`, `$tmp`, and equivalent root temp files.
- Did **not** delete tracked source, docs, credentials/environment files, `.git`, `.worktrees`, build source trees, or handoff markdowns.
- The important findings from the removed temp evidence files have been preserved in this markdown above. Future verification should use fresh commands/scripts rather than relying on deleted `tmp_*` files.

### User ideal hope / target end state

The desired end state is not merely that a single test exits successfully. The ideal system should behave like this:

1. **Same warm pre-establishment logic everywhere it matters**
   - Slave-backed MPTCP helper legs must use the same MPTCP pre-establishment concept as the client-as-a-slave path.
   - When the client selects a slave as an MPTCP leg, the slave-to-sidecar connection should already be warm, so the client does not wait for cold sidecar setup.
   - TCP and UoT/UDP should both benefit from this pre-established path.

2. **Smooth client experience**
   - The client should connect through the slave MPTCP leg without visible stalls, repeated timeout scans, or cold-path delay.
   - Runtime should try the actually selected healthy slave relay first, not stale relay candidates.
   - A selected helper like endpoint `499` should be used directly when it is the active healthy relay.

3. **Correct final structure**
   - Preserve the intended topology: `client sh36 -> slave/preconnect relay -> sidecar`.
   - Keep `lw-cfwg-47` as the CF-WG/client-as-a-slave path.
   - Keep `lw-mptcp-47-0` as the slave-backed MPTCP helper leg.
   - Keep slave ingress/preconnect ports inside the managed `7000-9000` range.
   - Do not introduce direct client-to-sidecar as the normal path.

4. **Correct final functions**
   - Panel can know/publish broad candidate metadata, but the active client runtime should render only the selected healthy relay set for live traffic.
   - The slave preconnect relay should maintain warm pools for both TCP and UoT/UDP.
   - Warm streams should be bounded, refilled, and stale-pruned so the earlier resource-exhaustion problem does not return.
   - `iperf3 -R -u -b 300M` should use the slave UoT preconnect path immediately. First acceptance is functional success; the higher ideal is low-loss/high-throughput performance after tuning.
   - If the final product goal is aggregate bandwidth, `lw-cfwg-47` and `lw-mptcp-47-0` must both be able to contribute. If the goal is smooth failover first, `lw-mptcp-47-0` may remain standby/backup-only. This needs an explicit decision.

5. **Operational quality bar**
   - No recurring SSH-hangs/resource exhaustion on slave nodes.
   - No update retry storms or CRLF installer failures.
   - No repeated attempts to dead helper relays during the active data path.
   - Fresh live verification should prove both structure and behavior after each deployment.

### Immediate discussion point after cleanup

The next technical decision is whether to deploy the local runtime relay-filter patch:

- Current recommendation: **yes**, filter `mptcp.slave_relays[]` in the active client runtime to selected healthy endpoint/node IDs.
- Reason: this matches the ideal smooth-client behavior by preventing timeout scans across inactive/dead slave relays.
- After that, we can separately decide whether the slave MPTCP leg should remain standby for failover or become active for aggregate throughput.
