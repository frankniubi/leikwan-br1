# Backend/Admin Route Direction Client Rules Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the PHP `master/` backend and admin Route Direction + Client Rule implementation from the approved design while leaving Android, Linux, and Windows client code unchanged.

**Architecture:** Add a Route Direction facade in PHP that maps stable product direction IDs to one admin-only primary runtime realization, then sync exact Client Rules through additive `/api/client/configs/pull` fields only when clients advertise route-rule capability. Keep validation and entitlement enforcement server-authoritative in `ProvisioningService` using focused `RouteDirectionService` and `ClientRuleValidator` helpers, and replace legacy admin routing product pages with 410 responses.

**Tech Stack:** PHP 8 strict types, existing `master/src` mini-router/controllers/services, MySQL-compatible `.sql` migrations plus `schema.sql`, executable PHP tests under `master/tests` run with `php`.

---

## Scope guard

This plan intentionally touches only PHP backend/admin files under `master/` plus the plan/test files. Do not edit Android, Linux agent, Windows app, installer, runtime artifact, or deployment files while executing this plan.

User-facing Route Direction and Client Rule product surfaces must not expose these terms: `LeiGod`, `current_mode`, `cf_mode`, `forward_combination`, `cf_location_group`, `chain`, `slave`, `sidecar`, internal line, listener, runtime mode. The Route Direction admin page may contain internal mapping fields because that page is the approved operator-only mapping surface.

## File structure

Create:
- `master/migrations/20260722_add_route_directions_client_rules.sql` — database objects for route directions, realizations, plan entitlements, Client Rules, and route-rule quota.
- `master/src/Support/RouteDirectionService.php` — Route Direction CRUD/payloads, plan entitlements, service-scope resolution, config-pull route-rule assembly.
- `master/src/Support/ClientRuleValidator.php` — strict IPv4/port normalization, policy blocking, duplicate key creation, write validation precedence.
- `master/tests/route_direction_schema_test.php` — schema and migration contract.
- `master/tests/route_direction_service_test.php` — RouteDirectionService payload, entitlement, and config-pull behavior.
- `master/tests/client_rule_validator_test.php` — destination, duplicate-key, quota, and validation-precedence behavior.
- `master/tests/plan_direction_entitlements_test.php` — plan UI/service persistence for direction entitlements and quota.
- `master/tests/admin_route_direction_routes_test.php` — admin Route Directions routes/page/product terminology.
- `master/tests/admin_client_rules_test.php` — admin Client Rules page/edit/tombstone behavior and terminology.
- `master/tests/client_rule_write_api_surface_test.php` — client create/update/delete APIs, stable errors, sync version.
- `master/tests/client_config_pull_route_direction_test.php` — additive pull payload, capability gates, helper-only forward filtering.
- `master/tests/legacy_routing_pages_gone_test.php` — old admin routing URLs return 410.

Modify:
- `master/migrations/schema.sql` — canonical schema mirror.
- `master/src/Support/ProvisioningService.php` — RouteDirectionService/ClientRuleValidator wiring; route direction list/save; plan entitlements; Client Rule CRUD; config-pull helper filtering; capability gates.
- `master/src/Controllers/ClientAgentController.php` — route-rule capability heartbeat fields; additive config pull merge; client rule create/update/delete handlers.
- `master/src/Controllers/AdminController.php` — Route Directions page; Client Rules page; Plans entitlements UI; admin save/tombstone handlers; legacy 410 handler.
- `master/src/Controllers/BaseController.php` — admin nav adds Route Directions/Client Rules and removes legacy routing product nav links.
- `master/src/Bootstrap.php` — route registration for new admin/client APIs and 410 legacy routes.
- `master/tests/admin_forward_combination_page_test.php` — invert legacy expectation so old page is gone.

## Shared exact test harness

Use this harness at the top of executable tests that instantiate `master/src` classes. For pure source-scanning tests, only the local `fail()` function is required.

```php
<?php
declare(strict_types=1);

$baseDir = __DIR__ . '/..';
spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
```

For sqlite-backed service tests, add this helper after the harness:

```php
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

function makeTestDb(string $name): array
{
    $envPath = tempnam(sys_get_temp_dir(), 'lw-' . $name . '-env-');
    $dbPath = tempnam(sys_get_temp_dir(), 'lw-' . $name . '-db-');
    if ($envPath === false || $dbPath === false) {
        fail('failed to allocate temp files');
    }
    file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\nAUTO_RUN_MIGRATIONS=false\n");
    $config = new Config($envPath);
    $db = new Database($config);
    return [$config, $db, $envPath, $dbPath];
}

function installRouteRuleTestSchema(Database $db): void
{
    $pdo = $db->pdo();
    $pdo->exec('CREATE TABLE service_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, product_code TEXT NOT NULL, max_client_rules_per_service_scope INTEGER NOT NULL DEFAULT 5, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE services (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, plan_id INTEGER NULL, name TEXT NOT NULL, whmcs_service_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "active", client_token TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER NULL, group_id INTEGER NULL, agent_token TEXT NULL, agent_config_json TEXT NULL, name TEXT NOT NULL, display_host TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active", created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE forwards (id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INTEGER NOT NULL, listen_port INTEGER NOT NULL, line_client_id INTEGER NULL, line_client_group_id INTEGER NULL, enabled INTEGER NOT NULL DEFAULT 1, route_rule_helper_only INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE route_directions (id TEXT PRIMARY KEY, label TEXT NOT NULL, status TEXT NOT NULL, admin_notes TEXT NULL, sort_order INTEGER NOT NULL DEFAULT 100, internal_mapping_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE direction_realizations (id INTEGER PRIMARY KEY AUTOINCREMENT, direction_id TEXT NOT NULL, is_primary INTEGER NOT NULL DEFAULT 1, binding_state TEXT NOT NULL DEFAULT "missing", forward_id INTEGER NULL, local_endpoint_host TEXT NOT NULL DEFAULT "127.0.0.1", local_tcp_port INTEGER NULL, local_udp_port INTEGER NULL, tcp_ready INTEGER NOT NULL DEFAULT 0, udp_ready INTEGER NOT NULL DEFAULT 0, helper_only INTEGER NOT NULL DEFAULT 1, internal_mapping_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE service_plan_route_directions (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INTEGER NOT NULL, direction_id TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(plan_id, direction_id))');
    $pdo->exec('CREATE TABLE client_rules (id TEXT PRIMARY KEY, service_scope_id TEXT NOT NULL, service_id INTEGER NOT NULL, subscription_id INTEGER NULL, destination_ip TEXT NOT NULL, destination_port INTEGER NOT NULL, protocol TEXT NOT NULL DEFAULT "tcp_udp", direction_id TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, effective_state TEXT NOT NULL DEFAULT "runtime_missing", sync_version INTEGER NOT NULL DEFAULT 1, active_duplicate_key TEXT NULL, tombstoned_at TEXT NULL, tombstoned_by TEXT NULL, notes TEXT NULL, created_by_client_id INTEGER NULL, updated_by_client_id INTEGER NULL, updated_by_admin_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(service_scope_id, active_duplicate_key))');
    $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, context_json TEXT NULL, created_at TEXT NOT NULL)');
}
```

---

### Task 1: Schema and migration

**Files:**
- Create: `master/migrations/20260722_add_route_directions_client_rules.sql`
- Modify: `master/migrations/schema.sql`
- Test: `master/tests/route_direction_schema_test.php`

- [ ] **Step 1: Write the failing schema test**

Create `master/tests/route_direction_schema_test.php` with these exact assertions:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql') ?: '';
$migration = file_get_contents(__DIR__ . '/../migrations/20260722_add_route_directions_client_rules.sql') ?: '';
$source = file_exists(__DIR__ . '/../src/Support/ProvisioningService.php') ? (file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '') : '';

$requiredSchema = [
    'CREATE TABLE IF NOT EXISTS route_directions',
    'id VARCHAR(64) NOT NULL PRIMARY KEY',
    'status VARCHAR(32) NOT NULL DEFAULT \'available\'',
    'CREATE TABLE IF NOT EXISTS direction_realizations',
    'direction_id VARCHAR(64) NOT NULL',
    'is_primary TINYINT(1) NOT NULL DEFAULT 1',
    'forward_id BIGINT UNSIGNED NULL',
    'helper_only TINYINT(1) NOT NULL DEFAULT 1',
    'CREATE TABLE IF NOT EXISTS service_plan_route_directions',
    'UNIQUE KEY uniq_plan_route_direction (plan_id, direction_id)',
    'max_client_rules_per_service_scope INT NOT NULL DEFAULT 5',
    'CREATE TABLE IF NOT EXISTS client_rules',
    'service_scope_id VARCHAR(64) NOT NULL',
    'protocol VARCHAR(16) NOT NULL DEFAULT \'tcp_udp\'',
    'sync_version BIGINT UNSIGNED NOT NULL DEFAULT 1',
    'active_duplicate_key VARCHAR(191) NULL',
    'tombstoned_at DATETIME NULL',
    'UNIQUE KEY uniq_client_rule_active_duplicate (service_scope_id, active_duplicate_key)',
    'route_rule_helper_only TINYINT(1) NOT NULL DEFAULT 0',
];
foreach ($requiredSchema as $needle) {
    if (!str_contains($schema, $needle)) {
        fail('schema.sql missing ' . $needle);
    }
}

$requiredMigration = [
    'CREATE TABLE route_directions',
    'CREATE TABLE direction_realizations',
    'CREATE TABLE service_plan_route_directions',
    'ALTER TABLE service_plans ADD COLUMN IF NOT EXISTS max_client_rules_per_service_scope INT NOT NULL DEFAULT 5',
    'CREATE TABLE client_rules',
    'ALTER TABLE forwards ADD COLUMN IF NOT EXISTS route_rule_helper_only TINYINT(1) NOT NULL DEFAULT 0',
];
foreach ($requiredMigration as $needle) {
    if (!str_contains($migration, $needle)) {
        fail('migration missing ' . $needle);
    }
}

if (!str_contains($source, 'RouteDirectionService') || !str_contains($source, 'ClientRuleValidator')) {
    fail('ProvisioningService must wire RouteDirectionService and ClientRuleValidator after schema lands');
}

echo "ok\n";
```

- [ ] **Step 2: Run the schema test and verify it fails**

Run: `php master/tests/route_direction_schema_test.php`

Expected: FAIL with `migration missing CREATE TABLE route_directions` or `schema.sql missing CREATE TABLE IF NOT EXISTS route_directions`.

- [ ] **Step 3: Add the SQL migration**

Create `master/migrations/20260722_add_route_directions_client_rules.sql` with this SQL:

```sql
-- 20260722_add_route_directions_client_rules.sql
-- Route Direction and Client Rule backend/admin schema.

CREATE TABLE route_directions (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    label VARCHAR(191) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'available',
    admin_notes TEXT NULL,
    sort_order INT NOT NULL DEFAULT 100,
    internal_mapping_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_route_directions_status_sort (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE direction_realizations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    direction_id VARCHAR(64) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 1,
    binding_state VARCHAR(32) NOT NULL DEFAULT 'missing',
    forward_id BIGINT UNSIGNED NULL,
    local_endpoint_host VARCHAR(64) NOT NULL DEFAULT '127.0.0.1',
    local_tcp_port INT NULL,
    local_udp_port INT NULL,
    tcp_ready TINYINT(1) NOT NULL DEFAULT 0,
    udp_ready TINYINT(1) NOT NULL DEFAULT 0,
    helper_only TINYINT(1) NOT NULL DEFAULT 1,
    internal_mapping_json JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_direction_realization_direction FOREIGN KEY (direction_id) REFERENCES route_directions(id),
    CONSTRAINT fk_direction_realization_forward FOREIGN KEY (forward_id) REFERENCES forwards(id),
    UNIQUE KEY uniq_direction_primary_realization (direction_id, is_primary),
    INDEX idx_direction_realizations_forward (forward_id),
    INDEX idx_direction_realizations_state (binding_state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE service_plan_route_directions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    plan_id BIGINT UNSIGNED NOT NULL,
    direction_id VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_plan_route_direction_plan FOREIGN KEY (plan_id) REFERENCES service_plans(id),
    CONSTRAINT fk_plan_route_direction_direction FOREIGN KEY (direction_id) REFERENCES route_directions(id),
    UNIQUE KEY uniq_plan_route_direction (plan_id, direction_id),
    INDEX idx_plan_route_directions_direction (direction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE service_plans ADD COLUMN IF NOT EXISTS max_client_rules_per_service_scope INT NOT NULL DEFAULT 5;
ALTER TABLE forwards ADD COLUMN IF NOT EXISTS route_rule_helper_only TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE client_rules (
    id VARCHAR(64) NOT NULL PRIMARY KEY,
    service_scope_id VARCHAR(64) NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    subscription_id BIGINT UNSIGNED NULL,
    destination_ip VARCHAR(45) NOT NULL,
    destination_port INT NOT NULL,
    protocol VARCHAR(16) NOT NULL DEFAULT 'tcp_udp',
    direction_id VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    effective_state VARCHAR(32) NOT NULL DEFAULT 'runtime_missing',
    sync_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
    active_duplicate_key VARCHAR(191) NULL,
    tombstoned_at DATETIME NULL,
    tombstoned_by VARCHAR(32) NULL,
    notes TEXT NULL,
    created_by_client_id BIGINT UNSIGNED NULL,
    updated_by_client_id BIGINT UNSIGNED NULL,
    updated_by_admin_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_client_rules_service FOREIGN KEY (service_id) REFERENCES services(id),
    CONSTRAINT fk_client_rules_direction FOREIGN KEY (direction_id) REFERENCES route_directions(id),
    CONSTRAINT fk_client_rules_created_client FOREIGN KEY (created_by_client_id) REFERENCES clients(id),
    CONSTRAINT fk_client_rules_updated_client FOREIGN KEY (updated_by_client_id) REFERENCES clients(id),
    UNIQUE KEY uniq_client_rule_active_duplicate (service_scope_id, active_duplicate_key),
    INDEX idx_client_rules_scope (service_scope_id, tombstoned_at),
    INDEX idx_client_rules_service (service_id),
    INDEX idx_client_rules_direction (direction_id),
    INDEX idx_client_rules_sync (sync_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 4: Mirror the migration in `schema.sql`**

In `master/migrations/schema.sql`:

1. Add `max_client_rules_per_service_scope INT NOT NULL DEFAULT 5` inside `CREATE TABLE IF NOT EXISTS service_plans` after `max_client_profiles INT NOT NULL DEFAULT 1`.
2. Add `route_rule_helper_only TINYINT(1) NOT NULL DEFAULT 0` inside `CREATE TABLE IF NOT EXISTS forwards` after `enabled TINYINT(1) NOT NULL DEFAULT 1`.
3. Insert the `CREATE TABLE IF NOT EXISTS route_directions`, `direction_realizations`, `service_plan_route_directions`, and `client_rules` blocks from the migration. In `schema.sql`, use `CREATE TABLE IF NOT EXISTS` instead of `CREATE TABLE` and keep the same column, key, and foreign-key names.

- [ ] **Step 5: Run the schema test and verify it passes the schema checks**

Run: `php master/tests/route_direction_schema_test.php`

Expected: FAIL with `ProvisioningService must wire RouteDirectionService and ClientRuleValidator after schema lands`. This is the intended next red state because the service classes are not wired yet.

- [ ] **Step 6: Commit the schema changes**

```bash
git add master/migrations/schema.sql master/migrations/20260722_add_route_directions_client_rules.sql master/tests/route_direction_schema_test.php
git commit -m "feat(master): add route direction client rule schema" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: RouteDirectionService

**Files:**
- Create: `master/src/Support/RouteDirectionService.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Test: `master/tests/route_direction_service_test.php`

- [ ] **Step 1: Write the failing RouteDirectionService test**

Create `master/tests/route_direction_service_test.php` using the shared harness and sqlite helpers, then add these assertions:

```php
use Leikwan\Master\Support\ClientRuleValidator;
use Leikwan\Master\Support\RouteDirectionService;
use Leikwan\Master\Support\ApiException;

[$config, $db] = makeTestDb('route-direction-service');
installRouteRuleTestSchema($db);
$now = gmdate('Y-m-d H:i:s');
$db->insert('service_plans', ['name' => 'Pro', 'product_code' => 'pro', 'max_client_rules_per_service_scope' => 2, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
$db->insert('services', ['user_id' => 9, 'plan_id' => 1, 'name' => 'Service A', 'whmcs_service_id' => 9001, 'status' => 'active', 'client_token' => 'tok', 'created_at' => $now, 'updated_at' => $now]);
$db->insert('clients', ['owner_user_id' => 9, 'group_id' => null, 'agent_token' => 'agent', 'agent_config_json' => json_encode(['supports_route_direction_rules' => true, 'supports_exact_destination_rules' => true]), 'name' => 'Client A', 'display_host' => 'client-a', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
$db->insert('forwards', ['service_id' => 1, 'listen_port' => 11001, 'line_client_id' => 1, 'line_client_group_id' => null, 'enabled' => 1, 'route_rule_helper_only' => 1, 'created_at' => $now, 'updated_at' => $now]);

$validator = new ClientRuleValidator($config, $db);
$service = new RouteDirectionService($config, $db, $validator);

$saved = $service->saveDirection([
    'id' => ' DIR_SH_JP_1 ',
    'label' => '沪日1',
    'status' => 'available',
    'admin_notes' => 'operator-only mapping note',
    'sort_order' => 10,
    'realization' => [
        'binding_state' => 'ready',
        'forward_id' => 1,
        'local_endpoint_host' => '127.0.0.1',
        'local_tcp_port' => 11001,
        'local_udp_port' => 11001,
        'tcp_ready' => true,
        'udp_ready' => true,
        'helper_only' => true,
        'internal_mapping' => ['internal_line_label' => 'line-a', 'internal_group_key' => 'group-a'],
    ],
]);
if (($saved['id'] ?? '') !== 'dir_sh_jp_1' || ($saved['label'] ?? '') !== '沪日1') {
    fail('saveDirection did not normalize id and preserve label');
}

$userDirections = $service->listDirections(false);
$userJson = json_encode($userDirections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
foreach (['internal_line_label', 'internal_group_key', 'forward_id', 'local_tcp_port'] as $forbidden) {
    if (str_contains((string) $userJson, $forbidden)) {
        fail('user direction payload leaked internal field ' . $forbidden);
    }
}

$adminDirection = $service->getDirection('dir_sh_jp_1', true);
if ((int) ($adminDirection['realization']['forward_id'] ?? 0) !== 1) {
    fail('admin direction payload did not include primary realization mapping');
}

$service->savePlanEntitlements(1, ['dir_sh_jp_1'], 2);
$allowed = $service->allowedDirectionIdsForPlan(1);
if ($allowed !== ['dir_sh_jp_1']) {
    fail('allowedDirectionIdsForPlan returned ' . json_encode($allowed));
}
$scope = $service->serviceScopeForServiceId(1);
if ($scope !== 'svc_scope_1' || $service->serviceIdForScope($scope) !== 1) {
    fail('service scope normalization failed');
}
$entitlement = $service->entitlementForScope($scope);
if (($entitlement['max_rules'] ?? 0) !== 2 || ($entitlement['remaining_rules'] ?? -1) !== 2) {
    fail('entitlementForScope did not expose max and remaining rule quota');
}
$pull = $service->configPullForClient(1, ['route_direction_rules' => true, 'exact_destination_rules' => true], [['forward_id' => 1, 'listen_port' => 11001]]);
foreach (['route_directions', 'route_rule_entitlements', 'client_rules', 'direction_runtime_bindings', 'capabilities'] as $key) {
    if (!array_key_exists($key, $pull)) {
        fail('configPullForClient missing ' . $key);
    }
}
$binding = $pull['direction_runtime_bindings'][0] ?? null;
if (!is_array($binding) || ($binding['binding_state'] ?? '') !== 'ready') {
    fail('ready realization did not create a ready runtime binding');
}
$requiredBindingKeys = ['service_scope_id', 'client_runtime_binding_id', 'direction_id', 'binding_state', 'runtime_ref', 'local_endpoint', 'tcp_ready', 'udp_ready'];
foreach ($requiredBindingKeys as $key) {
    if (!array_key_exists($key, $binding)) {
        fail('ready runtime binding missing required key ' . $key);
    }
}
if (($binding['service_scope_id'] ?? '') !== 'svc_scope_1') {
    fail('ready runtime binding missing service_scope_id svc_scope_1');
}
if (($binding['client_runtime_binding_id'] ?? '') !== 'svc_scope_1:dir_sh_jp_1') {
    fail('ready runtime binding missing stable client_runtime_binding_id');
}
if (($binding['direction_id'] ?? '') !== 'dir_sh_jp_1') {
    fail('ready runtime binding missing direction_id dir_sh_jp_1');
}
$endpoint = is_array($binding['local_endpoint'] ?? null) ? $binding['local_endpoint'] : [];
$endpointKeys = array_keys($endpoint);
sort($endpointKeys);
if ($endpointKeys !== ['host', 'tcp_port', 'udp_port']) {
    fail('ready runtime binding local_endpoint must contain exactly host, tcp_port, udp_port');
}
if (($endpoint['host'] ?? '') !== '127.0.0.1' || (int) ($endpoint['tcp_port'] ?? 0) !== 11001 || (int) ($endpoint['udp_port'] ?? 0) !== 11001) {
    fail('ready runtime binding local_endpoint contract is wrong');
}
if (array_key_exists('protocol', $endpoint)) {
    fail('ready runtime binding local_endpoint must not contain protocol');
}
if (($binding['tcp_ready'] ?? null) !== true || ($binding['udp_ready'] ?? null) !== true) {
    fail('ready runtime binding readiness booleans must be true');
}
$bindingJson = json_encode($binding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
foreach (['local_tcp_port', 'local_udp_port'] as $legacyPortField) {
    if (str_contains((string) $bindingJson, '"' . $legacyPortField . '"')) {
        fail('direction_runtime_bindings must not expose legacy top-level field ' . $legacyPortField);
    }
}

$legacyPull = $service->configPullForClient(1, ['route_direction_rules' => false, 'exact_destination_rules' => false], []);
if ($legacyPull !== [] || array_key_exists('direction_runtime_bindings', $legacyPull)) {
    fail('clients without capability must not receive route-rule pull fields or direction_runtime_bindings');
}

$threw = false;
try {
    $service->saveDirection(['id' => 'bad id', 'label' => 'Bad', 'status' => 'available']);
} catch (ApiException $exception) {
    $threw = $exception->getMessage() === 'invalid_route_direction_id';
}
if (!$threw) {
    fail('invalid direction id was not rejected');
}

echo "ok\n";
```

- [ ] **Step 2: Run the RouteDirectionService test and verify it fails**

Run: `php master/tests/route_direction_service_test.php`

Expected: FAIL with `Class "Leikwan\Master\Support\ClientRuleValidator" not found` or `Class "Leikwan\Master\Support\RouteDirectionService" not found`.

- [ ] **Step 3: Create RouteDirectionService with the required public API**

Create `master/src/Support/RouteDirectionService.php`. The class must be `final`, in namespace `Leikwan\Master\Support`, and expose these exact methods:

```php
public function normalizeDirectionId(string $id): string
public function normalizeStatus(string $status): string
public function directionPayload(array $direction, bool $admin = false): array
public function listDirections(bool $admin = false, ?string $serviceScopeId = null): array
public function getDirection(string $directionId, bool $admin = false): ?array
public function saveDirection(array $payload): array
public function savePlanEntitlements(int $planId, array $directionIds, int $maxRules): void
public function allowedDirectionIdsForPlan(int $planId): array
public function serviceScopeForServiceId(int $serviceId): string
public function serviceIdForScope(string $serviceScopeId): int
public function serviceForScope(string $serviceScopeId): ?array
public function entitlementForScope(string $serviceScopeId): array
public function configPullForClient(int $clientId, array $capabilities, array $forwards): array
```

Use this exact constructor and top-level read/save methods inside the class, then add the normalization and helper snippets below it:

```php
public function __construct(
    private \Leikwan\Master\Config $config,
    private \Leikwan\Master\Database\Database $db,
    private ClientRuleValidator $validator,
) {
}

public function listDirections(bool $admin = false, ?string $serviceScopeId = null): array
{
    $rows = $this->db->fetchAll(
        'SELECT * FROM route_directions ORDER BY sort_order ASC, label ASC, id ASC'
    );
    if ($serviceScopeId !== null) {
        $allowed = $this->entitlementForScope($serviceScopeId)['allowed_direction_ids'] ?? [];
        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => in_array((string) ($row['id'] ?? ''), $allowed, true)
        ));
    }
    return array_map(fn(array $row): array => $this->directionPayload($row, $admin), $rows);
}

public function getDirection(string $directionId, bool $admin = false): ?array
{
    $id = $this->normalizeDirectionId($directionId);
    $row = $this->db->fetchOne('SELECT * FROM route_directions WHERE id = :id LIMIT 1', ['id' => $id]);
    return $row === null ? null : $this->directionPayload($row, $admin);
}

public function saveDirection(array $payload): array
{
    $id = $this->normalizeDirectionId((string) ($payload['id'] ?? ''));
    $status = $this->normalizeStatus((string) ($payload['status'] ?? 'available'));
    $label = trim((string) ($payload['label'] ?? ''));
    if ($label === '') {
        throw new ApiException('invalid_route_direction_label', 422);
    }
    $now = $this->now();
    $existing = $this->db->fetchOne('SELECT id FROM route_directions WHERE id = :id LIMIT 1', ['id' => $id]);
    if ($existing === null) {
        $this->db->insert('route_directions', [
            'id' => $id,
            'label' => $label,
            'status' => $status,
            'admin_notes' => (string) ($payload['admin_notes'] ?? ''),
            'sort_order' => (int) ($payload['sort_order'] ?? 100),
            'internal_mapping_json' => json_encode($payload['internal_mapping'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    } else {
        $this->db->execute(
            'UPDATE route_directions SET label = :label, status = :status, admin_notes = :admin_notes, sort_order = :sort_order, internal_mapping_json = :internal_mapping_json, updated_at = :updated_at WHERE id = :id',
            [
                'id' => $id,
                'label' => $label,
                'status' => $status,
                'admin_notes' => (string) ($payload['admin_notes'] ?? ''),
                'sort_order' => (int) ($payload['sort_order'] ?? 100),
                'internal_mapping_json' => json_encode($payload['internal_mapping'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
            ]
        );
    }
    if (is_array($payload['realization'] ?? null)) {
        $this->savePrimaryRealization($id, $payload['realization']);
    }
    $saved = $this->getDirection($id, true);
    if ($saved === null) {
        throw new ApiException('route_direction_save_failed', 500);
    }
    return $saved;
}

private function savePrimaryRealization(string $directionId, array $realization): void
{
    $now = $this->now();
    $forwardId = max(0, (int) ($realization['forward_id'] ?? 0));
    $data = [
        'direction_id' => $directionId,
        'is_primary' => 1,
        'binding_state' => (string) ($realization['binding_state'] ?? ($forwardId > 0 ? 'ready' : 'missing')),
        'forward_id' => $forwardId > 0 ? $forwardId : null,
        'local_endpoint_host' => (string) ($realization['local_endpoint_host'] ?? '127.0.0.1'),
        'local_tcp_port' => max(0, (int) ($realization['local_tcp_port'] ?? 0)) ?: null,
        'local_udp_port' => max(0, (int) ($realization['local_udp_port'] ?? 0)) ?: null,
        'tcp_ready' => !empty($realization['tcp_ready']) ? 1 : 0,
        'udp_ready' => !empty($realization['udp_ready']) ? 1 : 0,
        'helper_only' => array_key_exists('helper_only', $realization) ? (!empty($realization['helper_only']) ? 1 : 0) : 1,
        'internal_mapping_json' => json_encode($realization['internal_mapping'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'updated_at' => $now,
    ];
    $existing = $this->db->fetchOne('SELECT id FROM direction_realizations WHERE direction_id = :direction_id AND is_primary = 1 LIMIT 1', ['direction_id' => $directionId]);
    if ($existing === null) {
        $this->db->insert('direction_realizations', $data + ['created_at' => $now]);
        return;
    }
    $data['id'] = (int) $existing['id'];
    $this->db->execute(
        'UPDATE direction_realizations SET binding_state = :binding_state, forward_id = :forward_id, local_endpoint_host = :local_endpoint_host, local_tcp_port = :local_tcp_port, local_udp_port = :local_udp_port, tcp_ready = :tcp_ready, udp_ready = :udp_ready, helper_only = :helper_only, internal_mapping_json = :internal_mapping_json, updated_at = :updated_at WHERE id = :id',
        $data
    );
}
```

Use these exact normalization snippets inside the class:

```php
public function normalizeDirectionId(string $id): string
{
    $normalized = strtolower(trim($id));
    $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? $normalized;
    $normalized = trim($normalized, '_');
    if (!preg_match('/^dir_[a-z0-9_]{2,60}$/', $normalized)) {
        throw new ApiException('invalid_route_direction_id', 422);
    }
    return $normalized;
}

public function normalizeStatus(string $status): string
{
    $normalized = strtolower(trim($status));
    if (!in_array($normalized, ['available', 'unavailable', 'maintenance', 'retired'], true)) {
        throw new ApiException('invalid_route_direction_status', 422);
    }
    return $normalized;
}
```

Use this exact public payload shape for non-admin direction payloads:

```php
public function directionPayload(array $direction, bool $admin = false): array
{
    $status = $this->normalizeStatus((string) ($direction['status'] ?? 'available'));
    $payload = [
        'id' => (string) $direction['id'],
        'label' => (string) $direction['label'],
        'status' => $status,
        'is_available' => $status === 'available',
        'sort_order' => (int) ($direction['sort_order'] ?? 100),
    ];
    if (!$admin) {
        return $payload;
    }
    $payload['admin_notes'] = (string) ($direction['admin_notes'] ?? '');
    $payload['internal_mapping'] = $this->decodeJsonAssoc($direction['internal_mapping_json'] ?? null);
    $payload['realization'] = $this->primaryRealizationForDirection((string) $direction['id']);
    $payload['usage'] = $this->usageForDirection((string) $direction['id']);
    return $payload;
}
```

Use this exact `configPullForClient()` skeleton and binding rules:

```php
public function configPullForClient(int $clientId, array $capabilities, array $forwards): array
{
    if (empty($capabilities['route_direction_rules']) || empty($capabilities['exact_destination_rules'])) {
        return [];
    }
    $serviceIds = $this->serviceIdsForClientPull($clientId, $forwards);
    $payload = [
        'route_directions' => [],
        'route_rule_entitlements' => [],
        'client_rules' => [],
        'direction_runtime_bindings' => [],
        'capabilities' => ['route_direction_rules' => true, 'exact_destination_rules' => true],
    ];
    if ($serviceIds === []) {
        return $payload;
    }
    $directionsById = [];
    foreach ($serviceIds as $serviceId) {
        $serviceScopeId = $this->serviceScopeForServiceId((int) $serviceId);
        $entitlement = $this->entitlementForScope($serviceScopeId);
        $payload['route_rule_entitlements'][] = $entitlement;
        foreach ($this->rulesForScope($serviceScopeId) as $rule) {
            $payload['client_rules'][] = [
                'id' => (string) $rule['id'],
                'service_scope_id' => $serviceScopeId,
                'destination_ip' => (string) $rule['destination_ip'],
                'destination_port' => (int) $rule['destination_port'],
                'protocol' => 'tcp_udp',
                'direction_id' => (string) $rule['direction_id'],
                'enabled' => (int) ($rule['enabled'] ?? 0) === 1,
                'effective_state' => $this->effectiveStateForRule($rule, $entitlement),
                'sync_version' => (int) ($rule['sync_version'] ?? 1),
            ];
        }
        foreach ($this->directionsRelevantToScope($serviceScopeId) as $direction) {
            $directionsById[(string) $direction['id']] = $this->directionPayload($direction, false);
            $binding = $this->runtimeBindingForDirection($serviceScopeId, $direction);
            if ($binding !== null) {
                $payload['direction_runtime_bindings'][] = $binding;
            }
        }
    }
    $payload['route_directions'] = array_values($directionsById);
    return $payload;
}
```

For each service scope in `configPullForClient()`:

- Include only plan-allowed directions and non-tombstoned rule-bound directions needed for explainability.
- Return `route_rule_entitlements[]` with `service_scope_id`, `service_id`, `subscription_id`, `max_rules`, `remaining_rules`, and `allowed_direction_ids`.
- Return non-tombstoned `client_rules[]` only.
- Return `direction_runtime_bindings[]` only for directions with a primary realization. A binding is `ready` only when `binding_state = ready`, `tcp_ready = 1`, `udp_ready = 1`, `forward_id` is non-null, and both local ports are positive. Otherwise the binding state is `missing`.
- Use `runtime_ref.type = forward_ref`, `runtime_ref.forward_id`, `runtime_ref.selectable = false`, and `runtime_ref.transport = tcp_udp`.
- Lock this external binding contract exactly: each returned ready binding must contain `service_scope_id`, `client_runtime_binding_id`, `direction_id`, `binding_state`, `runtime_ref`, `local_endpoint`, `tcp_ready`, and `udp_ready`. `local_endpoint` must contain exactly `host`, `tcp_port`, and `udp_port`; it must not contain `protocol`, and it must not expose legacy top-level `local_tcp_port` or `local_udp_port` inside `direction_runtime_bindings[]`.

Use this exact `runtimeBindingForDirection()` implementation so tests and service code share the same `direction_runtime_bindings[]` contract:

```php
private function runtimeBindingForDirection(string $serviceScopeId, array $direction): ?array
{
    $realization = $this->primaryRealizationForDirection((string) ($direction['id'] ?? ''));
    if ($realization === null) {
        return null;
    }
    $forwardId = (int) ($realization['forward_id'] ?? 0);
    $tcpPort = (int) ($realization['local_tcp_port'] ?? 0);
    $udpPort = (int) ($realization['local_udp_port'] ?? 0);
    $tcpReady = (int) ($realization['tcp_ready'] ?? 0) === 1;
    $udpReady = (int) ($realization['udp_ready'] ?? 0) === 1;
    $ready = (string) ($realization['binding_state'] ?? '') === 'ready'
        && $forwardId > 0
        && $tcpPort > 0
        && $udpPort > 0
        && $tcpReady
        && $udpReady;

    return [
        'service_scope_id' => $serviceScopeId,
        'client_runtime_binding_id' => $serviceScopeId . ':' . (string) $direction['id'],
        'direction_id' => (string) $direction['id'],
        'binding_state' => $ready ? 'ready' : 'missing',
        'runtime_ref' => [
            'type' => 'forward_ref',
            'forward_id' => $forwardId > 0 ? $forwardId : null,
            'selectable' => false,
            'transport' => 'tcp_udp',
        ],
        'local_endpoint' => [
            'host' => (string) ($realization['local_endpoint_host'] ?? '127.0.0.1'),
            'tcp_port' => $tcpPort > 0 ? $tcpPort : null,
            'udp_port' => $udpPort > 0 ? $udpPort : null,
        ],
        'tcp_ready' => $ready,
        'udp_ready' => $ready,
    ];
}
```

When adding a binding to `direction_runtime_bindings[]`, append only the array returned by `runtimeBindingForDirection()`:

```php
$binding = $this->runtimeBindingForDirection($serviceScopeId, $direction);
if ($binding !== null) {
    $payload['direction_runtime_bindings'][] = $binding;
}
```

Use these exact scope, entitlement, and lookup helpers so the service is executable rather than prose-only:

```php
public function savePlanEntitlements(int $planId, array $directionIds, int $maxRules): void
{
    $normalized = [];
    foreach ($directionIds as $directionId) {
        $normalized[] = $this->normalizeDirectionId((string) $directionId);
    }
    $normalized = array_values(array_unique($normalized));
    $now = $this->now();
    $this->db->transaction(function () use ($planId, $normalized, $maxRules, $now): void {
        $this->db->execute('DELETE FROM service_plan_route_directions WHERE plan_id = :plan_id', ['plan_id' => $planId]);
        foreach ($normalized as $directionId) {
            $this->db->insert('service_plan_route_directions', [
                'plan_id' => $planId,
                'direction_id' => $directionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->db->execute(
            'UPDATE service_plans SET max_client_rules_per_service_scope = :max_rules, updated_at = :updated_at WHERE id = :id',
            ['id' => $planId, 'max_rules' => max(0, $maxRules), 'updated_at' => $now]
        );
    });
}

public function allowedDirectionIdsForPlan(int $planId): array
{
    if ($planId <= 0) {
        return [];
    }
    $rows = $this->db->fetchAll(
        'SELECT direction_id FROM service_plan_route_directions WHERE plan_id = :plan_id ORDER BY direction_id ASC',
        ['plan_id' => $planId]
    );
    return array_values(array_map(static fn(array $row): string => (string) $row['direction_id'], $rows));
}

public function serviceScopeForServiceId(int $serviceId): string
{
    if ($serviceId <= 0) {
        throw new ApiException('invalid_service_scope', 422);
    }
    return 'svc_scope_' . $serviceId;
}

public function serviceIdForScope(string $serviceScopeId): int
{
    if (preg_match('/^svc_scope_(\d+)$/', $serviceScopeId, $matches) !== 1) {
        throw new ApiException('invalid_service_scope', 422);
    }
    return (int) $matches[1];
}

public function serviceForScope(string $serviceScopeId): ?array
{
    return $this->db->fetchOne(
        'SELECT s.*, p.max_client_rules_per_service_scope
         FROM services s
         LEFT JOIN service_plans p ON p.id = s.plan_id
         WHERE s.id = :service_id
         LIMIT 1',
        ['service_id' => $this->serviceIdForScope($serviceScopeId)]
    );
}

public function entitlementForScope(string $serviceScopeId): array
{
    $service = $this->serviceForScope($serviceScopeId);
    if ($service === null) {
        throw new ApiException('service_not_found', 404);
    }
    $planId = (int) ($service['plan_id'] ?? 0);
    $maxRules = max(0, (int) ($service['max_client_rules_per_service_scope'] ?? 0));
    $usedRules = (int) (($this->db->fetchOne(
        'SELECT COUNT(*) AS total FROM client_rules WHERE service_scope_id = :service_scope_id AND tombstoned_at IS NULL',
        ['service_scope_id' => $serviceScopeId]
    )['total'] ?? 0));
    return [
        'service_scope_id' => $serviceScopeId,
        'service_id' => (int) $service['id'],
        'subscription_id' => $service['whmcs_service_id'] !== null ? (int) $service['whmcs_service_id'] : null,
        'max_rules' => $maxRules,
        'remaining_rules' => max(0, $maxRules - $usedRules),
        'allowed_direction_ids' => $this->allowedDirectionIdsForPlan($planId),
        'over_quota' => $usedRules > $maxRules,
    ];
}

private function primaryRealizationForDirection(string $directionId): ?array
{
    return $this->db->fetchOne(
        'SELECT * FROM direction_realizations WHERE direction_id = :direction_id AND is_primary = 1 LIMIT 1',
        ['direction_id' => $directionId]
    );
}

private function usageForDirection(string $directionId): array
{
    $row = $this->db->fetchOne(
        'SELECT COUNT(*) AS rules FROM client_rules WHERE direction_id = :direction_id AND tombstoned_at IS NULL',
        ['direction_id' => $directionId]
    ) ?? [];
    return ['rules' => (int) ($row['rules'] ?? 0)];
}

private function serviceIdsForClientPull(int $clientId, array $forwards): array
{
    $serviceIds = [];
    foreach ($forwards as $forward) {
        if (isset($forward['service_id'])) {
            $serviceIds[] = (int) $forward['service_id'];
        }
    }
    if ($serviceIds === []) {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT s.id
             FROM services s
             INNER JOIN clients c ON c.owner_user_id = s.user_id
             WHERE c.id = :client_id AND s.status = :active
             ORDER BY s.id ASC',
            ['client_id' => $clientId, 'active' => 'active']
        );
        $serviceIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
    }
    return array_values(array_unique(array_filter($serviceIds, static fn(int $id): bool => $id > 0)));
}

private function rulesForScope(string $serviceScopeId): array
{
    return $this->db->fetchAll(
        'SELECT * FROM client_rules WHERE service_scope_id = :service_scope_id AND tombstoned_at IS NULL ORDER BY id ASC',
        ['service_scope_id' => $serviceScopeId]
    );
}

private function directionsRelevantToScope(string $serviceScopeId): array
{
    $entitlement = $this->entitlementForScope($serviceScopeId);
    $ids = $entitlement['allowed_direction_ids'] ?? [];
    foreach ($this->rulesForScope($serviceScopeId) as $rule) {
        $ids[] = (string) $rule['direction_id'];
    }
    $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '')));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return $this->db->fetchAll(
        'SELECT * FROM route_directions WHERE id IN (' . $placeholders . ') ORDER BY sort_order ASC, label ASC, id ASC',
        $ids
    );
}

private function effectiveStateForRule(array $rule, array $entitlement): string
{
    if ((int) ($rule['enabled'] ?? 0) !== 1) {
        return 'disabled';
    }
    if (!in_array((string) ($rule['direction_id'] ?? ''), $entitlement['allowed_direction_ids'] ?? [], true)) {
        return 'not_allowed_by_plan';
    }
    $direction = $this->getDirection((string) $rule['direction_id'], false);
    if ($direction === null || (string) ($direction['status'] ?? '') !== 'available') {
        return 'unavailable';
    }
    $binding = $this->runtimeBindingForDirection((string) $entitlement['service_scope_id'], $direction);
    return $binding !== null && ($binding['binding_state'] ?? '') === 'ready' ? 'active' : 'runtime_missing';
}

private function decodeJsonAssoc(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

private function now(): string
{
    return gmdate('Y-m-d H:i:s');
}
```

Add private helpers in the same class with these exact names because later tasks call them: `decodeJsonAssoc`, `now`, `primaryRealizationForDirection`, `usageForDirection`, `serviceIdsForClientPull`, `directionsRelevantToScope`, `rulesForScope`, `runtimeBindingForDirection`, `effectiveStateForRule`.

- [ ] **Step 4: Wire RouteDirectionService into ProvisioningService**

In `master/src/Support/ProvisioningService.php`, add properties near the existing private properties:

```php
private ?RouteDirectionService $routeDirectionService = null;
private ?ClientRuleValidator $clientRuleValidator = null;
```

Add these public wrappers near the plan methods:

```php
public function routeDirections(): RouteDirectionService
{
    if (!$this->routeDirectionService instanceof RouteDirectionService) {
        $this->routeDirectionService = new RouteDirectionService($this->config, $this->db, $this->clientRuleValidator());
    }
    return $this->routeDirectionService;
}

public function listRouteDirections(bool $admin = false): array
{
    return $this->routeDirections()->listDirections($admin);
}

public function saveRouteDirection(array $payload): array
{
    return $this->routeDirections()->saveDirection($payload);
}

public function allowedRouteDirectionIdsForPlan(int $planId): array
{
    return $this->routeDirections()->allowedDirectionIdsForPlan($planId);
}

public function clientRuleValidator(): ClientRuleValidator
{
    if (!$this->clientRuleValidator instanceof ClientRuleValidator) {
        $this->clientRuleValidator = new ClientRuleValidator($this->config, $this->db);
    }
    return $this->clientRuleValidator;
}
```

Add `use` imports are not needed because the new classes are in the same namespace.

- [ ] **Step 5: Run the tests and verify the new red/green state**

Run: `php master/tests/route_direction_service_test.php`

Expected: FAIL with `Class "Leikwan\Master\Support\ClientRuleValidator" not found` until Task 3 creates the validator. After Task 3, rerun this test and expect `ok`.

Run: `php master/tests/route_direction_schema_test.php`

Expected after the wrappers are added: PASS with `ok`.

- [ ] **Step 6: Lint and commit**

Run:

```bash
php -l master/src/Support/RouteDirectionService.php
php -l master/src/Support/ProvisioningService.php
```

Expected: both commands print `No syntax errors detected`.

Commit:

```bash
git add master/src/Support/RouteDirectionService.php master/src/Support/ProvisioningService.php master/tests/route_direction_service_test.php
git commit -m "feat(master): add route direction service" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: ClientRuleValidator

**Files:**
- Create: `master/src/Support/ClientRuleValidator.php`
- Test: `master/tests/client_rule_validator_test.php`

- [ ] **Step 1: Write the failing ClientRuleValidator test**

Create `master/tests/client_rule_validator_test.php` using the shared harness and sqlite helpers, then add:

```php
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\ClientRuleValidator;

[$config, $db] = makeTestDb('client-rule-validator');
installRouteRuleTestSchema($db);
$validator = new ClientRuleValidator($config, $db);

if ($validator->normalizeDestinationIp(" 93.184.216.34 ") !== '93.184.216.34') {
    fail('canonical IPv4 normalization failed');
}
foreach (['01.2.3.4', '1.2.3', '1.2.3.4 ', '2001:db8::1', 'example.com', 'http://93.184.216.34'] as $ip) {
    $expected = str_contains($ip, ':') ? 'unsupported_destination_address_family' : 'invalid_destination_ip';
    $threw = false;
    try {
        $validator->normalizeDestinationIp($ip);
    } catch (ApiException $exception) {
        $threw = $exception->getMessage() === $expected;
    }
    if (!$threw) {
        fail('expected ' . $expected . ' for ' . $ip);
    }
}
foreach (['10.0.0.1', '127.0.0.1', '169.254.1.1', '224.0.0.1', '255.255.255.255', '192.0.2.1', '198.51.100.7', '203.0.113.9'] as $blocked) {
    $threw = false;
    try {
        $validator->normalizeDestinationIp($blocked);
    } catch (ApiException $exception) {
        $threw = $exception->getMessage() === 'destination_blocked_by_policy';
    }
    if (!$threw) {
        fail('expected policy block for ' . $blocked);
    }
}
if ($validator->normalizeDestinationPort('443') !== 443) {
    fail('port normalization failed');
}
foreach ([0, 65536, 'abc', '443.1'] as $port) {
    $threw = false;
    try {
        $validator->normalizeDestinationPort($port);
    } catch (ApiException $exception) {
        $threw = $exception->getMessage() === 'invalid_destination_port';
    }
    if (!$threw) {
        fail('expected invalid_destination_port for ' . (string) $port);
    }
}
if ($validator->activeDuplicateKey('93.184.216.34', 443, true) !== '93.184.216.34:443:tcp_udp') {
    fail('active duplicate key for enabled rule is wrong');
}
if ($validator->activeDuplicateKey('93.184.216.34', 443, false) !== null) {
    fail('disabled rule must not participate in duplicate checks');
}

$baseContext = [
    'service_active' => true,
    'sync_version_matches' => true,
    'direction_exists' => true,
    'direction_allowed' => true,
    'direction_status' => 'available',
    'enabled' => true,
    'duplicate_enabled_exists' => false,
    'saved_count_after_write' => 1,
    'max_rules' => 2,
];
$valid = $validator->validateWrite($baseContext + ['destination_ip' => '93.184.216.34', 'destination_port' => 443]);
if (($valid['destination_ip'] ?? '') !== '93.184.216.34' || ($valid['destination_port'] ?? 0) !== 443) {
    fail('validateWrite did not return normalized destination');
}
$cases = [
    ['service_not_active', $baseContext + ['service_active' => false]],
    ['version_conflict', $baseContext + ['sync_version_matches' => false]],
    ['invalid_destination_ip', $baseContext + ['destination_ip' => 'not-an-ip']],
    ['direction_not_allowed', $baseContext + ['direction_allowed' => false]],
    ['direction_unavailable', $baseContext + ['direction_status' => 'maintenance']],
    ['duplicate_destination', $baseContext + ['duplicate_enabled_exists' => true]],
    ['rule_quota_exceeded', $baseContext + ['saved_count_after_write' => 3, 'max_rules' => 2]],
];
foreach ($cases as [$expected, $context]) {
    $threw = false;
    try {
        $validator->validateWrite($context + ['destination_ip' => '93.184.216.34', 'destination_port' => 443]);
    } catch (ApiException $exception) {
        $threw = $exception->getMessage() === $expected;
    }
    if (!$threw) {
        fail('validation precedence did not produce ' . $expected);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the validator test and verify it fails**

Run: `php master/tests/client_rule_validator_test.php`

Expected: FAIL with `Class "Leikwan\Master\Support\ClientRuleValidator" not found`.

- [ ] **Step 3: Create ClientRuleValidator**

Create `master/src/Support/ClientRuleValidator.php` with a `final class ClientRuleValidator` in namespace `Leikwan\Master\Support`. Include this constructor and public API:

```php
public function __construct(
    private \Leikwan\Master\Config $config,
    private \Leikwan\Master\Database\Database $db,
) {
}

public function normalizeDestinationIp(mixed $value): string
public function normalizeDestinationPort(mixed $value): int
public function activeDuplicateKey(string $ip, int $port, bool $enabled): ?string
public function validateWrite(array $context): array
```

Use this exact IPv4 normalization and policy behavior:

```php
public function normalizeDestinationIp(mixed $value): string
{
    $ip = trim((string) $value, " \t\n\r\0\x0B");
    if (str_contains($ip, ':')) {
        throw new ApiException('unsupported_destination_address_family', 422);
    }
    $parts = explode('.', $ip);
    if (count($parts) !== 4) {
        throw new ApiException('invalid_destination_ip', 422);
    }
    $octets = [];
    foreach ($parts as $part) {
        if ($part === '' || !ctype_digit($part)) {
            throw new ApiException('invalid_destination_ip', 422);
        }
        $number = (int) $part;
        if ((string) $number !== $part || $number < 0 || $number > 255) {
            throw new ApiException('invalid_destination_ip', 422);
        }
        $octets[] = $number;
    }
    $canonical = implode('.', array_map('strval', $octets));
    if ($this->isBlockedDestination($octets)) {
        throw new ApiException('destination_blocked_by_policy', 422);
    }
    return $canonical;
}

private function isBlockedDestination(array $octets): bool
{
    [$a, $b] = [$octets[0], $octets[1]];
    if ($a === 0 || $a === 10 || $a === 127 || $a >= 224) {
        return true;
    }
    if ($a === 100 && $b >= 64 && $b <= 127) {
        return true;
    }
    if ($a === 169 && $b === 254) {
        return true;
    }
    if ($a === 172 && $b >= 16 && $b <= 31) {
        return true;
    }
    if ($a === 192 && ($b === 0 || $b === 168)) {
        return true;
    }
    if ($a === 198 && ($b === 18 || $b === 19 || $b === 51)) {
        return true;
    }
    if ($a === 203 && $b === 0) {
        return true;
    }
    return false;
}
```

Use this write validation order in `validateWrite()`:

```php
public function validateWrite(array $context): array
{
    if (empty($context['service_active'])) {
        throw new ApiException('service_not_active', 422);
    }
    if (array_key_exists('sync_version_matches', $context) && empty($context['sync_version_matches'])) {
        throw new ApiException('version_conflict', 409);
    }
    $ip = $this->normalizeDestinationIp($context['destination_ip'] ?? '');
    $port = $this->normalizeDestinationPort($context['destination_port'] ?? 0);
    if (empty($context['direction_exists']) || empty($context['direction_allowed'])) {
        throw new ApiException('direction_not_allowed', 422);
    }
    if ((string) ($context['direction_status'] ?? 'available') !== 'available') {
        throw new ApiException('direction_unavailable', 422);
    }
    if (!empty($context['enabled']) && !empty($context['duplicate_enabled_exists'])) {
        throw new ApiException('duplicate_destination', 409);
    }
    if ((int) ($context['saved_count_after_write'] ?? 0) > (int) ($context['max_rules'] ?? 0)) {
        throw new ApiException('rule_quota_exceeded', 422);
    }
    return [
        'destination_ip' => $ip,
        'destination_port' => $port,
        'protocol' => 'tcp_udp',
    ];
}
```

- [ ] **Step 4: Run validator and service tests**

Run:

```bash
php master/tests/client_rule_validator_test.php
php master/tests/route_direction_service_test.php
php master/tests/route_direction_schema_test.php
```

Expected: each command prints `ok`.

- [ ] **Step 5: Lint and commit**

Run: `php -l master/src/Support/ClientRuleValidator.php`

Expected: `No syntax errors detected in master/src/Support/ClientRuleValidator.php`.

Commit:

```bash
git add master/src/Support/ClientRuleValidator.php master/tests/client_rule_validator_test.php
git commit -m "feat(master): add client rule validator" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: ProvisioningService plan entitlements and Client Rule CRUD

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Test: `master/tests/plan_direction_entitlements_test.php`

- [ ] **Step 1: Write the failing entitlement/CRUD test**

Create `master/tests/plan_direction_entitlements_test.php` using the shared harness and sqlite helpers, then add:

```php
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\ProvisioningService;

[$config, $db] = makeTestDb('plan-direction-entitlements');
installRouteRuleTestSchema($db);
$now = gmdate('Y-m-d H:i:s');
$db->insert('service_plans', ['name' => 'Pro', 'product_code' => 'pro', 'max_client_rules_per_service_scope' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
$db->insert('services', ['user_id' => 42, 'plan_id' => 1, 'name' => 'Service A', 'whmcs_service_id' => 4201, 'status' => 'active', 'client_token' => 'tok', 'created_at' => $now, 'updated_at' => $now]);
$db->insert('clients', ['owner_user_id' => 42, 'group_id' => null, 'agent_token' => 'agent', 'agent_config_json' => json_encode(['supports_route_direction_rules' => true, 'supports_exact_destination_rules' => true]), 'name' => 'Client A', 'display_host' => 'client-a', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
$db->insert('forwards', ['service_id' => 1, 'listen_port' => 11001, 'line_client_id' => 1, 'line_client_group_id' => null, 'enabled' => 1, 'route_rule_helper_only' => 1, 'created_at' => $now, 'updated_at' => $now]);
$provisioning = new ProvisioningService($config, $db);
$provisioning->saveRouteDirection(['id' => 'dir_sh_jp_1', 'label' => '沪日1', 'status' => 'available', 'realization' => ['binding_state' => 'ready', 'forward_id' => 1, 'local_tcp_port' => 11001, 'local_udp_port' => 11001, 'tcp_ready' => true, 'udp_ready' => true]]);
$provisioning->saveRouteDirection(['id' => 'dir_sh_hk_1', 'label' => '沪港1', 'status' => 'available']);

$provisioning->savePlan([
    'id' => 1,
    'name' => 'Pro',
    'product_code' => 'pro',
    'default_bandwidth_limit_mbps' => 100,
    'default_traffic_limit_gb' => 1000,
    'allowed_route_direction_ids' => ['dir_sh_jp_1'],
    'max_client_rules_per_service_scope' => 1,
    'feature_flags' => [],
]);
$allowed = $provisioning->allowedRouteDirectionIdsForPlan(1);
if ($allowed !== ['dir_sh_jp_1']) {
    fail('plan did not persist allowed route direction ids');
}
$plans = $provisioning->listPlans();
if (($plans[0]['max_client_rules_per_service_scope'] ?? 0) !== 1 || ($plans[0]['allowed_route_directions'][0]['id'] ?? '') !== 'dir_sh_jp_1') {
    fail('listPlans did not include route direction entitlements and quota');
}

$created = $provisioning->createClientRuleForAgent(1, [
    'service_scope_id' => 'svc_scope_1',
    'destination_ip' => '93.184.216.34',
    'destination_port' => 443,
    'direction_id' => 'dir_sh_jp_1',
    'enabled' => true,
]);
if (($created['sync_version'] ?? 0) !== 1 || ($created['effective_state'] ?? '') !== 'active') {
    fail('createClientRuleForAgent did not return accepted active rule');
}
$duplicate = false;
try {
    $provisioning->createClientRuleForAgent(1, ['service_scope_id' => 'svc_scope_1', 'destination_ip' => '93.184.216.34', 'destination_port' => 443, 'direction_id' => 'dir_sh_jp_1', 'enabled' => true]);
} catch (ApiException $exception) {
    $duplicate = $exception->getMessage() === 'duplicate_destination';
}
if (!$duplicate) {
    fail('duplicate enabled destination was not rejected');
}

$updated = $provisioning->updateClientRuleForAgent(1, (string) $created['id'], ['sync_version' => 1, 'enabled' => false]);
if (($updated['sync_version'] ?? 0) !== 2 || ($updated['effective_state'] ?? '') !== 'disabled') {
    fail('updateClientRuleForAgent did not increment sync_version and disable rule');
}
$conflict = false;
try {
    $provisioning->updateClientRuleForAgent(1, (string) $created['id'], ['sync_version' => 1, 'enabled' => true]);
} catch (ApiException $exception) {
    $conflict = $exception->getMessage() === 'version_conflict';
}
if (!$conflict) {
    fail('stale sync_version was not rejected');
}

$adminSaved = $provisioning->saveClientRuleFromAdmin((string) $created['id'], ['sync_version' => 2, 'destination_port' => 444, 'direction_id' => 'dir_sh_jp_1', 'enabled' => true, 'notes' => 'support edit']);
if (($adminSaved['destination_port'] ?? 0) !== 444 || ($adminSaved['sync_version'] ?? 0) !== 3) {
    fail('admin edit did not update destination and sync version');
}
$provisioning->tombstoneClientRuleFromAdmin((string) $created['id'], 'support cleanup');
$rules = $provisioning->listClientRulesForAdmin(['service_scope_id' => 'svc_scope_1']);
if (($rules[0]['tombstoned_at'] ?? '') === '') {
    fail('admin tombstone did not preserve audit row');
}

echo "ok\n";
```

- [ ] **Step 2: Run the entitlement/CRUD test and verify it fails**

Run: `php master/tests/plan_direction_entitlements_test.php`

Expected: FAIL with a missing method such as `Call to undefined method Leikwan\Master\Support\ProvisioningService::createClientRuleForAgent()`.

- [ ] **Step 3: Extend `listPlans()` and `savePlan()` for direction entitlements**

In `ProvisioningService::listPlans()`, add:

```php
$plan['allowed_route_directions'] = $this->routeDirections()->listDirections(true);
$allowedDirectionIds = $this->allowedRouteDirectionIdsForPlan((int) $plan['id']);
$plan['allowed_route_directions'] = array_values(array_filter(
    $plan['allowed_route_directions'],
    static fn(array $direction): bool => in_array((string) ($direction['id'] ?? ''), $allowedDirectionIds, true)
));
$plan['allowed_route_direction_ids'] = $allowedDirectionIds;
$plan['max_client_rules_per_service_scope'] = max(0, (int) ($plan['max_client_rules_per_service_scope'] ?? 0));
```

In `ProvisioningService::savePlan()`, parse the new payload before validation:

```php
$routeDirectionIds = [];
foreach ((array) ($payload['allowed_route_direction_ids'] ?? []) as $directionId) {
    $routeDirectionIds[] = $this->routeDirections()->normalizeDirectionId((string) $directionId);
}
$routeDirectionIds = array_values(array_unique($routeDirectionIds));
$maxClientRulesPerScope = max(0, (int) ($payload['max_client_rules_per_service_scope'] ?? 5));
```

Add `max_client_rules_per_service_scope = :max_client_rules_per_service_scope` to the update SQL, and add this value to both update and insert parameter arrays:

```php
'max_client_rules_per_service_scope' => $maxClientRulesPerScope,
```

Inside the save transaction, after plan ID is known, call:

```php
$this->routeDirections()->savePlanEntitlements($id, $routeDirectionIds, $maxClientRulesPerScope);
```

Keep legacy `allowed_forward_combination_ids` accepted in `savePlan()` for old tests, but do not require it when `allowed_route_direction_ids` is present. Replace the old unconditional empty forward-combination rejection with:

```php
if ($routeDirectionIds === [] && $forwardCombinationIds === []) {
    throw new ApiException('invalid_plan_route_direction_payload', 422, ['message' => 'Select at least one Route Direction before saving the plan.']);
}
```

- [ ] **Step 4: Add Client Rule CRUD methods to ProvisioningService**

Add these exact public methods:

```php
public function createClientRuleForAgent(int $clientId, array $payload): array
public function updateClientRuleForAgent(int $clientId, string $ruleId, array $payload): array
public function tombstoneClientRuleForAgent(int $clientId, string $ruleId): array
public function saveClientRuleFromAdmin(string $ruleId, array $payload): array
public function tombstoneClientRuleFromAdmin(string $ruleId, string $reason = ''): array
public function clientCanAccessScope(int $clientId, string $serviceScopeId): bool
public function listClientRulesForAdmin(array $filters = []): array
```

Implement the agent access check and rule serialization with these exact helpers before the public CRUD methods so every write path returns the same payload:

```php
public function clientCanAccessScope(int $clientId, string $serviceScopeId): bool
{
    $serviceId = $this->routeDirections()->serviceIdForScope($serviceScopeId);
    return $this->db->fetchOne(
        'SELECT s.id
         FROM services s
         INNER JOIN clients c ON c.owner_user_id = s.user_id
         WHERE s.id = :service_id AND c.id = :client_id AND s.status = :active
         LIMIT 1',
        ['service_id' => $serviceId, 'client_id' => $clientId, 'active' => 'active']
    ) !== null;
}

private function clientRulePayload(array $rule): array
{
    return [
        'id' => (string) $rule['id'],
        'service_scope_id' => (string) $rule['service_scope_id'],
        'service_id' => (int) $rule['service_id'],
        'subscription_id' => $rule['subscription_id'] !== null ? (int) $rule['subscription_id'] : null,
        'destination_ip' => (string) $rule['destination_ip'],
        'destination_port' => (int) $rule['destination_port'],
        'protocol' => 'tcp_udp',
        'direction_id' => (string) $rule['direction_id'],
        'enabled' => (int) ($rule['enabled'] ?? 0) === 1,
        'effective_state' => (string) ($rule['effective_state'] ?? 'runtime_missing'),
        'sync_version' => (int) ($rule['sync_version'] ?? 1),
        'notes' => (string) ($rule['notes'] ?? ''),
        'tombstoned_at' => (string) ($rule['tombstoned_at'] ?? ''),
        'updated_at' => (string) ($rule['updated_at'] ?? ''),
    ];
}

private function loadClientRule(string $ruleId, bool $includeTombstoned = false): ?array
{
    $sql = 'SELECT * FROM client_rules WHERE id = :id';
    if (!$includeTombstoned) {
        $sql .= ' AND tombstoned_at IS NULL';
    }
    $sql .= ' LIMIT 1';
    return $this->db->fetchOne($sql, ['id' => $ruleId]);
}
```

Use this stable ID generator for new rules:

```php
private function generateClientRuleId(): string
{
    return 'rule_' . bin2hex(random_bytes(12));
}
```

Use this duplicate-count SQL for enabled writes:

```php
$duplicate = $enabled ? $this->db->fetchOne(
    'SELECT id FROM client_rules
     WHERE service_scope_id = :service_scope_id
       AND active_duplicate_key = :active_duplicate_key
       AND tombstoned_at IS NULL
       AND id <> :id
     LIMIT 1',
    ['service_scope_id' => $serviceScopeId, 'active_duplicate_key' => $activeDuplicateKey, 'id' => $ruleId]
) : null;
```

Use this saved-count rule:

```php
$savedCount = (int) (($this->db->fetchOne(
    'SELECT COUNT(*) AS total FROM client_rules WHERE service_scope_id = :service_scope_id AND tombstoned_at IS NULL',
    ['service_scope_id' => $serviceScopeId]
)['total'] ?? 0));
$savedCountAfterWrite = $existing === null ? $savedCount + 1 : $savedCount;
```

For create/update/admin-save, build the validation context for `ClientRuleValidator::validateWrite()` with these keys: `service_active`, `sync_version_matches`, `destination_ip`, `destination_port`, `direction_exists`, `direction_allowed`, `direction_status`, `enabled`, `duplicate_enabled_exists`, `saved_count_after_write`, `max_rules`.

Use this concrete upsert body for both `createClientRuleForAgent()` and `updateClientRuleForAgent()` by adding a private shared method:

```php
private function saveClientRule(int $clientId, ?string $ruleId, array $payload, bool $adminWrite = false): array
{
    $existing = $ruleId !== null ? $this->loadClientRule($ruleId) : null;
    if ($ruleId !== null && $existing === null) {
        throw new ApiException('rule_not_found', 404);
    }
    $serviceScopeId = (string) ($payload['service_scope_id'] ?? ($existing['service_scope_id'] ?? ''));
    if (!$adminWrite && !$this->clientCanAccessScope($clientId, $serviceScopeId)) {
        throw new ApiException('service_not_active', 422);
    }
    $service = $this->routeDirections()->serviceForScope($serviceScopeId);
    if ($service === null || (string) ($service['status'] ?? '') !== 'active') {
        throw new ApiException('service_not_active', 422);
    }
    $entitlement = $this->routeDirections()->entitlementForScope($serviceScopeId);
    $directionId = $this->routeDirections()->normalizeDirectionId((string) ($payload['direction_id'] ?? ($existing['direction_id'] ?? '')));
    $direction = $this->routeDirections()->getDirection($directionId, false);
    $enabled = array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : ((int) ($existing['enabled'] ?? 1) === 1);
    $syncVersionMatches = $existing === null || (int) ($payload['sync_version'] ?? $existing['sync_version']) === (int) $existing['sync_version'];
    $ip = (string) ($payload['destination_ip'] ?? ($existing['destination_ip'] ?? ''));
    $port = $payload['destination_port'] ?? ($existing['destination_port'] ?? 0);
    $activeDuplicateKey = $this->clientRuleValidator()->activeDuplicateKey($this->clientRuleValidator()->normalizeDestinationIp($ip), $this->clientRuleValidator()->normalizeDestinationPort($port), $enabled);
    $duplicate = $enabled ? $this->db->fetchOne(
        'SELECT id FROM client_rules
         WHERE service_scope_id = :service_scope_id
           AND active_duplicate_key = :active_duplicate_key
           AND tombstoned_at IS NULL
           AND id <> :id
         LIMIT 1',
        ['service_scope_id' => $serviceScopeId, 'active_duplicate_key' => $activeDuplicateKey, 'id' => $ruleId ?? '']
    ) : null;
    $savedCount = (int) (($this->db->fetchOne(
        'SELECT COUNT(*) AS total FROM client_rules WHERE service_scope_id = :service_scope_id AND tombstoned_at IS NULL',
        ['service_scope_id' => $serviceScopeId]
    )['total'] ?? 0));
    $validated = $this->clientRuleValidator()->validateWrite([
        'service_active' => true,
        'sync_version_matches' => $syncVersionMatches,
        'destination_ip' => $ip,
        'destination_port' => $port,
        'direction_exists' => $direction !== null,
        'direction_allowed' => in_array($directionId, $entitlement['allowed_direction_ids'] ?? [], true),
        'direction_status' => (string) ($direction['status'] ?? 'missing'),
        'enabled' => $enabled,
        'duplicate_enabled_exists' => $duplicate !== null,
        'saved_count_after_write' => $existing === null ? $savedCount + 1 : $savedCount,
        'max_rules' => (int) ($entitlement['max_rules'] ?? 0),
    ]);
    $now = $this->now();
    $rule = [
        'id' => $ruleId ?? $this->generateClientRuleId(),
        'service_scope_id' => $serviceScopeId,
        'service_id' => (int) $service['id'],
        'subscription_id' => $service['whmcs_service_id'] ?? null,
        'destination_ip' => $validated['destination_ip'],
        'destination_port' => $validated['destination_port'],
        'protocol' => 'tcp_udp',
        'direction_id' => $directionId,
        'enabled' => $enabled ? 1 : 0,
        'effective_state' => $this->effectiveStateForClientRule(['enabled' => $enabled ? 1 : 0, 'direction_id' => $directionId], $entitlement, $direction),
        'sync_version' => $existing === null ? 1 : ((int) $existing['sync_version'] + 1),
        'active_duplicate_key' => $activeDuplicateKey,
        'notes' => (string) ($payload['notes'] ?? ($existing['notes'] ?? '')),
        'created_by_client_id' => $existing['created_by_client_id'] ?? ($adminWrite ? null : $clientId),
        'updated_by_client_id' => $adminWrite ? ($existing['updated_by_client_id'] ?? null) : $clientId,
        'updated_by_admin_id' => $adminWrite ? $clientId : null,
        'updated_at' => $now,
    ];
    if ($existing === null) {
        $this->db->insert('client_rules', $rule + ['created_at' => $now, 'tombstoned_at' => null, 'tombstoned_by' => null]);
    } else {
        $this->db->execute(
            'UPDATE client_rules SET destination_ip = :destination_ip, destination_port = :destination_port, protocol = :protocol, direction_id = :direction_id, enabled = :enabled, effective_state = :effective_state, sync_version = :sync_version, active_duplicate_key = :active_duplicate_key, notes = :notes, updated_by_client_id = :updated_by_client_id, updated_by_admin_id = :updated_by_admin_id, updated_at = :updated_at WHERE id = :id',
            $rule
        );
    }
    return $this->clientRulePayload($this->loadClientRule((string) $rule['id']) ?? $rule);
}

public function createClientRuleForAgent(int $clientId, array $payload): array
{
    return $this->saveClientRule($clientId, null, $payload, false);
}

public function updateClientRuleForAgent(int $clientId, string $ruleId, array $payload): array
{
    return $this->saveClientRule($clientId, $ruleId, $payload, false);
}

public function saveClientRuleFromAdmin(string $ruleId, array $payload): array
{
    return $this->saveClientRule(0, $ruleId, $payload, true);
}
```

Use these exact tombstone and admin-list methods:

```php
public function tombstoneClientRuleForAgent(int $clientId, string $ruleId): array
{
    $rule = $this->loadClientRule($ruleId);
    if ($rule === null || !$this->clientCanAccessScope($clientId, (string) $rule['service_scope_id'])) {
        throw new ApiException('rule_not_found', 404);
    }
    return $this->tombstoneClientRule($ruleId, 'client');
}

public function tombstoneClientRuleFromAdmin(string $ruleId, string $reason = ''): array
{
    return $this->tombstoneClientRule($ruleId, $reason !== '' ? $reason : 'admin');
}

private function tombstoneClientRule(string $ruleId, string $by): array
{
    $rule = $this->loadClientRule($ruleId);
    if ($rule === null) {
        throw new ApiException('rule_not_found', 404);
    }
    $this->db->execute(
        'UPDATE client_rules SET enabled = 0, active_duplicate_key = NULL, tombstoned_at = :tombstoned_at, tombstoned_by = :tombstoned_by, sync_version = sync_version + 1, updated_at = :updated_at WHERE id = :id',
        ['id' => $ruleId, 'tombstoned_at' => $this->now(), 'tombstoned_by' => $by, 'updated_at' => $this->now()]
    );
    return $this->clientRulePayload($this->loadClientRule($ruleId, true) ?? $rule);
}

public function listClientRulesForAdmin(array $filters = []): array
{
    $where = [];
    $params = [];
    if (!empty($filters['service_scope_id'])) {
        $where[] = 'service_scope_id = :service_scope_id';
        $params['service_scope_id'] = (string) $filters['service_scope_id'];
    }
    $sql = 'SELECT * FROM client_rules' . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY updated_at DESC, id DESC';
    return array_map(fn(array $row): array => $this->clientRulePayload($row), $this->db->fetchAll($sql, $params));
}
```

- [ ] **Step 5: Implement effective-state calculation**

Add a private helper in `ProvisioningService`:

```php
private function effectiveStateForClientRule(array $rule, array $entitlement, ?array $direction): string
{
    if ((int) ($rule['enabled'] ?? 0) !== 1) {
        return 'disabled';
    }
    if (!empty($entitlement['over_quota'])) {
        return 'over_quota';
    }
    if ($direction === null || !in_array((string) ($rule['direction_id'] ?? ''), $entitlement['allowed_direction_ids'] ?? [], true)) {
        return 'not_allowed_by_plan';
    }
    if ((string) ($direction['status'] ?? '') !== 'available') {
        return 'unavailable';
    }
    $binding = $this->routeDirections()->configPullForClient(0, ['route_direction_rules' => true, 'exact_destination_rules' => true], []);
    return 'active';
}
```

Then replace the final two lines with a local realization check rather than using client pull with `clientId = 0`:

```php
$realization = $this->db->fetchOne(
    'SELECT * FROM direction_realizations WHERE direction_id = :direction_id AND is_primary = 1 LIMIT 1',
    ['direction_id' => (string) ($rule['direction_id'] ?? '')]
);
if ($realization === null || (string) ($realization['binding_state'] ?? '') !== 'ready' || (int) ($realization['tcp_ready'] ?? 0) !== 1 || (int) ($realization['udp_ready'] ?? 0) !== 1) {
    return 'runtime_missing';
}
return 'active';
```

- [ ] **Step 6: Run entitlement/CRUD tests**

Run:

```bash
php master/tests/plan_direction_entitlements_test.php
php master/tests/client_rule_validator_test.php
php master/tests/route_direction_service_test.php
```

Expected: each command prints `ok`.

- [ ] **Step 7: Lint and commit**

Run: `php -l master/src/Support/ProvisioningService.php`

Expected: `No syntax errors detected in master/src/Support/ProvisioningService.php`.

Commit:

```bash
git add master/src/Support/ProvisioningService.php master/tests/plan_direction_entitlements_test.php
git commit -m "feat(master): enforce route direction client rule entitlements" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Config pull assembly, helper-only filtering, and capability gates

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Test: `master/tests/client_config_pull_route_direction_test.php`

- [ ] **Step 1: Write the failing config-pull test**

Create `master/tests/client_config_pull_route_direction_test.php` with source checks and a focused sqlite behavior check:

```php
use Leikwan\Master\Support\ProvisioningService;

[$config, $db] = makeTestDb('client-config-pull-route-direction');
installRouteRuleTestSchema($db);
$now = gmdate('Y-m-d H:i:s');
$db->insert('service_plans', ['name' => 'Pro', 'product_code' => 'pro', 'max_client_rules_per_service_scope' => 5, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
$db->insert('services', ['user_id' => 77, 'plan_id' => 1, 'name' => 'Service A', 'whmcs_service_id' => 7701, 'status' => 'active', 'client_token' => 'tok', 'created_at' => $now, 'updated_at' => $now]);
$db->insert('clients', ['owner_user_id' => 77, 'group_id' => null, 'agent_token' => 'agent', 'agent_config_json' => json_encode(['supports_route_direction_rules' => true, 'supports_exact_destination_rules' => true]), 'name' => 'Client A', 'display_host' => 'client-a', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
$db->insert('forwards', ['service_id' => 1, 'listen_port' => 11001, 'line_client_id' => 1, 'line_client_group_id' => null, 'enabled' => 1, 'route_rule_helper_only' => 1, 'created_at' => $now, 'updated_at' => $now]);
$provisioning = new ProvisioningService($config, $db);
$provisioning->saveRouteDirection(['id' => 'dir_sh_jp_1', 'label' => '沪日1', 'status' => 'available', 'realization' => ['binding_state' => 'ready', 'forward_id' => 1, 'local_tcp_port' => 11001, 'local_udp_port' => 11001, 'tcp_ready' => true, 'udp_ready' => true, 'helper_only' => true]]);
$provisioning->routeDirections()->savePlanEntitlements(1, ['dir_sh_jp_1'], 5);
$provisioning->createClientRuleForAgent(1, ['service_scope_id' => 'svc_scope_1', 'destination_ip' => '93.184.216.34', 'destination_port' => 443, 'direction_id' => 'dir_sh_jp_1', 'enabled' => true]);

$helperForward = ['forward_id' => 1, 'listen_port' => 11001, 'route_rule_helper_only' => true];
$legacyFiltered = $provisioning->filterRouteRuleHelperForwardsForPull([$helperForward], ['route_direction_rules' => false, 'exact_destination_rules' => false]);
if ($legacyFiltered !== []) {
    fail('legacy clients must not receive helper-only forwards');
}
$upgradedFiltered = $provisioning->filterRouteRuleHelperForwardsForPull([$helperForward], ['route_direction_rules' => true, 'exact_destination_rules' => true]);
if (($upgradedFiltered[0]['selectable'] ?? true) !== false) {
    fail('upgraded helper forward must be marked non-selectable');
}
$payload = $provisioning->routeDirectionConfigPullForClient(1, ['route_direction_rules' => true, 'exact_destination_rules' => true], $upgradedFiltered);
foreach (['route_directions', 'route_rule_entitlements', 'client_rules', 'direction_runtime_bindings', 'capabilities'] as $key) {
    if (!isset($payload[$key])) {
        fail('routeDirectionConfigPullForClient missing ' . $key);
    }
}
$binding = $payload['direction_runtime_bindings'][0] ?? null;
if (!is_array($binding)) {
    fail('upgraded pull missing first ready direction runtime binding');
}
foreach (['service_scope_id', 'client_runtime_binding_id', 'direction_id', 'binding_state', 'runtime_ref', 'local_endpoint', 'tcp_ready', 'udp_ready'] as $requiredBindingKey) {
    if (!array_key_exists($requiredBindingKey, $binding)) {
        fail('direction_runtime_bindings[] missing required key ' . $requiredBindingKey);
    }
}
$endpoint = is_array($binding['local_endpoint'] ?? null) ? $binding['local_endpoint'] : [];
$endpointKeys = array_keys($endpoint);
sort($endpointKeys);
if ($endpointKeys !== ['host', 'tcp_port', 'udp_port']) {
    fail('ready binding local_endpoint must contain exactly host, tcp_port, udp_port');
}
if (($endpoint['host'] ?? '') !== '127.0.0.1') {
    fail('ready binding local_endpoint.host must be 127.0.0.1');
}
if ((int) ($endpoint['tcp_port'] ?? 0) !== 11001 || (int) ($endpoint['udp_port'] ?? 0) !== 11001) {
    fail('ready binding local_endpoint ports must include tcp_port and udp_port');
}
if (array_key_exists('protocol', $endpoint)) {
    fail('ready binding local_endpoint must not contain protocol');
}
if (($binding['client_runtime_binding_id'] ?? '') !== 'svc_scope_1:dir_sh_jp_1' || ($binding['service_scope_id'] ?? '') !== 'svc_scope_1') {
    fail('ready binding must include stable client_runtime_binding_id and service_scope_id');
}
if (($binding['tcp_ready'] ?? null) !== true || ($binding['udp_ready'] ?? null) !== true) {
    fail('ready binding tcp_ready and udp_ready must be true');
}
$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
foreach (['local_tcp_port', 'local_udp_port'] as $legacyPortField) {
    if (str_contains((string) $json, '"' . $legacyPortField . '"')) {
        fail('upgraded pull must not expose legacy top-level port field inside direction_runtime_bindings: ' . $legacyPortField);
    }
}
$legacyPayload = $provisioning->routeDirectionConfigPullForClient(1, ['route_direction_rules' => false, 'exact_destination_rules' => false], []);
if ($legacyPayload !== [] || array_key_exists('direction_runtime_bindings', $legacyPayload)) {
    fail('legacy/no-capability clients must receive no direction_runtime_bindings field at all');
}
foreach (['LeiGod', 'current_mode', 'cf_mode', 'forward_combination', 'cf_location_group', 'chain', 'slave', 'sidecar', 'listener', 'runtime mode'] as $forbidden) {
    if (str_contains((string) $json, $forbidden)) {
        fail('route-rule pull payload leaked forbidden term ' . $forbidden);
    }
}

$controllerSource = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php') ?: '';
foreach ([
    'supports_route_direction_rules',
    'supports_exact_destination_rules',
    '$routeRuleCapabilities = $this->provisioning->clientRouteRuleCapabilities($client);',
    '$forwards = $this->provisioning->filterRouteRuleHelperForwardsForPull($forwards, $routeRuleCapabilities);',
    '$routeRulePayload = $this->provisioning->routeDirectionConfigPullForClient((int) $client[\'id\'], $routeRuleCapabilities, $forwards);',
    'array_merge($responsePayload, $routeRulePayload)',
] as $snippet) {
    if (!str_contains($controllerSource, $snippet)) {
        fail('ClientAgentController missing config-pull snippet ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the config-pull test and verify it fails**

Run: `php master/tests/client_config_pull_route_direction_test.php`

Expected: FAIL with `Call to undefined method Leikwan\Master\Support\ProvisioningService::filterRouteRuleHelperForwardsForPull()`.

- [ ] **Step 3: Add ProvisioningService capability and pull wrappers**

Add these methods to `ProvisioningService`:

```php
public function clientRouteRuleCapabilities(array $client): array
{
    $config = $this->decodeJsonAssoc($client['agent_config_json'] ?? null);
    return [
        'route_direction_rules' => !empty($config['supports_route_direction_rules']),
        'exact_destination_rules' => !empty($config['supports_exact_destination_rules']),
    ];
}

public function routeDirectionConfigPullForClient(int $clientId, array $capabilities, array $forwards): array
{
    return $this->routeDirections()->configPullForClient($clientId, $capabilities, $forwards);
}

public function filterRouteRuleHelperForwardsForPull(array $forwards, array $capabilities): array
{
    $upgraded = !empty($capabilities['route_direction_rules']) && !empty($capabilities['exact_destination_rules']);
    $filtered = [];
    foreach ($forwards as $forward) {
        if (!is_array($forward)) {
            continue;
        }
        $helperOnly = !empty($forward['route_rule_helper_only']);
        if ($helperOnly && !$upgraded) {
            continue;
        }
        if ($helperOnly) {
            $forward['selectable'] = false;
            $forward['route_rule_helper_only'] = true;
        }
        $filtered[] = $forward;
    }
    return $filtered;
}
```

In `assignedForwardConfigsForClient()`, after `$config = $this->buildClientConfig($forwardId);`, add:

```php
$config['route_rule_helper_only'] = (int) ($row['route_rule_helper_only'] ?? 0) === 1;
```

- [ ] **Step 4: Update ClientAgentController heartbeat capability recording**

In `ClientAgentController::heartbeat()`, add capability input keys to the `mergeRuntimeState()` call:

```php
'supports_route_direction_rules' => $request->input('supports_route_direction_rules'),
'supports_exact_destination_rules' => $request->input('supports_exact_destination_rules'),
```

In `mergeRuntimeState()`, change the boolean key list to:

```php
foreach (['supports_mptcp', 'supports_route_direction_rules', 'supports_exact_destination_rules'] as $boolKey) {
```

- [ ] **Step 5: Update ClientAgentController config pull assembly**

In `ClientAgentController::pullConfigs()`, after `$forwards` is assigned, add this exact snippet. This placement is required so legacy clients first lose helper-only forwards and then receive no route-rule extension fields:

```php
$routeRuleCapabilities = $this->provisioning->clientRouteRuleCapabilities($client);
$forwards = $this->provisioning->filterRouteRuleHelperForwardsForPull($forwards, $routeRuleCapabilities);
$routeRulePayload = $this->provisioning->routeDirectionConfigPullForClient((int) $client['id'], $routeRuleCapabilities, $forwards);
```

Do not add fallback `direction_runtime_bindings => []` in `ClientAgentController`; no-capability clients must receive no `direction_runtime_bindings` field at all because `routeDirectionConfigPullForClient()` returns `[]` when either required capability is false.

Replace the direct `return $this->json([...]);` with:

```php
$responsePayload = [
    'ok' => true,
    'client' => [
        'id' => (int) $client['id'],
        'name' => (string) $client['name'],
        'display_host' => (string) $client['display_host'],
        'group_id' => $client['group_id'] !== null ? (int) $client['group_id'] : null,
        'diag_request_token' => (string) ($client['last_diag_request_token'] ?? ''),
        'diag_status' => (string) ($client['last_diag_status'] ?? ''),
        'agent_config_json' => $agentConfig,
        'agent_update_request_token' => $updateRequestToken,
        'agent_update_requested_at' => (string) ($client['agent_update_requested_at'] ?? ''),
        'desired_agent_version' => (string) ($clientAgent['version'] ?? ''),
        'desired_agent_sha256' => (string) ($clientAgent['sha256'] ?? ''),
        'desired_installer_version' => (string) ($clientAgentInstaller['version'] ?? ''),
        'desired_installer_sha256' => (string) ($clientAgentInstaller['sha256'] ?? ''),
        'desired_bond_version' => (string) ($bondBinary['version'] ?? ''),
        'desired_bond_sha256' => $desiredBondSha256,
        'desired_mptcp_runtime_version' => (string) ($mptcpRuntime['version'] ?? ''),
        'desired_mptcp_runtime_sha256' => (string) ($mptcpRuntime['sha256'] ?? ''),
        'desired_cfwg_watchdog_version' => (string) ($cfwgLatencyWatchdog['version'] ?? ''),
        'desired_cfwg_watchdog_sha256' => (string) ($cfwgLatencyWatchdog['sha256'] ?? ''),
        'online_device_limit' => max(1, (int) ($client['online_device_limit'] ?? 1)),
        'active_online_devices' => $deviceSessionService->activeCountForClient((int) $client['id']),
        'current_device_id' => $deviceId,
    ],
    'forwards' => $forwards,
];

return $this->json(array_merge($responsePayload, $routeRulePayload));
```

- [ ] **Step 6: Run config-pull and regression tests**

Run:

```bash
php master/tests/client_config_pull_route_direction_test.php
php master/tests/client_heartbeat_republish_gate_regression_test.php
php master/tests/plan_direction_entitlements_test.php
```

Expected: each command prints `ok`.

- [ ] **Step 7: Lint and commit**

Run:

```bash
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/ClientAgentController.php
```

Expected: both commands print `No syntax errors detected`.

Commit:

```bash
git add master/src/Support/ProvisioningService.php master/src/Controllers/ClientAgentController.php master/tests/client_config_pull_route_direction_test.php
git commit -m "feat(master): add route-rule config pull payload" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Client Rule write API surface

**Files:**
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/src/Bootstrap.php`
- Test: `master/tests/client_rule_write_api_surface_test.php`

- [ ] **Step 1: Write the failing write-API surface test**

Create `master/tests/client_rule_write_api_surface_test.php` with:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php') ?: '';
$controller = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php') ?: '';
foreach ([
    "POST', '/api/client/client-rules'",
    "PUT', '/api/client/client-rules/{id}'",
    "DELETE', '/api/client/client-rules/{id}'",
    'createClientRule(',
    'updateClientRule(',
    'deleteClientRule(',
    'clientRuleErrorResponse(',
    'createClientRuleForAgent(',
    'updateClientRuleForAgent(',
    'tombstoneClientRuleForAgent(',
] as $snippet) {
    if (!str_contains($bootstrap . $controller, $snippet)) {
        fail('missing client rule API snippet ' . $snippet);
    }
}
foreach (['duplicate_destination', 'rule_quota_exceeded', 'direction_not_allowed', 'direction_unavailable', 'invalid_destination_ip', 'invalid_destination_port', 'unsupported_destination_address_family', 'destination_blocked_by_policy', 'service_not_active', 'rule_not_found', 'version_conflict'] as $errorCode) {
    if (!str_contains($controller, $errorCode)) {
        fail('missing stable client rule error code ' . $errorCode);
    }
}
foreach (['forward_combination', 'cf_location_group', 'current_mode', 'cf_mode'] as $forbidden) {
    if (preg_match('/clientRuleErrorResponse[\s\S]*' . preg_quote($forbidden, '/') . '/', $controller) === 1) {
        fail('client rule error response leaks forbidden term ' . $forbidden);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the write-API test and verify it fails**

Run: `php master/tests/client_rule_write_api_surface_test.php`

Expected: FAIL with `missing client rule API snippet POST', '/api/client/client-rules'`.

- [ ] **Step 3: Register client rule API routes**

In `master/src/Bootstrap.php`, after the config pull route, add:

```php
$this->router->add('POST', '/api/client/client-rules', [$clientAgent, 'createClientRule']);
$this->router->add('PUT', '/api/client/client-rules/{id}', [$clientAgent, 'updateClientRule']);
$this->router->add('DELETE', '/api/client/client-rules/{id}', [$clientAgent, 'deleteClientRule']);
```

- [ ] **Step 4: Add ClientAgentController write handlers**

Add these methods to `ClientAgentController`:

```php
public function createClientRule(Request $request, array $params): Response
{
    try {
        $client = Auth::requireClientAgent($request, $this->db);
        $rule = $this->provisioning->createClientRuleForAgent((int) $client['id'], $request->body());
        return $this->json(['ok' => true, 'client_rule' => $rule], 201);
    } catch (\Throwable $throwable) {
        return $this->clientRuleErrorResponse($throwable);
    }
}

public function updateClientRule(Request $request, array $params): Response
{
    try {
        $client = Auth::requireClientAgent($request, $this->db);
        $ruleId = trim((string) ($params['id'] ?? ''));
        $rule = $this->provisioning->updateClientRuleForAgent((int) $client['id'], $ruleId, $request->body());
        return $this->json(['ok' => true, 'client_rule' => $rule]);
    } catch (\Throwable $throwable) {
        return $this->clientRuleErrorResponse($throwable);
    }
}

public function deleteClientRule(Request $request, array $params): Response
{
    try {
        $client = Auth::requireClientAgent($request, $this->db);
        $ruleId = trim((string) ($params['id'] ?? ''));
        $rule = $this->provisioning->tombstoneClientRuleForAgent((int) $client['id'], $ruleId);
        return $this->json(['ok' => true, 'client_rule' => $rule]);
    } catch (\Throwable $throwable) {
        return $this->clientRuleErrorResponse($throwable);
    }
}
```

Add this stable error response method:

```php
private function clientRuleErrorResponse(\Throwable $throwable): Response
{
    $stable = [
        'duplicate_destination' => 409,
        'rule_quota_exceeded' => 422,
        'direction_not_allowed' => 422,
        'direction_unavailable' => 422,
        'invalid_destination_ip' => 422,
        'invalid_destination_port' => 422,
        'unsupported_destination_address_family' => 422,
        'destination_blocked_by_policy' => 422,
        'service_not_active' => 422,
        'rule_not_found' => 404,
        'version_conflict' => 409,
        'client_agent_auth_failed' => 401,
    ];
    $code = $throwable instanceof \Leikwan\Master\Support\ApiException ? $throwable->getMessage() : 'client_rule_write_failed';
    $status = $stable[$code] ?? 500;
    return $this->json([
        'ok' => false,
        'error' => $code,
        'message' => $this->clientRuleUserMessage($code),
    ], $status);
}

private function clientRuleUserMessage(string $code): string
{
    return match ($code) {
        'duplicate_destination' => '该目标已存在规则',
        'rule_quota_exceeded' => '规则数量已达到当前套餐上限',
        'direction_not_allowed' => '当前套餐不支持该方向',
        'direction_unavailable' => '当前方向暂不可用',
        'invalid_destination_ip', 'invalid_destination_port', 'unsupported_destination_address_family', 'destination_blocked_by_policy' => '目标地址不可用',
        'service_not_active' => '服务当前不可用',
        'rule_not_found' => '规则不存在',
        'version_conflict' => '规则已更新，请刷新后重试',
        default => '规则保存失败',
    };
}
```

- [ ] **Step 5: Run write-API tests**

Run:

```bash
php master/tests/client_rule_write_api_surface_test.php
php master/tests/plan_direction_entitlements_test.php
```

Expected: each command prints `ok`.

- [ ] **Step 6: Lint and commit**

Run:

```bash
php -l master/src/Controllers/ClientAgentController.php
php -l master/src/Bootstrap.php
```

Expected: both commands print `No syntax errors detected`.

Commit:

```bash
git add master/src/Controllers/ClientAgentController.php master/src/Bootstrap.php master/tests/client_rule_write_api_surface_test.php
git commit -m "feat(master): add client rule write APIs" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Admin Route Directions, Client Rules, Plans UI, and legacy 410 routes

**Files:**
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Controllers/BaseController.php`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/tests/admin_forward_combination_page_test.php`
- Test: `master/tests/admin_route_direction_routes_test.php`
- Test: `master/tests/admin_client_rules_test.php`
- Test: `master/tests/legacy_routing_pages_gone_test.php`

- [ ] **Step 1: Write the failing admin route tests**

Create `master/tests/admin_route_direction_routes_test.php` with:

```php
<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php') ?: '';
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php') ?: '';
$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php') ?: '';
foreach ([
    "GET', '/admin/route-directions'",
    "POST', '/admin/route-directions'",
    'routeDirectionsPage(',
    'saveRouteDirection(',
    'Route Directions',
    'Direction ID',
    'User Label',
    'Status',
    'Internal Mapping',
    'allowed_route_direction_ids[]',
    'max_client_rules_per_service_scope',
] as $snippet) {
    if (!str_contains($bootstrap . $admin . $base, $snippet)) {
        fail('missing route direction admin snippet ' . $snippet);
    }
}
foreach (['/admin/cf-location-groups', '/admin/forward-combinations', '/admin/forward-routes', '/admin/chains'] as $legacyHref) {
    if (str_contains($base, "'href' => '" . $legacyHref . "'")) {
        fail('legacy routing product link remains in admin navigation: ' . $legacyHref);
    }
}
echo "ok\n";
```

Create `master/tests/admin_client_rules_test.php` with:

```php
<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php') ?: '';
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php') ?: '';
$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php') ?: '';
foreach ([
    "GET', '/admin/client-rules'",
    "POST', '/admin/client-rules/{id}'",
    "POST', '/admin/client-rules/{id}/tombstone'",
    'clientRulesPage(',
    'saveClientRule(',
    'tombstoneClientRule(',
    'Client Rules',
    'service_scope_id',
    'Exact Destination',
    'Route Direction',
    'Sync Version',
    'saveClientRuleFromAdmin(',
    'tombstoneClientRuleFromAdmin(',
] as $snippet) {
    if (!str_contains($bootstrap . $admin . $base, $snippet)) {
        fail('missing admin client rule snippet ' . $snippet);
    }
}
foreach (['LeiGod', 'current_mode', 'cf_mode', 'forward_combination', 'cf_location_group', 'chain', 'listener', 'runtime mode'] as $forbidden) {
    $surfacePattern = '/Client Rules[\s\S]{0,9000}' . preg_quote($forbidden, '/') . '/';
    if (preg_match($surfacePattern, $admin) === 1) {
        fail('Client Rules admin surface leaks forbidden term ' . $forbidden);
    }
}
echo "ok\n";
```

Create `master/tests/legacy_routing_pages_gone_test.php` with:

```php
<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php') ?: '';
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php') ?: '';
foreach ([
    "GET', '/admin/cf-location-groups', [\$admin, 'legacyRoutingGone']",
    "POST', '/admin/cf-location-groups', [\$admin, 'legacyRoutingGone']",
    "GET', '/admin/forward-combinations', [\$admin, 'legacyRoutingGone']",
    "POST', '/admin/forward-combinations', [\$admin, 'legacyRoutingGone']",
    "GET', '/admin/forward-routes', [\$admin, 'legacyRoutingGone']",
    "POST', '/admin/forward-routes', [\$admin, 'legacyRoutingGone']",
    "POST', '/admin/forward-combinations/{id}/donations/{donationId}/revoke', [\$admin, 'legacyRoutingGone']",
    "GET', '/admin/chains', [\$admin, 'legacyRoutingGone']",
    "POST', '/admin/chains', [\$admin, 'legacyRoutingGone']",
    'Response::html(410',
    'legacy_routing_surface_gone',
] as $snippet) {
    if (!str_contains($bootstrap . $admin, $snippet)) {
        fail('legacy route is not gone: ' . $snippet);
    }
}
echo "ok\n";
```

- [ ] **Step 2: Invert the existing admin forward-combination test**

Replace `master/tests/admin_forward_combination_page_test.php` with:

```php
<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php') ?: '';
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php') ?: '';
foreach ([
    "GET', '/admin/forward-combinations', [\$admin, 'legacyRoutingGone']",
    "POST', '/admin/forward-combinations', [\$admin, 'legacyRoutingGone']",
    'legacy_routing_surface_gone',
] as $snippet) {
    if (!str_contains($bootstrap . $admin, $snippet)) {
        fail('forward combination admin page must be gone: ' . $snippet);
    }
}
foreach (['Allowed Forward Combinations', 'name="backend_mode"', 'name="cf_location_group_id"', 'value="current_mode"', 'value="cf_mode"'] as $forbidden) {
    if (preg_match('/Route Directions|Client Rules|Plans/', $admin) === 1 && str_contains($admin, $forbidden) && !str_contains($admin, 'legacyRoutingGone')) {
        fail('legacy forward-combination product snippet remains exposed: ' . $forbidden);
    }
}
echo "ok\n";
```

- [ ] **Step 3: Run admin tests and verify they fail**

Run:

```bash
php master/tests/admin_route_direction_routes_test.php
php master/tests/admin_client_rules_test.php
php master/tests/legacy_routing_pages_gone_test.php
php master/tests/admin_forward_combination_page_test.php
```

Expected: the first three fail with missing route/page snippets; the inverted forward-combination test fails until routes are changed to `legacyRoutingGone`.

- [ ] **Step 4: Register admin routes and legacy 410 routes**

In `master/src/Bootstrap.php`, replace legacy admin route registrations with:

```php
$this->router->add('GET', '/admin/route-directions', [$admin, 'routeDirectionsPage']);
$this->router->add('POST', '/admin/route-directions', [$admin, 'saveRouteDirection']);
$this->router->add('GET', '/admin/client-rules', [$admin, 'clientRulesPage']);
$this->router->add('POST', '/admin/client-rules/{id}', [$admin, 'saveClientRule']);
$this->router->add('POST', '/admin/client-rules/{id}/tombstone', [$admin, 'tombstoneClientRule']);

$this->router->add('GET', '/admin/cf-location-groups', [$admin, 'legacyRoutingGone']);
$this->router->add('POST', '/admin/cf-location-groups', [$admin, 'legacyRoutingGone']);
$this->router->add('GET', '/admin/forward-combinations', [$admin, 'legacyRoutingGone']);
$this->router->add('POST', '/admin/forward-combinations', [$admin, 'legacyRoutingGone']);
$this->router->add('GET', '/admin/forward-routes', [$admin, 'legacyRoutingGone']);
$this->router->add('POST', '/admin/forward-routes', [$admin, 'legacyRoutingGone']);
$this->router->add('POST', '/admin/forward-combinations/{id}/donations/{donationId}/revoke', [$admin, 'legacyRoutingGone']);
$this->router->add('GET', '/admin/chains', [$admin, 'legacyRoutingGone']);
$this->router->add('POST', '/admin/chains', [$admin, 'legacyRoutingGone']);
```

- [ ] **Step 5: Update BaseController admin navigation**

In `BaseController::adminNavigationItems()`, add:

```php
['href' => '/admin/route-directions', 'label' => 'Route Directions', 'active' => $active === 'route-directions'],
['href' => '/admin/client-rules', 'label' => 'Client Rules', 'active' => $active === 'client-rules'],
```

Remove the entries for `/admin/cf-location-groups`, `/admin/forward-combinations`, and `/admin/chains`.

- [ ] **Step 6: Add AdminController Route Directions page**

Add `routeDirectionsPage()` and `saveRouteDirection()` to `AdminController`. The page must contain product identity fields plus an operator-only internal mapping section:

```php
public function routeDirectionsPage(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }
    $directions = $this->provisioning?->listRouteDirections(true) ?? [];
    $editId = trim((string) $request->input('edit', ''));
    $editing = null;
    foreach ($directions as $direction) {
        if ((string) ($direction['id'] ?? '') === $editId) {
            $editing = $direction;
            break;
        }
    }
    $rows = '';
    foreach ($directions as $direction) {
        $rows .= '<tr><td>' . $this->escape((string) $direction['id']) . '</td><td>' . $this->escape((string) $direction['label']) . '</td><td>' . $this->escape((string) $direction['status']) . '</td><td>' . (int) ($direction['usage']['rules'] ?? 0) . '</td><td><a class="button ghost" href="/admin/route-directions?edit=' . $this->escape((string) $direction['id']) . '">Edit</a></td></tr>';
    }
    $realization = is_array($editing['realization'] ?? null) ? $editing['realization'] : [];
    $csrf = $this->escape(SessionAuth::csrfToken());
    $body = '<div class="hero"><div><h2>Route Directions</h2><p class="muted">Create stable product directions and maintain their operator-only internal mapping.</p></div></div>'
        . '<div class="grid two"><section class="card"><h3>' . ($editing ? 'Edit Route Direction' : 'Create Route Direction') . '</h3>'
        . '<form method="post" action="/admin/route-directions"><input type="hidden" name="csrf_token" value="' . $csrf . '">'
        . '<div class="form-grid">'
        . '<div class="field"><label>Direction ID</label><input name="id" value="' . $this->escape((string) ($editing['id'] ?? '')) . '" required></div>'
        . '<div class="field"><label>User Label</label><input name="label" value="' . $this->escape((string) ($editing['label'] ?? '')) . '" required></div>'
        . '<div class="field"><label>Status</label><select name="status"><option value="available">available</option><option value="unavailable">unavailable</option><option value="maintenance">maintenance</option><option value="retired">retired</option></select></div>'
        . '<div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="' . (int) ($editing['sort_order'] ?? 100) . '"></div>'
        . '</div><div class="field"><label>Admin Notes</label><textarea name="admin_notes">' . $this->escape((string) ($editing['admin_notes'] ?? '')) . '</textarea></div>'
        . '<h4>Internal Mapping</h4><p class="muted">Operator-only fields used to bind this product direction to the current runtime backing.</p>'
        . '<div class="form-grid">'
        . '<div class="field"><label>Runtime Forward ID</label><input type="number" name="forward_id" value="' . (int) ($realization['forward_id'] ?? 0) . '"></div>'
        . '<div class="field"><label>Local Endpoint Host</label><input name="local_endpoint_host" value="' . $this->escape((string) ($realization['local_endpoint_host'] ?? '127.0.0.1')) . '"></div>'
        . '<div class="field"><label>TCP Port</label><input type="number" name="local_tcp_port" value="' . (int) ($realization['local_tcp_port'] ?? 0) . '"></div>'
        . '<div class="field"><label>UDP Port</label><input type="number" name="local_udp_port" value="' . (int) ($realization['local_udp_port'] ?? 0) . '"></div>'
        . '</div><div class="actions"><button class="button" type="submit">Save Route Direction</button></div></form></section>'
        . '<section class="card"><h3>Route Direction List</h3><div class="table-wrap"><table><thead><tr><th>ID</th><th>User Label</th><th>Status</th><th>Rules</th><th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section></div>';
    return $this->adminPage('Route Directions', $body, $viewer, 'route-directions');
}

public function saveRouteDirection(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }
    try {
        SessionAuth::validateCsrf($request);
        $this->provisioning?->saveRouteDirection([
            'id' => $request->input('id'),
            'label' => $request->input('label'),
            'status' => $request->input('status', 'available'),
            'admin_notes' => $request->input('admin_notes', ''),
            'sort_order' => $request->input('sort_order', 100),
            'realization' => [
                'binding_state' => ((int) $request->input('forward_id', 0) > 0 ? 'ready' : 'missing'),
                'forward_id' => $request->input('forward_id', 0),
                'local_endpoint_host' => $request->input('local_endpoint_host', '127.0.0.1'),
                'local_tcp_port' => $request->input('local_tcp_port', 0),
                'local_udp_port' => $request->input('local_udp_port', 0),
                'tcp_ready' => (int) $request->input('local_tcp_port', 0) > 0,
                'udp_ready' => (int) $request->input('local_udp_port', 0) > 0,
                'helper_only' => true,
            ],
        ]);
        SessionAuth::flash('success', 'Route Direction saved.');
    } catch (\Throwable $throwable) {
        SessionAuth::flash('error', 'Failed to save Route Direction: ' . $this->displayThrowableMessage($throwable));
    }
    return Response::redirect('/admin/route-directions');
}
```

- [ ] **Step 7: Add AdminController Client Rules page**

Add `clientRulesPage()`, `saveClientRule()`, and `tombstoneClientRule()` to `AdminController` with this exact implementation. The page shows `service_scope_id`, user/service context, exact destination, Route Direction label, enabled state, effective state, and sync version. It intentionally does not show implementation names.

```php
public function clientRulesPage(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }
    $scopeFilter = trim((string) $request->input('service_scope_id', ''));
    $rules = $this->provisioning?->listClientRulesForAdmin($scopeFilter !== '' ? ['service_scope_id' => $scopeFilter] : []) ?? [];
    $directions = $this->provisioning?->listRouteDirections(false) ?? [];
    $directionLabels = [];
    foreach ($directions as $direction) {
        $directionLabels[(string) ($direction['id'] ?? '')] = (string) ($direction['label'] ?? $direction['id'] ?? '');
    }
    $csrf = $this->escape(SessionAuth::csrfToken());
    $rows = '';
    foreach ($rules as $rule) {
        $directionId = (string) ($rule['direction_id'] ?? '');
        $directionOptions = '';
        foreach ($directions as $direction) {
            $id = (string) ($direction['id'] ?? '');
            $directionOptions .= '<option value="' . $this->escape($id) . '"' . ($id === $directionId ? ' selected' : '') . '>' . $this->escape((string) ($direction['label'] ?? $id)) . '</option>';
        }
        $enabled = !empty($rule['enabled']);
        $rows .= '<tr>'
            . '<td><code>' . $this->escape((string) $rule['id']) . '</code><div class="muted">' . $this->escape((string) ($rule['updated_at'] ?? '')) . '</div></td>'
            . '<td><code>' . $this->escape((string) $rule['service_scope_id']) . '</code><div class="muted">Service #' . (int) ($rule['service_id'] ?? 0) . '</div></td>'
            . '<td><form method="post" action="/admin/client-rules/' . $this->escape((string) $rule['id']) . '"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="sync_version" value="' . (int) ($rule['sync_version'] ?? 1) . '"><input name="destination_ip" value="' . $this->escape((string) $rule['destination_ip']) . '" style="width:130px;">:<input type="number" name="destination_port" value="' . (int) ($rule['destination_port'] ?? 0) . '" style="width:86px;"></td>'
            . '<td><select name="direction_id">' . $directionOptions . '</select><div class="muted">' . $this->escape($directionLabels[$directionId] ?? $directionId) . '</div></td>'
            . '<td><label><input type="checkbox" name="enabled" value="1"' . ($enabled ? ' checked' : '') . '> Enabled</label><div class="muted">' . $this->escape((string) ($rule['effective_state'] ?? '')) . '</div></td>'
            . '<td>' . (int) ($rule['sync_version'] ?? 1) . '</td>'
            . '<td><textarea name="notes" rows="2">' . $this->escape((string) ($rule['notes'] ?? '')) . '</textarea></td>'
            . '<td><button class="button" type="submit">Save</button></form><form class="inline" method="post" action="/admin/client-rules/' . $this->escape((string) $rule['id']) . '/tombstone"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="reason" value="admin delete"><button class="button danger" type="submit">Tombstone</button></form></td>'
            . '</tr>';
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="8" class="muted">No Client Rules match this filter.</td></tr>';
    }
    $body = '<div class="hero"><div><h2>Client Rules</h2><p class="muted">Review and support-edit exact destination rules synchronized by upgraded clients.</p></div></div>'
        . '<section class="card"><form method="get" action="/admin/client-rules" class="actions"><label>service_scope_id <input name="service_scope_id" value="' . $this->escape($scopeFilter) . '" placeholder="svc_scope_1"></label><button class="button ghost" type="submit">Filter</button></form></section>'
        . '<section class="card"><div class="table-wrap"><table><thead><tr><th>Rule</th><th>Service Scope</th><th>Exact Destination</th><th>Route Direction</th><th>State</th><th>Sync Version</th><th>Notes</th><th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
    return $this->adminPage('Client Rules', $body, $viewer, 'client-rules');
}

public function saveClientRule(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }
    try {
        SessionAuth::validateCsrf($request);
        $this->provisioning?->saveClientRuleFromAdmin((string) ($params['id'] ?? ''), [
            'sync_version' => $request->input('sync_version'),
            'destination_ip' => $request->input('destination_ip'),
            'destination_port' => $request->input('destination_port'),
            'direction_id' => $request->input('direction_id'),
            'enabled' => $request->input('enabled', '0') === '1',
            'notes' => $request->input('notes', ''),
        ]);
        SessionAuth::flash('success', 'Client Rule saved.');
    } catch (\Throwable $throwable) {
        SessionAuth::flash('error', 'Failed to save Client Rule: ' . $this->displayThrowableMessage($throwable));
    }
    return Response::redirect('/admin/client-rules');
}

public function tombstoneClientRule(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }
    try {
        SessionAuth::validateCsrf($request);
        $this->provisioning?->tombstoneClientRuleFromAdmin((string) ($params['id'] ?? ''), (string) $request->input('reason', 'admin delete'));
        SessionAuth::flash('success', 'Client Rule tombstoned.');
    } catch (\Throwable $throwable) {
        SessionAuth::flash('error', 'Failed to tombstone Client Rule: ' . $this->displayThrowableMessage($throwable));
    }
    return Response::redirect('/admin/client-rules');
}
```

- [ ] **Step 8: Replace Plans UI entitlements**

In `AdminController::plansPage()`, replace the legacy route-package entitlement query and form fragment with Route Direction entitlements.

Replace this legacy query:

```php
$forwardCombinations = $this->provisioning?->listForwardCombinations() ?? [];
```

with:

```php
$routeDirections = $this->provisioning?->listRouteDirections(true) ?? [];
```

Inside the plan form card, replace the entire `Allowed Forward Combinations` checkbox block with this exact block:

```php
$selectedRouteDirectionIds = array_map('strval', $plan['allowed_route_direction_ids'] ?? []);
$directionEntitlementHtml = '<div class="field"><label>Allowed Route Directions</label>'
    . '<div class="muted">Select the product directions this plan may use for Client Rules.</div>'
    . '<div class="checkbox-list">' . $this->renderRouteDirectionCheckboxes($routeDirections, $selectedRouteDirectionIds) . '</div></div>'
    . '<div class="field"><label>Max Client Rules Per Service Scope</label>'
    . '<input type="number" min="0" name="max_client_rules_per_service_scope" value="' . (int) ($plan['max_client_rules_per_service_scope'] ?? 5) . '">'
    . '<div class="muted">0 disables user-created Client Rules for this plan.</div></div>';
```

Then insert `$directionEntitlementHtml` where the old `$this->renderForwardCombinationCheckboxes(...)` output was appended, and delete any user-visible `Allowed Forward Combinations`, `allowed_forward_combination_ids[]`, `backend_mode`, `current_mode`, `cf_mode`, or `cf_location_group_id` plan-entitlement markup from `plansPage()`.

Render direction checkboxes with a new private helper:

```php
private function renderRouteDirectionCheckboxes(array $directions, array $selectedIds): string
{
    if ($directions === []) {
        return '<div class="muted">No Route Directions yet. Create a Route Direction first.</div>';
    }
    $html = '';
    foreach ($directions as $direction) {
        $id = (string) ($direction['id'] ?? '');
        $label = (string) ($direction['label'] ?? $id);
        $status = (string) ($direction['status'] ?? 'available');
        $html .= '<label style="display:flex;align-items:center;gap:10px;padding:8px 0;"><input type="checkbox" name="allowed_route_direction_ids[]" value="' . $this->escape($id) . '"' . (in_array($id, $selectedIds, true) ? ' checked' : '') . '><span><strong>' . $this->escape($label) . '</strong> <span class="muted">' . $this->escape($status) . '</span></span></label>';
    }
    return $html;
}
```

In `AdminController::savePlan()`, add:

```php
'allowed_route_direction_ids' => $request->body()['allowed_route_direction_ids'] ?? [],
'max_client_rules_per_service_scope' => $request->input('max_client_rules_per_service_scope', '5'),
```

- [ ] **Step 9: Add legacy 410 handler**

Add this method to `AdminController`:

```php
public function legacyRoutingGone(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }
    return Response::html(
        410,
        '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Gone</title></head><body><h1>Route Direction required</h1><p>legacy_routing_surface_gone</p><p>Use Route Directions and Client Rules for this edition.</p></body></html>'
    );
}
```

- [ ] **Step 10: Run admin tests**

Run:

```bash
php master/tests/admin_route_direction_routes_test.php
php master/tests/admin_client_rules_test.php
php master/tests/legacy_routing_pages_gone_test.php
php master/tests/admin_forward_combination_page_test.php
php master/tests/admin_nav_consistency_regression_test.php
```

Expected: all commands print `ok`. If `admin_nav_consistency_regression_test.php` expects old links, update that test in the same commit so it expects `/admin/route-directions` and `/admin/client-rules` instead.

- [ ] **Step 11: Lint and commit**

Run:

```bash
php -l master/src/Controllers/AdminController.php
php -l master/src/Controllers/BaseController.php
php -l master/src/Bootstrap.php
```

Expected: each command prints `No syntax errors detected`.

Commit:

```bash
git add master/src/Controllers/AdminController.php master/src/Controllers/BaseController.php master/src/Bootstrap.php master/tests/admin_route_direction_routes_test.php master/tests/admin_client_rules_test.php master/tests/legacy_routing_pages_gone_test.php master/tests/admin_forward_combination_page_test.php master/tests/admin_nav_consistency_regression_test.php
git commit -m "feat(master): add route direction admin surfaces" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Final compatibility and terminology verification

**Files:**
- Modify only files that fail the verification commands in this task.
- Tests: all new tests plus touched legacy regression tests.

- [ ] **Step 1: Run targeted tests**

Run:

```bash
php master/tests/route_direction_schema_test.php
php master/tests/route_direction_service_test.php
php master/tests/client_rule_validator_test.php
php master/tests/plan_direction_entitlements_test.php
php master/tests/admin_route_direction_routes_test.php
php master/tests/admin_client_rules_test.php
php master/tests/client_rule_write_api_surface_test.php
php master/tests/client_config_pull_route_direction_test.php
php master/tests/legacy_routing_pages_gone_test.php
php master/tests/admin_forward_combination_page_test.php
```

Expected: every command prints `ok`.

- [ ] **Step 2: Run PHP lint on every touched PHP source file**

Run:

```bash
php -l master/src/Support/RouteDirectionService.php
php -l master/src/Support/ClientRuleValidator.php
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/ClientAgentController.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Controllers/BaseController.php
php -l master/src/Bootstrap.php
```

Expected: every command prints `No syntax errors detected`.

- [ ] **Step 3: Verify additive pull compatibility manually from source**

Inspect `ClientAgentController::pullConfigs()` and confirm these exact facts:

```php
$routeRulePayload = $this->provisioning->routeDirectionConfigPullForClient((int) $client['id'], $routeRuleCapabilities, $forwards);
return $this->json(array_merge($responsePayload, $routeRulePayload));
```

Expected:
- `responsePayload` still contains `ok`, `client`, and `forwards` for old clients.
- `routeDirectionConfigPullForClient()` returns `[]` unless both `route_direction_rules` and `exact_destination_rules` are true.
- `filterRouteRuleHelperForwardsForPull()` runs before the response payload is built, so old clients do not receive helper-only forwards.

- [ ] **Step 4: Verify terminology boundaries**

Run this PowerShell command from the repository worktree root:

```powershell
$files = @('master/src/Controllers/ClientAgentController.php','master/src/Controllers/AdminController.php','master/src/Controllers/BaseController.php','master/src/Support/RouteDirectionService.php'); foreach ($file in $files) { php -r "`$s=file_get_contents('$file'); if (preg_match('/Route Directions|Client Rules|route_directions|client_rules/', `$s)) { echo '$file checked' . PHP_EOL; }" }
```

Expected: each listed file prints `checked`. Then review the Route Direction and Client Rule page/API snippets touched in Tasks 5-7 and confirm:

- Client pull `route_directions`, `route_rule_entitlements`, `client_rules`, and client rule write responses do not include forbidden implementation terms.
- Admin Client Rules page does not include forbidden implementation terms.
- Plans page uses Route Direction and Client Rule quota language only.
- The only new UI area with operator-only internal mapping fields is `/admin/route-directions`.

- [ ] **Step 5: Commit any verification fixes**

If Step 1-4 required edits, commit them:

```bash
git add master
git commit -m "fix(master): tighten route direction compatibility checks" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

If no edits were required, do not create an empty commit.

## Self-review checklist for the implementer

- [ ] Schema covers `route_directions`, `direction_realizations`, `service_plan_route_directions`, `service_plans.max_client_rules_per_service_scope`, and `client_rules` duplicate/tombstone/sync-version fields.
- [ ] `RouteDirectionService` exposes all required methods named in this plan.
- [ ] `ClientRuleValidator` enforces canonical IPv4, port range, blocked destination policy, duplicate key, and write-error precedence.
- [ ] `ProvisioningService` exposes all required wrappers and Client Rule CRUD methods named in this plan.
- [ ] `ClientAgentController` records route-rule capabilities and merges upgraded pull fields additively with `array_merge`.
- [ ] Old clients with no capability receive no route-rule fields and no helper-only forwards.
- [ ] Admin Route Directions, Client Rules, and Plans pages use product terms; only the Route Direction admin mapping section contains operator-only internal mapping fields.
- [ ] Legacy admin routing product URLs return 410 through `legacyRoutingGone`.
- [ ] All listed tests and lint commands pass before handoff.
