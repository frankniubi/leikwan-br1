# Tenant Bandwidth Read-Only Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove tenant self-serve bandwidth adjustment from the tenant panel while keeping current bandwidth and billing information visible as read-only reference.

**Architecture:** Make the tenant service detail page informational only for bandwidth/package state. Remove the tenant POST route for flexible bandwidth updates, remove the bandwidth form from `TenantController`, keep billing summaries visible, and add read-only explanatory copy plus a regression test that prevents the form/route from coming back.

**Tech Stack:** PHP controller/router code, source-string regression test

---

### Task 1: Remove tenant bandwidth adjustment controls

**Files:**
- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/src/Bootstrap.php`
- Create: `master/tests/tenant_bandwidth_read_only_regression_test.php`

- [ ] **Step 1: Write the failing regression test**

Create `master/tests/tenant_bandwidth_read_only_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');

foreach ([
    'Current Bandwidth:',
    'Billing This Cycle',
    'Package and bandwidth changes are managed by admin.',
] as $required) {
    if (!str_contains($tenant, $required)) {
        fail('missing read-only billing snippet: ' . $required);
    }
}

foreach ([
    '/tenant/services/{id}/flexible-options',
    'Update Flexible Options',
    '<input type="number" min="\' . max(1, (int) ($billing[\'base_bandwidth_mbps\'] ?? $service[\'bandwidth_limit_mbps\'])) . \'\" name=\"bandwidth_limit_mbps\"',
] as $forbidden) {
    if (str_contains($tenant . $bootstrap, $forbidden)) {
        fail('stale tenant bandwidth control still present: ' . $forbidden);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the regression to verify it fails**

Run: `php master/tests/tenant_bandwidth_read_only_regression_test.php`  
Expected: FAIL because the form and route still exist.

- [ ] **Step 3: Remove the tenant route**

Delete this route from `master/src/Bootstrap.php`:

```php
$this->router->add('POST', '/tenant/services/{id}/flexible-options', [$tenant, 'updateFlexibleOptions']);
```

- [ ] **Step 4: Remove the tenant form and add read-only copy**

In `master/src/Controllers/TenantController.php`, remove:

```php
<form method="post" action="/tenant/services/' . (int) $service['id'] . '/flexible-options" ...>
...
<button class="button secondary" type="submit">Update Flexible Options</button>
</form>
```

Replace it with:

```php
<div class="muted" style="margin-top:12px;">Package and bandwidth changes are managed by admin. This section shows your current bandwidth and billing state only.</div>
```

- [ ] **Step 5: Remove the tenant controller action**

Delete `public function updateFlexibleOptions(...)` from `master/src/Controllers/TenantController.php`.

- [ ] **Step 6: Run the regression again**

Run: `php master/tests/tenant_bandwidth_read_only_regression_test.php`  
Expected: `ok`

- [ ] **Step 7: Lint touched PHP files**

Run:

```bash
php -l master/src/Controllers/TenantController.php
php -l master/src/Bootstrap.php
```

Expected:
- `No syntax errors detected in master/src/Controllers/TenantController.php`
- `No syntax errors detected in master/src/Bootstrap.php`

- [ ] **Step 8: Commit**

```bash
git add master/src/Controllers/TenantController.php master/src/Bootstrap.php master/tests/tenant_bandwidth_read_only_regression_test.php
git commit -m "feat: make tenant bandwidth details read-only"
```
