# Bond Leg Adaptive Weighting and Panel Reputation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship adaptive bad-leg detection and automatic weight rebalance for bond TCP/UDP, plus a panel-only reputation library and admin task-board improvements, without letting panel reputation feed back into runtime routing.

**Architecture:** The runtime truth stays inside `leikwan-bond`. It computes TCP and UDP leg health independently, writes local snapshot files, and adapts weights with bounded quarantine/probe recovery. Client and sidecar agents push those snapshots upward during heartbeat. Panel stores current reputation plus recent events for visibility only. Admin gets queue-health, stale-pending purge, and failed-task details. Tenant reuses the existing diagnostics surface for scoped reputation.

**Tech Stack:** Rust (`wstunnel-main/leikwan-bond`), Python agents (`client-agent`, `sidecar-agent`), PHP panel controllers and support classes, MySQL schema migrations, Python/PHP/Rust test suites.

---

## Parallelization map

This plan is intentionally split for multiple AI agents with mostly disjoint write sets.

### Worker A, Runtime core

Owns:

- `wstunnel-main/leikwan-bond/src/session.rs`
- optionally `wstunnel-main/leikwan-bond/src/main.rs`

### Worker B, Agent snapshot plumb-through

Owns:

- `client-agent/client_agent.py`
- `sidecar-agent/sidecar_agent.py`

### Worker C, Panel persistence and heartbeat ingestion

Owns:

- `master/migrations/20260419_add_bond_leg_reputation.sql`
- `master/migrations/schema.sql`
- `master/src/Support/BondLegReputationService.php`
- `master/src/Controllers/ClientAgentController.php`
- `master/src/Controllers/SidecarAgentController.php`
- `master/tests/bond_leg_reputation_persistence_test.php`

### Worker D, Admin and tenant operational UI

Owns:

- `master/src/Controllers/AdminController.php`
- `master/src/Controllers/TenantController.php`
- `master/tests/admin_bond_leg_reputation_dashboard_test.php`
- `master/tests/admin_pending_task_purge_test.php`
- `master/tests/tenant_bond_leg_reputation_visibility_test.php`

### Main integrator

Owns:

- final cross-worker merge fixes
- full verification pass
- ops manual

---

## Shared contract, lock this first

All workers must use the same local snapshot schema. Do not improvise.

### Client snapshot path

- `/run/leikwan/bond-leg-health-forward-26.json` for the initial rollout
- generalized rule: one file per forward under `/run/leikwan/`

### Sidecar snapshot path

- `/var/lib/leikwan-sidecar-4/bond-leg-health.json` for the initial rollout
- generalized rule: one file per sidecar state dir

### Snapshot shape

```json
{
  "observer_type": "client",
  "observer_id": 1,
  "forward_id": 26,
  "updated_at": "2026-04-19T14:30:00Z",
  "legs": [
    {
      "endpoint_id": 185,
      "node_id": 5,
      "host": "8.163.90.235",
      "tcp": {
        "state": "degraded",
        "score": 0.21,
        "effective_weight": 0.10,
        "delivered_bytes_ewma": 18231.4,
        "penalty_ewma": 0.81,
        "last_reason": "control_socket_closed_unexpectedly",
        "last_good_at": "2026-04-19T14:29:21Z"
      },
      "udp": {
        "state": "probation",
        "score": 0.08,
        "effective_weight": 0.05,
        "delivered_bytes_ewma": 7211.2,
        "penalty_ewma": 0.92,
        "last_reason": "high_loss_ratio",
        "last_good_at": "2026-04-19T14:28:44Z"
      },
      "recent_events": [
        {
          "protocol": "tcp",
          "from": "healthy",
          "to": "degraded",
          "reason": "retransmit_penalty_window",
          "observed_at": "2026-04-19T14:29:33Z"
        }
      ]
    }
  ]
}
```

Keep `recent_events` bounded, ring buffer style. Target cap: 20.

---

### Task 1: Worker A, implement runtime health model and local snapshot emission

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`
- Modify: `wstunnel-main/leikwan-bond/src/main.rs` (only if new config flags are truly required)
- Test: `wstunnel-main/leikwan-bond/src/session.rs` test module

- [ ] **Step 1: Add the failing runtime tests for bad-leg adaptation**

Add Rust tests covering:

- one toxic TCP leg among healthy legs
- one toxic UDP leg among healthy legs
- state transitions `Healthy -> Degraded -> Probation -> Quarantined`
- recovery transitions `Quarantined -> Probation -> Degraded -> Healthy`
- local snapshot serialization shape

Use a shape like:

```rust
#[test]
fn toxic_tcp_leg_is_downweighted_before_pool_collapses() {
    let mut legs = vec![
        fake_leg(0, 10, 0, 30),
        fake_leg(1, 10, 0, 30),
        fake_leg(2, 10, 0, 30),
        fake_leg(3, 10, 0, 30),
        fake_leg(4, 10, 0, 30),
    ];
    let mut health = fake_leg_health_set();

    penalize_tcp_leg(&mut health, 4, TcpPenalty::ControlSocketClosed);
    penalize_tcp_leg(&mut health, 4, TcpPenalty::RetransmitBurst);
    recompute_tcp_leg_state(&mut health, 4);

    assert_eq!(health[4].tcp.state, LegHealthState::Degraded);
    assert!(health[4].tcp.effective_weight < 0.25);
}
```

- [ ] **Step 2: Run the Rust tests to confirm they fail**

Run:

```bash
cargo test -p leikwan-bond toxic_tcp_leg_is_downweighted_before_pool_collapses -- --nocapture
```

Expected:

- FAIL because the health model does not exist yet

- [ ] **Step 3: Add health structs and state machine**

Implement explicit protocol-specific health on top of existing `LegState`.

Add shapes like:

```rust
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum LegHealthState {
    Healthy,
    Degraded,
    Probation,
    Quarantined,
}

#[derive(Clone, Debug)]
struct ProtocolLegHealth {
    state: LegHealthState,
    score: f64,
    effective_weight: f64,
    delivered_bytes_ewma: f64,
    penalty_ewma: f64,
    last_reason: Option<String>,
    last_good_at: Instant,
    last_probe_at: Option<Instant>,
}
```

Then extend `LegState` with:

```rust
tcp_health: ProtocolLegHealth,
udp_health: ProtocolLegHealth,
recent_events: VecDeque<LegHealthEvent>,
```

- [ ] **Step 4: Wire success and failure signals into the health model**

In `session.rs`, update these paths:

- TCP success: `apply_ack(...)`
- TCP failure: `retransmit_pending(...)`, `recv_reset`, stream-open timeout paths
- UDP success: `acknowledge_udp_datagram(...)`
- UDP failure: `retransmit_udp_datagrams(...)`

Implement helpers like:

```rust
fn credit_tcp_delivery(legs: &mut [LegState], leg_id: usize, delivered_bytes: usize) {
    let Some(leg) = legs.get_mut(leg_id) else { return; };
    leg.tcp_health.delivered_bytes_ewma = ewma(leg.tcp_health.delivered_bytes_ewma, delivered_bytes as f64);
    leg.tcp_health.penalty_ewma = decay_penalty(leg.tcp_health.penalty_ewma);
    leg.tcp_health.last_reason = None;
    leg.tcp_health.last_good_at = Instant::now();
    recompute_tcp_leg_state(leg);
}

fn penalize_tcp_leg(legs: &mut [LegState], leg_id: usize, reason: TcpPenalty) {
    let Some(leg) = legs.get_mut(leg_id) else { return; };
    leg.tcp_health.penalty_ewma = apply_penalty(leg.tcp_health.penalty_ewma, reason.weight());
    leg.tcp_health.last_reason = Some(reason.as_str().to_string());
    recompute_tcp_leg_state(leg);
}

fn credit_udp_delivery(legs: &mut [LegState], leg_id: usize, delivered_bytes: usize) {
    let Some(leg) = legs.get_mut(leg_id) else { return; };
    leg.udp_health.delivered_bytes_ewma = ewma(leg.udp_health.delivered_bytes_ewma, delivered_bytes as f64);
    leg.udp_health.penalty_ewma = decay_penalty(leg.udp_health.penalty_ewma);
    leg.udp_health.last_reason = None;
    leg.udp_health.last_good_at = Instant::now();
    recompute_udp_leg_state(leg);
}

fn penalize_udp_leg(legs: &mut [LegState], leg_id: usize, reason: UdpPenalty) {
    let Some(leg) = legs.get_mut(leg_id) else { return; };
    leg.udp_health.penalty_ewma = apply_penalty(leg.udp_health.penalty_ewma, reason.weight());
    leg.udp_health.last_reason = Some(reason.as_str().to_string());
    recompute_udp_leg_state(leg);
}
```

- [ ] **Step 5: Make selectors health-aware**

Modify:

- `select_stream_leg(...)`
- `select_stream_leg_warmup(...)`
- `select_udp_leg(...)`
- `select_leg_with_mode_filtered(...)`

So ranking becomes health-first.

Use a shape like:

```rust
candidates.sort_by(|a, b| {
    leg_sort_key(a, protocol).cmp(&leg_sort_key(b, protocol))
});
```

Where the key includes:

- protocol state
- effective weight
- inflight bytes
- smoothed RTT
- static priority

- [ ] **Step 6: Emit local snapshot files**

Write helper(s) in `session.rs` that serialize current leg reputation to the agreed JSON schema.

Target paths:

- client forward runtime: `/run/leikwan/bond-leg-health-forward-26.json` for the first rollout, then generalize to per-forward filenames
- sidecar runtime: `/var/lib/leikwan-sidecar-4/bond-leg-health.json` for the first rollout, then generalize to per-sidecar state dirs

Use bounded recent events, cap 20.

- [ ] **Step 7: Re-run focused Rust tests**

Run:

```bash
cargo test -p leikwan-bond -- --nocapture
```

Expected:

- all new state-machine and snapshot tests PASS

- [ ] **Step 8: Commit Worker A changes**

```bash
git add wstunnel-main/leikwan-bond/src/session.rs wstunnel-main/leikwan-bond/src/main.rs
git commit -m "feat: add adaptive bond leg health model"
```

---

### Task 2: Worker B, plumb local snapshots into agent heartbeats

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `sidecar-agent/sidecar_agent.py`
- Test: `client-agent/tests/test_client_agent_mptcp_health_grace.py`
- Test: `client-agent/tests/test_mptcp_bond_regressions.py`

- [ ] **Step 1: Add the failing agent tests**

Add Python tests that prove:

- client agent reads `/run/leikwan/bond-leg-health-forward-26.json`
- sidecar agent reads `/var/lib/leikwan-sidecar-4/bond-leg-health.json`
- payload includes a top-level reputation block

Use a shape like:

```python
def test_client_heartbeat_includes_bond_leg_reputation_snapshot(self) -> None:
    snapshot = {
        "observer_type": "client",
        "observer_id": 1,
        "forward_id": 26,
        "legs": [{"endpoint_id": 185, "host": "8.163.90.235"}],
    }
    pathlib.Path("/run/leikwan/bond-leg-health-forward-26.json").write_text(json.dumps(snapshot))
    captured = {}
    def fake_http_json(method, url, token, payload=None):
        captured["payload"] = payload or {}
        return {"forwards": [], "client": {}}
    agent.sync_once = mock.Mock(side_effect=agent.sync_once)
    with mock.patch.object(client_agent_module, "http_json", side_effect=fake_http_json):
        agent.sync_once()
    self.assertEqual(captured["payload"]["bond_leg_reputation"]["forwards"][0]["forward_id"], 26)
```

- [ ] **Step 2: Run the focused Python tests to verify they fail**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions -v
```

Expected:

- FAIL because heartbeat payload has no reputation section yet

- [ ] **Step 3: Add snapshot readers**

In `client_agent.py`, add helper(s) like:

```python
def load_bond_leg_reputation_snapshots(self) -> list[dict[str, Any]]:
    snapshots = []
    for path in pathlib.Path("/run/leikwan").glob("bond-leg-health-forward-*.json"):
        try:
            snapshots.append(json.loads(path.read_text(encoding="utf-8")))
        except Exception:
            continue
    return snapshots
```

In `sidecar_agent.py`, add:

```python
def load_bond_leg_reputation_snapshot(self) -> dict[str, Any] | None:
    path = self.state_dir / "bond-leg-health.json"
    if not path.exists():
        return None
    return json.loads(path.read_text(encoding="utf-8"))
```

- [ ] **Step 4: Include reputation in heartbeat payloads**

Client payload should include:

```python
"bond_leg_reputation": {
    "forwards": self.load_bond_leg_reputation_snapshots(),
}
```

Sidecar payload should include:

```python
"bond_leg_reputation": self.load_bond_leg_reputation_snapshot()
```

- [ ] **Step 5: Re-run Python tests**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions -v
python -m py_compile client-agent/client_agent.py sidecar-agent/sidecar_agent.py
```

Expected:

- new payload tests PASS
- py_compile PASS

- [ ] **Step 6: Commit Worker B changes**

```bash
git add client-agent/client_agent.py sidecar-agent/sidecar_agent.py client-agent/tests/test_mptcp_bond_regressions.py
git commit -m "feat: include bond leg reputation in agent heartbeats"
```

---

### Task 3: Worker C, persist reputation in panel and ingest heartbeat payloads

**Files:**
- Create: `master/migrations/20260419_add_bond_leg_reputation.sql`
- Modify: `master/migrations/schema.sql`
- Create: `master/src/Support/BondLegReputationService.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/src/Controllers/SidecarAgentController.php`
- Test: `master/tests/bond_leg_reputation_persistence_test.php`

- [ ] **Step 1: Add failing PHP regression test for persistence**

Create a focused test that proves:

- client heartbeat inserts/updates `bond_leg_reputation_current`
- sidecar heartbeat appends `bond_leg_reputation_events`
- panel stores TCP and UDP independently

Use a shape like:

```php
<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$db = new TestDatabase();
$service = new \Leikwan\Master\Support\BondLegReputationService($db);
$service->ingestClientSnapshots(1, [[
    'observer_type' => 'client',
    'observer_id' => 1,
    'forward_id' => 26,
    'legs' => [[
        'endpoint_id' => 185,
        'host' => '8.163.90.235',
        'tcp' => ['state' => 'degraded', 'score' => 0.21, 'effective_weight' => 0.10],
        'udp' => ['state' => 'probation', 'score' => 0.08, 'effective_weight' => 0.05],
        'recent_events' => [[
            'protocol' => 'tcp',
            'from' => 'healthy',
            'to' => 'degraded',
            'reason' => 'control_socket_closed_unexpectedly',
            'observed_at' => '2026-04-19T14:29:33Z',
        ]],
    ]],
]]);
$current = $service->latestGlobalRows(10)[0] ?? [];
$event = $service->recentEvents([], 10)[0] ?? [];

if (($current['protocol'] ?? '') !== 'tcp') {
    fail('expected tcp reputation row');
}
if (($event['to_state'] ?? '') !== 'degraded') {
    fail('expected reputation event state transition');
}
```

- [ ] **Step 2: Run the failing PHP test**

Run:

```bash
php master/tests/bond_leg_reputation_persistence_test.php
```

Expected:

- FAIL because tables/service/controller persistence do not exist yet

- [ ] **Step 3: Add schema migration**

Create `master/migrations/20260419_add_bond_leg_reputation.sql` with:

```sql
CREATE TABLE bond_leg_reputation_current (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    observer_type VARCHAR(32) NOT NULL,
    observer_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    node_id BIGINT UNSIGNED NULL,
    host VARCHAR(255) NOT NULL,
    state VARCHAR(32) NOT NULL,
    score DECIMAL(10,4) NOT NULL DEFAULT 0,
    effective_weight DECIMAL(10,4) NOT NULL DEFAULT 0,
    delivered_ewma DECIMAL(18,4) NOT NULL DEFAULT 0,
    penalty_ewma DECIMAL(18,4) NOT NULL DEFAULT 0,
    last_reason TEXT NULL,
    last_good_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_current_leg (observer_type, observer_id, forward_id, endpoint_id, protocol)
);

CREATE TABLE bond_leg_reputation_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    observer_type VARCHAR(32) NOT NULL,
    observer_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    endpoint_id BIGINT UNSIGNED NOT NULL,
    protocol VARCHAR(16) NOT NULL,
    host VARCHAR(255) NOT NULL,
    from_state VARCHAR(32) NULL,
    to_state VARCHAR(32) NOT NULL,
    score DECIMAL(10,4) NOT NULL DEFAULT 0,
    effective_weight DECIMAL(10,4) NOT NULL DEFAULT 0,
    reason TEXT NULL,
    observed_at DATETIME NOT NULL
);
```

Mirror the tables into `master/migrations/schema.sql`.

- [ ] **Step 4: Add a dedicated persistence service**

Create `master/src/Support/BondLegReputationService.php` with explicit methods like:

```php
public function ingestClientSnapshots(int $clientId, array $snapshots): void {}
public function ingestSidecarSnapshot(int $sidecarId, array $snapshot): void {}
public function latestGlobalRows(int $limit = 20): array {}
public function recentEvents(array $filters = [], int $limit = 50): array {}
public function scopedRowsForTenant(int $userId): array {}
```

Keep the service narrow. No routing logic. No policy decisions.

- [ ] **Step 5: Wire controller heartbeat ingestion**

In:

- `ClientAgentController::heartbeat()`
- `SidecarAgentController::heartbeat()`

Read the new heartbeat payload field and call the service.

Shape:

```php
$reputation = $request->input('bond_leg_reputation', []);
if (is_array($reputation) && $reputation !== []) {
    (new BondLegReputationService($this->db))->ingestClientSnapshots((int) $client['id'], $reputation['forwards'] ?? []);
}
```

Use the sidecar equivalent in `SidecarAgentController`.

- [ ] **Step 6: Re-run PHP tests**

Run:

```bash
php master/tests/bond_leg_reputation_persistence_test.php
```

Expected:

- PASS

- [ ] **Step 7: Commit Worker C changes**

```bash
git add master/migrations/20260419_add_bond_leg_reputation.sql master/migrations/schema.sql master/src/Support/BondLegReputationService.php master/src/Controllers/ClientAgentController.php master/src/Controllers/SidecarAgentController.php master/tests/bond_leg_reputation_persistence_test.php
git commit -m "feat: persist bond leg reputation snapshots"
```

---

### Task 4: Worker D, add admin and tenant operational UI

**Files:**
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Controllers/TenantController.php`
- Test: `master/tests/admin_bond_leg_reputation_dashboard_test.php`
- Test: `master/tests/admin_pending_task_purge_test.php`
- Test: `master/tests/tenant_bond_leg_reputation_visibility_test.php`

- [ ] **Step 1: Add failing admin/tenant regression tests**

Create tests proving:

- `/admin` contains `Task Queue Health`
- stale pending purge action is present and bounded to `pending`
- recent failed task detail board renders
- tenant view is scoped to own legs only

Use snippets like:

```php
if (!str_contains($adminHtml, 'Task Queue Health')) {
    fail('expected admin dashboard task queue section');
}
if (!str_contains($tenantHtml, 'Reference only')) {
    fail('expected tenant reputation trust copy');
}
```

- [ ] **Step 2: Run failing admin/tenant tests**

Run:

```bash
php master/tests/admin_bond_leg_reputation_dashboard_test.php
php master/tests/admin_pending_task_purge_test.php
php master/tests/tenant_bond_leg_reputation_visibility_test.php
```

Expected:

- FAIL because the UI sections and purge action do not exist yet

- [ ] **Step 3: Add admin index sections**

In `AdminController::dashboard()` add:

- `Task Queue Health`
- `Recent Failed Tasks`
- `Worst Legs Right Now`

Use existing panel style:

- hero
- summary cards
- table-wrap
- inline actions

Do not invent a new dashboard style.

- [ ] **Step 4: Add stale pending purge handler**

Add an admin POST action in `AdminController`:

```php
public function purgeStaleProvisionTasks(Request $request, array $params): Response
```

Behavior:

- validate CSRF
- accept threshold in minutes
- delete only `status = pending`
- never touch `running`
- flash deleted/skipped counts

- [ ] **Step 5: Add tenant-scoped reputation section**

In `TenantController`, reuse the existing diagnostics surface on `/tenant/clients`.

Add a new section showing:

- scoped current TCP/UDP states
- recent changes
- calm reference-only explanation

Required trust copy:

```html
<div class="muted">Reference only. Runtime already adapts automatically when a leg degrades. You usually do not need to take action unless the line itself is failing.</div>
```

- [ ] **Step 6: Re-run PHP UI tests**

Run:

```bash
php master/tests/admin_bond_leg_reputation_dashboard_test.php
php master/tests/admin_pending_task_purge_test.php
php master/tests/tenant_bond_leg_reputation_visibility_test.php
```

Expected:

- PASS

- [ ] **Step 7: Commit Worker D changes**

```bash
git add master/src/Controllers/AdminController.php master/src/Controllers/TenantController.php master/tests/admin_bond_leg_reputation_dashboard_test.php master/tests/admin_pending_task_purge_test.php master/tests/tenant_bond_leg_reputation_visibility_test.php
git commit -m "feat: add bond leg reputation operational views"
```

---

### Task 5: Main integrator, merge worker branches and fix interface mismatches

**Files:**
- Modify: files from Tasks 1-4 only if merge conflicts occur, most likely:
  - `wstunnel-main/leikwan-bond/src/session.rs`
  - `client-agent/client_agent.py`
  - `sidecar-agent/sidecar_agent.py`
  - `master/src/Controllers/ClientAgentController.php`
  - `master/src/Controllers/SidecarAgentController.php`
  - `master/src/Controllers/AdminController.php`
  - `master/src/Controllers/TenantController.php`

- [ ] **Step 1: Merge Worker A first**

Run:

```bash
git merge worker-a-runtime-health
```

Expected:

- runtime health model lands first

- [ ] **Step 2: Merge Worker B and Worker C**

Run:

```bash
git merge worker-b-agent-heartbeats
git merge worker-c-panel-persistence
```

If a heartbeat payload field name differs from the locked contract, fix it now.

- [ ] **Step 3: Merge Worker D**

Run:

```bash
git merge worker-d-admin-tenant-ui
```

Ensure:

- admin routes and handlers compile
- tenant diagnostics still render

- [ ] **Step 4: Run full verification**

Run:

```bash
cargo test -p leikwan-bond -- --nocapture
python -m unittest client-agent.tests.test_mptcp_bond_regressions -v
python -m py_compile client-agent/client_agent.py sidecar-agent/sidecar_agent.py
php master/tests/bond_leg_reputation_persistence_test.php
php master/tests/admin_bond_leg_reputation_dashboard_test.php
php master/tests/admin_pending_task_purge_test.php
php master/tests/tenant_bond_leg_reputation_visibility_test.php
```

Expected:

- all PASS

- [ ] **Step 5: Run live-style smoke verification notes**

Document the commands the operator should run after deployment:

```bash
cat /run/leikwan/bond-leg-health-forward-26.json | jq .
cat /var/lib/leikwan-sidecar-4/bond-leg-health.json | jq .
```

And verify:

- panel shows current reputation rows
- admin `/admin` shows task queue health and recent failed tasks
- tenant diagnostics shows scoped reputation only

- [ ] **Step 6: Commit integration**

```bash
git add .
git commit -m "feat: ship adaptive bond leg reputation and ops boards"
```

---

### Task 6: Main integrator, write the execution manual

**Files:**
- Create: `docs/2026-04-19-bond-leg-reputation-and-task-ops-manual.md`

- [ ] **Step 1: Write the ops manual**

Include sections:

- what each leg state means
- TCP vs UDP independent health
- what admin sees on `/admin`
- how stale pending purge works
- when not to purge
- how tenant reputation should be interpreted
- how to verify runtime snapshot health locally

Use headings like:

```md
## How adaptive leg states work
## Reading admin task queue health
## Safe use of stale pending purge
## How tenant-scoped reputation should be interpreted
## Verification checklist after deployment
```

- [ ] **Step 2: Sanity-check the manual**

Run:

```bash
rg -n "TODO|TBD" docs/2026-04-19-bond-leg-reputation-and-task-ops-manual.md
```

Expected:

- no matches

- [ ] **Step 3: Commit the manual**

```bash
git add docs/2026-04-19-bond-leg-reputation-and-task-ops-manual.md
git commit -m "docs: add bond leg reputation ops manual"
```

---

## Spec coverage checklist

- adaptive per-protocol health model: Task 1
- self-adjusting indicators: Task 1
- panel-only global reputation library: Tasks 2-4
- admin queue board: Task 4
- stale pending purge: Task 4
- task error detail board: Task 4
- tenant-scoped visibility: Task 4
- multi-agent decomposition: task ownership above
- execution manual: Task 6

## Recommended execution mode

Use **Subagent-Driven**.

This plan is built for multiple AI agents with disjoint ownership. Worker A can start first. Worker B and Worker C can begin once the snapshot schema from Task 1 is stable. Worker D can start once Worker C’s persistence service interface is fixed.
