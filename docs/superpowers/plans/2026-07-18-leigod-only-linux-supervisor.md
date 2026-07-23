# LeiGod-Only Linux Supervisor and Port Lease Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace LeiGod bond shell-group supervision on Linux clients with a structured per-Forward supervisor that owns collision-free local port leases, restart budgets, generation fencing, and systemd recovery without cleanup, purge, or deployment.

**Architecture:** Keep the active product contract locked to `backend_mode=leigod_mode`, `client_mode=bond`, `runtime_preference=single`, and `bond_ingress_mode=plain_tcp_mux`, and run the standalone `leikwan-bond` client through one Python supervisor manifest/process per Forward. The supervisor owns local ingress-child lifecycle, persistent host-wide port leases, readiness handoff, process restart budgets, and desired-generation replacement; the bond process owns network connect/Hello retry, and the Rust bond scheduler owns data-plane quarantine/probation. Client-agent reconciliation writes manifests/units and performs outer systemd half-open recovery only for exact Forward-owned units.

**Tech Stack:** Python 3 Linux client agent and `unittest`/pytest-compatible tests, systemd service units, JSON manifests/registries under `/var/lib/leikwan-client-agent`, loopback TCP reservation sockets, existing standalone Rust `wstunnel-main/leikwan-bond` binary, existing `client-agent/install_forward_runtime.py` renderer patterns.

---

## Scope and locked runtime contract

This plan writes an implementation plan only. Implementation of the tasks below must not deploy, purge, clean production hosts, publish artifacts, push commits, or run remote commands. Artifact mirrors under `artifacts/` and `master/public/artifacts/` are intentionally outside this plan.

Protected unrelated file:

```text
docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md
```

Do not modify, stage, commit, delete, or use that file as authorization for this changeset.

The only active runtime contract in scope is:

```text
backend_mode = leigod_mode
client_mode = bond
runtime_preference = single
bond_ingress_mode = plain_tcp_mux
```

For this plan, `runtime_preference = single` means the Linux client directly runs the standalone `leikwan-bond` client. It does not mean one Slave, and it does not authorize native MPTCP. One to eight selected Slaves are valid:

```text
1 usable Slave      -> standalone leikwan-bond, single-leg-only behavior
2..8 usable Slaves  -> standalone leikwan-bond, Speed Plus eligible
0 usable Slaves     -> explicit invalid/no-usable-legs state before supervisor activation
```

This plan does not implement retirement cleanup. It may release a local lease only when the exact active Forward/generation/logical key is removed or replaced and only after generation-fenced detach. It must never run a fleet-wide systemd, WireGuard, process, port, or file purge.

---

## Repo-grounded current state

The existing Linux client runtime path is centered in:

- `client-agent/client_agent.py:1421-1595` — `ClientAgent.apply_forward()` calls `render_forward_runtime()`, writes unit files/scripts, enables/restarts every rendered unit, and stores `units`, `managed_paths`, `published_mode`, `runtime_mode`, `contract_signature`, and `rendered_signature` in `applied.json`.
- `client-agent/client_agent.py:1597-1627` — `stop_forward()` stops/disables units recorded for one Forward and optionally deletes their managed files. This is Forward-scoped and must remain separate from retirement cleanup.
- `client-agent/client_agent.py:1695-1900` — `ensure_runtime_health()` currently restarts `leikwan-client-<forward_id>` for inactive services and failed local/remote probes. LeiGod supervisor mode must move ordinary child/network handling inside the supervisor and keep agent health from restarting healthy supervisors for ordinary leg failures.
- `client-agent/client_agent.py:2002-2011` — `restart_forward_service()` has an in-memory 30-second throttle only. Task implementation must add persisted outer `start-limit-hit` cooldown/half-open state for supervisor units.
- `client-agent/install_forward_runtime.py:650-953` — `render_forward_runtime()` builds shell scripts for race/bond modes. In bond mode it currently uses formula ports `20000 + ((forward_id * 37) % 15000)` and `base + 200 + index`, starts ingress helpers and `leikwan-bond` with `&`, waits for local listeners, then ends with `wait -n`. This whole-group shell supervision is rejected for LeiGod bond runtime.
- `client-agent/install_forward_runtime.py:821-827` and `887-893` — `plain_tcp_mux` currently starts `/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:<formula_port> --remote <slave_host>:<port> --route-key <route_key> --auth-token <token> &`.
- `wstunnel-main/leikwan-bond/src/main.rs:20-64` — standalone `leikwan-bond client` accepts `--protocol`, `--listen`, `--legs`, `--session-token`, and scheduler parameters. The Linux supervisor must use this binary rather than native MPTCP or legacy runtime-family fallback.
- `wstunnel-main/leikwan-bond/src/session.rs:25-42` and following scheduler code — the Rust bond runtime already owns protocol/session retry and health behavior; process supervision must not duplicate network-failure restarts.
- `client-agent/tests/test_mptcp_bond_regressions.py:233-356` — existing tests assert wait-for-listener behavior, formula local ports, plain TCP ingress command rendering, and TCP+UDP bond rendering. These tests must be updated or complemented so LeiGod-only Linux supervisor expectations replace the old `wait -n` bond script only for the locked LeiGod contract.

---

## File map

### Create

- `client-agent/leigod_bond_supervisor.py`
  - Long-running per-Forward supervisor executable/module.
  - Loads one manifest, opens the host lease registry through an exclusive lock, allocates/renews/reclaims local leg leases, reserves loopback ports, starts ingress workers, starts the standalone `leikwan-bond` TCP child, tracks readiness, restarts abnormal child exits within budgets, performs generation-fenced replacement, writes supervisor state snapshots, and exits non-zero only for internal corruption/unrecoverable supervisor exceptions.

- `client-agent/leigod_bond_manifest.py`
  - Pure dataclasses/helpers for supervisor manifest schema, logical leg keys, desired generation, desired-leg-set hash, validation, and command rendering inputs. Kept pure so tests can exercise schema and generation behavior without spawning processes.

- `client-agent/leigod_port_leases.py`
  - Host-wide persistent local port registry and allocator. Owns JSON schema, `fcntl.flock` locking on Linux, deterministic key-derived scan, bind preflight, loopback reservation socket, stale-generation detection, atomic write/replace, and exact-key release.

- `client-agent/leigod_restart_budget.py`
  - Pure restart-budget ledger for each stable child identity. Owns consecutive abnormal-exit streak, rolling restart-launch timestamps, exponential backoff, deterministic jitter, circuit-open transitions, and persistence serialization.

- `client-agent/tests/test_leigod_port_leases.py`
  - Unit coverage for collision-free allocation, persistent reuse, listener conflict avoidance, stale lease reclaim, exact-key release, and no formula-only ownership.

- `client-agent/tests/test_leigod_restart_budget.py`
  - Unit coverage for backoff, jitter bounds, rolling 10-in-600-second circuit, 60-second alive reset, and persisted ledger reload.

- `client-agent/tests/test_leigod_bond_supervisor.py`
  - Supervisor orchestration tests with fake process/clock/systemd adapters: manifest validation, one-to-eight legs, spawn order, readiness, generation replacement, child restart isolation, circuit-open behavior, lease_wait behavior, and stale-generation rejection.

- `client-agent/tests/test_leigod_bond_supervisor_rendering.py`
  - Renderer/apply tests proving the locked LeiGod contract produces supervisor manifest + unit, not a shell `wait -n` group, and that non-LeiGod legacy bond tests remain intentionally unchanged until retired by their own plan.

### Modify

- `client-agent/install_forward_runtime.py`
  - Add a LeiGod-only branch inside `render_forward_runtime()` before the generic `mode == "bond"` shell branch. The branch triggers only when the normalized contract is exactly `backend_mode=leigod_mode`, `mode=bond`, `runtime_preference=single`, and `bonding.ingress_mode=plain_tcp_mux` or `bond_ingress_mode=plain_tcp_mux`.
  - Render `/etc/leikwan/client-forward-<forward_id>-gen-<generation>.json` manifest and one supervisor service unit. Do not render shell `trap ... wait -n` scripts for this locked contract.
  - Keep the old bond rendering path untouched for non-LeiGod historical rows until control-plane/retirement plans remove their active use.

- `client-agent/client_agent.py`
  - Include supervisor-specific semantic config: lease range defaults, state paths, device jitter seed, supervisor binary path, and generation source.
  - Persist supervisor metadata in `applied.json` for the exact Forward: manifest path, supervisor unit name, desired generation, desired-leg-set hash, lease registry path, and outer recovery ledger state.
  - Adjust `ensure_runtime_health()` so LeiGod supervisor mode does not restart the whole unit for ordinary endpoint probe failures, all-leg unavailability, or child circuit state. It may report/refresh desired state, but outer `systemctl reset-failed` is handled only by the persisted start-limit-hit half-open path.
  - Keep `stop_forward()` scoped to recorded `applied.json` units/managed paths and exact supervisor manifest. Do not add prefix scans or fleet-wide cleanup.

- `client-agent/tests/test_mptcp_bond_regressions.py`
  - Preserve historical tests for non-LeiGod modes, and add assertions that the locked LeiGod contract no longer contains `wait -n`, formula-derived port expectations, or `Restart=always`.

- `client-agent/tests/test_client_agent_bond_endpoint_health.py`
  - Update health/restart expectations so locked LeiGod supervisor mode records endpoint health without restarting the supervisor for ordinary failed slave probes.

- `client-agent/install-slave.sh` or installer helper source only if the repo already installs client-agent helper files from an explicit file list.
  - Add `leigod_bond_supervisor.py`, `leigod_bond_manifest.py`, `leigod_port_leases.py`, and `leigod_restart_budget.py` to the local client-agent install payload. Do not publish artifacts in this plan.

### Do not modify

- `docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md`
- `docs/superpowers/plans/2026-07-18-leigod-only-retirement-cleanup.md`
- `wstunnel-main/leikwan-bond/src/*` except for tests in a later data-plane task if that plan requires it
- PHP control-plane files
- `artifacts/*`
- `master/public/artifacts/*`
- Production systemd units or remote hosts

---

## Naming, paths, and runtime ownership contracts

Use these exact local names unless implementation uncovers a hard conflict and updates all tests in the same task:

```text
Supervisor unit:      leikwan-client-<forward_id>.service
Supervisor manifest:  /etc/leikwan/client-forward-<forward_id>-gen-<generation>.json
Supervisor state:     /var/lib/leikwan-client-agent/supervisors/forward-<forward_id>.json
Lease registry:       /var/lib/leikwan-client-agent/leigod-port-leases.json
Lease lock:           /var/lib/leikwan-client-agent/leigod-port-leases.lock
Snapshot path:        /run/leikwan/bond-leg-health-forward-<forward_id>.json
Default lease range:  20000-39999
Protocol:             tcp
Lease purpose:        ingress_leg
Logical leg key:      bond:<forward_id>:tcp:<node_id>:<endpoint_id>
Lease key tuple:      (forward_id, protocol, node_id, endpoint_id, purpose)
```

The supervisor unit must be:

```ini
[Unit]
Description=Leikwan Client <forward_id> LeiGod Bond Supervisor
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
Environment=PYTHONUNBUFFERED=1
ExecStart=/usr/bin/python3 /usr/local/bin/leigod-bond-supervisor.py --manifest /etc/leikwan/client-forward-<forward_id>-gen-<generation>.json
Restart=on-failure
RestartSec=30
StartLimitIntervalSec=600
StartLimitBurst=5

[Install]
WantedBy=multi-user.target
```

Do not add a generic timer that restarts this unit. Agent reconciliation is the only owner of delayed `systemctl reset-failed` after `start-limit-hit`.

---

### Task 1: Add pure manifest schema and renderer tests

**Files:**
- Create: `client-agent/leigod_bond_manifest.py`
- Modify: `client-agent/install_forward_runtime.py:650-953`
- Create: `client-agent/tests/test_leigod_bond_supervisor_rendering.py`

- [ ] **Step 1: Write the failing locked-contract rendering test**

Create `client-agent/tests/test_leigod_bond_supervisor_rendering.py` with this initial test. It intentionally asserts the current shell renderer is no longer acceptable for the locked LeiGod contract.

```python
import json
import pathlib
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
CLIENT_AGENT = REPO_ROOT / "client-agent"
sys.path.insert(0, str(CLIENT_AGENT))

import install_forward_runtime


class LeiGodBondSupervisorRenderingTest(unittest.TestCase):
    def test_locked_leigod_bond_contract_renders_supervisor_manifest_not_wait_n_shell(self) -> None:
        rendered = install_forward_runtime.render_forward_runtime(
            {
                "forward_id": 38,
                "listen_port": 11038,
                "target": "193.227.109.82:5201",
                "sni": "unused-for-plain-mux.example",
                "path_prefix": "route38",
                "transport": "tcp",
                "mode": "bond",
                "backend_mode": "leigod_mode",
                "runtime_preference": "single",
                "bond_ingress_mode": "plain_tcp_mux",
                "desired_generation": 12,
                "endpoints": [
                    {"endpoint_id": 184, "node_id": 2, "host": "8.163.90.132", "port": 7001, "ingress_auth_token": "token-184"},
                    {"endpoint_id": 185, "node_id": 19, "host": "8.163.90.235", "port": 7001, "ingress_auth_token": "token-185"},
                ],
                "bonding": {
                    "ingress_mode": "plain_tcp_mux",
                    "route_key": "route38",
                    "remote_target": "138.252.162.120:9000",
                    "session_token": "tcp-token",
                    "join_timeout_ms": 400,
                    "quic_enabled": False,
                    "quic_via_slave": False,
                    "wss_standby_enabled": True,
                },
            },
            {
                "bond_bin": "/usr/local/bin/leikwan-bond",
                "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
                "leigod_bond_supervisor_bin": "/usr/local/bin/leigod-bond-supervisor.py",
                "state_dir": "/var/lib/leikwan-client-agent",
                "client_id": 123,
                "device_jitter_seed": "client-123-install-a",
            },
        )

        self.assertEqual(rendered["service_name"], "leikwan-client-38")
        self.assertEqual(rendered["start_script_path"], "")
        self.assertEqual(rendered["start_script"], "")
        self.assertIn("Restart=on-failure", rendered["service_unit"])
        self.assertIn("RestartSec=30", rendered["service_unit"])
        self.assertIn("StartLimitIntervalSec=600", rendered["service_unit"])
        self.assertIn("StartLimitBurst=5", rendered["service_unit"])
        self.assertNotIn("Restart=always", rendered["service_unit"])
        self.assertNotIn("wait -n", json.dumps(rendered, sort_keys=True))
        self.assertNotIn("20962", json.dumps(rendered, sort_keys=True))
        self.assertNotIn("21162", json.dumps(rendered, sort_keys=True))

        manifest_file = next(item for item in rendered["files"] if item["path"] == "/etc/leikwan/client-forward-38-gen-12.json")
        manifest = json.loads(manifest_file["content"])
        self.assertEqual(manifest["forward_id"], 38)
        self.assertEqual(manifest["desired_generation"], 12)
        self.assertEqual(manifest["listen"], "0.0.0.0:11038")
        self.assertEqual(manifest["bond_binary"], "/usr/local/bin/leikwan-bond")
        self.assertEqual(manifest["ingress_binary"], "/usr/local/bin/leikwan-ingress")
        self.assertEqual(manifest["lease_range"], {"start": 20000, "end": 39999})
        self.assertEqual(manifest["protocol"], "tcp")
        self.assertEqual(len(manifest["legs"]), 2)
        self.assertEqual(manifest["legs"][0]["logical_key"], "bond:38:tcp:2:184")
        self.assertEqual(manifest["legs"][1]["logical_key"], "bond:38:tcp:19:185")
        self.assertEqual(manifest["legs"][0]["purpose"], "ingress_leg")
        self.assertEqual(manifest["legs"][0]["route_key"], "route38")
        self.assertEqual(manifest["legs"][0]["auth_token"], "token-184")
        self.assertEqual(manifest["supervisor_state_path"], "/var/lib/leikwan-client-agent/supervisors/forward-38.json")
        self.assertEqual(manifest["lease_registry_path"], "/var/lib/leikwan-client-agent/leigod-port-leases.json")
        self.assertEqual(manifest["snapshot_path"], "/run/leikwan/bond-leg-health-forward-38.json")


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run the failing renderer test**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_bond_supervisor_rendering.py -q
```

Expected before implementation: FAIL because the current renderer emits `/usr/local/bin/leikwan-client-start-38.sh`, formula-derived local ports, `Restart=always`, and `wait -n`.

- [ ] **Step 3: Add pure manifest helpers**

Create `client-agent/leigod_bond_manifest.py` with dataclasses for:

```python
@dataclasses.dataclass(frozen=True)
class LeiGodBondLeg:
    forward_id: int
    node_id: int
    endpoint_id: int
    host: str
    port: int
    route_key: str
    auth_token: str
    priority: int = 10
    protocol: str = "tcp"
    purpose: str = "ingress_leg"

    @property
    def logical_key(self) -> str:
        return f"bond:{self.forward_id}:tcp:{self.node_id}:{self.endpoint_id}"

    @property
    def lease_key(self) -> str:
        return f"{self.forward_id}:tcp:{self.node_id}:{self.endpoint_id}:ingress_leg"
```

Also define `LeiGodBondManifest`, `manifest_from_forward(forward, agent_config)`, and `manifest_to_json_dict(manifest)`. Validation rules must be explicit:

```text
forward_id > 0
listen_port > 0
backend_mode == leigod_mode
mode == bond
runtime_preference == single
plain_tcp_mux from forward.bond_ingress_mode or bonding.ingress_mode
1 <= distinct node_id count == leg count <= 8
host non-empty, port > 0, endpoint_id > 0, node_id > 0
session_token non-empty
ingress auth token non-empty for every leg
protocol tcp only
purpose ingress_leg only
```

- [ ] **Step 4: Add the LeiGod branch to the runtime renderer**

In `client-agent/install_forward_runtime.py`, before the existing generic `if mode == "bond":` block, detect the locked LeiGod contract and return:

```python
{
    "service_name": f"leikwan-client-{forward_id}",
    "service_path": f"/etc/systemd/system/leikwan-client-{forward_id}.service",
    "service_unit": leigod_supervisor_unit,
    "start_script_path": "",
    "start_script": "",
    "files": [{"path": manifest_path, "content": manifest_json, "mode": 0o600}],
    "supervisor": {
        "kind": "leigod_bond_supervisor",
        "manifest_path": manifest_path,
        "desired_generation": manifest.desired_generation,
        "desired_leg_set_hash": manifest.desired_leg_set_hash,
        "lease_registry_path": manifest.lease_registry_path,
    },
    "extra_units": [],
}
```

The service unit must match the naming block above and must not contain `Restart=always`.

- [ ] **Step 5: Run the renderer test and existing bond renderer subset**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_bond_supervisor_rendering.py client-agent/tests/test_mptcp_bond_regressions.py::MptcpBondRegressionTest::test_bond_plain_tcp_ingress_uses_leikwan_ingress_client -q
```

Expected: the new LeiGod test PASS; the legacy non-LeiGod test still PASS because the old branch remains for rows outside the locked contract.

- [ ] **Step 6: Commit**

```bash
git add client-agent/leigod_bond_manifest.py client-agent/install_forward_runtime.py client-agent/tests/test_leigod_bond_supervisor_rendering.py
git commit -m "feat(client-agent): render leigod bond supervisor manifests"
```

---

### Task 2: Implement host-wide persistent port leases

**Files:**
- Create: `client-agent/leigod_port_leases.py`
- Create: `client-agent/tests/test_leigod_port_leases.py`

- [ ] **Step 1: Write allocator tests**

Create `client-agent/tests/test_leigod_port_leases.py` with tests for:

```text
1. same lease key reuses the same port after registry reload when bind-safe
2. two different active keys never receive the same port
3. an actual unknown listener in the range is skipped, not killed
4. release_exact removes only the matching forward/protocol/node/endpoint/purpose/generation
5. stale generation can be reclaimed only when owner generation is absent and port bind preflight succeeds
6. exhausted range returns local_port_exhausted without changing existing leases
7. allocation start is deterministic from the lease key, but formula ports are not trusted ownership evidence
```

Use `socket.socket()` bound to `127.0.0.1:0` for dynamic conflict tests when possible, and a tiny temporary range around the bound port for deterministic exhaustion cases. Use a temporary registry path and lock path.

- [ ] **Step 2: Run allocator tests to verify failure**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_port_leases.py -q
```

Expected before implementation: FAIL because `leigod_port_leases.py` does not exist.

- [ ] **Step 3: Implement registry schema and lock**

Create `client-agent/leigod_port_leases.py` with:

```python
class PortLeaseError(RuntimeError): ...
class LocalPortExhausted(PortLeaseError): ...
@dataclasses.dataclass(frozen=True)
class LeaseKey: ...
@dataclasses.dataclass
class PortLease: ...
class PortLeaseRegistry:
    def __init__(self, registry_path: pathlib.Path, lock_path: pathlib.Path, port_start: int = 20000, port_end: int = 39999): ...
    @contextlib.contextmanager
    def locked(self): ...  # fcntl.flock exclusive on Linux
    def allocate(self, key: LeaseKey, desired_generation: int, live_generations: set[int]) -> PortLease: ...
    def release_exact(self, key: LeaseKey, desired_generation: int) -> bool: ...
```

Persist JSON with this shape:

```json
{
  "schema_version": 1,
  "range": {"start": 20000, "end": 39999},
  "leases": {
    "38:tcp:2:184:ingress_leg": {
      "port": 20962,
      "forward_id": 38,
      "protocol": "tcp",
      "node_id": 2,
      "endpoint_id": 184,
      "purpose": "ingress_leg",
      "desired_generation": 12,
      "last_successful_bind": 1721260800.0,
      "owner_liveness": "supervisor:38:12"
    }
  }
}
```

Use same-directory temp file + flush + `os.fsync()` + `os.replace()` for registry writes.

- [ ] **Step 4: Implement deterministic allocation and bind preflight**

Under the exclusive lock:

```text
- Validate protocol=tcp and purpose=ingress_leg.
- If the exact key has a same-generation lease and bind preflight succeeds, reuse it.
- If the exact key has an old generation and that generation is not live, reclaim it only after bind preflight succeeds.
- Scan every port in the configured range starting from sha256(lease_key) % range_size.
- Reject any port already owned by a different live registry key.
- Reject any port that cannot bind to 127.0.0.1 with SO_REUSEADDR disabled.
- Return LocalPortExhausted without mutating existing leases if no port is available.
```

Do not kill processes, close unknown listeners, or infer ownership from `20000 + ((forward_id * 37) % 15000)`.

- [ ] **Step 5: Run allocator tests**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_port_leases.py -q
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add client-agent/leigod_port_leases.py client-agent/tests/test_leigod_port_leases.py
git commit -m "feat(client-agent): add leigod local port leases"
```

---

### Task 3: Implement restart-budget ledgers

**Files:**
- Create: `client-agent/leigod_restart_budget.py`
- Create: `client-agent/tests/test_leigod_restart_budget.py`

- [ ] **Step 1: Write restart budget tests**

Create tests covering these exact rules:

```text
- initial desired-generation launch is not counted as a restart
- orderly generation replacement is not counted as a restart
- abnormal exit records one consecutive streak and one launch timestamp before relaunch
- backoff starts at 1 second and doubles to cap at 60 seconds
- deterministic jitter is >=0% and <=20% of the base backoff
- timestamps outside [now-600s, now) expire before circuit check
- with ten timestamps remaining, the eleventh launch is denied and circuit_open_until = now + 600
- 60 continuous seconds alive resets the consecutive streak but not unrelated rolling timestamps
- serialized JSON reload preserves streak, rolling timestamps, and circuit state
```

Use a fake clock and stable jitter seed such as `client-123|bond:38:tcp:2:184|restart-epoch-7`.

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_restart_budget.py -q
```

Expected before implementation: FAIL because module does not exist.

- [ ] **Step 3: Implement restart budget module**

Create:

```python
@dataclasses.dataclass
class ChildRestartLedger:
    child_key: str
    consecutive_abnormal_exits: int = 0
    restart_launch_timestamps: list[float] = dataclasses.field(default_factory=list)
    circuit_open_until: float = 0.0
    last_started_at: float = 0.0

class RestartBudget:
    def can_launch_initial(...): ...
    def record_abnormal_exit(...): ...
    def next_restart_deadline(...): ...
    def record_restart_launch(...): ...
    def record_alive(...): ...
```

Backoff formula:

```text
base = min(60, 2 ** max(0, consecutive_abnormal_exits - 1))
jitter = stable_hash(device_seed + child_key + restart_epoch) mapped to [0.0, 0.2]
delay = base * (1.0 + jitter)
```

Circuit formula:

```text
window = [timestamp for timestamp in restart_launch_timestamps if now - 600 <= timestamp < now]
if len(window) >= 10: deny launch and set circuit_open_until = now + 600
```

- [ ] **Step 4: Run restart tests**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_restart_budget.py -q
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add client-agent/leigod_restart_budget.py client-agent/tests/test_leigod_restart_budget.py
git commit -m "feat(client-agent): add supervisor restart budgets"
```

---

### Task 4: Implement supervisor spawn/readiness loop

**Files:**
- Create: `client-agent/leigod_bond_supervisor.py`
- Create: `client-agent/tests/test_leigod_bond_supervisor.py`
- Modify: `client-agent/leigod_bond_manifest.py`

- [ ] **Step 1: Write supervisor orchestration tests with fakes**

In `client-agent/tests/test_leigod_bond_supervisor.py`, add fake adapters:

```python
class FakeProcess:
    def __init__(self, name: str, returncode: int | None = None): ...
class FakeProcessRunner:
    def spawn(self, child_key: str, argv: list[str], env: dict[str, str]) -> FakeProcess: ...
class FakeClock:
    def now(self) -> float: ...
    def advance(self, seconds: float) -> None: ...
```

Test cases:

```text
- supervisor starts ingress workers for every desired leg before starting bond child
- bond child starts after at least one required ingress listener is ready
- one ingress child abnormal exit restarts only that child, not the bond child or other ingress children
- bond child abnormal exit follows its own ledger independently
- local_port_exhausted enters lease_wait and does not increment process restart budget
- malformed desired state is generation-fatal and does not crash-loop the supervisor
- ordinary network/Hello failure is not represented as a process restart request
```

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_bond_supervisor.py -q
```

Expected before implementation: FAIL because supervisor module does not exist.

- [ ] **Step 3: Implement supervisor child model**

In `client-agent/leigod_bond_supervisor.py`, define:

```python
@dataclasses.dataclass
class ChildSpec:
    child_key: str
    logical_key: str
    kind: typing.Literal["ingress", "bond"]
    desired_generation: int
    argv: list[str]
    env: dict[str, str]
    lease_key: str | None = None
    port: int | None = None

@dataclasses.dataclass
class ChildState:
    spec: ChildSpec
    phase: str = "stopped"
    pid: int | None = None
    ready: bool = False
    next_attempt_at: float = 0.0
    last_error: str = ""
```

Ingress `argv` must call standalone ingress helper:

```text
/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:<leased_port> --remote <host>:<port> --route-key <route_key> --auth-token <auth_token>
```

Bond `argv` must call standalone bond helper:

```text
/usr/local/bin/leikwan-bond client --protocol tcp --listen 0.0.0.0:<listen_port> --legs tcp://127.0.0.1:<leased_port>?priority=<priority>&logical_key=<urlencoded_logical_key> --session-token <session_token> ...
```

If the data-plane plan has added a different stable-identity CLI flag, use that instead and update this test in the same task. Do not call `leikwan-mptcp-relay`, kernel MPTCP helpers, WSS fallback, or CF fallback.

- [ ] **Step 4: Implement readiness checks**

Add loopback listener readiness probes equivalent to the current `append_wait_for_local_listener()` behavior but per child:

```text
- ingress child phase: stopped -> lease_wait -> starting -> ready
- readiness is a TCP connect to 127.0.0.1:<leased_port>
- bond child starts only after at least one desired ingress child is ready
- if all desired ingress children are lease_wait/circuit_open, report all-leg-unavailable in supervisor state and keep supervisor alive
```

- [ ] **Step 5: Implement state persistence**

Write `/var/lib/leikwan-client-agent/supervisors/forward-<forward_id>.json` with:

```json
{
  "schema_version": 1,
  "forward_id": 38,
  "desired_generation": 12,
  "desired_leg_set_hash": "...",
  "supervisor_epoch": "...",
  "phase": "running",
  "children": {
    "ingress:bond:38:tcp:2:184": {"phase": "ready", "pid": 1234, "port": 20962},
    "bond:tcp": {"phase": "ready", "pid": 1240}
  },
  "restart_ledgers": {"...": "..."},
  "lease_wait": {},
  "circuit_open": {}
}
```

Use temp file + fsync + replace. Never include LeiGod account credentials or secrets beyond already-required ingress auth tokens in root-readable manifests (`0600`).

- [ ] **Step 6: Run supervisor tests**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_bond_supervisor.py -q
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add client-agent/leigod_bond_supervisor.py client-agent/leigod_bond_manifest.py client-agent/tests/test_leigod_bond_supervisor.py
git commit -m "feat(client-agent): add leigod bond supervisor loop"
```

---

### Task 5: Add generation fencing and safe replacement

**Files:**
- Modify: `client-agent/leigod_bond_supervisor.py`
- Modify: `client-agent/leigod_port_leases.py`
- Modify: `client-agent/tests/test_leigod_bond_supervisor.py`
- Modify: `client-agent/tests/test_leigod_port_leases.py`

- [ ] **Step 1: Add failing generation replacement tests**

Add tests proving this sequence:

```text
1. old generation is marked draining
2. outstanding lease/connect attempts for old generation are cancelled or joined
3. removed leg specs require a detach acknowledgement containing old desired_generation and logical_key
4. only after detach ack does the supervisor stop removed ingress worker and release its exact lease
5. surviving logical key keeps its lease when bind-safe
6. new generation manifest and registry generation activate atomically
7. stale readiness/connect/attach events from old generation are rejected and cannot rejoin through a reused port
```

Use two manifests for the same Forward:

```text
generation 12: bond:38:tcp:2:184, bond:38:tcp:19:185
generation 13: bond:38:tcp:19:185, bond:38:tcp:20:186
```

Assert node 19/endpoint 185 keeps its lease, node 2/endpoint 184 releases only after detach ack, and old generation 12 cannot attach after generation 13 activates.

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_bond_supervisor.py::LeiGodBondSupervisorTest::test_generation_replacement_is_fenced_and_ordered -q
```

Expected before implementation: FAIL.

- [ ] **Step 3: Implement event fencing**

Every internal event object must include:

```python
@dataclasses.dataclass(frozen=True)
class SupervisorEvent:
    desired_generation: int
    logical_key: str
    event_type: str
    payload: dict[str, typing.Any]
```

Reject an event if:

```text
event.desired_generation != active_desired_generation
logical_key is not in active manifest and not in draining manifest awaiting detach
sequence is older than the last accepted event for that logical key
```

- [ ] **Step 4: Implement ordered replacement**

When a new manifest appears for the same Forward:

```text
- write replacement intent to supervisor state
- mark old generation draining
- cancel pre-spawn lease/connect attempts for removed keys
- tell bond child to detach removed leg specs if supported; otherwise stop only after bond process has exited during orderly replacement
- require detach acknowledgement in tests through the supervisor's adapter boundary
- stop removed ingress workers
- release exact leases for removed keys/generation
- allocate leases for added keys
- atomically write registry and active manifest state
```

If the current `leikwan-bond` process lacks a runtime detach API, implement the supervisor boundary as a generation-fenced orderly bond child replacement, not as a full systemd restart. The supervisor stays alive, preserves ledgers, and starts the new bond child after old bond child exits cleanly.

- [ ] **Step 5: Run generation tests**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_bond_supervisor.py client-agent/tests/test_leigod_port_leases.py -q
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add client-agent/leigod_bond_supervisor.py client-agent/leigod_port_leases.py client-agent/tests/test_leigod_bond_supervisor.py client-agent/tests/test_leigod_port_leases.py
git commit -m "feat(client-agent): fence leigod supervisor generations"
```

---

### Task 6: Integrate client-agent apply state and boot/systemd recovery

**Files:**
- Modify: `client-agent/client_agent.py:104-118`
- Modify: `client-agent/client_agent.py:1421-1595`
- Modify: `client-agent/client_agent.py:1695-1900`
- Modify: `client-agent/client_agent.py:2002-2011`
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`

- [ ] **Step 1: Write failing apply-state and systemd recovery tests**

Add tests that fake a LeiGod supervisor render result and assert `applied.json` stores:

```json
{
  "supervisor": {
    "kind": "leigod_bond_supervisor",
    "manifest_path": "/etc/leikwan/client-forward-38-gen-12.json",
    "desired_generation": 12,
    "desired_leg_set_hash": "...",
    "lease_registry_path": "/var/lib/leikwan-client-agent/leigod-port-leases.json",
    "outer_recovery": {
      "start_limit_hit_at": 0,
      "next_half_open_at": 0,
      "half_open": false,
      "stable_since": 0
    }
  }
}
```

Add a `systemctl show` mock for `ActiveState=failed` and `Result=start-limit-hit`. Assert:

```text
- before persisted cooldown expires, no reset-failed runs
- after device-jittered 10-minute cooldown, agent runs systemctl reset-failed leikwan-client-38.service once and systemctl start leikwan-client-38.service once
- no generic timer unit is rendered
- 10 minutes active clears half-open state
- ordinary endpoint probe failure does not call systemctl restart for leigod supervisor mode
```

- [ ] **Step 2: Run tests to verify failure**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_mptcp_bond_regressions.py client-agent/tests/test_client_agent_bond_endpoint_health.py -q
```

Expected before implementation: FAIL on the newly added assertions.

- [ ] **Step 3: Persist supervisor metadata in `apply_forward()`**

In `ClientAgent.apply_forward()`, when `rendered.get("supervisor", {}).get("kind") == "leigod_bond_supervisor"`:

```text
- include rendered["supervisor"] in applied[str(forward_id)]
- preserve existing outer_recovery state on equivalent generation
- reset outer_recovery only when desired generation changes after an orderly apply
- keep persisted units limited to the exact rendered supervisor unit and exact manifest path
```

Do not add scans of `/etc/systemd/system/leikwan-client-*`.

- [ ] **Step 4: Add outer systemd recovery helpers**

Add methods:

```python
def supervisor_systemd_status(self, service_name: str) -> dict[str, str]: ...
def reconcile_leigod_supervisor_systemd(self, forward_id: int, service_name: str, supervisor_state: dict, now: float) -> dict: ...
def stable_device_jitter_seconds(self, key: str, max_seconds: int) -> int: ...
```

Rules:

```text
- Use systemctl show <service> -p ActiveState -p Result --value or parse named properties.
- If Result=start-limit-hit, persist start_limit_hit_at and next_half_open_at = now + 600 + stable 0..120s jitter.
- Before next_half_open_at, do nothing.
- At/after next_half_open_at, run systemctl reset-failed <service>.service then systemctl start <service>.service once, set half_open=true.
- If half_open and service remains active for 600 seconds, clear outer recovery.
- If half_open fails back to start-limit-hit, reopen with a new cooldown.
```

- [ ] **Step 5: Update health checks for supervisor mode**

In `ensure_runtime_health()`, detect applied supervisor kind. For that mode:

```text
- call reconcile_leigod_supervisor_systemd()
- do not restart the service for ordinary failed slave ready probes, all-leg-unavailable, circuit-open, or local leg lease_wait
- allow apply/refresh only when desired generation or contract signature changes
- keep existing behavior for mptcp, race, single, and non-LeiGod legacy bond
```

- [ ] **Step 6: Run integration tests**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_mptcp_bond_regressions.py client-agent/tests/test_client_agent_bond_endpoint_health.py client-agent/tests/test_leigod_bond_supervisor_rendering.py -q
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add client-agent/client_agent.py client-agent/tests/test_mptcp_bond_regressions.py client-agent/tests/test_client_agent_bond_endpoint_health.py client-agent/tests/test_leigod_bond_supervisor_rendering.py
git commit -m "feat(client-agent): recover leigod supervisor systemd safely"
```

---

### Task 7: Add boot recovery and stale-unit safety tests

**Files:**
- Modify: `client-agent/leigod_bond_supervisor.py`
- Modify: `client-agent/leigod_port_leases.py`
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/tests/test_leigod_bond_supervisor.py`
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`

- [ ] **Step 1: Write boot recovery tests**

Add tests for these crash/boot cases:

```text
- crash after registry reservation before manifest activation leaves old generation authoritative
- crash after manifest write before systemd restart reuses the same generation and preserves restart ledgers
- equivalent-generation supervisor process replacement reloads child restart ledgers and rolling timestamps
- port with active unknown listener is treated as conflict and creates lease_wait, not reclaimed
- absent old generation plus bind-safe old lease can be reclaimed by exact key only
```

- [ ] **Step 2: Write safe stale-unit tests**

Add `ClientAgent.apply_forward()` tests proving:

```text
- reapply removes only stale units recorded in the same Forward's previous applied.json entry
- look-alike units such as leikwan-client-380.service and leikwan-client-38-debug.service are not touched
- missing applied.json does not authorize prefix cleanup
- stop_forward(delete_files=True) deletes only recorded managed_paths and the exact default service for the requested Forward
- active LeiGod supervisor manifest/lease registry paths remain in the keep-set and are not treated as legacy cleanup artifacts
```

- [ ] **Step 3: Run tests to verify failure**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_bond_supervisor.py client-agent/tests/test_mptcp_bond_regressions.py -q
```

Expected before implementation: FAIL on the newly added cases.

- [ ] **Step 4: Implement boot recovery state decisions**

Supervisor startup must:

```text
- load manifest and supervisor state
- if state generation equals manifest generation, reload ledgers and reconcile child state by PID/liveness probe
- if state generation is older, enter fenced replacement path rather than deleting unknown resources
- if registry contains same exact key/generation and bind-safe port, reuse it
- if registry contains different live owner, allocate a different port or enter lease_wait
- if JSON is corrupt, preserve the corrupt file as .corrupt.<timestamp>, start with no leases only when no active generation can be proven, and report supervisor_state_corrupt
```

- [ ] **Step 5: Keep stale-unit handling exact**

Ensure `ClientAgent.apply_forward()` and `stop_forward()` never enumerate systemd units by `leikwan-client-*` prefix. They may stop/disable/delete only:

```text
- service_name values from the same Forward's previous applied.json units list
- current rendered service_name for this Forward
- managed_paths recorded for that Forward
- manifest path returned by current render
```

- [ ] **Step 6: Run boot/stale safety tests**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_leigod_bond_supervisor.py client-agent/tests/test_mptcp_bond_regressions.py -q
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add client-agent/leigod_bond_supervisor.py client-agent/leigod_port_leases.py client-agent/client_agent.py client-agent/tests/test_leigod_bond_supervisor.py client-agent/tests/test_mptcp_bond_regressions.py
git commit -m "feat(client-agent): recover leigod supervisor on boot"
```

---

### Task 8: Wire installer payload and version metadata without publishing artifacts

**Files:**
- Modify: `client-agent/client_agent.py:27`
- Modify: client-agent installer helper file that owns client helper copy/download lists, if present in this checkout
- Modify: `client-agent/tests/test_client_agent_installer_helper.py`

- [ ] **Step 1: Write installer helper test**

Extend `client-agent/tests/test_client_agent_installer_helper.py` to assert the local install payload includes:

```text
leigod-bond-supervisor.py
leigod_bond_manifest.py
leigod_port_leases.py
leigod_restart_budget.py
```

If the installer currently expects hyphenated executable names in `/usr/local/bin`, assert:

```text
/usr/local/bin/leigod-bond-supervisor.py exists or is downloaded/copied
```

Do not assert artifact publication or production URLs beyond existing installer patterns.

- [ ] **Step 2: Run installer helper tests to verify failure**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_client_agent_installer_helper.py -q
```

Expected before implementation: FAIL until helper files are included.

- [ ] **Step 3: Add helper files to local install payload**

Update the installer helper source used by `test_client_agent_installer_helper.py` so local installation places:

```text
client-agent/leigod_bond_supervisor.py -> /usr/local/bin/leigod-bond-supervisor.py (0755)
client-agent/leigod_bond_manifest.py -> Python import location used by supervisor
client-agent/leigod_port_leases.py -> Python import location used by supervisor
client-agent/leigod_restart_budget.py -> Python import location used by supervisor
```

If this repo uses a single flat `/usr/local/bin` Python helper pattern, copy all four files there and import by module name from the script directory. Keep the implementation consistent with existing `client_agent.py` and `install_forward_runtime.py` install conventions.

- [ ] **Step 4: Bump local client agent version**

Update `AGENT_VERSION` in `client-agent/client_agent.py:27` to the next explicit version string chosen for this changeset. Do not update `versions.json`, `artifacts/`, or `master/public/artifacts/` in this plan.

- [ ] **Step 5: Run installer tests**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest client-agent/tests/test_client_agent_installer_helper.py -q
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add client-agent/client_agent.py client-agent/tests/test_client_agent_installer_helper.py <installer-helper-files>
git commit -m "feat(client-agent): install leigod supervisor helpers"
```

---

### Task 9: Verification and safety audit

**Files:**
- Test-only/no source changes expected unless a previous task failed verification.
- Do not modify `docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md`.

- [ ] **Step 1: Run focused test suite**

Run:

```bash
cd /absolute/path/to/repo && python -m pytest \
  client-agent/tests/test_leigod_port_leases.py \
  client-agent/tests/test_leigod_restart_budget.py \
  client-agent/tests/test_leigod_bond_supervisor.py \
  client-agent/tests/test_leigod_bond_supervisor_rendering.py \
  client-agent/tests/test_mptcp_bond_regressions.py \
  client-agent/tests/test_client_agent_bond_endpoint_health.py \
  client-agent/tests/test_client_agent_installer_helper.py \
  -q
```

Expected: PASS.

- [ ] **Step 2: Run syntax checks**

Run:

```bash
cd /absolute/path/to/repo && python -m py_compile \
  client-agent/client_agent.py \
  client-agent/install_forward_runtime.py \
  client-agent/leigod_bond_manifest.py \
  client-agent/leigod_port_leases.py \
  client-agent/leigod_restart_budget.py \
  client-agent/leigod_bond_supervisor.py
```

Expected: command exits 0.

- [ ] **Step 3: Audit forbidden runtime patterns**

Run:

```bash
cd /absolute/path/to/repo && python - <<'PY'
import json
import pathlib
import sys
sys.path.insert(0, str(pathlib.Path('client-agent').resolve()))
import install_forward_runtime
forward = {
    'forward_id': 38,
    'listen_port': 11038,
    'target': '193.227.109.82:5201',
    'sni': 'unused.example',
    'path_prefix': 'route38',
    'transport': 'tcp',
    'mode': 'bond',
    'backend_mode': 'leigod_mode',
    'runtime_preference': 'single',
    'bond_ingress_mode': 'plain_tcp_mux',
    'desired_generation': 12,
    'endpoints': [{'endpoint_id': 184, 'node_id': 2, 'host': '8.163.90.132', 'port': 7001, 'ingress_auth_token': 'token-184'}],
    'bonding': {'ingress_mode': 'plain_tcp_mux', 'route_key': 'route38', 'remote_target': '138.252.162.120:9000', 'session_token': 'tcp-token'},
}
rendered = install_forward_runtime.render_forward_runtime(forward, {'bond_bin': '/usr/local/bin/leikwan-bond', 'bond_ingress_bin': '/usr/local/bin/leikwan-ingress'})
blob = json.dumps(rendered, sort_keys=True)
for forbidden in ['wait -n', 'Restart=always', 'mptcp_preferred_bond_fallback', 'leikwan-mptcp-relay', 'wss_path_mux']:
    if forbidden in blob:
        raise SystemExit(f'forbidden pattern present: {forbidden}')
print('locked leigod render forbidden-pattern audit passed')
PY
```

Expected:

```text
locked leigod render forbidden-pattern audit passed
```

- [ ] **Step 4: Audit protected file unchanged**

Run:

```bash
cd /absolute/path/to/repo && git status --short -- docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md
```

Expected: no output.

- [ ] **Step 5: Audit no artifact/deployment changes**

Run:

```bash
cd /absolute/path/to/repo && git status --short -- artifacts master/public/artifacts
```

Expected: no output for this changeset.

- [ ] **Step 6: Commit verification fixes only if needed**

If Steps 1-5 fail because of implementation mistakes, fix the specific source/test files and commit:

```bash
git add <fixed-files>
git commit -m "fix(client-agent): harden leigod supervisor verification"
```

Do not commit generated reports, logs, or temporary files.

---

## Pitfalls and non-negotiable invariants

- Do not implement or deploy while following this plan unless separately authorized; these tasks are repository changes and local tests only.
- Do not touch `docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md`.
- Do not publish or sync `artifacts/`, `master/public/artifacts/`, production systemd units, or remote hosts in this plan.
- Do not replace standalone `leikwan-bond` with kernel MPTCP, `leikwan-mptcp-relay`, WSS fallback, CF fallback, or `mptcp_preferred_bond_fallback`.
- Do not treat `runtime_preference=single` as one Slave. The supervisor must support one to eight selected Slaves.
- Do not use formula-derived ports as ownership evidence. `20000 + ((forward_id * 37) % 15000)` is a historical starting formula only and must not be the final allocator.
- Do not kill unknown listeners to reclaim ports. Unknown bind conflicts create `lease_wait`/`local_port_exhausted` for the affected leg only.
- Do not charge process restart budgets for lease exhaustion, remote network failure, Hello/ack retry, data-plane quarantine, or probation failure.
- Do not let a single ingress child exit tear down unrelated healthy ingress children or the supervisor process.
- Do not add a generic timer that restarts supervisor units. Agent reconciliation alone owns `reset-failed` half-open recovery.
- Do not scan by broad prefixes such as `leikwan-client-*` for deletion. Only exact Forward/generation-owned units, files, leases, and child identities may be stopped or released.
- Do not discard restart ledgers on equivalent-generation process/systemd replacement. Rolling timestamps must survive boot and supervisor restart.
- Do not allow stale-generation child readiness/connect/attach events to rejoin after detach acknowledgement and port release.
- Do not expose LeiGod credentials in manifests, snapshots, or logs.

---

## Plan self-review checklist

- Spec coverage: covers structured per-Forward supervisor, persistent host-wide port leases, restart budgets, generation fencing, boot/systemd recovery, safe stale-unit handling, one-to-eight selected Slaves, standalone `leikwan-bond`, and preservation of active LeiGod runtime.
- Boundary coverage: explicitly excludes cleanup/purge/deployment/artifact publication and keeps retirement cleanup in its own plan.
- Repo grounding: points to current shell `wait -n` renderer, formula port allocation, client-agent apply/health/restart functions, existing tests, and standalone Rust bond CLI.
- Test coverage: includes unit tests for leases and restart ledgers, supervisor fake-process tests, renderer shape tests, apply/systemd recovery tests, boot/stale safety tests, syntax checks, forbidden-pattern audit, protected-file audit, and artifact audit.
- Placeholder scan: the plan avoids placeholder markers, open-ended handling instructions, and vague edge-case directives; every task has concrete files, commands, expected results, and safety assertions.
