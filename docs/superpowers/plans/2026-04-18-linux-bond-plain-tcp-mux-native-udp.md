# Linux Bond Plain TCP Mux Native UDP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align the Linux `client -> slave -> sidecar` bond path with the approved native TCP/UDP design, including topology-aware recovery, published-vs-live slave verification, and automatic NTP assurance.

**Architecture:** Keep the current `client -> slave -> sidecar` topology and current panel contract, but make client health and runtime state transport-aware instead of port-only. Add explicit published-vs-live slave consistency checks, basic clock-skew reporting against panel time, and idempotent NTP bootstrap in the Linux installers without changing the slave ingress port range.

**Tech Stack:** Python 3 agents, Bash installers, PHP panel tests, systemd-managed Linux services, `ss`/`timedatectl`/HTTP `Date` header checks

---

### Task 1: Add Regression Coverage For Native Bond Health, Published-vs-Live Checks, And NTP Bootstrap

**Files:**
- Create: `master/tests/provisioning_bond_native_udp_regression_test.php`
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
- Modify: `client-agent/tests/test_client_agent_installer_helper.py`
- Modify: `client-agent/tests/test_sidecar_agent_update_helper.py`
- Create: `slave-agent/tests/test_slave_installer_helper.py`

- [ ] **Step 1: Write the failing panel regression**

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if (!is_string($source) || $source === '') {
    fail('failed to read ProvisioningService source');
}

$required = [
    "'session_token' =>",
    "'udp_session_token' =>",
    "'ingress_mode' => \$ingressMode",
    "'route_key' =>",
    "'sidecar_host' =>",
    "'sidecar_port' =>",
];

foreach ($required as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Write the failing client/installer tests**

```python
def test_collect_live_bond_slave_connections_prefers_established_panel_endpoints(self) -> None:
    with tempfile.TemporaryDirectory() as tmp_dir:
        agent = self.make_agent(tmp_dir)
        with mock.patch.object(
            client_agent_module.subprocess,
            "run",
            return_value=SimpleNamespace(
                stdout="ESTAB 0 0 172.21.255.189:20962 8.163.90.132:7001\nESTAB 0 0 172.21.255.189:20963 8.163.90.235:7001\n",
                stderr="",
                returncode=0,
            ),
        ):
            actual = agent.collect_live_bond_slave_connections(
                {"forward_id": 26, "endpoints": [{"endpoint_id": 184, "host": "8.163.90.132", "port": 7001}, {"endpoint_id": 185, "host": "8.163.90.235", "port": 7001}]}
            )
    self.assertEqual([item["endpoint_id"] for item in actual], [184, 185])

def test_install_client_agent_ensures_time_sync(self) -> None:
    source = (REPO_ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")
    self.assertIn("ensure_time_sync()", source)
    self.assertIn("timedatectl", source)
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health client-agent.tests.test_client_agent_installer_helper client-agent.tests.test_sidecar_agent_update_helper slave-agent.tests.test_slave_installer_helper`
Expected: FAIL because the helper methods and NTP installer logic do not exist yet.

Run: `php master/tests/provisioning_bond_native_udp_regression_test.php`
Expected: PASS or FAIL only after the file exists; if it passes immediately, keep it as the contract guard and move to the Python failures as the TDD driver.

- [ ] **Step 4: Commit**

```bash
git add master/tests/provisioning_bond_native_udp_regression_test.php client-agent/tests/test_client_agent_bond_endpoint_health.py client-agent/tests/test_client_agent_installer_helper.py client-agent/tests/test_sidecar_agent_update_helper.py slave-agent/tests/test_slave_installer_helper.py
git commit -m "test: add linux bond topology and ntp regressions"
```

### Task 2: Implement Client Topology Health, Published-vs-Live Mismatch Detection, And Clock-Skew Reporting

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`

- [ ] **Step 1: Add a failing test for mismatch-driven restart and clock-skew state**

```python
def test_ensure_runtime_health_restarts_on_published_runtime_mismatch(self) -> None:
    with tempfile.TemporaryDirectory() as tmp_dir:
        agent = self.make_agent(tmp_dir)
        forward = {
            "forward_id": 26,
            "service_status": "active",
            "listen_port": 11024,
            "mode": "bond",
            "transport": "tcp+udp",
            "path_prefix": "rk",
            "endpoints": [{"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "ingress_auth_token": "t"}],
            "bonding": {"ingress_mode": "plain_tcp_mux", "route_key": "rk"},
        }
        with mock.patch.object(agent, "systemd_is_active", return_value=True):
            with mock.patch.object(agent, "local_runtime_probe", return_value=(True, 1.0, None)):
                with mock.patch.object(agent, "remote_plain_tcp_ready_probe", return_value=(True, 1.0, None)):
                    with mock.patch.object(agent, "collect_live_bond_slave_connections", return_value=[]):
                        with mock.patch.object(agent, "restart_forward_service") as restart_mock:
                            agent.ensure_runtime_health([forward])
        restart_mock.assert_called_once_with(26, "leikwan-client-26", "published runtime mismatch: no live slave connections matched panel endpoints")
```

- [ ] **Step 2: Run the focused test to verify it fails**

Run: `python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_ensure_runtime_health_restarts_on_published_runtime_mismatch`
Expected: FAIL because `collect_live_bond_slave_connections(...)` and the mismatch branch do not exist.

- [ ] **Step 3: Implement the minimal client-agent helpers**

```python
def collect_live_bond_slave_connections(self, forward: dict) -> list[dict]:
    endpoints = {(str(ep.get("host", "")).strip(), int(ep.get("port", 0) or 0)): dict(ep) for ep in forward.get("endpoints", []) if isinstance(ep, dict)}
    result = subprocess.run("ss -tn state established", shell=True, text=True, capture_output=True, check=False)
    matches: list[dict] = []
    for line in result.stdout.splitlines():
        parts = line.split()
        if len(parts) < 5:
            continue
        remote = parts[-1]
        host, _, port_text = remote.rpartition(":")
        try:
            port = int(port_text)
        except ValueError:
            continue
        key = (host.strip("[]"), port)
        if key in endpoints:
            endpoint = dict(endpoints[key])
            endpoint["live_remote"] = remote
            matches.append(endpoint)
    return matches
```

```python
live_matches = self.collect_live_bond_slave_connections(forward) if plain_tcp_ingress and mode == "bond" else []
if plain_tcp_ingress and mode == "bond" and live_matches == []:
    self.restart_forward_service(forward_id, service_name, "published runtime mismatch: no live slave connections matched panel endpoints")
    continue
```

- [ ] **Step 4: Add clock-skew capture during sync**

```python
def panel_clock_state(self, master: str, token: str) -> dict:
    request = urllib.request.Request(f"{master}/api/client/heartbeat", headers={"Authorization": f"Bearer {token}"}, method="POST")
    return {"synchronized": None, "offset_seconds": None, "source": "deferred"}
```

Use the real HTTP response `Date` header if available from `http_json(...)`, and persist the result into `config_pull_state.json` as:

```python
"clock": {
    "panel_time": panel_time_iso,
    "offset_seconds": offset_seconds,
    "status": status,
}
```

- [ ] **Step 5: Run the updated client tests**

Run: `python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health -v`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add client-agent/client_agent.py client-agent/tests/test_client_agent_bond_endpoint_health.py
git commit -m "feat: add client bond topology mismatch detection"
```

### Task 3: Implement Sidecar/Slave Time-Sync Reporting And Installer NTP Assurance

**Files:**
- Modify: `sidecar-agent/sidecar_agent.py`
- Modify: `slave-agent/slave_agent.py`
- Modify: `install/install-client-agent.sh`
- Modify: `install/install-slave.sh`
- Modify: `install/install-bond-sidecar.sh`
- Modify: `client-agent/tests/test_client_agent_installer_helper.py`
- Modify: `client-agent/tests/test_sidecar_agent_update_helper.py`
- Create: `slave-agent/tests/test_slave_installer_helper.py`

- [ ] **Step 1: Write failing tests for time-sync bootstrap**

```python
def test_slave_installer_ensures_time_sync(self) -> None:
    source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")
    self.assertIn("ensure_time_sync()", source)
    self.assertIn("timedatectl show -p NTPSynchronized", source)
    self.assertIn("apt-get install -y ntp", source)
```

- [ ] **Step 2: Run the installer tests to verify they fail**

Run: `python -m unittest client-agent.tests.test_client_agent_installer_helper client-agent.tests.test_sidecar_agent_update_helper slave-agent.tests.test_slave_installer_helper -v`
Expected: FAIL because the installer helper functions do not exist yet.

- [ ] **Step 3: Implement the shared installer pattern**

```bash
ensure_time_sync() {
  if command -v timedatectl >/dev/null 2>&1; then
    if timedatectl show -p NTPSynchronized --value 2>/dev/null | grep -qi '^yes$'; then
      return 0
    fi
  fi
  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y ntp
    systemctl enable ntp.service || true
    systemctl restart ntp.service || true
  fi
}
```

Call `ensure_time_sync` before downloading artifacts in all three installers.

- [ ] **Step 4: Add runtime reporting to slave and sidecar**

Persist basic time-sync state into heartbeat/pull-state payloads:

```python
"clock": {
    "synchronized": synchronized,
    "service": service_name,
}
```

Keep this lightweight: installer guarantees NTP; runtime only reports current status for diagnostics.

- [ ] **Step 5: Run the relevant tests**

Run: `python -m unittest client-agent.tests.test_sidecar_agent_update_helper slave-agent.tests.test_slave_installer_helper -v`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add sidecar-agent/sidecar_agent.py slave-agent/slave_agent.py install/install-client-agent.sh install/install-slave.sh install/install-bond-sidecar.sh client-agent/tests/test_client_agent_installer_helper.py client-agent/tests/test_sidecar_agent_update_helper.py slave-agent/tests/test_slave_installer_helper.py
git commit -m "feat: add linux ntp assurance and clock reporting"
```

### Task 4: Verify Locally, Then Sync And Validate Live

**Files:**
- Modify: `task_plan.md`
- Modify: `findings.md`
- Modify: `progress.md`

- [ ] **Step 1: Run the local regression set**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health client-agent.tests.test_client_agent_installer_helper client-agent.tests.test_sidecar_agent_update_helper client-agent.tests.test_mptcp_bond_regressions slave-agent.tests.test_slave_plain_tcp_mux slave-agent.tests.test_slave_installer_helper
php master/tests/provisioning_plain_tcp_ingress_regression_test.php
php master/tests/provisioning_bond_native_udp_regression_test.php
```

Expected: all tests pass.

- [ ] **Step 2: Sync versions to panel/client/slave/sidecar**

Use the existing local credential files and WSL-first SSH path to upload only the touched Linux panel/client/slave/sidecar files and restart the relevant services.

- [ ] **Step 3: Prove published-vs-live slave consistency before throughput tests**

Collect and compare:

```bash
ss -tnp | grep ':7001'
ss -tnp | grep ':9000'
```

And the currently published endpoint set from panel for the target forward.

- [ ] **Step 4: Run live validation**

Run on client:

```bash
iperf3 -c 127.0.0.1 -p 11024 -R -b 400M
iperf3 -c 127.0.0.1 -p 11024 -R -b 400M -u
```

Record:

- whether panel-published and live slave sets matched
- whether TCP listener and UDP listener both stayed healthy
- whether any automatic recovery fired

- [ ] **Step 5: Commit**

```bash
git add task_plan.md findings.md progress.md
git commit -m "docs: record linux bond native udp verification"
```
