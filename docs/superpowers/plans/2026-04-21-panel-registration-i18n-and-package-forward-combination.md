# Panel Registration, I18n, and Package Forward Combination Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Simplified Chinese support, complete Traditional Chinese coverage, move registration management into a dedicated admin page, add a bulk clear-failed-tasks action, and change membership packages to grant specific forward combinations with package-owned slave/sidecar pricing.

**Architecture:** Reuse the existing registration backend and split its admin UI into a dedicated `/admin/registration` surface. Keep failed-task visibility on existing admin task/diagnostics surfaces, but add a focused bulk delete for `failed` rows only. Replace package entitlement checkboxes for node/client/sidecar infrastructure with a direct package-to-forward-combination mapping table, and move combination filtering to that mapping while keeping package pricing on `service_plans`.

**Tech Stack:** PHP controllers, PHP `ProvisioningService`, SQL migration + schema update, JSON locale files, source-string regression tests, lightweight provisioning behavior tests

---

## What already exists

- `master/src/Controllers/BaseController.php` already has the locale switcher and i18n bootstrap, but supports only `en` and `zh-TW`.
- `master/public/i18n/zh-TW.json` already exists.
- `master/public/install.php` already exposes a language selector, but only Traditional Chinese is available.
- `master/src/Support/TenantRegistrationService.php` already contains the registration settings and registration code backend.
- `master/src/Controllers/AdminController.php::tenantsPage()` already embeds the registration settings + code generation UI.
- `master/src/Controllers/AdminController.php::purgeStaleProvisionTasks()` already deletes stale `pending` tasks, but nothing deletes all `failed` tasks.
- `master/src/Controllers/AdminController.php::plansPage()` and `master/src/Support/ProvisioningService::savePlan()` already manage `service_plans`, but package gating is still based on allowed node groups, client groups, and sidecar points.
- `master/src/Support/ProvisioningService::availableForwardCombinationsForService()` already filters combinations indirectly through package node-group / sidecar constraints.

## NOT in scope

- Retry orchestration for failed tasks
- Full billing rewrite or per-forward-combination pricing
- Renaming `zh-TW` to `zh-Hant`
- Changing runtime topology
- Reworking admin forward-combination CRUD beyond package mapping support

## File map

- Modify: `master/src/Controllers/BaseController.php`
- Modify: `master/public/install.php`
- Add: `master/public/i18n/zh-Hans.json`
- Modify: `master/public/i18n/zh-TW.json`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/migrations/schema.sql`
- Add: `master/migrations/20260421_add_plan_forward_combination_mapping_and_unit_prices.sql`
- Add: `master/tests/i18n_locale_regression_test.php`
- Add: `master/tests/admin_registration_page_regression_test.php`
- Add: `master/tests/admin_failed_task_cleanup_regression_test.php`
- Add: `master/tests/provisioning_plan_forward_combination_mapping_test.php`

## Task 1: Add Simplified Chinese support and complete locale plumbing

**Files:**
- Modify: `master/src/Controllers/BaseController.php`
- Modify: `master/public/install.php`
- Modify: `master/public/i18n/zh-TW.json`
- Add: `master/public/i18n/zh-Hans.json`
- Test: `master/tests/i18n_locale_regression_test.php`

- [ ] **Step 1: Write the failing locale regression**

Create `master/tests/i18n_locale_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
$install = file_get_contents(__DIR__ . '/../public/install.php');
$zhTw = file_get_contents(__DIR__ . '/../public/i18n/zh-TW.json');
$zhHansPath = __DIR__ . '/../public/i18n/zh-Hans.json';

foreach ([
    "supported: ['en', 'zh-TW', 'zh-Hans']",
    '<option value="zh-Hans">Simplified Chinese</option>',
    '<option value="zh-TW">Traditional Chinese</option>',
] as $snippet) {
    if (!str_contains($base . $install, $snippet)) {
        fail('missing locale snippet: ' . $snippet);
    }
}

if (!file_exists($zhHansPath)) {
    fail('missing zh-Hans locale file');
}

$zhHans = file_get_contents($zhHansPath);
foreach ([
    '"locale": "zh-TW"',
    '"Traditional Chinese"',
    '"Simplified Chinese"',
    '"Registration"',
    '"Clear All Failed Tasks"',
    '"Allowed Forward Combinations"',
] as $snippet) {
    if (!str_contains($zhTw . $zhHans, $snippet)) {
        fail('missing translation key snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the locale regression and verify it fails**

Run: `php master/tests/i18n_locale_regression_test.php`  
Expected: FAIL because `zh-Hans` is not supported yet.

- [ ] **Step 3: Add the locale options**

Update `master/src/Controllers/BaseController.php`:

```php
protected function localeSwitcherHtml(): string
{
    return <<<HTML
        <div class="locale-switch">
            <label for="locale-switcher" class="locale-label">Language</label>
            <select id="locale-switcher" class="locale-select">
                <option value="en">English</option>
                <option value="zh-TW">Traditional Chinese</option>
                <option value="zh-Hans">Simplified Chinese</option>
            </select>
        </div>
    HTML;
}
```

Update the i18n bootstrap to:

```php
protected function i18nBootstrapHtml(): string
{
    return <<<'HTML'
  <script>
    window.LEIKWAN_I18N = {
      defaultLocale: 'en',
      supported: ['en', 'zh-TW', 'zh-Hans'],
      basePath: '/i18n'
    };
  </script>
  <script src="/i18n/patch.js"></script>
HTML;
}
```

Update `master/public/install.php` so its selector also exposes:

```html
<option value="zh-Hans">Simplified Chinese</option>
```

- [ ] **Step 4: Add the locale file and translation keys**

Create `master/public/i18n/zh-Hans.json` by copying the structure of `zh-TW.json` and filling at least the changed admin/package/task keys with Simplified Chinese.

Also update `master/public/i18n/zh-TW.json` to include complete translations for:

```json
"Registration": "註冊管理",
"Simplified Chinese": "简体中文",
"Clear All Failed Tasks": "清除所有失败任务",
"Allowed Forward Combinations": "允許的轉發組合",
"Slave Price": "Slave 價格",
"Sidecar Price": "Sidecar 價格"
```

- [ ] **Step 5: Run the locale regression again**

Run: `php master/tests/i18n_locale_regression_test.php`  
Expected: `ok`

- [ ] **Step 6: Commit**

```bash
git add master/src/Controllers/BaseController.php master/public/install.php master/public/i18n/zh-TW.json master/public/i18n/zh-Hans.json master/tests/i18n_locale_regression_test.php
git commit -m "feat(i18n): add simplified chinese locale support"
```

## Task 2: Move registration management into a dedicated admin page

**Files:**
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/AdminController.php`
- Test: `master/tests/admin_registration_page_regression_test.php`

- [ ] **Step 1: Write the failing admin registration regression**

Create `master/tests/admin_registration_page_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');

foreach ([
    "/admin/registration",
    "public function registrationPage(Request \$request, array \$params): Response",
    "Save Registration Settings",
    "Generate Registration Code",
    "Registration Codes",
] as $snippet) {
    if (!str_contains($bootstrap . $admin, $snippet)) {
        fail('missing registration page snippet: ' . $snippet);
    }
}

if (str_contains($admin, "public function tenantsPage") && str_contains($admin, 'Tenant Registration Settings')) {
    fail('registration management still embedded in tenantsPage');
}

echo "ok\n";
```

- [ ] **Step 2: Run the registration regression and verify it fails**

Run: `php master/tests/admin_registration_page_regression_test.php`  
Expected: FAIL because `/admin/registration` does not exist yet.

- [ ] **Step 3: Add the route and page**

In `master/src/Bootstrap.php`, add:

```php
$this->router->add('GET', '/admin/registration', [$admin, 'registrationPage']);
$this->router->add('POST', '/admin/registration/settings', [$admin, 'saveTenantRegistrationSettings']);
$this->router->add('POST', '/admin/registration/codes', [$admin, 'createTenantRegistrationCode']);
$this->router->add('POST', '/admin/registration/codes/{id}/toggle', [$admin, 'toggleTenantRegistrationCode']);
```

In `master/src/Controllers/AdminController.php`, extract the registration-specific UI from `tenantsPage()` into:

```php
public function registrationPage(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }

    $registration = $this->registrationService();
    $registrationSettings = $registration->registrationSettings();
    $inviteCodes = $registration->listInviteCodes();
    $csrf = $this->escape(SessionAuth::csrfToken());
    $requireCodeChecked = $registrationSettings['require_invite_code'] ? ' checked' : '';

    // build invite rows the same way tenantsPage() did before
    // render one dedicated page with settings + generate form + code list

    return $this->adminPage('Registration', $body, $viewer, 'registration');
}
```

Also remove the registration settings/code UI block from `tenantsPage()`.

- [ ] **Step 4: Add the nav item**

Update the admin nav array to include:

```php
['href' => '/admin/registration', 'label' => 'Registration', 'active' => $active === 'registration'],
```

- [ ] **Step 5: Run the registration regression again**

Run: `php master/tests/admin_registration_page_regression_test.php`  
Expected: `ok`

- [ ] **Step 6: Commit**

```bash
git add master/src/Bootstrap.php master/src/Controllers/AdminController.php master/tests/admin_registration_page_regression_test.php
git commit -m "feat(admin): add dedicated registration page"
```

## Task 3: Add bulk clear-all-failed-tasks action

**Files:**
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/AdminController.php`
- Test: `master/tests/admin_failed_task_cleanup_regression_test.php`

- [ ] **Step 1: Write the failing failed-task cleanup regression**

Create `master/tests/admin_failed_task_cleanup_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');

foreach ([
    '/admin/provision-tasks/clear-failed',
    'public function clearFailedProvisionTasks(Request $request, array $params): Response',
    'Clear All Failed Tasks',
    "WHERE status = 'failed'",
] as $snippet) {
    if (!str_contains($bootstrap . $admin, $snippet)) {
        fail('missing failed-task cleanup snippet: ' . $snippet);
    }
}

foreach ([
    "WHERE status = 'pending'",
    "WHERE status = 'running'",
    "WHERE status = 'completed'",
] as $wrongTarget) {
    if (str_contains($admin, 'clearFailedProvisionTasks') && str_contains($admin, $wrongTarget)) {
        fail('clearFailedProvisionTasks should not target non-failed rows: ' . $wrongTarget);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the cleanup regression and verify it fails**

Run: `php master/tests/admin_failed_task_cleanup_regression_test.php`  
Expected: FAIL because the route/method/button do not exist yet.

- [ ] **Step 3: Add the route, method, and button**

In `master/src/Bootstrap.php`, add:

```php
$this->router->add('POST', '/admin/provision-tasks/clear-failed', [$admin, 'clearFailedProvisionTasks']);
```

In `master/src/Controllers/AdminController.php`, add:

```php
public function clearFailedProvisionTasks(Request $request, array $params): Response
{
    $viewer = $this->adminViewer();
    if ($viewer === null) {
        return Response::redirect('/login');
    }

    try {
        SessionAuth::validateCsrf($request);
        $count = (int) (($this->db->fetchOne("SELECT COUNT(*) AS total FROM provision_tasks WHERE status = 'failed'")['total'] ?? 0));
        if ($count <= 0) {
            SessionAuth::flash('success', 'Clear failed tasks finished. deleted=0');
            return Response::redirect('/admin');
        }

        $deleted = $this->db->execute("DELETE FROM provision_tasks WHERE status = 'failed'");
        SessionAuth::flash('success', 'Clear failed tasks finished. deleted=' . $deleted);
    } catch (\Throwable $throwable) {
        SessionAuth::flash('error', 'Failed to clear failed tasks: ' . $this->displayThrowableMessage($throwable));
    }

    return Response::redirect('/admin');
}
```

Add the button next to the existing stale pending purge UI:

```php
<form class="actions" method="post" action="/admin/provision-tasks/clear-failed" style="margin-top:14px;">
  <input type="hidden" name="csrf_token" value="' . $csrf . '">
  <button type="submit" class="button danger" onclick="return confirm('Delete failed tasks only?');">Clear All Failed Tasks</button>
</form>
```

- [ ] **Step 4: Run the cleanup regression again**

Run: `php master/tests/admin_failed_task_cleanup_regression_test.php`  
Expected: `ok`

- [ ] **Step 5: Commit**

```bash
git add master/src/Bootstrap.php master/src/Controllers/AdminController.php master/tests/admin_failed_task_cleanup_regression_test.php
git commit -m "feat(admin): add clear failed tasks action"
```

## Task 4: Replace package infrastructure entitlements with forward-combination mapping

**Files:**
- Modify: `master/migrations/schema.sql`
- Add: `master/migrations/20260421_add_plan_forward_combination_mapping_and_unit_prices.sql`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Test: `master/tests/provisioning_plan_forward_combination_mapping_test.php`

- [ ] **Step 1: Write the failing package-mapping regression**

Create `master/tests/provisioning_plan_forward_combination_mapping_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migration = file_exists(__DIR__ . '/../migrations/20260421_add_plan_forward_combination_mapping_and_unit_prices.sql')
    ? file_get_contents(__DIR__ . '/../migrations/20260421_add_plan_forward_combination_mapping_and_unit_prices.sql')
    : '';
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    'CREATE TABLE IF NOT EXISTS service_plan_forward_combinations',
    'slave_unit_price',
    'sidecar_unit_price',
    'Allowed Forward Combinations',
] as $snippet) {
    if (!str_contains($schema . $migration . $admin, $snippet)) {
        fail('missing package combination schema/ui snippet: ' . $snippet);
    }
}

foreach ([
    'allowed_node_group_ids[]',
    'allowed_client_group_ids[]',
    'allowed_sidecar_point_ids[]',
] as $forbidden) {
    if (str_contains($admin, $forbidden)) {
        fail('stale package infrastructure entitlement UI still present: ' . $forbidden);
    }
}

foreach ([
    'service_plan_forward_combinations',
    'allowed_forward_combination_ids',
    'slave_unit_price',
    'sidecar_unit_price',
] as $snippet) {
    if (!str_contains($provisioning, $snippet)) {
        fail('missing provisioning package mapping snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the package-mapping regression and verify it fails**

Run: `php master/tests/provisioning_plan_forward_combination_mapping_test.php`  
Expected: FAIL because the mapping table and UI do not exist yet.

- [ ] **Step 3: Add the schema**

Update `master/migrations/schema.sql` and add migration `master/migrations/20260421_add_plan_forward_combination_mapping_and_unit_prices.sql` with:

```sql
ALTER TABLE service_plans
  ADD COLUMN slave_unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER extra_client_price,
  ADD COLUMN sidecar_unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER slave_unit_price;

CREATE TABLE IF NOT EXISTS service_plan_forward_combinations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plan_id BIGINT UNSIGNED NOT NULL,
  forward_combination_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_plan_forward_combination (plan_id, forward_combination_id),
  CONSTRAINT fk_plan_forward_combinations_plan FOREIGN KEY (plan_id) REFERENCES service_plans(id),
  CONSTRAINT fk_plan_forward_combinations_combination FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id)
);
```

- [ ] **Step 4: Replace the package edit UI**

In `master/src/Controllers/AdminController.php`, replace:

```php
<label>Allowed Slave Groups</label>
<label>Allowed Client Groups</label>
<label>Allowed Sidecar Points</label>
```

with one section:

```php
<label>Allowed Forward Combinations</label>
<div class="stack">' . $this->renderForwardCombinationCheckboxes($forwardCombinations, 'allowed_forward_combination_ids[]', $selectedCombinationIds) . '</div>
```

Also add pricing inputs:

```php
<div class="field"><label>Slave Price</label><input type="number" step="0.01" min="0" name="slave_unit_price" ...></div>
<div class="field"><label>Sidecar Price</label><input type="number" step="0.01" min="0" name="sidecar_unit_price" ...></div>
```

- [ ] **Step 5: Save and load the new mapping in ProvisioningService**

In `master/src/Support/ProvisioningService.php`:

- parse `allowed_forward_combination_ids`
- persist rows into `service_plan_forward_combinations`
- include selected combinations when loading plans
- save/load `slave_unit_price` and `sidecar_unit_price`

For combination visibility, change `availableForwardCombinationsForService(...)` so it filters by package mapping first:

```php
$allowedCombinationIds = $this->allowedForwardCombinationIdsForPlan((int) ($service['plan_id'] ?? 0));
if ($allowedCombinationIds !== [] && !in_array((int) ($row['id'] ?? 0), $allowedCombinationIds, true)) {
    return false;
}
```

Keep legacy low-level package visibility only as transitional compatibility if the new mapping is empty for an old plan.

- [ ] **Step 6: Run the package-mapping regression again**

Run: `php master/tests/provisioning_plan_forward_combination_mapping_test.php`  
Expected: `ok`

- [ ] **Step 7: Commit**

```bash
git add master/migrations/schema.sql master/migrations/20260421_add_plan_forward_combination_mapping_and_unit_prices.sql master/src/Controllers/AdminController.php master/src/Support/ProvisioningService.php master/tests/provisioning_plan_forward_combination_mapping_test.php
git commit -m "feat(plans): map packages to forward combinations"
```

## Task 5: Final verification

**Files:**
- Verify only

- [ ] **Step 1: Run all new regression tests**

Run:

```bash
php master/tests/i18n_locale_regression_test.php
php master/tests/admin_registration_page_regression_test.php
php master/tests/admin_failed_task_cleanup_regression_test.php
php master/tests/provisioning_plan_forward_combination_mapping_test.php
```

Expected: all print `ok`

- [ ] **Step 2: Run PHP lint on touched controllers/services**

Run:

```bash
php -l master/src/Controllers/BaseController.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Support/ProvisioningService.php
php -l master/public/install.php
```

Expected: `No syntax errors detected ...`

- [ ] **Step 3: Commit any final cleanup**

```bash
git add master/src/Controllers/BaseController.php master/src/Controllers/AdminController.php master/src/Support/ProvisioningService.php master/public/install.php master/public/i18n/zh-TW.json master/public/i18n/zh-Hans.json master/tests/i18n_locale_regression_test.php master/tests/admin_registration_page_regression_test.php master/tests/admin_failed_task_cleanup_regression_test.php master/tests/provisioning_plan_forward_combination_mapping_test.php
git commit -m "test: cover registration i18n and plan combination flow"
```
