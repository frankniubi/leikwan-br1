# UDP Session Throughput Hysteresis Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish a panel-authoritative UDP sidepath session policy that keeps low-rate UDP sessions on one stable leg and promotes to bounded multi-leg mode only after sustained higher live throughput, with matching client and sidecar Python runtime behavior.

**Architecture:** Normalize and publish `udp_uot_sidepath.session_leg_policy` from the panel through the existing agent-config path, render that policy into the client and sidecar runtime JSON files, then teach the client and sidecar Python UDP runtimes to apply per-session hysteresis instead of broad always-on fanout. Reuse the existing sidecar throughput accounting machinery where possible, but replace its current instantaneous capacity heuristic with the approved hysteresis model.

**Tech Stack:** PHP panel controllers/tests, Python client/sidecar agents and runtimes, `unittest`, existing live LeiKwan bond/plain-tcp-mux UDP sidepath environment.

---

## File Map

### Panel / config publication

- Modify: `master/src/Controllers/BaseController.php`
  - Add one normalization helper for `udp_uot_sidepath`, including `session_leg_policy` defaults/clamping.
- Modify: `master/src/Controllers/ClientAgentController.php`
  - Publish normalized `udp_uot_sidepath` to client agents.
- Modify: `master/src/Controllers/NodeController.php`
  - Publish the same normalized block to slave agents so shadow-route enablement stays in sync with panel defaults.
- Modify: `master/src/Controllers/SidecarAgentController.php`
  - Publish normalized `udp_uot_sidepath` into sidecar `config_patch`.
- Create: `master/tests/udp_uot_sidepath_session_policy_regression_test.php`
  - Regression test for normalization/defaults/publication.

### Runtime config rendering / propagation

- Modify: `client-agent/install_forward_runtime.py`
  - Render `session_leg_policy` into `/etc/leikwan/uot-bond-forward-<id>.json`.
- Modify: `sidecar-agent/sidecar_agent.py`
  - Render `session_leg_policy` into `/var/lib/leikwan-sidecar-<id>/udp-uot-runtime.json`.
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`
  - Regression test for client render path.
- Modify: `client-agent/tests/test_sidecar_dual_runtime_regression.py`
  - Regression test for sidecar runtime config path.

### Client UDP runtime

- Modify: `client-agent/leikwan_uot_bond.py`
  - Add per-session hysteresis state and selection logic.
- Modify: `client-agent/tests/test_uot_bond_runtime.py`
  - Add focused unit tests for low-rate single-leg mode, promote/demote hysteresis, and `multi_leg_cap`.

### Sidecar UDP runtime

- Modify: `sidecar-agent/leikwan_udp_uot_merge.py`
  - Replace the current one-second capacity-based leg-count heuristic with hysteresis policy while preserving current penalty/quarantine behavior.
- Modify: `sidecar-agent/tests/test_udp_uot_merge_runtime.py`
  - Replace/update the current capacity-count tests with hysteresis-based tests and keep the existing selection/penalty tests passing.

### Operator docs / rollout note

- Modify: `docs/BOND_OPERATIONS.md`
  - Document the new `session_leg_policy` block and the initial defaults (`220/180/3s/2 legs`).

---

### Task 1: Normalize and publish `session_leg_policy` from the panel

**Files:**
- Create: `master/tests/udp_uot_sidepath_session_policy_regression_test.php`
- Modify: `master/src/Controllers/BaseController.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/src/Controllers/NodeController.php`
- Modify: `master/src/Controllers/SidecarAgentController.php`

- [ ] **Step 1: Write the failing panel regression test**

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database {}
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $relative = substr($class, strlen($prefix));
        if ($relative === false) {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Controllers\ClientAgentController;
    use Leikwan\Master\Controllers\NodeController;
    use Leikwan\Master\Controllers\SidecarAgentController;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function call_private(object $instance, string $method, mixed ...$args): mixed {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invoke($instance, ...$args);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-uot-policy-env-');
    if ($envPath === false) {
        fail('failed to create env file');
    }
    file_put_contents($envPath, "APP_URL=https://v3.leikwanhost.com\nAGENT_API_URL=https://v3-mirror.leikwanhost.com\n");

    $config = new Config($envPath);
    $db = new Database();
    $provisioning = new ProvisioningService($config, $db);

    $clientController = new ClientAgentController($config, $db, $provisioning);
    $nodeController = new NodeController($config, $db, $provisioning);
    $sidecarController = new SidecarAgentController($config, $db, $provisioning);

    $raw = [
        'enabled_forward_ids' => [26, '45'],
        'shadow_route_suffix' => '.uot',
        'sidecar_port' => 8999,
        'reorder_window_packets' => 4096,
        'reorder_window_bytes' => 16777216,
        'reorder_timeout_ms' => 25,
        'session_leg_policy' => [
            'mode' => 'throughput_hysteresis',
            'initial_mode' => 'single_leg',
            'single_leg_selection' => 'best_healthy',
            'promote_threshold_mbps' => 220,
            'demote_threshold_mbps' => 180,
            'hold_seconds' => 3,
            'multi_leg_cap' => 2,
        ],
    ];

    $normalized = call_private($clientController, 'normalizeUdpUotSidepathConfig', $raw);
    if (!is_array($normalized)) {
        fail('expected normalized udp_uot_sidepath array');
    }
    if (($normalized['session_leg_policy']['promote_threshold_mbps'] ?? null) !== 220) {
        fail('expected promote_threshold_mbps to be published');
    }
    if (($normalized['session_leg_policy']['multi_leg_cap'] ?? null) !== 2) {
        fail('expected multi_leg_cap to be published');
    }

    $clientConfig = call_private($clientController, 'authoritativeAgentConfig', [
        'agent_config_json' => json_encode(['udp_uot_sidepath' => $raw], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    if (($clientConfig['udp_uot_sidepath']['session_leg_policy']['hold_seconds'] ?? null) !== 3) {
        fail('expected client agent config to include session_leg_policy defaults');
    }

    $nodeConfig = call_private($nodeController, 'authoritativeAgentConfig', ['agent_config_json' => json_encode(['udp_uot_sidepath' => $raw], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], [], 'default');
    if (($nodeConfig['udp_uot_sidepath']['session_leg_policy']['demote_threshold_mbps'] ?? null) !== 180) {
        fail('expected slave authoritative config to include session_leg_policy');
    }

    $configPatch = call_private($sidecarController, 'normalizeUdpUotSidepathConfig', $raw);
    if (($configPatch['session_leg_policy']['initial_mode'] ?? null) !== 'single_leg') {
        fail('expected sidecar config patch to keep initial_mode=single_leg');
    }

    echo "ok\n";
}
```

- [ ] **Step 2: Run the PHP regression to verify it fails**

Run: `php master/tests/udp_uot_sidepath_session_policy_regression_test.php`

Expected: FAIL because `normalizeUdpUotSidepathConfig` does not exist yet and the controllers currently just pass through `udp_uot_sidepath` without session-policy normalization.

- [ ] **Step 3: Add one shared normalization helper and wire it into publication**

```php
// master/src/Controllers/BaseController.php

/**
 * @return array<string, mixed>|null
 */
protected function normalizeUdpUotSidepathConfig(mixed $raw): ?array
{
    if (!is_array($raw)) {
        return null;
    }

    $enabledForwardIds = array_values(array_map(
        'intval',
        array_filter(
            $raw['enabled_forward_ids'] ?? [],
            static fn(mixed $value): bool => trim((string) $value) !== '' && ctype_digit((string) $value)
        )
    ));

    $normalized = [
        'enabled_forward_ids' => $enabledForwardIds,
        'shadow_route_suffix' => (string) ($raw['shadow_route_suffix'] ?? '.uot'),
        'sidecar_port' => max(1, (int) ($raw['sidecar_port'] ?? 8999)),
        'reorder_window_packets' => max(1, (int) ($raw['reorder_window_packets'] ?? 4096)),
        'reorder_window_bytes' => max(1024, (int) ($raw['reorder_window_bytes'] ?? 16777216)),
        'reorder_timeout_ms' => max(1, (int) ($raw['reorder_timeout_ms'] ?? 25)),
    ];

    $policy = is_array($raw['session_leg_policy'] ?? null) ? $raw['session_leg_policy'] : [];
    if ($policy !== []) {
        $normalized['session_leg_policy'] = [
            'mode' => 'throughput_hysteresis',
            'initial_mode' => 'single_leg',
            'single_leg_selection' => 'best_healthy',
            'promote_threshold_mbps' => max(1, (int) ($policy['promote_threshold_mbps'] ?? 220)),
            'demote_threshold_mbps' => max(1, (int) ($policy['demote_threshold_mbps'] ?? 180)),
            'hold_seconds' => max(1, (int) ($policy['hold_seconds'] ?? 3)),
            'multi_leg_cap' => max(1, (int) ($policy['multi_leg_cap'] ?? 2)),
        ];
    }

    return $normalized;
}
```

```php
// master/src/Controllers/ClientAgentController.php
$snapshot['udp_uot_sidepath'] = $this->normalizeUdpUotSidepathConfig($stored['udp_uot_sidepath'] ?? null);
```

```php
// master/src/Controllers/NodeController.php
$snapshot['udp_uot_sidepath'] = $this->normalizeUdpUotSidepathConfig($agentConfig['udp_uot_sidepath'] ?? null);
```

```php
// master/src/Controllers/SidecarAgentController.php
'udp_uot_sidepath' => $this->normalizeUdpUotSidepathConfig($runtimeState['udp_uot_sidepath'] ?? null),
```

- [ ] **Step 4: Run the PHP regression again**

Run: `php master/tests/udp_uot_sidepath_session_policy_regression_test.php`

Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add master/src/Controllers/BaseController.php master/src/Controllers/ClientAgentController.php master/src/Controllers/NodeController.php master/src/Controllers/SidecarAgentController.php master/tests/udp_uot_sidepath_session_policy_regression_test.php
git commit -m "feat: publish udp session hysteresis policy"
```

### Task 2: Render `session_leg_policy` into client and sidecar runtime JSON

**Files:**
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `sidecar-agent/sidecar_agent.py`
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`
- Modify: `client-agent/tests/test_sidecar_dual_runtime_regression.py`

- [ ] **Step 1: Add the failing client/sidecar render tests**

```python
# client-agent/tests/test_mptcp_bond_regressions.py

def test_bond_udp_uot_sidepath_renders_session_leg_policy(self) -> None:
    rendered = install_forward_runtime.render_udp_uot_aux_unit(
        {
            "forward_id": 26,
            "listen_port": 11024,
            "path_prefix": "a43547b6350f72cd",
        },
        {
            "route_key": "a43547b6350f72cd",
            "udp_session_token": "udp-token",
            "ws_priority": 10,
        },
        [
            {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "ingress_auth_token": "auth-184"},
        ],
        {},
        {
            "shadow_route_suffix": ".uot",
            "sidecar_port": 8999,
            "reorder_window_packets": 4096,
            "reorder_window_bytes": 16777216,
            "reorder_timeout_ms": 25,
            "session_leg_policy": {
                "mode": "throughput_hysteresis",
                "initial_mode": "single_leg",
                "single_leg_selection": "best_healthy",
                "promote_threshold_mbps": 220,
                "demote_threshold_mbps": 180,
                "hold_seconds": 3,
                "multi_leg_cap": 2,
            },
        },
    )
    payload = json.loads(rendered["files"][0]["content"])
    self.assertEqual(payload["session_leg_policy"]["promote_threshold_mbps"], 220)
    self.assertEqual(payload["session_leg_policy"]["multi_leg_cap"], 2)
```

```python
# client-agent/tests/test_sidecar_dual_runtime_regression.py

self.assertEqual(payload["session_leg_policy"]["hold_seconds"], 3)
self.assertEqual(payload["session_leg_policy"]["initial_mode"], "single_leg")
```

- [ ] **Step 2: Run the Python tests to verify they fail**

Run:
- `python -m unittest discover -s client-agent/tests -p 'test_mptcp_bond_regressions.py' -v`
- `python -m unittest discover -s client-agent/tests -p 'test_sidecar_dual_runtime_regression.py' -v`

Expected: FAIL because neither runtime JSON currently includes `session_leg_policy`.

- [ ] **Step 3: Thread the normalized policy into both runtime config writers**

```python
# client-agent/install_forward_runtime.py

def normalize_session_leg_policy(raw: dict | None) -> dict | None:
    if not isinstance(raw, dict):
        return None
    return {
        "mode": "throughput_hysteresis",
        "initial_mode": "single_leg",
        "single_leg_selection": "best_healthy",
        "promote_threshold_mbps": max(1, int(raw.get("promote_threshold_mbps", 220) or 220)),
        "demote_threshold_mbps": max(1, int(raw.get("demote_threshold_mbps", 180) or 180)),
        "hold_seconds": max(1, int(raw.get("hold_seconds", 3) or 3)),
        "multi_leg_cap": max(1, int(raw.get("multi_leg_cap", 2) or 2)),
    }


def udp_uot_sidepath_settings(forward: dict, agent_config: dict) -> dict | None:
    raw = agent_config.get("udp_uot_sidepath", {})
    if not isinstance(raw, dict):
        return None
    enabled_ids = {
        int(value)
        for value in raw.get("enabled_forward_ids", [])
        if str(value).strip().isdigit()
    }
    forward_id = int(forward.get("forward_id", 0) or 0)
    if forward_id not in enabled_ids:
        return None
    settings = {
        "shadow_route_suffix": str(raw.get("shadow_route_suffix", ".uot") or ".uot"),
        "sidecar_port": int(raw.get("sidecar_port", 8999) or 8999),
        "reorder_window_packets": int(raw.get("reorder_window_packets", 4096) or 4096),
        "reorder_window_bytes": int(raw.get("reorder_window_bytes", 16777216) or 16777216),
        "reorder_timeout_ms": int(raw.get("reorder_timeout_ms", 25) or 25),
    }
    policy = normalize_session_leg_policy(raw.get("session_leg_policy"))
    if policy is not None:
        settings["session_leg_policy"] = policy
    return settings
```

```python
# client-agent/install_forward_runtime.py inside render_udp_uot_aux_unit()
if "session_leg_policy" in settings:
    config_payload["session_leg_policy"] = settings["session_leg_policy"]
```

```python
# sidecar-agent/sidecar_agent.py inside apply_management_config()
policy = sidepath.get("session_leg_policy") if isinstance(sidepath, dict) else None
if isinstance(policy, dict):
    runtime_config["session_leg_policy"] = {
        "mode": "throughput_hysteresis",
        "initial_mode": "single_leg",
        "single_leg_selection": "best_healthy",
        "promote_threshold_mbps": max(1, int(policy.get("promote_threshold_mbps", 220) or 220)),
        "demote_threshold_mbps": max(1, int(policy.get("demote_threshold_mbps", 180) or 180)),
        "hold_seconds": max(1, int(policy.get("hold_seconds", 3) or 3)),
        "multi_leg_cap": max(1, int(policy.get("multi_leg_cap", 2) or 2)),
    }
```

- [ ] **Step 4: Run the render tests again**

Run:
- `python -m unittest discover -s client-agent/tests -p 'test_mptcp_bond_regressions.py' -v`
- `python -m unittest discover -s client-agent/tests -p 'test_sidecar_dual_runtime_regression.py' -v`

Expected: PASS for the new assertions, with runtime JSON containing the normalized policy block.

- [ ] **Step 5: Commit**

```bash
git add client-agent/install_forward_runtime.py sidecar-agent/sidecar_agent.py client-agent/tests/test_mptcp_bond_regressions.py client-agent/tests/test_sidecar_dual_runtime_regression.py
git commit -m "feat: render udp session hysteresis policy"
```

### Task 3: Add per-session hysteresis to the client UDP runtime

**Files:**
- Modify: `client-agent/leikwan_uot_bond.py`
- Modify: `client-agent/tests/test_uot_bond_runtime.py`

- [ ] **Step 1: Add failing client runtime unit tests for hysteresis and capped fanout**

```python
# client-agent/tests/test_uot_bond_runtime.py

def test_session_target_leg_ids_stays_single_below_promote_threshold(self) -> None:
    config = uot_bond.RuntimeConfig(
        forward_id=26,
        listen_udp="127.0.0.1:26026",
        route_key="rk",
        session_token="st",
        reorder_window_packets=16,
        reorder_window_bytes=4096,
        reorder_timeout_ms=10,
        legs=[
            uot_bond.LegConfig(endpoint_id=184, host="8.163.90.132", port=7001, auth_token="t1", weight=8),
            uot_bond.LegConfig(endpoint_id=185, host="8.163.90.235", port=7001, auth_token="t2", weight=8),
        ],
        stats_path="NUL",
        session_leg_policy={
            "mode": "throughput_hysteresis",
            "initial_mode": "single_leg",
            "single_leg_selection": "best_healthy",
            "promote_threshold_mbps": 220,
            "demote_threshold_mbps": 180,
            "hold_seconds": 3,
            "multi_leg_cap": 2,
        },
    )
    runtime = uot_bond.Runtime(config)
    for leg in runtime.leg_states:
        runtime.mark_leg_ready(leg)
    runtime.record_session_egress(1, 1_000_000, now_ms=1000)
    runtime.record_session_egress(1, 1_000_000, now_ms=2000)
    self.assertEqual(runtime.session_target_leg_ids(1, now_ms=2000), [184])


def test_session_target_leg_ids_promotes_after_hold_window(self) -> None:
    config = uot_bond.RuntimeConfig(
        forward_id=26,
        listen_udp="127.0.0.1:26026",
        route_key="rk",
        session_token="st",
        reorder_window_packets=16,
        reorder_window_bytes=4096,
        reorder_timeout_ms=10,
        legs=[
            uot_bond.LegConfig(endpoint_id=184, host="8.163.90.132", port=7001, auth_token="t1", weight=8),
            uot_bond.LegConfig(endpoint_id=185, host="8.163.90.235", port=7001, auth_token="t2", weight=8),
            uot_bond.LegConfig(endpoint_id=186, host="8.163.87.42", port=7001, auth_token="t3", weight=8),
        ],
        stats_path="NUL",
        session_leg_policy={
            "mode": "throughput_hysteresis",
            "initial_mode": "single_leg",
            "single_leg_selection": "best_healthy",
            "promote_threshold_mbps": 220,
            "demote_threshold_mbps": 180,
            "hold_seconds": 3,
            "multi_leg_cap": 2,
        },
    )
    runtime = uot_bond.Runtime(config)
    for leg in runtime.leg_states:
        runtime.mark_leg_ready(leg)
    runtime.record_session_egress(1, 30_000_000, now_ms=1000)
    runtime.record_session_egress(1, 30_000_000, now_ms=2000)
    runtime.record_session_egress(1, 30_000_000, now_ms=3000)
    runtime.record_session_egress(1, 30_000_000, now_ms=4000)
    self.assertEqual(runtime.session_target_leg_ids(1, now_ms=4000), [184, 185])


def test_session_target_leg_ids_demotes_after_lower_hold_window(self) -> None:
    config = uot_bond.RuntimeConfig(
        forward_id=26,
        listen_udp="127.0.0.1:26026",
        route_key="rk",
        session_token="st",
        reorder_window_packets=16,
        reorder_window_bytes=4096,
        reorder_timeout_ms=10,
        legs=[
            uot_bond.LegConfig(endpoint_id=184, host="8.163.90.132", port=7001, auth_token="t1", weight=8),
            uot_bond.LegConfig(endpoint_id=185, host="8.163.90.235", port=7001, auth_token="t2", weight=8),
            uot_bond.LegConfig(endpoint_id=186, host="8.163.87.42", port=7001, auth_token="t3", weight=8),
        ],
        stats_path="NUL",
        session_leg_policy={
            "mode": "throughput_hysteresis",
            "initial_mode": "single_leg",
            "single_leg_selection": "best_healthy",
            "promote_threshold_mbps": 220,
            "demote_threshold_mbps": 180,
            "hold_seconds": 3,
            "multi_leg_cap": 2,
        },
    )
    runtime = uot_bond.Runtime(config)
    for leg in runtime.leg_states:
        runtime.mark_leg_ready(leg)
    for ts in (1000, 2000, 3000, 4000):
        runtime.record_session_egress(1, 30_000_000, now_ms=ts)
    self.assertEqual(runtime.session_target_leg_ids(1, now_ms=4000), [184, 185])
    for ts in (6000, 7000, 8000, 9000):
        runtime.record_session_egress(1, 1_000_000, now_ms=ts)
    self.assertEqual(runtime.session_target_leg_ids(1, now_ms=9000), [184])
```

- [ ] **Step 2: Run the client runtime tests to verify they fail**

Run: `python -m unittest discover -s client-agent/tests -p 'test_uot_bond_runtime.py' -v`

Expected: FAIL because `RuntimeConfig` does not yet accept `session_leg_policy` and the runtime has no per-session hysteresis helpers.

- [ ] **Step 3: Implement per-session hysteresis and pinned-leg selection in `leikwan_uot_bond.py`**

```python
@dataclasses.dataclass
class SessionLegPolicy:
    mode: str = "throughput_hysteresis"
    initial_mode: str = "single_leg"
    single_leg_selection: str = "best_healthy"
    promote_threshold_mbps: int = 220
    demote_threshold_mbps: int = 180
    hold_seconds: int = 3
    multi_leg_cap: int = 2


@dataclasses.dataclass
class SessionLegPolicyState:
    mode: str = "single_leg"
    pinned_endpoint_id: int | None = None
    promote_started_ms: int | None = None
    demote_started_ms: int | None = None
```

```python
# RuntimeConfig
session_leg_policy: SessionLegPolicy | None = None
```

```python
# load_config()
raw_policy = raw.get("session_leg_policy")
policy = None
if isinstance(raw_policy, dict):
    policy = SessionLegPolicy(
        mode="throughput_hysteresis",
        initial_mode="single_leg",
        single_leg_selection="best_healthy",
        promote_threshold_mbps=max(1, int(raw_policy.get("promote_threshold_mbps", 220) or 220)),
        demote_threshold_mbps=max(1, int(raw_policy.get("demote_threshold_mbps", 180) or 180)),
        hold_seconds=max(1, int(raw_policy.get("hold_seconds", 3) or 3)),
        multi_leg_cap=max(1, int(raw_policy.get("multi_leg_cap", 2) or 2)),
    )
return RuntimeConfig(
    forward_id=forward_id,
    listen_udp=str(raw["listen_udp"]),
    route_key=str(raw["route_key"]),
    session_token=str(raw["session_token"]),
    reorder_window_packets=int(raw.get("reorder_window_packets", 4096) or 4096),
    reorder_window_bytes=int(raw.get("reorder_window_bytes", 16777216) or 16777216),
    reorder_timeout_ms=int(raw.get("reorder_timeout_ms", 25) or 25),
    legs=legs,
    stats_path=stats_path,
    session_idle_timeout_ms=int(raw.get("session_idle_timeout_ms", 120000) or 120000),
    session_leg_policy=policy,
)
```

```python
# Runtime.__init__
self.session_policy_state: dict[int, SessionLegPolicyState] = {}
self.session_egress_history: dict[int, deque[tuple[int, int]]] = {}
```

```python
def record_session_egress(self, session_id: int, payload_bytes: int, now_ms: int | None = None) -> None:
    now_ms = int(time.time() * 1000) if now_ms is None else now_ms
    history = self.session_egress_history.setdefault(session_id, deque())
    history.append((now_ms, max(0, int(payload_bytes or 0))))
    while history and now_ms - history[0][0] > max(1000, int((self.config.session_leg_policy.hold_seconds if self.config.session_leg_policy else 3) * 1000)):
        history.popleft()


def session_target_leg_ids(self, session_id: int, now_ms: int | None = None) -> list[int]:
    if self.config.session_leg_policy is None:
        return [leg.endpoint_id for leg in self.leg_states if leg.active]
    history = self.session_egress_history.setdefault(session_id, deque())
    now_ms = int(time.time() * 1000) if now_ms is None else now_ms
    policy = self.config.session_leg_policy
    state = self.session_policy_state.setdefault(session_id, SessionLegPolicyState(mode=policy.initial_mode))
    while history and now_ms - history[0][0] > max(1000, policy.hold_seconds * 1000):
        history.popleft()
    active = [leg for leg in self.leg_states if leg.active and leg.weight > 0]
    ordered = sorted(active, key=lambda leg: (-int(leg.weight or 0), int(leg.consecutive_failures), int(leg.endpoint_id)))
    if not ordered:
        return []
    bits = sum(size for _ts, size in history) * 8
    window_ms = max(1, now_ms - history[0][0] + 1) if history else 1
    estimated_mbps = (bits * 1000) / window_ms / 1_000_000
    if state.mode == "single_leg":
        if estimated_mbps > policy.promote_threshold_mbps:
            state.promote_started_ms = now_ms if state.promote_started_ms is None else state.promote_started_ms
            if now_ms - state.promote_started_ms >= policy.hold_seconds * 1000:
                state.mode = "multi_leg"
                state.promote_started_ms = None
        else:
            state.promote_started_ms = None
    else:
        if estimated_mbps < policy.demote_threshold_mbps:
            state.demote_started_ms = now_ms if state.demote_started_ms is None else state.demote_started_ms
            if now_ms - state.demote_started_ms >= policy.hold_seconds * 1000:
                state.mode = "single_leg"
                state.demote_started_ms = None
        else:
            state.demote_started_ms = None
    if state.mode == "single_leg":
        if state.pinned_endpoint_id not in {leg.endpoint_id for leg in ordered}:
            state.pinned_endpoint_id = ordered[0].endpoint_id
        return [int(state.pinned_endpoint_id)]
    return [leg.endpoint_id for leg in ordered[: policy.multi_leg_cap]]
```

```python
# enqueue_outbound()
allowed = self.session_target_leg_ids(session_id)
leg = self.pick_session_leg(session_id, allowed)
```

```python
# leg_writer_loop()
if frame.msg_type == MSG_DATA:
    self.record_session_egress(frame.session_id, len(frame.payload))
```

- [ ] **Step 4: Run the client runtime tests again**

Run: `python -m unittest discover -s client-agent/tests -p 'test_uot_bond_runtime.py' -v`

Expected: PASS, including the new single-leg / promote / demote / capped-fanout tests.

- [ ] **Step 5: Commit**

```bash
git add client-agent/leikwan_uot_bond.py client-agent/tests/test_uot_bond_runtime.py
git commit -m "feat: add client udp session hysteresis policy"
```

### Task 4: Replace the sidecar's current capacity heuristic with hysteresis policy

**Files:**
- Modify: `sidecar-agent/leikwan_udp_uot_merge.py`
- Modify: `sidecar-agent/tests/test_udp_uot_merge_runtime.py`

- [ ] **Step 1: Replace the existing capacity-count tests with hysteresis-specific failing tests**

```python
# sidecar-agent/tests/test_udp_uot_merge_runtime.py

def test_hysteresis_scope_target_leg_count_stays_single_before_hold_window(self) -> None:
    config = merge_runtime.RuntimeConfig(
        listen_host="127.0.0.1",
        listen_port=8999,
        token_secret="secret-123",
        allowed_forward_ids=[26],
        reorder_window_packets=8,
        reorder_window_bytes=4096,
        reorder_timeout_ms=25,
        stats_path="NUL",
        session_leg_policy={
            "mode": "throughput_hysteresis",
            "initial_mode": "single_leg",
            "single_leg_selection": "best_healthy",
            "promote_threshold_mbps": 220,
            "demote_threshold_mbps": 180,
            "hold_seconds": 3,
            "multi_leg_cap": 2,
        },
    )
    runtime = merge_runtime.Runtime(config)
    scope = merge_runtime.build_scope_key({
        "target_host": "193.227.109.82",
        "target_port": 5201,
        "target_protocol": "udp",
        "forward_id": 26,
    })
    runtime.record_scope_egress(scope, 30_000_000, now_ms=1000)
    runtime.record_scope_egress(scope, 30_000_000, now_ms=2000)
    self.assertEqual(runtime.scope_target_leg_count(scope, now_ms=2000), 1)


def test_hysteresis_scope_target_leg_count_promotes_after_hold_window(self) -> None:
    config = merge_runtime.RuntimeConfig(
        listen_host="127.0.0.1",
        listen_port=8999,
        token_secret="secret-123",
        allowed_forward_ids=[26],
        reorder_window_packets=8,
        reorder_window_bytes=4096,
        reorder_timeout_ms=25,
        stats_path="NUL",
        session_leg_policy={
            "mode": "throughput_hysteresis",
            "initial_mode": "single_leg",
            "single_leg_selection": "best_healthy",
            "promote_threshold_mbps": 220,
            "demote_threshold_mbps": 180,
            "hold_seconds": 3,
            "multi_leg_cap": 2,
        },
    )
    runtime = merge_runtime.Runtime(config)
    scope = merge_runtime.build_scope_key({
        "target_host": "193.227.109.82",
        "target_port": 5201,
        "target_protocol": "udp",
        "forward_id": 26,
    })
    for ts in (1000, 2000, 3000, 4000):
        runtime.record_scope_egress(scope, 30_000_000, now_ms=ts)
    self.assertEqual(runtime.scope_target_leg_count(scope, now_ms=4000), 2)


def test_hysteresis_scope_target_leg_count_demotes_after_lower_hold_window(self) -> None:
    config = merge_runtime.RuntimeConfig(
        listen_host="127.0.0.1",
        listen_port=8999,
        token_secret="secret-123",
        allowed_forward_ids=[26],
        reorder_window_packets=8,
        reorder_window_bytes=4096,
        reorder_timeout_ms=25,
        stats_path="NUL",
        session_leg_policy={
            "mode": "throughput_hysteresis",
            "initial_mode": "single_leg",
            "single_leg_selection": "best_healthy",
            "promote_threshold_mbps": 220,
            "demote_threshold_mbps": 180,
            "hold_seconds": 3,
            "multi_leg_cap": 2,
        },
    )
    runtime = merge_runtime.Runtime(config)
    scope = merge_runtime.build_scope_key({
        "target_host": "193.227.109.82",
        "target_port": 5201,
        "target_protocol": "udp",
        "forward_id": 26,
    })
    for ts in (1000, 2000, 3000, 4000):
        runtime.record_scope_egress(scope, 30_000_000, now_ms=ts)
    self.assertEqual(runtime.scope_target_leg_count(scope, now_ms=4000), 2)
    for ts in (6000, 7000, 8000, 9000):
        runtime.record_scope_egress(scope, 1_000_000, now_ms=ts)
    self.assertEqual(runtime.scope_target_leg_count(scope, now_ms=9000), 1)
```

- [ ] **Step 2: Run the sidecar runtime tests to verify they fail**

Run: `python -m unittest discover -s sidecar-agent/tests -p 'test_udp_uot_merge_runtime.py' -v`

Expected: FAIL because the current implementation uses a one-second instantaneous `per_leg_capacity_bps` heuristic and has no hysteresis timers or mode state.

- [ ] **Step 3: Refactor the sidecar runtime to apply the published hysteresis policy**

```python
@dataclasses.dataclass
class SessionLegPolicy:
    mode: str = "throughput_hysteresis"
    initial_mode: str = "single_leg"
    single_leg_selection: str = "best_healthy"
    promote_threshold_mbps: int = 220
    demote_threshold_mbps: int = 180
    hold_seconds: int = 3
    multi_leg_cap: int = 2


@dataclasses.dataclass
class ScopeLegPolicyState:
    mode: str = "single_leg"
    pinned_leg_id: int | None = None
    promote_started_ms: int | None = None
    demote_started_ms: int | None = None
```

```python
# RuntimeConfig
session_leg_policy: SessionLegPolicy | None = None
```

```python
# Runtime.__init__
self.scope_policy_state: dict[object, ScopeLegPolicyState] = {}
```

```python
def scope_target_leg_count(self, scope_key: object, now_ms: int | None = None) -> int:
    if self.config.session_leg_policy is None:
        history = self.scope_egress_history.setdefault(scope_key, deque())
        history = self.scope_egress_history.setdefault(scope_key, deque())
        while history and now_ms - history[0][0] > 1000:
            history.popleft()
        if not history:
            return 1
        bits = sum(size for _ts, size in history) * 8
        window_ms = max(250, now_ms - history[0][0] + 1)
        estimated_bps = (bits * 1000) / window_ms
        return max(1, int((estimated_bps + 200_000_000 - 1) // 200_000_000))

    policy = self.config.session_leg_policy
    state = self.scope_policy_state.setdefault(scope_key, ScopeLegPolicyState(mode=policy.initial_mode))
    estimated_mbps = self.scope_estimated_mbps(scope_key, now_ms)
    state = self.scope_policy_state.setdefault(scope_key, ScopeLegPolicyState(mode=policy.initial_mode))
    history = self.scope_egress_history.setdefault(scope_key, deque())
    while history and now_ms - history[0][0] > max(1000, policy.hold_seconds * 1000):
        history.popleft()
    if not history:
        return 1
    bits = sum(size for _ts, size in history) * 8
    window_ms = max(1, now_ms - history[0][0] + 1)
    estimated_mbps = (bits * 1000) / window_ms / 1_000_000
    if state.mode == "single_leg":
        if estimated_mbps > policy.promote_threshold_mbps:
            state.promote_started_ms = now_ms if state.promote_started_ms is None else state.promote_started_ms
            if now_ms - state.promote_started_ms >= policy.hold_seconds * 1000:
                state.mode = "multi_leg"
                state.promote_started_ms = None
        else:
            state.promote_started_ms = None
    else:
        if estimated_mbps < policy.demote_threshold_mbps:
            state.demote_started_ms = now_ms if state.demote_started_ms is None else state.demote_started_ms
            if now_ms - state.demote_started_ms >= policy.hold_seconds * 1000:
                state.mode = "single_leg"
                state.demote_started_ms = None
        else:
            state.demote_started_ms = None
    return 1 if state.mode == 'single_leg' else policy.multi_leg_cap
```

```python
def pick_leg(self, scope_key: object, max_legs: int | None = None) -> LegState | None:
    leg_order = self.scope_leg_order.get(scope_key)
    if not leg_order:
        return None
    state = self.scope_policy_state.get(scope_key)
    if state is not None and state.mode == "single_leg" and state.pinned_leg_id is not None:
        pinned = self.active_legs.get(state.pinned_leg_id)
        if pinned is not None:
            return pinned
    allowed_leg_ids: set[int] | None = None
    if max_legs is not None:
        candidates = []
        seen: set[int] = set()
        for leg_id in leg_order:
            if leg_id in seen:
                continue
            seen.add(leg_id)
            leg_state = self.active_legs.get(leg_id)
            if leg_state is None:
                continue
            penalty = self.endpoint_penalties.get(int(leg_state.endpoint_id or 0), {})
            candidates.append((int(penalty.get("failures", 0) or 0), -int(leg_state.weight or 1), int(leg_state.leg_id), leg_state.leg_id))
        candidates.sort()
        allowed_leg_ids = {item[3] for item in candidates[: max(1, int(max_legs or 1))]}
        if state is not None and state.mode == "single_leg" and allowed_leg_ids:
            state.pinned_leg_id = sorted(allowed_leg_ids)[0]
    for _ in range(len(leg_order)):
        leg_id = leg_order[0]
        leg_order.rotate(-1)
        leg_state = self.active_legs.get(leg_id)
        if leg_state is not None and (allowed_leg_ids is None or leg_id in allowed_leg_ids):
            return leg_state
    return None
```

The important rule here is to preserve the existing `record_scope_egress()` and penalty/quarantine machinery, but replace only the leg-count decision from “instant capacity estimate” to “published hysteresis policy”.

- [ ] **Step 4: Run the sidecar runtime tests again**

Run: `python -m unittest discover -s sidecar-agent/tests -p 'test_udp_uot_merge_runtime.py' -v`

Expected: PASS, with the new hysteresis tests replacing the old capacity-threshold assumptions.

- [ ] **Step 5: Commit**

```bash
git add sidecar-agent/leikwan_udp_uot_merge.py sidecar-agent/tests/test_udp_uot_merge_runtime.py
git commit -m "feat: add sidecar udp session hysteresis policy"
```

### Task 5: Update operator docs and run the live verification sweep

**Files:**
- Modify: `docs/BOND_OPERATIONS.md`

- [ ] **Step 1: Add the published `session_leg_policy` block to `docs/BOND_OPERATIONS.md`**

```md
## UDP session hysteresis policy

Panel-published default:

```json
{
  "udp_uot_sidepath": {
    "session_leg_policy": {
      "mode": "throughput_hysteresis",
      "initial_mode": "single_leg",
      "single_leg_selection": "best_healthy",
      "promote_threshold_mbps": 220,
      "demote_threshold_mbps": 180,
      "hold_seconds": 3,
      "multi_leg_cap": 2
    }
  }
}
```

Behavior:
- low-rate UDP sessions stay on one stable leg
- only sustained >220 Mbps promotes to two-leg mode
- sustained <180 Mbps demotes back to one leg
```

- [ ] **Step 2: Run the full verification commands after implementation**

Run:

```bash
php master/tests/udp_uot_sidepath_session_policy_regression_test.php
python -m unittest discover -s client-agent/tests -p 'test_mptcp_bond_regressions.py' -v
python -m unittest discover -s client-agent/tests -p 'test_sidecar_dual_runtime_regression.py' -v
python -m unittest discover -s client-agent/tests -p 'test_uot_bond_runtime.py' -v
python -m unittest discover -s sidecar-agent/tests -p 'test_udp_uot_merge_runtime.py' -v
```

Expected:
- PHP regression prints `ok`
- all Python test files pass

- [ ] **Step 3: Run live low-rate verification**

Run:

```bash
ssh root@172.21.255.189 'systemctl restart leikwan-client-26-udp-uot.service && timeout 15 iperf3 -c 127.0.0.1 -p 11024 -u -R -b 10M -t 10 && cat /run/leikwan/uot-bond-forward-26.stats.json'
```

Expected:
- session remains in single-leg mode
- no fast reorder-buffer growth

- [ ] **Step 4: Run live high-rate verification**

Run:

```bash
ssh root@172.21.255.189 'systemctl restart leikwan-client-26-udp-uot.service && timeout 18 iperf3 -c 127.0.0.1 -p 11024 -u -R -b 400M -t 8 && cat /run/leikwan/uot-bond-forward-26.stats.json'
ssh -J root@8.163.90.132 root@138.252.162.120 'cat /var/lib/leikwan-sidecar-4/udp-uot-runtime-stats.json'
```

Expected:
- initial single-leg operation
- promote to 2-leg mode after the 3-second hold window
- materially lower reorder-skip pressure than the current 8-leg baseline

- [ ] **Step 5: Commit**

```bash
git add docs/BOND_OPERATIONS.md
git commit -m "docs: add udp session hysteresis policy rollout notes"
```
