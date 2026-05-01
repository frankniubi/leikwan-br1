# Plain TCP Mux Hotfix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the existing `plain_tcp_mux` implementation so bond mode can listen reliably on real slave IPv4 hosts and auto-roll out the updated client/slave agents.

**Architecture:** Keep the current `client -> slave -> sidecar` topology and the existing `leikwan-ingress` protocol. Harden the slave mux to bind all configured listen hosts instead of hardcoding a single IPv6 wildcard, then bump artifact versions so panels can actually drive the updated agent rollout.

**Tech Stack:** Python client/slave agents, Rust Tokio ingress helper, PHP artifact metadata

---

## Scope

- Do not change `leikwan-bond` framing or the `plain_tcp_mux` wire format.
- Do not change sidecar topology.
- Do not attempt to fix MPTCP in this pass.
- Only touch the files needed to make `plain_tcp_mux` reachable and deployable.

## Root Cause Hypothesis

The repo already contains `plain_tcp_mux`, but it still has two rollout blockers:

1. The slave unit generator hardcodes `leikwan-ingress mux --listen [::]:PORT`, ignoring the existing `mux_listen_hosts` config. That makes public IPv4 reachability depend on host dual-stack behavior.
2. The panel artifact manifest does not clearly advertise the currently edited client/slave agent generations for this rollout, so updated hosts may not converge onto the code that knows about `plain_tcp_mux`.

## File Map

- Modify: `slave-agent/slave_agent.py`
- Modify: `slave-agent/tests/test_slave_plain_tcp_mux.py`
- Modify: `wstunnel-main/leikwan-ingress/src/main.rs`
- Modify: `wstunnel-main/leikwan-ingress/src/mux.rs`
- Modify: `client-agent/client_agent.py`
- Modify: `artifacts/versions.json`
- Test: `master/tests/plain_tcp_mux_rollout_versions_regression_test.php`

### Task 1: Lock The Hotfix With Failing Tests

**Files:**
- Modify: `slave-agent/tests/test_slave_plain_tcp_mux.py`
- Test: `master/tests/plain_tcp_mux_rollout_versions_regression_test.php`

- [ ] **Step 1: Extend the slave test with a listen-host regression**

Add a test that proves the generated plain-TCP mux unit includes both configured listen hosts:

```python
def test_plain_tcp_mux_unit_uses_all_configured_listen_hosts(self) -> None:
    agent = self.make_agent()
    agent.config["mux_listen_hosts"] = ["::", "0.0.0.0"]

    unit_body = agent.render_plain_tcp_mux_unit(
        "leikwan-tcp-mux-7001",
        7001,
        agent.listener_routes_path(7001),
    )

    self.assertIn("--listen [::]:7001", unit_body)
    self.assertIn("--listen 0.0.0.0:7001", unit_body)
```

- [ ] **Step 2: Add a manifest/version regression**

Create `master/tests/plain_tcp_mux_rollout_versions_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$clientSource = file_get_contents(__DIR__ . '/../src/../../client-agent/client_agent.py');
$slaveSource = file_get_contents(__DIR__ . '/../src/../../slave-agent/slave_agent.py');
$versions = json_decode(file_get_contents(__DIR__ . '/../../artifacts/versions.json'), true);

if (!is_string($clientSource) || !preg_match('/AGENT_VERSION = "([^"]+)"/', $clientSource, $clientMatch)) {
    fail('failed to read client agent version');
}
if (!is_string($slaveSource) || !preg_match('/AGENT_VERSION = "([^"]+)"/', $slaveSource, $slaveMatch)) {
    fail('failed to read slave agent version');
}
if (!is_array($versions)) {
    fail('failed to parse versions manifest');
}
if (($versions['client_agent']['version'] ?? null) !== $clientMatch[1]) {
    fail('client_agent.version must match client_agent.py AGENT_VERSION');
}
if (($versions['slave_agent']['version'] ?? null) !== $slaveMatch[1]) {
    fail('slave_agent.version must match slave_agent.py AGENT_VERSION');
}

echo "ok\n";
```

- [ ] **Step 3: Run tests and verify RED**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_plain_tcp_mux
php master/tests/plain_tcp_mux_rollout_versions_regression_test.php
```

Expected:
- the slave test fails because the unit only emits one `--listen`
- the PHP regression fails if artifact metadata is stale

### Task 2: Make `leikwan-ingress mux` Honor Multiple Listen Hosts

**Files:**
- Modify: `wstunnel-main/leikwan-ingress/src/main.rs`
- Modify: `wstunnel-main/leikwan-ingress/src/mux.rs`
- Modify: `slave-agent/slave_agent.py`

- [ ] **Step 1: Teach the Rust CLI to accept repeated `--listen`**

Change the `Mux` command in `wstunnel-main/leikwan-ingress/src/main.rs` from:

```rust
    Mux {
        #[arg(long)]
        listen: String,
        #[arg(long = "routes-file")]
        routes_file: PathBuf,
    },
```

to:

```rust
    Mux {
        #[arg(long, required = true)]
        listen: Vec<String>,
        #[arg(long = "routes-file")]
        routes_file: PathBuf,
    },
```

and dispatch with:

```rust
        Commands::Mux { listen, routes_file } => leikwan_ingress::mux::run_mux(&listen, &routes_file).await,
```

- [ ] **Step 2: Update the Rust mux runtime to bind all requested listeners**

Replace the single-listener entry point in `wstunnel-main/leikwan-ingress/src/mux.rs` with one that:

- accepts `&[String]`
- binds each listen address
- keeps already-bound listeners alive if a later bind fails
- errors only if no listener bound at all

Core shape:

```rust
pub async fn run_mux(listens: &[String], routes_file: &Path) -> Result<()> {
    let mut listeners = Vec::new();
    for listen in listens {
        match TcpListener::bind(listen).await {
            Ok(listener) => {
                info!("leikwan-ingress mux listening on {}, routes {}", listen, routes_file.display());
                listeners.push(listener);
            }
            Err(err) if !listeners.is_empty() => {
                warn!("failed to also bind mux listener {}: {err:#}", listen);
            }
            Err(err) => {
                warn!("failed to bind mux listener {}: {err:#}", listen);
            }
        }
    }
    if listeners.is_empty() {
        bail!("failed to bind any mux listener");
    }
    // spawn accept loops for each listener and wait forever
}
```

- [ ] **Step 3: Update the slave unit renderer to emit every configured host**

In `slave-agent/slave_agent.py`, generate one `--listen host:port` argument per `self.mux_listen_hosts()` entry instead of hardcoding `[::]:{listen_port}`.

Implementation target:

```python
listen_args = " ".join(
    f"--listen {shell_arg(f'{host}:{listen_port}')}"
    for host in self.mux_listen_hosts()
)
```

and then:

```python
ExecStart={ingress_bin} mux {listen_args} --routes-file ...
```

- [ ] **Step 4: Run tests and verify GREEN**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_plain_tcp_mux
cargo test -p leikwan-ingress
cargo build --release -p leikwan-ingress
```

Expected: all pass.

### Task 3: Make Rollout Converge

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `slave-agent/slave_agent.py`
- Modify: `artifacts/versions.json`
- Test: `master/tests/plain_tcp_mux_rollout_versions_regression_test.php`

- [ ] **Step 1: Bump the client and slave agent versions**

Update:

```python
AGENT_VERSION = "2026.04.17.2"
```

in:

- `client-agent/client_agent.py`
- `slave-agent/slave_agent.py`

- [ ] **Step 2: Sync the artifact manifest**

Update `artifacts/versions.json`:

```json
"client_agent": {
  "version": "2026.04.17.2",
  ...
},
"slave_agent": {
  "version": "2026.04.17.2",
  ...
}
```

- [ ] **Step 3: Run the version regression and targeted runtime checks**

Run:

```bash
php master/tests/plain_tcp_mux_rollout_versions_regression_test.php
python -m py_compile client-agent/client_agent.py slave-agent/slave_agent.py
```

Expected: pass.

### Task 4: Verify The Hotfix End To End Locally

**Files:**
- Modify: none

- [ ] **Step 1: Re-run the plain TCP targeted suite**

Run:

```bash
php master/tests/provisioning_plain_tcp_ingress_regression_test.php
python -m unittest slave-agent.tests.test_slave_plain_tcp_mux
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_plain_tcp_ingress_uses_leikwan_ingress_client
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_remote_plain_tcp_ready_probe_uses_ping
cargo test -p leikwan-ingress
```

Expected: pass.

- [ ] **Step 2: Record follow-up deployment note**

Document that the next live deployment must verify:

- slave unit now includes both `--listen [::]:7001` and `--listen 0.0.0.0:7001`
- the slave host only needs one of those binds to succeed
- panel sees `desired_agent_version = 2026.04.17.2` for both client and slave agents

