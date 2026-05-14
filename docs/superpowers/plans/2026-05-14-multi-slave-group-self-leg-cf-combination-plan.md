# Multi-Slave-Group + Private Self-Leg CF Combination Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add CF-mode forward-combination support for ordered multi-group rented helpers plus a per-device private self-leg, with MPTCP-preferred and bond fallback execution, while keeping tenant choice limited to one forward combination.

**Architecture:** Extend `forward_combinations` with hybrid runtime policy fields and add a `forward_combination_helper_groups` relation table. Make provisioning resolve a per-device candidate pool from `client_device_sessions`, then teach the Linux client runtime to consume rented-group candidates plus a synthetic bond-only self-leg candidate under latency-target-driven escalation.

**Tech Stack:** PHP panel/controllers/provisioning tests, MySQL schema migrations, Python Linux client-agent/runtime tests, existing `client_device_sessions`, existing CF forward-combination model, `unittest`, focused PHP regression tests.

---

## File Structure

### Control-plane / schema

- Modify: `master/migrations/schema.sql`
  - add `forward_combination_helper_groups`
  - add forward-combination policy columns
- Create: `master/migrations/20260514_add_forward_combination_helper_groups_and_self_leg_policy.sql`
  - forward migration for live DBs

### Panel + provisioning

- Modify: `master/src/Support/ProvisioningService.php`
  - save/load new combination policy fields
  - load ordered helper groups
  - expand helper groups into real `service_endpoints` during reconciliation
  - make `assignedForwardConfigsForClient(...)` device-aware
  - emit per-device candidate pool with rented candidates + optional synthetic self-leg
- Modify: `master/src/Controllers/AdminController.php`
  - admin UI for helper groups, helper cap, self-leg and runtime preference fields
- Modify: `master/src/Controllers/TenantController.php`
  - ensure tenant flow keeps combination-only abstraction with no leaked internals
- Modify: `master/src/Controllers/ClientAgentController.php`
  - pass device/session context into provisioning
- Modify: `master/src/Support/ClientOnlineSessionService.php`
  - expose the active device-session row cleanly for provisioning

### Provisioning / panel tests

- Modify: `master/tests/provisioning_forward_combination_regression_test.php`
  - schema and save/load snippets for new combination fields/table
- Create: `master/tests/provisioning_forward_combination_helper_groups_regression_test.php`
  - ordered helper group persistence and loading
- Create: `master/tests/provisioning_self_leg_candidate_regression_test.php`
  - per-device candidate pool resolution
- Create: `master/tests/provisioning_multi_group_endpoint_reconcile_regression_test.php`
  - helper-group-driven real endpoint reconciliation
- Create: `master/tests/provisioning_runtime_preference_regression_test.php`
  - hybrid runtime-preference output rules
- Create: `master/tests/provisioning_self_leg_payload_regression_test.php`
  - explicit self-leg payload shape for direct CF execution and probing
- Create: `master/tests/admin_forward_combination_self_leg_policy_test.php`
  - admin UI wiring for new fields
- Create: `master/tests/tenant_forward_combination_self_leg_visibility_test.php`
  - ensure tenant surfaces never leak self-leg/helper internals

### Linux client runtime

- Modify: `client-agent/client_agent.py`
  - runtime-family selection using `runtime_preference`
  - candidate-pool interpretation
  - self-leg admission / escalation / de-escalation scaffolding
- Modify: `client-agent/install_forward_runtime.py`
  - runtime rendering for candidate-pool metadata and hybrid family selection
- Modify: `client-agent/leikwan_mptcp_client.py`
  - consume only rented helper candidates for MPTCP path
- Create: `client-agent/tests/test_self_leg_candidate_policy.py`
  - self-leg qualification and ranking
- Create: `client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py`
  - hybrid family choice rules
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`
  - assert self-leg is bond-only and rented helpers may remain MPTCP-capable
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
  - latency-target admission / removal behavior

---

### Task 1: Add schema coverage for helper-group relation and self-leg policy fields

**Files:**
- Modify: `master/migrations/schema.sql`
- Create: `master/migrations/20260514_add_forward_combination_helper_groups_and_self_leg_policy.sql`
- Create: `master/tests/forward_combination_self_leg_schema_regression_test.php`

- [ ] **Step 1: Write the failing PHP regression for the new schema snippets**

Create `master/tests/forward_combination_self_leg_schema_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migration = file_exists(__DIR__ . '/../migrations/20260514_add_forward_combination_helper_groups_and_self_leg_policy.sql')
    ? file_get_contents(__DIR__ . '/../migrations/20260514_add_forward_combination_helper_groups_and_self_leg_policy.sql')
    : '';

foreach ([
    'CREATE TABLE IF NOT EXISTS forward_combination_helper_groups',
    'ADD COLUMN runtime_preference',
    'ADD COLUMN allow_private_self_leg',
    'ADD COLUMN self_leg_scope',
    'ADD COLUMN self_leg_role',
    'ADD COLUMN helper_group_cap',
    'ADD COLUMN helper_escalation_policy',
] as $snippet) {
    if (!str_contains($schema, $snippet) && !str_contains($migration, $snippet)) {
        fail('missing schema snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the regression to verify it fails**

Run:

```bash
php master/tests/forward_combination_self_leg_schema_regression_test.php
```

Expected: FAIL with one of the missing schema snippet messages above.

- [ ] **Step 3: Add the forward migration file**

Create `master/migrations/20260514_add_forward_combination_helper_groups_and_self_leg_policy.sql` with:

```sql
ALTER TABLE forward_combinations
    ADD COLUMN runtime_preference VARCHAR(64) NOT NULL DEFAULT 'single' AFTER client_mode,
    ADD COLUMN allow_private_self_leg TINYINT(1) NOT NULL DEFAULT 0 AFTER runtime_preference,
    ADD COLUMN self_leg_scope VARCHAR(64) NOT NULL DEFAULT 'none' AFTER allow_private_self_leg,
    ADD COLUMN self_leg_role VARCHAR(64) NOT NULL DEFAULT 'none' AFTER self_leg_scope,
    ADD COLUMN helper_group_cap INT NOT NULL DEFAULT 0 AFTER self_leg_role,
    ADD COLUMN helper_escalation_policy VARCHAR(64) NOT NULL DEFAULT 'disabled' AFTER helper_group_cap;

CREATE TABLE IF NOT EXISTS forward_combination_helper_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    node_group_key VARCHAR(191) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forward_combination_helper_groups_combo
        FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE,
    INDEX idx_forward_combination_helper_groups_combo (forward_combination_id, sort_order),
    INDEX idx_forward_combination_helper_groups_group (node_group_key)
);
```

- [ ] **Step 4: Mirror the schema in `master/migrations/schema.sql`**

Add the same new `forward_combinations` columns and new `forward_combination_helper_groups` table to `master/migrations/schema.sql`, colocated with the existing `forward_combinations` definition:

```sql
    runtime_preference VARCHAR(64) NOT NULL DEFAULT 'single',
    allow_private_self_leg TINYINT(1) NOT NULL DEFAULT 0,
    self_leg_scope VARCHAR(64) NOT NULL DEFAULT 'none',
    self_leg_role VARCHAR(64) NOT NULL DEFAULT 'none',
    helper_group_cap INT NOT NULL DEFAULT 0,
    helper_escalation_policy VARCHAR(64) NOT NULL DEFAULT 'disabled',
```

and:

```sql
CREATE TABLE IF NOT EXISTS forward_combination_helper_groups (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    node_group_key VARCHAR(191) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forward_combination_helper_groups_combo
        FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE,
    INDEX idx_forward_combination_helper_groups_combo (forward_combination_id, sort_order),
    INDEX idx_forward_combination_helper_groups_group (node_group_key)
);
```

- [ ] **Step 5: Re-run the regression to verify it passes**

Run:

```bash
php master/tests/forward_combination_self_leg_schema_regression_test.php
```

Expected: `ok`

- [ ] **Step 6: Commit**

```bash
git add master/migrations/schema.sql master/migrations/20260514_add_forward_combination_helper_groups_and_self_leg_policy.sql master/tests/forward_combination_self_leg_schema_regression_test.php
git commit -m "feat: add helper-group and self-leg forward combination schema"
```

### Task 2: Persist and load new forward-combination policy fields and helper-group rows

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/src/Controllers/AdminController.php`
- Create: `master/tests/provisioning_forward_combination_helper_groups_regression_test.php`
- Create: `master/tests/admin_forward_combination_self_leg_policy_test.php`

- [ ] **Step 1: Write the failing provisioning regression for helper-group persistence**

Create `master/tests/provisioning_forward_combination_helper_groups_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    'forward_combination_helper_groups',
    "runtime_preference",
    "allow_private_self_leg",
    "self_leg_scope",
    "self_leg_role",
    "helper_group_cap",
    "helper_escalation_policy",
    'DELETE FROM forward_combination_helper_groups WHERE forward_combination_id = :forward_combination_id',
    "node_group_key",
    "sort_order",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the new provisioning regression and confirm failure**

Run:

```bash
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
```

Expected: FAIL with missing snippet output.

- [ ] **Step 3: Extend `saveForwardCombination()` normalization and persistence**

In `master/src/Support/ProvisioningService.php`, inside `saveForwardCombination(array $payload): array`, add normalized policy extraction:

```php
$runtimePreference = trim((string) ($payload['runtime_preference'] ?? 'single'));
$allowPrivateSelfLeg = !in_array(strtolower(trim((string) ($payload['allow_private_self_leg'] ?? '0'))), ['0', 'false', 'no', 'off', ''], true);
$selfLegScope = trim((string) ($payload['self_leg_scope'] ?? 'none')) ?: 'none';
$selfLegRole = trim((string) ($payload['self_leg_role'] ?? 'none')) ?: 'none';
$helperGroupCap = max(0, (int) ($payload['helper_group_cap'] ?? 0));
$helperEscalationPolicy = trim((string) ($payload['helper_escalation_policy'] ?? 'disabled')) ?: 'disabled';
$helperGroups = [];
foreach ((array) ($payload['helper_node_group_keys'] ?? []) as $groupKey) {
    $normalizedGroupKey = trim((string) $groupKey);
    if ($normalizedGroupKey === '') {
        continue;
    }
    $helperGroups[] = [
        'node_group_key' => $normalizedGroupKey,
        'sort_order' => count($helperGroups),
        'is_active' => 1,
    ];
}
```

Then persist those columns in both the insert and update payloads for `forward_combinations`, and after saving the combination row, replace helper rows with:

```php
$this->db->execute('DELETE FROM forward_combination_helper_groups WHERE forward_combination_id = :forward_combination_id', [
    'forward_combination_id' => $id,
]);
foreach ($helperGroups as $helperGroup) {
    $this->db->insert('forward_combination_helper_groups', [
        'forward_combination_id' => $id,
        'node_group_key' => $helperGroup['node_group_key'],
        'sort_order' => $helperGroup['sort_order'],
        'is_active' => 1,
        'created_at' => $this->now(),
        'updated_at' => $this->now(),
    ]);
}
```

- [ ] **Step 4: Load helper groups and new policy fields in available-combination queries**

In the methods that fetch combinations (the existing `availableForwardCombinationsForService(...)` and list helpers it uses), add helper-group hydration after the base query:

```php
$rows = $this->db->fetchAll(/* existing query */);
foreach ($rows as &$row) {
    $row['helper_node_group_keys'] = array_map(
        static fn(array $item): string => (string) ($item['node_group_key'] ?? ''),
        $this->db->fetchAll(
            'SELECT node_group_key
             FROM forward_combination_helper_groups
             WHERE forward_combination_id = :forward_combination_id AND is_active = 1
             ORDER BY sort_order ASC, id ASC',
            ['forward_combination_id' => (int) ($row['id'] ?? 0)]
        )
    );
}
unset($row);
```

- [ ] **Step 5: Wire the admin form fields**

In `master/src/Controllers/AdminController.php`, extend the forward-combination form and payload handling with fields:

```php
<div class="field"><label>Runtime Preference</label><select name="runtime_preference"><option value="single">single</option><option value="mptcp_preferred_bond_fallback">mptcp_preferred_bond_fallback</option></select></div>
<div class="field"><label>Allow Private Self Leg</label><select name="allow_private_self_leg"><option value="0">No</option><option value="1">Yes</option></select></div>
<div class="field"><label>Self Leg Scope</label><select name="self_leg_scope"><option value="none">none</option><option value="device_session">device_session</option></select></div>
<div class="field"><label>Self Leg Role</label><select name="self_leg_role"><option value="none">none</option><option value="latency_anchor">latency_anchor</option></select></div>
<div class="field"><label>Helper Group Cap</label><input type="number" name="helper_group_cap" min="0" value="0"></div>
<div class="field"><label>Helper Escalation Policy</label><select name="helper_escalation_policy"><option value="disabled">disabled</option><option value="desired_latency_only">desired_latency_only</option></select></div>
<div class="field"><label>Helper Node Group Keys</label><textarea name="helper_node_group_keys_text"></textarea><p class="muted">One node group key per line, ordered by priority.</p></div>
```

and normalize in request handling:

```php
$helperNodeGroupKeys = preg_split('/\r\n|\r|\n/', (string) ($request->body()['helper_node_group_keys_text'] ?? '')) ?: [];
```

- [ ] **Step 6: Add the admin wiring regression**

Create `master/tests/admin_forward_combination_self_leg_policy_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
foreach ([
    'name="runtime_preference"',
    'name="allow_private_self_leg"',
    'name="self_leg_scope"',
    'name="self_leg_role"',
    'name="helper_group_cap"',
    'name="helper_escalation_policy"',
    'helper_node_group_keys_text',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing admin snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 7: Run the two regressions and confirm pass**

Run:

```bash
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
php master/tests/admin_forward_combination_self_leg_policy_test.php
```

Expected: both print `ok`

- [ ] **Step 8: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/src/Controllers/AdminController.php master/tests/provisioning_forward_combination_helper_groups_regression_test.php master/tests/admin_forward_combination_self_leg_policy_test.php
git commit -m "feat: add forward combination helper-group and self-leg policy wiring"
```

### Task 3: Expand ordered helper groups into real service endpoints during reconciliation

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/provisioning_multi_group_endpoint_reconcile_regression_test.php`

- [ ] **Step 1: Write the failing reconciliation regression**

Create `master/tests/provisioning_multi_group_endpoint_reconcile_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
foreach ([
    'forward_combination_helper_groups',
    'orderedHelperGroupKeysForForward',
    'reconcileForwardEndpoints',
    'pickNodesForGroups',
    'origin_group_key',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing multi-group reconcile snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the regression and confirm failure**

Run:

```bash
php master/tests/provisioning_multi_group_endpoint_reconcile_regression_test.php
```

Expected: FAIL with a missing reconcile snippet.

- [ ] **Step 3: Add helper-group resolution methods**

In `master/src/Support/ProvisioningService.php`, add:

```php
/**
 * @return array<int, string>
 */
private function orderedHelperGroupKeysForForward(array $forward): array
{
    $forwardCombinationId = (int) ($forward['forward_combination_id'] ?? 0);
    if ($forwardCombinationId <= 0) {
        $fallback = trim((string) ($forward['node_group_key'] ?? ''));
        return $fallback !== '' ? [$fallback] : [];
    }
    $rows = $this->db->fetchAll(
        'SELECT node_group_key
         FROM forward_combination_helper_groups
         WHERE forward_combination_id = :forward_combination_id AND is_active = 1
         ORDER BY sort_order ASC, id ASC',
        ['forward_combination_id' => $forwardCombinationId]
    );
    $keys = [];
    foreach ($rows as $row) {
        $key = trim((string) ($row['node_group_key'] ?? ''));
        if ($key !== '') {
            $keys[] = $key;
        }
    }
    if ($keys === []) {
        $fallback = trim((string) ($forward['node_group_key'] ?? ''));
        if ($fallback !== '') {
            $keys[] = $fallback;
        }
    }
    return array_values(array_unique($keys));
}

private function helperGroupCapForForward(array $forward): int
{
    $cap = (int) ($forward['helper_group_cap'] ?? $forward['forward_combination_helper_group_cap'] ?? 0);
    return max(0, $cap);
}
```

- [ ] **Step 4: Teach `reconcileForwardEndpoints()` to use helper groups**

In `master/src/Support/ProvisioningService.php`, replace the single-group line:

```php
$groupKeys = [(string) ($forward['node_group_key'] ?? '')];
```

with:

```php
$groupKeys = $this->orderedHelperGroupKeysForForward($forward);
```

Then, when building or updating service endpoints, persist origin-group metadata in the endpoint payload path:

```php
$originGroupKey = (string) ($node['group_key'] ?? $node['node_group_key'] ?? '');
```

and include it in both the endpoint row update intent and publish payload:

```php
'origin_group_key' => $originGroupKey,
```

If `service_endpoints` has no `origin_group_key` column yet, keep it in payload JSON / publish-task metadata first and leave row schema unchanged in phase one.

- [ ] **Step 5: Make publish payloads preserve origin-group identity**

In `publishTaskPayloadForForward(...)`, include:

```php
'origin_group_key' => (string) ($node['group_key'] ?? $node['node_group_key'] ?? ''),
```

so downstream runtime config can distinguish helper origins even when two nodes share the same external shape.

- [ ] **Step 6: Run the reconciliation regression and existing provisioning regression**

Run:

```bash
php master/tests/provisioning_multi_group_endpoint_reconcile_regression_test.php
php master/tests/provisioning_forward_combination_regression_test.php
```

Expected: both print `ok`

- [ ] **Step 7: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/tests/provisioning_multi_group_endpoint_reconcile_regression_test.php
git commit -m "feat: expand helper groups into real forward endpoints"
```

### Task 4: Make client config resolution device-aware and emit a candidate pool

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/src/Support/ClientOnlineSessionService.php`
- Create: `master/tests/provisioning_self_leg_candidate_regression_test.php`
- Create: `master/tests/provisioning_self_leg_payload_regression_test.php`
- Create: `master/tests/provisioning_runtime_preference_regression_test.php`

- [ ] **Step 1: Write the failing regression for device-aware provisioning output**

Create `master/tests/provisioning_self_leg_candidate_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
foreach ([
    'assignedForwardConfigsForClient(int $clientId, ?array $deviceSession = null): array',
    'candidate_kind',
    'self_leg',
    'rented_group_leg',
    'runtime_preference',
    'helper_group_cap',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing provisioning snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the regression and confirm failure**

Run:

```bash
php master/tests/provisioning_self_leg_candidate_regression_test.php
```

Expected: FAIL with a missing provisioning snippet.

- [ ] **Step 3: Add an active device-session accessor**

In `master/src/Support/ClientOnlineSessionService.php`, add:

```php
/**
 * @return array<string, mixed>|null
 */
public function activeSessionForClientAndDevice(int $clientId, string $deviceId): ?array
{
    $row = $this->db->fetchOne(
        'SELECT * FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id LIMIT 1',
        [
            'client_id' => $clientId,
            'device_id' => trim($deviceId),
        ]
    );
    if ($row === null) {
        return null;
    }
    $cutoff = gmdate('Y-m-d H:i:s', time() - self::ACTIVE_WINDOW_SECONDS);
    return (string) ($row['last_seen_at'] ?? '') >= $cutoff ? $row : null;
}
```

- [ ] **Step 4: Pass device-session context from the client pull controller**

In `master/src/Controllers/ClientAgentController.php`, replace the current pull call with:

```php
$deviceSession = $deviceSessionService->activeSessionForClientAndDevice((int) $client['id'], $deviceId);
$forwards = $this->provisioning->assignedForwardConfigsForClient((int) $client['id'], $deviceSession);
```

- [ ] **Step 5: Make `assignedForwardConfigsForClient()` device-aware**

Change the method signature in `master/src/Support/ProvisioningService.php` to:

```php
public function assignedForwardConfigsForClient(int $clientId, ?array $deviceSession = null): array
```

and after `buildClientConfig($forwardId)`, inject a new candidate-pool block using helper groups and optional self-leg:

```php
$config['runtime_preference'] = (string) ($config['forward_combination_runtime_preference'] ?? 'single');
$config['helper_group_cap'] = max(0, (int) ($config['forward_combination_helper_group_cap'] ?? 0));
$config['candidate_pool'] = $this->buildForwardCandidatePool($config, $client, $deviceSession);
```

- [ ] **Step 6: Add the candidate-pool builder methods**

In `master/src/Support/ProvisioningService.php`, add:

```php
private function buildForwardCandidatePool(array $config, array $client, ?array $deviceSession): array
{
    $pool = [];
    foreach ((array) ($config['endpoints'] ?? []) as $endpoint) {
        if (!is_array($endpoint)) {
            continue;
        }
        $pool[] = [
            'candidate_kind' => 'rented_group_leg',
            'endpoint_id' => (int) ($endpoint['endpoint_id'] ?? 0),
            'origin_group_key' => (string) ($endpoint['node_group_key'] ?? $endpoint['group_key'] ?? ''),
            'origin_group_order' => $this->helperGroupOrderForConfig($config, (string) ($endpoint['node_group_key'] ?? $endpoint['group_key'] ?? '')),
            'runtime_capabilities' => ['bond', 'mptcp'],
            'host' => (string) ($endpoint['host'] ?? ''),
            'port' => (int) ($endpoint['port'] ?? 0),
        ];
    }

    $selfLeg = $this->buildSelfLegCandidate($config, $client, $deviceSession);
    if ($selfLeg !== null) {
        array_unshift($pool, $selfLeg);
    }

    return $pool;
}

private function buildSelfLegCandidate(array $config, array $client, ?array $deviceSession): ?array
{
    if (!((int) ($config['forward_combination_allow_private_self_leg'] ?? 0) === 1)) {
        return null;
    }
    if ($deviceSession === null) {
        return null;
    }
    $deviceId = trim((string) ($deviceSession['device_id'] ?? ''));
    if ($deviceId === '') {
        return null;
    }
    return [
        'candidate_kind' => 'self_leg',
        'session_id' => (int) ($deviceSession['id'] ?? 0),
        'device_id' => $deviceId,
        'runtime_capabilities' => ['bond'],
        'self_leg_scope' => 'device_session',
        'self_leg_role' => 'latency_anchor',
        'probe_transport' => 'cf_direct_wss',
        'dial_host' => (string) ($config['bond_remote_host'] ?? ''),
        'dial_port' => (int) ($config['bond_remote_port'] ?? 0),
        'cf_sni_host' => (string) ($config['sni'] ?? ''),
        'path_prefix' => (string) ($config['path_prefix'] ?? ''),
        'ingress_auth_token' => '',
        'target_sidecar_host' => (string) ($config['bond_remote_host'] ?? ''),
        'target_sidecar_port' => (int) ($config['bond_remote_port'] ?? 0),
    ];
}
```

- [ ] **Step 7: Add a regression for explicit self-leg payload shape**

Create `master/tests/provisioning_self_leg_payload_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
foreach ([
    'probe_transport',
    'cf_direct_wss',
    'dial_host',
    'dial_port',
    'cf_sni_host',
    'path_prefix',
    'target_sidecar_host',
    'target_sidecar_port',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing self-leg payload snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 8: Add a regression for runtime-preference output shape**

Create `master/tests/provisioning_runtime_preference_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
foreach ([
    "mptcp_preferred_bond_fallback",
    "candidate_pool",
    "runtime_capabilities' => ['bond']",
    "runtime_capabilities' => ['bond', 'mptcp']",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing runtime-preference snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 9: Run the provisioning regressions and confirm pass**

Run:

```bash
php master/tests/provisioning_self_leg_candidate_regression_test.php
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_runtime_preference_regression_test.php
```

Expected: both print `ok`

- [ ] **Step 10: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/src/Controllers/ClientAgentController.php master/src/Support/ClientOnlineSessionService.php master/tests/provisioning_self_leg_candidate_regression_test.php master/tests/provisioning_self_leg_payload_regression_test.php master/tests/provisioning_runtime_preference_regression_test.php
git commit -m "feat: emit device-aware candidate pools for forward combinations"
```

### Task 5: Teach the Linux client runtime to honor hybrid runtime preference

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/leikwan_mptcp_client.py`
- Create: `client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py`
- Create: `client-agent/tests/test_self_leg_candidate_policy.py`

- [ ] **Step 1: Write the failing runtime-preference unit tests**

Create `client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py`:

```python
import importlib.util
import pathlib
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent_module = load_module("client_agent_runtime_pref_under_test", "client-agent/client_agent.py")


class RuntimePreferenceTests(unittest.TestCase):
    def test_self_leg_primary_forces_bond_family(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "candidate_pool": [
                    {"candidate_kind": "self_leg", "selected_primary": True, "runtime_capabilities": ["bond"]},
                    {"candidate_kind": "rented_group_leg", "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("bond", family)

    def test_rented_only_candidates_prefer_mptcp(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "candidate_pool": [
                    {"candidate_kind": "rented_group_leg", "selected_primary": True, "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("mptcp", family)


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run the test file to confirm failure**

Run:

```bash
python -m unittest client-agent.tests.test_runtime_preference_mptcp_bond_fallback
```

Expected: FAIL because `choose_runtime_family` does not exist yet.

- [ ] **Step 3: Add the runtime-family chooser**

In `client-agent/client_agent.py`, add:

```python
    def choose_runtime_family(self, forward: dict, mptcp_supported: bool) -> str:
        runtime_preference = str(forward.get("runtime_preference", "single") or "single").strip()
        if runtime_preference != "mptcp_preferred_bond_fallback":
            return self.normalize_mode(forward.get("mode", "single"))

        candidate_pool = [item for item in forward.get("candidate_pool", []) if isinstance(item, dict)]
        primary = next((item for item in candidate_pool if bool(item.get("selected_primary", False))), candidate_pool[0] if candidate_pool else None)
        if isinstance(primary, dict) and str(primary.get("candidate_kind", "") or "") == "self_leg":
            return "bond"
        if mptcp_supported:
            return "mptcp"
        return "bond"
```

- [ ] **Step 4: Use the chooser during sync/apply**

In the rendering path in `client-agent/client_agent.py`, before mode-specific runtime generation, compute:

```python
runtime_mode = self.choose_runtime_family(render_forward, self.running_kernel_supports_mptcp())
if runtime_mode in {"bond", "mptcp"}:
    render_forward = dict(render_forward)
    render_forward["mode"] = runtime_mode
```

- [ ] **Step 5: Add a self-leg policy test file**

Create `client-agent/tests/test_self_leg_candidate_policy.py`:

```python
import importlib.util
import pathlib
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent_module = load_module("client_agent_self_leg_under_test", "client-agent/client_agent.py")


class SelfLegCandidatePolicyTests(unittest.TestCase):
    def test_selects_self_leg_as_primary_when_lowest_rtt(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        selected = client_agent_module.ClientAgent.select_forward_candidates(
            agent,
            {
                "candidate_pool": [
                    {"candidate_kind": "self_leg", "device_id": "abc", "measured_rtt_ms": 31.0, "ready": True, "runtime_capabilities": ["bond"]},
                    {"candidate_kind": "rented_group_leg", "endpoint_id": 316, "measured_rtt_ms": 44.0, "ready": True, "runtime_capabilities": ["bond", "mptcp"]},
                ]
            },
        )
        self.assertEqual("self_leg", selected[0]["candidate_kind"])
        self.assertTrue(selected[0]["selected_primary"])


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 6: Implement minimal candidate ordering helper**

In `client-agent/client_agent.py`, add:

```python
    def select_forward_candidates(self, forward: dict) -> list[dict]:
        candidates = [dict(item) for item in forward.get("candidate_pool", []) if isinstance(item, dict)]
        ready = [item for item in candidates if bool(item.get("ready", True))]
        ready.sort(key=lambda item: (float(item.get("measured_rtt_ms", 999999.0) or 999999.0), str(item.get("candidate_kind", ""))))
        for index, item in enumerate(ready):
            item["selected_primary"] = index == 0
        return ready
```

- [ ] **Step 7: Run the new tests and confirm pass**

Run:

```bash
python -m unittest client-agent.tests.test_runtime_preference_mptcp_bond_fallback
python -m unittest client-agent.tests.test_self_leg_candidate_policy
```

Expected: both PASS.

- [ ] **Step 8: Commit**

```bash
git add client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py client-agent/tests/test_self_leg_candidate_policy.py
git commit -m "feat: add hybrid runtime preference and self-leg candidate policy"
```

### Task 5: Add latency-target-driven helper admission and removal behavior

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
- Modify: `client-agent/tests/test_mptcp_bond_regressions.py`

- [ ] **Step 1: Write the failing latency-target admission test**

Append this test to `client-agent/tests/test_client_agent_bond_endpoint_health.py`:

```python
    def test_self_leg_primary_adds_helper_when_latency_target_exceeded(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        forward = {
            "forward_id": 91,
            "runtime_preference": "mptcp_preferred_bond_fallback",
            "helper_group_cap": 1,
            "desired_latency_ms": 40,
            "candidate_pool": [
                {"candidate_kind": "self_leg", "device_id": "abc", "measured_rtt_ms": 55.0, "ready": True, "selected_primary": True},
                {"candidate_kind": "rented_group_leg", "endpoint_id": 316, "origin_group_key": "shanghai-a", "measured_rtt_ms": 38.0, "ready": True},
            ],
        }
        next_forward = client_agent_module.ClientAgent.apply_latency_target_candidate_escalation(agent, forward)
        selected = [item for item in next_forward["candidate_pool"] if item.get("active_helper")]
        self.assertEqual(1, len(selected))
        self.assertEqual("shanghai-a", selected[0]["origin_group_key"])
```

- [ ] **Step 2: Run the targeted test to confirm failure**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_self_leg_primary_adds_helper_when_latency_target_exceeded
```

Expected: FAIL because the helper does not exist yet.

- [ ] **Step 3: Implement minimal escalation helper**

In `client-agent/client_agent.py`, add:

```python
    def apply_latency_target_candidate_escalation(self, forward: dict) -> dict:
        next_forward = dict(forward)
        desired_latency_ms = float(forward.get("desired_latency_ms", 0) or 0)
        helper_cap = max(0, int(forward.get("helper_group_cap", 0) or 0))
        pool = [dict(item) for item in forward.get("candidate_pool", []) if isinstance(item, dict)]
        primary = next((item for item in pool if bool(item.get("selected_primary", False))), None)
        if desired_latency_ms <= 0 or helper_cap <= 0 or primary is None:
            next_forward["candidate_pool"] = pool
            return next_forward
        if str(primary.get("candidate_kind", "") or "") != "self_leg":
            next_forward["candidate_pool"] = pool
            return next_forward
        primary_rtt = float(primary.get("measured_rtt_ms", 999999.0) or 999999.0)
        if primary_rtt <= desired_latency_ms:
            next_forward["candidate_pool"] = pool
            return next_forward
        helpers = [item for item in pool if str(item.get("candidate_kind", "") or "") == "rented_group_leg" and bool(item.get("ready", True))]
        helpers.sort(key=lambda item: (float(item.get("measured_rtt_ms", 999999.0) or 999999.0), int(item.get("origin_group_order", 999999) or 999999)))
        chosen_group_keys = set()
        chosen = 0
        for helper in helpers:
            group_key = str(helper.get("origin_group_key", "") or "")
            if group_key != "" and group_key in chosen_group_keys:
                continue
            helper["active_helper"] = True
            if group_key != "":
                chosen_group_keys.add(group_key)
            chosen += 1
            if chosen >= helper_cap:
                break
        next_forward["candidate_pool"] = pool
        return next_forward
```

- [ ] **Step 4: Ensure MPTCP runtime never consumes self-leg candidates**

Add this assertion-oriented regression to `client-agent/tests/test_mptcp_bond_regressions.py`:

```python
    def test_mptcp_runtime_filters_out_self_leg_candidates(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        candidates = client_agent_module.ClientAgent.filter_mptcp_capable_candidates(
            agent,
            [
                {"candidate_kind": "self_leg", "runtime_capabilities": ["bond"]},
                {"candidate_kind": "rented_group_leg", "runtime_capabilities": ["bond", "mptcp"]},
            ],
        )
        self.assertEqual(1, len(candidates))
        self.assertEqual("rented_group_leg", candidates[0]["candidate_kind"])
```

and implement:

```python
    def filter_mptcp_capable_candidates(self, candidate_pool: list[dict]) -> list[dict]:
        return [
            dict(item)
            for item in candidate_pool
            if isinstance(item, dict)
            and str(item.get("candidate_kind", "") or "") != "self_leg"
            and "mptcp" in list(item.get("runtime_capabilities", []))
        ]
```

- [ ] **Step 5: Run the updated Python regressions**

Run:

```bash
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health.ClientAgentBondEndpointHealthTests.test_self_leg_primary_adds_helper_when_latency_target_exceeded
python -m unittest client-agent.tests.test_mptcp_bond_regressions
```

Expected: targeted helper-admission test PASS, full MPTCP regressions PASS.

- [ ] **Step 6: Commit**

```bash
git add client-agent/client_agent.py client-agent/tests/test_client_agent_bond_endpoint_health.py client-agent/tests/test_mptcp_bond_regressions.py
git commit -m "feat: add latency-target helper admission for self-leg sessions"
```

### Task 6: Add runtime handling for explicit self-leg payload shape

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/install_forward_runtime.py`
- Create: `client-agent/tests/test_self_leg_direct_cf_runtime_shape.py`

- [ ] **Step 1: Write the failing self-leg runtime-shape test**

Create `client-agent/tests/test_self_leg_direct_cf_runtime_shape.py`:

```python
import importlib.util
import pathlib
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent_module = load_module("client_agent_self_leg_runtime_shape_under_test", "client-agent/client_agent.py")


class SelfLegDirectCfRuntimeShapeTests(unittest.TestCase):
    def test_self_leg_candidate_requires_explicit_direct_cf_fields(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        candidate = {
            "candidate_kind": "self_leg",
            "probe_transport": "cf_direct_wss",
            "dial_host": "162.159.39.16",
            "dial_port": 2053,
            "cf_sni_host": "nrt1.nbwly.com",
            "path_prefix": "07289d1f5ed25399",
        }
        shape = client_agent_module.ClientAgent.normalize_self_leg_runtime_shape(agent, candidate)
        self.assertEqual("cf_direct_wss", shape["probe_transport"])
        self.assertEqual("162.159.39.16", shape["dial_host"])
        self.assertEqual(2053, shape["dial_port"])


if __name__ == "__main__":
    unittest.main()
```

- [ ] **Step 2: Run the test to confirm failure**

Run:

```bash
python -m unittest client-agent.tests.test_self_leg_direct_cf_runtime_shape
```

Expected: FAIL because `normalize_self_leg_runtime_shape` does not exist yet.

- [ ] **Step 3: Add explicit self-leg runtime-shape normalizer**

In `client-agent/client_agent.py`, add:

```python
    def normalize_self_leg_runtime_shape(self, candidate: dict) -> dict:
        return {
            "candidate_kind": "self_leg",
            "probe_transport": str(candidate.get("probe_transport", "") or ""),
            "dial_host": str(candidate.get("dial_host", "") or ""),
            "dial_port": int(candidate.get("dial_port", 0) or 0),
            "cf_sni_host": str(candidate.get("cf_sni_host", "") or ""),
            "path_prefix": str(candidate.get("path_prefix", "") or ""),
            "target_sidecar_host": str(candidate.get("target_sidecar_host", "") or ""),
            "target_sidecar_port": int(candidate.get("target_sidecar_port", 0) or 0),
        }
```

Use this normalizer before any self-leg-specific probing or runtime rendering branch.

- [ ] **Step 4: Keep `install_forward_runtime.py` from mapping self-leg into MPTCP underlays**

In `client-agent/install_forward_runtime.py`, when deriving MPTCP runtime candidates, filter out any candidate with:

```python
candidate_kind == "self_leg"
```

and when deriving bond runtime metadata, preserve the explicit self-leg direct-CF fields for bond-only execution/probing.

- [ ] **Step 5: Run the self-leg runtime-shape test**

Run:

```bash
python -m unittest client-agent.tests.test_self_leg_direct_cf_runtime_shape
```

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/tests/test_self_leg_direct_cf_runtime_shape.py
git commit -m "feat: add explicit direct-cf self-leg runtime shape"
```

### Task 7: Update documentation-facing surfaces and verify no tenant leakage

**Files:**
- Modify: `master/src/Controllers/TenantController.php`
- Create: `master/tests/tenant_forward_combination_self_leg_visibility_test.php`

- [ ] **Step 1: Write the failing tenant-visibility regression**

Create `master/tests/tenant_forward_combination_self_leg_visibility_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
foreach ([
    'available_forward_combinations',
    'forward_combination_id',
] as $required) {
    if (!str_contains($source, $required)) {
        fail('missing tenant combination snippet: ' . $required);
    }
}
foreach ([
    'allow_private_self_leg',
    'helper_group_cap',
    'helper_node_group_keys',
    'runtime_preference',
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('tenant controller leaks internal field: ' . $forbidden);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the tenant regression and confirm pass or failure**

Run:

```bash
php master/tests/tenant_forward_combination_self_leg_visibility_test.php
```

Expected: if it fails, it should identify a leaked internal field; otherwise `ok`.

- [ ] **Step 3: Remove or avoid leaking new internal fields in tenant views**

In `master/src/Controllers/TenantController.php`, ensure the tenant-facing rendering continues to use only:

```php
'forward_combination_id'
'forward_combination_label'
'forward_combination_name'
```

and does **not** append any of these internal values into tenant HTML or JSON:

```php
allow_private_self_leg
helper_group_cap
helper_node_group_keys
runtime_preference
```

- [ ] **Step 4: Re-run the tenant regression**

Run:

```bash
php master/tests/tenant_forward_combination_self_leg_visibility_test.php
```

Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add master/src/Controllers/TenantController.php master/tests/tenant_forward_combination_self_leg_visibility_test.php
git commit -m "feat: keep self-leg and helper policy hidden from tenants"
```

### Task 8: Final verification sweep and plan handoff

**Files:** verify only

- [ ] **Step 1: Run the PHP regression suite for this feature slice**

Run:

```bash
php master/tests/provisioning_forward_combination_regression_test.php
php master/tests/forward_combination_self_leg_schema_regression_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
php master/tests/provisioning_multi_group_endpoint_reconcile_regression_test.php
php master/tests/provisioning_self_leg_candidate_regression_test.php
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_runtime_preference_regression_test.php
php master/tests/admin_forward_combination_self_leg_policy_test.php
php master/tests/tenant_forward_combination_self_leg_visibility_test.php
```

Expected: all print `ok`

- [ ] **Step 2: Run the Python regression suite for this feature slice**

Run:

```bash
python -m unittest client-agent.tests.test_runtime_preference_mptcp_bond_fallback
python -m unittest client-agent.tests.test_self_leg_candidate_policy
python -m unittest client-agent.tests.test_self_leg_direct_cf_runtime_shape
python -m unittest client-agent.tests.test_client_agent_bond_endpoint_health
python -m unittest client-agent.tests.test_mptcp_bond_regressions
python -m unittest client-agent.tests.test_sidecar_dual_runtime_regression
```

Expected: all PASS

- [ ] **Step 3: Run focused syntax checks on the touched PHP files**

Run:

```bash
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Controllers/ClientAgentController.php
php -l master/src/Controllers/TenantController.php
php -l master/src/Support/ClientOnlineSessionService.php
```

Expected: `No syntax errors detected` for each file

- [ ] **Step 4: Inspect the final diff before execution handoff**

Run:

```bash
git diff --stat HEAD~8..HEAD
```

Expected: only the planned schema, panel, provisioning, and Linux client-runtime files are included.

- [ ] **Step 5: Commit any final cleanups**

```bash
git add master/src/Support/ProvisioningService.php master/src/Controllers/AdminController.php master/src/Controllers/ClientAgentController.php master/src/Controllers/TenantController.php master/src/Support/ClientOnlineSessionService.php client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py master/tests/provisioning_forward_combination_helper_groups_regression_test.php master/tests/provisioning_self_leg_candidate_regression_test.php master/tests/provisioning_runtime_preference_regression_test.php master/tests/admin_forward_combination_self_leg_policy_test.php master/tests/tenant_forward_combination_self_leg_visibility_test.php client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py client-agent/tests/test_self_leg_candidate_policy.py
git commit -m "feat: wire multi-group self-leg CF forward combination runtime"
```

---

## Self-Review

### Spec coverage

- Control-plane model: Tasks 1-4
- Helper-group relation table: Tasks 1-2
- Real multi-group endpoint reconciliation: Task 3
- Private self-leg model: Tasks 4-6
- MPTCP-preferred / bond fallback: Tasks 4-6
- Self-leg bond-only constraint: Tasks 5-6
- Latency-target-based helper admission: Task 5
- Tenant-hidden abstraction: Task 7
- Verification boundary: Task 8

### Placeholder scan

- No `TODO`, `TBD`, or deferred implementation placeholders remain.
- Every task includes exact file paths and exact commands.
- Every code-change step includes concrete code blocks.

### Type consistency

- `runtime_preference`, `allow_private_self_leg`, `self_leg_scope`, `self_leg_role`, `helper_group_cap`, and `helper_escalation_policy` are consistent across schema, PHP, and tests.
- `candidate_pool`, `candidate_kind`, `self_leg`, and `rented_group_leg` are consistent across provisioning and Python runtime tasks.


