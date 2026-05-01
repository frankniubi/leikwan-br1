# Tenant Default Client Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create one real shared default client for every tenant, use it as the deterministic fallback for tenant-created forwards, and safely backfill older tenants plus only truly unbound older forwards.

**Architecture:** Add an explicit default-client marker on `clients`, centralize default-client lifecycle rules inside `ProvisioningService`, then hook tenant registration, admin tenant creation, forward fallback, delete behavior, and a one-time CLI backfill flow into those helpers. Keep the rollout conservative by only patching forwards with no explicit client or client-group binding.

**Tech Stack:** PHP 8.2, existing `ProvisioningService` / controller architecture, SQL migrations in `master/migrations`, CLI scripts in `master/scripts`, SQLite-backed regression tests plus source-string regression tests in `master/tests`.

---

### Task 1: Add the default-client schema and schema regression coverage

**Files:**
- Create: `master/migrations/20260425_add_client_default_owner_flag.sql`
- Modify: `master/migrations/schema.sql:323-365`
- Test: `master/tests/default_client_schema_regression_test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migration = file_exists(__DIR__ . '/../migrations/20260425_add_client_default_owner_flag.sql')
    ? file_get_contents(__DIR__ . '/../migrations/20260425_add_client_default_owner_flag.sql')
    : '';

if (!is_string($schema) || $schema === '') {
    fail('failed to read schema.sql');
}

foreach ([
    'is_default_for_owner TINYINT(1) NOT NULL DEFAULT 0',
    'INDEX idx_clients_owner_default_status (owner_user_id, is_default_for_owner, status)',
] as $required) {
    if (!str_contains($schema, $required) && !str_contains((string) $migration, $required)) {
        fail('missing default-client schema snippet: ' . $required);
    }
}

if (!str_contains((string) $migration, 'ALTER TABLE clients')) {
    fail('missing client default-flag migration');
}

echo "ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d date.timezone=UTC master/tests/default_client_schema_regression_test.php`

Expected: FAIL with `missing default-client schema snippet` because neither the schema nor the new migration contains the default-client column yet.

- [ ] **Step 3: Write minimal implementation**

Add this migration file:

```sql
ALTER TABLE clients
    ADD COLUMN is_default_for_owner TINYINT(1) NOT NULL DEFAULT 0 AFTER owner_user_id;

ALTER TABLE clients
    ADD INDEX idx_clients_owner_default_status (owner_user_id, is_default_for_owner, status);
```

Update `master/migrations/schema.sql` inside the `CREATE TABLE IF NOT EXISTS clients` block:

```sql
    owner_user_id BIGINT UNSIGNED NULL,
    is_default_for_owner TINYINT(1) NOT NULL DEFAULT 0,
    agent_token VARCHAR(128) NULL,
```

and append the index in the clients table indexes section:

```sql
    INDEX idx_clients_owner_default_status (owner_user_id, is_default_for_owner, status),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d date.timezone=UTC master/tests/default_client_schema_regression_test.php`

Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add master/migrations/20260425_add_client_default_owner_flag.sql master/migrations/schema.sql master/tests/default_client_schema_regression_test.php
git commit -m "feat: add tenant default client schema"
```

### Task 2: Add default-client lifecycle helpers in `ProvisioningService`

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:718-744`, `master/src/Support/ProvisioningService.php:1062-1169`
- Test: `master/tests/default_client_provisioning_test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database/Database.php';
require_once __DIR__ . '/../src/Support/ApiException.php';
require_once __DIR__ . '/../src/Support/ProvisioningService.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$tmpDir = sys_get_temp_dir() . '/leikwan-default-client-' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    fail('failed to create temp dir');
}

$envPath = $tmpDir . '/.env';
$dbPath = $tmpDir . '/default-clients.sqlite';
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\n");

$config = new Config($envPath);
$db = new Database($config);
$pdo = $db->pdo();

$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    role TEXT NOT NULL,
    status TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER NULL,
    group_id INTEGER NULL,
    is_default_for_owner INTEGER NOT NULL DEFAULT 0,
    agent_token TEXT NULL,
    name TEXT NOT NULL,
    display_host TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT "active",
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');

$pdo->exec("INSERT INTO users (id, name, email, role, status) VALUES
    (10, 'Tenant A', 'tenant-a@example.com', 'tenant', 'active'),
    (20, 'Tenant B', 'tenant-b@example.com', 'tenant', 'active'),
    (30, 'Tenant C', 'tenant-c@example.com', 'tenant', 'active')");
$pdo->exec("INSERT INTO clients (id, owner_user_id, group_id, is_default_for_owner, agent_token, name, display_host, status, created_at, updated_at) VALUES
    (101, 20, NULL, 0, 'tok-101', 'Older Active', 'older.example.com', 'active', '2026-04-25 00:00:00', '2026-04-25 00:00:00'),
    (102, 20, NULL, 0, 'tok-102', 'Newer Active', 'newer.example.com', 'active', '2026-04-25 00:00:01', '2026-04-25 00:00:01'),
    (201, 30, NULL, 1, 'tok-201', 'First Wrong Default', 'first.example.com', 'active', '2026-04-25 00:00:00', '2026-04-25 00:00:00'),
    (202, 30, NULL, 1, 'tok-202', 'Second Wrong Default', 'second.example.com', 'active', '2026-04-25 00:00:01', '2026-04-25 00:00:01')");

$service = new ProvisioningService($config, $db);

$created = $service->ensureDefaultClientForUser(10);
if ((int) ($created['owner_user_id'] ?? 0) !== 10 || (int) ($created['is_default_for_owner'] ?? 0) !== 1) {
    fail('expected ensureDefaultClientForUser() to create a default client for tenant 10');
}

$repeat = $service->ensureDefaultClientForUser(10);
if ((int) ($repeat['id'] ?? 0) !== (int) ($created['id'] ?? 0)) {
    fail('expected ensureDefaultClientForUser() to be idempotent for tenant 10');
}

$backfill = $service->backfillDefaultClientsForUsers(null, true);
if ((int) ($backfill['created'] ?? 0) < 1 || (int) ($backfill['marked_existing'] ?? 0) < 1 || (int) ($backfill['normalized_duplicates'] ?? 0) < 1) {
    fail('expected backfillDefaultClientsForUsers() to report created, marked, and normalized tenants');
}

$defaultB = $service->defaultClientForUser(20);
if ((int) ($defaultB['id'] ?? 0) !== 101) {
    fail('expected oldest active owned client to become default for tenant 20');
}

$allC = $db->fetchAll('SELECT id, is_default_for_owner FROM clients WHERE owner_user_id = :owner_user_id ORDER BY id ASC', ['owner_user_id' => 30]);
$defaultCount = 0;
foreach ($allC as $row) {
    if ((int) ($row['is_default_for_owner'] ?? 0) === 1) {
        $defaultCount++;
    }
}
if ($defaultCount !== 1) {
    fail('expected tenant 30 to have exactly one default client after normalization');
}

echo "ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d date.timezone=UTC master/tests/default_client_provisioning_test.php`

Expected: FAIL with `Call to undefined method Leikwan\Master\Support\ProvisioningService::ensureDefaultClientForUser()` because the helper set does not exist yet.

- [ ] **Step 3: Write minimal implementation**

Add these focused helpers to `master/src/Support/ProvisioningService.php` near the client lifecycle methods:

```php
public function defaultClientForUser(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $row = $this->db->fetchOne(
        'SELECT *
         FROM clients
         WHERE owner_user_id = :owner_user_id
           AND is_default_for_owner = 1
         ORDER BY id ASC
         LIMIT 1',
        ['owner_user_id' => $userId]
    );

    return is_array($row) ? $row : null;
}

public function ensureDefaultClientForUser(int $userId): array
{
    $user = $this->tenantUserById($userId);
    $existingDefault = $this->defaultClientForUser($userId);
    if ($existingDefault !== null) {
        return $existingDefault;
    }

    $owned = $this->db->fetchAll(
        'SELECT *
         FROM clients
         WHERE owner_user_id = :owner_user_id
         ORDER BY CASE WHEN status = :active THEN 0 ELSE 1 END, id ASC',
        [
            'owner_user_id' => $userId,
            'active' => 'active',
        ]
    );

    if ($owned !== []) {
        $clientId = (int) ($owned[0]['id'] ?? 0);
        $this->markClientAsDefaultForUser($userId, $clientId);
        return $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $clientId]) ?? [];
    }

    return $this->createDefaultClientForUser($userId, $user);
}

public function createDefaultClientForUser(int $userId, array $user): array
{
    $now = $this->now();
    $emailLocal = preg_replace('/[^a-z0-9]+/i', '-', (string) strstr((string) ($user['email'] ?? ''), '@', true));
    $displayHost = trim((string) $emailLocal, '-');
    if ($displayHost === '') {
        $displayHost = 'tenant-' . $userId;
    }
    $displayHost .= '.client.local';

    $clientId = $this->db->insert('clients', [
        'group_id' => null,
        'owner_user_id' => $userId,
        'is_default_for_owner' => 1,
        'agent_token' => bin2hex(random_bytes(24)),
        'name' => 'Default Client',
        'display_host' => $displayHost,
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $clientId]) ?? [];
}

public function markClientAsDefaultForUser(int $userId, int $clientId): void
{
    $this->db->execute(
        'UPDATE clients
         SET is_default_for_owner = 0,
             updated_at = :updated_at
         WHERE owner_user_id = :owner_user_id',
        [
            'owner_user_id' => $userId,
            'updated_at' => $this->now(),
        ]
    );

    $this->db->execute(
        'UPDATE clients
         SET is_default_for_owner = 1,
             updated_at = :updated_at
         WHERE id = :id
           AND owner_user_id = :owner_user_id',
        [
            'id' => $clientId,
            'owner_user_id' => $userId,
            'updated_at' => $this->now(),
        ]
    );
}

public function backfillDefaultClientsForUsers(?int $userId = null, bool $apply = false): array
{
    $tenants = $userId !== null
        ? [$this->tenantUserById($userId)]
        : $this->db->fetchAll('SELECT * FROM users WHERE role = :role ORDER BY id ASC', ['role' => 'tenant']);

    $summary = [
        'scanned' => count($tenants),
        'created' => 0,
        'marked_existing' => 0,
        'normalized_duplicates' => 0,
    ];

    foreach ($tenants as $tenant) {
        $owned = $this->db->fetchAll(
            'SELECT *
             FROM clients
             WHERE owner_user_id = :owner_user_id
             ORDER BY CASE WHEN status = :active THEN 0 ELSE 1 END, id ASC',
            [
                'owner_user_id' => (int) ($tenant['id'] ?? 0),
                'active' => 'active',
            ]
        );

        $defaults = array_values(array_filter($owned, static fn(array $client): bool => (int) ($client['is_default_for_owner'] ?? 0) === 1));
        if ($owned === []) {
            $summary['created']++;
            if ($apply) {
                $this->createDefaultClientForUser((int) $tenant['id'], $tenant);
            }
            continue;
        }

        if ($defaults === []) {
            $summary['marked_existing']++;
            if ($apply) {
                $this->markClientAsDefaultForUser((int) $tenant['id'], (int) ($owned[0]['id'] ?? 0));
            }
            continue;
        }

        if (count($defaults) > 1) {
            $summary['normalized_duplicates']++;
            if ($apply) {
                $this->markClientAsDefaultForUser((int) $tenant['id'], (int) ($owned[0]['id'] ?? 0));
            }
        }
    }

    return $summary;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d date.timezone=UTC master/tests/default_client_provisioning_test.php`

Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/tests/default_client_provisioning_test.php
git commit -m "feat: add tenant default client lifecycle helpers"
```

### Task 3: Hook self-registration and admin tenant creation into the default-client lifecycle

**Files:**
- Modify: `master/src/Support/TenantRegistrationService.php:98-204`
- Modify: `master/src/Controllers/AdminController.php:2514-2593`
- Test: `master/tests/default_client_registration_hooks_regression_test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$registration = file_get_contents(__DIR__ . '/../src/Support/TenantRegistrationService.php');
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');

if (!is_string($registration) || $registration === '') {
    fail('failed to read TenantRegistrationService source');
}
if (!is_string($admin) || $admin === '') {
    fail('failed to read AdminController source');
}

foreach ([
    '$this->provisioning()->ensureDefaultClientForUser((int) ($user[\'id\'] ?? 0));',
    '$this->provisioning()->ensureDefaultClientForUser($tenantId);',
] as $required) {
    if (!str_contains($registration . $admin, $required)) {
        fail('missing default-client creation hook: ' . $required);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d date.timezone=UTC master/tests/default_client_registration_hooks_regression_test.php`

Expected: FAIL with `missing default-client creation hook` because neither registration nor admin tenant save currently ensures a default client.

- [ ] **Step 3: Write minimal implementation**

In `master/src/Support/TenantRegistrationService.php`, add a provisioning accessor:

```php
private function provisioning(): ProvisioningService
{
    return new ProvisioningService($this->config, $this->db);
}
```

Then, just before returning `$user` from `registerTenant(...)`, add:

```php
$this->provisioning()->ensureDefaultClientForUser((int) ($user['id'] ?? 0));
```

In `master/src/Controllers/AdminController.php`, capture the tenant id and ensure the default client after create and update:

```php
$tenantId = 0;
if ($existing === null) {
    $tenantId = (int) $this->db->insert('users', [
        'name' => $name,
        'email' => $email,
        'api_token' => bin2hex(random_bytes(24)),
        'password_hash' => $passwordHash,
        'role' => 'tenant',
        'feature_flags_json' => json_encode($this->extractFeatureFlagsFromRequest($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'status' => $status,
        'email_verified_at' => $emailVerifiedAt,
        'email_verification_token' => $emailVerificationToken,
        'verification_sent_at' => $verificationSentAt,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    SessionAuth::flash('success', 'Tenant created.');
} else {
    $tenantId = (int) $existing['id'];
    $this->db->execute(
        'UPDATE users
         SET name = :name,
             email = :email,
             password_hash = :password_hash,
             role = :role,
             feature_flags_json = :feature_flags_json,
             status = :status,
             email_verified_at = :email_verified_at,
             email_verification_token = :email_verification_token,
             verification_sent_at = :verification_sent_at,
             updated_at = :updated_at
         WHERE id = :id',
        [
            'id' => $existing['id'],
            'name' => $name,
            'email' => $email,
            'password_hash' => $passwordHash ?? $existing['password_hash'],
            'role' => 'tenant',
            'feature_flags_json' => json_encode($this->extractFeatureFlagsFromRequest($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => $status,
            'email_verified_at' => $emailVerifiedAt,
            'email_verification_token' => $emailVerificationToken,
            'verification_sent_at' => $verificationSentAt,
            'updated_at' => $now,
        ]
    );
    SessionAuth::flash('success', 'Tenant updated.');
}

$this->provisioning->ensureDefaultClientForUser($tenantId);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d date.timezone=UTC master/tests/default_client_registration_hooks_regression_test.php`

Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add master/src/Support/TenantRegistrationService.php master/src/Controllers/AdminController.php master/tests/default_client_registration_hooks_regression_test.php
git commit -m "feat: create a default client when tenants are created"
```

### Task 4: Use the default client for unbound tenant forwards and backfill only truly unbound old forwards

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:2972-3065`
- Modify: `master/src/Support/ProvisioningService.php:2122-2178`
- Test: `master/tests/default_client_forward_binding_test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database/Database.php';
require_once __DIR__ . '/../src/Support/ApiException.php';
require_once __DIR__ . '/../src/Support/ProvisioningService.php';

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

$tmpDir = sys_get_temp_dir() . '/leikwan-default-forward-' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    fail('failed to create temp dir');
}

$envPath = $tmpDir . '/.env';
$dbPath = $tmpDir . '/forward-binding.sqlite';
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\n");

$config = new Config($envPath);
$db = new Database($config);
$pdo = $db->pdo();

$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL)');
$pdo->exec('CREATE TABLE clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER NULL,
    group_id INTEGER NULL,
    is_default_for_owner INTEGER NOT NULL DEFAULT 0,
    agent_token TEXT NULL,
    name TEXT NOT NULL,
    display_host TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT "active",
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE services (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    plan_id INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE forwards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    service_id INTEGER NOT NULL,
    line_client_id INTEGER NULL,
    line_client_group_id INTEGER NULL,
    line_client_group_ids_json TEXT NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
)');

$pdo->exec("INSERT INTO users (id, name, email, role, status) VALUES (10, 'Tenant A', 'tenant-a@example.com', 'tenant', 'active')");
$pdo->exec("INSERT INTO clients (id, owner_user_id, group_id, is_default_for_owner, agent_token, name, display_host, status, created_at, updated_at) VALUES
    (101, 10, NULL, 1, 'tok-default', 'Default Client', 'default.client.local', 'active', '2026-04-25 00:00:00', '2026-04-25 00:00:00'),
    (102, 10, NULL, 0, 'tok-other', 'Other Client', 'other.client.local', 'active', '2026-04-25 00:00:01', '2026-04-25 00:00:01')");
$pdo->exec("INSERT INTO services (id, user_id, plan_id, status) VALUES (501, 10, 0, 'active')");
$pdo->exec("INSERT INTO forwards (id, service_id, line_client_id, line_client_group_id, line_client_group_ids_json, enabled, updated_at) VALUES
    (601, 501, NULL, NULL, NULL, 1, '2026-04-25 00:00:00'),
    (602, 501, 102, NULL, NULL, 1, '2026-04-25 00:00:00'),
    (603, 501, NULL, 77, NULL, 1, '2026-04-25 00:00:00'),
    (604, 501, NULL, NULL, '[88]', 1, '2026-04-25 00:00:00')");

$service = new ProvisioningService($config, $db);

$implicit = call_private(
    $service,
    'resolveImplicitTenantLineClientId',
    ['id' => 501, 'user_id' => 10],
    0,
    0,
    [],
    'tenant'
);
if ((int) $implicit !== 101) {
    fail('expected tenant fallback to resolve the marked default client');
}

$summary = $service->assignUnboundForwardsToDefaultClient(null, true);
if ((int) ($summary['updated_forwards'] ?? 0) !== 1) {
    fail('expected exactly one unbound forward to be reassigned');
}

$rows = $db->fetchAll('SELECT id, line_client_id FROM forwards ORDER BY id ASC');
$actual = [];
foreach ($rows as $row) {
    $actual[(int) $row['id']] = $row['line_client_id'] !== null ? (int) $row['line_client_id'] : null;
}

if ($actual !== [
    601 => 101,
    602 => 102,
    603 => null,
    604 => null,
]) {
    fail('unexpected forward reassociation map: ' . json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

echo "ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d date.timezone=UTC master/tests/default_client_forward_binding_test.php`

Expected: FAIL with `Call to undefined method` or reflection failure for `resolveImplicitTenantLineClientId()` / `assignUnboundForwardsToDefaultClient()`.

- [ ] **Step 3: Write minimal implementation**

Add this helper in `master/src/Support/ProvisioningService.php`:

```php
private function resolveImplicitTenantLineClientId(
    array $service,
    int $lineClientId,
    int $lineClientGroupId,
    array $selectedLineClientGroupIds,
    string $actor
): int {
    if ($lineClientId > 0 || $lineClientGroupId > 0 || $selectedLineClientGroupIds !== []) {
        return $lineClientId;
    }

    if ($actor !== 'tenant') {
        return $lineClientId;
    }

    $defaultClient = $this->defaultClientForUser((int) ($service['user_id'] ?? 0));
    if ($defaultClient !== null) {
        return (int) ($defaultClient['id'] ?? 0);
    }

    return $lineClientId;
}
```

Then replace the current fallback:

```php
if ($lineClientId <= 0 && $lineClientGroupId <= 0 && $selectedLineClientGroupIds === [] && $availableClients !== []) {
    $lineClientId = (int) $availableClients[0]['id'];
}
```

with:

```php
$lineClientId = $this->resolveImplicitTenantLineClientId(
    $service,
    $lineClientId,
    $lineClientGroupId,
    $selectedLineClientGroupIds,
    $actor
);
```

Add the backfill method:

```php
public function assignUnboundForwardsToDefaultClient(?int $userId = null, bool $apply = false): array
{
    $services = $userId !== null
        ? $this->db->fetchAll(
            'SELECT * FROM services WHERE user_id = :user_id ORDER BY id ASC',
            ['user_id' => $userId]
        )
        : $this->db->fetchAll(
            'SELECT s.*
             FROM services s
             INNER JOIN users u ON u.id = s.user_id
             WHERE u.role = :role
             ORDER BY s.id ASC',
            ['role' => 'tenant']
        );

    $summary = [
        'scanned_services' => count($services),
        'updated_forwards' => 0,
    ];

    foreach ($services as $service) {
        $defaultClient = $this->defaultClientForUser((int) ($service['user_id'] ?? 0));
        if ($defaultClient === null) {
            continue;
        }

        $forwards = $this->db->fetchAll(
            'SELECT *
             FROM forwards
             WHERE service_id = :service_id
             ORDER BY id ASC',
            ['service_id' => (int) ($service['id'] ?? 0)]
        );

        foreach ($forwards as $forward) {
            $explicitClient = (int) ($forward['line_client_id'] ?? 0) > 0;
            $explicitGroup = (int) ($forward['line_client_group_id'] ?? 0) > 0;
            $groupListRaw = trim((string) ($forward['line_client_group_ids_json'] ?? ''));
            $groupList = $groupListRaw !== '' ? json_decode($groupListRaw, true) : [];
            $explicitGroupList = is_array($groupList) && $groupList !== [];
            if ($explicitClient || $explicitGroup || $explicitGroupList) {
                continue;
            }

            $summary['updated_forwards']++;
            if ($apply) {
                $this->db->execute(
                    'UPDATE forwards
                     SET line_client_id = :line_client_id,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => (int) ($forward['id'] ?? 0),
                        'line_client_id' => (int) ($defaultClient['id'] ?? 0),
                        'updated_at' => $this->now(),
                    ]
                );
            }
        }
    }

    return $summary;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d date.timezone=UTC master/tests/default_client_forward_binding_test.php`

Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/tests/default_client_forward_binding_test.php
git commit -m "feat: bind unassigned tenant forwards to the default client"
```

### Task 5: Protect the default client during deletion

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:1172-1215`
- Test: `master/tests/default_client_delete_regression_test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\ProvisioningService;

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database/Database.php';
require_once __DIR__ . '/../src/Support/ApiException.php';
require_once __DIR__ . '/../src/Support/ProvisioningService.php';

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$tmpDir = sys_get_temp_dir() . '/leikwan-default-delete-' . bin2hex(random_bytes(4));
if (!mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    fail('failed to create temp dir');
}

$envPath = $tmpDir . '/.env';
$dbPath = $tmpDir . '/default-delete.sqlite';
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\n");

$config = new Config($envPath);
$db = new Database($config);
$pdo = $db->pdo();

$pdo->exec('CREATE TABLE clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER NULL,
    is_default_for_owner INTEGER NOT NULL DEFAULT 0,
    name TEXT NOT NULL,
    display_host TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT "active",
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE forwards (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    line_client_id INTEGER NULL,
    enabled INTEGER NOT NULL DEFAULT 1,
    updated_at TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE agent_usage_hourly (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NOT NULL
)');

$pdo->exec("INSERT INTO clients (id, owner_user_id, is_default_for_owner, name, display_host, status, created_at, updated_at) VALUES
    (101, 10, 1, 'Default Client', 'default.client.local', 'active', '2026-04-25 00:00:00', '2026-04-25 00:00:00'),
    (102, 10, 0, 'Backup Client', 'backup.client.local', 'active', '2026-04-25 00:00:01', '2026-04-25 00:00:01'),
    (201, 20, 1, 'Lonely Default', 'lonely.client.local', 'active', '2026-04-25 00:00:00', '2026-04-25 00:00:00')");

$service = new ProvisioningService($config, $db);
$service->deleteClient(101);

$promoted = $db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => 102]);
if ((int) ($promoted['is_default_for_owner'] ?? 0) !== 1) {
    fail('expected fallback client to be promoted to default after deleting the old default');
}

try {
    $service->deleteClient(201);
    fail('expected deleting the only default client to be blocked');
} catch (ApiException $exception) {
    if ((string) $exception->getMessage() === '') {
        fail('expected a readable ApiException message when blocking default-client deletion');
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d date.timezone=UTC master/tests/default_client_delete_regression_test.php`

Expected: FAIL because `deleteClient()` currently just deletes the row and does not promote a replacement or block the last default client.

- [ ] **Step 3: Write minimal implementation**

Update `deleteClient()` in `master/src/Support/ProvisioningService.php` so it loads the client first and handles the default case before the existing delete logic:

```php
$client = $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $clientId]);
if ($client === null) {
    throw new ApiException('client_not_found', 404, ['message' => 'Client not found.']);
}
```

Inside the transaction, before deleting the client row:

```php
$ownerUserId = (int) ($client['owner_user_id'] ?? 0);
$isDefault = (int) ($client['is_default_for_owner'] ?? 0) === 1;
if ($isDefault && $ownerUserId > 0) {
    $replacement = $db->fetchOne(
        'SELECT *
         FROM clients
         WHERE owner_user_id = :owner_user_id
           AND id <> :id
         ORDER BY CASE WHEN status = :active THEN 0 ELSE 1 END, id ASC
         LIMIT 1',
        [
            'owner_user_id' => $ownerUserId,
            'id' => $clientId,
            'active' => 'active',
        ]
    );
    if ($replacement === null) {
        throw new ApiException('client_delete_blocked', 409, ['message' => 'The tenant must keep at least one default client.']);
    }
}
```

Then, after deleting the client row, promote the replacement if one was chosen:

```php
if ($isDefault && isset($replacement['id'])) {
    $db->execute(
        'UPDATE clients
         SET is_default_for_owner = 0,
             updated_at = :updated_at
         WHERE owner_user_id = :owner_user_id',
        [
            'owner_user_id' => $ownerUserId,
            'updated_at' => $this->now(),
        ]
    );
    $db->execute(
        'UPDATE clients
         SET is_default_for_owner = 1,
             updated_at = :updated_at
         WHERE id = :id',
        [
            'id' => (int) $replacement['id'],
            'updated_at' => $this->now(),
        ]
    );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d date.timezone=UTC master/tests/default_client_delete_regression_test.php`

Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/tests/default_client_delete_regression_test.php
git commit -m "fix: preserve a tenant default client on delete"
```

### Task 6: Add the backfill CLI for production repair

**Files:**
- Create: `master/scripts/backfill-default-clients.php`
- Test: `master/tests/default_client_backfill_script_regression_test.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$script = file_get_contents(__DIR__ . '/../scripts/backfill-default-clients.php');
if (!is_string($script) || $script === '') {
    fail('missing backfill-default-clients.php');
}

foreach ([
    '--user-id',
    '--dry-run',
    '--apply',
    'backfillDefaultClientsForUsers',
    'assignUnboundForwardsToDefaultClient',
] as $required) {
    if (!str_contains($script, $required)) {
        fail('missing backfill script snippet: ' . $required);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php -d date.timezone=UTC master/tests/default_client_backfill_script_regression_test.php`

Expected: FAIL with `missing backfill-default-clients.php` because the script does not exist yet.

- [ ] **Step 3: Write minimal implementation**

Create `master/scripts/backfill-default-clients.php`:

```php
<?php
declare(strict_types=1);

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Support\ProvisioningService;

require_once __DIR__ . '/../src/Bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$args = [
    'user_id' => null,
    'dry_run' => false,
    'apply' => false,
];
for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i] ?? '';
    if (str_starts_with($arg, '--user-id=')) {
        $args['user_id'] = (int) substr($arg, strlen('--user-id='));
        continue;
    }
    if ($arg === '--dry-run') {
        $args['dry_run'] = true;
        continue;
    }
    if ($arg === '--apply') {
        $args['apply'] = true;
        continue;
    }
}

if ($args['dry_run'] === $args['apply']) {
    fwrite(STDERR, "Usage: php master/scripts/backfill-default-clients.php [--user-id=<id>] --dry-run|--apply\n");
    exit(1);
}

$bootstrap = new Bootstrap(__DIR__ . '/../.env');
$service = new ProvisioningService($bootstrap->config(), $bootstrap->db());
$userId = is_int($args['user_id']) && $args['user_id'] > 0 ? $args['user_id'] : null;
$apply = (bool) $args['apply'];

$defaultSummary = $service->backfillDefaultClientsForUsers($userId, $apply);
$forwardSummary = $service->assignUnboundForwardsToDefaultClient($userId, $apply);

echo json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'user_id' => $userId,
    'default_clients' => $defaultSummary,
    'forwards' => $forwardSummary,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php -d date.timezone=UTC master/tests/default_client_backfill_script_regression_test.php`

Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add master/scripts/backfill-default-clients.php master/tests/default_client_backfill_script_regression_test.php
git commit -m "feat: add default-client backfill script"
```

## Self-Review

### Spec coverage

- schema flag and index: Task 1
- default-client helpers and idempotent creation: Task 2
- registration default client creation: Task 3
- admin tenant creation default client creation: Task 3
- tenant forward fallback to default client: Task 4
- conservative reassociation of only truly unbound forwards: Task 4
- delete promotion/block behavior: Task 5
- CLI dry-run/apply backfill flow: Task 6

### Placeholder scan

- no red-flag placeholder text remains
- each code-changing step includes concrete code
- each verification step includes exact commands and expected results

### Type consistency

Helper names used across tasks are consistent:

- `defaultClientForUser`
- `ensureDefaultClientForUser`
- `createDefaultClientForUser`
- `markClientAsDefaultForUser`
- `backfillDefaultClientsForUsers`
- `assignUnboundForwardsToDefaultClient`
- `resolveImplicitTenantLineClientId`
