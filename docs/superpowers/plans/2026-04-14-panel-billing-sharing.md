# Panel Billing, Slave Sharing & Quick-Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement 5 user-facing panel changes: remove SNI fields from forward creation, default TCP+UDP transport, MPTCP kernel warning, flexible per-node billing with auto-stop, and user slave sharing groups.

**Architecture:** Three modules (C→B→A) implemented in order. Module C modifies only `TenantController.php` HTML output. Module B adds `traffic_prices` + `service_plan_sidecar_points` tables, a `resolveTrafficPrice()` method in ProvisioningService, an hourly billing CLI script, and admin pricing UI. Module A adds `user_slave_groups` + `user_slave_group_members` tables, sharing controls on nodes, a tenant groups page, and restructured forward creation dropdown.

**Tech Stack:** PHP (custom framework, no ORM), MySQL, server-rendered HTML

**Spec:** `docs/superpowers/specs/2026-04-14-panel-billing-sharing-design.md`

---

## Task 1: Module C — Remove SNI/CDN host fields from quick create form

**Files:**
- Modify: `master/src/Controllers/TenantController.php:1742-1743`

- [ ] **Step 1: Remove sni_host field from quickCreateForward()**

In `master/src/Controllers/TenantController.php`, delete the SNI Host field at line 1742:

```php
// DELETE this entire line:
                    <div class="field"><label>SNI Host</label><input type="text" name="sni_host" value="' . $this->escape((string) $this->config->get('DEFAULT_CDN_HOST', 'edge.example.com')) . '"><p class="field-note">From config default.</p></div>
```

- [ ] **Step 2: Remove cdn_host field from quickCreateForward()**

Delete the CDN Host field at line 1743 (now line 1742 after step 1):

```php
// DELETE this entire line:
                    <div class="field"><label>CDN Host</label><input type="text" name="cdn_host" value="' . $this->escape((string) $this->config->get('DEFAULT_CDN_HOST', 'edge.example.com')) . '"><p class="field-note">From config default.</p></div>
```

- [ ] **Step 3: Remove sni_host and cdn_host defaults from forwardWizard()**

In `forwardWizard()` around line 1816-1817, change the `$data` array entries so they don't appear in wizard hidden fields. Remove these two lines from the `$data` array:

```php
// DELETE these two lines from the $data = [...] array:
            'sni_host' => (string) $request->input('sni_host', $this->config->get('DEFAULT_CDN_HOST', 'edge.example.com')),
            'cdn_host' => (string) $request->input('cdn_host', $this->config->get('DEFAULT_CDN_HOST', 'edge.example.com')),
```

- [ ] **Step 4: Verify normalizeTenantForwardPayload still works**

Check that `normalizeTenantForwardPayload()` does not require `sni_host`/`cdn_host` in the payload. It does not — those keys simply won't be present, and `ProvisioningService::saveForward()` will use the config defaults. No changes needed.

- [ ] **Step 5: Commit**

```bash
git add master/src/Controllers/TenantController.php
git commit -m "fix(panel): remove SNI/CDN host fields from forward creation forms

These are infrastructure concerns, not user-configurable. Backend
continues to use config defaults when fields are absent from POST data."
```

---

## Task 2: Module C — Default transport mode to TCP+UDP

**Files:**
- Modify: `master/src/Controllers/TenantController.php:1735` and `:1814`

- [ ] **Step 1: Change default in quickCreateForward()**

In `quickCreateForward()`, change the transport select from:

```php
                        <option value="tcp" selected>TCP</option>
                        <option value="tcp+udp">TCP + UDP</option>
```

to:

```php
                        <option value="tcp">TCP</option>
                        <option value="tcp+udp" selected>TCP + UDP</option>
```

- [ ] **Step 2: Change default in forwardWizard()**

In `forwardWizard()`, change the default value in the `$data` array from:

```php
            'transport_mode' => (string) $request->input('transport_mode', 'tcp'),
```

to:

```php
            'transport_mode' => (string) $request->input('transport_mode', 'tcp+udp'),
```

- [ ] **Step 3: Commit**

```bash
git add master/src/Controllers/TenantController.php
git commit -m "fix(panel): default new forwards to TCP+UDP transport mode"
```

---

## Task 3: Module C — MPTCP kernel requirement warning

**Files:**
- Modify: `master/src/Controllers/TenantController.php:1697` and `:1763-1774`

- [ ] **Step 1: Add MPTCP warning element after sidecar field**

In `quickCreateForward()`, after the `$sidecarField` variable (line 1697), add a new variable:

```php
        $mptcpWarning = '
                <p id="qcf-mptcp-warning" class="field-note" style="display:none;color:var(--danger);">
                  ⚠ MPTCP requires the client machine to run Linux with kernel 6.0 or above.
                </p>';
```

Then insert `' . $mptcpWarning . '` into the `$body` HTML, right after `' . $sidecarField . '` on line 1717 (inside the `<div class="form-grid">` but after the sidecar field):

```php
                  ' . $sidecarField . '
                  ' . $mptcpWarning . '
                </div>
```

- [ ] **Step 2: Extend JS toggle to show/hide MPTCP warning**

Replace the existing JS block (lines 1763-1775):

```php
            <script>
            (function(){
              var mode = document.getElementById("qcf-client-mode");
              var sidecar = document.getElementById("qcf-sidecar-field");
              if (mode && sidecar) {
                function toggle() {
                  sidecar.style.display = (mode.value === "bond" || mode.value === "mptcp") ? "" : "none";
                }
                mode.addEventListener("change", toggle);
                toggle();
              }
            })();
            </script>
```

with:

```php
            <script>
            (function(){
              var mode = document.getElementById("qcf-client-mode");
              var sidecar = document.getElementById("qcf-sidecar-field");
              var mptcpWarn = document.getElementById("qcf-mptcp-warning");
              function toggle() {
                var isBondOrMptcp = (mode.value === "bond" || mode.value === "mptcp");
                if (sidecar) { sidecar.style.display = isBondOrMptcp ? "" : "none"; }
                if (mptcpWarn) { mptcpWarn.style.display = (mode.value === "mptcp") ? "" : "none"; }
              }
              if (mode) {
                mode.addEventListener("change", toggle);
                toggle();
              }
            })();
            </script>
```

- [ ] **Step 3: Commit**

```bash
git add master/src/Controllers/TenantController.php
git commit -m "feat(panel): show MPTCP kernel 6.0+ requirement warning in forward creation"
```

---

## Task 4: Module B — Database migration for flexible billing

**Files:**
- Create: `master/migrations/20260414_add_flexible_billing.sql`

- [ ] **Step 1: Create migration file**

Create `master/migrations/20260414_add_flexible_billing.sql`:

```sql
-- Module B: Flexible per-node/per-sidecar traffic billing

CREATE TABLE IF NOT EXISTS traffic_prices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resource_type ENUM('slave', 'sidecar') NOT NULL,
    node_id INT NULL COMMENT 'nodes.id (slave) or sidecar_points.id (sidecar)',
    node_group_id INT NULL COMMENT 'node_groups.id (slave only)',
    price_per_gb DECIMAL(10, 4) NOT NULL DEFAULT 0.0000,
    currency VARCHAR(8) NOT NULL DEFAULT 'CNY',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS service_plan_sidecar_points (
    plan_id INT NOT NULL,
    sidecar_point_id INT NOT NULL,
    PRIMARY KEY (plan_id, sidecar_point_id)
);

ALTER TABLE users
    ADD COLUMN wallet_low_balance_threshold DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    ADD COLUMN wallet_warning_sent TINYINT(1) NOT NULL DEFAULT 0;
```

- [ ] **Step 2: Update schema.sql with new table definitions**

Add the two new table definitions to `master/migrations/schema.sql` after the existing `wallet_transactions` table section.

- [ ] **Step 3: Commit**

```bash
git add master/migrations/20260414_add_flexible_billing.sql master/migrations/schema.sql
git commit -m "feat(billing): add traffic_prices and service_plan_sidecar_points tables

Supports per-node and per-group traffic pricing with node-level override.
Adds wallet_low_balance_threshold and wallet_warning_sent to users table."
```

---

## Task 5: Module B — Price lookup logic in ProvisioningService

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`

- [ ] **Step 1: Add resolveTrafficPrice() method**

Add this public method to `ProvisioningService`, after the existing `listSidecarPointsForUser()` method (around line 465):

```php
    /**
     * Resolve the traffic price for a resource. Node-level price takes priority; falls back to group-level.
     * Returns null if no pricing is configured (free / user-owned node).
     *
     * @return array{price_per_gb: string, currency: string}|null
     */
    public function resolveTrafficPrice(int $resourceId, string $resourceType): ?array
    {
        // 1. Check node-level price
        $nodePrice = $this->db->fetchOne(
            'SELECT price_per_gb, currency FROM traffic_prices
             WHERE node_id = :node_id AND resource_type = :resource_type LIMIT 1',
            ['node_id' => $resourceId, 'resource_type' => $resourceType]
        );
        if ($nodePrice !== null) {
            return $nodePrice;
        }

        // 2. For slaves, fall back to group-level price
        if ($resourceType === 'slave') {
            $node = $this->db->fetchOne(
                'SELECT group_id FROM nodes WHERE id = :id LIMIT 1',
                ['id' => $resourceId]
            );
            if ($node !== null && (int) ($node['group_id'] ?? 0) > 0) {
                $groupPrice = $this->db->fetchOne(
                    'SELECT price_per_gb, currency FROM traffic_prices
                     WHERE node_group_id = :group_id AND resource_type = :resource_type LIMIT 1',
                    ['group_id' => (int) $node['group_id'], 'resource_type' => $resourceType]
                );
                if ($groupPrice !== null) {
                    return $groupPrice;
                }
            }
        }

        // 3. No price configured
        return null;
    }
```

- [ ] **Step 2: Add upsertTrafficPrice() method for admin saves**

```php
    /**
     * Insert or update a traffic price row. Enforces application-level uniqueness.
     */
    public function upsertTrafficPrice(string $resourceType, ?int $nodeId, ?int $nodeGroupId, string $pricePerGb, string $currency = 'CNY'): void
    {
        if ($nodeId !== null) {
            $existing = $this->db->fetchOne(
                'SELECT id FROM traffic_prices WHERE resource_type = :type AND node_id = :node_id LIMIT 1',
                ['type' => $resourceType, 'node_id' => $nodeId]
            );
        } else {
            $existing = $this->db->fetchOne(
                'SELECT id FROM traffic_prices WHERE resource_type = :type AND node_group_id = :group_id LIMIT 1',
                ['type' => $resourceType, 'group_id' => $nodeGroupId]
            );
        }

        $price = number_format((float) $pricePerGb, 4, '.', '');

        if ($existing !== null) {
            $this->db->execute(
                'UPDATE traffic_prices SET price_per_gb = :price, currency = :currency WHERE id = :id',
                ['price' => $price, 'currency' => $currency, 'id' => (int) $existing['id']]
            );
        } elseif ((float) $price > 0) {
            $this->db->insert('traffic_prices', [
                'resource_type' => $resourceType,
                'node_id' => $nodeId,
                'node_group_id' => $nodeGroupId,
                'price_per_gb' => $price,
                'currency' => $currency,
            ]);
        }
    }

    /**
     * Get the traffic price row for a specific node or group.
     * @return array{price_per_gb: string, currency: string}|null
     */
    public function getTrafficPriceFor(string $resourceType, ?int $nodeId, ?int $nodeGroupId): ?array
    {
        if ($nodeId !== null) {
            return $this->db->fetchOne(
                'SELECT price_per_gb, currency FROM traffic_prices
                 WHERE resource_type = :type AND node_id = :node_id LIMIT 1',
                ['type' => $resourceType, 'node_id' => $nodeId]
            );
        }
        if ($nodeGroupId !== null) {
            return $this->db->fetchOne(
                'SELECT price_per_gb, currency FROM traffic_prices
                 WHERE resource_type = :type AND node_group_id = :group_id LIMIT 1',
                ['type' => $resourceType, 'group_id' => $nodeGroupId]
            );
        }
        return null;
    }
```

- [ ] **Step 3: Add sidecar point plan assignment methods**

Add methods mirroring the existing `allowedNodeGroupsForPlan()` pattern:

```php
    /**
     * @return array<int, array<string, mixed>>
     */
    public function allowedSidecarPointsForPlan(int $planId): array
    {
        return $this->db->fetchAll(
            'SELECT sp.id, sp.name, sp.sidecar_host, sp.listen_port
             FROM service_plan_sidecar_points spsp
             INNER JOIN sidecar_points sp ON sp.id = spsp.sidecar_point_id
             WHERE spsp.plan_id = :plan_id
             ORDER BY sp.name ASC, sp.id ASC',
            ['plan_id' => $planId]
        );
    }
```

And update `savePlan()` to handle `allowed_sidecar_point_ids`. After the existing `service_plan_client_groups` block (around line 1146), add:

```php
            $this->db->execute('DELETE FROM service_plan_sidecar_points WHERE plan_id = :plan_id', [
                'plan_id' => $id,
            ]);
            foreach (array_values(array_unique($sidecarPointIds)) as $sidecarId) {
                $this->db->insert('service_plan_sidecar_points', [
                    'plan_id' => $id,
                    'sidecar_point_id' => $sidecarId,
                ]);
            }
```

And at the top of `savePlan()`, parse the new field (after `$clientGroupIds` parsing around line 1043):

```php
        $sidecarPointIds = [];
        foreach ((array) ($payload['allowed_sidecar_point_ids'] ?? []) as $sidecarId) {
            $casted = (int) $sidecarId;
            if ($casted > 0) {
                $sidecarPointIds[] = $casted;
            }
        }
```

Also add `$sidecarPointIds` to the `use (...)` clause of the transaction closure.

- [ ] **Step 4: Commit**

```bash
git add master/src/Support/ProvisioningService.php
git commit -m "feat(billing): add price lookup, upsert, and plan sidecar assignment methods"
```

---

## Task 6: Module B — Admin pricing UI for node groups

**Files:**
- Modify: `master/src/Controllers/AdminController.php:1308-1370` (nodeGroupsPage)
- Modify: `master/src/Controllers/AdminController.php:2540` (saveNodeGroup)

- [ ] **Step 1: Add traffic price field to node group form**

In `nodeGroupsPage()`, add a price field after the Port Strategy field (around line 1352), inside the `<div class="form-grid">`:

```php
                    <div class="field"><label>Traffic Price / GB</label><input type="number" step="0.0001" min="0" name="traffic_price_per_gb" value="0" placeholder="0.0000"><p class="muted">Per-GB price for traffic through slaves in this group. Set 0 for free.</p></div>
```

- [ ] **Step 2: Add price column to node group table**

In the table header (line 1362), add a `<th>Price/GB</th>` column. In the row template, fetch and display the group-level price:

Before building the rows, load the prices:

```php
        $groupPriceMap = [];
        foreach ($groups as $group) {
            $gp = $this->provisioning->getTrafficPriceFor('slave', null, (int) $group['id']);
            $groupPriceMap[(int) $group['id']] = $gp !== null ? $gp['price_per_gb'] . ' ' . $gp['currency'] : '-';
        }
```

Then in each row add:

```php
                <td>' . $this->escape($groupPriceMap[(int) $group['id']] ?? '-') . '</td>
```

- [ ] **Step 3: Save price in saveNodeGroup()**

In `saveNodeGroup()` (around line 2540), after the existing `$this->provisioning?->saveNodeGroup(...)` call, add:

```php
            $groupId = /* get the saved group id from the return value */;
            $trafficPrice = trim((string) $request->input('traffic_price_per_gb', '0'));
            if ($trafficPrice !== '' && $this->provisioning !== null) {
                $this->provisioning->upsertTrafficPrice('slave', null, $groupId, $trafficPrice);
            }
```

The `saveNodeGroup()` method in ProvisioningService already returns the saved group array. Extract the `id` from it:

```php
            $result = $this->provisioning?->saveNodeGroup([...]);
            $savedGroupId = (int) ($result['id'] ?? 0);
            $trafficPrice = trim((string) $request->input('traffic_price_per_gb', '0'));
            if ($savedGroupId > 0 && $this->provisioning !== null) {
                $this->provisioning->upsertTrafficPrice('slave', null, $savedGroupId, $trafficPrice);
            }
```

- [ ] **Step 4: Commit**

```bash
git add master/src/Controllers/AdminController.php
git commit -m "feat(billing): add traffic price field to admin node group management"
```

---

## Task 7: Module B — Admin pricing UI for nodes

**Files:**
- Modify: `master/src/Controllers/AdminController.php:1372+` (nodesPage) and `saveNodeManagement()`

- [ ] **Step 1: Add price field to node management form**

In `nodesPage()`, find the editing form section. Add a price override field:

```php
                    <div class="field"><label>Traffic Price Override / GB</label><input type="number" step="0.0001" min="0" name="traffic_price_per_gb" value="' . $this->escape((string) ($editingNodePrice['price_per_gb'] ?? '')) . '" placeholder="use group default"><p class="muted">Leave empty to use the group default price. Set a value to override.</p></div>
```

Load `$editingNodePrice` before building the form:

```php
        $editingNodePrice = ($editing !== null && $this->provisioning !== null)
            ? $this->provisioning->getTrafficPriceFor('slave', (int) $editing['id'], null)
            : null;
```

- [ ] **Step 2: Save price in saveNodeManagement()**

After the existing node save logic, add:

```php
            $nodeId = $this->idParam($params);
            $trafficPrice = trim((string) $request->input('traffic_price_per_gb', ''));
            if ($trafficPrice !== '' && $this->provisioning !== null) {
                $this->provisioning->upsertTrafficPrice('slave', $nodeId, null, $trafficPrice);
            }
```

- [ ] **Step 3: Commit**

```bash
git add master/src/Controllers/AdminController.php
git commit -m "feat(billing): add per-node traffic price override in admin nodes page"
```

---

## Task 8: Module B — Admin pricing UI for sidecars

**Files:**
- Modify: `master/src/Controllers/AdminController.php:1058+` (sidecarsPage) and `saveSidecarPoint()`

- [ ] **Step 1: Add price field to sidecar form**

In `sidecarsPage()`, add a price field to the sidecar creation/editing form:

```php
                    <div class="field"><label>Traffic Price / GB</label><input type="number" step="0.0001" min="0" name="traffic_price_per_gb" value="' . $this->escape((string) ($editingSidecarPrice['price_per_gb'] ?? '0')) . '" placeholder="0.0000"><p class="muted">Per-GB price for traffic through this sidecar. 0 = free (user-owned sidecars are always free).</p></div>
```

Load price before rendering:

```php
        $editingSidecarPrice = ($editing !== null && $this->provisioning !== null)
            ? $this->provisioning->getTrafficPriceFor('sidecar', (int) $editing['id'], null)
            : null;
```

- [ ] **Step 2: Save price in saveSidecarPoint()**

After the existing sidecar save, add:

```php
            $sidecarId = /* extract saved sidecar id */;
            $trafficPrice = trim((string) $request->input('traffic_price_per_gb', '0'));
            if ($sidecarId > 0 && $this->provisioning !== null) {
                $this->provisioning->upsertTrafficPrice('sidecar', $sidecarId, null, $trafficPrice);
            }
```

- [ ] **Step 3: Commit**

```bash
git add master/src/Controllers/AdminController.php
git commit -m "feat(billing): add per-sidecar traffic pricing in admin sidecars page"
```

---

## Task 9: Module B — Admin plan page: sidecar point selection

**Files:**
- Modify: `master/src/Controllers/AdminController.php:229-346` (plansPage)
- Modify: `master/src/Controllers/AdminController.php:1941` (savePlan)

- [ ] **Step 1: Load sidecar points for plan form**

In `plansPage()`, after fetching `$clientGroups` (line 238), add:

```php
        $sidecarPoints = $this->provisioning?->listSidecarPoints() ?? [];
```

When building the editing data, also load allowed sidecar points:

```php
        $editingAllowedSidecarPointIds = [];
        if ($editing !== null && $this->provisioning !== null) {
            $editingAllowedSidecarPointIds = array_map(
                static fn(array $sp): int => (int) $sp['id'],
                $this->provisioning->allowedSidecarPointsForPlan((int) $editing['id'])
            );
        }
```

- [ ] **Step 2: Add sidecar point checkboxes to plan form**

After the "Allowed Client Groups" section (around line 323), add:

```php
                  <div class="field" style="margin-top:14px;">
                    <label>Allowed Sidecar Points</label>
                    <div class="stack">' . $this->renderSidecarPointCheckboxes($sidecarPoints, 'allowed_sidecar_point_ids[]', $editingAllowedSidecarPointIds) . '</div>
                  </div>
```

Add a helper method `renderSidecarPointCheckboxes()` in AdminController:

```php
    private function renderSidecarPointCheckboxes(array $points, string $name, array $selectedIds): string
    {
        $html = '';
        foreach ($points as $point) {
            $id = (int) ($point['id'] ?? 0);
            $checked = in_array($id, $selectedIds, true) ? ' checked' : '';
            $label = $this->escape((string) ($point['name'] ?? '')) . ' (' . $this->escape((string) ($point['sidecar_host'] ?? '')) . ':' . (int) ($point['listen_port'] ?? 0) . ')';
            $html .= '<label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="' . $this->escape($name) . '" value="' . $id . '"' . $checked . '> ' . $label . '</label>';
        }
        return $html !== '' ? $html : '<span class="muted">No sidecar points available.</span>';
    }
```

- [ ] **Step 3: Pass sidecar point IDs in savePlan()**

In `savePlan()` (around line 1968), add to the array passed to `$this->provisioning?->savePlan()`:

```php
                'allowed_sidecar_point_ids' => $request->body()['allowed_sidecar_point_ids'] ?? [],
```

- [ ] **Step 4: Show allowed sidecars in plan table**

In the plan list table (around line 268), add a column for allowed sidecar points. Add `<th>Allowed Sidecars</th>` to the header and display the names in each row.

- [ ] **Step 5: Commit**

```bash
git add master/src/Controllers/AdminController.php master/src/Support/ProvisioningService.php
git commit -m "feat(billing): add sidecar point selection to admin plans page"
```

---

## Task 10: Module B — Hourly billing deduction script

**Files:**
- Create: `master/cli/billing-deduct.php`

- [ ] **Step 1: Create the billing deduction script**

Create `master/cli/billing-deduct.php`:

```php
<?php
declare(strict_types=1);

/**
 * Hourly billing deduction script.
 * Run via cron: 0 * * * * php /path/to/master/cli/billing-deduct.php
 *
 * For each active service:
 *   1. Query last hour's traffic delta per node from traffic_usage_hourly
 *   2. Resolve price per node/sidecar
 *   3. Deduct from user wallet
 *   4. Warn on low balance
 *   5. Auto-stop forwards on zero balance
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;

$config = new Config();
$db = new Database($config);
$provisioning = new ProvisioningService($config, $db);

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$hourAgo = $now->modify('-1 hour');
$hourStart = $hourAgo->format('Y-m-d H:00:00');
$hourEnd = $now->format('Y-m-d H:00:00');

echo "[billing-deduct] Running for hour {$hourStart} to {$hourEnd}\n";

// Get all users who have active services
$users = $db->fetchAll(
    'SELECT DISTINCT u.id, u.wallet_balance, u.wallet_low_balance_threshold, u.wallet_warning_sent, u.wallet_currency
     FROM users u
     INNER JOIN services s ON s.user_id = u.id
     WHERE s.status = :status',
    ['status' => 'active']
);

$totalDeducted = 0;
$usersProcessed = 0;

foreach ($users as $user) {
    $userId = (int) $user['id'];
    $currency = strtoupper(trim((string) ($user['wallet_currency'] ?? 'CNY')));

    // Sum traffic per node for this user's services in the last hour
    $trafficRows = $db->fetchAll(
        'SELECT t.node_id, SUM(t.bytes_in + t.bytes_out) AS total_bytes
         FROM traffic_usage_hourly t
         INNER JOIN service_endpoints e ON e.id = t.endpoint_id
         INNER JOIN forwards f ON f.id = e.forward_id
         INNER JOIN services s ON s.id = f.service_id
         WHERE s.user_id = :user_id
           AND s.status = :status
           AND t.hour_start >= :hour_start
           AND t.hour_start < :hour_end
         GROUP BY t.node_id',
        [
            'user_id' => $userId,
            'status' => 'active',
            'hour_start' => $hourStart,
            'hour_end' => $hourEnd,
        ]
    );

    $totalCost = 0.0;
    $breakdown = [];

    foreach ($trafficRows as $row) {
        $nodeId = (int) ($row['node_id'] ?? 0);
        $bytes = (int) ($row['total_bytes'] ?? 0);
        if ($bytes <= 0 || $nodeId <= 0) {
            continue;
        }

        // Check if node is user-owned (free)
        $node = $db->fetchOne(
            'SELECT owner_service_id FROM nodes WHERE id = :id LIMIT 1',
            ['id' => $nodeId]
        );
        if ($node !== null && (int) ($node['owner_service_id'] ?? 0) > 0) {
            continue; // User-owned node, free
        }

        $price = $provisioning->resolveTrafficPrice($nodeId, 'slave');
        if ($price === null) {
            continue; // No price configured
        }

        $gb = $bytes / (1024 * 1024 * 1024);
        $cost = $gb * (float) $price['price_per_gb'];
        $totalCost += $cost;
        $breakdown[] = [
            'node_id' => $nodeId,
            'bytes' => $bytes,
            'price_per_gb' => $price['price_per_gb'],
            'currency' => $price['currency'],
            'cost' => round($cost, 4),
        ];
    }

    // TODO: Also process sidecar traffic from agent_usage_hourly if applicable

    if ($totalCost <= 0) {
        continue;
    }

    $usersProcessed++;
    $totalDeducted += $totalCost;

    // Deduct in transaction
    $db->transaction(function () use ($db, $userId, $totalCost, $breakdown, $currency, $hourStart) {
        // Deduct
        $db->execute(
            'UPDATE users SET wallet_balance = wallet_balance - :cost WHERE id = :id',
            ['cost' => round($totalCost, 2), 'id' => $userId]
        );

        // Record transaction
        $db->insert('wallet_transactions', [
            'user_id' => $userId,
            'type' => 'traffic_deduction',
            'amount' => -round($totalCost, 2),
            'currency' => $currency,
            'description' => 'Hourly traffic billing for ' . $hourStart,
            'metadata_json' => json_encode(['breakdown' => $breakdown, 'hour' => $hourStart]),
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);

        // Check balance after deduction
        $updated = $db->fetchOne('SELECT wallet_balance, wallet_low_balance_threshold, wallet_warning_sent FROM users WHERE id = :id LIMIT 1', ['id' => $userId]);
        $balance = (float) ($updated['wallet_balance'] ?? 0);
        $threshold = (float) ($updated['wallet_low_balance_threshold'] ?? 5.00);
        $warningSent = (int) ($updated['wallet_warning_sent'] ?? 0);

        // Low balance warning
        if ($balance <= $threshold && $warningSent === 0) {
            $db->execute(
                'UPDATE users SET wallet_warning_sent = 1 WHERE id = :id',
                ['id' => $userId]
            );
        }

        // Auto-stop on zero balance
        if ($balance <= 0) {
            $db->execute(
                'UPDATE forwards SET enabled = 0
                 WHERE service_id IN (SELECT id FROM services WHERE user_id = :user_id AND status = :status)',
                ['user_id' => $userId, 'status' => 'active']
            );

            $db->insert('audit_logs', [
                'user_id' => $userId,
                'action' => 'auto_suspend_insufficient_balance',
                'details_json' => json_encode(['balance' => $balance, 'hour' => $hourStart]),
                'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ]);
        }
    });
}

echo "[billing-deduct] Processed {$usersProcessed} users, total deducted: {$totalDeducted}\n";
```

- [ ] **Step 2: Commit**

```bash
git add master/cli/billing-deduct.php
git commit -m "feat(billing): add hourly billing deduction CLI script

Resolves per-node traffic prices, deducts from wallet, warns on low
balance, and auto-suspends forwards when balance reaches zero."
```

---

## Task 11: Module B — Site-wide low balance warning bar

**Files:**
- Modify: `master/src/Controllers/BaseController.php:1323` (page method)

- [ ] **Step 1: Add low balance warning to page() method**

In `BaseController::page()`, after the flash HTML block (around line 1338), add a low-balance warning check:

```php
        $lowBalanceHtml = '';
        if ($viewer !== null && (int) ($viewer['wallet_warning_sent'] ?? 0) === 1) {
            $balance = number_format((float) ($viewer['wallet_balance'] ?? 0), 2);
            $curr = strtoupper(trim((string) ($viewer['wallet_currency'] ?? 'CNY')));
            $lowBalanceHtml = '<div class="flash danger" style="margin-bottom:18px;">⚠ Your balance is low (' . $this->escape($curr) . ' ' . $this->escape($balance) . '). <a href="/tenant/account" style="text-decoration:underline;">Top up</a> to avoid service interruption.</div>';
        }
```

Then insert `{$lowBalanceHtml}` in the HTML template, right after `{$flashHtml}` in the `<main>` section:

```html
      <main class="content-card">
        {$flashHtml}
        {$lowBalanceHtml}
        {$body}
      </main>
```

- [ ] **Step 2: Commit**

```bash
git add master/src/Controllers/BaseController.php
git commit -m "feat(billing): add site-wide low balance warning bar for tenants"
```

---

## Task 12: Module B — Tenant billing page enhancements

**Files:**
- Modify: `master/src/Controllers/TenantController.php:231+` (billingPage)

- [ ] **Step 1: Add balance display card with color coding**

In `billingPage()`, after building the `$snapshot` (around line 265), add balance color logic:

```php
        $balanceColor = 'var(--success)';
        $balanceLabel = 'Active';
        if ($localBalance <= 0) {
            $balanceColor = 'var(--danger)';
            $balanceLabel = 'Suspended';
        } elseif ($localBalance <= (float) ($user['wallet_low_balance_threshold'] ?? 5.00)) {
            $balanceColor = '#c96b32';
            $balanceLabel = 'Low';
        }
```

Then add a balance card at the top of the billing page body, before the existing bind section:

```php
        $balanceCard = '<section class="card" style="margin-bottom:18px;">
            <div class="grid two">
              <div>
                <div class="muted">Panel Balance</div>
                <div class="stats" style="color:' . $balanceColor . ';">' . $this->escape($localCurrency) . ' ' . $this->escape(number_format($localBalance, 2)) . '</div>
                <span class="badge" style="background:' . $balanceColor . ';color:#fff;margin-top:10px;">' . $this->escape($balanceLabel) . '</span>
              </div>
              <div style="display:flex;align-items:center;justify-content:flex-end;">'
            . ($localBalance > 0 && $this->hasSuspendedForwards($user) ? '
                <form method="post" action="/tenant/account/reactivate">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <button class="button" type="submit">Reactivate All Forwards</button>
                </form>' : '') . '
              </div>
            </div>
          </section>';
```

- [ ] **Step 2: Add usage breakdown table**

After the balance card, add a per-node usage breakdown for the current billing cycle:

```php
        $usageBreakdown = $this->buildBillingUsageBreakdown((int) $user['id']);
        $usageRows = '';
        foreach ($usageBreakdown as $row) {
            $usageRows .= '<tr>
                <td>' . $this->escape((string) $row['name']) . '</td>
                <td>' . $this->escape((string) $row['region']) . '</td>
                <td>' . $this->escape($this->formatTrafficBytes((int) $row['bytes'])) . '</td>
                <td>' . $this->escape((string) $row['price_per_gb']) . '/GB</td>
                <td>' . $this->escape((string) $row['currency']) . ' ' . $this->escape(number_format((float) $row['cost'], 2)) . '</td>
            </tr>';
        }
        $usageTable = $usageRows !== '' ? '
            <section class="card" style="margin-bottom:18px;">
              <h3>This Cycle Usage Breakdown</h3>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Node</th><th>Region</th><th>Traffic</th><th>Unit Price</th><th>Cost</th></tr></thead>
                  <tbody>' . $usageRows . '</tbody>
                </table>
              </div>
            </section>' : '';
```

- [ ] **Step 3: Add helper methods**

Add `hasSuspendedForwards()` and `buildBillingUsageBreakdown()` private methods to TenantController:

```php
    private function hasSuspendedForwards(array $user): bool
    {
        $count = (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS total FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             WHERE s.user_id = :user_id AND f.enabled = 0',
            ['user_id' => (int) $user['id']]
        )['total'] ?? 0);
        return $count > 0;
    }

    private function buildBillingUsageBreakdown(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT n.id AS node_id, n.name, n.region, SUM(t.bytes_in + t.bytes_out) AS total_bytes
             FROM traffic_usage_hourly t
             INNER JOIN service_endpoints e ON e.id = t.endpoint_id
             INNER JOIN forwards f ON f.id = e.forward_id
             INNER JOIN services s ON s.id = f.service_id
             INNER JOIN nodes n ON n.id = t.node_id
             WHERE s.user_id = :user_id
               AND t.hour_start >= :cycle_start
             GROUP BY n.id, n.name, n.region
             ORDER BY total_bytes DESC',
            [
                'user_id' => $userId,
                'cycle_start' => (new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC')))->format('Y-m-d 00:00:00'),
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $nodeId = (int) ($row['node_id'] ?? 0);
            $bytes = (int) ($row['total_bytes'] ?? 0);
            $price = $this->provisioning->resolveTrafficPrice($nodeId, 'slave');
            $pricePerGb = $price !== null ? (float) $price['price_per_gb'] : 0;
            $currency = $price !== null ? $price['currency'] : 'CNY';
            $gb = $bytes / (1024 * 1024 * 1024);
            $result[] = [
                'name' => $row['name'] ?? '',
                'region' => $row['region'] ?? '',
                'bytes' => $bytes,
                'price_per_gb' => $pricePerGb > 0 ? number_format($pricePerGb, 4) : 'Free',
                'currency' => $currency,
                'cost' => $gb * $pricePerGb,
            ];
        }
        return $result;
    }
```

- [ ] **Step 4: Add reactivation route and handler**

In `Bootstrap.php`, add:

```php
        $this->router->add('POST', '/tenant/account/reactivate', [$tenant, 'reactivateForwards']);
```

In `TenantController.php`, add:

```php
    public function reactivateForwards(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $balance = (float) ($user['wallet_balance'] ?? 0);
            if ($balance <= 0) {
                throw new ApiException('insufficient_balance', 400);
            }

            $this->db->execute(
                'UPDATE forwards SET enabled = 1
                 WHERE service_id IN (SELECT id FROM services WHERE user_id = :user_id AND status = :status)',
                ['user_id' => (int) $user['id'], 'status' => 'active']
            );
            $this->db->execute(
                'UPDATE users SET wallet_warning_sent = 0 WHERE id = :id',
                ['id' => (int) $user['id']]
            );
            SessionAuth::flash('success', 'All forwards have been reactivated.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Reactivation failed: ' . $throwable->getMessage());
        }

        return Response::redirect('/tenant/account');
    }
```

- [ ] **Step 5: Commit**

```bash
git add master/src/Controllers/TenantController.php master/src/Bootstrap.php
git commit -m "feat(billing): enhance tenant billing page with balance, usage breakdown, and reactivation

Shows color-coded balance card, per-node usage table for current cycle,
and a reactivation button when balance is restored after auto-suspend."
```

---

## Task 13: Module A — Database migration for user slave groups

**Files:**
- Create: `master/migrations/20260414_add_user_slave_groups.sql`

- [ ] **Step 1: Create migration file**

Create `master/migrations/20260414_add_user_slave_groups.sql`:

```sql
-- Module A: User Slave Sharing Groups

CREATE TABLE IF NOT EXISTS user_slave_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    owner_user_id INT NOT NULL,
    invite_code VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_invite_code (invite_code)
);

CREATE TABLE IF NOT EXISTS user_slave_group_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    user_id INT NOT NULL,
    role ENUM('owner', 'member') NOT NULL DEFAULT 'member',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_user (group_id, user_id)
);

ALTER TABLE nodes
    ADD COLUMN sharing_mode ENUM('private', 'group_shared') NOT NULL DEFAULT 'private',
    ADD COLUMN user_slave_group_id INT NULL;
```

- [ ] **Step 2: Update schema.sql**

Add the new table definitions to `master/migrations/schema.sql`.

- [ ] **Step 3: Commit**

```bash
git add master/migrations/20260414_add_user_slave_groups.sql master/migrations/schema.sql
git commit -m "feat(sharing): add user_slave_groups and user_slave_group_members tables

Adds sharing_mode and user_slave_group_id columns to nodes table."
```

---

## Task 14: Module A — ProvisioningService sharing group methods

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`

- [ ] **Step 1: Add CRUD methods for user slave groups**

```php
    public function createUserSlaveGroup(int $ownerUserId, string $name): array
    {
        $inviteCode = bin2hex(random_bytes(6)); // 12-char hex code
        $id = $this->db->insert('user_slave_groups', [
            'name' => trim($name),
            'owner_user_id' => $ownerUserId,
            'invite_code' => $inviteCode,
            'created_at' => $this->now(),
        ]);

        $this->db->insert('user_slave_group_members', [
            'group_id' => $id,
            'user_id' => $ownerUserId,
            'role' => 'owner',
            'joined_at' => $this->now(),
        ]);

        return $this->db->fetchOne('SELECT * FROM user_slave_groups WHERE id = :id LIMIT 1', ['id' => $id]) ?? [];
    }

    public function joinUserSlaveGroup(int $userId, string $inviteCode): array
    {
        $group = $this->db->fetchOne(
            'SELECT * FROM user_slave_groups WHERE invite_code = :code LIMIT 1',
            ['code' => trim($inviteCode)]
        );
        if ($group === null) {
            throw new ApiException('invalid_invite_code', 404);
        }

        $existing = $this->db->fetchOne(
            'SELECT id FROM user_slave_group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1',
            ['gid' => (int) $group['id'], 'uid' => $userId]
        );
        if ($existing !== null) {
            throw new ApiException('already_member', 409);
        }

        $this->db->insert('user_slave_group_members', [
            'group_id' => (int) $group['id'],
            'user_id' => $userId,
            'role' => 'member',
            'joined_at' => $this->now(),
        ]);

        return $group;
    }

    public function leaveUserSlaveGroup(int $userId, int $groupId): void
    {
        $member = $this->db->fetchOne(
            'SELECT role FROM user_slave_group_members WHERE group_id = :gid AND user_id = :uid LIMIT 1',
            ['gid' => $groupId, 'uid' => $userId]
        );
        if ($member === null) {
            return;
        }
        if ((string) ($member['role'] ?? '') === 'owner') {
            throw new ApiException('owner_cannot_leave', 400);
        }

        $this->db->execute(
            'DELETE FROM user_slave_group_members WHERE group_id = :gid AND user_id = :uid',
            ['gid' => $groupId, 'uid' => $userId]
        );
    }

    public function kickUserSlaveGroupMember(int $ownerUserId, int $groupId, int $targetUserId): void
    {
        $group = $this->db->fetchOne(
            'SELECT owner_user_id FROM user_slave_groups WHERE id = :id LIMIT 1',
            ['id' => $groupId]
        );
        if ($group === null || (int) $group['owner_user_id'] !== $ownerUserId) {
            throw new ApiException('not_group_owner', 403);
        }

        $this->db->execute(
            'DELETE FROM user_slave_group_members WHERE group_id = :gid AND user_id = :uid AND role != :owner_role',
            ['gid' => $groupId, 'uid' => $targetUserId, 'owner_role' => 'owner']
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUserSlaveGroups(int $userId): array
    {
        $groups = $this->db->fetchAll(
            'SELECT g.*, m.role AS my_role, u.email AS owner_email
             FROM user_slave_groups g
             INNER JOIN user_slave_group_members m ON m.group_id = g.id AND m.user_id = :uid
             LEFT JOIN users u ON u.id = g.owner_user_id
             ORDER BY g.name ASC, g.id ASC',
            ['uid' => $userId]
        );

        foreach ($groups as &$group) {
            $group['members'] = $this->db->fetchAll(
                'SELECT m.user_id, m.role, u.email
                 FROM user_slave_group_members m
                 INNER JOIN users u ON u.id = m.user_id
                 WHERE m.group_id = :gid
                 ORDER BY m.role ASC, u.email ASC',
                ['gid' => (int) $group['id']]
            );
            $group['shared_nodes'] = $this->db->fetchAll(
                'SELECT n.id, n.name, n.public_ip, n.status
                 FROM nodes n
                 WHERE n.sharing_mode = :shared AND n.user_slave_group_id = :gid
                 ORDER BY n.name ASC',
                ['shared' => 'group_shared', 'gid' => (int) $group['id']]
            );
        }
        unset($group);

        return $groups;
    }

    public function updateNodeSharing(int $nodeId, int $ownerUserId, string $sharingMode, ?int $userSlaveGroupId): void
    {
        $node = $this->db->fetchOne(
            'SELECT n.id FROM nodes n
             INNER JOIN services s ON s.id = n.owner_service_id
             WHERE n.id = :nid AND s.user_id = :uid LIMIT 1',
            ['nid' => $nodeId, 'uid' => $ownerUserId]
        );
        if ($node === null) {
            throw new ApiException('node_not_owned', 403);
        }

        $this->db->execute(
            'UPDATE nodes SET sharing_mode = :mode, user_slave_group_id = :gid WHERE id = :id',
            ['mode' => $sharingMode, 'gid' => $userSlaveGroupId, 'id' => $nodeId]
        );
    }
```

- [ ] **Step 2: Commit**

```bash
git add master/src/Support/ProvisioningService.php
git commit -m "feat(sharing): add user slave group CRUD and node sharing methods"
```

---

## Task 15: Module A — Update node visibility in pickNodes()

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:2981-3040`

- [ ] **Step 1: Update pickNodes() to include group-shared nodes**

Replace the `pickNodes()` method:

```php
    private function pickNodes(string $groupKey, int $count, ?int $serviceId = null): array
    {
        $params = [
            'active' => 'active',
            'degraded' => 'degraded',
        ];
        $sql = 'SELECT n.*
                FROM nodes n
                LEFT JOIN node_groups g ON g.id = n.group_id
                WHERE (n.status = :active OR n.status = :degraded)';

        if ($groupKey !== '' && $groupKey !== 'default') {
            $sql .= ' AND g.group_key = :group_key';
            $params['group_key'] = $groupKey;
        }

        if ($serviceId !== null) {
            // Platform nodes (no owner) + user-owned nodes + group-shared nodes visible to this service's user
            $sql .= ' AND (n.owner_service_id IS NULL OR n.owner_service_id = :owner_service_id';
            $params['owner_service_id'] = $serviceId;

            // Group-shared: visible if service owner is in the node's sharing group
            $sql .= ' OR (n.sharing_mode = :shared AND n.user_slave_group_id IN (
                SELECT m.group_id FROM user_slave_group_members m
                INNER JOIN services s ON s.user_id = m.user_id
                WHERE s.id = :svc_id_for_group
            ))';
            $params['shared'] = 'group_shared';
            $params['svc_id_for_group'] = $serviceId;

            $sql .= ')';
        }

        $sql .= ' ORDER BY n.current_load ASC, n.last_heartbeat_at DESC, n.id ASC LIMIT ' . max(1, $count);
        return $this->db->fetchAll($sql, $params);
    }
```

- [ ] **Step 2: Commit**

```bash
git add master/src/Support/ProvisioningService.php
git commit -m "feat(sharing): update pickNodes() to include group-shared nodes in visibility"
```

---

## Task 16: Module A — Tenant slave groups page

**Files:**
- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/src/Bootstrap.php`

- [ ] **Step 1: Add routes in Bootstrap.php**

After the existing tenant routes (around line 172), add:

```php
        $this->router->add('GET', '/tenant/slave-groups', [$tenant, 'slaveGroupsPage']);
        $this->router->add('POST', '/tenant/slave-groups', [$tenant, 'saveSlaveGroup']);
        $this->router->add('POST', '/tenant/slave-groups/join', [$tenant, 'joinSlaveGroup']);
        $this->router->add('POST', '/tenant/slave-groups/{id}/leave', [$tenant, 'leaveSlaveGroup']);
        $this->router->add('POST', '/tenant/slave-groups/{id}/kick', [$tenant, 'kickSlaveGroupMember']);
        $this->router->add('POST', '/tenant/nodes/{id}/sharing', [$tenant, 'updateNodeSharing']);
```

- [ ] **Step 2: Add nav entry in tenantNav()**

In `tenantNav()`, add after the sidecars entry:

```php
            ['href' => '/tenant/slave-groups', 'label' => 'Slave 群组', 'active' => $active === 'slave-groups'],
```

- [ ] **Step 3: Add slaveGroupsPage() method**

```php
    public function slaveGroupsPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $groups = $this->provisioning->listUserSlaveGroups((int) $user['id']);
        $csrf = $this->escape(SessionAuth::csrfToken());

        $groupCards = '';
        foreach ($groups as $group) {
            $isOwner = (string) ($group['my_role'] ?? '') === 'owner';
            $memberList = '';
            foreach ($group['members'] as $member) {
                $memberList .= '<span class="badge info">' . $this->escape((string) $member['email']);
                if ($isOwner && (int) $member['user_id'] !== (int) $user['id']) {
                    $memberList .= ' <form class="inline" method="post" action="/tenant/slave-groups/' . (int) $group['id'] . '/kick">
                        <input type="hidden" name="csrf_token" value="' . $csrf . '">
                        <input type="hidden" name="target_user_id" value="' . (int) $member['user_id'] . '">
                        <button type="submit" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:12px;">x</button>
                    </form>';
                }
                $memberList .= '</span> ';
            }

            $nodeList = '';
            foreach ($group['shared_nodes'] as $node) {
                $nodeList .= $this->escape((string) $node['name']) . ' (' . $this->escape((string) $node['public_ip']) . '), ';
            }
            $nodeList = rtrim($nodeList, ', ') ?: 'None';

            $actions = '';
            if ($isOwner) {
                $actions = '<div class="muted" style="margin-top:10px;">Invite Code: <strong>' . $this->escape((string) $group['invite_code']) . '</strong> '
                    . $this->renderCopyButton('Copy', (string) $group['invite_code']) . '</div>';
            } else {
                $actions = '<form class="inline" method="post" action="/tenant/slave-groups/' . (int) $group['id'] . '/leave" style="margin-top:10px;">
                    <input type="hidden" name="csrf_token" value="' . $csrf . '">
                    <button type="submit" class="button danger">Leave Group</button>
                </form>';
            }

            $roleLabel = $isOwner ? 'Owner' : 'Member';
            $groupCards .= '<section class="card" style="margin-bottom:14px;">
                <h3>' . $this->escape((string) $group['name']) . ' <span class="badge info">' . $roleLabel . '</span></h3>
                <div class="muted">Members: ' . $memberList . '</div>
                <div class="muted" style="margin-top:8px;">Shared Slaves: ' . $this->escape($nodeList) . '</div>
                ' . $actions . '
            </section>';
        }

        if ($groupCards === '') {
            $groupCards = '<div class="muted">You have not created or joined any slave groups yet.</div>';
        }

        $body = '
            <div class="hero">
              <div>
                <h2>Slave Groups</h2>
                <p class="muted">Create sharing groups, invite others, and share your slaves with group members.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>Create New Group</h3>
                <form method="post" action="/tenant/slave-groups">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="action" value="create">
                  <div class="field"><label>Group Name</label><input type="text" name="name" placeholder="My Team" required></div>
                  <div class="actions"><button class="button" type="submit">Create Group</button></div>
                </form>
              </section>
              <section class="card">
                <h3>Join Group by Invite Code</h3>
                <form method="post" action="/tenant/slave-groups/join">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="field"><label>Invite Code</label><input type="text" name="invite_code" placeholder="abc123def456" required></div>
                  <div class="actions"><button class="button" type="submit">Join Group</button></div>
                </form>
              </section>
            </div>
            <div style="margin-top:18px;">' . $groupCards . '</div>';

        return $this->page('Slave Groups', $body, $user, $this->tenantNav('slave-groups'));
    }
```

- [ ] **Step 4: Add saveSlaveGroup(), joinSlaveGroup(), leaveSlaveGroup(), kickSlaveGroupMember() methods**

```php
    public function saveSlaveGroup(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $name = trim((string) $request->input('name', ''));
            if ($name === '') {
                throw new ApiException('name_required', 422);
            }
            $this->provisioning->createUserSlaveGroup((int) $user['id'], $name);
            SessionAuth::flash('success', 'Group created.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/tenant/slave-groups');
    }

    public function joinSlaveGroup(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $code = trim((string) $request->input('invite_code', ''));
            $this->provisioning->joinUserSlaveGroup((int) $user['id'], $code);
            SessionAuth::flash('success', 'Joined group successfully.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/tenant/slave-groups');
    }

    public function leaveSlaveGroup(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $groupId = $this->idParam($params);
            $this->provisioning->leaveUserSlaveGroup((int) $user['id'], $groupId);
            SessionAuth::flash('success', 'Left group.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/tenant/slave-groups');
    }

    public function kickSlaveGroupMember(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $groupId = $this->idParam($params);
            $targetUserId = (int) $request->input('target_user_id', 0);
            $this->provisioning->kickUserSlaveGroupMember((int) $user['id'], $groupId, $targetUserId);
            SessionAuth::flash('success', 'Member removed.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/tenant/slave-groups');
    }
```

- [ ] **Step 5: Commit**

```bash
git add master/src/Controllers/TenantController.php master/src/Bootstrap.php
git commit -m "feat(sharing): add tenant slave groups page with create, join, leave, kick"
```

---

## Task 17: Module A — Node sharing controls on tenant nodes page

**Files:**
- Modify: `master/src/Controllers/TenantController.php`

- [ ] **Step 1: Add sharing dropdown to user-owned nodes**

In the existing section where user-owned BYOS nodes are displayed (find it by searching for `removeUserNode` or BYOS-related code in `TenantController`), add a sharing control for each user-owned node:

```php
    public function updateNodeSharing(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $nodeId = $this->idParam($params);
            $mode = trim((string) $request->input('sharing_mode', 'private'));
            $groupId = (int) $request->input('user_slave_group_id', 0);
            if ($mode === 'group_shared' && $groupId <= 0) {
                throw new ApiException('group_required', 422);
            }
            $this->provisioning->updateNodeSharing($nodeId, (int) $user['id'], $mode, $mode === 'group_shared' ? $groupId : null);
            SessionAuth::flash('success', 'Sharing updated.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/tenant/clients');
    }
```

In the service detail page where user nodes are listed, add a sharing form per node. The form should include a select with "Private" and one option per group the user owns.

- [ ] **Step 2: Commit**

```bash
git add master/src/Controllers/TenantController.php
git commit -m "feat(sharing): add node sharing controls to tenant nodes page"
```

---

## Task 18: Module A — Forward creation: restructured node dropdown

**Files:**
- Modify: `master/src/Controllers/TenantController.php:1653-1670`

- [ ] **Step 1: Build grouped node options in quickCreateForward()**

Replace the simple `$groupOptions` building (lines 1667-1670) with:

```php
        // Build grouped node options (platform + user-owned + group-shared)
        $groupOptions = '<option value="">Auto choose</option>';

        // Platform node groups
        if ($allowedGroups !== []) {
            $groupOptions .= '<optgroup label="Platform Nodes">';
            foreach ($allowedGroups as $group) {
                $groupOptions .= '<option value="' . $this->escape((string) $group['group_key']) . '">' . $this->escape((string) $group['name']) . '</option>';
            }
            $groupOptions .= '</optgroup>';
        }

        // User-owned nodes
        $userNodes = $this->db->fetchAll(
            'SELECT n.id, n.name, n.public_ip, n.sharing_mode
             FROM nodes n
             INNER JOIN services s ON s.id = n.owner_service_id
             WHERE s.user_id = :uid AND (n.status = :active OR n.status = :degraded)
             ORDER BY n.name ASC',
            ['uid' => $user['id'], 'active' => 'active', 'degraded' => 'degraded']
        );
        if ($userNodes !== []) {
            $groupOptions .= '<optgroup label="My Slaves">';
            foreach ($userNodes as $uNode) {
                $label = $this->escape((string) $uNode['name']) . ' (' . $this->escape((string) $uNode['public_ip']) . ')';
                $groupOptions .= '<option value="_user_node_' . (int) $uNode['id'] . '">' . $label . '</option>';
            }
            $groupOptions .= '</optgroup>';
        }

        // Group-shared nodes
        $sharedGroups = $this->provisioning->listUserSlaveGroups((int) $user['id']);
        foreach ($sharedGroups as $sg) {
            $sharedNodes = $sg['shared_nodes'] ?? [];
            if ($sharedNodes === []) {
                continue;
            }
            $groupOptions .= '<optgroup label="Shared — ' . $this->escape((string) $sg['name']) . '">';
            foreach ($sharedNodes as $sNode) {
                $label = $this->escape((string) $sNode['name']) . ' (' . $this->escape((string) $sNode['public_ip']) . ')';
                $groupOptions .= '<option value="_user_node_' . (int) $sNode['id'] . '">' . $label . '</option>';
            }
            $groupOptions .= '</optgroup>';
        }
```

- [ ] **Step 2: Handle _user_node_ prefix in createForward backend**

In `ProvisioningService::saveForward()` (or wherever node_group is resolved), detect the `_user_node_` prefix:

```php
        $nodeGroup = trim((string) ($payload['node_group'] ?? ''));
        $specificNodeId = null;
        if (str_starts_with($nodeGroup, '_user_node_')) {
            $specificNodeId = (int) substr($nodeGroup, strlen('_user_node_'));
            $nodeGroup = ''; // Clear group key, will assign to specific node
        }
```

When `$specificNodeId` is set, the forward should be assigned directly to that node instead of going through group-based node picking.

- [ ] **Step 3: Commit**

```bash
git add master/src/Controllers/TenantController.php master/src/Support/ProvisioningService.php
git commit -m "feat(sharing): restructure forward creation dropdown with platform/user/shared sections

User-owned and group-shared nodes use _user_node_{id} prefix to route
to specific nodes instead of group-based assignment."
```

---

## Task 19: Module A — Admin user groups management page

**Files:**
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Bootstrap.php`

- [ ] **Step 1: Add admin route**

In `Bootstrap.php`, add after the existing admin routes:

```php
        $this->router->add('GET', '/admin/user-groups', [$admin, 'userSlaveGroupsPage']);
        $this->router->add('POST', '/admin/user-groups/{id}/delete', [$admin, 'deleteUserSlaveGroup']);
```

- [ ] **Step 2: Add admin nav entry**

In `AdminController::adminPage()` (around line 2852), find the admin navigation array and add:

```php
            ['href' => '/admin/user-groups', 'label' => 'User Groups', 'active' => $active === 'user-groups'],
```

- [ ] **Step 3: Add userSlaveGroupsPage() method**

```php
    public function userSlaveGroupsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $groups = $this->db->fetchAll(
            'SELECT g.*, u.email AS owner_email,
                    (SELECT COUNT(*) FROM user_slave_group_members m WHERE m.group_id = g.id) AS member_count,
                    (SELECT COUNT(*) FROM nodes n WHERE n.user_slave_group_id = g.id AND n.sharing_mode = :shared) AS shared_node_count
             FROM user_slave_groups g
             LEFT JOIN users u ON u.id = g.owner_user_id
             ORDER BY g.created_at DESC, g.id DESC',
            ['shared' => 'group_shared']
        );

        $csrf = $this->escape(SessionAuth::csrfToken());
        $rows = '';
        foreach ($groups as $group) {
            $rows .= '<tr>
                <td>' . (int) $group['id'] . '</td>
                <td>' . $this->escape((string) $group['name']) . '</td>
                <td>' . $this->escape((string) ($group['owner_email'] ?? '')) . '</td>
                <td>' . $this->escape((string) $group['invite_code']) . '</td>
                <td>' . (int) ($group['member_count'] ?? 0) . '</td>
                <td>' . (int) ($group['shared_node_count'] ?? 0) . '</td>
                <td>' . $this->escape((string) $group['created_at']) . '</td>
                <td>
                    <form class="inline" method="post" action="/admin/user-groups/' . (int) $group['id'] . '/delete">
                        <input type="hidden" name="csrf_token" value="' . $csrf . '">
                        <button type="submit" class="button danger">Delete</button>
                    </form>
                </td>
            </tr>';
        }

        $body = '
            <div class="hero">
              <div>
                <h2>User Slave Groups</h2>
                <p class="muted">View and manage all tenant-created slave sharing groups.</p>
              </div>
            </div>
            <section class="card">
              <div class="table-wrap">
                <table>
                  <thead><tr><th>ID</th><th>Name</th><th>Owner</th><th>Invite Code</th><th>Members</th><th>Shared Nodes</th><th>Created</th><th>Actions</th></tr></thead>
                  <tbody>' . $rows . '</tbody>
                </table>
              </div>
            </section>';

        return $this->adminPage('User Slave Groups', $body, $viewer, 'user-groups');
    }

    public function deleteUserSlaveGroup(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $groupId = $this->idParam($params);
            // Reset sharing on nodes in this group
            $this->db->execute(
                'UPDATE nodes SET sharing_mode = :private, user_slave_group_id = NULL WHERE user_slave_group_id = :gid',
                ['private' => 'private', 'gid' => $groupId]
            );
            $this->db->execute('DELETE FROM user_slave_group_members WHERE group_id = :gid', ['gid' => $groupId]);
            $this->db->execute('DELETE FROM user_slave_groups WHERE id = :id', ['id' => $groupId]);
            SessionAuth::flash('success', 'Group deleted.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/admin/user-groups');
    }
```

- [ ] **Step 4: Commit**

```bash
git add master/src/Controllers/AdminController.php master/src/Bootstrap.php
git commit -m "feat(sharing): add admin user slave groups management page"
```
