# Panel Features Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add three features: (1) tenant edit forward, (2) admin republish buttons, (3) admin diagnostics page.

**Architecture:** Custom PHP router app. All views are inline HTML strings in controllers. Routes registered in Bootstrap.php.

**Tech Stack:** PHP 8, Custom Router/Controller framework, MySQL

---

## File Map

- Modify: `master/src/Controllers/TenantController.php` - edit button, GET/POST handlers
- Modify: `master/src/Controllers/AdminController.php` - republish handlers, diagnostics page
- Modify: `master/src/Support/ProvisioningService.php` - republish wrappers with results
- Modify: `master/src/Bootstrap.php` - add 6 new routes

---

## Task 1: Add Edit Forward Button to Forward Table

**Files:**
- Modify: `master/src/Controllers/TenantController.php:1359-1367`

- [ ] **Step 1: Add Edit button before Delete in forward table actions**

Find this section around line 1359-1367:
```php
'<td>
    <div class="actions">' .
        (!empty($features['allow_delete_forwards'])
            ? '<form class="inline" method="post" action="/tenant/forwards/' . (int) $forward['id'] . '/delete">
                <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                <button type="submit" class="button danger">Delete</button>
              </form>'
            : '<span class="muted">Delete disabled by policy</span>') .
    '</div>
```

Replace with:
```php
'<td>
    <div class="actions">' .
        (!empty($features['allow_manage_forwards'])
            ? '<a class="button secondary" href="/tenant/services/' . (int) $service['id'] . '/forward/' . (int) $forward['id'] . '/edit">Edit</a>'
            : '') .
        (!empty($features['allow_delete_forwards'])
            ? '<form class="inline" method="post" action="/tenant/forwards/' . (int) $forward['id'] . '/delete">
                <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                <button type="submit" class="button danger">Delete</button>
              </form>'
            : '<span class="muted">Delete disabled by policy</span>') .
    '</div>
```

---

## Task 2: Add Edit Forward GET Handler

**Files:**
- Modify: `master/src/Controllers/TenantController.php` - add new method

- [ ] **Step 1: Add editForward method to TenantController**

Add after `createForward` method (around line 2286):
```php
public function editForward(Request $request, array $params): Response
{
    $user = SessionAuth::currentUser($this->db);
    if ($user === null || (string) $user['role'] !== 'tenant') {
        return Response::redirect('/login');
    }

    $serviceId = $this->idParam($params);
    $forwardId = $this->idParam($params, 'fid');

    $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
        'id' => $serviceId,
        'user_id' => $user['id'],
    ]);
    if ($service === null) {
        throw new ApiException('service_not_found', 404);
    }

    $forward = $this->db->fetchOne(
        'SELECT f.*, s.client_token
         FROM forwards f
         INNER JOIN services s ON s.id = f.service_id
         WHERE f.id = :id AND f.service_id = :service_id AND f.enabled = 1
         LIMIT 1',
        ['id' => $forwardId, 'service_id' => $serviceId]
    );
    if ($forward === null) {
        throw new ApiException('forward_not_found', 404);
    }

    $csrf = $this->escape(SessionAuth::csrfToken());
    $serviceIdInt = (int) $service['id'];
    $forwardIdInt = (int) $forward['id'];

    $body = '
        <div class="hero">
          <div>
            <h2>Edit Forward #' . $forwardIdInt . '</h2>
            <p class="muted">Service: ' . $this->escape((string) $service['name']) . '</p>
          </div>
          <div class="actions">
            <a class="button ghost" href="/tenant/services/' . $serviceIdInt . '">Back to Service</a>
          </div>
        </div>
        <section class="card">
          <h3>Editable Fields</h3>
          <p class="muted">Only target host, target port, path prefix, and speed limit can be edited.</p>
          <form method="post" action="/tenant/services/' . $serviceIdInt . '/forward/' . $forwardIdInt . '/edit">
            <input type="hidden" name="csrf_token" value="' . $csrf . '">
            <div class="form-grid">
              <div class="field">
                <label>Target Host</label>
                <input type="text" name="target_host" value="' . $this->escape((string) ($forward['target_host'] ?? '')) . '" required>
              </div>
              <div class="field">
                <label>Target Port</label>
                <input type="number" name="target_port" value="' . (int) ($forward['target_port'] ?? 0) . '" min="1" max="65535" required>
              </div>
              <div class="field">
                <label>Path Prefix</label>
                <input type="text" name="path_prefix" value="' . $this->escape((string) ($forward['path_prefix'] ?? '')) . '">
              </div>
              <div class="field">
                <label>Speed Limit (Mbps)</label>
                <input type="number" name="speed_limit_mbps" value="' . (int) ($forward['speed_limit_mbps'] ?? 0) . '" min="0">
              </div>
            </div>
            <div class="actions">
              <button class="button" type="submit">Save Changes</button>
            </div>
          </form>
        </section>';

    return $this->page('Edit Forward', $body, $user, $this->tenantNav('service', $serviceIdInt));
}
```

---

## Task 3: Add Edit Forward POST Handler

**Files:**
- Modify: `master/src/Controllers/TenantController.php`

- [ ] **Step 1: Add saveForwardEdit POST handler**

Add after `editForward`:
```php
public function saveForwardEdit(Request $request, array $params): Response
{
    $user = SessionAuth::currentUser($this->db);
    if ($user === null || (string) $user['role'] !== 'tenant') {
        return Response::redirect('/login');
    }

    if (!SessionAuth::validateCsrfToken($request->post('csrf_token'))) {
        throw new ApiException('csrf_invalid', 400);
    }

    $serviceId = $this->idParam($params);
    $forwardId = $this->idParam($params, 'fid');

    $forward = $this->db->fetchOne(
        'SELECT f.* FROM forwards f WHERE f.id = :id AND f.service_id = :service_id AND f.enabled = 1 LIMIT 1',
        ['id' => $forwardId, 'service_id' => $serviceId]
    );
    if ($forward === null) {
        throw new ApiException('forward_not_found', 404);
    }

    $targetHost = trim((string) $request->post('target_host'));
    $targetPort = (int) $request->post('target_port');
    $pathPrefix = trim((string) $request->post('path_prefix'));
    $speedLimitMbp = max(0, (int) $request->post('speed_limit_mbps'));

    if ($targetHost === '') {
        throw new ApiException('target_host_required', 400);
    }
    if ($targetPort < 1 || $targetPort > 65535) {
        throw new ApiException('target_port_invalid', 400);
    }

    $payload = [
        'target_host' => $targetHost,
        'target_port' => $targetPort,
        'path_prefix' => $pathPrefix,
        'speed_limit_mbps' => $speedLimitMbp,
        'client_mode' => (string) $forward['client_mode'],
        'endpoint_mode' => (string) $forward['endpoint_mode'],
        'transport_mode' => (string) $forward['transport_mode'],
        'listen_port' => (int) ($forward['listen_port'] ?? 0),
        'node_group' => (string) ($forward['node_group'] ?? ''),
        'line_client_id' => (int) ($forward['line_client_id'] ?? 0),
        'line_client_group_ids' => [],
        'forward_chain_id' => (int) ($forward['forward_chain_id'] ?? 0),
        'bond_sidecar_point_id' => (int) ($forward['bond_sidecar_point_id'] ?? 0),
        'bond_remote_host' => (string) ($forward['bond_remote_host'] ?? ''),
        'bond_remote_port' => (int) ($forward['bond_remote_port'] ?? 0),
        'filter_profile' => (string) ($forward['filter_profile'] ?? 'off'),
    ];

    $this->provisioning->updateForward($forwardId, $payload, 'user:' . $user['id']);

    $_SESSION['flash'] = ['success' => 'Forward updated successfully.'];
    return Response::redirect('/tenant/services/' . $serviceId);
}
```

---

## Task 4: Register Edit Forward Routes

**Files:**
- Modify: `master/src/Bootstrap.php`

- [ ] **Step 1: Add routes for edit forward**

Add after line 170 (after `regenerate-client-token` route):
```php
$this->router->add('GET', '/tenant/services/{id}/forward/{fid}/edit', [$tenant, 'editForward']);
$this->router->add('POST', '/tenant/services/{id}/forward/{fid}/edit', [$tenant, 'saveForwardEdit']);
```

---

## Task 5: Add Flash Message Support

**Files:**
- Modify: `master/src/Controllers/BaseController.php`

- [ ] **Step 1: Check and add flash support**

Find the `page()` method. Before the closing `</body>` tag in the HTML, add flash handling:
```php
// In page() method, before returning Response::html:
// Check for flash message
$flashHtml = '';
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $flashClass = !empty($flash['success']) ? 'flash success' : (empty($flash['error']) ? 'flash' : 'flash danger');
    $flashMsg = !empty($flash['success']) ? $flash['success'] : ($flash['error'] ?? '');
    if ($flashMsg !== '') {
        $flashHtml = '<div class="' . $flashClass . '" style="margin:16px;">' . $this->escape($flashMsg) . '</div>';
    }
}
// Insert $flashHtml before first </section> or </div> in body
if ($flashHtml !== '') {
    $body = preg_replace('/(<section|<div [^>]*>)/', '$1' . $flashHtml, $body, 1);
}
```

---

## Task 6: Add Republish Wrapper Methods

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`

- [ ] **Step 1: Add republish wrapper methods**

Add after existing republish methods (around line 3160):
```php
/**
 * @return array{republished: int, errors: string[]}
 */
public function republishForwardsForServiceWithResult(int $serviceId): array
{
    $errors = [];
    $count = 0;
    if ($serviceId <= 0) {
        return ['republished' => 0, 'errors' => $errors];
    }
    try {
        $this->republishActiveForwardsForService($serviceId);
        $rows = $this->db->fetchAll(
            'SELECT f.id FROM forwards f WHERE f.enabled = 1 AND f.service_id = :service_id',
            ['service_id' => $serviceId]
        );
        $count = count($rows);
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
    return ['republished' => $count, 'errors' => $errors];
}

/**
 * @return array{republished: int, errors: string[]}
 */
public function republishForwardsForNodeWithResult(int $nodeId): array
{
    $errors = [];
    $count = 0;
    if ($nodeId <= 0) {
        return ['republished' => 0, 'errors' => $errors];
    }
    try {
        $this->republishActiveForwardsForNode($nodeId);
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT f.id FROM forwards f
             INNER JOIN forward_endpoints fe ON fe.forward_id = f.id
             WHERE f.enabled = 1 AND fe.node_id = :node_id',
            ['node_id' => $nodeId]
        );
        $count = count($rows);
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
    return ['republished' => $count, 'errors' => $errors];
}

/**
 * @return array{republished: int, errors: string[]}
 */
public function republishForwardsForClientWithResult(int $clientId): array
{
    $errors = [];
    $count = 0;
    if ($clientId <= 0) {
        return ['republished' => 0, 'errors' => $errors];
    }
    try {
        $this->republishActiveForwardsForClient($clientId);
        $rows = $this->db->fetchAll(
            'SELECT f.id FROM forwards f WHERE f.enabled = 1 AND f.line_client_id = :client_id',
            ['client_id' => $clientId]
        );
        $count = count($rows);
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
    return ['republished' => $count, 'errors' => $errors];
}

/**
 * @return array{republished: int, errors: string[]}
 */
public function republishAllForwardsWithResult(): array
{
    $errors = [];
    $count = 0;
    try {
        $rows = $this->db->fetchAll(
            'SELECT f.id FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE f.enabled = 1 AND s.status = :status',
            ['status' => 'active']
        );
        foreach ($rows as $row) {
            try {
                $this->reconcileForwardEndpoints((int) ($row['id'] ?? 0));
                $count++;
            } catch (\Throwable $e) {
                $errors[] = 'Forward ' . ($row['id'] ?? '?') . ': ' . $e->getMessage();
            }
        }
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
    return ['republished' => $count, 'errors' => $errors];
}
```

- [ ] **Step 2: Add republishActiveForwardsForNode if missing**

Search for it. If missing, add:
```php
public function republishActiveForwardsForNode(int $nodeId): void
{
    if ($nodeId <= 0) {
        return;
    }
    $rows = $this->db->fetchAll(
        'SELECT DISTINCT f.id AS forward_id
         FROM forwards f
         INNER JOIN forward_endpoints fe ON fe.forward_id = f.id
         INNER JOIN services s ON s.id = f.service_id
         WHERE f.enabled = 1 AND fe.node_id = :node_id AND s.status = :service_status',
        ['node_id' => $nodeId, 'service_status' => 'active']
    );
    foreach ($rows as $row) {
        $this->reconcileForwardEndpoints((int) ($row['forward_id'] ?? 0));
    }
}
```

---

## Task 7: Add Republish Handlers to AdminController

**Files:**
- Modify: `master/src/Controllers/AdminController.php`

- [ ] **Step 1: Add republish handler methods**

Add after existing methods:
```php
public function republishForService(Request $request, array $params): Response
{
    $this->requireAdmin();
    if (!SessionAuth::validateCsrfToken($request->post('csrf_token'))) {
        return Response::json(400, ['error' => 'csrf_invalid']);
    }
    $serviceId = $this->idParam($params);
    $result = $this->provisioning->republishForwardsForServiceWithResult($serviceId);
    $_SESSION['flash'] = empty($result['errors'])
        ? ['success' => 'Republished ' . $result['republished'] . ' forwards.']
        : ['error' => 'Republished ' . $result['republished'] . ' forwards. Errors: ' . implode('; ', $result['errors'])];
    return Response::redirect('/admin/services');
}

public function republishForNode(Request $request, array $params): Response
{
    $this->requireAdmin();
    if (!SessionAuth::validateCsrfToken($request->post('csrf_token'))) {
        return Response::json(400, ['error' => 'csrf_invalid']);
    }
    $nodeId = $this->idParam($params);
    $result = $this->provisioning->republishForwardsForNodeWithResult($nodeId);
    $_SESSION['flash'] = empty($result['errors'])
        ? ['success' => 'Republished ' . $result['republished'] . ' forwards.']
        : ['error' => 'Republished ' . $result['republished'] . ' forwards. Errors: ' . implode('; ', $result['errors'])];
    return Response::redirect('/admin/nodes');
}

public function republishForClient(Request $request, array $params): Response
{
    $this->requireAdmin();
    if (!SessionAuth::validateCsrfToken($request->post('csrf_token'))) {
        return Response::json(400, ['error' => 'csrf_invalid']);
    }
    $clientId = $this->idParam($params);
    $result = $this->provisioning->republishForwardsForClientWithResult($clientId);
    $_SESSION['flash'] = empty($result['errors'])
        ? ['success' => 'Republished ' . $result['republished'] . ' forwards.']
        : ['error' => 'Republished ' . $result['republished'] . ' forwards. Errors: ' . implode('; ', $result['errors'])];
    return Response::redirect('/admin/clients');
}

public function republishAll(Request $request, array $params): Response
{
    $this->requireAdmin();
    if (!SessionAuth::validateCsrfToken($request->post('csrf_token'))) {
        return Response::json(400, ['error' => 'csrf_invalid']);
    }
    $result = $this->provisioning->republishAllForwardsWithResult();
    $_SESSION['flash'] = empty($result['errors'])
        ? ['success' => 'Republished ' . $result['republished'] . ' forwards system-wide.']
        : ['error' => 'Republished ' . $result['republished'] . ' forwards. Errors: ' . implode('; ', $result['errors'])];
    return Response::redirect('/admin');
}
```

- [ ] **Step 2: Add requireAdmin helper**

Add to BaseController:
```php
protected function requireAdmin(): void
{
    $user = SessionAuth::currentUser($this->db);
    if ($user === null || (string) $user['role'] !== 'admin') {
        throw new ApiException('admin_required', 403);
    }
}
```

---

## Task 8: Register Republish Routes

**Files:**
- Modify: `master/src/Bootstrap.php`

- [ ] **Step 1: Add republish routes**

Add around line 143 (after existing admin routes):
```php
$this->router->add('POST', '/admin/republish/service/{id}', [$admin, 'republishForService']);
$this->router->add('POST', '/admin/republish/node/{id}', [$admin, 'republishForNode']);
$this->router->add('POST', '/admin/republish/client/{id}', [$admin, 'republishForClient']);
$this->router->add('POST', '/admin/republish/all', [$admin, 'republishAll']);
```

---

## Task 9: Add Republish Buttons to Admin Pages

**Files:**
- Modify: `master/src/Controllers/AdminController.php`

- [ ] **Step 1: Add Republish button to Services page**

In `servicesPage`, find the services table. Add an Actions column header and a Republish button in each row.

- [ ] **Step 2: Add Republish button to Nodes page**

In `nodesPage`, add Republish button to each node row.

- [ ] **Step 3: Add Republish button to Clients page**

In `clientsPage`, add Republish button to each client row.

---

## Task 10: Add Global Republish to Dashboard

**Files:**
- Modify: `master/src/Controllers/AdminController.php`

- [ ] **Step 1: Add Republish All button to dashboard**

In `dashboard` method, add near the top:
```php
'<form class="inline" method="post" action="/admin/republish/all" style="margin-bottom:16px;">
    <input type="hidden" name="csrf_token" value="' . $csrf . '">
    <button type="submit" class="button danger" onclick="return confirm(\'Republish all forwards?\');">Republish All Forwards</button>
</form>'
```

---

## Task 11: Create Diagnostics Page

**Files:**
- Modify: `master/src/Controllers/AdminController.php`

- [ ] **Step 1: Add diagnosticsPage method**

Add new method with health summary, forward health table, node health table, and recent errors.

---

## Task 12: Register Diagnostics Route

**Files:**
- Modify: `master/src/Bootstrap.php`

- [ ] **Step 1: Add diagnostics route**

```php
$this->router->add('GET', '/admin/diagnostics', [$admin, 'diagnosticsPage']);
```