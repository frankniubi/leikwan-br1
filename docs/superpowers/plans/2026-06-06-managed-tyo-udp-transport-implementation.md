# Managed TYO UDP Transport Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make panel publishes automatically converge new and existing forwards to a TYO-terminated managed UDP transport that rides the existing internal accelerated carrier via `lguot`, while keeping provider names out of customer-facing output.

**Architecture:** Reuse the existing static sidecar uplink path already consumed by the slave and sidecar agents. The control plane must publish the existing internal `outer_transport=leigod` field plus `leigod_uot_impl=lguot` for the TYO profile, and the agents must reconcile that payload into WG + `lguot` services automatically. The implementation should keep the current emergency TCP shim as a diagnostic fallback only; the durable product path must be panel-driven, deterministic, and lifecycle-managed for new installs, updates, and deletes.

**Tech Stack:** PHP (`master/src`, `master/tests`), Python (`slave-agent`, `sidecar-agent`, their tests), Rust carrier binary (`tools/lguot`), systemd, WireGuard, current artifact packaging (`artifacts/*`), shell-based smoke checks.

---

## File Structure

- `master/src/Support/ProvisioningService.php`
  - Source of truth for route/combination/forward publish payloads.
  - Existing code already has static sidecar lane primitives:
    - `buildStaticSidecarUplinkPairForNode(...)` at `master/src/Support/ProvisioningService.php:5695`
    - `staticSidecarPrimaryUplinkForForwardNode(...)` at `master/src/Support/ProvisioningService.php:6347`
    - `publishTaskPayloadForForward(...)` at `master/src/Support/ProvisioningService.php:6469`
    - `buildMptcpSlaveUnderlayPayload(...)` at `master/src/Support/ProvisioningService.php:9086`
  - Implementation must extend this existing workflow, not add a parallel sh36-only path.
- `slave-agent/slave_agent.py` and `artifacts/slave-agent.py`
  - Slave-side reconciliation for WG + local `lguot client`.
  - Existing code path: `apply_sidecar_uplink(...)` at `slave-agent/slave_agent.py:4276`.
- `sidecar-agent/sidecar_agent.py` and `artifacts/sidecar-agent.py`
  - TYO sidecar-side reconciliation for WG + `lguot server`.
  - Existing code path: `apply_slave_sidecar_uplink(...)` at `sidecar-agent/sidecar_agent.py:336`.
- `tools/lguot/main.go`
  - Carrier primitive only. Do not add policy/endpoint selection here.
- `master/tests/*leigod*`, `slave-agent/tests/*leigod*`, `sidecar-agent/tests/*leigod*`
  - Regression tests for publish payload, agent reconciliation, and overlay routing.
- `docs/2026-06-06-managed-tyo-udp-smoke.md`
  - Live validation runbook for sh36 and a clean/new-install style convergence check.
- `scripts/managed-tyo-udp-smoke.sh`
  - Optional executable smoke script if implementation time allows. If created, it must be safe to run repeatedly.

---

### Task 1: Make provisioning publish the managed TYO `lguot` lane from existing lane workflow

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:5695-5903`
- Modify: `master/src/Support/ProvisioningService.php:6469-6535`
- Test: `master/tests/provisioning_leigod_uot_impl_test.php`
- Test: `master/tests/provisioning_leigod_overlay_routing_test.php`
- Test: `master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php`

- [ ] **Step 1: Write the failing test**

Extend `master/tests/provisioning_leigod_uot_impl_test.php` with concrete source-level guards around the existing lane workflow. This test style matches the current file, which already uses snippet guards for the relevant production invariants.

Add these assertions after the existing ProvisioningService snippet checks:

```php
foreach ([
    "\$leigodUotImpl = strtolower((string) (\$combination['leigod_uot_impl'] ?? 'gost')) === 'lguot' ? 'lguot' : 'gost';",
    "\$slave['leigod_uot_impl'] = \$leigodUotImpl;",
    "\$sidecar['leigod_uot_impl'] = \$leigodUotImpl;",
    "\$slave['sidecar_gost_host'] = \$sidecarHost;",
    "\$slave['sidecar_gost_port'] = \$gostSocks;",
    "\$sidecar['gost_socks_listen_port'] = \$gostSocks;",
    "\$slave['sidecar_endpoint_host'] = '127.0.0.1';",
    "\$slave['sidecar_endpoint_port'] = \$slaveListenPort;",
] as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('ProvisioningService missing managed TYO lane snippet: ' . $snippet);
    }
}

foreach ([
    "if (is_array(\$staticSidecarUplink) && trim((string) (\$staticSidecarUplink['relay_target'] ?? '')) !== '')",
    "\$publishTarget = (string) \$staticSidecarUplink['relay_target'];",
    "'sidecar_uplink' => \$staticSidecarUplink ?? []",
    "'sidecar_uplink_selector' => \$staticSidecarSelector",
] as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('publishTaskPayloadForForward missing managed uplink target snippet: ' . $snippet);
    }
}
```

Run:

```bash
php master/tests/provisioning_leigod_uot_impl_test.php
```

Expected: FAIL if any managed TYO lane field is missing or if `publishTaskPayloadForForward(...)` does not use the static sidecar `relay_target`.

- [ ] **Step 2: Implement the minimal production change**

In `buildStaticSidecarUplinkPairForNode(...)`, keep the existing internal field names but make the TYO profile explicit and authoritative. The implementation should preserve current behavior for non-managed/non-TYO combinations.

The relevant production block is already around `master/src/Support/ProvisioningService.php:5873-5901`. Ensure it contains these semantics:

```php
if ($leigodMode) {
    $gostSocks = $this->stableSidecarGostSocksPort($combinationId, $nodeId, $lane);
    [$socksUser, $socksPass] = $this->mintLeigodSocksCredentials($combination);
    $leigodUotImpl = strtolower((string) ($combination['leigod_uot_impl'] ?? 'gost')) === 'lguot' ? 'lguot' : 'gost';

    $slave['leigod_line_id'] = (int) ($combination['leigod_line_id'] ?? 0);
    $slave['gost_listen_udp_port'] = $slaveListenPort;
    $slave['sidecar_gost_host'] = $sidecarHost;
    $slave['sidecar_gost_port'] = $gostSocks;
    $slave['sidecar_wg_listen_port'] = $listenPort;
    $slave['socks_user'] = $socksUser;
    $slave['socks_pass'] = $socksPass;
    $slave['leigod_uot_impl'] = $leigodUotImpl;
    $slave['mtu'] = $this->config->getInt('BOND_LEIGOD_WG_MTU', 1280);
    $slave['sidecar_endpoint_host'] = '127.0.0.1';
    $slave['sidecar_endpoint_port'] = $slaveListenPort;
    $slave['leigod_line_toml'] = $this->leigodLineToml((int) ($combination['leigod_line_id'] ?? 0));

    $sidecar['gost_socks_listen_port'] = $gostSocks;
    $sidecar['socks_user'] = $socksUser;
    $sidecar['socks_pass'] = $socksPass;
    $sidecar['leigod_uot_impl'] = $leigodUotImpl;
    $slave['overlay_peer_allowed_ips'] = $overlaySidecarIps;
    $sidecar['overlay_peer_allowed_ips'] = $overlayClientIps;
    $sidecar['overlay_local_addresses'] = $overlaySidecarIps;
}
```

For this feature, set `leigod_uot_impl='lguot'` in the route/combination used for the managed TYO profile. Do not hard-code all internal carrier combinations to `lguot` unless the existing product mode requires that globally.

- [ ] **Step 3: Run the test to verify it passes**

Run:

```bash
php master/tests/provisioning_leigod_uot_impl_test.php
php master/tests/provisioning_leigod_overlay_routing_test.php
php master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/tests/provisioning_leigod_uot_impl_test.php master/tests/provisioning_leigod_overlay_routing_test.php master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php
git commit -m "feat: publish managed TYO lguot uplink"
```

---

### Task 2: Add a real payload-shape test for new forward publish automation

**Files:**
- Modify: `master/tests/provisioning_leigod_uot_impl_test.php`
- Modify: `master/tests/provisioning_leigod_mode_direct_ingress_test.php`
- Modify: `master/tests/provisioning_leigod_no_preconnect_test.php`
- Modify: `master/tests/provisioning_leigod_wg_mtu_config_test.php`
- Modify: `master/src/Support/ProvisioningService.php:6469-6535`

- [ ] **Step 1: Write the failing test**

Add a test in `master/tests/provisioning_leigod_uot_impl_test.php` that directly guards the code path used by `publishTaskPayloadForForward(...)`. If using reflection is practical in the existing test harness, instantiate `ProvisioningService`, seed enough fake rows to reach `publishTaskPayloadForForward(...)`, and assert the returned payload shape. If reflection setup is too heavy, add a source-level guard matching the existing file style.

The target assertion is:

```php
$requiredPayloadSnippets = [
    "'sidecar_uplink' => \$staticSidecarUplink ?? []",
    "'sidecar_uplink_selector' => \$staticSidecarSelector",
    "'mptcp_underlay' => \$clientMode === 'mptcp' ? \$this->buildMptcpSlaveUnderlayPayload",
    "\$publishTarget = (string) \$staticSidecarUplink['relay_target'];",
];
foreach ($requiredPayloadSnippets as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('publish payload missing managed TYO automation snippet: ' . $snippet);
    }
}
```

Run:

```bash
php master/tests/provisioning_leigod_uot_impl_test.php
```

Expected: FAIL if the publish payload does not expose the static uplink / selector / underlay fields needed by clean installs.

- [ ] **Step 2: Implement the minimal production change**

In `publishTaskPayloadForForward(...)`, ensure the publish payload always carries the static sidecar uplink and selector when the selected direct target is sidecar-backed:

```php
'sidecar_uplink' => $staticSidecarUplink ?? [],
'sidecar_uplink_selector' => $staticSidecarSelector,
'mptcp_underlay' => $clientMode === 'mptcp' ? $this->buildMptcpSlaveUnderlayPayload(...) : null,
```

Ensure `$publishTarget` is overridden by the static uplink relay target when present:

```php
if (is_array($staticSidecarUplink) && trim((string) ($staticSidecarUplink['relay_target'] ?? '')) !== '') {
    $publishTarget = (string) $staticSidecarUplink['relay_target'];
}
```

This is the path that makes newly installed agents converge from panel publish state without sh36-specific shell state.

- [ ] **Step 3: Run the tests to verify they pass**

Run:

```bash
php master/tests/provisioning_leigod_uot_impl_test.php
php master/tests/provisioning_leigod_no_preconnect_test.php
php master/tests/provisioning_leigod_wg_mtu_config_test.php
php master/tests/provisioning_leigod_mode_direct_ingress_test.php
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/tests/provisioning_leigod_uot_impl_test.php master/tests/provisioning_leigod_mode_direct_ingress_test.php master/tests/provisioning_leigod_no_preconnect_test.php master/tests/provisioning_leigod_wg_mtu_config_test.php
git commit -m "feat: automate managed TYO transport publish"
```

---

### Task 3: Verify slave agent convergence from clean payload

**Files:**
- Modify: `slave-agent/tests/test_leigod_overlay_routing.py`
- Modify: `slave-agent/slave_agent.py:4276-4496` only if tests expose a missing behavior
- Modify: `artifacts/slave-agent.py:4276-4496` if `slave-agent/slave_agent.py` changes

- [ ] **Step 1: Write the failing test**

Extend `slave-agent/tests/test_leigod_overlay_routing.py` with a test that verifies the existing lguot branch creates the correct service path from a clean payload.

Add this test method to `SlaveLeigodOverlayRoutingTests`:

```python
def test_leigod_lguot_uplink_uses_lguot_client_and_local_wg_endpoint(self):
    agent = self.make_agent()
    agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
    agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
    written: dict[str, str] = {}
    calls: list[str] = []
    uplink = {
        "enabled": True,
        "interface_name": "lws5c5n20p",
        "local_address": "10.240.29.6",
        "peer_address": "10.240.29.5",
        "sidecar_peer_public_key": "sidecar-pub",
        "outer_transport": "leigod",
        "local_listen_port": 43040,
        "gost_listen_udp_port": 43040,
        "sidecar_wg_listen_port": 14980,
        "leigod_line_id": 453,
        "sidecar_gost_host": "185.220.238.143",
        "sidecar_gost_port": 47980,
        "socks_user": "wguot",
        "socks_pass": "s3cret",
        "leigod_uot_impl": "lguot",
        "mtu": 1240,
        "overlay_peer_allowed_ips": ["10.203.28.159"],
    }

    with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
        with mock.patch.object(slave_agent_module, "run_command", return_value=SimpleNamespace(returncode=0, stdout="", stderr="")):
            with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                with mock.patch.object(agent, "write_text_if_changed", side_effect=lambda path, content: written.setdefault(str(path), content) is not None):
                    with mock.patch.object(agent, "ensure_leigod_line_service", side_effect=lambda *args: calls.append("line")):
                        with mock.patch.object(agent, "remove_sidecar_gost_uot_client_service", side_effect=lambda *args: calls.append("remove-gost")):
                            with mock.patch.object(agent, "ensure_sidecar_lguot_client_service", side_effect=lambda *args: calls.append("ensure-lguot")):
                                agent.apply_sidecar_uplink("lws5c5n20p", uplink)

    rendered = "\n".join(written.values())
    self.assertIn("Endpoint = 127.0.0.1:43040", rendered)
    self.assertIn("MTU = 1240", rendered)
    self.assertIn("AllowedIPs = 10.240.29.5/32, 10.203.28.159/32", rendered)
    self.assertEqual(["line", "remove-gost", "ensure-lguot"], calls)
```

Run:

```bash
python3 -m unittest slave-agent.tests.test_leigod_overlay_routing -v
```

Expected: FAIL if the existing branch does not invoke the lguot client path or render the local WG endpoint.

- [ ] **Step 2: Implement the minimal production change**

If the test fails, fix only `apply_sidecar_uplink(...)` so `outer_transport='leigod'` and `leigod_uot_impl='lguot'` causes:

```python
self.remove_sidecar_gost_uot_client_service(interface_name)
self.ensure_sidecar_lguot_client_service(
    interface_name, gost_listen_udp_port, leigod_line_id,
    sidecar_gost_host, sidecar_gost_port, socks_pass,
)
endpoint_host = "127.0.0.1"
endpoint_port = gost_listen_udp_port
```

Keep existing `force_wg_reinit` and remove-before-add behavior.

- [ ] **Step 3: Run the tests to verify they pass**

Run:

```bash
python3 -m unittest slave-agent.tests.test_leigod_overlay_routing -v
python3 -m unittest slave-agent.tests.test_mptcp_underlay_applies_leigod_uplink -v
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add slave-agent/tests/test_leigod_overlay_routing.py slave-agent/slave_agent.py artifacts/slave-agent.py
git commit -m "feat: verify slave managed TYO lguot convergence"
```

---

### Task 4: Verify sidecar agent convergence from clean payload

**Files:**
- Modify: `sidecar-agent/tests/test_leigod_overlay_routing.py`
- Modify: `sidecar-agent/sidecar_agent.py:336-435` only if tests expose a missing behavior
- Modify: `artifacts/sidecar-agent.py:336-435` if `sidecar-agent/sidecar_agent.py` changes

- [ ] **Step 1: Write the failing test**

Extend `sidecar-agent/tests/test_leigod_overlay_routing.py` with a test that verifies the existing sidecar lguot branch creates the server and preserves overlay addressing.

Add this test method to `SidecarLeigodOverlayRoutingTests`:

```python
def test_leigod_lguot_uplink_uses_lguot_server_and_overlay_addresses(self):
    with tempfile.TemporaryDirectory() as tmp_dir:
        tmp = pathlib.Path(tmp_dir)
        config_path = tmp / "agent.json"
        config_path.write_text(json.dumps({
            "state_dir": str(tmp),
            "service_name": "leikwan-bond-sidecar-5",
            "managed_iperf3_ports": [],
        }), encoding="utf-8")
        agent = sidecar_agent_module.SidecarAgent(config_path)
        (tmp / "wireguard").mkdir(parents=True, exist_ok=True)
        (tmp / "wireguard" / "private.key").write_text("sidecar-private\n", encoding="utf-8")
        (tmp / "wireguard" / "public.key").write_text("sidecar-public\n", encoding="utf-8")
        written: dict[str, str] = {}
        calls: list[str] = []
        uplink = {
            "enabled": True,
            "interface_name": "lws5c5n20p",
            "local_address": "10.240.29.5",
            "peer_address": "10.240.29.6",
            "slave_peer_public_key": "slave-pub",
            "listen_port": 14980,
            "outer_transport": "leigod",
            "gost_socks_listen_port": 47980,
            "socks_user": "wguot",
            "socks_pass": "s3cret",
            "leigod_uot_impl": "lguot",
            "overlay_peer_allowed_ips": ["10.203.28.157"],
            "overlay_local_addresses": ["10.203.28.159"],
        }

        with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(sidecar_agent_module.subprocess, "run", return_value=SimpleNamespace(returncode=0, stdout="inactive", stderr="")):
                with mock.patch.object(sidecar_agent_module, "write_text_if_changed", side_effect=lambda path, content, mode=0o600: written.setdefault(str(path), content) is not None):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="sidecar-public"):
                        with mock.patch.object(agent, "remove_sidecar_leigod_gost_server_service", side_effect=lambda *args: calls.append("remove-gost")):
                            with mock.patch.object(agent, "ensure_sidecar_leigod_lguot_server_service", side_effect=lambda *args: calls.append("ensure-lguot")):
                                agent.apply_slave_sidecar_uplink(uplink)

        rendered = "\n".join(written.values())
        self.assertIn("Address = 10.203.28.159/32", rendered)
        self.assertIn("AllowedIPs = 10.240.29.6/32, 10.203.28.157/32", rendered)
        self.assertEqual(["remove-gost", "ensure-lguot"], calls)
```

Run:

```bash
python3 -m unittest sidecar-agent.tests.test_leigod_overlay_routing -v
```

Expected: FAIL if the sidecar does not install the lguot server path or loses overlay addressing.

- [ ] **Step 2: Implement the minimal production change**

If the test fails, fix only `apply_slave_sidecar_uplink(...)` so the sidecar lguot branch calls:

```python
self.remove_sidecar_leigod_gost_server_service(interface_name)
self.ensure_sidecar_leigod_lguot_server_service(
    interface_name, gost_socks_listen_port, listen_port, socks_pass,
)
```

Do not alter the existing overlay Address/AllowedIPs behavior.

- [ ] **Step 3: Run the tests to verify they pass**

Run:

```bash
python3 -m unittest sidecar-agent.tests.test_leigod_overlay_routing -v
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add sidecar-agent/tests/test_leigod_overlay_routing.py sidecar-agent/sidecar_agent.py artifacts/sidecar-agent.py
git commit -m "feat: verify sidecar managed TYO lguot convergence"
```

---

### Task 5: Add neutral customer-facing label guard

**Files:**
- Modify: `master/tests/admin_forward_combination_leigod_field_test.php`
- Modify: `master/src/Controllers/AdminController.php` only if a customer-facing label leaks provider wording
- Modify: `master/src/Support/ProvisioningService.php` only if API summaries expose provider wording

- [ ] **Step 1: Write the failing test**

Extend `master/tests/admin_forward_combination_leigod_field_test.php` or add a small standalone guard test `master/tests/managed_tyo_transport_label_test.php` with explicit string scans.

Create `master/tests/managed_tyo_transport_label_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = __DIR__ . '/..';
$customerFacingFiles = [
    $root . '/src/Controllers/AdminController.php',
    $root . '/src/Controllers/TenantController.php',
    $root . '/public',
];

$forbidden = ['LeiGod', 'leigod'];
foreach ($customerFacingFiles as $path) {
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $content = file_get_contents($file->getPathname()) ?: '';
            foreach ($forbidden as $word) {
                if (str_contains($content, $word)) {
                    fail('customer-facing file leaks provider word: ' . $file->getPathname());
                }
            }
        }
        continue;
    }
    if (is_file($path)) {
        $content = file_get_contents($path) ?: '';
        foreach ($forbidden as $word) {
            if (str_contains($content, $word)) {
                fail('customer-facing file leaks provider word: ' . $path);
            }
        }
    }
}

echo "OK\n";
```

Run:

```bash
php master/tests/managed_tyo_transport_label_test.php
```

Expected: FAIL if customer-facing controllers/public files include the provider word. Internal implementation files such as `ProvisioningService.php` are excluded because existing field names still require it.

- [ ] **Step 2: Implement the minimal production change**

If the test fails, replace user-visible labels only. Use neutral text:

```text
Managed TYO transport
Accelerated TYO route
Regional transport
```

Do not rename internal DB fields or internal code symbols in this task.

- [ ] **Step 3: Run the test to verify it passes**

Run:

```bash
php master/tests/managed_tyo_transport_label_test.php
php master/tests/admin_forward_combination_leigod_field_test.php
```

Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add master/tests/managed_tyo_transport_label_test.php master/src/Controllers/AdminController.php master/src/Controllers/TenantController.php master/public
git commit -m "feat: hide provider name in managed TYO labels"
```

---

### Task 6: Add live smoke validation runbook for sh36 and clean-publish automation

**Files:**
- Create: `docs/2026-06-06-managed-tyo-udp-smoke.md`
- Create: `scripts/managed-tyo-udp-smoke.sh`

- [ ] **Step 1: Write the failing smoke check**

Create `scripts/managed-tyo-udp-smoke.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

SH36_HOST="${SH36_HOST:-sh36.ctyun.mcbar.cc}"
SH36_PASS="${SH36_PASS:-Frank20021212.}"
TOKYO_HOST="${TOKYO_HOST:-185.220.238.143}"
TOKYO_PASS="${TOKYO_PASS:-DefaultPasswd123.}"

plink -batch -ssh -pw "$SH36_PASS" "root@$SH36_HOST" "
  set -eu
  systemctl is-active lgtcp-12022.service >/dev/null
  systemctl is-enabled lgtcp-12022.service >/dev/null
  systemctl is-active leigod-tcp-forwards-reconcile.path >/dev/null
  systemctl is-active leigod-tcp-forwards-reconcile.timer >/dev/null
  systemctl is-enabled leigod-tcp-forwards-reconcile.path >/dev/null
  systemctl is-enabled leigod-tcp-forwards-reconcile.timer >/dev/null
  systemctl is-active lgfwd.service >/tmp/lgfwd.active 2>&1 && exit 1 || true
  ss -ltnp | grep ':12022' | grep 'gost'
"

plink -batch -ssh -pw "$TOKYO_PASS" "root@$TOKYO_HOST" "
  set -eu
  systemctl restart leikwan-bond-sidecar-5-iperf3-5201.service
  sleep 1
  systemctl is-active leikwan-bond-sidecar-5-iperf3-5201.service >/dev/null
"

plink -batch -ssh -pw "$SH36_PASS" "root@$SH36_HOST" "
  set -eu
  timeout 25 iperf3 -c 127.0.0.1 -p 12022 -t 5 --forceflush >/tmp/managed-tyo-12022.out 2>&1
  grep 'receiver' /tmp/managed-tyo-12022.out
"

echo "managed TYO smoke PASS"
```

Run:

```bash
bash scripts/managed-tyo-udp-smoke.sh
```

Expected: FAIL until the script exists and current live dependencies are reachable.

- [ ] **Step 2: Write the runbook**

Create `docs/2026-06-06-managed-tyo-udp-smoke.md`:

```markdown
# Managed TYO UDP Transport Smoke Runbook

This smoke validates sh36 as a live host only. It is not the architecture.

## Checks

1. `lgtcp-12022.service` is persistent, enabled, and active.
2. The reconcile `.path` and `.timer` are enabled and active.
3. `lgfwd.service` is inactive.
4. TCP/12022 reaches the TYO target through the current diagnostic shim.
5. Production UDP rollout must additionally verify the panel-published static sidecar uplink payload and agent-created lguot/WG services.

## Command

```bash
bash scripts/managed-tyo-udp-smoke.sh
```
```

- [ ] **Step 3: Run the smoke check to verify it passes**

Run:

```bash
bash scripts/managed-tyo-udp-smoke.sh
```

Expected: PASS when the current live sh36/TYO validation path is reachable.

- [ ] **Step 4: Commit**

```bash
git add docs/2026-06-06-managed-tyo-udp-smoke.md scripts/managed-tyo-udp-smoke.sh
git commit -m "docs: add managed TYO transport smoke check"
```

---

## Self-Review Checklist

Before handing off implementation:

- [ ] The spec covers new installs, not just sh36.
- [ ] The plan covers automatic reconcile on every new forward publish.
- [ ] The plan uses existing lane primitives in `ProvisioningService`, `slave_agent.py`, and `sidecar_agent.py`.
- [ ] The plan includes code-level work, not just prose.
- [ ] The plan explicitly hides provider names from customer-facing output while allowing existing internal field names.
- [ ] The plan includes tests for payload generation, slave reconcile, sidecar reconcile, cleanup, and smoke validation.
- [ ] The plan does not require SG experimental `wg-uot`.
- [ ] The plan keeps the emergency TCP shim isolated from the durable product path.
- [ ] The implementation commits source and artifact agent copies together whenever agent code changes.
