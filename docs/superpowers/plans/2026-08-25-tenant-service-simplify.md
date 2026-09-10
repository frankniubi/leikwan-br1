# Tenant Service Detail Simplification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Simplify the tenant `/tenant/services/{id}` page — hide the raw client config/token, collapse Service Info to a compact header, add Start/Pause/Modify/Delete actions and a per-forward "this-cycle usage" column.

**Architecture:** Two new read methods and one new mutation method on `ProvisioningService`, a new POST toggle route, and a rewrite of `TenantController::serviceDetail` (the only tenant-facing consumer). Per-forward usage is derived by joining `traffic_usage_hourly` through `service_endpoints.forward_id`; paused forwards are fetched separately so a Pause'd forward stays visible with a Start button.

**Tech Stack:** PHP 8 (panel), MySQL, source-contract regression tests (`php tests/xxx.php`).

**Spec:** `docs/superpowers/specs/2026-08-25-tenant-service-simplify-design.md`

---

## File Structure

- `master/src/Support/ProvisioningService.php` — add `setForwardEnabled`, `forwardCycleUsageMap`, `pausedForwardsForService`.
- `master/src/Controllers/TenantController.php` — rewrite `serviceDetail`; add `toggleForward`.
- `master/src/Bootstrap.php` — add the `/toggle` route.
- `master/tests/tenant_forward_toggle_and_usage_regression_test.php` — new source-contract test.

## Task 1: `setForwardEnabled` toggle method

**Files:**
- Modify: `master/src/Support/ProvisioningService.php` (insert after `deleteForward`, which ends ~line 4942)
- Test: `master/tests/tenant_forward_toggle_and_usage_regression_test.php`

- [ ] **Step 1: Write the failing regression test**

Create `master/tests/tenant_forward_toggle_and_usage_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$source = $provisioning . $tenant . $bootstrap;

foreach ([
    "public function setForwardEnabled(",
    "public function forwardCycleUsageMap(",
    "public function pausedForwardsForService(",
    "'forward.paused'",
    "'forward.started'",
    "revokeForwardEndpoints(\$forwardId, 'pause')",
    "reconcileForwardEndpoints(\$forwardId)",
    "traffic_usage_hourly",
    "service_endpoints se ON se.id = tuh.endpoint_id",
    "period_start >= :cycle_started_at",
    "balance_suspended_at IS NULL",
    "/tenant/services/{id}/forward/{fid}/toggle",
    "public function toggleForward(Request \$request, array \$params): Response",
    "\$action === 'start'",
    "setForwardEnabled(",
    "本期用量",
    "formatTrafficBytes",
    "'Pause'",
    "'Start'",
    ">Modify<",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing snippet: ' . $snippet);
    }
}

foreach ([
    'View client config',
    "escape((string) \$service['client_token'])",
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('stale snippet still present: ' . $forbidden);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php master/tests/tenant_forward_toggle_and_usage_regression_test.php`
Expected: FAIL with `missing snippet: public function setForwardEnabled(`.

- [ ] **Step 3: Add `setForwardEnabled` to ProvisioningService**

Insert after `deleteForward()` (which ends just before `reactivateEligibleForwardsForUser` at ~line 4944):

```php
    /**
     * Enable or pause a forward. Pause revokes its endpoints so the relay
     * nodes stop carrying traffic; start reconciles them back. Distinct from
     * delete (which sets user_deleted_at) and from balance suspension.
     *
     * @return array<string, mixed>
     */
    public function setForwardEnabled(
        int $forwardId,
        bool $enabled,
        string $actor = 'user',
        ?int $expectedRevision = null,
        ?array $mutationPolicy = null
    ): array {
        return $this->db->transaction(function () use ($forwardId, $enabled, $actor, $expectedRevision, $mutationPolicy): array {
            $forward = $this->db->fetchOne('SELECT * FROM forwards WHERE id = :id LIMIT 1 FOR UPDATE', ['id' => $forwardId]);
            if ($forward === null) {
                throw new ApiException('forward_not_found', 404);
            }
            $this->lockForwardMutationService((int) $forward['service_id'], $mutationPolicy, 'forward_not_found');
            $this->assertExpectedForwardRevision($forward, $expectedRevision);
            $nextRevision = max(1, (int) ($forward['api_revision'] ?? 1)) + 1;
            $now = $this->now();

            if ($enabled) {
                $this->db->execute(
                    'UPDATE forwards
                     SET enabled = 1,
                         balance_suspended_at = NULL,
                         api_revision = :api_revision,
                         updated_at = :updated_at
                     WHERE id = :id',
                    ['id' => $forwardId, 'api_revision' => $nextRevision, 'updated_at' => $now]
                );
                $this->audit('forward.started', [
                    'forward_id' => $forwardId,
                    'service_id' => (int) ($forward['service_id'] ?? 0),
                    'actor' => $actor,
                    'revision' => $nextRevision,
                ]);
                $this->reconcileForwardEndpoints($forwardId);
            } else {
                $this->revokeForwardEndpoints($forwardId, 'pause');
                $this->db->execute(
                    'UPDATE forwards
                     SET enabled = 0,
                         balance_suspended_at = NULL,
                         api_revision = :api_revision,
                         updated_at = :updated_at
                     WHERE id = :id',
                    ['id' => $forwardId, 'api_revision' => $nextRevision, 'updated_at' => $now]
                );
                $this->audit('forward.paused', [
                    'forward_id' => $forwardId,
                    'service_id' => (int) ($forward['service_id'] ?? 0),
                    'actor' => $actor,
                    'revision' => $nextRevision,
                ]);
            }

            return $this->buildClientConfig($forwardId);
        });
    }
```

- [ ] **Step 4: Add the two read methods below it**

```php
    /**
     * Per-forward traffic used within the current billing cycle, keyed by
     * forward_id. Approximation for display only; see design spec.
     *
     * @return array<int, int>
     */
    public function forwardCycleUsageMap(int $serviceId, string $cycleStartedAt): array
    {
        $map = [];
        if ($serviceId <= 0 || trim($cycleStartedAt) === '') {
            return $map;
        }
        $rows = $this->db->fetchAll(
            'SELECT se.forward_id, COALESCE(SUM(tuh.bytes_in + tuh.bytes_out), 0) AS used_bytes
             FROM traffic_usage_hourly tuh
             INNER JOIN service_endpoints se ON se.id = tuh.endpoint_id
             WHERE tuh.service_id = :service_id
               AND tuh.period_start >= :cycle_started_at
             GROUP BY se.forward_id',
            ['service_id' => $serviceId, 'cycle_started_at' => $cycleStartedAt]
        );
        foreach ($rows as $row) {
            $map[(int) $row['forward_id']] = (int) $row['used_bytes'];
        }
        return $map;
    }

    /**
     * Paused (user-disabled, not deleted, not balance-suspended) forwards for
     * the service page, so they remain visible with a Start action.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pausedForwardsForService(int $serviceId): array
    {
        if ($serviceId <= 0) {
            return [];
        }
        return $this->db->fetchAll(
            'SELECT f.*, combo.name AS forward_combination_name, combo.tenant_label AS forward_combination_label
             FROM forwards f
             LEFT JOIN forward_combinations combo ON combo.id = f.forward_combination_id
             WHERE f.service_id = :service_id
               AND f.enabled = 0
               AND f.user_deleted_at IS NULL
               AND f.balance_suspended_at IS NULL
             ORDER BY f.id ASC',
            ['service_id' => $serviceId]
        );
    }
```

- [ ] **Step 5: Run test to verify the ProvisioningService snippets pass**

Run: `php master/tests/tenant_forward_toggle_and_usage_regression_test.php`
Expected: FAIL only on the route/controller/UI snippets (Task 3/4), but NOT on the ProvisioningService snippets.

- [ ] **Step 6: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/tests/tenant_forward_toggle_and_usage_regression_test.php
git commit -m "feat(provisioning): add setForwardEnabled + per-forward usage + paused list"
```

## Task 2: `/toggle` route and controller method

**Files:**
- Modify: `master/src/Bootstrap.php` (near the other tenant forward routes, ~line 296)
- Modify: `master/src/Controllers/TenantController.php` (after `deleteForward`, ~line 2617)

- [ ] **Step 1: Add the route**

In `master/src/Bootstrap.php`, after the `deleteForward` route line
`$this->router->add('POST', '/tenant/forwards/{id}/delete', [$tenant, 'deleteForward']);`
add:

```php
        $this->router->add('POST', '/tenant/services/{id}/forward/{fid}/toggle', [$tenant, 'toggleForward']);
```

- [ ] **Step 2: Add `toggleForward` to TenantController**

Insert after `deleteForward()` (which ends ~line 2617):

```php
    public function toggleForward(Request $request, array $params): Response
    {
        $serviceId = $this->idParam($params);
        $forwardId = (int) ($params['fid'] ?? 0);
        $action = strtolower(trim((string) $request->input('action', 'pause')));

        try {
            $user = SessionAuth::currentUser($this->db);
            if ($user === null || (string) $user['role'] !== 'tenant') {
                return Response::redirect('/login');
            }
            SessionAuth::validateCsrf($request);
            if ($forwardId <= 0 || !in_array($action, ['start', 'pause'], true)) {
                throw new ApiException('invalid_forward_action', 422);
            }
            $forward = $this->db->fetchOne(
                'SELECT f.*, s.user_id, s.status AS service_status
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.id = :id AND f.service_id = :service_id AND f.user_deleted_at IS NULL LIMIT 1',
                ['id' => $forwardId, 'service_id' => $serviceId]
            );
            if ($forward === null || (int) $forward['user_id'] !== (int) $user['id']) {
                throw new ApiException('forward_not_found', 404);
            }
            $this->assertTenantServiceActive($forward);
            $this->provisioning->setForwardEnabled(
                $forwardId,
                $action === 'start',
                'tenant:' . (int) $user['id'],
                (int) ($forward['api_revision'] ?? 1),
                ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
            );
            SessionAuth::flash('success', $action === 'start' ? 'Forward started.' : 'Forward paused.');
            return Response::redirect('/tenant/services/' . $serviceId);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->forwardCreateFlashMessage($throwable));
        }

        return Response::redirect('/tenant/services/' . $serviceId);
    }
```

- [ ] **Step 3: Run test to verify route + controller snippets pass**

Run: `php master/tests/tenant_forward_toggle_and_usage_regression_test.php`
Expected: FAIL only on the UI snippets (`本期用量`, `>Pause<`, etc. — Task 4).

- [ ] **Step 4: Commit**

```bash
git add master/src/Bootstrap.php master/src/Controllers/TenantController.php
git commit -m "feat(tenant): add forward start/pause toggle endpoint"
```

## Task 3: Rewrite `serviceDetail`

**Files:**
- Modify: `master/src/Controllers/TenantController.php` (`serviceDetail`, lines 1643-2006)

- [ ] **Step 1: Merge paused forwards + build usage map**

In `serviceDetail`, immediately after `$serviceIsActive = (string) ($service['status'] ?? '') === 'active';` (line 1684), insert:

```php
        $pausedForwards = $this->provisioning->pausedForwardsForService($serviceId);
        $forwardRows = array_merge($summary['forwards'], $pausedForwards);
        usort($forwardRows, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $cycleStart = (string) (($summary['billing']['cycle_started_at'] ?? '') ?: ($service['created_at'] ?? ''));
        $usageMap = $this->provisioning->forwardCycleUsageMap($serviceId, $cycleStart);
        $includedBytes = max(0, (int) (($summary['billing']['included_bytes'] ?? 0) ?: 0));
        $csrf = $this->escape(SessionAuth::csrfToken());
```

- [ ] **Step 2: Replace the `$rows` building loop**

Replace the entire block from `$rows = '';` (line 1681) through the closing of the `foreach` loop and the `if ($rows === '')` guard (currently ends ~line 1764) with:

```php
        $rows = '';
        foreach ($forwardRows as $forward) {
            $isRunning = (int) ($forward['enabled'] ?? 0) === 1;
            $statusBadge = $isRunning
                ? '<span class="badge success">运行</span>'
                : '<span class="badge info">已暂停</span>';

            $usedBytes = (int) ($usageMap[(int) $forward['id']] ?? 0);
            $usedLabel = $this->formatTrafficBytes($usedBytes);
            $pct = $includedBytes > 0 ? min(100, (int) round($usedBytes / $includedBytes * 100)) : 0;
            $usageCell = '<div style="display:flex;align-items:center;gap:8px;min-width:120px;">'
                . '<div style="flex:1;height:6px;background:#e5e7eb;border-radius:3px;overflow:hidden;">'
                . '<div style="width:' . $pct . '%;height:100%;background:#2563eb;"></div></div>'
                . '<span style="font-size:12px;white-space:nowrap;">' . $usedLabel . '</span></div>';

            $endpointHtml = '';
            $activeEndpointCount = 0;
            foreach (($forward['endpoints'] ?? []) as $endpoint) {
                $healthClass = ((string) $endpoint['health_status'] === 'active') ? 'success' : 'info';
                if ((string) $endpoint['health_status'] === 'active') {
                    $activeEndpointCount++;
                }
                $endpointHtml .= '<div><span class="badge ' . $healthClass . '">' . $this->escape((string) $endpoint['health_status']) . '</span> '
                    . $this->escape((string) ($endpoint['node_name'] ?? $endpoint['node'] ?? 'Slave endpoint')) . '</div>';
            }
            if ($endpointHtml === '') {
                $endpointHtml = $isRunning
                    ? '<div class="muted">No endpoint assigned yet</div>'
                    : '<div class="muted">Paused — endpoints released</div>';
            } else {
                $endpointHtml = '<div class="muted">Total: ' . count($forward['endpoints']) . ' | Active: ' . $activeEndpointCount . '</div>' . $endpointHtml;
            }

            $targetCell = $this->escape((string) $forward['target_host']) . ':' . (int) $forward['target_port'];
            if (in_array($this->canonicalClientMode((string) ($forward['client_mode'] ?? 'single')), ['bond', 'mptcp'], true) && !empty($forward['bond_remote_host'])) {
                if (!empty($forward['bond_sidecar_point_name'])) {
                    $targetCell .= '<div class="muted">point ' . $this->escape((string) $forward['bond_sidecar_point_name']) . '</div>';
                }
                $targetCell .= '<div class="muted">target transport ' . $this->escape((string) $forward['bond_remote_host']) . ':' . (int) ($forward['bond_remote_port'] ?? 0) . '</div>';
            }

            $actionsHtml = '';
            if (!empty($features['allow_manage_forwards'])) {
                $toggleAction = $isRunning ? 'pause' : 'start';
                $toggleLabel = $isRunning ? 'Pause' : 'Start';
                $toggleClass = $isRunning ? 'secondary' : '';
                $actionsHtml .= '<form class="inline" method="post" action="/tenant/services/' . $serviceId . '/forward/' . (int) $forward['id'] . '/toggle">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<input type="hidden" name="action" value="' . $toggleAction . '">'
                    . '<button type="submit" class="button ' . $toggleClass . '">' . $toggleLabel . '</button>'
                    . '</form>';
                $actionsHtml .= '<a class="button ghost" href="/tenant/services/' . $serviceId . '/forward/' . (int) $forward['id'] . '/edit">Modify</a>';
            } else {
                $actionsHtml .= '<span class="muted">Manage disabled by policy</span>';
            }
            if (!empty($features['allow_delete_forwards'])) {
                $actionsHtml .= '<form class="inline" method="post" action="/tenant/forwards/' . (int) $forward['id'] . '/delete">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<button type="submit" class="button danger">Delete</button>'
                    . '</form>';
            } else {
                $actionsHtml .= '<span class="muted">Delete disabled by policy</span>';
            }

            $rows .= '<tr>
                <td>' . (int) $forward['id'] . '</td>
                <td>' . (int) $forward['listen_port'] . '</td>
                <td>' . $this->escape((string) ($forward['forward_combination_name'] ?? '')) . '</td>
                <td>' . $targetCell . '</td>
                <td>' . $this->escape($this->transportModeLabel((string) $forward['transport_mode'])) . '</td>
                <td>' . $usageCell . '</td>
                <td>' . $statusBadge . '</td>
                <td>' . $endpointHtml . '</td>
                <td><div class="actions">' . $actionsHtml . '</div></td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="9" class="muted">No forward rules yet. Create your first forward from the button above.</td></tr>';
        }
```

Note: this also removes the old `$configPreview`/`$configJson` computation, the `$runtimeManagementHtml` hint, and the "View client config" `<details>` (all were inside the replaced block).

- [ ] **Step 3: Update the table header**

Replace the `<thead>` line (currently ~line 1997):

```html
                    <tr><th>ID</th><th>Local Listen</th><th>Forward Combination</th><th>Target</th><th>Transport</th><th>Endpoint Status</th><th>Actions</th></tr>
```

with:

```html
                    <tr><th>ID</th><th>Local Listen</th><th>Forward Combination</th><th>Target</th><th>Transport</th><th>本期用量</th><th>Status</th><th>Endpoint Status</th><th>Actions</th></tr>
```

- [ ] **Step 4: Simplify the Service Info card**

Replace the entire `<section class="card">` for Service Info (from the `<h3>Service Info</h3>` line ~1897 through its closing `</section>` at line 1979) with:

```html
              <section class="card">
                <h3>Service Info</h3>
                <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center;">
                  <div><div class="muted">Plan</div><strong>' . $this->escape((string) ($summary['plan_name'] ?? '')) . '</strong></div>
                  <div><div class="muted">Expires</div><strong>' . $this->escape((string) $service['expire_at']) . '</strong></div>
                  <div><div class="muted">Bandwidth</div><strong>' . (int) $service['bandwidth_limit_mbps'] . ' Mbps</strong></div>
                  <div style="flex:1;min-width:220px;">
                    <div class="muted">本期已用流量</div>
                    <div style="display:flex;align-items:center;gap:8px;">
                      <div style="flex:1;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
                        <div style="width:' . (($includedBytes > 0) ? min(100, (int) round(((int) ($billing['current_cycle_used_bytes'] ?? 0)) / $includedBytes * 100)) : 0) . '%;height:100%;background:#2563eb;"></div>
                      </div>
                      <span style="font-size:12px;white-space:nowrap;">' . $this->formatTrafficBytes((int) ($billing['current_cycle_used_bytes'] ?? 0)) . ' / ' . $this->formatTrafficBytes($includedBytes) . '</span>
                    </div>
                  </div>
                </div>
                <details style="margin-top:16px;">
                  <summary>更多详情</summary>
                  <p class="muted">WHMCS Service ID: ' . $this->escape((string) $service['whmcs_service_id']) . '</p>
                  <p class="muted">Allowed Client Groups: ' . $this->escape(implode(', ', array_map(static fn(array $group): string => (string) $group['name'], $allowedClientGroups))) . '</p>
                  <p class="muted">Traffic Policy: ' . (((string) ($billing['mode'] ?? 'monthly_bundle') === 'monthly_bundle') ? ((int) ($billing['included_traffic_gb'] ?? 0) . ' GB restored every billing month') : 'metered by monthly cycle') . '</p>
                  <p class="muted">Lifetime Used Traffic: ' . round(((int) $service['traffic_used_bytes']) / 1024 / 1024 / 1024, 2) . ' GB</p>
                  <div class="flash success" style="margin-top:16px;">
                    <strong>Flexible Package</strong><br>
                    Base Bandwidth: ' . (int) ($billing['base_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps']) . ' Mbps<br>
                    Current Bandwidth: ' . (int) ($billing['current_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps']) . ' Mbps<br>
                    Bandwidth Add-on: +' . (int) ($billing['bandwidth_step_mbps'] ?? 0) . ' Mbps => ' . $this->escape((string) ($billing['bandwidth_step_price_formatted'] ?? 'CNY 0.00')) . ' each month<br>
                    Current Extra Bandwidth: ' . (int) ($billing['extra_bandwidth_mbps'] ?? 0) . ' Mbps (' . (int) ($billing['extra_bandwidth_steps'] ?? 0) . ' step)<br>
                    Current Bandwidth Add-on: ' . $this->escape((string) ($billing['bandwidth_addon_formatted'] ?? 'CNY 0.00')) . '<br>
                    Included Clients: ' . (int) ($billing['included_client_count'] ?? 0) . '<br>
                    Current Explicit Clients In Use: ' . (int) ($billing['active_client_count'] ?? 0) . '<br>
                    Extra Client Charge: ' . $this->escape((string) ($billing['extra_client_price_formatted'] ?? 'CNY 0.00')) . ' each month<br>
                    Current Extra Client Charge: ' . $this->escape((string) ($billing['client_addon_formatted'] ?? 'CNY 0.00')) . '
                  </div>
                  <form method="post" action="/tenant/services/' . (int) $service['id'] . '/flexible-options" style="margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="' . $csrf . '">
                    <div class="form-grid">
                      <div class="field">
                        <label>Bandwidth Mbps</label>
                        <input type="number" min="' . max(1, (int) ($billing['base_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps'])) . '" name="bandwidth_limit_mbps" value="' . (int) ($billing['current_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps']) . '" required>
                        <p class="muted">If this package uses bandwidth steps, keep the value aligned with the configured step size.</p>
                      </div>
                    </div>
                    <div class="actions">
                      <button class="button secondary" type="submit">Update Flexible Options</button>
                    </div>
                  </form>
                  <div class="flash success" style="margin-top:16px;">
                    <strong>Billing This Cycle</strong><br>
                    Mode: ' . $this->escape((string) ($billing['mode_label'] ?? 'Monthly Bundle')) . '<br>
                    Base Fee: ' . $this->escape((string) ($billing['base_fee_formatted'] ?? 'CNY 0.00')) . '<br>
                    Traffic Charge: ' . $this->escape((string) ($billing['traffic_charge_formatted'] ?? 'CNY 0.00')) . '<br>
                    Bandwidth Add-on: ' . $this->escape((string) ($billing['bandwidth_addon_formatted'] ?? 'CNY 0.00')) . '<br>
                    Client Add-on: ' . $this->escape((string) ($billing['client_addon_formatted'] ?? 'CNY 0.00')) . '<br>
                    ' . (((string) ($billing['mode'] ?? 'monthly_bundle')) === 'monthly_bundle'
                      ? 'Included Traffic: ' . (int) ($billing['included_traffic_gb'] ?? 0) . ' GB / month<br>Remaining Included Traffic: ' . $this->escape((string) (($billing['remaining_included_gb'] ?? 0))) . ' GB<br>'
                      : 'Usage Price: ' . $this->escape((string) ($billing['traffic_price_per_gb_formatted'] ?? 'CNY 0.0000 / GB')) . '<br>Billable Traffic: ' . $this->escape((string) ($billing['billable_gb'] ?? 0)) . ' GB<br>') . '
                    Current Estimate: ' . $this->escape((string) ($billing['estimated_total_formatted'] ?? 'CNY 0.00')) . '<br>
                    Billing Cycle: ' . $this->escape((string) ($billing['cycle_started_at'] ?? '')) . ' -> ' . $this->escape((string) ($billing['cycle_ends_at'] ?? '')) . '
                  </div>
                  <div class="flash success" style="margin-top:16px;">
                    <strong>Traffic Reset</strong><br>
                    Reset Price: ' . $this->escape($trafficResetCurrency) . ' ' . $this->escape(number_format($trafficResetPrice, 2)) . '<br>
                    This action resets <code>Current Cycle Used Traffic</code> back to 0 for this service. Lifetime traffic stays unchanged.
                  </div>
                  <form method="post" action="/tenant/services/' . (int) $service['id'] . '/reset-traffic" style="margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="' . $csrf . '">
                    <div class="actions">
                      <button class="button secondary" type="submit">Reset Traffic</button>
                    </div>
                  </form>
                  <div class="table-wrap" style="margin-top:16px;">
                    <table>
                      <thead><tr><th>Cycle Start</th><th>Cycle End</th><th>Mode</th><th>Usage</th><th>Total</th></tr></thead>
                      <tbody>' . $billingHistoryRows . '</tbody>
                    </table>
                  </div>
                  <h3 style="margin-top:18px;">Available Lines</h3>
                  <ul class="list">' . $availableClientHtml . '</ul>
                  <p class="muted">If there is no line here, admin still needs to create a client and authorize it through your plan.</p>
                  <div class="actions">
                    <a class="button ghost" href="/knowledgebase?thread=getting-started-first-forward-checklist">Knowledgebase</a>
                    <form class="inline" method="post" action="/tenant/services/' . (int) $service['id'] . '/regenerate-client-token">
                      <input type="hidden" name="csrf_token" value="' . $csrf . '">
                      <button class="button secondary" type="submit" ' . (empty($features['allow_rotate_client_token']) ? 'disabled' : '') . '>Rotate Client Token</button>
                    </form>
                  </div>
                </details>
              </section>
```

Note: the raw `<pre>` client_token dump and the "Fastest way to get your first forward working" flash and the "Create New Forward" action button are intentionally dropped from Service Info (Create Forward remains in the right-hand card and the hero). `$billing`, `$billingHistoryRows`, `$trafficResetCurrency`, `$trafficResetPrice`, `$availableClientHtml`, `$allowedClientGroups`, and `$csrf` are still defined earlier in the method.

- [ ] **Step 5: Remove the now-unused `$configPreview`/`$configJson` remnants if any remain**

After the Step 2 replacement, confirm there is no leftover reference to `$configJson` or `$runtimeManagementHtml` in `serviceDetail` (they were fully inside the replaced block). If a `buildClientConfig` reference remains outside the loop, leave it — it is still used by other methods.

- [ ] **Step 6: Run the regression test to verify all snippets pass**

Run: `php master/tests/tenant_forward_toggle_and_usage_regression_test.php`
Expected: `ok`

- [ ] **Step 7: Run the existing adjacent regression tests**

```bash
php master/tests/tenant_forward_edit_regression_test.php
php master/tests/tenant_forward_combination_regression_test.php
php master/tests/tenant_dashboard_service_row_regression_test.php
```

Expected: `ok` for each (these assert unchanged features).

- [ ] **Step 8: Commit**

```bash
git add master/src/Controllers/TenantController.php
git commit -m "feat(tenant): simplify service detail, add start/pause/modify/delete + per-forward usage"
```

## Task 4: Final verification

- [ ] **Step 1: Full source-contract sweep**

Run: `php master/tests/tenant_forward_toggle_and_usage_regression_test.php`
Expected: `ok`

- [ ] **Step 2: Manual sanity (optional, live)**

On a live service with running forwards: pause one → row shows 已暂停 with Start; start it → endpoints reconcile. Confirm the top Service Info shows only the four compact stats plus 更多详情.

---

## Self-Review

- **Spec coverage:** start/pause (Task 1 + 2), per-forward usage (Task 1 + 3), hide config/token (Task 3), Service Info collapse (Task 3), feature gates (`allow_manage_forwards`, `allow_delete_forwards` — Task 3), regression test (Task 1 Step 1). Covered.
- **Placeholder scan:** no TBD/TODO; every code step has full code.
- **Type consistency:** `setForwardEnabled(int, bool, string, ?int, ?array): array`, `forwardCycleUsageMap(int, string): array`, `pausedForwardsForService(int): array` — consistent across spec, plan, and controller call sites. `formatTrafficBytes` is the existing private helper in TenantController.
