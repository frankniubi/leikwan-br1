# 2026-05-17 Forward 47 Shanghai -> Tokyo Client Self-Leg / CF Bond Handover

> Local-only handover for the next engineer / AI. This file intentionally does **not** reprint passwords. Use the local environment files (`leikwan-v3.txt`, `shanghais.txt`) and the already-provided session credentials if authorized. Do not commit this handover or any temp scripts containing secrets without review.

## 0. Stop point / current answer

I stopped after one fresh local verification pass and one narrow live verification pass.

Current state is **not finished**:

- Local regression tests for the current client/panel/sidecar code passed.
- Live `forward 47` on `114.80.33.50` is up and carries traffic through the self-leg WireGuard sidecar path, but the path is poor and unstable.
- Latest live `iperf3 -R -b 300M` through `127.0.0.1:11035` completed with only about **8.66 Mbit/s receiver throughput**.
- The real self-leg WG interface `lw-self-47` exists and handshakes to Tokyo sidecar `185.220.238.143:14969`, but ping to the self-leg private sidecar address still showed **~56.9 ms average with 40% loss**.
- The agent is still refreshing/churning `forward 47` because runtime health checks report sidecar leg probe failures.
- The live rendered `forward 47` config is internally suspicious: it uses private self-leg `remote_host=10.204.53.42:8898`, but `direct_legs=[]`, `bond_mode=""`, and `relay_host=10.203.201.179:8898` still points at a rented-helper sidecar address.

The next best fix is not more broad tuning. Freeze the agent, isolate the private self-leg path, fix the health/render mismatch, then retest.

---

## 1. Scope and topology rules

The user explicitly narrowed scope:

- Ignore Canton / Guangzhou for this work.
- Focus Shanghai -> Tokyo direction.
- The old sidecar `161.248.136.183:14950` is no longer used.
- Current Tokyo sidecar in this thread is `185.220.238.143`.
- Sidecar access should stay through a Shanghai slave hop unless the user explicitly overrides it.
- `iperf3` tests should use `-b 300M` to avoid source VM bandwidth overuse.
- Linux client first is enough; Windows/Android parity can wait.

Forward in scope:

- Real client: `114.80.33.50`
- Forward: `47`
- Local listener: `127.0.0.1:11035`
- Target service: sidecar-local `127.0.0.1:5201` via the Tokyo sidecar relay path
- Current Tokyo sidecar: `185.220.238.143`
- Current private self-leg WG pair on live client:
  - client: `10.204.53.41/32` on `lw-self-47`
  - sidecar: `10.204.53.42/32`
  - sidecar public endpoint: `185.220.238.143:14969`
- Current selected rented-helper path in rendered config:
  - endpoint id `317`
  - Shanghai slave `47.116.104.15`
  - client WG address `10.203.201.177`
  - sidecar relay address `10.203.201.179`

---

## 2. Project structure relevant to this work

Important top-level areas in `C:\Users\frankniubi\Downloads\leikwan-br1`:

```text
master/
  src/Support/ProvisioningService.php
    Panel-side provisioning/publish logic. Builds forward payloads, helper groups,
    self-leg candidates, target sidecar tuple, and client self-leg WG contract.

  src/Controllers/*
    Panel artifact and agent/pull controllers. Some changed during this work,
    especially artifact publication consistency and sidecar/client update paths.

  tests/*.php
    PHP source-regression tests for provisioning payload contracts.

client-agent/
  client_agent.py
    Long-running Linux client agent. Pulls panel config, selects candidates,
    probes self-leg / helper paths, applies forwards, and runs health checks.

  install_forward_runtime.py
    Renderer/installer for Linux forward runtime files and systemd services:
    bond runtime, MPTCP runtime, WireGuard underlays, direct self-leg legs.

  leikwan_mptcp_client.py
    Legacy/python MPTCP client fallback and underlay setup helper used by start scripts.

  tests/*.py
    Main regression suite for client selection, self-leg, MPTCP/Bond render,
    health, installer, artifact versions.

sidecar-agent/
  sidecar_agent.py
    Sidecar management agent. Applies management payloads and sidecar runtime changes.

  leikwan_mptcp_sidecar.py
    Sidecar MPTCP runtime setup/helper logic.

  tests/*.py
    Sidecar CF direct backend, self-leg WG, managed iperf, CF-mode runtime tests.

slave-agent/
  slave_agent.py
    Slave ingress / backend reconciliation logic. Important when invalid bond frame
    magic comes from a slave serving the wrong backend protocol.

wstunnel-main/leikwan-mptcp-relay/
  src/client.rs
  src/server.rs
  src/bond.rs
  src/config.rs
    Rust relay used live on Linux. Handles MPTCP relay and bond direct/helper legs.

artifacts/
  versions.json
  wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64
    Published/runtime artifacts and version/hash metadata.

docs/superpowers/handoffs/
  Prior and current handoff files. Treat as local-only if they mention live hosts.
```

Worktree warning:

- The checkout is very dirty and contains many untracked temp scripts.
- Do **not** commit wholesale.
- Separate real source/test changes from temp files (`tmp-codex-*`, `run-tmp-*`, `.lscache`, local dumps, etc.).
- Several temp scripts contain live credentials; keep them local-only or remove before any commit.

---

## 3. What this implementation is trying to achieve

The intended end state is:

1. Panel publishes enough information for the client to decide locally.
2. The real Linux client can act as its own low-latency "self-leg" when that beats rented helpers.
3. For Shanghai -> Tokyo, the client should be able to use a direct/self-leg sidecar path instead of being forced through rented Shanghai helpers.
4. The same logic should be automatic for future forwards: panel publish -> client pull -> local candidate choice -> renderer -> stable runtime.
5. Rented helper group logic must stay available as fallback, not be deleted.
6. Forward 47 should carry stable traffic through the winning path, ideally approaching IEPL quality, with the user target around 35 ms from Shanghai client to Tokyo sidecar.

There are two related self-leg shapes in the code:

### A. Direct CF / WSTunnel self-leg

Panel publishes CF edge candidates and the client locally chooses the best edge:

- `self_leg_cf_candidates`
- `dial_port=2053`
- `cf_sni_host=nrt1.nbwly.com`
- route/path prefix
- backend target tuple, usually `127.0.0.1:8899` for direct WSTunnel backend

Renderer can create `direct_legs` and set `bond_mode="tcp"` so the Rust bond runtime prefers the direct/self leg and uses helper legs only when needed.

### B. Client self-leg WireGuard profile

Panel publishes a real WireGuard self-leg profile for the client:

- mode `client_self_leg`
- client private address `10.204.53.41`
- sidecar private address `10.204.53.42`
- sidecar public endpoint `185.220.238.143:14969`

Renderer brings up `lw-self-47` and can point the MPTCP relay directly at `10.204.53.42:8898`.

The live system currently ended up in shape B, not the desired CF Bond direct-leg shape.

---

## 4. Main source changes already present locally

These are local dirty-worktree changes; verify before committing.

### 4.1 Client agent selection/probing

File: `client-agent/client_agent.py`

Fresh line anchors from current checkout:

- `AGENT_VERSION = "2026.05.17.3"` at line 24.
- `select_forward_candidates(...)` at line 2210.
- `resolve_self_leg_connect_ip_with_source(...)` at line 2349.
- `resolve_self_leg_connect_ip(...)` at line 2396.
- `expand_self_leg_cf_candidate_ips(...)` at line 2433.
- `self_leg_candidate_edge_latency_ms(...)` at line 2475.
- `probe_self_leg_websocket_path(...)` at line 2696.
- `self_leg_direct_cf_connect_probe(...)` at line 2819.

What changed conceptually:

- Self-leg candidate set expansion is local to the client.
- Probe validity and edge-latency ranking are separated.
- Panel-published candidate IPs and CIDRs can be expanded/probed locally.
- Forward-specific best-IP override/cache is supported.
- Same-agent stale in-memory self-leg probe behavior has regression coverage.
- Self-leg direct CF connect probe can use the real WSTunnel path and bond-token validation.
- Self-leg primary can be selected when measured lower latency than rented candidates.

### 4.2 Client runtime renderer

File: `client-agent/install_forward_runtime.py`

Fresh line anchors from current checkout:

- `selected_self_leg_candidates(...)` at line 273.
- `self_leg_endpoint(...)` at line 289.
- `selected_mptcp_self_leg_candidates(...)` at line 322.
- `selected_private_wg_self_leg_candidates(...)` at line 338.
- `self_leg_wg_underlay(...)` at line 355.
- `mptcp_self_leg_endpoint(...)` at line 389.
- direct-leg render path starts around lines 785-806.
- `bond_mode` becomes `"tcp"` when `direct_legs` exist around line 835.
- private self-leg underlays are inserted into the MPTCP config around lines 852-858.

What changed conceptually:

- Can render direct CF self-leg into `direct_legs` for CF Bond mode.
- Can render client self-leg WireGuard underlay (`client_self_leg`) and bring up `lw-self-47`.
- Can keep rented helper endpoints alongside self-leg paths.
- Can derive target sidecar port from explicit panel fields.
- Has regression coverage for private WG profile rendering and stale self-leg cleanup.

### 4.3 Panel provisioning

File: `master/src/Support/ProvisioningService.php`

Fresh line anchors from current checkout:

- helper group fields around lines 388, 429, 2503, 2539-2544.
- `publishedSelfLegCfCandidates(...)` at line 2568.
- self-leg CF target tuple around lines 2658-2703.
- self-leg candidate-set copy around lines 2742-2751.
- `client_self_leg` payload around line 2793 and again around line 6084.

What changed conceptually:

- Panel can publish `self_leg_cf_candidates` instead of collapsing to one IP.
- Panel publishes `dial_port`, `cf_sni_host`, `target_sidecar_port`, `target_sidecar_bond_port`, `target_sidecar_mptcp_port`.
- Panel preserves helper group metadata (`origin_group_key`, `origin_group_order`, `helper_group_cap`).
- Panel can publish a client self-leg WG contract.

### 4.4 Sidecar/slave/runtime changes

Changed files include:

- `sidecar-agent/sidecar_agent.py`
- `sidecar-agent/leikwan_mptcp_sidecar.py`
- `slave-agent/slave_agent.py`
- `wstunnel-main/leikwan-mptcp-relay/src/bond.rs`
- `wstunnel-main/leikwan-mptcp-relay/src/client.rs`
- `wstunnel-main/leikwan-mptcp-relay/src/config.rs`
- `wstunnel-main/leikwan-mptcp-relay/src/server.rs`

Important concepts in these changes:

- Sidecar can apply self-leg WG peer config.
- Sidecar CF route can target direct backend without forcing a local WSTunnel backend.
- Managed iperf3 support exists for sidecar-side test service.
- Rust bond code has support for `direct_legs` and prioritizing direct/self legs before helper legs.
- Slave-agent backend reconciliation was touched; this matters for `invalid bond frame magic` class bugs when a slave backend serves WSTunnel/plain TCP instead of the expected bond/ingress protocol.

---

## 5. Fresh verification run before this handoff

### 5.1 Local Python client tests

Command run from repo root:

```powershell
$env:PYTHONPATH=(Get-Location).Path
python -m unittest `
  client-agent.tests.test_self_leg_best_ip_override `
  client-agent.tests.test_highloss_self_leg_runtime_regression `
  client-agent.tests.test_runtime_preference_mptcp_bond_fallback `
  client-agent.tests.test_self_leg_candidate_policy `
  client-agent.tests.test_self_leg_direct_cf_runtime_shape `
  client-agent.tests.test_mptcp_bond_regressions `
  client-agent.tests.test_mptcp_setup_and_cleanup_regressions `
  client-agent.tests.test_client_agent_bond_endpoint_health `
  client-agent.tests.test_client_agent_installer_helper `
  -v
```

Result:

```text
Ran 132 tests in 7.678s
OK
```

Notes:

- Some expected warnings about missing local Windows-side Linux runtime binaries appeared in tests, but all tests passed.
- The suite includes the new/important self-leg tests:
  - same-agent stale probe cache fix
  - candidate set expansion and ranking
  - bond-token based self-leg probe
  - late websocket 520 rejection
  - selected self-leg carries resolved connect IP into renderer
  - private WG self-leg profile render
  - self-leg-selected CF bond health behavior

### 5.2 Local PHP provisioning tests

Command run:

```powershell
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
php master/tests/provisioning_self_leg_best_ip_contract_regression_test.php
php master/tests/provisioning_self_leg_target_contract_regression_test.php
php master/tests/provisioning_self_leg_wg_contract_regression_test.php
```

Result:

```text
ok
ok
ok
ok
ok
```

Notes:

- PHP emitted environment warnings: `Invalid date.timezone value 'UTC+8', using 'UTC' instead`.
- These warnings did not fail the tests.

### 5.3 Local sidecar tests

Command run:

```powershell
$env:PYTHONPATH=(Get-Location).Path
python -m unittest sidecar-agent.tests.test_sidecar_cf_direct_backend sidecar-agent.tests.test_sidecar_self_leg_wg sidecar-agent.tests.test_sidecar_cf_mode_runtime sidecar-agent.tests.test_sidecar_managed_iperf3 -v
```

Result:

```text
Ran 6 tests in 1.742s
OK
```

Notes:

- Windows printed `systemctl is not recognized` during sidecar runtime tests. The tests still passed.
- One test attempted a dummy download from `https://panel.example.com/...` and logged SSL EOF, but the regression still passed.

### 5.4 Artifact version consistency

Command run:

```powershell
$env:PYTHONPATH=(Get-Location).Path
python -m unittest client-agent.tests.test_artifact_versions_consistency -v
```

Result:

```text
Ran 1 test in 0.007s
OK
```

---

## 6. Fresh live verification before this handoff

### 6.1 Live state snapshot

Command was executed through WSL using SSH to `root@114.80.33.50`.

Fresh live facts:

```text
agent=active/enabled
f47=active
listener: 127.0.0.1:11035
AGENT_VERSION = "2026.05.17.3"
client_agent.py sha256 = 5632d7e673c944911a0045d1639d63c161bec03387209e9649a8e95fad622fb9
install_forward_runtime.py sha256 = d0a711b5da4e2711180c3e5ef89e91c9991b7f6a3e011d99e5cc96788d6656f9
```

Current `/etc/leikwan/mptcp-forward-47.json` summary from the live client:

```json
{
  "bond_mode": "",
  "remote_host": "10.204.53.42",
  "remote_port": 8898,
  "relay_host": "10.203.201.179",
  "relay_port": 8898,
  "subflow_limit": 1,
  "policy_mode": "latency_first",
  "direct_legs": []
}
```

Rendered endpoint summary:

```text
endpoint 317 -> 47.116.104.15:7000, role=primary, health=healthy
```

Rendered underlay summary:

```text
client_self_leg lw-self-47:
  sidecar public endpoint: 185.220.238.143:14969
  client private: 10.204.53.41
  sidecar private: 10.204.53.42

slave_transit lw-mptcp-47-0:
  Shanghai slave: 47.116.104.15:7329
  client private: 10.203.201.177
  sidecar private: 10.203.201.179
```

### 6.2 Live traffic test

Command run on the real client:

```bash
iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 12 --connect-timeout 3000
```

Result:

```text
iperf_rc=0
receiver throughput: 12.4 MBytes over 12.00 sec = 8.66 Mbits/sec
sender throughput:   23.7 MBytes over 12.48 sec = 15.9 Mbits/sec
sender retransmits: 1034
```

Interpretation:

- The path carries traffic.
- It is nowhere near the desired quality.
- Throughput is far below earlier helper-path expectations and far below the user's IEPL-quality target.

Live `leikwan-client-47.service` logs during the test:

```text
client relay listening on 127.0.0.1:11035
accepted local connection
connected MPTCP to 10.204.53.42:8898
sent handshake (295 bytes)
```

This proves the tested traffic used the private self-leg sidecar target `10.204.53.42:8898`, not only the rented helper address.

### 6.3 Live self-leg WG latency/loss snapshot

Command run on the real client:

```bash
wg show lw-self-47
ping -c 10 -i 0.2 -W 1 10.204.53.42
ping -c 10 -i 0.2 -W 1 10.203.201.179
```

Result for `lw-self-47`:

```text
interface: lw-self-47
  client address: 10.204.53.41/32
  peer endpoint: 185.220.238.143:14969
  latest handshake: 7 seconds ago
```

Self-leg private sidecar ping:

```text
10 packets transmitted, 6 received, 40% packet loss
rtt min/avg/max/mdev = 55.852/56.875/58.420/0.909 ms
```

Rented-helper sidecar private ping:

```text
10 packets transmitted, 7 received, 30% packet loss
rtt min/avg/max/mdev = 70.904/76.929/84.824/5.116 ms
```

Interpretation:

- Self-leg WG is lower latency than selected helper WG in this snapshot.
- Both paths have significant packet loss.
- Self-leg is still not close to the 35 ms target.
- The poor throughput is consistent with loss/retransmits and/or backend/health churn.

### 6.4 Live health loop / churn evidence

Recent agent logs repeatedly showed this pattern:

```text
applying forward 47 mode=mptcp transport=tcp endpoints=6 target=185.220.238.143:5201
applied forward 47 service=leikwan-client-47 unit_changed=False script_changed=False
refreshed leikwan-client-47 to apply mptcp leg health policy
WARNING forward 47 has a live local mptcp listener but all sidecar leg tcp probes failed; isolating failed legs and refreshing runtime instead of restarting
```

Interpretation:

- Runtime is active but not stable.
- The agent is repeatedly refreshing F47 due to health probe failures.
- This can invalidate iperf measurements and may keep changing selected legs/state during testing.
- The next person should freeze the agent before manual transport tests.

---

## 7. What failed / what remains broken

### 7.1 Goal not met

The requested end state is not proven. Specifically:

- No stable IEPL-like path.
- No 35 ms Shanghai client -> Tokyo sidecar proof.
- No stable high-throughput F47 data plane.
- No proof that the final automatic path is correct for future forwards.

### 7.2 Current live render is suspicious

Live F47 config is a mixed state:

```text
remote_host=10.204.53.42:8898      # private self-leg sidecar
relay_host=10.203.201.179:8898     # helper-side sidecar address
direct_legs=[]                     # no CF Bond direct leg rendered
bond_mode=""                       # not CF Bond mode
endpoints=[317 only]                # one rented helper endpoint still primary in endpoint list
underlays=[client_self_leg, slave_transit]
```

This may be intentional for private WG self-leg MPTCP, but it is not the user's requested "Shanghai - Tokyo via CF Bond mode only" shape. It also creates confusing health behavior because endpoint health is still based on rented helper endpoints while the real MPTCP remote target is the private self-leg address.

### 7.3 Health checks are probably applying the wrong model

A likely root cause for continued churn:

- When private WG self-leg is the selected route, the agent still evaluates helper sidecar leg probes and refreshes because rented helper probes fail.
- Existing test coverage includes a CF-bond self-leg case, but the live failing shape is private WG + MPTCP, not CF-bond `direct_legs`.

Suggested regression to add next:

> When a private WG self-leg candidate is selected and `remote_host` points to the self-leg sidecar private address, failed slave-helper sidecar probes must not trigger repeated refresh/restart of the forward. Helper failures should be quarantined or left as fallback health, not treated as primary path failure.

### 7.4 Data plane still weak

Live iperf:

- `8.66 Mbit/s` receiver with `-R -b 300M -t 12`
- `1034` retransmits on sender
- self-leg WG ping has `40%` loss

Likely causes to isolate:

1. Self-leg WG underlay itself is lossy (`185.220.238.143:14969` path).
2. Sidecar MPTCP backend at `10.204.53.42:8898` is unstable or overloaded.
3. Token/target/backend path to `127.0.0.1:5201` works but is slow because of sidecar-side bottleneck.
4. Agent refresh churn interrupts/perturbs measurements.
5. The Rust relay config is using private self-leg remote target but still has helper relay metadata that confuses health/selection.

### 7.5 Invalid bond frame magic issue

I did not see fresh `invalid bond frame magic` in the final live MPTCP/WG test logs.

Do not assume it is permanently solved. If it reappears in CF Bond mode, first check:

- Whether the Shanghai slave backend for F47 is actually `leikwan-ingress backend` / plain TCP mux as expected.
- Whether slave-agent reconciliation drifted back to WSTunnel server mode.
- Whether the client is connecting a bond protocol leg to a non-bond/non-ingress backend.
- Whether `backend_protocol=tcp_relay` is preserved in panel payload and slave-agent render state.

This is a known failure class from earlier work: repeated `invalid bond frame magic` can be caused by backend protocol drift, not by client bond flags.

---

## 8. Recommended next-person plan

### Step 1: Freeze the live agent before transport experiments

The agent is enabled/active and churns F47. For manual testing, freeze it first:

```bash
systemctl disable --now leikwan-client-agent.service
systemctl status leikwan-client-agent.service --no-pager -l
```

Record the original state first (`active/enabled` at this handoff) and re-enable it when leaving the box unless intentionally leaving it frozen:

```bash
systemctl enable --now leikwan-client-agent.service
```

### Step 2: Snapshot current runtime cleanly

On `114.80.33.50`:

```bash
date -Is
systemctl is-active leikwan-client-47.service
ss -ltnp | grep -E ':(11035)\b'
jq . /etc/leikwan/mptcp-forward-47.json
wg show lw-self-47
ip -4 addr show dev lw-self-47
journalctl -u leikwan-client-47.service -n 200 --no-pager -o short-iso
```

### Step 3: Decide which architecture to finish first

The live system currently uses private WG self-leg MPTCP. The user asked for Shanghai -> Tokyo via CF Bond mode only, but also asked for client-as-a-slave first if it is faster.

Pick one narrow target for the next patch:

#### Option A: finish private WG self-leg MPTCP first

Minimum fix:

- Keep `lw-self-47`.
- Keep `remote_host=10.204.53.42`, `remote_port=8898`.
- Stop helper-side probe failures from refreshing/restarting a selected private self-leg route.
- Measure self-leg WG loss and sidecar backend independently.

This is the current live shape and likely fastest to stabilize.

#### Option B: force actual CF Bond direct-leg shape

Minimum fix:

- Render `direct_legs` non-empty.
- Set `bond_mode="tcp"`.
- Ensure direct leg local WSTunnel targets sidecar backend `127.0.0.1:8899` with SNI `nrt1.nbwly.com`, port `2053`, correct path prefix, and winning CF edge.
- Keep helper legs only as fallback.
- Verify no `invalid bond frame magic` by checking slave backend mode first.

This matches the user's CF Bond wording better, but is a wider change than stabilizing the current live WG path.

### Step 4: Sidecar inspection through Shanghai hop

Use a Shanghai slave hop, not direct sidecar access unless the user explicitly allows direct.

On the sidecar, inspect:

```bash
hostname; date -Is
ss -ltnup | grep -E ':(8898|8899|14969|24969|2053|5201|5202)\b'
systemctl --type=service --state=running | grep -Ei 'leikwan|wstunnel|iperf|wg'
wg show
ip -4 addr
journalctl -u leikwan-sidecar-agent.service -n 200 --no-pager -o short-iso
journalctl -u 'leikwan*' -n 200 --no-pager -o short-iso
```

Key things to prove:

- `:14969` WireGuard endpoint is healthy and not rate-limited.
- `:8898` MPTCP backend accepts client handshakes and reaches `127.0.0.1:5201`.
- `:8899` direct CF backend, if used, maps to the correct target and token path.
- `iperf3 -s -p 5201` is actually running and stable.
- Sidecar firewall is not dropping most packets on the self-leg WG path.

### Step 5: Add the missing regression before patching health logic

Likely test location:

- `client-agent/tests/test_client_agent_bond_endpoint_health.py`
- or `client-agent/tests/test_highloss_self_leg_runtime_regression.py`

Test contract:

1. Construct a forward where selected candidate is private WG self-leg.
2. Rendered config remote points at self-leg private sidecar address.
3. Helper sidecar probes fail.
4. `ensure_runtime_health` should not restart/refresh the selected private self-leg route solely because helpers fail.
5. Helper endpoints may be quarantined, but primary self-leg route should stay stable.

Then patch `client-agent/client_agent.py` health/restart logic narrowly.

### Step 6: Retest with the required iperf cap

After freezing agent and applying the narrow patch:

```bash
systemctl restart leikwan-client-47.service
sleep 3
iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 15 --connect-timeout 3000
journalctl -u leikwan-client-47.service --since '-2 min' --no-pager -o short-iso | tail -n 120
```

Target for declaring progress:

- service stays active without repeated refresh loops,
- listener remains at `127.0.0.1:11035`,
- no invalid magic / 520 / failed handshake spam,
- throughput materially improves over `8.66 Mbit/s`,
- self-leg ping loss drops significantly,
- latency moves toward the user's `35 ms` target.

---

## 9. Files/scripts created during final verification

Local untracked helper scripts from this final handoff turn:

- `tmp-codex-handoff-live-test-0517.lf.sh`
- `tmp-codex-handoff-final-snapshot-0517.lf.sh`

They contain live SSH credentials in clear text because they were made for local testing. Do not commit them. Remove or redact before any cleanup commit.

Other many `tmp-codex-*` and `run-tmp-*` scripts existed before this final turn. Treat them as local scratch evidence, not production code.

---

## 10. Concise command log from this handoff turn

Local tests that passed:

```text
python unittest client self-leg/MPTCP/health/installer subset: 132 tests OK
php provisioning self-leg/helper/WG contract tests: 5 ok
python sidecar direct/self-leg/managed-iperf subset: 6 tests OK
python artifact version consistency: 1 test OK
```

Live tests that ran:

```text
client host: 114.80.33.50
agent: active/enabled
f47: active
listener: 127.0.0.1:11035
live path during iperf: connected MPTCP to 10.204.53.42:8898
iperf: iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 12 --connect-timeout 3000
iperf result: rc=0, receiver 8.66 Mbit/s, sender 15.9 Mbit/s, retransmits 1034
self-leg ping: 10.204.53.42 avg 56.875 ms, 40% loss
helper ping: 10.203.201.179 avg 76.929 ms, 30% loss
```

---

## 11. Final status for next handoff

The codebase has enough local support for self-leg candidate publication, local client selection, direct CF self-leg rendering, and private WG self-leg rendering. The final tested live system, however, is only a partial success:

- Control plane: mostly there.
- Runtime render: present but mixed/suspicious.
- Data plane: carries traffic but badly.
- Stability: not good because agent health refresh churn remains.
- Automation: not proven for new forwards.

Do not repeat broad all-day tuning. The next person should take the narrow path:

1. Freeze agent.
2. Prove private self-leg WG and sidecar backend separately.
3. Add the missing health regression for selected private self-leg.
4. Patch health/render semantics minimally.
5. Retest `iperf3 -R -b 300M` and latency.
6. Only after live stability, decide whether to convert the same logic back to CF Bond `direct_legs` for the user's preferred final shape.
