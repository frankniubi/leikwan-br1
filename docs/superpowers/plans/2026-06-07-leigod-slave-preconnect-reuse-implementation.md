# LeiGod Slave Preconnect Reuse Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Linux `leigod_mode` MPTCP clients reuse the slave's preconnected WG/UoT relay instead of creating local client WG/UoT.

**Architecture:** Add an opt-in panel contract that emits a relay-only Linux client MPTCP config for leigod forwards while the slave keeps the managed leigod/lguot sidecar uplink and public preconnect relay warm. Keep current leigod behavior as the default, keep Android/Windows unchanged, and keep public slave ingress inside `7000-9000`.

**Tech Stack:** PHP panel/provisioning tests, Python slave-agent/client-agent runtime tests, existing Rust MPTCP relay behavior.

---

## File Structure

- Modify: `master/src/Support/ProvisioningService.php`
  - Add a leigod-specific preconnect reuse gate.
  - Add a stable 7000-8999 relay-port allocator.
  - Emit relay-only Linux client config when enabled.
  - Emit public preconnect relay fields to the slave payload.
- Create: `master/tests/provisioning_leigod_slave_preconnect_reuse_test.php`
  - Source-level regression for panel contract and port range.
- Modify: `master/tests/provisioning_leigod_no_preconnect_test.php`
  - Keep existing default/no-preconnect guard intact. Add one assertion that the new feature is opt-in.
- Modify: `slave-agent/slave_agent.py`
  - Let `ensure_preconnect_relay()` bind to `preconnect_listen_host` when present.
  - Let the health port be explicitly provided.
- Create: `slave-agent/tests/test_preconnect_relay_leigod_reuse.py`
  - Unit test that the slave relay binds `0.0.0.0:7xxx`, uses `health_port=data+1`, and targets the sidecar address.
- Create: `client-agent/tests/test_leigod_slave_preconnect_reuse_runtime.py`
  - Unit test that Linux runtime config with slave preconnect does not render client local WG/UoT underlays.
- Optional verify only: `wstunnel-main/leikwan-mptcp-relay/src/client.rs`
  - Existing tests already prove `slave_relay_host` overrides sidecar address. Do not change unless a test reveals a gap.

---

### Task 1: Add panel contract test for opt-in leigod slave reuse

**Files:**
- Create: `master/tests/provisioning_leigod_slave_preconnect_reuse_test.php`
- Modify: `master/tests/provisioning_leigod_no_preconnect_test.php`

- [ ] **Step 1: Write source-level regression test**

Create `master/tests/provisioning_leigod_slave_preconnect_reuse_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$svc = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';

foreach ([
    "LEIGOD_SLAVE_PRECONNECT_REUSE",
    'stableLeigodPreconnectRelayPort',
    "LEIGOD_PRECONNECT_RELAY_PORT_RANGE_START",
    "LEIGOD_PRECONNECT_RELAY_PORT_RANGE_END",
    "'preconnect_listen_host' => '0.0.0.0'",
    "'preconnect_advertise_host' => \$this->resolveEndpointPublicHost(\$endpoint)",
    "'preconnect_health_port' => \$leigodPreconnectPort + 1",
    "'slave_relay_host' => \$leigodPreconnectReuse ? \$this->resolveEndpointPublicHost(",
    "'slave_relay_port' => \$leigodPreconnectReuse ? \$leigodPreconnectPort : 0",
    "'manage_mptcp_endpoints' => \$leigodPreconnectReuse ? false : \$underlayLegs !== []",
    "'underlays' => \$leigodPreconnectReuse ? [] : array_values(array_map(",
    "'subflow_limit' => \$leigodPreconnectReuse ? 1 : max(1,",
] as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('ProvisioningService missing leigod slave-preconnect reuse snippet: ' . $snippet);
    }
}

if (preg_match('/LEIGOD_PRECONNECT_RELAY_PORT_RANGE_START[^\n]+38000/', $svc)) {
    fail('leigod preconnect allocator must not default to 38000-range ports');
}

foreach (["7000", "8999", "9000"] as $portBoundary) {
    if (!str_contains($svc, $portBoundary)) {
        fail('ProvisioningService missing ingress-boundary value: ' . $portBoundary);
    }
}

echo "OK\n";
exit(0);
```

- [ ] **Step 2: Run the new test and confirm it fails**

Run:

```powershell
php master/tests/provisioning_leigod_slave_preconnect_reuse_test.php
```

Expected: failure naming missing `LEIGOD_SLAVE_PRECONNECT_REUSE` or `stableLeigodPreconnectRelayPort`.

- [ ] **Step 3: Preserve default no-preconnect behavior**

Append this assertion to `master/tests/provisioning_leigod_no_preconnect_test.php` before `echo "OK\n";`:

```php
if (!str_contains($svc, "\$leigodPreconnectReuse = \$leigodDirect && \$this->config->getBool('LEIGOD_SLAVE_PRECONNECT_REUSE', false);")) {
    fail('ProvisioningService must keep leigod slave preconnect reuse opt-in');
}
```

- [ ] **Step 4: Run both panel source tests**

Run:

```powershell
php master/tests/provisioning_leigod_no_preconnect_test.php
php master/tests/provisioning_leigod_slave_preconnect_reuse_test.php
```

Expected: first still passes, second still fails until implementation lands.

- [ ] **Step 5: Commit tests**

```bash
git add master/tests/provisioning_leigod_no_preconnect_test.php master/tests/provisioning_leigod_slave_preconnect_reuse_test.php
git commit -m "test(panel): cover leigod slave preconnect reuse contract"
```

---

### Task 2: Add leigod-specific panel port allocator and client contract

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Test: `master/tests/provisioning_leigod_slave_preconnect_reuse_test.php`
- Test: `master/tests/provisioning_leigod_no_preconnect_test.php`

- [ ] **Step 1: Add the opt-in predicate in `buildMptcpClientConfig()`**

Near the existing `$leigodDirect` line, add:

```php
$leigodPreconnectReuse = $leigodDirect && $this->config->getBool('LEIGOD_SLAVE_PRECONNECT_REUSE', false);
$leigodPreconnectEndpoint = $leigodPreconnectReuse && $endpoints !== [] ? $endpoints[0] : [];
$leigodPreconnectPort = $leigodPreconnectReuse && is_array($leigodPreconnectEndpoint)
    ? $this->stableLeigodPreconnectRelayPort(
        (int) ($forward['id'] ?? 0),
        (int) ($leigodPreconnectEndpoint['node_id'] ?? 0)
    )
    : 0;
```

- [ ] **Step 2: Make client config relay-only when opt-in is enabled**

In the returned MPTCP config, change these fields:

```php
'subflow_limit' => $leigodPreconnectReuse ? 1 : max(1, (int) ($this->config->getInt('MPTCP_SUBFLOW_LIMIT', $subflowLimitDefault) ?? $subflowLimitDefault)),
'manage_mptcp_endpoints' => $leigodPreconnectReuse ? false : $underlayLegs !== [],
'mptcp_endpoints' => $leigodPreconnectReuse ? [] : array_values(array_map(function ($leg, $idx) use ($forward) {
    return [
        'endpoint_id' => (int) ($leg['endpoint_id'] ?? 0),
        'address' => $leg['local_address'],
        'dev' => 'lw-mptcp-' . (int) ($forward['id'] ?? 0) . '-' . $idx,
        'subflow' => true,
        'signal' => false,
        'backup_only' => $leg['backup_only'] ?? false,
    ];
}, $underlayLegs, array_keys($underlayLegs))),
'underlays' => $leigodPreconnectReuse ? [] : array_values(array_map(function ($leg, $idx) use ($forward, $overlayMtu) {
    return [
        'type' => (string) ($this->config->get('MPTCP_UNDERLAY_TYPE', 'wireguard') ?? 'wireguard'),
        'mode' => 'slave_transit',
        'interface_name' => 'lw-mptcp-' . (int) ($forward['id'] ?? 0) . '-' . $idx,
        'peer_keepalive_seconds' => max(0, (int) ($this->config->getInt('MPTCP_PEER_KEEPALIVE_SECONDS', 25) ?? 25)),
        'mtu' => $overlayMtu,
        'legs' => [$leg],
    ];
}, $underlayLegs, array_keys($underlayLegs))),
'slave_relay_host' => $leigodPreconnectReuse ? $this->resolveEndpointPublicHost($leigodPreconnectEndpoint) : (
    $this->config->getBool('MPTCP_SLAVE_PRECONNECT', false) && !$leigodDirect && $underlayLegs !== []
        ? (string) ($underlayLegs[0]['slave_address'] ?? '')
        : ''
),
'slave_relay_port' => $leigodPreconnectReuse ? $leigodPreconnectPort : (
    $this->config->getBool('MPTCP_SLAVE_PRECONNECT', false) && !$leigodDirect && $underlayLegs !== []
        ? $this->stablePreconnectRelayPort((int) ($forward['id'] ?? 0), (int) ($underlayLegs[0]['node_id'] ?? 0))
        : 0
),
```

- [ ] **Step 3: Add leigod-specific allocator**

Add this method near `stablePreconnectRelayPort()`:

```php
/**
 * Stable public relay port for leigod slave-preconnect reuse.
 * The data port must stay in 7000-8999 so health_port=data+1 stays <=9000.
 */
private function stableLeigodPreconnectRelayPort(int $forwardId, int $nodeId): int
{
    $configuredStart = (int) ($this->config->getInt('LEIGOD_PRECONNECT_RELAY_PORT_RANGE_START', 7000) ?? 7000);
    $configuredEnd = (int) ($this->config->getInt('LEIGOD_PRECONNECT_RELAY_PORT_RANGE_END', 8999) ?? 8999);
    $rangeStart = max(7000, min(8999, $configuredStart));
    $rangeEnd = max($rangeStart, min(8999, $configuredEnd));
    $rangeSize = max(1, ($rangeEnd - $rangeStart) + 1);
    return $rangeStart + abs((($forwardId * 131) + ($nodeId * 37)) % $rangeSize);
}
```

- [ ] **Step 4: Run panel tests**

```powershell
php master/tests/provisioning_leigod_no_preconnect_test.php
php master/tests/provisioning_leigod_slave_preconnect_reuse_test.php
```

Expected: both `OK`.

- [ ] **Step 5: Commit panel client contract**

```bash
git add master/src/Support/ProvisioningService.php master/tests/provisioning_leigod_no_preconnect_test.php master/tests/provisioning_leigod_slave_preconnect_reuse_test.php
git commit -m "feat(panel): emit leigod slave preconnect reuse contract"
```

---

### Task 3: Publish public preconnect fields to the slave payload

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Test: `master/tests/provisioning_leigod_slave_preconnect_reuse_test.php`

- [ ] **Step 1: Add leigod relay fields in `buildMptcpSlaveUnderlayPayload()`**

After `$forwardId` and `$nodeId` are computed, add:

```php
$leigodDirect = strtolower(trim((string) ($forward['backend_mode'] ?? ''))) === 'leigod_mode';
$leigodPreconnectReuse = $leigodDirect && $this->config->getBool('LEIGOD_SLAVE_PRECONNECT_REUSE', false);
$leigodPreconnectPort = $leigodPreconnectReuse
    ? $this->stableLeigodPreconnectRelayPort($forwardId, $nodeId)
    : 0;
$endpointPublicHost = $this->resolveEndpointPublicHost($endpoint);
```

- [ ] **Step 2: Replace slave preconnect fields in the returned payload**

Change the existing preconnect fields to:

```php
'slave_preconnect' => $leigodPreconnectReuse || $this->config->getBool('MPTCP_SLAVE_PRECONNECT', false),
'preconnect_relay_port' => $leigodPreconnectReuse ? $leigodPreconnectPort : $this->stablePreconnectRelayPort($forwardId, $nodeId),
'preconnect_listen_host' => $leigodPreconnectReuse ? '0.0.0.0' : $addresses['slave_address'],
'preconnect_advertise_host' => $leigodPreconnectReuse ? $endpointPublicHost : $addresses['slave_address'],
'preconnect_health_port' => $leigodPreconnectReuse ? $leigodPreconnectPort + 1 : $this->stablePreconnectRelayPort($forwardId, $nodeId) + 1,
```

- [ ] **Step 3: Keep static sidecar uplink untouched**

Do not remove or rename these fields:

```php
'uses_static_sidecar_uplink' => $usesStaticSidecarUplink,
'sidecar_static_uplink' => $usesStaticSidecarUplink ? $staticSidecarUplink : [],
```

This is what keeps the slave -> sidecar leigod/lguot carrier warm.

- [ ] **Step 4: Run panel tests**

```powershell
php master/tests/provisioning_leigod_slave_preconnect_reuse_test.php
php master/tests/provisioning_leigod_uot_impl_test.php
```

Expected: both `OK`.

- [ ] **Step 5: Commit slave payload contract**

```bash
git add master/src/Support/ProvisioningService.php master/tests/provisioning_leigod_slave_preconnect_reuse_test.php
git commit -m "feat(panel): publish leigod preconnect relay fields to slaves"
```

---

### Task 4: Make slave-agent honor public preconnect listen fields

**Files:**
- Modify: `slave-agent/slave_agent.py`
- Create: `slave-agent/tests/test_preconnect_relay_leigod_reuse.py`

- [ ] **Step 1: Write failing slave-agent test**

Create `slave-agent/tests/test_preconnect_relay_leigod_reuse.py`:

```python
import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from unittest import mock

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE_PATH = REPO_ROOT / "slave-agent" / "slave_agent.py"
spec = importlib.util.spec_from_file_location("slave_agent_leigod_preconnect_under_test", MODULE_PATH)
slave_agent = importlib.util.module_from_spec(spec)
assert spec.loader is not None
sys.modules["slave_agent_leigod_preconnect_under_test"] = slave_agent
spec.loader.exec_module(slave_agent)


class LeigodPreconnectRelayReuseTests(unittest.TestCase):
    def test_preconnect_relay_uses_public_bind_and_explicit_health_port(self) -> None:
        with tempfile.TemporaryDirectory() as td:
            root = pathlib.Path(td)
            cfg = root / "config.json"
            cfg.write_text(json.dumps({
                "state_dir": str(root / "state"),
                "systemd_dir": str(root / "systemd"),
                "log_level": "INFO",
            }), encoding="utf-8")
            agent = slave_agent.SlaveAgent(cfg)
            (root / "systemd").mkdir(parents=True, exist_ok=True)

            with mock.patch.object(slave_agent.shutil, "which", return_value=None):
                result = agent.ensure_preconnect_relay("wstunnel-svc-65-2", {
                    "slave_preconnect": True,
                    "slave_address": "10.203.24.90",
                    "sidecar_address": "10.203.24.91",
                    "preconnect_relay_port": 7324,
                    "preconnect_listen_host": "0.0.0.0",
                    "preconnect_advertise_host": "49.235.160.246",
                    "preconnect_health_port": 7325,
                    "sidecar_mptcp_port": 8898,
                    "tcp_session_token": "token.tcp",
                    "udp_session_token": "token.udp",
                    "forward_id": 65,
                })

            config_path = pathlib.Path(result["relay_service"] and agent.preconnect_relay_config_path("wstunnel-svc-65-2"))
            payload = json.loads(config_path.read_text(encoding="utf-8"))
            self.assertEqual("0.0.0.0", payload["listen_host"])
            self.assertEqual(7324, payload["listen_port"])
            self.assertEqual(7325, payload["health_port"])
            self.assertEqual("10.203.24.91", payload["sidecar_host"])
            self.assertEqual(8898, payload["sidecar_port"])
            self.assertEqual(7324, result["relay_port"])
            self.assertEqual("49.235.160.246", result["relay_advertise_host"])


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run test and confirm it fails**

```powershell
py -3 -m pytest --import-mode=importlib slave-agent/tests/test_preconnect_relay_leigod_reuse.py
```

Expected: failure because `preconnect_listen_host`, `preconnect_health_port`, and `relay_advertise_host` are not honored yet.

- [ ] **Step 3: Implement slave-agent field support**

In `slave-agent/slave_agent.py::ensure_preconnect_relay()`, after reading `slave_address`, add:

```python
listen_host = str(underlay.get("preconnect_listen_host", "") or "").strip() or slave_address
advertise_host = str(underlay.get("preconnect_advertise_host", "") or "").strip() or listen_host
try:
    health_port = int(underlay.get("preconnect_health_port", 0) or 0)
except (TypeError, ValueError):
    health_port = 0
if health_port <= 0:
    health_port = relay_port + 1
```

Then change the relay config fields:

```python
"listen_host": listen_host,
"listen_port": relay_port,
"health_port": health_port,
```

Change logging/result metadata:

```python
logging.info(
    "preconnect relay %s: listen=%s:%d advertise=%s sidecar=%s:%d forward_id=%d",
    relay_service, listen_host, relay_port, advertise_host, sidecar_address, sidecar_port, forward_id,
)
return {
    "relay_service": relay_service,
    "relay_host": listen_host,
    "relay_advertise_host": advertise_host,
    "relay_port": relay_port,
    "health_port": health_port,
}
```

- [ ] **Step 4: Run slave-agent test**

```powershell
py -3 -m pytest --import-mode=importlib slave-agent/tests/test_preconnect_relay_leigod_reuse.py
```

Expected: `1 passed`.

- [ ] **Step 5: Commit slave-agent support**

```bash
git add slave-agent/slave_agent.py slave-agent/tests/test_preconnect_relay_leigod_reuse.py
git commit -m "feat(slave): bind leigod preconnect relay on public ingress"
```

---

### Task 5: Lock Linux client relay-only rendering

**Files:**
- Create: `client-agent/tests/test_leigod_slave_preconnect_reuse_runtime.py`
- Modify only if test fails: `client-agent/install_forward_runtime.py`

- [ ] **Step 1: Write failing/passing Linux runtime test**

Create `client-agent/tests/test_leigod_slave_preconnect_reuse_runtime.py`:

```python
import importlib.util
import json
import pathlib
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE_PATH = REPO_ROOT / "client-agent" / "install_forward_runtime.py"
spec = importlib.util.spec_from_file_location("install_forward_runtime_leigod_preconnect_under_test", MODULE_PATH)
runtime = importlib.util.module_from_spec(spec)
assert spec.loader is not None
sys.modules["install_forward_runtime_leigod_preconnect_under_test"] = runtime
spec.loader.exec_module(runtime)


class LeigodSlavePreconnectRuntimeTests(unittest.TestCase):
    def test_mptcp_slave_preconnect_reuse_does_not_render_client_underlays(self) -> None:
        forward = {
            "forward_id": 65,
            "listen_port": 32065,
            "target": "185.220.238.143:5201",
            "mode": "mptcp",
            "backend_mode": "leigod_mode",
            "transport": "tcp+udp",
            "sni": "v3.leikwanhost.com",
            "path_prefix": "lk-65",
            "endpoints": [],
            "mptcp": {
                "enabled": True,
                "remote_host": "49.235.160.246",
                "remote_port": 7324,
                "relay_host": "49.235.160.246",
                "relay_port": 7324,
                "slave_relay_host": "49.235.160.246",
                "slave_relay_port": 7324,
                "tcp_session_token": "token.tcp",
                "udp_session_token": "token.udp",
                "manage_mptcp_endpoints": False,
                "subflow_limit": 1,
                "underlays": [],
                "mptcp_endpoints": [],
            },
        }
        agent_config = {"state_dir": "/var/lib/leikwan-client-agent", "wireguard_listen_port": 51820}

        result = runtime.render_forward_runtime(forward, agent_config)
        files = {item["path"]: item["content"] for item in result.get("files", [])}
        config_path = next(path for path in files if path.endswith("mptcp-forward-65.json"))
        payload = json.loads(files[config_path])

        self.assertEqual("49.235.160.246", payload["slave_relay_host"])
        self.assertEqual(7324, payload["slave_relay_port"])
        self.assertEqual([], payload["underlays"])
        self.assertEqual([], payload["mptcp_endpoints"])
        self.assertFalse(payload["manage_mptcp_endpoints"])
        self.assertEqual(1, payload["subflow_limit"])
        self.assertNotIn("wstunnel client -L udp://127.0.0.1", result.get("start_script", ""))
        self.assertNotIn("wg-quick up", result.get("start_script", ""))
```

- [ ] **Step 2: Run the test**

```powershell
py -3 -m pytest --import-mode=importlib client-agent/tests/test_leigod_slave_preconnect_reuse_runtime.py
```

Expected: pass if current renderer is already relay-only for empty underlays. If it fails because the renderer creates local WG/UoT, continue to Step 3.

- [ ] **Step 3: Patch renderer only if needed**

If the test fails, change `client-agent/install_forward_runtime.py` so that when:

```python
slave_preconnect_enabled = str(mptcp.get("slave_relay_host", "") or "").strip() != "" and int_or_default(mptcp.get("slave_relay_port"), 0) > 0
```

and `mptcp.get("underlays")` is empty, the renderer does not append any local WG/UoT setup helpers beyond the harmless `--setup-underlays` no-op. Do not remove generic preconnect behavior for non-leigod forwards.

- [ ] **Step 4: Run Linux runtime tests**

```powershell
py -3 -m pytest --import-mode=importlib client-agent/tests/test_leigod_slave_preconnect_reuse_runtime.py client-agent/tests/test_mptcp_setup_and_cleanup_regressions.py client-agent/tests/test_cfwg_slave_relay_runtime.py
```

Expected: all pass.

- [ ] **Step 5: Commit Linux runtime guard**

```bash
git add client-agent/tests/test_leigod_slave_preconnect_reuse_runtime.py client-agent/install_forward_runtime.py
git commit -m "test(client): lock relay-only leigod preconnect runtime"
```

---

### Task 6: Final verification and live rollout checklist

**Files:**
- No source changes.
- Use local tests and live panel/slave checks.

- [ ] **Step 1: Run full focused local verification**

```powershell
php master/tests/provisioning_leigod_no_preconnect_test.php
php master/tests/provisioning_leigod_slave_preconnect_reuse_test.php
php master/tests/provisioning_leigod_uot_impl_test.php
py -3 -m pytest --import-mode=importlib slave-agent/tests/test_preconnect_relay_leigod_reuse.py client-agent/tests/test_leigod_slave_preconnect_reuse_runtime.py client-agent/tests/test_cfwg_slave_relay_runtime.py
```

Expected: all pass.

- [ ] **Step 2: Inspect generated client config for target forward**

Use the panel bootstrap pattern already used in this repo and inspect `buildClientConfig(<forward_id>)` for the leigod MPTCP forward.

Expected client-side fields:

```json
{
  "mptcp": {
    "slave_relay_host": "<slave public host>",
    "slave_relay_port": 7xxx,
    "underlays": [],
    "mptcp_endpoints": [],
    "manage_mptcp_endpoints": false,
    "subflow_limit": 1
  }
}
```

- [ ] **Step 3: Inspect generated slave publish payload**

Expected slave-side fields:

```json
{
  "mptcp_underlay": {
    "slave_preconnect": true,
    "preconnect_relay_port": 7xxx,
    "preconnect_listen_host": "0.0.0.0",
    "preconnect_advertise_host": "<slave public host>",
    "preconnect_health_port": 7xxx + 1,
    "uses_static_sidecar_uplink": true,
    "sidecar_static_uplink": {
      "outer_transport": "leigod",
      "leigod_uot_impl": "lguot"
    }
  }
}
```

- [ ] **Step 4: Live slave check**

On the selected slave, verify:

```bash
systemctl is-active leikwan-preconnect-*.service
ss -ltnp | grep -E ':(7[0-9]{3}|8[0-9]{3}|9000)'
journalctl -u 'leikwan-preconnect-*' -n 80 --no-pager
```

Expected:

- relay listens on `0.0.0.0:<data_port>`;
- health listens on `<data_port + 1>`;
- service logs show sidecar target over the managed sidecar address;
- no public port outside `7000-9000`.

- [ ] **Step 5: Live Linux client check**

On the Linux client host, verify no client-local WG/UoT is created for this forward:

```bash
systemctl cat leikwan-client-<forward_id>.service
cat /etc/leikwan/mptcp-forward-<forward_id>.json
ip link show | grep 'lw-mptcp-<forward_id>' && echo unexpected-local-wg || true
systemctl list-units 'leikwan-uot-lw-mptcp-<forward_id>*' --no-pager
```

Expected:

- config contains `slave_relay_host` / `slave_relay_port`;
- config has empty `underlays`;
- no `lw-mptcp-<forward_id>-*` interface;
- no client-local UoT service for this forward.

- [ ] **Step 6: End-to-end traffic check**

```bash
timeout 25 iperf3 -c 127.0.0.1 -p <client_listen_port> -t 5 --forceflush
```

Expected: receiver line appears and throughput is comparable to the current leigod/lguot path.

- [ ] **Step 7: Commit any verification docs if created**

If a runbook or smoke note is added:

```bash
git add docs/<new-runbook>.md
git commit -m "docs: add leigod slave preconnect reuse smoke"
```
