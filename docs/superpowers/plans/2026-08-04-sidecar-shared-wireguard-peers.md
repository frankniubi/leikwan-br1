# Sidecar Shared WireGuard Peers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Realize one point-level WireGuard listener with one peer per slave for allowlisted sidecar points, then canary point 10 and rerun the current-mode forward benchmark without using port 443.

**Architecture:** The Panel remains the desired-state owner. It emits a shared peer contract for allowlisted points while preserving the legacy per-tuple contract elsewhere. The sidecar groups the shared records into one atomic `wg-quick` configuration, and each slave consumes one deduplicated point-and-node interface. Existing persisted port values remain unchanged.

**Tech Stack:** PHP 8 Panel and regression fixtures, Python 3 sidecar/slave agents with `unittest`, WireGuard/wg-quick, systemd, MySQL, WSL-based deployment tooling, iperf3.

---

## File Map

- Modify `master/src/Support/ProvisioningService.php`: select the shared contract, derive point/node identities, use the persisted point listen port, and deduplicate management payloads.
- Create `master/tests/provisioning_shared_sidecar_wireguard_peers_regression_test.php`: behavioral Panel contract coverage.
- Modify `master/.env.example`: document the point allowlist without changing any port defaults.
- Modify `sidecar-agent/sidecar_agent.py`: validate, group, render, and reconcile one multi-peer point interface atomically.
- Modify `sidecar-agent/tests/test_static_sidecar_uplinks.py`: multi-peer rendering and fail-closed reconciliation coverage.
- Modify `slave-agent/slave_agent.py`: deduplicate shared point uplinks before applying them.
- Modify `slave-agent/tests/test_static_sidecar_uplinks.py`: one-apply-per-point and conflicting-duplicate coverage.
- Regenerate `artifacts/sidecar-agent.py` and `artifacts/slave-agent.py`: byte-identical deployable mirrors.
- Modify `artifacts/versions.json`: agent versions, hashes, and byte sizes.

## Baseline Notes

- `python -m unittest discover -s sidecar-agent/tests -p test_static_sidecar_uplinks.py -v` passes 11 tests on Windows.
- The corresponding WSL Python invocation has four pre-existing permission errors because the test fixture writes `/etc/wireguard` and `/etc/leikwan-sidecar`; use Windows Python for this suite.
- Three targeted Panel tests pass on `master`.
- `master/tests/provisioning_mptcp_sidecar_wireguard_port_regression_test.php` has an existing `expected buildMptcpSlaveUnderlayPayload to return an array` failure and is not a release gate for this change unless the diff alters that code path.

### Task 1: Panel Shared Peer Contract

**Files:**
- Create: `master/tests/provisioning_shared_sidecar_wireguard_peers_regression_test.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/.env.example`

- [ ] **Step 1: Write the failing behavioral test**

Create a fake Database fixture following `master/tests/provisioning_static_sidecar_allocation_hardening_test.php`, instantiate the real `ProvisioningService`, and invoke `buildStaticSidecarUplinkPairForNode()` through reflection.

The central assertions must be equivalent to:

```php
$envPath = tempnam(sys_get_temp_dir(), 'lw-shared-wg-env-');
file_put_contents($envPath, implode("\n", [
    'DEFAULT_TLS_CERT_REF=default-edge-cert',
    'STATIC_SIDECAR_SHARED_WG_POINT_IDS=10',
    '',
]));

$combination = [
    'id' => 13,
    'sidecar_point_id' => 10,
    'backend_mode' => 'current_mode',
    'sidecar_host' => 'hk-sidecar.example',
    'sidecar_listen_port' => 18501,
    'sidecar_bond_listen_port' => 18501,
    'sidecar_mptcp_listen_port' => 18502,
    'sidecar_agent_config_json' => json_encode([
        'wireguard_public_key' => 'sidecar-public-key',
        'wireguard_listen_port' => 18502,
        'wireguard_port_range_end' => 18509,
    ]),
];
$node = [
    'id' => 2,
    'agent_config_json' => json_encode([
        'wireguard_public_key' => 'slave-public-key-2',
    ]),
];

$pairs = call_private($service, 'buildStaticSidecarUplinkPairForNode', $combination, $node);
assert_same(1, count($pairs));
assert_same('shared', $pairs[0]['sidecar']['lane']);
assert_same('shared', $pairs[0]['sidecar']['wireguard_peer_mode']);
assert_same('lwsp10', $pairs[0]['sidecar']['interface_name']);
assert_same('lwsp10n2', $pairs[0]['slave']['interface_name']);
assert_same(18502, $pairs[0]['sidecar']['listen_port']);
assert_same(18502, $pairs[0]['slave']['sidecar_endpoint_port']);
```

Also assert:

```php
// Combination provenance must not change the tunnel identity.
$combination['id'] = 99;
$repeat = call_private($service, 'buildStaticSidecarUplinkPairForNode', $combination, $node);
assert_same($pairs[0]['sidecar']['interface_name'], $repeat[0]['sidecar']['interface_name']);
assert_same($pairs[0]['sidecar']['subnet'], $repeat[0]['sidecar']['subnet']);

// A non-allowlisted point keeps two legacy lanes and allocated range ports.
$combination['sidecar_point_id'] = 9;
$legacy = call_private($service, 'buildStaticSidecarUplinkPairForNode', $combination, $node);
assert_same(2, count($legacy));
assert_true($legacy[0]['sidecar']['listen_port'] !== 18502);
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```bash
wsl bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers && php master/tests/provisioning_shared_sidecar_wireguard_peers_regression_test.php'
```

Expected: FAIL because the current method emits primary/backup tuples, per-tuple names, and allocated range ports.

- [ ] **Step 3: Add allowlist and identity helpers**

Add focused private helpers to `ProvisioningService`:

```php
/** @return array<int, true> */
private function staticSidecarSharedWireguardPointIds(): array
{
    $ids = [];
    foreach (explode(',', (string) ($this->config->get('STATIC_SIDECAR_SHARED_WG_POINT_IDS', '') ?? '')) as $raw) {
        $id = (int) trim($raw);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    return $ids;
}

private function staticSidecarUsesSharedWireguardPeers(int $pointId): bool
{
    return isset($this->staticSidecarSharedWireguardPointIds()[$pointId]);
}

private function sharedSidecarWireguardInterfaceName(int $pointId): string
{
    $name = 'lwsp' . $pointId;
    return strlen($name) <= 15 ? $name : 'lwsp' . substr(hash('crc32b', (string) $pointId), 0, 11);
}

private function sharedSlaveWireguardInterfaceName(int $pointId, int $nodeId): string
{
    $name = 'lwsp' . $pointId . 'n' . $nodeId;
    return strlen($name) <= 15 ? $name : 'lwp' . substr(hash('crc32b', $pointId . ':' . $nodeId), 0, 12);
}
```

Use the existing collision-checked subnet allocator with a shared identity key:

```php
$addresses = $this->allocateStaticSidecarUplinkSubnet($pointId, 0, $nodeId, 'shared');
```

- [ ] **Step 4: Emit one shared pair before the legacy lane loop**

In `buildStaticSidecarUplinkPairForNode()`, after keys, host, mode, and point listen port are resolved, branch for an allowlisted point. Return one pair with:

```php
$base = [
    'enabled' => true,
    'mode' => 'static_slave_sidecar',
    'wireguard_peer_mode' => 'shared',
    'forward_combination_id' => $combinationId,
    'sidecar_point_id' => $pointId,
    'node_id' => $nodeId,
    'lane' => 'shared',
    'subnet' => $addresses['subnet'],
    'listen_port' => $sidecarListenPort,
    'outer_transport' => 'udp',
    'peer_keepalive_seconds' => 25,
    'watchdog' => $existingWatchdog,
];

$sidecar = $base + [
    'interface_name' => $this->sharedSidecarWireguardInterfaceName($pointId),
    'local_address' => $addresses['sidecar_address'],
    'peer_address' => $addresses['slave_address'],
    'slave_peer_public_key' => $slavePublicKey,
    'sidecar_peer_public_key' => $sidecarPublicKey,
    'relay_target' => $addresses['sidecar_address'] . ':' . $relayPort,
];
$slave = $base + [
    'interface_name' => $this->sharedSlaveWireguardInterfaceName($pointId, $nodeId),
    'local_address' => $addresses['slave_address'],
    'peer_address' => $addresses['sidecar_address'],
    'sidecar_peer_public_key' => $sidecarPublicKey,
    'slave_peer_public_key' => $slavePublicKey,
    'sidecar_endpoint_host' => $sidecarHost,
    'sidecar_endpoint_port' => $sidecarListenPort,
    'local_listen_port' => $this->stableStaticLocalUdpPort($pointId, 0, $nodeId, 'shared'),
    'relay_target' => $addresses['sidecar_address'] . ':' . $relayPort,
];
return [['sidecar' => $sidecar, 'slave' => $slave]];
```

Fail closed instead of silently using a range port when the configured shared listen port is absent:

```php
if ($sidecarListenPort <= 0) {
    return [];
}
```

Keep the existing lane loop unchanged for non-allowlisted points.

- [ ] **Step 5: Deduplicate management payloads**

Add a private helper that keys shared entries by point and node and legacy entries by interface:

```php
private function deduplicateStaticSidecarManagementUplinks(array $uplinks): array
{
    $deduped = [];
    foreach ($uplinks as $uplink) {
        if (!is_array($uplink)) {
            continue;
        }
        $shared = (string) ($uplink['wireguard_peer_mode'] ?? '') === 'shared';
        $key = $shared
            ? 'shared:' . (int) ($uplink['sidecar_point_id'] ?? 0) . ':' . (int) ($uplink['node_id'] ?? 0)
            : 'legacy:' . (string) ($uplink['interface_name'] ?? '');
        if ($key === 'legacy:' || isset($deduped[$key])) {
            continue;
        }
        $deduped[$key] = $uplink;
    }
    return array_values($deduped);
}
```

Call it in both `slaveSidecarUplinkManagementForPoint()` and `slaveSidecarUplinkManagementForNode()` before returning.

- [ ] **Step 6: Document the activation key**

Add to `master/.env.example` near the static sidecar settings:

```dotenv
# Comma-separated sidecar point IDs that use one point-level WG listener with one peer per slave.
STATIC_SIDECAR_SHARED_WG_POINT_IDS=
```

- [ ] **Step 7: Run Panel tests and verify GREEN**

Run:

```bash
wsl bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers && php master/tests/provisioning_shared_sidecar_wireguard_peers_regression_test.php && php master/tests/forward_combination_static_sidecar_wg_regression_test.php && php master/tests/provisioning_static_sidecar_selector_port_regression_test.php && php master/tests/provisioning_static_sidecar_allocation_hardening_test.php'
```

Expected: all PASS. The legacy allocation test must continue to prove non-allowlisted behavior.

- [ ] **Step 8: Commit the Panel contract**

```bash
git add master/.env.example master/src/Support/ProvisioningService.php master/tests/provisioning_shared_sidecar_wireguard_peers_regression_test.php
git commit -m "fix(panel): publish shared sidecar wireguard peers"
```

### Task 2: Sidecar Atomic Multi-Peer Interface

**Files:**
- Modify: `sidecar-agent/tests/test_static_sidecar_uplinks.py`
- Modify: `sidecar-agent/sidecar_agent.py`

- [ ] **Step 1: Write failing render and reconciliation tests**

Add a `shared_uplink()` fixture that varies `node_id`, `local_address`, `peer_address`, and `slave_peer_public_key` while keeping `interface_name='lwsp10'`, `listen_port=18502`, and `wireguard_peer_mode='shared'`.

Add tests equivalent to:

```python
def test_shared_point_renders_one_interface_with_one_peer_per_slave(self):
    agent = self.make_agent()
    peers = [self.shared_uplink(2), self.shared_uplink(3), self.shared_uplink(19), self.shared_uplink(20)]
    config = agent.render_shared_sidecar_wireguard_config(peers, "private-key")
    self.assertEqual(1, config.count("[Interface]"))
    self.assertEqual(4, config.count("[Peer]"))
    self.assertEqual(1, config.count("ListenPort = 18502"))
    self.assertIn("Address = 10.240.1.1/32", config)
    self.assertIn("PublicKey = slave-key-20", config)

def test_shared_point_is_applied_once_per_management_cycle(self):
    agent = self.make_agent()
    peers = [self.shared_uplink(2), self.shared_uplink(3)]
    with mock.patch.object(agent, "apply_shared_slave_sidecar_interface") as apply_shared:
        with mock.patch.object(agent, "apply_slave_sidecar_uplink") as apply_legacy:
            agent.apply_management({"sidecar_uplinks": peers, "bond_runtime": None, "mptcp_runtime": None})
    apply_shared.assert_called_once()
    apply_legacy.assert_not_called()

def test_invalid_shared_peer_group_preserves_running_interface(self):
    agent = self.make_agent()
    first = self.shared_uplink(2)
    duplicate_key = self.shared_uplink(3)
    duplicate_key["slave_peer_public_key"] = first["slave_peer_public_key"]
    with mock.patch.object(agent, "apply_shared_slave_sidecar_interface", side_effect=ValueError("duplicate peer key")):
        with mock.patch.object(agent, "remove_slave_sidecar_uplink") as remove:
            agent.apply_management({"sidecar_uplinks": [first, duplicate_key], "bond_runtime": None, "mptcp_runtime": None})
    remove.assert_not_called_with({"interface_name": "lwsp10"})
```

- [ ] **Step 2: Run the three tests and verify RED**

Run:

```powershell
python -m unittest sidecar-agent.tests.test_static_sidecar_uplinks.SidecarStaticSidecarUplinkTests.test_shared_point_renders_one_interface_with_one_peer_per_slave -v
python -m unittest sidecar-agent.tests.test_static_sidecar_uplinks.SidecarStaticSidecarUplinkTests.test_shared_point_is_applied_once_per_management_cycle -v
python -m unittest sidecar-agent.tests.test_static_sidecar_uplinks.SidecarStaticSidecarUplinkTests.test_invalid_shared_peer_group_preserves_running_interface -v
```

Expected: FAIL because the renderer and grouped apply method do not exist.

- [ ] **Step 3: Implement pure validation and rendering**

Add methods to `SidecarAgent`:

```python
def validate_shared_sidecar_uplinks(self, uplinks: list[dict[str, object]]) -> tuple[str, int]:
    if not uplinks:
        raise ValueError("shared sidecar uplink group is empty")
    interface_name = str(uplinks[0].get("interface_name", "") or "").strip()[:15]
    listen_port = int(uplinks[0].get("listen_port", 0) or 0)
    point_id = int(uplinks[0].get("sidecar_point_id", 0) or 0)
    if interface_name == "" or listen_port <= 0 or point_id <= 0:
        raise ValueError("shared sidecar uplink identity is incomplete")
    peer_keys: set[str] = set()
    allowed_ips: set[str] = set()
    for uplink in uplinks:
        if str(uplink.get("interface_name", "") or "").strip()[:15] != interface_name:
            raise ValueError("shared sidecar interface mismatch")
        if int(uplink.get("listen_port", 0) or 0) != listen_port:
            raise ValueError("shared sidecar listen port mismatch")
        if int(uplink.get("sidecar_point_id", 0) or 0) != point_id:
            raise ValueError("shared sidecar point mismatch")
        if str(uplink.get("outer_transport", "udp") or "udp").strip().lower() != "udp":
            raise ValueError("shared canary only supports direct udp outer transport")
        key = str(uplink.get("slave_peer_public_key", "") or "").strip()
        peer_ip = str(uplink.get("peer_address", "") or "").strip()
        local_ip = str(uplink.get("local_address", "") or "").strip()
        if key == "" or peer_ip == "" or local_ip == "":
            raise ValueError("shared sidecar peer is incomplete")
        if key in peer_keys or peer_ip in allowed_ips:
            raise ValueError("duplicate shared sidecar peer")
        peer_keys.add(key)
        allowed_ips.add(peer_ip)
    return interface_name, listen_port
```

`render_shared_sidecar_wireguard_config()` must sort peers by `node_id`, emit all unique interface addresses, then one `[Peer]` block per slave. It must include any `overlay_local_addresses` and `overlay_peer_allowed_ips` on the corresponding interface/peer.

- [ ] **Step 4: Implement one atomic apply**

`apply_shared_slave_sidecar_interface()` must:

1. call validation before touching files;
2. load the existing sidecar private key;
3. render the complete desired configuration;
4. write the state copy and `/etc/wireguard/${interface_name}.conf` with `write_text_if_changed()`;
5. run at most one `systemctl restart wg-quick@${interface_name}.service` for a changed configuration;
6. start the unit once if it is absent;
7. leave the last valid file and unit untouched when validation raises.

The systemd block should remain equivalent to:

```python
if shutil.which("systemctl") is not None:
    service_name = f"wg-quick@{interface_name}"
    active = self.systemd_is_active(service_name)
    if changed or system_changed:
        subprocess.run(["systemctl", "daemon-reload"], text=True, capture_output=True, check=False)
    if changed or system_changed or not active:
        subprocess.run(["systemctl", "enable", f"{service_name}.service"], text=True, capture_output=True, check=False)
        subprocess.run(["systemctl", "restart" if active else "start", f"{service_name}.service"], text=True, capture_output=True, check=False)
```

- [ ] **Step 5: Group shared records in `apply_management()`**

Split management records into `shared_groups` and legacy records. Add a shared interface to `active_interfaces` before applying so a validation failure preserves the currently running interface. Apply each group once and continue processing other interfaces after a group error.

```python
shared_groups: dict[str, list[dict[str, object]]] = {}
legacy_uplinks: list[dict[str, object]] = []
for uplink in sidecar_uplinks:
    if not isinstance(uplink, dict):
        continue
    interface_name = str(uplink.get("interface_name", "") or "").strip()[:15]
    if str(uplink.get("wireguard_peer_mode", "") or "") == "shared":
        shared_groups.setdefault(interface_name, []).append(uplink)
    else:
        legacy_uplinks.append(uplink)
```

Persist one state entry per shared interface and retain the existing per-interface cleanup behavior for legacy entries.

- [ ] **Step 6: Run the full sidecar suite and verify GREEN**

Run:

```powershell
python -m unittest discover -s sidecar-agent/tests -p test_static_sidecar_uplinks.py -v
python -m py_compile sidecar-agent/sidecar_agent.py
```

Expected: all tests PASS and syntax compilation succeeds.

- [ ] **Step 7: Commit sidecar reconciliation**

```bash
git add sidecar-agent/sidecar_agent.py sidecar-agent/tests/test_static_sidecar_uplinks.py
git commit -m "fix(sidecar): reconcile shared wireguard peers atomically"
```

### Task 3: Slave Shared Uplink Deduplication

**Files:**
- Modify: `slave-agent/tests/test_static_sidecar_uplinks.py`
- Modify: `slave-agent/slave_agent.py`

- [ ] **Step 1: Write failing duplicate tests**

Add tests that call the real `apply_static_sidecar_uplinks()` while mocking the per-interface apply/watch methods:

```python
def test_shared_point_is_applied_once_when_panel_repeats_combination_provenance(self):
    agent = self.make_agent()
    first = self.shared_uplink(point_id=10, node_id=2, combination_id=13)
    repeated = dict(first, forward_combination_id=99)
    with mock.patch.object(agent, "apply_sidecar_uplink") as apply:
        with mock.patch.object(agent, "watch_static_sidecar_uplink"):
            agent.apply_static_sidecar_uplinks([first, repeated])
    apply.assert_called_once()

def test_conflicting_shared_duplicates_preserve_previous_interface(self):
    agent = self.make_agent()
    agent.save_static_sidecar_uplink_state({"interfaces": {"lwsp10n2": {"interface_name": "lwsp10n2"}}})
    first = self.shared_uplink(point_id=10, node_id=2, combination_id=13)
    conflict = dict(first, sidecar_endpoint_port=18504)
    with mock.patch.object(agent, "apply_sidecar_uplink") as apply:
        with mock.patch.object(agent, "remove_sidecar_uplink") as remove:
            agent.apply_static_sidecar_uplinks([first, conflict])
    apply.assert_not_called()
    remove.assert_not_called()
```

- [ ] **Step 2: Run the tests and verify RED**

Run:

```powershell
python -m unittest slave-agent.tests.test_static_sidecar_uplinks.StaticSidecarUplinkTests.test_shared_point_is_applied_once_when_panel_repeats_combination_provenance -v
python -m unittest slave-agent.tests.test_static_sidecar_uplinks.StaticSidecarUplinkTests.test_conflicting_shared_duplicates_preserve_previous_interface -v
```

Expected: the first test observes two applies and the conflict is not rejected.

- [ ] **Step 3: Normalize the desired records before applying**

Add a pure helper:

```python
def normalize_static_sidecar_uplinks(self, raw_uplinks: list[Any]) -> tuple[list[dict[str, Any]], set[str]]:
    normalized: dict[str, dict[str, Any]] = {}
    invalid: set[str] = set()
    for raw in raw_uplinks:
        if not isinstance(raw, dict):
            continue
        uplink = dict(raw)
        interface_name = str(uplink.get("interface_name", "") or "").strip()[:15]
        if interface_name == "" or not uplink.get("enabled", False):
            continue
        previous = normalized.get(interface_name)
        if previous is None:
            normalized[interface_name] = uplink
            continue
        if str(uplink.get("wireguard_peer_mode", "") or "") != "shared":
            invalid.add(interface_name)
            continue
        comparable = ("sidecar_point_id", "node_id", "local_address", "peer_address", "sidecar_peer_public_key", "sidecar_endpoint_host", "sidecar_endpoint_port")
        if any(previous.get(key) != uplink.get(key) for key in comparable):
            invalid.add(interface_name)
    return [item for name, item in normalized.items() if name not in invalid], invalid
```

In `apply_static_sidecar_uplinks()`, treat invalid interfaces as active for cleanup purposes but do not apply or overwrite their previous state entry. Log one error naming the interface and continue with other interfaces.

- [ ] **Step 4: Run slave tests and verify GREEN**

Run:

```powershell
python -m unittest discover -s slave-agent/tests -p test_static_sidecar_uplinks.py -v
python -m py_compile slave-agent/slave_agent.py
```

Expected: all tests PASS and syntax compilation succeeds.

- [ ] **Step 5: Commit slave deduplication**

```bash
git add slave-agent/slave_agent.py slave-agent/tests/test_static_sidecar_uplinks.py
git commit -m "fix(slave): deduplicate shared sidecar uplinks"
```

### Task 4: Artifact Mirrors And Version Metadata

**Files:**
- Modify: `sidecar-agent/sidecar_agent.py`
- Modify: `slave-agent/slave_agent.py`
- Modify: `artifacts/sidecar-agent.py`
- Modify: `artifacts/slave-agent.py`
- Modify: `artifacts/versions.json`

- [ ] **Step 1: Bump source versions**

Set:

```python
# sidecar-agent/sidecar_agent.py
AGENT_VERSION = "2026.08.04.1"

# slave-agent/slave_agent.py
AGENT_VERSION = "2026.08.04.1"
```

- [ ] **Step 2: Regenerate byte-identical mirrors**

Run from PowerShell:

```powershell
Copy-Item sidecar-agent/sidecar_agent.py artifacts/sidecar-agent.py -Force
Copy-Item slave-agent/slave_agent.py artifacts/slave-agent.py -Force
```

- [ ] **Step 3: Update manifest hashes and sizes**

Calculate SHA-256 and byte sizes, then update only the `sidecar_agent` and `slave_agent` entries in `artifacts/versions.json`:

```powershell
$manifestPath = 'artifacts/versions.json'
$manifest = Get-Content $manifestPath -Raw | ConvertFrom-Json
$sidecarFile = Get-Item 'artifacts/sidecar-agent.py'
$slaveFile = Get-Item 'artifacts/slave-agent.py'
$manifest.sidecar_agent.version = '2026.08.04.1'
$manifest.sidecar_agent.sha256 = (Get-FileHash $sidecarFile.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
$manifest.sidecar_agent.size_bytes = [int64]$sidecarFile.Length
$manifest.sidecar_agent.size = [int64]$sidecarFile.Length
$manifest.slave_agent.version = '2026.08.04.1'
$manifest.slave_agent.sha256 = (Get-FileHash $slaveFile.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
$manifest.slave_agent.size_bytes = [int64]$slaveFile.Length
$manifest.slave_agent.size = [int64]$slaveFile.Length
$manifest | ConvertTo-Json -Depth 20 | Set-Content $manifestPath -Encoding utf8
```

Verify both calculated sizes are nonzero before continuing.

- [ ] **Step 4: Run artifact consistency tests**

Run:

```powershell
python -m unittest slave-agent.tests.test_slave_artifact_consistency -v
python -m pytest client-agent/tests/test_artifact_versions_consistency.py -q
```

Run Panel manifest checks in WSL:

```bash
wsl bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers && php master/tests/bond_versions_manifest_regression_test.php && php master/tests/artifact_versions_manifest_consistency_test.php'
```

Expected: all PASS.

- [ ] **Step 5: Commit deployable artifacts**

```bash
git add sidecar-agent/sidecar_agent.py slave-agent/slave_agent.py artifacts/sidecar-agent.py artifacts/slave-agent.py artifacts/versions.json
git commit -m "build: publish shared wireguard agent artifacts"
```

### Task 5: Focused Regression And Review

**Files:**
- Review all files changed since `145797f`.

- [ ] **Step 1: Run focused suites**

```powershell
python -m unittest discover -s sidecar-agent/tests -p test_static_sidecar_uplinks.py -v
python -m unittest discover -s slave-agent/tests -p test_static_sidecar_uplinks.py -v
python -m unittest slave-agent.tests.test_slave_artifact_consistency -v
python -m pytest client-agent/tests/test_artifact_versions_consistency.py -q
python -m py_compile sidecar-agent/sidecar_agent.py slave-agent/slave_agent.py artifacts/sidecar-agent.py artifacts/slave-agent.py
```

```bash
wsl bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers && php master/tests/provisioning_shared_sidecar_wireguard_peers_regression_test.php && php master/tests/forward_combination_static_sidecar_wg_regression_test.php && php master/tests/provisioning_static_sidecar_selector_port_regression_test.php && php master/tests/provisioning_static_sidecar_allocation_hardening_test.php && php master/tests/sidecar_wireguard_peer_observability_regression_test.php && php master/tests/bond_versions_manifest_regression_test.php && php master/tests/artifact_versions_manifest_consistency_test.php'
```

- [ ] **Step 2: Inspect the diff**

```bash
git diff --check master...HEAD
git diff --stat master...HEAD
git diff master...HEAD -- master/src/Support/ProvisioningService.php sidecar-agent/sidecar_agent.py slave-agent/slave_agent.py
```

Confirm:

- no existing port constants or persisted point values changed;
- no direct client-to-sidecar path was introduced;
- `443` does not appear in the new shared WG contract;
- non-allowlisted points still execute the legacy branch;
- validation errors preserve the last valid interfaces.

- [ ] **Step 3: Run pre-deploy review**

Use the repository review workflow against `master...HEAD`. Resolve every correctness finding before deployment. Do not combine unrelated frontend or current dirty-worktree changes.

### Task 6: Production Canary Deployment

**Files deployed:**
- Panel `master/src/Support/ProvisioningService.php`
- Panel `master/.env.example` is documentation only; do not replace production `.env`.
- Artifact files and `artifacts/versions.json`
- Sidecar and slave agent artifacts from the committed branch.

- [ ] **Step 1: Verify the deployment source and live preconditions**

```bash
git status --short
git log -1 --oneline
```

Require a clean worktree and deploy committed `HEAD` only.

Read-only production checks must prove:

```sql
SELECT COUNT(*) AS active_forwards FROM forwards WHERE enabled=1;
SELECT id, name, listen_port, bond_listen_port, mptcp_listen_port,
       JSON_UNQUOTE(JSON_EXTRACT(agent_config_json, '$.wireguard_listen_port')) AS wg_port
FROM sidecar_points WHERE id=10;
```

Expected for the canary: no enabled forwards; point 10 retains `18501`, `18501`, `18502`, and WG `18502`.

- [ ] **Step 2: Back up Panel files, `.env`, and database**

Create a timestamped backup outside the webroot deployment path. Back up:

- the current Panel tree or changed files;
- production `.env`;
- a consistent MySQL dump of the Panel database.

Record backup paths and verify that every backup is nonempty before changing runtime files.

- [ ] **Step 3: Deploy committed Panel and artifact files**

Sync only the committed files from `HEAD`, preserving production ownership and modes. Run:

```bash
php -l master/src/Support/ProvisioningService.php
python3 -m py_compile artifacts/sidecar-agent.py artifacts/slave-agent.py
```

Probe the live artifact endpoints and verify their SHA-256 values equal `artifacts/versions.json`.

- [ ] **Step 4: Deploy agents before enabling the contract**

Keep `STATIC_SIDECAR_SHARED_WG_POINT_IDS` unset while updating agents.

- Update the selected `sha-tencent` slave agents through their normal authenticated update path.
- Update `yt-hk-a` manually through a slave SSH hop because its point config has auto-update disabled.
- Verify all agents report `2026.08.04.1` and recent heartbeats.

Do not access the sidecar directly from the client or local workstation; the SSH path must traverse a slave.

- [ ] **Step 5: Enable point 10 and republish**

Add without changing any port setting:

```dotenv
STATIC_SIDECAR_SHARED_WG_POINT_IDS=10
```

Reload the Panel service if required, then trigger point/node reconciliation. Wait for all provision tasks to complete.

- [ ] **Step 6: Verify the shared data plane**

On the sidecar through a slave hop:

```bash
wg show lwsp10 listen-port
wg show lwsp10 peers | wc -w
wg show lwsp10 latest-handshakes
wg show lwsp10 transfer
ss -H -lunp | grep ':18502 '
```

Expected:

- listen port exactly `18502`;
- one `lwsp10` interface;
- one peer per selected slave;
- every peer has a fresh handshake and nonzero RX/TX after probes;
- no `lws10c13n*p` or `lws10c13n*b` interfaces remain.

On every selected slave:

```bash
wg show interfaces
for iface in $(wg show interfaces | tr ' ' '\n' | awk '/^lwsp10n/'); do
    wg show "$iface" endpoints
    wg show "$iface" latest-handshakes
    peer_ip=$(wg show "$iface" allowed-ips | awk 'NR == 1 {split($2, a, "/"); print a[1]}')
    test -n "$peer_ip" && ping -c 3 "$peer_ip"
done
```

Expected: one point interface, endpoint port `18502`, fresh handshake, and successful overlay ping.

- [ ] **Step 7: Roll back immediately if the gate fails**

Remove point 10 from `STATIC_SIDECAR_SHARED_WG_POINT_IDS`, reload the Panel, and republish point/node desired state. Verify the old interfaces return and no test forward has been created. Port data and database rows require no migration or reversal.

### Task 7: Forward Benchmark Without Port 443

**Runtime scope:** testing client, `sha-tencent` slaves, sidecar point 10, target `v4-hkt-b.mcbar.cc:5201`.

- [ ] **Step 1: Capture direct baseline**

On the test client:

```bash
iperf3 -c v4-hkt-b.mcbar.cc -p 5201 -P 4 -t 30 --json > /tmp/forward-speed-direct.json
```

Record sender/receiver Mbps, retransmits, and test duration.

- [ ] **Step 2: Create explicit production-style test forwards**

Create three temporary persisted forwards on the test service/client:

```text
single: listen_port=11051, public_port=7000, backend_mode=current_mode
race:   listen_port=11052, public_port=7000, backend_mode=current_mode
bond:   listen_port=11053, public_port=7001, backend_mode=current_mode,
        forward_combination_id=13, bond_ingress_mode=plain_tcp_mux
```

Before calling `saveForward()`, assert the payloads satisfy:

```php
foreach ($payloads as $payload) {
    if ((int) $payload['public_port'] === 443) {
        throw new RuntimeException('benchmark_public_port_443_forbidden');
    }
    if (($payload['client_mode'] ?? '') === 'bond'
        && ((int) $payload['public_port'] < 7000 || (int) $payload['public_port'] > 9000)) {
        throw new RuntimeException('benchmark_bond_port_out_of_range');
    }
}
```

Do not edit the test client's port range, node-group ingress settings, sidecar point ports, or global edge default.

- [ ] **Step 3: Wait for convergence and verify listeners**

Require all Panel provisioning tasks to complete. Verify:

```bash
# Slaves
ss -H -lntp | grep -E ':(7000|7001) '
ss -H -lntup | grep ':443 ' || true

# Test client
ss -H -lntp | grep -E ':(11051|11052|11053) '
```

Expected: WSS ingress on `7000`, plain TCP mux on `7001`, all three local listeners, and no temporary slave data-plane listener on `443`.

- [ ] **Step 4: Run the three tests sequentially**

```bash
iperf3 -c 127.0.0.1 -p 11051 -P 4 -t 30 --json > /tmp/forward-speed-single.json
iperf3 -c 127.0.0.1 -p 11052 -P 4 -t 30 --json > /tmp/forward-speed-race.json
iperf3 -c 127.0.0.1 -p 11053 -P 4 -t 30 --json > /tmp/forward-speed-bond.json
```

Run one warm-up before each recorded test if the first connection includes setup cost. Do not run the three measurements concurrently.

- [ ] **Step 5: Correlate runtime evidence**

For each test capture:

- client active mode and selected endpoint/legs;
- slave listener and backend unit state;
- sidecar peer RX/TX deltas for bond;
- sender/receiver Mbps and retransmits;
- errors or reconnects from client/slave/sidecar journals.

Report that single/race use the normal slave path while bond uses sidecar aggregation; do not present them as identical topologies.

- [ ] **Step 6: Delete all temporary forwards**

Use `ProvisioningService::deleteForward()` for all three IDs. Wait for destroy tasks to complete, then verify:

```text
forwards enabled = 0 for all test IDs
test client forwards_total = 0
no listeners on 11051-11053
no temporary slave listeners on 7000/7001/443
no temporary endpoint services or routes
shared point interface remains healthy because it is combination desired state
```

- [ ] **Step 7: Produce the final benchmark report**

Include a compact table with direct, single, race, and bond results plus the realized path, port, endpoint count, throughput, retransmits, and errors. State any invalid run explicitly and do not rank modes from an invalid sample.
