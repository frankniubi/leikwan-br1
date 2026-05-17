# Shanghai Tokyo Self-Leg MPTCP Landing Leg Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Shanghai -> Tokyo path prefer a client-owned direct Cloudflare self-leg as the first MPTCP landing leg when it is faster than rented Shanghai slaves, while preserving normal `client -> slave -> sidecar` topology for rented helpers, keeping Canton untouched, and reducing loss by fixing stale/failed MPTCP underlay handling.

**Architecture:** Keep control-plane selection in `master/src/Support/ProvisioningService.php`, where the Tokyo forward combination emits a candidate pool containing rented helper legs plus one synthetic per-device self-leg that targets the sidecar through approved Cloudflare upstream realizations. On the client, keep `mode = mptcp` when the self-leg advertises MPTCP capability, but inject the self-leg as a direct `wstunnel` landing leg with highest priority while leaving rented helpers as normal MPTCP/WireGuard transit underlays; harden the local MPTCP helper so one bad/stale transit leg cannot restart-loop the whole forward.

**Tech Stack:** PHP panel/provisioning tests, Python client runtime/tests, systemd/WireGuard/wstunnel runtime generation, WSL-based SSH deployment using repo-local credentials in `leikwan-v3.txt` and `shanghais.txt`.

---

## File Structure

### Control plane / provisioning

- Modify: `master/src/Support/ProvisioningService.php`
  - ensure candidate-pool payload includes a direct-CF self-leg for Tokyo combinations
  - ensure self-leg carries approved CF upstream identity, sidecar CF listen target, and MPTCP capability
  - ensure `buildMptcpClientConfig()` always carries `endpoint_id` in `mptcp_endpoints`
  - ensure helper/slave MPTCP public ports stay in `7000-9000`
- Modify: `master/src/Controllers/NodeController.php`
  - keep heartbeat compatibility if current payload changes depend on it
- Modify: `master/src/Controllers/SidecarAgentController.php`
  - keep heartbeat/runtime payload compatibility for sidecar CF fields if needed
- Test: `master/tests/provisioning_self_leg_payload_regression_test.php`
- Test: `master/tests/provisioning_mptcp_slave_port_range_regression_test.php`
- Test: `master/tests/provisioning_runtime_preference_regression_test.php`

### Client selection / runtime rendering

- Modify: `client-agent/client_agent.py`
  - choose `mptcp` when self-leg is selected primary and advertises `mptcp`
  - rank self-leg ahead of rented helpers when direct CF RTT is lower
  - keep helper escalation logic for high-latency self-leg cases
  - preserve endpoint/underlay ordering after health-based resorting
- Modify: `client-agent/install_forward_runtime.py`
  - render direct-CF self-leg as the first landing leg for MPTCP runtime
  - keep rented slave legs as regular MPTCP underlays
  - never route rented helper traffic directly to sidecar
- Test: `client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py`
- Test: `client-agent/tests/test_highloss_self_leg_runtime_regression.py`
- Test: `client-agent/tests/test_mptcp_bond_regressions.py`

### MPTCP helper / cleanup

- Modify: `client-agent/leikwan_mptcp_client.py`
  - keep failed WG underlays non-fatal
  - remove stale `lw-mptcp-*` interfaces and stale `leikwan-uot-*` units for the same forward only
  - preserve active underlays on no-op reapply
- Test: `client-agent/tests/test_mptcp_setup_and_cleanup_regressions.py`

### Live deployment / verification

- Use local credential sources only: `leikwan-v3.txt`, `shanghais.txt`
- Use WSL SSH hop flow only
- Tokyo direction only: panel combo and Shanghai client/slaves as needed
- Do not edit Canton combination/slaves

---

### Task 1: Freeze the failing behavior into regression tests and confirm the current baseline

**Files:**
- Modify: `client-agent/tests/test_highloss_self_leg_runtime_regression.py`
- Modify: `client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py`
- Modify: `master/tests/provisioning_self_leg_payload_regression_test.php`
- Modify: `master/tests/provisioning_mptcp_slave_port_range_regression_test.php`

- [ ] **Step 1: Add a regression that requires panel MPTCP payloads to carry `endpoint_id`**

In `client-agent/tests/test_highloss_self_leg_runtime_regression.py`, keep or add:

```python
    def test_panel_mptcp_endpoint_payload_carries_endpoint_id_for_health_remap(self) -> None:
        panel_php = (REPO_ROOT / "master/src/Support/ProvisioningService.php").read_text(encoding="utf-8")
        self.assertIn("'endpoint_id' => (int) ($leg['endpoint_id'] ?? 0)", panel_php)
```

- [ ] **Step 2: Add a regression that self-leg primary may still keep the runtime in MPTCP family**

In `client-agent/tests/test_highloss_self_leg_runtime_regression.py`, keep or add:

```python
    def test_runtime_preference_keeps_mptcp_when_self_leg_can_be_direct_mptcp(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = agent.choose_runtime_family(
            {
                "mode": "mptcp",
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                        "probe_transport": "cf_direct_wss",
                        "dial_host": "162.159.39.16",
                        "dial_port": 2053,
                        "cf_sni_host": "nrt1.nbwly.com",
                        "path_prefix": "tokyo-path",
                    },
                    {"candidate_kind": "rented_group_leg", "endpoint_id": 320, "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )

        self.assertEqual("mptcp", family)
```

- [ ] **Step 3: Keep the self-leg payload regression strict about direct Cloudflare fields**

In `master/tests/provisioning_self_leg_payload_regression_test.php`, keep or add the exact snippet checks:

```php
foreach ([
    'probe_transport',
    'cf_direct_wss',
    'dial_host',
    'dial_port',
    'cf_sni_host',
    'path_prefix',
    'target_sidecar_host',
    'target_sidecar_port',
    'cf_listen_port',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing self-leg payload snippet: ' . $snippet);
    }
}
```

- [ ] **Step 4: Keep the slave MPTCP port-range regression strict**

In `master/tests/provisioning_mptcp_slave_port_range_regression_test.php`, keep the checks that require:

```php
foreach (['mptcpSlavePortRangeStart', 'mptcpSlavePortRangeEnd', 'stableMptcpSlavePort'] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing port-range helper snippet: ' . $snippet);
    }
}
```

and continue rejecting the old unbounded port formula.

- [ ] **Step 5: Run the focused baseline regressions**

Run:

```bash
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_mptcp_slave_port_range_regression_test.php
python -m unittest client-agent.tests.test_highloss_self_leg_runtime_regression
python -m unittest client-agent.tests.test_runtime_preference_mptcp_bond_fallback
```

Expected:

- both PHP tests print `ok`
- Python high-loss suite: `Ran 11 tests ... OK`
- Python runtime-preference suite: `Ran 3 tests ... OK`

- [ ] **Step 6: Commit the baseline test guardrails**

```bash
git add master/tests/provisioning_self_leg_payload_regression_test.php master/tests/provisioning_mptcp_slave_port_range_regression_test.php client-agent/tests/test_highloss_self_leg_runtime_regression.py client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py
git commit -m "test: lock self-leg mptcp and slave-port regression coverage"
```

### Task 2: Finish panel/provisioning output for Tokyo self-leg + endpoint identity

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Test: `master/tests/provisioning_self_leg_payload_regression_test.php`
- Test: `client-agent/tests/test_highloss_self_leg_runtime_regression.py`

- [ ] **Step 1: Ensure `buildForwardCandidatePool()` emits rented helpers with stable endpoint identity**

In `master/src/Support/ProvisioningService.php`, keep the rented helper payload shape as:

```php
$pool[] = [
    'candidate_kind' => 'rented_group_leg',
    'endpoint_id' => (int) ($endpoint['endpoint_id'] ?? 0),
    'origin_group_key' => $originGroupKey,
    'origin_group_order' => $this->helperGroupOrderForConfig($config, $originGroupKey),
    'runtime_capabilities' => ['bond', 'mptcp'],
    'host' => (string) ($endpoint['host'] ?? ''),
    'port' => (int) ($endpoint['port'] ?? 0),
];
```

- [ ] **Step 2: Ensure `buildSelfLegCandidate()` advertises MPTCP capability and direct CF fields**

In `master/src/Support/ProvisioningService.php`, keep or update the self-leg payload to include:

```php
return [
    'candidate_kind' => 'self_leg',
    'session_id' => (int) ($deviceSession['id'] ?? 0),
    'device_id' => $deviceId,
    'runtime_capabilities' => ['bond', 'mptcp'],
    'self_leg_scope' => 'device_session',
    'self_leg_role' => 'latency_anchor',
    'probe_transport' => 'cf_direct_wss',
    'dial_host' => $cfTarget['dial_host'],
    'dial_port' => $cfTarget['dial_port'],
    'cf_sni_host' => $cfTarget['cf_sni_host'],
    'path_prefix' => (string) ($config['path_prefix'] ?? ''),
    'target_sidecar_host' => $cfTarget['target_sidecar_host'],
    'target_sidecar_port' => $cfTarget['target_sidecar_port'],
    'target_sidecar_bond_port' => (int) ($config['bond_sidecar_port'] ?? $config['bond_remote_port'] ?? 0),
];
```

- [ ] **Step 3: Make `selfLegCfTarget()` prefer approved CF upstream realizations, not private MPTCP sidecar ports**

In `master/src/Support/ProvisioningService.php`, keep the selection order:

```php
$approved = $config['bond_sidecar_approved_upstream_realizations'] ?? [];
```

and return a target built from:

```php
'dial_host' => $dialHost,
'dial_port' => $dialPort,
'cf_sni_host' => $sniHost !== '' ? $sniHost : $dialHost,
'target_sidecar_host' => trim((string) ($config['bond_sidecar_host'] ?? $config['bond_remote_host'] ?? '')),
'target_sidecar_port' => (int) ($config['bond_sidecar_cf_listen_port'] ?? $dialPort),
```

Do **not** fall back to:

```php
'"dial_host" => (string) ($config['bond_remote_host'] ?? '')'
'"dial_port" => (int) ($config['bond_remote_port'] ?? 0)'
```

for the direct self-leg path.

- [ ] **Step 4: Ensure `buildClientConfig()` exposes CF-sidecar fields to the client payload**

In `master/src/Support/ProvisioningService.php`, keep these keys in the returned config:

```php
'bond_sidecar_cf_listen_port' => (int) ($forward['bond_sidecar_cf_listen_port'] ?? 0) > 0 ? (int) ($forward['bond_sidecar_cf_listen_port'] ?? 0) : null,
'bond_sidecar_cf_sni_host' => (string) ($pointConfig['cf_sni_host'] ?? $pointConfig['cf_sni'] ?? ''),
'bond_sidecar_approved_upstream_realizations' => $this->approvedCfUpstreamRealizations($pointConfig),
```

- [ ] **Step 5: Ensure `buildMptcpClientConfig()` carries endpoint ids in `mptcp_endpoints`**

In `master/src/Support/ProvisioningService.php`, keep the mapping as:

```php
'mptcp_endpoints' => array_values(array_map(function ($leg, $idx) use ($forward) {
    return [
        'endpoint_id' => (int) ($leg['endpoint_id'] ?? 0),
        'address' => $leg['local_address'],
        'dev' => 'lw-mptcp-' . (int) ($forward['id'] ?? 0) . '-' . $idx,
        'subflow' => true,
        'signal' => false,
        'backup_only' => $leg['backup_only'] ?? false,
    ];
}, $underlayLegs, array_keys($underlayLegs))),
```

- [ ] **Step 6: Run the provisioning and payload regressions**

Run:

```bash
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_runtime_preference_regression_test.php
python -m unittest client-agent.tests.test_highloss_self_leg_runtime_regression
```

Expected:

- PHP tests print `ok`
- Python suite prints `Ran 11 tests ... OK`

- [ ] **Step 7: Commit the provisioning fixes**

```bash
git add master/src/Support/ProvisioningService.php master/tests/provisioning_self_leg_payload_regression_test.php master/tests/provisioning_runtime_preference_regression_test.php client-agent/tests/test_highloss_self_leg_runtime_regression.py
git commit -m "feat: emit self-leg direct-cf mptcp candidate payloads"
```

### Task 3: Keep MPTCP family when self-leg wins, and make it the first landing leg

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/install_forward_runtime.py`
- Test: `client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py`
- Test: `client-agent/tests/test_highloss_self_leg_runtime_regression.py`

- [ ] **Step 1: Keep `choose_runtime_family()` on MPTCP when self-leg primary supports it**

In `client-agent/client_agent.py`, keep the self-leg branch as:

```python
        if isinstance(primary, dict) and str(primary.get("candidate_kind", "") or "") == "self_leg":
            primary_caps = [str(value).strip().lower() for value in primary.get("runtime_capabilities", [])]
            if mptcp_supported and "mptcp" in primary_caps:
                return "mptcp"
            return "bond"
```

- [ ] **Step 2: Keep `select_forward_candidates()` self-leg-first when direct CF RTT is lower**

In `client-agent/client_agent.py`, keep the candidate sort as:

```python
        ready.sort(
            key=lambda item: (
                float(item.get("measured_rtt_ms", 999999.0) or 999999.0),
                0 if str(item.get("candidate_kind", "") or "") == "self_leg" else 1,
                int(item.get("origin_group_order", 999999) or 999999),
                int(item.get("endpoint_id", 0) or 0),
            )
        )
```

and mark only the first candidate as primary.

- [ ] **Step 3: Keep helper escalation available when self-leg misses desired latency**

In `client-agent/client_agent.py`, keep `apply_latency_target_candidate_escalation()` behavior that only activates rented helpers when:

```python
if str(primary.get("candidate_kind", "") or "") != "self_leg":
    next_forward["candidate_pool"] = pool
    return next_forward
```

and the measured self-leg RTT is above `desired_latency_ms`.

- [ ] **Step 4: Render the self-leg as the first MPTCP landing leg**

In `client-agent/install_forward_runtime.py`, keep direct self-leg extraction as:

```python
direct_self_leg_endpoints = [
    endpoint
    for index, candidate in enumerate(selected_mptcp_self_leg_candidates(forward))
    for endpoint in [mptcp_self_leg_endpoint(candidate, forward, index + 1)]
    if endpoint is not None
]
```

then convert it into `direct_legs` with lower priority numbers than rented legs.

- [ ] **Step 5: Force the MPTCP runtime to land on the local wstunnel port when self-leg exists**

In `client-agent/install_forward_runtime.py`, keep:

```python
        if direct_legs:
            remote_host = "127.0.0.1"
            remote_port = int(direct_legs[0]["local_port"])
```

and the MPTCP config payload fields:

```python
"bond_mode": "tcp" if direct_legs else str(mptcp.get("bond_mode", "") or ""),
"direct_legs": direct_legs,
"relay_host": sidecar_relay_host,
"relay_port": sidecar_relay_port,
```

- [ ] **Step 6: Start the local direct-CF wstunnel before the MPTCP helper**

In `client-agent/install_forward_runtime.py`, keep the start-script flow:

```python
for index, endpoint in enumerate(direct_self_leg_endpoints):
    append_wstunnel_direct_leg(
        lines,
        endpoint,
        direct_leg_base + index,
        f"127.0.0.1:{sidecar_relay_port if sidecar_relay_port > 0 else endpoint.get('target_sidecar_port', 0)}",
        sni,
        path_prefix,
    )
if direct_legs:
    append_wait_for_local_listener(lines, [int(leg["local_port"]) for leg in direct_legs])
lines.append(f"/usr/bin/python3 {helper_path} --config {config_path} --setup-underlays || echo \"mptcp setup-underlays returned $?; continuing to runtime listener\" >&2")
```

- [ ] **Step 7: Run the client runtime regressions**

Run:

```bash
python -m unittest client-agent.tests.test_runtime_preference_mptcp_bond_fallback
python -m unittest client-agent.tests.test_highloss_self_leg_runtime_regression
python -m unittest client-agent.tests.test_mptcp_bond_regressions
```

Expected:

- first suite: `Ran 3 tests ... OK`
- second suite: `Ran 11 tests ... OK`
- third suite: all targeted regressions pass, including the self-leg MPTCP render cases

- [ ] **Step 8: Commit the client runtime selection/rendering changes**

```bash
git add client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py client-agent/tests/test_highloss_self_leg_runtime_regression.py client-agent/tests/test_mptcp_bond_regressions.py
git commit -m "feat: land mptcp on direct-cf self leg before rented helpers"
```

### Task 4: Harden local MPTCP helper cleanup so high-loss diagnosis is not masked by stale WG state

**Files:**
- Modify: `client-agent/leikwan_mptcp_client.py`
- Test: `client-agent/tests/test_mptcp_setup_and_cleanup_regressions.py`
- Test: `client-agent/tests/test_highloss_self_leg_runtime_regression.py`

- [ ] **Step 1: Keep failed underlays non-fatal to the forward**

In `client-agent/leikwan_mptcp_client.py`, keep:

```python
        if apply_wireguard_transit_underlay_single(merged):
            if name:
                active_iface_names.add(name)
        elif name:
            failed_iface_names.add(name)
```

and after cleanup:

```python
    if failed_iface_names:
        logging.warning("skipped failed MPTCP transit interfaces: %s", ", ".join(sorted(failed_iface_names)))
```

- [ ] **Step 2: Keep stale transit cleanup scoped to the same forward prefix only**

In `client-agent/leikwan_mptcp_client.py`, keep scope derivation:

```python
    forward_id = int(config.get("forward_id", 0) or 0)
    if forward_id > 0:
        scoped_prefixes.add(f"lw-mptcp-{forward_id}-")
```

and only remove interfaces that match the scoped prefixes but are not in `active_names`.

- [ ] **Step 3: Keep stale UoT unit cleanup aligned with the same forward**

In `client-agent/leikwan_mptcp_client.py`, keep:

```python
    prefix = f"leikwan-uot-lw-mptcp-{forward_id}-"
    for unit_path in unit_root.glob(f"{prefix}*.service"):
```

so cleanup is not allowed to reach unrelated forwards.

- [ ] **Step 4: Preserve active underlays on no-op reapply**

Keep the current client apply path behavior that avoids calling `cleanup_mptcp_forward_runtime()` when the rendered files are unchanged and the service is already active; keep the regression in `client-agent/tests/test_highloss_self_leg_runtime_regression.py` passing.

- [ ] **Step 5: Run the MPTCP cleanup regressions**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_setup_and_cleanup_regressions
python -m unittest client-agent.tests.test_highloss_self_leg_runtime_regression
```

Expected: both suites pass.

- [ ] **Step 6: Commit the helper cleanup hardening**

```bash
git add client-agent/leikwan_mptcp_client.py client-agent/tests/test_mptcp_setup_and_cleanup_regressions.py client-agent/tests/test_highloss_self_leg_runtime_regression.py
git commit -m "fix: keep stale mptcp transit cleanup scoped and non-fatal"
```

### Task 5: Verify Tokyo-only control-plane settings before touching live hosts

**Files:** verify only

- [ ] **Step 1: Check the current Tokyo forward-combination fields locally in code/tests**

Run:

```bash
rg -n "runtime_preference|allow_private_self_leg|self_leg_scope|self_leg_role|helper_group_cap|bond_ingress_mode" master/src/Support/ProvisioningService.php master/src/Controllers/AdminController.php -S
```

Expected: the Tokyo-capable combination fields are present in both provisioning and admin surfaces.

- [ ] **Step 2: Record the intended Tokyo-only values before live application**

The live Tokyo combination should be set to exactly:

```text
runtime_preference = mptcp_preferred_bond_fallback
allow_private_self_leg = 1
self_leg_scope = device_session
self_leg_role = latency_anchor
bond_ingress_mode = plain_tcp_mux
```

and should keep Shanghai helper groups only. Do not apply any Canton change.

- [ ] **Step 3: Verify that slave ingress ports remain constrained to 7000-9000**

Run:

```bash
php master/tests/provisioning_mptcp_slave_port_range_regression_test.php
```

Expected: `ok`

### Task 6: Deploy Tokyo-only code and restart only the affected client/runtime pieces through WSL

**Files:** deployment only

- [ ] **Step 1: Sync the touched runtime files to the real client host through WSL SSH**

Use WSL and the host tuple stored in `leikwan-v3.txt` to copy exactly these files to the client runtime locations:

```text
client-agent/client_agent.py -> /usr/local/bin/client_agent.py equivalent deployed path
client-agent/install_forward_runtime.py -> deployed client-agent runtime path
client-agent/leikwan_mptcp_client.py -> /usr/local/bin/leikwan-mptcp-client.py
```

Also sync panel PHP changes to the panel host from `leikwan-v3.txt` if they are not already deployed.

- [ ] **Step 2: Apply Tokyo combination changes only**

Using panel access from `leikwan-v3.txt`, update only the Tokyo forward combination / Tokyo forwards so the self-leg is enabled and Canton combinations remain unchanged.

- [ ] **Step 3: Clean only forward-47 stale client artifacts**

On the client host, stop only the affected service and remove only forward-47 stale transit state:

```bash
sudo systemctl stop leikwan-client-47.service
sudo systemctl stop wg-quick@lw-mptcp-47-0.service wg-quick@lw-mptcp-47-1.service wg-quick@lw-mptcp-47-2.service wg-quick@lw-mptcp-47-3.service 2>/dev/null || true
sudo systemctl stop leikwan-uot-lw-mptcp-47-0-*.service leikwan-uot-lw-mptcp-47-1-*.service leikwan-uot-lw-mptcp-47-2-*.service leikwan-uot-lw-mptcp-47-3-*.service 2>/dev/null || true
sudo rm -f /etc/wireguard/lw-mptcp-47-*.conf
sudo ip link delete dev lw-mptcp-47-0 2>/dev/null || true
sudo ip link delete dev lw-mptcp-47-1 2>/dev/null || true
sudo ip link delete dev lw-mptcp-47-2 2>/dev/null || true
sudo ip link delete dev lw-mptcp-47-3 2>/dev/null || true
sudo ip link delete dev lw-mptcp-47-4 2>/dev/null || true
sudo ip link delete dev lw-mptcp-47-5 2>/dev/null || true
sudo systemctl daemon-reload
sudo systemctl restart leikwan-client-agent.service
```

Expected: the client agent re-renders fresh `forward-47` runtime state without stale `47-4/47-5` devices surviving.

- [ ] **Step 4: Restart only Tokyo/Shanghai slave-side services if panel publish requires it**

Use only the Shanghai entries from `shanghais.txt`. Do not log into or restart Canton slaves.

- [ ] **Step 5: Commit the deployment manifest if you maintain one locally**

```bash
git add docs/superpowers/handoffs docs/superpowers/plans
git commit -m "docs: record tokyo self-leg mptcp deployment plan"
```

### Task 7: Verify that forward 47 is stable and that the self-leg is really the first landing leg

**Files:** verify only

- [ ] **Step 1: Check the rendered client MPTCP config**

On the client host, inspect:

```bash
sudo cat /etc/leikwan/mptcp-forward-47.json
```

Expected decisive fields:

```json
{
  "remote_host": "127.0.0.1",
  "bond_mode": "tcp",
  "direct_legs": [
    {
      "candidate_kind": "self_leg",
      "priority": 1,
      "display_host": "self_leg"
    }
  ]
}
```

and `mptcp_endpoints` entries should each still carry `endpoint_id`.

- [ ] **Step 2: Check service health and local listener**

Run on the client:

```bash
sudo systemctl is-active leikwan-client-47.service
sudo ss -ltnp | grep 11035
sudo journalctl -u leikwan-client-47.service -n 100 --no-pager
```

Expected:

- service is `active`
- listener exists on `:11035`
- no `RuntimeError: transit interface did not appear`

- [ ] **Step 3: Check that stale interfaces are gone and active ones are reordered correctly**

Run:

```bash
sudo wg show interfaces
sudo ip -o link show | grep 'lw-mptcp-47-'
```

Expected:

- no `lw-mptcp-47-4`
- no `lw-mptcp-47-5`
- active underlays match the new sorted endpoint order

- [ ] **Step 4: Check that the self-leg wstunnel process exists**

Run:

```bash
ps -ef | grep wstunnel | grep -E '2053|10012|tokyo-path'
```

Expected: one direct-CF client-side wstunnel for the Tokyo self-leg, pointing at the Cloudflare edge/SNI/path for Tokyo.

### Task 8: Measure whether loss/latency actually improved and isolate any remaining bond mismatch

**Files:** verify only

- [ ] **Step 1: Re-run the user-visible loss sample through forward 47**

Run from the client path that exercises the Tokyo listener:

```bash
ping -c 555 -i 1 127.0.0.1 -p 2a -W 1
```

If the actual validation must go through the forwarded Tokyo target instead of localhost, run the same packet-loss sample through the user’s existing test path and capture the resulting packet-loss/RTT line.

Expected: packet loss materially lower than `24.6847%` and RTT near the measured fast CF edge path rather than the old `~82 ms` helper-first path.

- [ ] **Step 2: Check direct-CF readiness versus rented-helper readiness**

Run the built-in client probes or inspect the latest health snapshot so you can compare:

- self-leg measured RTT
- endpoint `320` RTT
- other Shanghai helper RTTs

Expected: the selected primary candidate is whichever is actually lowest-latency at runtime.

- [ ] **Step 3: If forward 59 still shows `invalid bond frame magic`, isolate it separately without changing Canton**

Inspect:

```bash
sudo journalctl -u leikwan-client-59.service -n 100 --no-pager
```

Then verify on the relevant Shanghai slave(s) that `plain_tcp_mux` routes point to bond-speaking sidecar backends, not plain HTTP/WSS responses.

Expected: if still broken, treat `forward 59` as a separate Shanghai/Tokyo bond-ingress mismatch follow-up, not as a blocker to shipping the forward-47 self-leg MPTCP landing-leg fix.

- [ ] **Step 4: Final verification sweep**

Run locally:

```bash
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/NodeController.php
php -l master/src/Controllers/SidecarAgentController.php
python -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py
python -m unittest client-agent.tests.test_highloss_self_leg_runtime_regression client-agent.tests.test_runtime_preference_mptcp_bond_fallback client-agent.tests.test_mptcp_setup_and_cleanup_regressions client-agent.tests.test_mptcp_bond_regressions
```

Expected:

- PHP syntax checks: `No syntax errors detected`
- Python compile: no output / success exit code
- all listed unit suites pass

- [ ] **Step 5: Commit any final cleanups**

```bash
git add master/src/Support/ProvisioningService.php master/src/Controllers/NodeController.php master/src/Controllers/SidecarAgentController.php client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py client-agent/tests/test_highloss_self_leg_runtime_regression.py client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py client-agent/tests/test_mptcp_setup_and_cleanup_regressions.py client-agent/tests/test_mptcp_bond_regressions.py master/tests/provisioning_self_leg_payload_regression_test.php master/tests/provisioning_mptcp_slave_port_range_regression_test.php master/tests/provisioning_runtime_preference_regression_test.php
git commit -m "feat: prefer tokyo direct-cf self leg as first mptcp landing leg"
```

---

## Self-Review

### Spec coverage

- Tokyo-only self-leg feature: Tasks 2-3
- self-leg as first MPTCP landing leg: Task 3
- endpoint identity/remap correctness: Task 2
- high-loss masking by stale WG underlays: Task 4
- Shanghai-only deployment and no Canton touch: Tasks 5-8
- slave ingress port-range safety: Tasks 1, 5
- direct CF to sidecar for self-leg only: Tasks 2-3, 7

### Placeholder scan

- No `TODO` / `TBD` placeholders remain.
- Every task names exact files.
- Verification steps include concrete commands and expected results.
- Secret values are not copied into the plan; instead, the plan uses the repo-authoritative credential files named in `AGENTS.md`.

### Type consistency

- `candidate_kind = self_leg`
- `runtime_capabilities = ['bond', 'mptcp']`
- `probe_transport = cf_direct_wss`
- `mptcp_endpoints[*].endpoint_id`
- `bond_sidecar_approved_upstream_realizations`
- `bond_sidecar_cf_listen_port`

These names are consistent across the PHP payload, client selection logic, and runtime renderer.
