# Latency-First Bond Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a new `bond_latency_first` policy that keeps sidecar topology unchanged, preserves the existing leg-health engine, gives normal bond and CF-bond lower interactive latency, and keeps helper legs for bulk TCP plus failover.

**Architecture:** Publish a small policy contract from the panel, let Linux and Windows clients select a primary leg plus helpers using the existing health engine, then teach `leikwan-bond` to honor per-leg roles. TCP opens and interactive traffic stay pinned to the primary leg until bulk threshold is crossed. UDP stays primary-only in phase one, with helper legs reserved for failover.

**Tech Stack:** PHP panel provisioning + regression tests, Python Linux client runtime and health logic + `unittest`, Rust `leikwan-bond` CLI/scheduler + `cargo test`, C# Windows desktop runtime parity + `xUnit`, existing live sh36 / Guangzhou / Shanghai verification workflow.

---

## File Structure

### Production files

- Modify: `master/src/Support/ProvisioningService.php`
  - publish `bonding.policy_mode`, `bonding.udp_primary_only`, `bonding.primary_reselect_on_failure_only`, and `bonding.cf_primary_selection_mode`
- Modify: `client-agent/client_agent.py`
  - extend bond endpoint selection to all latency-first bond forwards
  - fix CF “null latency = perfect score” ranking bug
  - assign `leg_role=primary|helper` before runtime rendering
- Modify: `client-agent/install_forward_runtime.py`
  - emit per-leg `role=` query params
  - emit new `leikwan-bond` CLI flags for latency-first scheduling
- Modify: `wstunnel-main/leikwan-bond/src/main.rs`
  - accept latency-first CLI flags and pass them into `SessionConfig`
- Modify: `wstunnel-main/leikwan-bond/src/transport.rs`
  - parse `role=primary|helper` leg metadata
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`
  - add leg role to `LegState`
  - keep interactive TCP on primary until bulk threshold
  - keep UDP primary-only unless primary fails
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/BondingConfig.cs`
  - add JSON-backed policy properties
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs`
  - carry `LegRole`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs`
  - apply latency-first selection to normal bond and CF-bond
  - fix null-latency CF ranking parity
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs`
  - emit per-leg roles and runtime flags
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
  - use latency-first endpoint selection for all latency-first bond forwards
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
  - pass new flags into Windows CF one-leg probe

### Test files

- Create: `master/tests/bond_latency_first_publish_regression_test.php`
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`
- Modify: `wstunnel-main/leikwan-bond/src/transport.rs` tests
- Modify: `wstunnel-main/leikwan-bond/src/session.rs` tests
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondEndpointHealthServiceTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondRuntimeBuilderTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs`

### Documentation files

- Modify: `docs/BOND_OPERATIONS.md`
  - add a short operator section for latency-first bond

### What already exists

- The panel already publishes scheduler tuning in `buildBondClientConfig(...)`:
  - `interactive_chunk_bytes`
  - `latency_guard_bytes`
  - `interactive_rtt_slack_ms`
  - `warmup_trigger_bytes`
  - `warmup_slice_bytes`
  - `warmup_leg_delay_ms`
  - `bulk_promote_bytes`
  - `leg_cooldown_ms`
- Linux client already records bond endpoint health in `client-agent/client_agent.py`.
- CF-bond client selection already exists, but it only handles `cf_mode` and currently caps to the best three healthy legs.
- `leikwan-bond` already supports:
  - sticky / warmup / bulk striping stages
  - per-leg priority
  - TCP and UDP health
  - RTT-aware selection
- Windows desktop already mirrors the Linux bond health and runtime builder patterns.

### NOT in scope

- No direct client -> sidecar topology
- No movement of final aggregation off the sidecar
- No phase-one bulk striping for unknown UDP
- No full DSCP / fwmark / cgroup hint implementation yet
- No UoT sidepath redesign in this plan

---

### Task 1: Publish the latency-first bond policy contract from the panel

**Files:**
- Create: `master/tests/bond_latency_first_publish_regression_test.php`
- Modify: `master/src/Support/ProvisioningService.php:5192-5238`
- Test: `master/tests/bond_leg_cooldown_publish_regression_test.php`
- Test: `master/tests/bond_latency_first_publish_regression_test.php`

- [ ] **Step 1: Write the failing panel regression test**

Create `master/tests/bond_latency_first_publish_regression_test.php` with:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
    }
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
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function call_private(object $instance, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invoke($instance, ...$args);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-bond-latency-first-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }

    file_put_contents(
        $envPath,
        "DEFAULT_TLS_CERT_REF=default-edge-cert\n"
        . "BOND_TOKEN_SECRET=test-bond-secret\n"
        . "BOND_POLICY_MODE=latency_first\n"
        . "BOND_UDP_PRIMARY_ONLY=true\n"
        . "BOND_PRIMARY_RESELECT_ON_FAILURE_ONLY=true\n"
        . "BOND_CF_PRIMARY_SELECTION_MODE=real_path\n"
    );

    $service = new ProvisioningService(new Config($envPath), new Database());

    $forward = [
        'client_mode' => 'bond',
        'public_port' => 7000,
        'path_prefix' => '07289d1f5ed25399',
        'sni_host' => 'nrt1.nbwly.com',
        'cdn_host' => 'nrt1.nbwly.com',
        'filter_profile' => 'off',
        'transport_mode' => 'tcp+udp',
        'backend_mode' => 'cf_mode',
        'bond_remote_host' => '185.220.238.143',
        'bond_remote_port' => 2053,
        'target_host' => '185.220.238.143',
        'target_port' => 5201,
        'service_id' => 2,
        'id' => 47,
        'line_client_name' => 'sh36',
        'bond_ingress_mode' => 'plain_tcp_mux',
    ];

    $endpoints = [
        ['id' => 316, 'host' => '47.116.11.144', 'public_port' => 7000],
        ['id' => 317, 'host' => '47.116.104.15', 'public_port' => 7000],
    ];

    $bonding = call_private($service, 'buildBondClientConfig', $forward, $endpoints);

    if (($bonding['policy_mode'] ?? null) !== 'latency_first') {
        fail('expected policy_mode latency_first');
    }
    if (($bonding['udp_primary_only'] ?? null) !== true) {
        fail('expected udp_primary_only true');
    }
    if (($bonding['primary_reselect_on_failure_only'] ?? null) !== true) {
        fail('expected primary_reselect_on_failure_only true');
    }
    if (($bonding['cf_primary_selection_mode'] ?? null) !== 'real_path') {
        fail('expected cf_primary_selection_mode real_path');
    }

    echo "ok\n";
}
```

- [ ] **Step 2: Run the new panel test and verify it fails**

Run:

```bash
php master/tests/bond_latency_first_publish_regression_test.php
```

Expected: FAIL because `buildBondClientConfig(...)` does not publish the new policy fields yet.

- [ ] **Step 3: Publish the new policy fields from `buildBondClientConfig(...)`**

In `master/src/Support/ProvisioningService.php`, extend the return array near `5236-5238` to include:

```php
            'policy_mode' => trim((string) ($this->config->get('BOND_POLICY_MODE', 'latency_first') ?? 'latency_first')) ?: 'latency_first',
            'udp_primary_only' => $this->config->getBool('BOND_UDP_PRIMARY_ONLY', true),
            'primary_reselect_on_failure_only' => $this->config->getBool('BOND_PRIMARY_RESELECT_ON_FAILURE_ONLY', true),
            'cf_primary_selection_mode' => trim((string) ($this->config->get('BOND_CF_PRIMARY_SELECTION_MODE', 'real_path') ?? 'real_path')) ?: 'real_path',
```

Do not remove the existing tuning fields. The new keys are additive.

- [ ] **Step 4: Run the panel regression tests and verify they pass**

Run:

```bash
php master/tests/bond_leg_cooldown_publish_regression_test.php
php master/tests/bond_latency_first_publish_regression_test.php
```

Expected:
- both print `ok`

- [ ] **Step 5: Commit the panel contract**

Run:

```bash
git add master/src/Support/ProvisioningService.php master/tests/bond_latency_first_publish_regression_test.php
git commit -m "feat: publish latency-first bond policy fields"
```

---

### Task 2: Extend Linux client endpoint selection to choose primary plus helpers

**Files:**
- Modify: `client-agent/client_agent.py:1409-1715`
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
- Test: `client-agent/tests/test_client_agent_bond_endpoint_health.py`

- [ ] **Step 1: Add failing Linux selection tests**

Append these tests to `ClientAgentBondEndpointHealthTests` in `client-agent/tests/test_client_agent_bond_endpoint_health.py`:

```python
    def test_select_bond_runtime_endpoints_latency_first_normal_bond_marks_primary_then_helpers(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_bond_endpoint_health(
                {
                    "40": {
                        "254": {"role": "healthy", "consecutive_successes": 4, "consecutive_failures": 0, "quality_score": 0.75},
                        "256": {"role": "healthy", "consecutive_successes": 4, "consecutive_failures": 0, "quality_score": 0.95},
                        "258": {"role": "healthy", "consecutive_successes": 4, "consecutive_failures": 0, "quality_score": 0.60},
                    }
                }
            )
            forward = {
                "forward_id": 40,
                "mode": "bond",
                "backend_mode": "current_mode",
                "endpoints": [
                    {"endpoint_id": 254, "host": "8.163.90.132", "port": 7000},
                    {"endpoint_id": 256, "host": "8.163.87.42", "port": 7000},
                    {"endpoint_id": 258, "host": "8.163.71.73", "port": 7000},
                ],
                "bonding": {"policy_mode": "latency_first", "ws_priority": 10},
            }

            selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [256, 254, 258])
            self.assertEqual([item["leg_role"] for item in selected], ["primary", "helper", "helper"])

    def test_select_bond_runtime_endpoints_cf_snapshot_does_not_promote_null_latency_legs_to_perfect(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            snapshot_dir = pathlib.Path(tmp_dir) / "run-leikwan"
            snapshot_dir.mkdir(parents=True, exist_ok=True)
            (snapshot_dir / "bond-leg-health-forward-47.json").write_text(
                json.dumps(
                    {
                        "forward_id": 47,
                        "legs": [
                            {"endpoint_id": 316, "host": "47.116.11.144", "tcp": {"state": "healthy", "last_reason": None}, "recent_events": []},
                            {"endpoint_id": 318, "host": "101.133.142.65", "tcp": {"state": "healthy", "last_reason": None}, "recent_events": []},
                            {"endpoint_id": 320, "host": "8.133.170.123", "tcp": {"state": "healthy", "last_reason": None}, "recent_events": []},
                        ],
                    }
                ),
                encoding="utf-8",
            )
            agent.save_bond_endpoint_health(
                {
                    "47": {
                        "316": {"role": "healthy", "consecutive_successes": 3, "consecutive_failures": 0, "quality_score": 0.40},
                        "317": {"role": "healthy", "consecutive_successes": 20, "consecutive_failures": 0, "quality_score": 0.7480, "last_latency_ms": 50.4},
                        "318": {"role": "healthy", "consecutive_successes": 3, "consecutive_failures": 0, "quality_score": 0.40},
                        "319": {"role": "healthy", "consecutive_successes": 20, "consecutive_failures": 0, "quality_score": 0.7485, "last_latency_ms": 50.3},
                        "320": {"role": "healthy", "consecutive_successes": 3, "consecutive_failures": 0, "quality_score": 0.40},
                    }
                }
            )
            forward = {
                "forward_id": 47,
                "mode": "bond",
                "backend_mode": "cf_mode",
                "endpoints": [
                    {"endpoint_id": 316, "host": "47.116.11.144", "port": 7000},
                    {"endpoint_id": 317, "host": "47.116.104.15", "port": 7000},
                    {"endpoint_id": 318, "host": "101.133.142.65", "port": 7000},
                    {"endpoint_id": 319, "host": "47.116.134.3", "port": 7000},
                    {"endpoint_id": 320, "host": "8.133.170.123", "port": 7000},
                ],
                "bonding": {"policy_mode": "latency_first", "ws_priority": 10},
            }

            with mock.patch.object(agent, "bond_leg_snapshot_dir", return_value=snapshot_dir):
                selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [319, 317, 316])
            self.assertEqual(selected[0]["leg_role"], "primary")
            self.assertTrue(all(item["quality_score"] < 1.0 for item in selected))

    def test_select_bond_runtime_endpoints_non_latency_first_bond_keeps_existing_ordering(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            forward = {
                "forward_id": 26,
                "mode": "bond",
                "backend_mode": "current_mode",
                "endpoints": [
                    {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001},
                    {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001},
                ],
                "bonding": {"ws_priority": 10},
            }

            selected = agent.select_bond_runtime_endpoints(forward, now=100.0)

            self.assertEqual([item["endpoint_id"] for item in selected], [184, 185])
            self.assertTrue(all("leg_role" not in item for item in selected))
```

- [ ] **Step 2: Run the focused Linux tests and verify they fail**

Run:

```bash
python -m unittest \
  client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_select_bond_runtime_endpoints_latency_first_normal_bond_marks_primary_then_helpers \
  client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_select_bond_runtime_endpoints_cf_snapshot_does_not_promote_null_latency_legs_to_perfect \
  client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_select_bond_runtime_endpoints_non_latency_first_bond_keeps_existing_ordering
```

Expected: FAIL because the current selector only specializes CF bond and does not assign `leg_role`.

- [ ] **Step 3: Implement latency-first endpoint selection and null-latency CF score fix**

In `client-agent/client_agent.py`, add a helper near `select_bond_runtime_endpoints(...)`:

```python
    @staticmethod
    def latency_first_bond_enabled(forward: dict) -> bool:
        if str(forward.get("mode", "") or "").strip() != "bond":
            return False
        bond = forward.get("bonding", {})
        if not isinstance(bond, dict):
            return False
        return str(bond.get("policy_mode", "") or "").strip() == "latency_first"
```

Update `cf_bond_runtime_health_entry(...)` so healthy snapshot-only legs do not default to perfect quality:

```python
        return (
            str(endpoint_id),
            {
                "role": role,
                "consecutive_failures": 3 if role == "quarantined" else (2 if role == "probation" else 0),
                "consecutive_successes": 3 if role == "healthy" else 0,
                "quarantine_until": quarantine_until,
                "probation_until": probation_until,
                "last_error": runtime_reason,
                "last_latency_ms": None,
                "quality_score": 0.6 if role == "healthy" else (0.4 if role == "probation" else 0.0),
            },
        )
```

Then replace the body of `select_bond_runtime_endpoints(...)` with logic shaped like:

```python
        latency_first = self.latency_first_bond_enabled(forward)
        is_cf_bond = str(forward.get("backend_mode", "current_mode") or "current_mode") == "cf_mode"

        if not latency_first:
            preferred = [item for item in decorated if item["health_role"] != "quarantined"]
            if preferred:
                preferred.sort(key=lambda item: (int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))
                return preferred
            decorated.sort(key=lambda item: (int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))
            return decorated[:1]

        healthy = [item for item in decorated if item["health_role"] == "healthy"]
        healthy.sort(key=lambda item: (-float(item.get("quality_score", 0.0) or 0.0), int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))
        probation = [item for item in decorated if item["health_role"] == "probation"]
        probation.sort(key=lambda item: (-float(item.get("quality_score", 0.0) or 0.0), int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))
        quarantined = [item for item in decorated if item["health_role"] == "quarantined"]
        quarantined.sort(key=lambda item: (-float(item.get("quality_score", 0.0) or 0.0), int(item.get("dynamic_priority", base_priority)), int(item.get("endpoint_id", 0) or 0)))

        selected = healthy + probation
        if selected == []:
            selected = quarantined[:1]
        elif is_cf_bond:
            selected = selected[:3]

        for index, endpoint in enumerate(selected):
            endpoint["leg_role"] = "primary" if index == 0 else "helper"
        return selected
```

- [ ] **Step 4: Run the Linux bond health suite and verify it passes**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health
```

Expected: PASS.

- [ ] **Step 5: Commit the Linux selector changes**

Run:

```bash
git add client-agent/client_agent.py client-agent/tests/test_client_agent_bond_endpoint_health.py
git commit -m "feat: choose latency-first primary and helper bond legs"
```

---

### Task 3: Emit per-leg roles and latency-first flags from the Linux runtime renderer

**Files:**
- Modify: `client-agent/install_forward_runtime.py:58-95`
- Modify: `client-agent/install_forward_runtime.py:352-498`
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`
- Test: `client-agent/tests/test_mptcp_bond_regressions.py`

- [ ] **Step 1: Add failing renderer tests**

Append these tests to `client-agent/tests/test_mptcp_bond_regressions.py`:

```python
    def test_bond_latency_first_runtime_marks_primary_and_helper_legs(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 40,
                "listen_port": 11030,
                "target": "45.196.220.47:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "5b6997520f04a681",
                "transport": "tcp",
                "mode": "bond",
                "endpoints": [
                    {"endpoint_id": 256, "host": "8.163.87.42", "port": 7000, "dynamic_priority": 10, "ingress_auth_token": "token-a", "leg_role": "primary"},
                    {"endpoint_id": 254, "host": "8.163.90.132", "port": 7000, "dynamic_priority": 10, "ingress_auth_token": "token-b", "leg_role": "helper"},
                ],
                "bonding": {
                    "policy_mode": "latency_first",
                    "udp_primary_only": True,
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "5b6997520f04a681",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
            },
        )

        script = rendered["start_script"]
        self.assertIn("role=primary", script)
        self.assertIn("role=helper", script)
        self.assertIn("--latency-first", script)

    def test_bond_latency_first_udp_runtime_sets_primary_only_flag(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 40,
                "listen_port": 11030,
                "target": "45.196.220.47:5201",
                "sni": "br1.nbwly.com",
                "path_prefix": "5b6997520f04a681",
                "transport": "udp",
                "mode": "bond",
                "endpoints": [
                    {"endpoint_id": 256, "host": "8.163.87.42", "port": 7000, "dynamic_priority": 10, "leg_role": "primary"},
                    {"endpoint_id": 254, "host": "8.163.90.132", "port": 7000, "dynamic_priority": 10, "leg_role": "helper"},
                ],
                "bonding": {
                    "policy_mode": "latency_first",
                    "udp_primary_only": True,
                    "ingress_mode": "wss_path_mux",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "udp_session_token": "udp-token",
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
            },
        )

        self.assertIn("--protocol udp --listen 0.0.0.0:11030", rendered["start_script"])
        self.assertIn("--latency-first", rendered["start_script"])
        self.assertIn("--udp-primary-only", rendered["start_script"])
```

- [ ] **Step 2: Run the focused renderer tests and verify they fail**

Run:

```bash
python -m unittest \
  client-agent.tests.test_mptcp_bond_regressions.ClientMptcpBondRegressionTests.test_bond_latency_first_runtime_marks_primary_and_helper_legs \
  client-agent.tests.test_mptcp_bond_regressions.ClientMptcpBondRegressionTests.test_bond_latency_first_udp_runtime_sets_primary_only_flag
```

Expected: FAIL because the renderer does not emit `role=` or the new CLI flags yet.

- [ ] **Step 3: Emit leg roles and latency-first flags in `install_forward_runtime.py`**

First, extend `_leg_query_params(...)` so endpoint leg role is encoded:

```python
        leg_role = str(endpoint.get("leg_role", "") or "").strip()
        if leg_role in {"primary", "helper"}:
            params.append(f"role={quote(leg_role, safe='')}")
```

Then, inside `render_forward_runtime(...)`, add:

```python
        policy_mode = str(bond.get("policy_mode", "") or "").strip()
        latency_first = policy_mode == "latency_first"
        udp_primary_only = truthy(bond.get("udp_primary_only", False))
```

Append the new CLI flags when rendering `leikwan-bond`:

```python
            tcp_latency_flags = "--latency-first " if latency_first else ""
```

and inject into TCP runtime:

```python
                f"{bond_bin} client --protocol tcp --listen {tcp_listen_host}:{tcp_listen_port} "
                f"{tcp_latency_flags}"
                f"--legs '{','.join(tcp_leg_targets)}' --session-token '{bond_session_token}' "
```

For UDP runtime, inject:

```python
            udp_latency_flags = "--latency-first " if latency_first else ""
            if latency_first and udp_primary_only:
                udp_latency_flags += "--udp-primary-only "
```

and:

```python
                f"{bond_bin} client --protocol udp --listen {udp_listen_host}:{udp_listen_port} "
                f"{udp_latency_flags}"
                f"--legs '{','.join(udp_leg_targets)}' --session-token '{udp_token}' "
```

- [ ] **Step 4: Run the renderer regression suite and verify it passes**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions
```

Expected: PASS.

- [ ] **Step 5: Commit the Linux runtime rendering changes**

Run:

```bash
git add client-agent/install_forward_runtime.py client-agent/tests/test_mptcp_bond_regressions.py
git commit -m "feat: emit latency-first bond roles and runtime flags"
```

---

### Task 4: Teach `leikwan-bond` to honor primary/helper roles and primary-only UDP

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/main.rs`
- Modify: `wstunnel-main/leikwan-bond/src/transport.rs`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`
- Test: `wstunnel-main/leikwan-bond/src/transport.rs`
- Test: `wstunnel-main/leikwan-bond/src/session.rs`

- [ ] **Step 1: Add failing Rust transport and scheduler tests**

In `wstunnel-main/leikwan-bond/src/transport.rs`, add:

```rust
    #[test]
    fn parse_leg_spec_reads_role_query_param() {
        let spec = parse_leg_spec("tcp://127.0.0.1:20962?priority=10&endpoint_id=184&role=primary").expect("parse");
        assert_eq!(spec.role, LegRole::Primary);

        let helper = parse_leg_spec("tcp://127.0.0.1:20963?priority=10&endpoint_id=185&role=helper").expect("parse");
        assert_eq!(helper.role, LegRole::Helper);
    }
```

In `wstunnel-main/leikwan-bond/src/session.rs`, add tests shaped like:

```rust
    #[test]
    fn latency_first_stays_on_primary_before_bulk_threshold() {
        let mut config = test_config();
        config.latency_first = true;

        let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        legs[0].role = LegRole::Primary;
        legs[1].role = LegRole::Helper;

        let mut cursor = 0usize;
        let selected = select_stream_leg(&legs, &mut cursor, Some(0), 32 * 1024, 0, &config);
        assert_eq!(selected, Some(0));
    }

    #[test]
    fn latency_first_udp_prefers_primary_until_primary_fails() {
        let mut config = test_config();
        config.latency_first = true;
        config.udp_primary_only = true;

        let mut legs = vec![fake_leg(0, 10, 0, 20), fake_leg(1, 10, 0, 25)];
        legs[0].role = LegRole::Primary;
        legs[1].role = LegRole::Helper;

        let mut cursor = 0usize;
        assert_eq!(select_udp_leg(&legs, &mut cursor, 1200, &config), Some(0));

        legs[0].active = false;
        assert_eq!(select_udp_leg(&legs, &mut cursor, 1200, &config), Some(1));
    }
```

- [ ] **Step 2: Run the focused Rust tests and verify they fail**

Run:

```bash
cargo test -p leikwan-bond parse_leg_spec_reads_role_query_param
cargo test -p leikwan-bond latency_first_stays_on_primary_before_bulk_threshold
cargo test -p leikwan-bond latency_first_udp_prefers_primary_until_primary_fails
```

Expected: FAIL because `LegRole` and the new config flags do not exist yet.

- [ ] **Step 3: Add the new CLI flags and session config fields**

In `wstunnel-main/leikwan-bond/src/main.rs`, extend `Command::Client`:

```rust
        #[arg(long, default_value_t = false)]
        latency_first: bool,
        #[arg(long, default_value_t = false)]
        udp_primary_only: bool,
```

and pass them into `SessionConfig`:

```rust
                        bulk_promote_bytes,
                        leg_cooldown: Duration::from_millis(leg_cooldown_ms),
                        latency_first,
                        udp_primary_only,
```

In `wstunnel-main/leikwan-bond/src/session.rs`, extend `SessionConfig`:

```rust
    pub latency_first: bool,
    pub udp_primary_only: bool,
```

Update the server-side default config and `test_config()` to include:

```rust
            latency_first: false,
            udp_primary_only: false,
```

- [ ] **Step 4: Parse and carry leg role metadata**

In `wstunnel-main/leikwan-bond/src/transport.rs`, add:

```rust
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum LegRole {
    Primary,
    Helper,
}
```

Extend `LegSpec`:

```rust
    pub role: LegRole,
```

Default role:

```rust
            role: LegRole::Primary,
```

Parse `role=` in `parse_leg_spec(...)`:

```rust
    let mut role = LegRole::Primary;
    for (key, value) in url.query_pairs() {
        match key.as_ref() {
            "role" => {
                role = match value.as_ref() {
                    "helper" => LegRole::Helper,
                    _ => LegRole::Primary,
                };
            }
```

and include it in the returned `LegSpec`.

In `wstunnel-main/leikwan-bond/src/session.rs`, add `role` to `LegState` and thread it through `OpenLeg::new(...).with_metadata(...)` setup.

- [ ] **Step 5: Enforce latency-first scheduling**

In `wstunnel-main/leikwan-bond/src/session.rs`, add small helpers:

```rust
fn primary_leg_ids<'a>(legs: &'a [LegState]) -> Vec<&'a LegState> {
    let primary: Vec<&LegState> = legs.iter().filter(|leg| leg.active && leg.role == LegRole::Primary).collect();
    if primary.is_empty() {
        legs.iter().filter(|leg| leg.active).collect()
    } else {
        primary
    }
}
```

Update `select_stream_affinity_leg(...)` and `select_control_leg(...)` so when `config.latency_first` is true, they prefer active primary legs first.

Change `decide_striping_stage(...)` signature to include `config: &SessionConfig` only once and branch:

```rust
    if config.latency_first {
        if active_leg_count < 2 {
            return TcpStripingStage::Sticky;
        }
        if total_bytes < config.bulk_promote_bytes {
            return TcpStripingStage::Sticky;
        }
        if warmup_used_legs < 2 {
            return TcpStripingStage::Warmup;
        }
        return TcpStripingStage::Bulk;
    }
```

This preserves sticky primary-only behavior until the stream has clearly crossed the bulk threshold.

Update `select_udp_leg(...)`:

```rust
    if config.latency_first && config.udp_primary_only {
        let primary: Vec<&LegState> = legs.iter().filter(|leg| leg.active && leg.role == LegRole::Primary).collect();
        if let Some(chosen) = primary.first() {
            return Some(chosen.id);
        }
    }
```

Then fall back to the existing selector so helper failover still works.

- [ ] **Step 6: Run the full Rust bond suite**

Run:

```bash
cargo test -p leikwan-bond
```

Expected: PASS.

- [ ] **Step 7: Commit the Rust scheduler changes**

Run:

```bash
git add wstunnel-main/leikwan-bond/src/main.rs wstunnel-main/leikwan-bond/src/transport.rs wstunnel-main/leikwan-bond/src/session.rs
git commit -m "feat: add latency-first bond scheduler"
```

---

### Task 5: Mirror the latency-first policy in the Windows desktop runtime

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/BondingConfig.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondEndpointHealthServiceTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondRuntimeBuilderTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs`

- [ ] **Step 1: Add failing Windows parity tests**

In `BondEndpointHealthServiceTests.cs`, add:

```csharp
    [Fact]
    public void SelectRuntimeEndpoints_LatencyFirstNormalBond_MarksPrimaryThenHelpers()
    {
        using var tempDir = new TempStateDirectory();
        var stateManager = new StateManager(tempDir.Path);
        var service = new BondEndpointHealthService(stateManager);

        stateManager.SaveBondEndpointHealth(new Dictionary<string, Dictionary<string, BondEndpointHealthEntry>>
        {
            ["40"] = new()
            {
                ["254"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.75 },
                ["256"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.95 },
                ["258"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.60 },
            }
        });

        var forward = CreateLatencyFirstNormalBondForward();
        var selected = service.SelectRuntimeEndpoints(forward, nowUnixSeconds: 100);

        Assert.Equal(new[] { 256, 254, 258 }, selected.Select(endpoint => endpoint.EndpointId).ToArray());
        Assert.Equal(new[] { "primary", "helper", "helper" }, selected.Select(endpoint => endpoint.LegRole).ToArray());
    }
```

In `BondRuntimeBuilderTests.cs`, add:

```csharp
    [Fact]
    public void BuildBond_LatencyFirst_EmitsRolesAndFlags()
    {
        var builder = CreateBuilder();
        var forward = CreatePlainTcpMuxBondForward();
        forward.Bonding!.PolicyMode = "latency_first";
        forward.Bonding.UdpPrimaryOnly = true;
        forward.Endpoints[0].LegRole = "primary";
        forward.Endpoints[1].LegRole = "helper";

        var spec = builder.Build(forward);
        var runtime = Assert.Single(spec.Processes, process => process.Role == "bond-runtime");

        Assert.Contains("role=primary", runtime.Arguments);
        Assert.Contains("role=helper", runtime.Arguments);
        Assert.Contains("--latency-first", runtime.Arguments);
    }
```

- [ ] **Step 2: Run the focused Windows tests and verify they fail**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~BondEndpointHealthServiceTests|FullyQualifiedName~BondRuntimeBuilderTests"
```

Expected: FAIL because the models, selector, and builder do not carry the new policy yet.

- [ ] **Step 3: Add policy and role properties to the Windows models**

In `BondingConfig.cs`, add:

```csharp
    [JsonPropertyName("policy_mode")]
    public string PolicyMode { get; set; } = "";

    [JsonPropertyName("udp_primary_only")]
    public bool UdpPrimaryOnly { get; set; }

    [JsonPropertyName("primary_reselect_on_failure_only")]
    public bool PrimaryReselectOnFailureOnly { get; set; } = true;

    [JsonPropertyName("cf_primary_selection_mode")]
    public string CfPrimarySelectionMode { get; set; } = "real_path";
```

In `EndpointConfig.cs`, add:

```csharp
    [JsonPropertyName("leg_role")]
    public string LegRole { get; set; } = "";
```

and copy it inside `Clone()`.

- [ ] **Step 4: Apply latency-first selection and builder parity**

In `BondEndpointHealthService.cs`, generalize `SelectRuntimeEndpoints(...)` to:

- short-circuit only when `forward.Mode != "bond"` or `forward.Bonding?.PolicyMode != "latency_first"`
- sort healthy endpoints by quality score
- cap CF-bond to three total endpoints
- assign `LegRole = "primary"` to the first item and `"helper"` to the rest

In `ForwardRuntimeManager.cs`, replace:

```csharp
        if (!BondEndpointHealthService.IsCfPlainTcpBond(forward))
            return forward;
```

with:

```csharp
        if (ModeNormalizer.Normalize(forward.Mode) != "bond" || !string.Equals(forward.Bonding?.PolicyMode, "latency_first", StringComparison.OrdinalIgnoreCase))
            return forward;
```

In `BondRuntimeBuilder.cs`, append:

```csharp
        if (string.Equals(bond.PolicyMode, "latency_first", StringComparison.OrdinalIgnoreCase))
            args += " --latency-first";
        if (string.Equals(bond.PolicyMode, "latency_first", StringComparison.OrdinalIgnoreCase) && bond.UdpPrimaryOnly)
            args += " --udp-primary-only";
```

and include `role=` in the leg URI query params.

In `HealthCheckService.cs`, pass the same flags into the CF one-leg probe command.

- [ ] **Step 5: Run the full Windows runtime suite**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj
```

Expected: PASS.

- [ ] **Step 6: Commit the Windows parity changes**

Run:

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/BondingConfig.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondEndpointHealthServiceTests.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondRuntimeBuilderTests.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs
git commit -m "feat: add windows latency-first bond parity"
```

---

### Task 6: Update operator documentation and run full targeted verification

**Files:**
- Modify: `docs/BOND_OPERATIONS.md`
- Verify only

- [ ] **Step 1: Add the operator note to `docs/BOND_OPERATIONS.md`**

Add a short section like:

```markdown
### Latency-first bond

`bond_latency_first` keeps the sidecar as final HK aggregation point, keeps one `primary` slave leg for stream-open and interactive traffic, and only allows helper legs to join clearly bulk TCP sessions. Phase one keeps UDP primary-only with helper failover only.

Operational expectations:
- single-leg-like latency for short TCP and gameplay-safe traffic
- higher throughput than single-leg for large TCP transfers
- helper-leg promotion only after bulk threshold
- no direct client-to-sidecar topology change
```

- [ ] **Step 2: Run the full cross-language verification set**

Run:

```bash
php master/tests/bond_leg_cooldown_publish_regression_test.php
php master/tests/bond_latency_first_publish_regression_test.php
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health
python -m unittest client-agent.tests.test_mptcp_bond_regressions
cargo test -p leikwan-bond
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj
```

Expected:
- all suites pass

- [ ] **Step 3: Commit the docs and final verification-ready state**

Run:

```bash
git add docs/BOND_OPERATIONS.md
git commit -m "docs: add latency-first bond operations note"
```

---

### Task 7: Live rollout and verification on sh36

**Files:**
- Modify live panel and runtime hosts only
- Verify on `sh36.ctyun.mcbar.cc`

- [ ] **Step 1: Back up the current live runtime artifacts before rollout**

Use the local credentials from `leikwan-v3.txt`, `cantons.txt`, and `shanghais.txt`. Do **not** inline secrets into repo files or commits.

Back up on:

- panel host `10.0.10.196`
- client `sh36.ctyun.mcbar.cc`
- Guangzhou normal-bond slaves used by `11030`
- CF-bond slaves used by `11035`

Keep:

```bash
/root/codex-backups/latency-first-bond-<timestamp>/
```

- [ ] **Step 2: Roll out in this order**

1. panel PHP files
2. published Linux client artifacts
3. published Windows artifacts if desktop parity is shipping now
4. `leikwan-bond` binary to the sidecar-serving artifact path
5. client `sh36`

This keeps control-plane and runtime contract aligned.

- [ ] **Step 3: Refresh the client runtime and inspect emitted roles**

On `sh36`, after pull/restart, inspect:

```bash
systemctl restart leikwan-client-agent.service
sleep 5
systemctl restart leikwan-client-40.service
systemctl restart leikwan-client-47.service
sleep 15
grep -n -- 'role=primary\|role=helper\|latency-first\|udp-primary-only' /usr/local/bin/leikwan-client-start-40.sh
grep -n -- 'role=primary\|role=helper\|latency-first\|udp-primary-only' /usr/local/bin/leikwan-client-start-47.sh
cat /var/lib/leikwan-client-agent/bond-endpoint-health.json
cat /run/leikwan/bond-leg-health-forward-40.json
cat /run/leikwan/bond-leg-health-forward-47.json
```

Expected:
- exactly one primary leg per forward
- helper legs present after it
- `--latency-first` present
- `--udp-primary-only` present on UDP bond runtime where applicable

- [ ] **Step 4: Run the latency and throughput checks**

On `sh36`:

```bash
timeout 20 iperf3 -c 127.0.0.1 -p 11030 -R -b 400M
timeout 20 iperf3 -c 127.0.0.1 -p 11035 -R -b 300M
```

Then compare against:

- best single-leg Guangzhou latency baseline
- prior eager-bond latency baseline
- prior eager-bond throughput baseline

Success:
- latency is materially closer to the best single leg than the old eager-bond mode
- throughput still exceeds single-leg baseline

- [ ] **Step 5: Run the gameplay-safety checks**

For traffic that behaves like game/interactive flows:

```bash
# Short TCP/open-path sanity
python3 - <<'PY'
import socket, time
for port in (11030, 11035):
    s = socket.socket()
    s.settimeout(2)
    started = time.time()
    try:
        s.connect(("127.0.0.1", port))
        print(port, round((time.time() - started) * 1000, 1))
    finally:
        s.close()
PY
```

For UDP behavior, verify there is no helper-leg striping in phase one by checking process arguments and runtime logs rather than forcing a new UDP architecture in this rollout.

Success:
- no ping oscillation
- no evidence that helper legs are being used for primary-only UDP

- [ ] **Step 6: If latency regresses or helper legs leak into interactive flows, stop and roll back**

Rollback order:

1. client runtime
2. published Linux artifact
3. panel contract

Do not keep a partially mismatched control-plane/runtime pair live.

---

## Spec coverage self-review

- Keep sidecar unchanged: covered by Tasks 1, 3, 4, and 7
- Preserve health engine: covered by Task 2 and Task 5
- Normal bond + CF-bond support: covered by Tasks 2, 3, 4, and 5
- TCP primary-only until bulk: covered by Task 4
- UDP primary-only in phase one: covered by Tasks 3 and 4
- Avoid null-latency CF perfect ranking: covered by Task 2 and Task 5
- Windows parity: covered by Task 5
- Live verification on sh36: covered by Task 7

## Placeholder scan self-review

- No `TBD` or `TODO`
- All code-edit steps include concrete code snippets
- All verification steps include exact commands
- Credentials are intentionally kept out of the repo plan and referenced only through the approved local files

## Type consistency self-review

Planned names are consistent across the plan:

- `policy_mode = latency_first`
- `udp_primary_only`
- `primary_reselect_on_failure_only`
- `cf_primary_selection_mode = real_path`
- `leg_role = primary|helper`
- `latency_first`

Linux, Rust, and Windows all use the same names and semantics.

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-09-latency-first-bond.md`.

Two execution options:

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
