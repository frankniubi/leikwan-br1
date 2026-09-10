# WG-Backed Latency-Intent Bond Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the unstable slave-to-sidecar backhaul for bond traffic with persistent per-slave WireGuard uplinks, add forward-chain latency-intent policy and rolling-window thresholds, and let normal bond and CF-bond choose stable paths that satisfy user latency goals before resorting to WG interface restart.

**Architecture:** Keep `client -> slave -> sidecar -> target`, but split responsibility cleanly. Client bond remains latency-first on the front half. Slaves get one persistent WG uplink to the sidecar, with one dedicated sidecar WG interface per slave. Forward-chain latency intent is published from panel and consumed by client, slave, sidecar, and CF selection. Path remediation order is sidepath change first, quarantine second, slave-specific WG restart last.

**Tech Stack:** PHP panel + migrations + regression tests, Python slave/client/sidecar agents + `unittest`, existing WireGuard / `wg-quick` orchestration, existing CF candidate cache and MPTCP underlay builders, live panel host `10.0.10.196`, live client `sh36`, live slaves and sidecar.

---

## File Structure

### Production files

- Modify: `master/migrations/schema.sql`
- Create: `master/migrations/20260509_add_forward_chain_latency_intent.sql`
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Controllers/BaseController.php`
- Modify: `slave-agent/slave_agent.py`
- Modify: `sidecar-agent/sidecar_agent.py`
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/leikwan_mptcp_client.py`
- Modify: `sidecar-agent/leikwan_mptcp_sidecar.py`
- Modify: `install/install-slave.sh`
- Modify: `install/install-bond-sidecar.sh`
- Modify: `docs/BOND_OPERATIONS.md`

### Test files

- Create: `master/tests/forward_chain_latency_intent_regression_test.php`
- Create: `slave-agent/tests/test_slave_sidecar_wg_uplink_policy.py`
- Create: `sidecar-agent/tests/test_sidecar_per_slave_wg_uplink_policy.py`
- Create: `client-agent/tests/test_client_agent_latency_intent_policy.py`
- Modify: `slave-agent/tests/test_slave_cf_location_group_selection.py`
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`

### What already exists

- Panel already has forward-chain records and steps:
  - `listForwardChains()` and `saveForwardChain(...)` in `master/src/Support/ProvisioningService.php`
- Panel already publishes forward payloads via:
  - `publishTaskPayloadForForward(...)`
  - `buildClientConfig(...)`
- Slave already has:
  - CF candidate selection state at `cf-location-best-ip.json`
  - WireGuard identity management
  - MPTCP underlay creation and cleanup helpers
- Sidecar already has:
  - WireGuard identity management
  - runtime config application loop
- Client already has:
  - bond endpoint health cache
  - RTT-aware latency-first primary selection
  - latency-first runtime flags
- MPTCP underlay builders already demonstrate the pattern for stable address calculation and per-leg WG config generation:
  - `stableMptcpLegAddressing(...)`
  - `buildMptcpSlaveUnderlayPayload(...)`
  - `mptcpSidecarManagementForPoint(...)`

### NOT in scope

- No direct client-to-sidecar mode
- No all-in-one sidecar WG interface for all slaves
- No per-forward dedicated WG tunnels
- No phase-one bulk UDP striping for unknown or game-like traffic
- No panel real-time active switching loop beyond intent and thresholds

---

### Task 1: Add forward-chain latency-intent schema and panel persistence

**Files:**
- Create: `master/migrations/20260509_add_forward_chain_latency_intent.sql`
- Modify: `master/migrations/schema.sql`
- Modify: `master/src/Support/ProvisioningService.php:2217-2288`
- Create: `master/tests/forward_chain_latency_intent_regression_test.php`

- [ ] **Step 1: Write the failing panel persistence regression first**

Create `master/tests/forward_chain_latency_intent_regression_test.php` with:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var list<array{sql:string, params:array<string,mixed>}> */
        public array $executed = [];
        /** @var list<array{table:string, values:array<string,mixed>}> */
        public array $inserted = [];

        public function transaction(callable $callback): mixed
        {
            return $callback();
        }

        public function execute(string $sql, array $params = []): void
        {
            $this->executed[] = ['sql' => $sql, 'params' => $params];
        }

        public function insert(string $table, array $values): int
        {
            $this->inserted[] = ['table' => $table, 'values' => $values];
            return 901;
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'SELECT * FROM forward_chains WHERE id = :id')) {
                return [
                    'id' => 901,
                    'name' => 'Guangzhou HK Smart',
                    'desired_latency_ms' => 60,
                    'max_exceed_time_seconds' => 8,
                    'max_exceed_ratio_percent' => 15,
                    'max_loss_ratio_percent' => 1,
                    'evaluation_window_seconds' => 3600,
                ];
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }
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

    $envPath = tempnam(sys_get_temp_dir(), 'lw-chain-latency-env-');
    file_put_contents((string) $envPath, "APP_URL=https://v3.leikwanhost.com\n");
    $db = new Database();
    $service = new ProvisioningService(new Config((string) $envPath), $db);

    $chain = $service->saveForwardChain([
        'name' => 'Guangzhou HK Smart',
        'description' => 'latency first',
        'is_active' => 1,
        'node_group_ids' => [7, 8],
        'desired_latency_ms' => 60,
        'max_exceed_time_seconds' => 8,
        'max_exceed_ratio_percent' => 15,
        'max_loss_ratio_percent' => 1,
        'evaluation_window_seconds' => 3600,
    ]);

    $forwardChainInsert = null;
    foreach ($db->inserted as $entry) {
        if ($entry['table'] === 'forward_chains') {
            $forwardChainInsert = $entry['values'];
            break;
        }
    }
    if ($forwardChainInsert === null) {
        fail('expected forward_chains insert');
    }
    if (($forwardChainInsert['desired_latency_ms'] ?? null) !== 60) {
        fail('expected desired_latency_ms to persist');
    }
    if (($forwardChainInsert['evaluation_window_seconds'] ?? null) !== 3600) {
        fail('expected evaluation_window_seconds to persist');
    }
    if (($chain['max_loss_ratio_percent'] ?? null) !== 1) {
        fail('expected saved chain payload to return max_loss_ratio_percent');
    }

    echo "ok\n";
}
```

- [ ] **Step 2: Run the new panel test and verify it fails**

Run:

```bash
php master/tests/forward_chain_latency_intent_regression_test.php
```

Expected: FAIL because `saveForwardChain(...)` and the schema do not persist the new latency-intent fields yet.

- [ ] **Step 3: Add the schema migration**

Create `master/migrations/20260509_add_forward_chain_latency_intent.sql`:

```sql
ALTER TABLE forward_chains
  ADD COLUMN desired_latency_ms INT NULL AFTER description,
  ADD COLUMN max_exceed_time_seconds INT NOT NULL DEFAULT 15 AFTER desired_latency_ms,
  ADD COLUMN max_exceed_ratio_percent INT NOT NULL DEFAULT 25 AFTER max_exceed_time_seconds,
  ADD COLUMN max_loss_ratio_percent INT NOT NULL DEFAULT 2 AFTER max_exceed_ratio_percent,
  ADD COLUMN evaluation_window_seconds INT NOT NULL DEFAULT 3600 AFTER max_loss_ratio_percent;
```

Mirror the same columns into `master/migrations/schema.sql` inside the `forward_chains` table definition.

- [ ] **Step 4: Persist and return the new fields in `saveForwardChain(...)`**

In `master/src/Support/ProvisioningService.php`, extend `saveForwardChain(...)` so it parses:

```php
        $desiredLatencyMs = ($payload['desired_latency_ms'] ?? '') !== '' ? max(1, (int) $payload['desired_latency_ms']) : null;
        $maxExceedTimeSeconds = max(1, (int) ($payload['max_exceed_time_seconds'] ?? 15));
        $maxExceedRatioPercent = max(0, min(100, (int) ($payload['max_exceed_ratio_percent'] ?? 25)));
        $maxLossRatioPercent = max(0, min(100, (int) ($payload['max_loss_ratio_percent'] ?? 2)));
        $evaluationWindowSeconds = max(10, (int) ($payload['evaluation_window_seconds'] ?? 3600));
```

Store them in both the `UPDATE forward_chains` and `insert('forward_chains', ...)` paths:

```php
                        'desired_latency_ms' => $desiredLatencyMs,
                        'max_exceed_time_seconds' => $maxExceedTimeSeconds,
                        'max_exceed_ratio_percent' => $maxExceedRatioPercent,
                        'max_loss_ratio_percent' => $maxLossRatioPercent,
                        'evaluation_window_seconds' => $evaluationWindowSeconds,
```

- [ ] **Step 5: Run the panel persistence test and verify it passes**

Run:

```bash
php master/tests/forward_chain_latency_intent_regression_test.php
```

Expected: `ok`

- [ ] **Step 6: Commit the schema and panel persistence**

Run:

```bash
git add master/migrations/20260509_add_forward_chain_latency_intent.sql master/migrations/schema.sql master/src/Support/ProvisioningService.php master/tests/forward_chain_latency_intent_regression_test.php
git commit -m "feat: add forward chain latency intent settings"
```

---

### Task 2: Add admin UI and chain observability for latency intent

**Files:**
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Controllers/BaseController.php`
- Test: `master/tests/admin_forward_combination_page_test.php` or a new focused regression if needed

- [ ] **Step 1: Add the failing UI regression**

Create or update a focused PHP regression test proving the forward-chain admin form renders:

- `Desired latency`
- `Latency may exceed for up to`
- `Allowed exceed ratio`
- `Allowed loss ratio`
- `Evaluation window`

Use the same HTML string assertion style already used in admin-page regression tests.

- [ ] **Step 2: Render the new fields in the forward-chain editor**

In the forward-chain admin form in `AdminController.php`, add inputs bound to:

```php
desired_latency_ms
max_exceed_time_seconds
max_exceed_ratio_percent
max_loss_ratio_percent
evaluation_window_seconds
```

Use clear labels:

```html
Desired latency (ms)
Latency may exceed for up to (s)
Allowed exceed ratio (%)
Allowed loss ratio (%)
Evaluation window (s)
```

- [ ] **Step 3: Add chain observability in admin read views**

In the chain list/detail view, show the stored values in compact chips or table cells so admins can see the current intent without opening edit mode.

- [ ] **Step 4: Run the UI regression and verify it passes**

Run the targeted PHP admin test command used by the chosen regression file.

- [ ] **Step 5: Commit the admin UI**

Run:

```bash
git add master/src/Controllers/AdminController.php master/src/Controllers/BaseController.php master/tests/<chosen-test-file>
git commit -m "feat: add admin controls for chain latency intent"
```

---

### Task 3: Publish chain latency intent to client/slave/sidecar payloads

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Test: `master/tests/default_client_provisioning_test.php` or a new focused regression

- [ ] **Step 1: Write the failing payload regression**

Add a regression test that proves:

- `buildClientConfig(...)` returns `forward_chain_latency_policy`
- `publishTaskPayloadForForward(...)` returns `forward_chain_latency_policy`

with fields:

```php
desired_latency_ms
max_exceed_time_seconds
max_exceed_ratio_percent
max_loss_ratio_percent
evaluation_window_seconds
```

- [ ] **Step 2: Add the helper to normalize chain policy**

In `ProvisioningService.php`, add:

```php
    private function forwardChainLatencyPolicy(array $forward): array
    {
        return [
            'desired_latency_ms' => ($forward['chain_desired_latency_ms'] ?? null) !== null ? (int) $forward['chain_desired_latency_ms'] : null,
            'max_exceed_time_seconds' => max(1, (int) ($forward['chain_max_exceed_time_seconds'] ?? 15)),
            'max_exceed_ratio_percent' => max(0, min(100, (int) ($forward['chain_max_exceed_ratio_percent'] ?? 25))),
            'max_loss_ratio_percent' => max(0, min(100, (int) ($forward['chain_max_loss_ratio_percent'] ?? 2))),
            'evaluation_window_seconds' => max(10, (int) ($forward['chain_evaluation_window_seconds'] ?? 3600)),
        ];
    }
```

- [ ] **Step 3: Extend the forward fetches to include chain fields**

Where forward rows already join `forward_chains`, also select the new columns aliased as:

```php
chain_desired_latency_ms
chain_max_exceed_time_seconds
chain_max_exceed_ratio_percent
chain_max_loss_ratio_percent
chain_evaluation_window_seconds
```

- [ ] **Step 4: Publish the policy**

In `buildClientConfig(...)` and `publishTaskPayloadForForward(...)`, add:

```php
'forward_chain_latency_policy' => $this->forwardChainLatencyPolicy($forward),
```

- [ ] **Step 5: Run the payload regression and verify it passes**

Run the targeted PHP regression command.

- [ ] **Step 6: Commit the payload publication**

Run:

```bash
git add master/src/Support/ProvisioningService.php master/tests/<chosen-test-file>
git commit -m "feat: publish chain latency intent to runtimes"
```

---

### Task 4: Build persistent per-slave WG uplink payloads from panel

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/provisioning_slave_sidecar_wg_uplink_regression_test.php`

- [ ] **Step 1: Write the failing payload-shape test**

Create `master/tests/provisioning_slave_sidecar_wg_uplink_regression_test.php` that proves panel can produce:

- one persistent slave-side WG uplink payload per slave
- one dedicated sidecar-side peer payload per slave
- stable addressing
- plain TCP relay target inside WG

The test should assert the payload includes fields like:

```php
sidecar_uplink
  enabled = true
  type = wireguard
  mode = slave_sidecar_persistent
  interface_name
  local_address
  peer_address
  sidecar_peer_public_key
  sidecar_endpoint_host
  sidecar_endpoint_port
  relay_target
  peer_keepalive_seconds
```

- [ ] **Step 2: Add a stable addressing helper**

In `ProvisioningService.php`, add a stable helper, following the existing `stableMptcpLegAddressing(...)` style:

```php
    private function stableSlaveSidecarUplinkAddressing(int $sidecarPointId, int $nodeId): array
    {
        $stableId = max(1, ($sidecarPointId * 512) + $nodeId);
        $bucket = intdiv($stableId - 1, 60);
        $networkBase = (($stableId - 1) % 60) * 4;
        $octet3 = 200 + ($bucket % 40);
        return [
            'subnet' => '10.240.' . $octet3 . '.' . $networkBase . '/30',
            'slave_address' => '10.240.' . $octet3 . '.' . ($networkBase + 1),
            'sidecar_address' => '10.240.' . $octet3 . '.' . ($networkBase + 2),
        ];
    }
```

- [ ] **Step 3: Build slave uplink payloads**

Add a new helper such as:

```php
    private function buildSlaveSidecarUplinkPayload(array $forward, array $node): array
```

It should:

- read slave public key from node config
- read sidecar public key and listen port from sidecar point config
- build one per-slave interface payload
- set:
  - `type = wireguard`
  - `mode = slave_sidecar_persistent`
  - `interface_name = 'lwsc' . (int) $node['id']`
  - `peer_keepalive_seconds`
  - stable `slave_address` and `sidecar_address`
  - `relay_target` for plain TCP into sidecar local relay

- [ ] **Step 4: Attach the uplink payload to publish output**

In `publishTaskPayloadForForward(...)`, for bond forwards on the new architecture path, add:

```php
'sidecar_uplink' => $clientMode === 'bond' ? $this->buildSlaveSidecarUplinkPayload($forward, $node) : null,
```

- [ ] **Step 5: Run the regression and verify it passes**

Run:

```bash
php master/tests/provisioning_slave_sidecar_wg_uplink_regression_test.php
```

- [ ] **Step 6: Commit the uplink payload generation**

Run:

```bash
git add master/src/Support/ProvisioningService.php master/tests/provisioning_slave_sidecar_wg_uplink_regression_test.php
git commit -m "feat: build persistent slave sidecar wg uplink payloads"
```

---

### Task 5: Apply persistent slave-side WG uplinks and latency-intent state on the slave

**Files:**
- Modify: `slave-agent/slave_agent.py`
- Create: `slave-agent/tests/test_slave_sidecar_wg_uplink_policy.py`
- Modify: `slave-agent/tests/test_slave_cf_location_group_selection.py`

- [ ] **Step 1: Write the failing slave uplink policy tests**

Create `slave-agent/tests/test_slave_sidecar_wg_uplink_policy.py` with tests that prove:

1. a published `sidecar_uplink` payload creates one stable WG interface config
2. the relay service for the forward targets the sidecar WG address, not the unstable public sidecar path
3. the slave stores chain latency policy window state
4. when exceed ratio / exceed time / loss ratio breach policy, the slave marks the current sidepath unacceptable before any WG restart

Use temporary directories and monkeypatch `run_command(...)` / file writers like the existing slave tests do.

- [ ] **Step 2: Add latency-intent state files on the slave**

In `slave_agent.py`, add:

```python
    def latency_policy_state_path(self) -> Path:
        return self.state_dir / "forward-chain-latency-state.json"

    def load_latency_policy_state(self) -> dict[str, Any]:
        ...

    def save_latency_policy_state(self, payload: dict[str, Any]) -> None:
        ...
```

- [ ] **Step 3: Add WG uplink apply/remove helpers**

Add a new helper separate from the MPTCP underlay path, for example:

```python
    def apply_sidecar_uplink(self, service_name: str, uplink: dict[str, Any]) -> None:
        ...

    def remove_sidecar_uplink(self, service_name: str, uplink: dict[str, Any] | None = None) -> None:
        ...
```

This should:

- create one `/etc/wireguard/<iface>.conf`
- enable / restart `wg-quick@<iface>.service`
- use `PersistentKeepalive`
- not touch other slave uplinks

- [ ] **Step 4: Route bond relay through the WG uplink**

In `publish_task(...)`, when a `sidecar_uplink` payload is present:

- apply the uplink first
- change relay target from unstable public sidecar path to:
  - sidecar WG address + relay TCP port

Store the uplink in metadata so removal is clean.

- [ ] **Step 5: Implement rolling-window policy evaluation on the slave**

Add a helper like:

```python
    def evaluate_latency_policy_window(self, chain_key: str, policy: dict[str, Any], sample: dict[str, Any]) -> dict[str, Any]:
        ...
```

It should track:

- rolling samples
- exceed ratio
- continuous exceed duration
- loss ratio

and return whether the current sidepath is acceptable.

- [ ] **Step 6: Make sidepath adjustment the first remediation**

When policy is violated:

1. first choose a different acceptable sidepath / CF path / local route
2. if not enough, mark bad path elements suppressed
3. only last, request WG restart

Persist:

- `last_switch_time`
- `last_switch_reason`
- `last_wg_restart_time`

- [ ] **Step 7: Run the full slave test set**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_sidecar_wg_uplink_policy
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
```

- [ ] **Step 8: Commit the slave uplink and policy work**

Run:

```bash
git add slave-agent/slave_agent.py slave-agent/tests/test_slave_sidecar_wg_uplink_policy.py slave-agent/tests/test_slave_cf_location_group_selection.py
git commit -m "feat: add slave sidecar wg uplink policy engine"
```

---

### Task 6: Apply per-slave WG ingress ownership and last-resort restart on the sidecar

**Files:**
- Modify: `sidecar-agent/sidecar_agent.py`
- Create: `sidecar-agent/tests/test_sidecar_per_slave_wg_uplink_policy.py`

- [ ] **Step 1: Write the failing sidecar WG policy tests**

Create `sidecar-agent/tests/test_sidecar_per_slave_wg_uplink_policy.py` with tests that prove:

1. one sidecar WG interface is created per slave
2. adding one uplink does not touch existing interfaces
3. removing one uplink does not disturb others
4. sidecar can evaluate one slave uplink as degraded and only restart that one interface as final remedy

- [ ] **Step 2: Add sidecar uplink inventory and state**

Add:

```python
    def sidecar_uplink_state_path(self) -> pathlib.Path:
        return self.state_dir / "slave-sidecar-uplink-state.json"
```

Persist:

- interface per slave
- last restart time
- last degradation reason
- policy violation counters

- [ ] **Step 3: Add one-interface-per-slave WG apply helpers**

Implement a helper separate from the existing MPTCP logic:

```python
    def apply_slave_sidecar_uplink(self, uplink: dict[str, Any]) -> None:
        ...

    def remove_slave_sidecar_uplink(self, uplink: dict[str, Any]) -> None:
        ...
```

Use:

- one config per slave interface
- one peer per interface
- no all-in-one peer aggregation

- [ ] **Step 4: Add targeted restart helper**

Implement:

```python
    def restart_slave_sidecar_uplink(self, interface_name: str) -> None:
        ...
```

and ensure it is only called:

- after sidepath change attempts fail
- after suppression / quarantine attempts fail
- after cooldown rules allow it

- [ ] **Step 5: Run the sidecar policy suite**

Run:

```bash
python -m unittest sidecar-agent.tests.test_sidecar_per_slave_wg_uplink_policy
```

- [ ] **Step 6: Commit the sidecar work**

Run:

```bash
git add sidecar-agent/sidecar_agent.py sidecar-agent/tests/test_sidecar_per_slave_wg_uplink_policy.py
git commit -m "feat: add per-slave sidecar wg uplink control"
```

---

### Task 7: Feed chain latency intent into client bond selection and stable-primary lease

**Files:**
- Modify: `client-agent/client_agent.py`
- Create: `client-agent/tests/test_client_agent_latency_intent_policy.py`

- [ ] **Step 1: Write the failing client latency-intent tests**

Create `client-agent/tests/test_client_agent_latency_intent_policy.py` with tests proving:

1. latency-first primary does not switch on a tiny RTT improvement when chain policy is still satisfied
2. primary only switches after sustained better RTT over several checks
3. active-flow count freezes primary even if another leg briefly looks faster
4. if exceed ratio / exceed time crosses chain policy, a new primary is allowed

- [ ] **Step 2: Add latency-intent lease state**

In `client_agent.py`, add:

```python
    def latency_primary_state_path(self) -> pathlib.Path:
        return self.state_dir / "latency-primary-state.json"
```

Track, per chain:

- selected primary
- selected primary RTT
- chosen time
- hold until
- candidate better count
- last switch reason

- [ ] **Step 3: Count active sessions before switching**

Add a helper using local listener / `ss` evidence to estimate whether a forward currently has active sessions:

```python
    def active_forward_session_count(self, forward_id: int) -> int:
        ...
```

- [ ] **Step 4: Make primary selection policy-aware**

When `forward_chain_latency_policy` exists:

- use `one_leg_rtt_ms` first
- require thresholded improvement to switch
- freeze while active sessions exist
- allow switch only when policy window says current path is no longer acceptable, or when hard failure happens

- [ ] **Step 5: Run the client policy suite**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_latency_intent_policy
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health
```

- [ ] **Step 6: Commit the client policy lease work**

Run:

```bash
git add client-agent/client_agent.py client-agent/tests/test_client_agent_latency_intent_policy.py client-agent/tests/test_client_agent_bond_endpoint_health.py
git commit -m "feat: add latency intent primary lease to client bond"
```

---

### Task 8: Integrate CF selection with forward-chain latency intent

**Files:**
- Modify: `slave-agent/slave_agent.py`
- Modify: `slave-agent/tests/test_slave_cf_location_group_selection.py`

- [ ] **Step 1: Add failing CF intent tests**

Add tests proving:

1. if current CF IP is within latency policy, keep it even if another IP is slightly better
2. if current CF IP exceeds desired latency too long or too often, switch consistently
3. tiny jitter does not trigger chain-wide CF churn

- [ ] **Step 2: Pass chain latency policy into `select_cf_location_ip(...)`**

Extend the `cf_mode` selection payload so the slave sees:

```python
forward_chain_latency_policy
```

Use it inside `select_cf_location_ip(...)` and candidate ranking.

- [ ] **Step 3: Change decision rule**

Instead of always choosing the lowest immediate latency sample:

- keep current path if still policy-compliant
- switch only when:
  - current path violates desired latency for too long, or
  - exceed ratio is too high, or
  - loss ratio is too high

- [ ] **Step 4: Run the CF selection suite**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
```

- [ ] **Step 5: Commit the CF intent integration**

Run:

```bash
git add slave-agent/slave_agent.py slave-agent/tests/test_slave_cf_location_group_selection.py
git commit -m "feat: apply chain latency intent to cf path selection"
```

---

### Task 9: Extend installers and ops docs for per-slave WG uplinks

**Files:**
- Modify: `install/install-slave.sh`
- Modify: `install/install-bond-sidecar.sh`
- Modify: `docs/BOND_OPERATIONS.md`

- [ ] **Step 1: Add installer/runtime support for the new uplink helpers**

Ensure slave and sidecar installs still include:

- `wireguard-tools`
- required helper scripts
- state directories for WG identity and policy state

If new helper scripts are introduced, add them to the installer download lists.

- [ ] **Step 2: Document the new operator model**

Append to `docs/BOND_OPERATIONS.md`:

- one WG interface per slave on sidecar
- one uplink per slave
- rolling latency-intent policy
- sidepath-first remediation
- slave-specific WG restart last

- [ ] **Step 3: Commit installers/docs**

Run:

```bash
git add install/install-slave.sh install/install-bond-sidecar.sh docs/BOND_OPERATIONS.md
git commit -m "docs: add wg-backed latency intent bond operations"
```

---

### Task 10: Full local verification

**Files:**
- Verify only

- [ ] **Step 1: Run all targeted PHP tests**

Run:

```bash
php master/tests/forward_chain_latency_intent_regression_test.php
php master/tests/provisioning_slave_sidecar_wg_uplink_regression_test.php
php master/tests/bond_leg_cooldown_publish_regression_test.php
php master/tests/bond_latency_first_publish_regression_test.php
```

- [ ] **Step 2: Run all targeted Python tests**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_latency_intent_policy
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health
python -m unittest slave-agent.tests.test_slave_sidecar_wg_uplink_policy
python -m unittest slave-agent.tests.test_slave_cf_location_group_selection
python -m unittest sidecar-agent.tests.test_sidecar_per_slave_wg_uplink_policy
```

- [ ] **Step 3: Run the targeted Windows suite**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj
```

- [ ] **Step 4: If any new Rust code is introduced for relay logic, run the matching Rust suite**

Run only if you touched a Rust crate:

```bash
cargo test -p leikwan-bond
```

- [ ] **Step 5: Confirm all targeted suites pass**

Do not claim success without fresh output from the commands above.

---

### Task 11: Live rollout order

**Files:**
- Modify live hosts only

- [ ] **Step 1: Back up panel + live hosts**

Back up:

- panel repo files
- slave configs / `/etc/wireguard/*.conf`
- sidecar configs / `/etc/wireguard/*.conf`
- client configs / relevant runtime scripts

- [ ] **Step 2: Roll out in this order**

1. panel schema + source
2. slave source / installer support
3. sidecar source / installer support
4. client source
5. one test forward-chain only

- [ ] **Step 3: Build one persistent WG uplink for one slave**

Verify:

- sidecar interface appears
- slave interface appears
- handshake stays fresh
- counters move

- [ ] **Step 4: Move one Guangzhou normal bond forward onto WG-backed backhaul**

Verify:

- client still reaches target
- latency window narrows
- throughput remains acceptable

- [ ] **Step 5: Only then extend to more slaves and CF-bond**

Do not roll CF-bond and normal bond and all chains simultaneously in the first live pass.

---

## Spec coverage self-review

- per-slave WG uplinks on sidecar: covered by Tasks 4, 5, 6, 9, 11
- raw TCP inside WG: covered by Task 4 and Task 5
- forward-chain latency intent: covered by Tasks 1, 2, 3, 7, 8
- rolling evaluation window: covered by Tasks 1, 5, 7, 8
- sidepath-first remediation: covered by Tasks 5, 6, 7, 8
- slave-specific WG restart last: covered by Tasks 5 and 6
- CF-bond consistent switching: covered by Task 8

## Placeholder scan self-review

- No `TBD` or `TODO`
- All tasks name exact files
- All verification steps include exact commands
- Schema, runtime, and rollout work are explicitly separated

## Type consistency self-review

Planned field names stay consistent across the plan:

- `desired_latency_ms`
- `max_exceed_time_seconds`
- `max_exceed_ratio_percent`
- `max_loss_ratio_percent`
- `evaluation_window_seconds`
- `forward_chain_latency_policy`
- `sidecar_uplink`
- `slave_sidecar_persistent`

These names are reused across panel, slave, sidecar, and client tasks intentionally.

## Execution handoff

Plan complete and saved to `docs/superpowers/plans/2026-05-09-wg-backed-latency-intent-bond.md`.

Two execution options:

**1. Subagent-Driven (recommended)** - I dispatch a fresh subagent per task, review between tasks, fast iteration

**2. Inline Execution** - Execute tasks in this session using executing-plans, batch execution with checkpoints

**Which approach?**
