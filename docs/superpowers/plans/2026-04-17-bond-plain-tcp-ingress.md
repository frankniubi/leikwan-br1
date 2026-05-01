# Bond Plain TCP Ingress Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace only the `client -> slave` WSS ingress used by `bond` mode with a transparent shared-port plain TCP ingress, while preserving existing `leikwan-bond` TCP/UDP semantics and the current `slave -> sidecar` per-leg forwarding model.

**Architecture:** Add a new control-plane flag `bond_ingress_mode=plain_tcp_mux`. In that mode, `client-agent` swaps each per-leg `wstunnel client` process for a new `leikwan-ingress client` helper that opens a cleartext, HMAC-authenticated TCP session to a shared slave mux on port `7001`. The slave mux validates a route key plus auth token, supports an explicit `PING` health probe, and then bridges bytes to a local `leikwan-ingress backend` service that opens one raw TCP connection to the sidecar target per leg. `leikwan-bond` remains the session protocol for both TCP and UDP, so UDP continues to ride inside the existing bond datagram framing over reliable TCP legs.

**Tech Stack:** PHP 8 panel, Python client/slave agents, Rust Tokio helper binary, existing `leikwan-bond` crate

---

## Scope Guardrails

- This prototype is transparent plain TCP, not a disguise or anti-detection transport.
- Only the `client -> slave` ingress layer changes.
- `slave -> sidecar` stays one outbound TCP connection per leg so existing leg-level bandwidth aggregation behavior is preserved.
- `leikwan-bond` stays the transport/session layer for both `--protocol tcp` and `--protocol udp`.
- Prototype panel scope is control-plane/API/downlink support. UI toggles can follow later if needed.

## File Map

- Modify: `master/migrations/schema.sql`
- Create: `master/migrations/20260417_add_bond_ingress_mode.sql`
- Modify: `master/src/Support/ProvisioningService.php`
- Test: `master/tests/provisioning_plain_tcp_ingress_regression_test.php`
- Create: `wstunnel-main/leikwan-ingress/Cargo.toml`
- Create: `wstunnel-main/leikwan-ingress/src/lib.rs`
- Create: `wstunnel-main/leikwan-ingress/src/main.rs`
- Create: `wstunnel-main/leikwan-ingress/src/protocol.rs`
- Create: `wstunnel-main/leikwan-ingress/src/client.rs`
- Create: `wstunnel-main/leikwan-ingress/src/mux.rs`
- Create: `wstunnel-main/leikwan-ingress/src/backend.rs`
- Test: `wstunnel-main/leikwan-ingress/tests/protocol.rs`
- Modify: `wstunnel-main/Cargo.toml`
- Modify: `slave-agent/slave_agent.py`
- Test: `slave-agent/tests/test_slave_plain_tcp_mux.py`
- Modify: `slave-agent/tests/test_slave_path_prefix_cleanup.py`
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/client_agent.py`
- Test: `client-agent/tests/test_mptcp_bond_regressions.py`
- Modify: `install/install-client-agent.sh`
- Modify: `install/install-slave.sh`
- Modify: `artifacts/versions.json`
- Modify: `docs/DEPLOY.md`

### Task 1: Add Control-Plane Support For `plain_tcp_mux`

**Files:**
- Modify: `master/migrations/schema.sql`
- Create: `master/migrations/20260417_add_bond_ingress_mode.sql`
- Modify: `master/src/Support/ProvisioningService.php`
- Test: `master/tests/provisioning_plain_tcp_ingress_regression_test.php`

- [ ] **Step 1: Write the failing PHP regression first**

Create `master/tests/provisioning_plain_tcp_ingress_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$path = __DIR__ . '/../src/Support/ProvisioningService.php';
$source = file_get_contents($path);
if (!is_string($source) || $source === '') {
    fail('failed to read ProvisioningService source');
}

$requiredSnippets = [
    'private function normalizeBondIngressMode(string $value): string',
    "'bond_ingress_mode' =>",
    "'ingress_mode' =>",
    "'plain_tcp_mux'",
    "'route_key' =>",
    "'backend_protocol' =>",
    "'ingress_protocol' =>",
    "'ingress_auth_token' =>",
];

foreach ($requiredSnippets as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('expected ProvisioningService to contain snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php master/tests/provisioning_plain_tcp_ingress_regression_test.php`

Expected: fail because `bond_ingress_mode`, `ingress_mode`, `route_key`, `backend_protocol`, `ingress_protocol`, and `ingress_auth_token` do not exist yet.

- [ ] **Step 3: Add the schema field**

Create `master/migrations/20260417_add_bond_ingress_mode.sql`:

```sql
ALTER TABLE forwards
    ADD COLUMN bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'wss_path_mux' AFTER bond_remote_port;
```

Update `master/migrations/schema.sql`:

```sql
bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'wss_path_mux',
```

- [ ] **Step 4: Persist and normalize the new mode**

In `master/src/Support/ProvisioningService.php`, add:

```php
private function normalizeBondIngressMode(string $value): string
{
    return match (strtolower(trim($value))) {
        'plain_tcp_mux', 'plain-tcp-mux', 'plain_tcp', 'tcp_mux' => 'plain_tcp_mux',
        default => 'wss_path_mux',
    };
}
```

Then in `saveForward(...)`:

```php
$bondIngressMode = $this->normalizeBondIngressMode((string) ($payload['bond_ingress_mode'] ?? 'wss_path_mux'));
```

And include it in insert/update payloads:

```php
'bond_ingress_mode' => $bondIngressMode,
```

- [ ] **Step 5: Emit route key and ingress token**

In `buildClientConfig(...)`, extend bond downlink generation:

```php
$bondIngressMode = $this->normalizeBondIngressMode((string) ($forward['bond_ingress_mode'] ?? 'wss_path_mux'));
$routeKey = (string) ($forward['path_prefix'] ?? '');

$bonding['ingress_mode'] = $bondIngressMode;
$bonding['route_key'] = $routeKey;

if ($clientMode === 'bond' && $bondIngressMode === 'plain_tcp_mux') {
    $endpoint['ingress_protocol'] = 'plain_tcp_mux';
    $endpoint['ingress_auth_token'] = $this->bondIngressAuthToken($forward, (int) $endpoint['id'], $routeKey);
}
```

In `publishTaskPayloadForForward(...)`, add:

```php
'route_key' => (string) ($forward['path_prefix'] ?? ''),
'ingress_protocol' => $clientMode === 'bond' && $bondIngressMode === 'plain_tcp_mux' ? 'plain_tcp_mux' : 'wss_path_mux',
'backend_protocol' => $clientMode === 'bond' && $bondIngressMode === 'plain_tcp_mux' ? 'tcp_relay' : 'wss_backend',
'ingress_auth_token' => $clientMode === 'bond' && $bondIngressMode === 'plain_tcp_mux'
    ? $this->bondIngressAuthToken($forward, $endpointId, $routeKey)
    : '',
```

And add:

```php
private function bondIngressAuthToken(array $forward, int $endpointId, string $routeKey): string
{
    $serviceToken = trim((string) ($forward['client_token'] ?? ''));
    if ($serviceToken === '') {
        $serviceId = (int) ($forward['service_id'] ?? 0);
        if ($serviceId > 0) {
            $service = $this->db->fetchOne('SELECT client_token FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
            $serviceToken = trim((string) ($service['client_token'] ?? ''));
        }
    }
    if ($serviceToken === '') {
        $serviceToken = trim((string) ($this->config->get('BOND_TOKEN_SECRET', 'bond-default') ?? 'bond-default'));
    }

    return hash_hmac('sha256', 'bond-ingress:' . $endpointId . ':' . $routeKey, $serviceToken);
}
```

- [ ] **Step 6: Verify GREEN**

Run:

```bash
php master/tests/provisioning_plain_tcp_ingress_regression_test.php
php -l master/src/Support/ProvisioningService.php
```

Expected: both pass.

### Task 2: Add The `leikwan-ingress` Helper Crate

**Files:**
- Create: `wstunnel-main/leikwan-ingress/Cargo.toml`
- Create: `wstunnel-main/leikwan-ingress/src/lib.rs`
- Create: `wstunnel-main/leikwan-ingress/src/main.rs`
- Create: `wstunnel-main/leikwan-ingress/src/protocol.rs`
- Create: `wstunnel-main/leikwan-ingress/src/client.rs`
- Create: `wstunnel-main/leikwan-ingress/src/mux.rs`
- Create: `wstunnel-main/leikwan-ingress/src/backend.rs`
- Test: `wstunnel-main/leikwan-ingress/tests/protocol.rs`
- Modify: `wstunnel-main/Cargo.toml`

- [ ] **Step 1: Write the failing Rust protocol tests**

Create `wstunnel-main/leikwan-ingress/tests/protocol.rs`:

```rust
use leikwan_ingress::protocol::{Command, parse_client_line};

#[test]
fn parses_open_command() {
    let parsed = parse_client_line("LEIKWAN-INGRESS 1 OPEN a43547b6350f72cd token-1\n").unwrap();
    assert_eq!(parsed.command, Command::Open);
    assert_eq!(parsed.route_key, "a43547b6350f72cd");
    assert_eq!(parsed.auth_token, "token-1");
}

#[test]
fn parses_ping_command() {
    let parsed = parse_client_line("LEIKWAN-INGRESS 1 PING a43547b6350f72cd token-1\n").unwrap();
    assert_eq!(parsed.command, Command::Ping);
    assert_eq!(parsed.route_key, "a43547b6350f72cd");
}
```

- [ ] **Step 2: Run the crate tests and verify RED**

Run: `cargo test -p leikwan-ingress`

Expected: fail because the crate does not exist yet.

- [ ] **Step 3: Add the crate and workspace entry**

Create `wstunnel-main/leikwan-ingress/Cargo.toml`:

```toml
[package]
name = "leikwan-ingress"
version = "0.1.0"
edition = "2021"

[dependencies]
anyhow = "1"
clap = { version = "4", features = ["derive"] }
serde = { version = "1", features = ["derive"] }
serde_json = "1"
tokio = { version = "1", features = ["full"] }
tracing = "0.1"
tracing-subscriber = { version = "0.3", features = ["fmt", "env-filter"] }
```

Update `wstunnel-main/Cargo.toml`:

```toml
members = ["leikwan-bond", "leikwan-ingress", "leikwan-mptcp-relay", "leikwan-client-win/src-tauri"]
```

- [ ] **Step 4: Implement the minimal protocol and subcommands**

Implement:

- `protocol.rs`
  - `Command::Open`
  - `Command::Ping`
  - `parse_client_line(...)`
  - `build_client_line(...)`
- `client.rs`
  - `run_client(...)`
  - `run_probe(...)`
- `mux.rs`
  - `run_mux(...)`
  - route file loading
  - `OPEN` auth check
  - `PING` returns `OK\n`
  - raw TCP bridging to backend
- `backend.rs`
  - `run_backend(...)`
  - one inbound connection creates one outbound sidecar TCP connection

Runtime contracts:

```text
leikwan-ingress client --listen 127.0.0.1:20962 --remote 8.163.90.132:7001 --route-key a43547b6350f72cd --auth-token token
leikwan-ingress probe --remote 8.163.90.132:7001 --route-key a43547b6350f72cd --auth-token token
leikwan-ingress mux --listen [::]:7001 --routes-file /var/lib/leikwan-slave/listeners/routes-7001.json
leikwan-ingress backend --listen 127.0.0.1:6002 --target 138.252.162.120:9000
```

- [ ] **Step 5: Verify GREEN**

Run:

```bash
cargo test -p leikwan-ingress
cargo build --release -p leikwan-ingress
```

Expected: tests pass and `target/release/leikwan-ingress` exists.

### Task 3: Integrate Slave-Side Plain TCP Mux And Backend

**Files:**
- Modify: `slave-agent/slave_agent.py`
- Test: `slave-agent/tests/test_slave_plain_tcp_mux.py`
- Modify: `slave-agent/tests/test_slave_path_prefix_cleanup.py`

- [ ] **Step 1: Write failing slave tests**

Create `slave-agent/tests/test_slave_plain_tcp_mux.py` with:

```python
def test_listener_routes_include_plain_tcp_fields(self): ...
def test_publish_task_uses_plain_tcp_backend_unit(self): ...
```

Extend `slave-agent/tests/test_slave_path_prefix_cleanup.py` metadata with:

```python
{
    "route_key": "a43547b6350f72cd",
    "ingress_protocol": "plain_tcp_mux",
    "ingress_auth_token": "token-123",
    "backend_protocol": "tcp_relay",
}
```

- [ ] **Step 2: Run the slave tests and verify RED**

Run:

```bash
python -m unittest slave-agent/tests/test_slave_plain_tcp_mux.py
python -m unittest slave-agent/tests/test_slave_path_prefix_cleanup.py
```

Expected: fail because route file fields and plain backend unit do not exist yet.

- [ ] **Step 3: Extend metadata, routes, and service selection**

In `publish_task(...)`, persist:

```python
"route_key": route_key,
"ingress_protocol": ingress_protocol,
"backend_protocol": backend_protocol,
"ingress_auth_token": ingress_auth_token,
```

In `listener_routes(...)`, emit:

```python
{
    "path_prefix": ...,
    "route_key": ...,
    "backend_host": ...,
    "backend_port": ...,
    "service_name": ...,
    "ingress_protocol": ...,
    "ingress_auth_token": ...,
}
```

Add:

```python
def plain_tcp_mux_service_name(listen_port: int) -> str:
    return f"leikwan-tcp-mux-{listen_port}"
```

Add:

```python
def render_plain_tcp_backend_unit(...): ...
def render_plain_tcp_mux_unit(...): ...
```

And switch:

- `backend_protocol == "tcp_relay"` to `render_plain_tcp_backend_unit(...)`
- any listener with `ingress_protocol == "plain_tcp_mux"` to `render_plain_tcp_mux_unit(...)`

- [ ] **Step 4: Verify GREEN**

Run:

```bash
python -m unittest slave-agent/tests/test_slave_plain_tcp_mux.py
python -m unittest slave-agent/tests/test_slave_path_prefix_cleanup.py
python -m py_compile slave-agent/slave_agent.py
```

Expected: all pass.

### Task 4: Switch Bond Runtime Rendering And Health Probes On The Client

**Files:**
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/client_agent.py`
- Test: `client-agent/tests/test_mptcp_bond_regressions.py`

- [ ] **Step 1: Write failing client tests**

Extend `client-agent/tests/test_mptcp_bond_regressions.py` with:

```python
def test_bond_plain_tcp_ingress_uses_leikwan_ingress_client(self): ...
def test_remote_plain_tcp_ready_probe_uses_ping(self): ...
```

- [ ] **Step 2: Run the tests and verify RED**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_plain_tcp_ingress_uses_leikwan_ingress_client
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_remote_plain_tcp_ready_probe_uses_ping
```

Expected: fail because bond mode still renders `wstunnel client` and no plain probe helper exists.

- [ ] **Step 3: Add plain TCP ingress rendering**

In `install_forward_runtime.py`, inside bond mode:

```python
bond_ingress_bin = str(agent_config.get("bond_ingress_bin", "/usr/local/bin/leikwan-ingress")).strip() or "/usr/local/bin/leikwan-ingress"
ingress_mode = str(bond.get("ingress_mode", "wss_path_mux")).strip() or "wss_path_mux"
route_key = str(bond.get("route_key", path_prefix)).strip() or path_prefix
ingress_auth_token = str(endpoint.get("ingress_auth_token", "") or bond.get("ingress_auth_token", "")).strip()
```

For each leg:

```python
if ingress_mode == "plain_tcp_mux":
    lines.append(
        f"{bond_ingress_bin} client --listen 127.0.0.1:{tcp_back} "
        f"--remote {host}:{port} --route-key {route_key} --auth-token {ingress_auth_token} &"
    )
else:
    # existing wstunnel path
```

Do the same for UDP legs. Keep `leikwan-bond` above it unchanged.

- [ ] **Step 4: Add a plain TCP probe**

In `client_agent.py`, add:

```python
@classmethod
def remote_plain_tcp_ready_probe(cls, host: str, port: int, route_key: str, ingress_auth_token: str, timeout: float = 3.0):
    with socket.create_connection((host, port), timeout=timeout) as sock:
        sock.settimeout(timeout)
        payload = f"LEIKWAN-INGRESS 1 PING {route_key} {ingress_auth_token}\n"
        sock.sendall(payload.encode("utf-8"))
        response = sock.recv(64)
    first_line = response.decode("utf-8", errors="ignore").strip()
    if first_line == "OK":
        return True, ..., None
    return False, None, first_line or "empty_plain_tcp_probe_response"
```

Dispatch it in:

- `ensure_runtime_health(...)`
- `run_diagnostics_if_requested(...)`

when:

```python
mode == "bond" and bonding.get("ingress_mode") == "plain_tcp_mux"
```

- [ ] **Step 5: Verify GREEN**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_plain_tcp_ingress_uses_leikwan_ingress_client
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_remote_plain_tcp_ready_probe_uses_ping
python -m py_compile client-agent/install_forward_runtime.py client-agent/client_agent.py
```

Expected: all pass.

### Task 5: Ship The Binary And Installer Wiring

**Files:**
- Modify: `install/install-client-agent.sh`
- Modify: `install/install-slave.sh`
- Modify: `artifacts/versions.json`
- Modify: `docs/DEPLOY.md`

- [ ] **Step 1: Update installers**

In `install/install-client-agent.sh`:

```bash
download "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-ingress_linux_amd64" "$BIN_DIR/leikwan-ingress"
```

And config:

```json
"bond_ingress_bin": "/usr/local/bin/leikwan-ingress"
```

In `install/install-slave.sh`:

```bash
curl -fsSL "$MASTER_URL/artifacts/wstunnel/leikwan-ingress_linux_amd64" -o "$BIN_DIR/leikwan-ingress"
chmod 0755 "$BIN_DIR/leikwan-ingress"
```

And config:

```json
"ingress_bin": "/usr/local/bin/leikwan-ingress"
```

- [ ] **Step 2: Update the versions manifest**

In `artifacts/versions.json` add:

```json
"leikwan_ingress_linux_amd64": {
  "version": "0.1.0",
  "path": "artifacts/wstunnel/leikwan-ingress_linux_amd64",
  "artifact_url": "/artifacts/wstunnel/leikwan-ingress_linux_amd64"
}
```

And bump installer versions to `2026.04.17.1`.

- [ ] **Step 3: Update deploy docs**

In `docs/DEPLOY.md` add:

```md
## Bond plain TCP ingress prototype

- bond forwards using `bond_ingress_mode=plain_tcp_mux` should expose the slave ingress on a dedicated public port such as `7001`
- `client -> slave` uses a transparent shared-port TCP mux driven by `leikwan-ingress`
- health checks for this mode use the same TCP ingress path with a `PING` command, not `/_leikwan/readyz/...`
- `slave -> sidecar` remains one raw TCP connection per accepted bond leg
```

- [ ] **Step 4: Verify**

Run:

```bash
php master/tests/provisioning_plain_tcp_ingress_regression_test.php
python -m unittest slave-agent/tests/test_slave_plain_tcp_mux.py
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_plain_tcp_ingress_uses_leikwan_ingress_client client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_remote_plain_tcp_ready_probe_uses_ping
cargo test -p leikwan-ingress
cargo build --release -p leikwan-ingress
```

Expected: all pass.

### Task 6: Deployment And Live Validation

**Files:**
- Modify: none
- Test: live environment only

- [ ] **Step 1: Build and stage artifact**

Run in WSL:

```bash
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/wstunnel-main
cargo build --release -p leikwan-ingress
cp target/release/leikwan-ingress /mnt/c/Users/frankniubi/Downloads/leikwan-br1/artifacts/wstunnel/leikwan-ingress_linux_amd64
```

- [ ] **Step 2: Push panel files**

Copy to panel host:

- `master/src/Support/ProvisioningService.php`
- `master/migrations/schema.sql`
- `master/migrations/20260417_add_bond_ingress_mode.sql`
- `client-agent/install_forward_runtime.py`
- `client-agent/client_agent.py`
- `slave-agent/slave_agent.py`
- `install/install-client-agent.sh`
- `install/install-slave.sh`
- `artifacts/versions.json`
- `docs/DEPLOY.md`
- `artifacts/wstunnel/leikwan-ingress_linux_amd64`

- [ ] **Step 3: Push runtime files to target machines**

To the 5 Guangzhou slaves:

- `/usr/local/bin/leikwan-slave-agent.py`
- `/usr/local/bin/leikwan-ingress`

To the client:

- `/usr/local/bin/leikwan-client-agent.py`
- `/usr/local/bin/install_forward_runtime.py`
- `/usr/local/bin/leikwan-ingress`

- [ ] **Step 4: Switch one live forward**

Update forward `26` to:

```json
{
  "client_mode": "bond",
  "bond_ingress_mode": "plain_tcp_mux",
  "public_port": 7001
}
```

- [ ] **Step 5: Verify ingress**

On client:

```bash
/usr/local/bin/leikwan-ingress probe --remote 8.163.90.132:7001 --route-key a43547b6350f72cd --auth-token <token>
```

Repeat for all 5 slave IPs.

Expected: all return success.

- [ ] **Step 6: Verify runtime convergence**

On client:

```bash
grep -n "leikwan-ingress client" /usr/local/bin/leikwan-client-start-26.sh
systemctl status leikwan-client-26 --no-pager -n 25
```

On one slave:

```bash
cat /var/lib/leikwan-slave/services/wstunnel-svc-1-184.json
cat /var/lib/leikwan-slave/listeners/routes-7001.json
systemctl status leikwan-tcp-mux-7001 --no-pager -n 10
```

- [ ] **Step 7: Verify end-to-end**

On client:

```bash
timeout 15s iperf3 -c 127.0.0.1 -p 11024 -t 5
journalctl -u leikwan-client-26 --no-pager -n 120 --since "3 min ago"
```

Success criteria:

- plain TCP ingress probes all pass
- client launches `leikwan-ingress` for all bond legs
- at least one bond leg acknowledges and joins
- remaining failures, if any, are now downstream of the replaced ingress layer

## GSTACK REVIEW REPORT

| Review | Trigger | Why | Runs | Status | Findings |
|--------|---------|-----|------|--------|----------|
| CEO Review | `/plan-ceo-review` | Scope & strategy | 1 | ISSUES OPEN | mode: SELECTIVE_EXPANSION, 3 critical gaps |
| Codex Review | `/codex review` | Independent 2nd opinion | 0 | - | - |
| Eng Review | `/plan-eng-review` | Architecture & tests (required) | 0 | - | not run yet |
| Design Review | `/plan-design-review` | UI/UX gaps | 0 | - | not needed for current scope |
| DX Review | `/plan-devex-review` | Developer experience gaps | 0 | - | not needed for current scope |

**CODEX:** The repo already contains substantial `plain_tcp_mux` implementation, so this plan is partly stale and should be converted from "greenfield build" to "gap-closing rollout + verification."

**UNRESOLVED:** 3

**VERDICT:** CEO review says keep the bond plain-TCP direction, but split MPTCP into a separate experimental track, require a fresh `/plan-eng-review`, and do not trust single-host lab throughput as production proof.
