# Tenant Forward Combination-Only Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make tenant forward creation combination-only everywhere, add safe non-structural forward editing, and enforce the same routing restrictions through the authenticated user API.

**Architecture:** Reuse the existing `/tenant/services/{id}/create-forward` page as the single create surface, remove the stale inline create form and wizard-era behavior, then add a dedicated edit flow that only mutates target-facing fields. In `ProvisioningService`, validate merged updates before revoking endpoints, and reuse that safe path from both tenant edit and `PUT /api/user/forwards/{id}`.

**Tech Stack:** PHP controllers, PHP `ProvisioningService`, existing source-string regression tests, lightweight fake-DB PHP behavior tests, existing route/bootstrap wiring

---

## What already exists

- `master/src/Controllers/TenantController.php:1717-1789` already renders the combination-only create page.
- `master/src/Controllers/TenantController.php:2600-2612` already normalizes the narrow create payload shape.
- `master/src/Support/ProvisioningService.php:2081-2089` already has a generic `updateForward(...)` path, but it is unsafe for partial edit payloads.
- `master/src/Controllers/ServiceController.php:77-96` already exposes `PUT /api/user/forwards/{id}`.
- `master/tests/tenant_forward_combination_regression_test.php` already covers the existence of forward combinations.
- `master/tests/provisioning_sidecar_republish_test.php` already shows the fake-DB behavior-test pattern we should reuse.

## NOT in scope

- Admin forward-combination CRUD
- Changing how combinations are resolved at create time
- Allowing tenants or API users to switch `forward_combination_id`
- Reworking sidecar/slave topology
- Cloudflare best-IP or other unrelated runtime work

## File map

- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/src/Controllers/ServiceController.php`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/tests/tenant_forward_combination_regression_test.php`
- Modify: `master/tests/tenant_controller_regressions_test.php`
- Add: `master/tests/tenant_forward_edit_regression_test.php`
- Add: `master/tests/provisioning_forward_non_structural_update_test.php`

## Task 1: Remove stale tenant create surfaces and simplify the service detail page

**Files:**
- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/src/Bootstrap.php`
- Test: `master/tests/tenant_forward_combination_regression_test.php`
- Test: `master/tests/tenant_controller_regressions_test.php`

- [ ] **Step 1: Write the failing UI regression checks**

Add assertions to `master/tests/tenant_forward_combination_regression_test.php` that require:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');

foreach ([
    '/tenant/services/{id}/create-forward',
    'Forward Combination',
    'Create Forward',
] as $snippet) {
    if (!str_contains($source . $bootstrap, $snippet)) {
        fail('missing required combination-only snippet: ' . $snippet);
    }
}

foreach ([
    '<h3 id="new-forward">Create Forward</h3>',
    '<label>Route Mode</label>',
    '<label>Forward Chain</label>',
    '<label>Connection Mode</label>',
    '<label>Sidecar Point</label>',
    '<label>Slave Group</label>',
    '<th>Line Groups</th>',
    '<th>Route Mode</th>',
    '<th>Chain</th>',
    '<th>Client Mode</th>',
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('found forbidden stale tenant forward snippet: ' . $forbidden);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the UI regression test and verify it fails**

Run: `php master/tests/tenant_forward_combination_regression_test.php`  
Expected: FAIL because the old inline create form and table columns still exist.

- [ ] **Step 3: Write the minimal tenant service-detail cleanup**

In `master/src/Controllers/TenantController.php`:

```php
// Remove the entire legacy inline create form block from serviceDetail().
// Replace it with a compact CTA block like:
$createForwardCta = '
    <section class="card">
      <h3>Create Forward</h3>
      <p class="muted">Choose an admin-published forward combination, then fill your backend target.</p>
      <div class="actions" style="margin-top:12px;">
        <a class="button" href="/tenant/services/' . (int) $service['id'] . '/create-forward">Create Forward</a>
        <a class="button ghost" href="/knowledgebase#quick-start">Knowledgebase</a>
      </div>
    </section>';
```

Also simplify the forward rules table header to:

```php
<tr>
  <th>ID</th>
  <th>Local Listen</th>
  <th>Forward Combination</th>
  <th>Target</th>
  <th>Transport</th>
  <th>Endpoint Status</th>
  <th>Actions</th>
</tr>
```

And simplify each row so it no longer renders line, line groups, route mode, chain, client mode, or path as top-level columns.

In `master/src/Bootstrap.php`, remove:

```php
$this->router->add('POST', '/tenant/services/{id}/forwards', [$tenant, 'createForward']);
```

Keep the wizard routes only as redirects.

- [ ] **Step 4: Re-run the UI regression test and verify it passes**

Run: `php master/tests/tenant_forward_combination_regression_test.php`  
Expected: PASS

- [ ] **Step 5: Update the stale tenant controller regression**

Replace the old assertions in `master/tests/tenant_controller_regressions_test.php` with:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read TenantController source');
}

foreach ([
    'No route package is available for this service yet. Ask admin to publish a forward combination first.',
    'Choose one route package, then fill your backend target.',
    'Create Forward',
] as $required) {
    if (!str_contains($source, $required)) {
        fail('missing required tenant forward flow snippet: ' . $required);
    }
}

foreach ([
    '<label>Forward Chain</label>',
    '<label>Route Mode</label>',
    '<label>Connection Mode</label>',
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('stale tenant forward selector still present: ' . $forbidden);
    }
}

echo "ok\n";
```

- [ ] **Step 6: Run the stale-regression replacement test**

Run: `php master/tests/tenant_controller_regressions_test.php`  
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add master/src/Controllers/TenantController.php master/src/Bootstrap.php master/tests/tenant_forward_combination_regression_test.php master/tests/tenant_controller_regressions_test.php
git commit -m "feat: make tenant forward creation combination-only"
```

## Task 2: Add tenant forward edit UI and controller endpoints

**Files:**
- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/src/Bootstrap.php`
- Add: `master/tests/tenant_forward_edit_regression_test.php`

- [ ] **Step 1: Write the failing tenant edit regression test**

Create `master/tests/tenant_forward_edit_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');

foreach ([
    "public function editForward(Request \$request, array \$params): Response",
    "public function saveForwardEdit(Request \$request, array \$params): Response",
    "private function normalizeTenantForwardEditPayload(array \$payload): array",
    "/tenant/services/{id}/forward/{fid}/edit",
    "Current Forward Combination",
] as $snippet) {
    if (!str_contains($tenant . $bootstrap, $snippet)) {
        fail('missing tenant edit snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the tenant edit regression test and verify it fails**

Run: `php master/tests/tenant_forward_edit_regression_test.php`  
Expected: FAIL because the edit routes and methods do not exist yet.

- [ ] **Step 3: Add edit routes**

In `master/src/Bootstrap.php`, add:

```php
$this->router->add('GET', '/tenant/services/{id}/forward/{fid}/edit', [$tenant, 'editForward']);
$this->router->add('POST', '/tenant/services/{id}/forward/{fid}/edit', [$tenant, 'saveForwardEdit']);
```

- [ ] **Step 4: Add the edit payload normalizer**

In `master/src/Controllers/TenantController.php`, add:

```php
/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
private function normalizeTenantForwardEditPayload(array $payload): array
{
    return [
        'listen_port' => max(0, (int) ($payload['listen_port'] ?? 0)),
        'target_host' => trim((string) ($payload['target_host'] ?? '')),
        'target_port' => max(0, (int) ($payload['target_port'] ?? 0)),
        'transport_mode' => match (strtolower(trim((string) ($payload['transport_mode'] ?? 'tcp')))) {
            'udp' => 'udp',
            'tcp+udp', 'udp+tcp', 'both' => 'tcp+udp',
            default => 'tcp',
        },
    ];
}
```

- [ ] **Step 5: Add the edit page and save handlers**

In `master/src/Controllers/TenantController.php`, add the minimal implementations:

```php
public function editForward(Request $request, array $params): Response
{
    $user = SessionAuth::currentUser($this->db);
    if ($user === null || (string) $user['role'] !== 'tenant') {
        return Response::redirect('/login');
    }

    $serviceId = $this->idParam($params);
    $forwardId = (int) ($params['fid'] ?? 0);
    $forward = $this->db->fetchOne(
        'SELECT f.*, s.user_id, combo.tenant_label AS forward_combination_label
         FROM forwards f
         INNER JOIN services s ON s.id = f.service_id
         LEFT JOIN forward_combinations combo ON combo.id = f.forward_combination_id
         WHERE f.id = :id AND f.service_id = :service_id LIMIT 1',
        ['id' => $forwardId, 'service_id' => $serviceId]
    );
    if ($forward === null || (int) $forward['user_id'] !== (int) $user['id']) {
        throw new ApiException('forward_not_found', 404);
    }

    $csrf = $this->escape(SessionAuth::csrfToken());
    $body = '
        <div class="hero">
          <div>
            <h2>Edit Forward</h2>
            <p class="muted">Change your backend target settings without changing the admin-defined route package.</p>
          </div>
        </div>
        <div class="card">
          <form method="post" action="/tenant/services/' . $serviceId . '/forward/' . $forwardId . '/edit">
            <input type="hidden" name="csrf_token" value="' . $csrf . '">
            <div class="form-grid">
              <div class="field"><label>Current Forward Combination</label><div class="value-chip">' . $this->escape((string) ($forward['forward_combination_label'] ?? '')) . '</div></div>
              <div class="field"><label>Target Host</label><input type="text" name="target_host" value="' . $this->escape((string) $forward['target_host']) . '" required></div>
              <div class="field"><label>Target Port</label><input type="number" name="target_port" value="' . (int) $forward['target_port'] . '" required></div>
              <div class="field"><label>Local Listen Port</label><input type="number" name="listen_port" value="' . (int) $forward['listen_port'] . '"></div>
              <div class="field"><label>Transport</label><select name="transport_mode"><option value="tcp"' . ((string) $forward['transport_mode'] === 'tcp' ? ' selected' : '') . '>TCP</option><option value="tcp+udp"' . ((string) $forward['transport_mode'] === 'tcp+udp' ? ' selected' : '') . '>TCP + UDP</option><option value="udp"' . ((string) $forward['transport_mode'] === 'udp' ? ' selected' : '') . '>UDP</option></select></div>
            </div>
            <div class="actions" style="margin-top:20px;">
              <button class="button" type="submit">Save Forward</button>
              <a class="button ghost" href="/tenant/services/' . $serviceId . '">Cancel</a>
            </div>
          </form>
        </div>';

    return $this->page('Edit Forward', $body, $user, $this->tenantNav('service', $serviceId));
}

public function saveForwardEdit(Request $request, array $params): Response
{
    $user = SessionAuth::currentUser($this->db);
    if ($user === null || (string) $user['role'] !== 'tenant') {
        return Response::redirect('/login');
    }

    $serviceId = $this->idParam($params);
    $forwardId = (int) ($params['fid'] ?? 0);
    try {
        SessionAuth::validateCsrf($request);
        $forward = $this->db->fetchOne(
            'SELECT f.*, s.user_id
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.id = :id AND f.service_id = :service_id LIMIT 1',
            ['id' => $forwardId, 'service_id' => $serviceId]
        );
        if ($forward === null || (int) $forward['user_id'] !== (int) $user['id']) {
            throw new ApiException('forward_not_found', 404);
        }
        $payload = $this->normalizeTenantForwardEditPayload($request->body());
        $this->provisioning->updateForwardNonStructural($forwardId, $payload, 'tenant');
        SessionAuth::flash('success', 'Forward updated.');
        return Response::redirect('/tenant/services/' . $serviceId);
    } catch (\Throwable $throwable) {
        SessionAuth::flash('error', 'Failed to update forward: ' . $this->displayThrowableMessage($throwable));
    }

    return Response::redirect('/tenant/services/' . $serviceId . '/forward/' . $forwardId . '/edit');
}
```

- [ ] **Step 6: Add Edit links to the service detail table**

In the forward actions cell in `serviceDetail()`, add:

```php
'<a class="button ghost" href="/tenant/services/' . (int) $service['id'] . '/forward/' . (int) $forward['id'] . '/edit">Edit</a>'
```

before the delete form.

- [ ] **Step 7: Re-run the tenant edit regression test and verify it passes**

Run: `php master/tests/tenant_forward_edit_regression_test.php`  
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add master/src/Controllers/TenantController.php master/src/Bootstrap.php master/tests/tenant_forward_edit_regression_test.php
git commit -m "feat: add tenant forward edit flow"
```

## Task 3: Make forward updates safe in ProvisioningService

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Add: `master/tests/provisioning_forward_non_structural_update_test.php`

- [ ] **Step 1: Write the failing safe-update behavior test**

Create `master/tests/provisioning_forward_non_structural_update_test.php` using the fake-DB pattern:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        private array $tables = [];
        private array $autoIds = ['service_endpoints' => 100, 'provision_tasks' => 200];

        public function seed(string $table, array $rows): void { $this->tables[$table] = array_values($rows); }
        public function fetchAll(string $sql, array $params = []): array {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'SELECT * FROM service_endpoints')) {
                return array_values(array_filter($this->tables['service_endpoints'] ?? [], static fn(array $row): bool => (int) ($row['forward_id'] ?? 0) === (int) ($params['forward_id'] ?? 0) && !in_array((string) ($row['health_status'] ?? ''), [(string) ($params['deleted'] ?? ''), (string) ($params['deleting'] ?? '')], true)));
            }
            if (str_contains($normalized, 'FROM provision_tasks')) {
                return $this->tables['provision_tasks'] ?? [];
            }
            return [];
        }
        public function fetchOne(string $sql, array $params = []): ?array {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'SELECT * FROM forwards WHERE id = :id LIMIT 1')) {
                foreach ($this->tables['forwards'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) return $row;
                }
            }
            if (str_contains($normalized, 'SELECT * FROM services WHERE id = :id LIMIT 1')) {
                foreach ($this->tables['services'] ?? [] as $row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) return $row;
                }
            }
            return null;
        }
        public function insert(string $table, array $data): int {
            $id = $this->autoIds[$table] ?? 1;
            $data['id'] = $id;
            $this->tables[$table][] = $data;
            $this->autoIds[$table] = $id + 1;
            return $id;
        }
        public function execute(string $sql, array $params = []): int {
            $normalized = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
            if (str_contains($normalized, 'UPDATE service_endpoints SET health_status = :status')) {
                foreach ($this->tables['service_endpoints'] ?? [] as &$row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        $row['health_status'] = $params['status'];
                        return 1;
                    }
                }
            }
            if (str_contains($normalized, 'UPDATE forwards SET')) {
                foreach ($this->tables['forwards'] ?? [] as &$row) {
                    if ((int) ($row['id'] ?? 0) === (int) ($params['id'] ?? 0)) {
                        foreach ($params as $key => $value) {
                            if ($key !== 'id') { $row[$key] = $value; }
                        }
                        return 1;
                    }
                }
            }
            return 0;
        }
        public function transaction(callable $callback) { return $callback($this); }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') return;
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) require_once $path;
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

    $env = tempnam(sys_get_temp_dir(), 'lw-env-');
    file_put_contents($env, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");
    $db = new Database();
    $db->seed('services', [['id' => 1, 'user_id' => 7, 'plan_id' => 1, 'status' => 'active', 'client_token' => 'tok']]);
    $db->seed('forwards', [[
        'id' => 9, 'service_id' => 1, 'forward_combination_id' => 22, 'backend_mode' => 'current_mode',
        'cf_location_group_id' => null, 'line_client_id' => 1, 'line_client_group_id' => null,
        'line_group_mode' => 'selected', 'line_client_group_ids_json' => null, 'endpoint_mode' => 'slave_group',
        'forward_chain_id' => null, 'custom_endpoint_ids_json' => null, 'transport_mode' => 'tcp',
        'client_mode' => 'single', 'sni_host' => 'edge.example.com', 'path_prefix' => 'abc',
        'node_group_key' => 'default', 'endpoint_count' => 1, 'public_port' => 443, 'cdn_host' => '',
        'filter_profile' => 'off', 'listen_port' => 5000, 'target_host' => 'old.example.com', 'target_port' => 80,
        'bond_remote_host' => null, 'bond_remote_port' => null, 'bond_ingress_mode' => 'plain_tcp_mux', 'bond_sidecar_point_id' => null,
    ]]);
    $db->seed('service_endpoints', [['id' => 70, 'forward_id' => 9, 'node_id' => 1, 'public_port' => 443, 'health_status' => 'active']]);
    $db->seed('provision_tasks', []);

    $service = new ProvisioningService(new Config($env), $db);

    try {
        $service->updateForwardNonStructural(9, [
            'target_host' => '',
            'target_port' => 443,
            'listen_port' => 5001,
            'transport_mode' => 'tcp+udp',
            'forward_combination_id' => 999,
        ], 'tenant');
        fail('expected invalid payload to throw');
    } catch (\Throwable) {
        $endpoints = $db->fetchAll('SELECT * FROM service_endpoints WHERE forward_id = :forward_id AND health_status <> :deleted AND health_status <> :deleting', [
            'forward_id' => 9,
            'deleted' => 'deleted',
            'deleting' => 'deleting',
        ]);
        if (count($endpoints) !== 1 || (int) ($endpoints[0]['id'] ?? 0) !== 70) {
            fail('invalid edit should not revoke existing endpoint');
        }
    }

    echo "ok\n";
```

- [ ] **Step 2: Run the safe-update behavior test and verify it fails**

Run: `php master/tests/provisioning_forward_non_structural_update_test.php`  
Expected: FAIL because `updateForwardNonStructural(...)` does not exist yet.

- [ ] **Step 3: Add the safe non-structural update path**

In `master/src/Support/ProvisioningService.php`, add:

```php
/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
public function updateForwardNonStructural(int $forwardId, array $payload, string $actor = 'user'): array
{
    $forward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => $forwardId]);
    if ($forward === null) {
        throw new ApiException('forward_not_found', 404);
    }

    $merged = array_merge($forward, [
        'listen_port' => (int) ($payload['listen_port'] ?? $forward['listen_port']),
        'target_host' => (string) ($payload['target_host'] ?? $forward['target_host']),
        'target_port' => (int) ($payload['target_port'] ?? $forward['target_port']),
        'transport_mode' => (string) ($payload['transport_mode'] ?? $forward['transport_mode']),
        'forward_combination_id' => $forward['forward_combination_id'],
        'backend_mode' => $forward['backend_mode'],
        'cf_location_group_id' => $forward['cf_location_group_id'],
        'line_client_id' => $forward['line_client_id'],
        'line_client_group_id' => $forward['line_client_group_id'],
        'line_group_mode' => $forward['line_group_mode'],
        'line_client_group_ids' => json_decode((string) ($forward['line_client_group_ids_json'] ?? '[]'), true) ?: [],
        'endpoint_mode' => $forward['endpoint_mode'],
        'forward_chain_id' => $forward['forward_chain_id'],
        'node_group' => (string) ($forward['node_group_key'] ?? ''),
        'client_mode' => (string) ($forward['client_mode'] ?? 'single'),
        'bond_sidecar_point_id' => (int) ($forward['bond_sidecar_point_id'] ?? 0),
        'bond_remote_host' => (string) ($forward['bond_remote_host'] ?? ''),
        'bond_remote_port' => (int) ($forward['bond_remote_port'] ?? 0),
        'bond_ingress_mode' => (string) ($forward['bond_ingress_mode'] ?? 'plain_tcp_mux'),
        'public_port' => (int) ($forward['public_port'] ?? 0),
        'cdn_host' => (string) ($forward['cdn_host'] ?? ''),
        'filter_profile' => (string) ($forward['filter_profile'] ?? 'off'),
        'sni_host' => (string) ($forward['sni_host'] ?? ''),
        'path_prefix' => (string) ($forward['path_prefix'] ?? ''),
    ]);

    if ((int) ($merged['listen_port'] ?? 0) <= 0 || trim((string) ($merged['target_host'] ?? '')) === '' || (int) ($merged['target_port'] ?? 0) <= 0) {
        throw new ApiException('invalid_forward_payload', 422, ['payload' => $payload]);
    }

    $this->revokeForwardEndpoints($forwardId, 'update');
    return $this->saveForward((int) $forward['service_id'], $forwardId, $merged, $actor);
}
```

- [ ] **Step 4: Rewire generic updateForward() to validate before revoke**

Replace:

```php
$this->revokeForwardEndpoints($forwardId, 'update');
return $this->saveForward((int) $forward['service_id'], $forwardId, $payload, $actor);
```

with:

```php
return $this->updateForwardNonStructural($forwardId, $payload, $actor);
```

only if the caller contract is now non-structural. Otherwise, keep `updateForward()` separate and move the revoke call to after a merged-validation helper. The important invariant is: **no revoke before validation**.

- [ ] **Step 5: Re-run the safe-update behavior test and verify it passes**

Run: `php master/tests/provisioning_forward_non_structural_update_test.php`  
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/tests/provisioning_forward_non_structural_update_test.php
git commit -m "fix: validate forward edits before endpoint revoke"
```

## Task 4: Lock the authenticated user API to the same non-structural contract

**Files:**
- Modify: `master/src/Controllers/ServiceController.php`
- Modify: `master/tests/provisioning_forward_non_structural_update_test.php`

- [ ] **Step 1: Extend the failing behavior test to cover API parity**

Add this assertion block to `master/tests/provisioning_forward_non_structural_update_test.php`:

```php
// After a valid non-structural update, combination and routing fields must remain unchanged.
$service->updateForwardNonStructural(9, [
    'target_host' => 'new.example.com',
    'target_port' => 443,
    'listen_port' => 5443,
    'transport_mode' => 'tcp+udp',
    'forward_combination_id' => 999,
    'endpoint_mode' => 'forward_chain',
    'client_mode' => 'bond',
], 'user');

$updated = $db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1', ['id' => 9]);
if (($updated['target_host'] ?? null) !== 'new.example.com') fail('target_host should update');
if ((int) ($updated['target_port'] ?? 0) !== 443) fail('target_port should update');
if ((int) ($updated['listen_port'] ?? 0) !== 5443) fail('listen_port should update');
if (($updated['transport_mode'] ?? null) !== 'tcp+udp') fail('transport_mode should update');
if ((int) ($updated['forward_combination_id'] ?? 0) !== 22) fail('forward_combination_id must stay locked');
if (($updated['endpoint_mode'] ?? null) !== 'slave_group') fail('endpoint_mode must stay locked');
if (($updated['client_mode'] ?? null) !== 'single') fail('client_mode must stay locked');
```

- [ ] **Step 2: Run the behavior test and verify it fails**

Run: `php master/tests/provisioning_forward_non_structural_update_test.php`  
Expected: FAIL until the updater preserves structural fields.

- [ ] **Step 3: Narrow the authenticated API update contract**

In `master/src/Controllers/ServiceController.php`, add:

```php
/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
private function normalizeForwardNonStructuralPayload(array $payload): array
{
    return [
        'listen_port' => max(0, (int) ($payload['listen_port'] ?? 0)),
        'target_host' => trim((string) ($payload['target_host'] ?? '')),
        'target_port' => max(0, (int) ($payload['target_port'] ?? 0)),
        'transport_mode' => match (strtolower(trim((string) ($payload['transport_mode'] ?? 'tcp')))) {
            'udp' => 'udp',
            'tcp+udp', 'udp+tcp', 'both' => 'tcp+udp',
            default => 'tcp',
        },
    ];
}
```

Then update `updateForward(...)` to use:

```php
$payload = $this->normalizeForwardNonStructuralPayload($request->body());
'client_config' => $this->provisioning->updateForwardNonStructural($forwardId, $payload, 'user'),
```

- [ ] **Step 4: Re-run the behavior test and verify it passes**

Run: `php master/tests/provisioning_forward_non_structural_update_test.php`  
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add master/src/Controllers/ServiceController.php master/tests/provisioning_forward_non_structural_update_test.php
git commit -m "fix: align user forward API with non-structural edit rules"
```

## Task 5: Run the full verification set

**Files:**
- Verify only

- [ ] **Step 1: Run tenant UI regression tests**

Run: `php master/tests/tenant_forward_combination_regression_test.php`  
Expected: `ok`

Run: `php master/tests/tenant_controller_regressions_test.php`  
Expected: `ok`

Run: `php master/tests/tenant_forward_edit_regression_test.php`  
Expected: `ok`

- [ ] **Step 2: Run provisioning behavior regression test**

Run: `php master/tests/provisioning_forward_non_structural_update_test.php`  
Expected: `ok`

- [ ] **Step 3: Run PHP lint on touched files**

Run: `php -l master/src/Controllers/TenantController.php`  
Expected: `No syntax errors detected`

Run: `php -l master/src/Controllers/ServiceController.php`  
Expected: `No syntax errors detected`

Run: `php -l master/src/Support/ProvisioningService.php`  
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit any final test-driven cleanups**

```bash
git add master/src/Controllers/TenantController.php master/src/Controllers/ServiceController.php master/src/Support/ProvisioningService.php master/tests/tenant_forward_combination_regression_test.php master/tests/tenant_controller_regressions_test.php master/tests/tenant_forward_edit_regression_test.php master/tests/provisioning_forward_non_structural_update_test.php
git commit -m "test: cover tenant forward combination-only edit flow"
```
