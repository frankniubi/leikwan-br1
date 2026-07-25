# Precision Grid Web Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign every visible admin and tenant Web page with the approved `Precision Grid` system while preserving behavior, keeping retired routing tabs hidden, and showing no client strategy or client-measured latency.

**Architecture:** Extract the current embedded application-shell CSS into one tracked static design-system asset and add a small PHP renderer for grouped navigation, page headers, metric strips, empty states, and ruled sections. First make the shared shell and compatibility classes render every existing page coherently, then migrate controller markup in bounded page-family batches to the three approved templates: Overview, List and Operations, and Detail and Edit. Every visual decision must trace to the approved Superpowers specification; rendered checks use the gstack `/browse` workflow and Superpowers verification gates.

**Tech Stack:** PHP 8.2 server-rendered HTML, semantic HTML5, CSS Grid/Flexbox, existing vanilla JavaScript/i18n patch, focused PHP regression scripts, gstack `/browse`, desktop/mobile screenshots.

---

## File Map

- `master/public/assets/precision-grid.css`: complete shared token and component implementation.
- `master/src/Support/PrecisionGridUi.php`: deterministic HTML renderers for grouped navigation, page header, metric strip, empty state, and section header.
- `master/src/Controllers/BaseController.php`: load the shared CSS, render the new shell, mobile navigation, grouped admin navigation, viewer controls, flash messages, dialogs, and shared diagnostics.
- `master/src/Controllers/AdminController.php`: migrate all visible admin page bodies to approved templates.
- `master/src/Controllers/TenantController.php`: migrate all visible tenant page bodies and navigation to approved templates.
- `master/src/Controllers/AuthController.php`: migrate login, registration, password, binding, and Knowledgebase public surfaces.
- `master/src/Controllers/HealthController.php`: align health/entry status with the shared public shell.
- `master/public/index.php`: render bootstrap errors with Precision Grid tokens.
- `master/public/install.php`: align installer/update forms and result tables with Precision Grid.
- `master/src/Bootstrap.php`: no route additions; retained compatibility routes remain non-navigable.
- `master/public/i18n/en.json`, `zh-Hans.json`, `zh-TW.json`: synchronize changed visible labels.
- `master/tests/precision_grid_design_system_regression_test.php`: token, shell, component, and forbidden-decoration guard.
- `master/tests/precision_grid_navigation_regression_test.php`: grouped information architecture and retired-tab guard.
- `master/tests/precision_grid_page_templates_regression_test.php`: controller markup follows the three shared templates.
- `master/tests/precision_grid_privacy_regression_test.php`: no client strategy or client latency in Web source/output.
- `master/tests/precision_grid_responsive_accessibility_test.php`: viewport, focus, overflow, label, dialog, and contrast-source guards.
- `.superpowers/brainstorm/ui-1784962520/content/`: approved visual evidence only; remains ignored and is not committed.

## Superpowers Design Gate

Before changing production markup, the executing agent must read:

```text
docs/superpowers/specs/2026-07-25-leigod-client-strategies-precision-grid-web-design.md
```

The following are locked decisions, not implementation suggestions:

- direction B, `Precision Grid`;
- hard dividers, compact system typography, 0-4 px radii, no gradients;
- grouped navigation and three shared page templates;
- no nested cards, oversized hero text, decorative blobs, or marketing layout;
- no restored `CF Location Groups`, `Forward Combinations`, `Forward Routes`, or `Chains` navigation;
- no `Speed max`, `Latency Best`, `Random`, client choice, or client-measured latency in the Web panel.

Any deviation must stop execution and return to `superpowers:brainstorming` for approval before code changes continue.

### Task 1: Establish the Precision Grid Asset and Source Guards

**Files:**
- Create: `master/public/assets/precision-grid.css`
- Create: `master/tests/precision_grid_design_system_regression_test.php`
- Modify: `master/src/Controllers/BaseController.php:1165-1783,1789-2440`

- [ ] **Step 1: Write the failing design-system test**

Create a source regression that reads the CSS and `BaseController` and asserts exact tokens, one stylesheet link in both public/application shells, and forbidden decoration absence:

```php
$css = file_get_contents(__DIR__ . '/../public/assets/precision-grid.css');
$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
foreach (['--page-bg:#f3f3f0', '--surface:#ffffff', '--ink:#111111', '--success:#087f5b', '--danger:#c73f32'] as $token) {
    assert_contains(str_replace(' ', '', $css), $token);
}
assert_contains($base, '<link rel="stylesheet" href="/assets/precision-grid.css?v=20260725">');
foreach (['radial-gradient', 'linear-gradient', 'border-radius: 15px', 'border-radius: 18px', 'border-radius: 22px'] as $forbidden) {
    assert_not_contains($css . $base, $forbidden);
}
```

Also require `:focus-visible`, `.table-scroll`, `.mobile-nav-toggle`, `.status[data-state]`, and a `@media (max-width: 900px)` rule.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/precision_grid_design_system_regression_test.php
```

Expected: FAIL because the CSS asset and link do not exist.

- [ ] **Step 3: Create the shared stylesheet and link both shells**

Define the approved tokens and compatibility/component classes in the new CSS:

```css
:root{--page-bg:#f3f3f0;--surface:#ffffff;--surface-muted:#e9e9e6;--ink:#111111;--muted:#60666c;--line:#b9bec2;--line-strong:#111111;--success:#087f5b;--danger:#c73f32;--info:#2563b8;--warning:#a86800}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:var(--page-bg);color:var(--ink);font-family:"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;letter-spacing:0}
:focus-visible{outline:3px solid var(--info);outline-offset:2px}
.button,.nav-link,input,select,textarea,details,.card,.content-card{border-radius:2px}
.table-scroll{max-width:100%;overflow:auto;border-top:2px solid var(--line-strong)}
.status[data-state="ok"]{color:var(--success)}
.status[data-state="error"]{color:var(--danger)}
@media(max-width:900px){.layout{grid-template-columns:1fr}.sidebar{display:none}.sidebar[data-open="true"]{display:block}.mobile-nav-toggle{display:inline-flex}}
```

Move shared application-shell styling out of `BaseController::page`; keep only the HTML structure and the stylesheet link. Convert the public/auth shell to the same tokens without turning it into a landing-page hero. Retain the i18n and copy-button scripts.

- [ ] **Step 4: Run design-system and existing shell tests**

```powershell
php master/tests/precision_grid_design_system_regression_test.php
php master/tests/admin_nav_consistency_regression_test.php
php master/tests/homepage_positioning_regression_test.php
php master/tests/i18n_locale_regression_test.php
```

Expected: four `ok` results.

- [ ] **Step 5: Commit the shared design system**

```powershell
git add master/public/assets/precision-grid.css master/src/Controllers/BaseController.php master/tests/precision_grid_design_system_regression_test.php
git commit -m "feat: establish precision grid web system"
```

### Task 2: Add Shared Page Primitives and Grouped Navigation

**Files:**
- Create: `master/src/Support/PrecisionGridUi.php`
- Modify: `master/src/Controllers/BaseController.php:1090-1111,1789-1840,2340-2380`
- Modify: `master/src/Controllers/TenantController.php:2850-2885`
- Modify: `master/src/Controllers/AuthController.php:800-830`
- Create: `master/tests/precision_grid_navigation_regression_test.php`
- Modify: `master/tests/admin_nav_consistency_regression_test.php`

- [ ] **Step 1: Write failing primitive and navigation tests**

Require exact group order and forbidden tabs:

```php
$groups = PrecisionGridUi::groupNavigation([
    ['group' => 'Operations', 'href' => '/admin', 'label' => 'Overview'],
    ['group' => 'Infrastructure', 'href' => '/admin/nodes', 'label' => 'Slave Nodes'],
]);
assert_contains($groups, 'data-nav-group="Operations"');
assert_contains($groups, 'data-nav-group="Infrastructure"');

foreach (['CF Location Groups', 'Forward Combinations', 'Forward Routes', '>Chains<'] as $retired) {
    assert_not_contains($renderedAdminShell, $retired);
}
```

Add direct tests for `pageHeader`, `metricStrip`, `emptyState`, and `sectionHeader`; each must escape text and emit one stable root class.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/precision_grid_navigation_regression_test.php
```

Expected: FAIL because `PrecisionGridUi` does not exist.

- [ ] **Step 3: Implement primitives and grouped navigation**

Create stateless renderers with signatures:

```php
public static function groupNavigation(array $items): string;
public static function pageHeader(string $eyebrow, string $title, string $actionsHtml = '', string $statusHtml = ''): string;
public static function metricStrip(array $metrics): string;
public static function emptyState(string $title, string $commandHtml = ''): string;
public static function sectionHeader(string $title, string $meta = ''): string;
```

Assign admin items to the approved groups: Operations, Customers, Products, Infrastructure, Support, Context. Assign tenant items to Overview, Services and Forwards, Clients, Plans and Billing, Infrastructure, Account, Support. Render service details through breadcrumbs/local context, not permanent nav entries.

- [ ] **Step 4: Run navigation and shell tests**

```powershell
php master/tests/precision_grid_navigation_regression_test.php
php master/tests/admin_nav_consistency_regression_test.php
php master/tests/tenant_controller_regressions_test.php
```

Expected: PASS.

- [ ] **Step 5: Commit primitives and information architecture**

```powershell
git add master/src/Support/PrecisionGridUi.php master/src/Controllers/BaseController.php master/src/Controllers/TenantController.php master/src/Controllers/AuthController.php master/tests/precision_grid_navigation_regression_test.php master/tests/admin_nav_consistency_regression_test.php
git commit -m "feat: group precision grid navigation"
```

### Task 3: Migrate Authentication, Registration, Password, and Knowledgebase Public Pages

**Files:**
- Modify: `master/src/Controllers/AuthController.php:1-850`
- Modify: `master/src/Controllers/HealthController.php`
- Modify: `master/public/index.php:35-55`
- Modify: `master/public/install.php`
- Modify: `master/public/i18n/en.json`
- Modify: `master/public/i18n/zh-Hans.json`
- Modify: `master/public/i18n/zh-TW.json`
- Create: `master/tests/precision_grid_public_pages_regression_test.php`

- [ ] **Step 1: Write failing public-page template tests**

Require each public handler body to use `.pg-auth-layout`, `.pg-form-section`, labelled inputs, bounded errors, and the shared page header. Require `HealthController`, bootstrap error HTML, and installer/update HTML to use the shared token names and ruled form/table classes. Forbid old `.hero-copy`, `.feature-grid`, `.landing-card`, and inline gradients.

```php
foreach (['loginPage', 'registerPage', 'forgotPasswordPage', 'resetPasswordPage', 'buyBindingVerifyPage'] as $method) {
    assert_method_contains($authSource, $method, 'pg-form-section');
}
assert_not_contains($authSource, 'hero-copy');
```

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/precision_grid_public_pages_regression_test.php
```

Expected: FAIL on the first old public-page class.

- [ ] **Step 3: Migrate public pages to Precision Grid**

Use a compact two-region auth layout only where useful: identity/status rail plus form work area. Do not use a marketing hero. Use real labels, one primary command, and inline bounded validation. Convert Knowledgebase list/detail to the List and Operations template with a filter row and ruled article body. Apply the same tokens, focus rules, 0-4 px radii, and bounded error/output regions to the health entry, bootstrap error, and installer/update pages; these surfaces must not retain a separate rounded-card theme.

Synchronize all changed labels across three locale JSON files; preserve letter spacing `0` and ensure long Chinese text wraps.

- [ ] **Step 4: Run public, auth, and i18n tests**

```powershell
php master/tests/precision_grid_public_pages_regression_test.php
php master/tests/i18n_locale_regression_test.php
php master/tests/knowledgebase_reader_regression_test.php
php master/tests/admin_knowledgebase_regression_test.php
```

Expected: PASS.

- [ ] **Step 5: Commit public-page migration**

```powershell
git add master/src/Controllers/AuthController.php master/src/Controllers/HealthController.php master/public/index.php master/public/install.php master/public/i18n master/tests/precision_grid_public_pages_regression_test.php
git commit -m "feat: redesign public account pages"
```

### Task 4: Migrate Admin Overview and Diagnostics

**Files:**
- Modify: `master/src/Controllers/AdminController.php:40-1265`
- Create: `master/tests/precision_grid_admin_overview_regression_test.php`

- [ ] **Step 1: Write failing Overview template tests**

Require one `.pg-page-header`, one `.pg-metric-strip`, `.pg-live-health`, `.pg-action-queue`, and ruled tables. Require `leigodOnlyRolloutPage` to use the operations table and bounded action/error queue. Forbid nested `.card` inside `.card` and old oversized `.stats` cards.

```php
assert_method_contains($source, 'dashboard', 'pg-metric-strip');
assert_method_contains($source, 'dashboard', 'pg-live-health');
assert_method_contains($source, 'diagnosticsPage', 'pg-operations-table');
assert_not_contains(method_body($source, 'dashboard'), 'class="stats"');
```

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/precision_grid_admin_overview_regression_test.php
```

Expected: FAIL because the dashboard uses the old card grid.

- [ ] **Step 3: Implement Overview and diagnostics templates**

Render a single metric strip for tenants, services, plans, groups, nodes, queued tasks, and certificate tasks. Follow it with live Slave/Sidecar health and an action queue. Keep all existing actions and CSRF forms. Diagnostics and LeiGod-only rollout become dense operations tables with explicit status text, bounded command output, and no decorative cards.

- [ ] **Step 4: Run focused admin tests**

```powershell
php master/tests/precision_grid_admin_overview_regression_test.php
php master/tests/admin_dashboard_actions_regression_test.php
php master/tests/admin_pending_task_purge_test.php
php master/tests/admin_queue_repair_source_test.php
```

Expected: PASS.

- [ ] **Step 5: Commit admin Overview migration**

```powershell
git add master/src/Controllers/AdminController.php master/tests/precision_grid_admin_overview_regression_test.php
git commit -m "feat: redesign admin operations overview"
```

### Task 5: Migrate Admin Customer and Product Pages

**Files:**
- Modify: `master/src/Controllers/AdminController.php:1267-2152,3445-3799,4510-4600,5649-5725`
- Create: `master/tests/precision_grid_admin_customer_product_regression_test.php`

- [ ] **Step 1: Write failing List/Edit template tests**

Cover tenants, registration codes, plans, services, user groups, and Knowledgebase editor. Require page header, filter/action bar when applicable, `.pg-data-grid`, `.pg-form-section`, and `.pg-action-row`. Ensure destructive forms carry `.pg-danger-zone` and do not sit beside the primary Save command.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/precision_grid_admin_customer_product_regression_test.php
```

Expected: FAIL on old card/form markup.

- [ ] **Step 3: Migrate customer and product pages**

Preserve every existing field, validation path, CSRF token, delete confirmation, and redirect. Replace presentation only:

```html
<header class="pg-page-header">...</header>
<div class="pg-toolbar">...</div>
<div class="table-scroll"><table class="pg-data-grid">...</table></div>
<section class="pg-form-section">...</section>
<div class="pg-action-row">...</div>
```

Empty Services/Plans states must occupy the table body and show only the valid next command; do not seed sample data.

- [ ] **Step 4: Run customer/product regressions**

```powershell
php master/tests/precision_grid_admin_customer_product_regression_test.php
php master/tests/admin_plan_storefront_controls_regression_test.php
php master/tests/admin_client_limit_plan_controls_regression_test.php
php master/tests/admin_knowledgebase_regression_test.php
```

Expected: PASS.

- [ ] **Step 5: Commit customer/product migration**

```powershell
git add master/src/Controllers/AdminController.php master/tests/precision_grid_admin_customer_product_regression_test.php
git commit -m "feat: redesign admin customer and product pages"
```

### Task 6: Migrate Admin Client and Infrastructure Pages

**Files:**
- Modify: `master/src/Controllers/AdminController.php:1637-3443`
- Modify: `master/src/Controllers/BaseController.php:300-900`
- Create: `master/tests/precision_grid_admin_infrastructure_regression_test.php`

- [ ] **Step 1: Write failing infrastructure template tests**

Cover Client Groups, Credentials, Clients, Transport Endpoints, Slave Groups, Slave Nodes, install command panels, diagnostic dialogs, usage panels, and reputation tables. Require bounded monospace command regions, stable table columns, semantic dialog titles, and textual status.

Explicitly assert retired compatibility pages are absent from shared navigation even though their route handlers still exist.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/precision_grid_admin_infrastructure_regression_test.php
```

Expected: FAIL on old command cards/dialog markup.

- [ ] **Step 3: Migrate infrastructure pages**

Use the List and Operations template for inventories, and Detail and Edit for configuration. Convert command panels into ruled sections with one-line titles, bounded `<pre>`, and compact action rows. Convert diagnostics to an accessible `<dialog aria-labelledby>` with a clear Close button and existing data. Keep all operational commands and backend fields unchanged.

- [ ] **Step 4: Run infrastructure regressions**

```powershell
php master/tests/precision_grid_admin_infrastructure_regression_test.php
php master/tests/admin_client_actions_regression_test.php
php master/tests/admin_bond_leg_reputation_dashboard_test.php
php master/tests/admin_nav_consistency_regression_test.php
```

Expected: PASS.

- [ ] **Step 5: Commit infrastructure migration**

```powershell
git add master/src/Controllers/AdminController.php master/src/Controllers/BaseController.php master/tests/precision_grid_admin_infrastructure_regression_test.php
git commit -m "feat: redesign admin infrastructure pages"
```

### Task 7: Migrate Tenant Overview, Account, Plans, and Billing

**Files:**
- Modify: `master/src/Controllers/TenantController.php:25-802`
- Create: `master/tests/precision_grid_tenant_account_regression_test.php`

- [ ] **Step 1: Write failing tenant Overview/account tests**

Require the Overview template on `introPage` and `dashboard`, a ruled balance/usage summary on `billingPage`, and List/Edit templates for plans and purchase confirmation. Forbid feature-description copy, nested cards, and old hero classes.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/precision_grid_tenant_account_regression_test.php
```

Expected: FAIL on existing card-heavy sections.

- [ ] **Step 3: Implement tenant Overview/account templates**

Show service availability, account state, usage, and valid next actions in dense ruled regions. Preserve balance transfer, Buy binding, purchase, reset traffic, and reactivation forms. Empty service state must not recreate deleted plans or bindings.

- [ ] **Step 4: Run tenant account regressions**

```powershell
php master/tests/precision_grid_tenant_account_regression_test.php
php master/tests/tenant_dashboard_service_row_regression_test.php
php master/tests/tenant_plan_storefront_regression_test.php
php master/tests/tenant_controller_regressions_test.php
```

Expected: PASS.

- [ ] **Step 5: Commit tenant account migration**

```powershell
git add master/src/Controllers/TenantController.php master/tests/precision_grid_tenant_account_regression_test.php
git commit -m "feat: redesign tenant account pages"
```

### Task 8: Migrate Tenant Services and Forward Workflows

**Files:**
- Modify: `master/src/Controllers/TenantController.php:1351-2262`
- Create: `master/tests/precision_grid_tenant_forward_regression_test.php`
- Create: `master/tests/precision_grid_privacy_regression_test.php`

- [ ] **Step 1: Write failing Forward workflow and privacy tests**

Require service detail, create Forward, and edit Forward to use Detail and Edit sections with predictable Save/Cancel actions. Assert no Web source or rendered body includes the three client strategy names or client-measured latency:

```php
$webSource = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php')
    . file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php')
    . file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
foreach (['Speed max', 'Latency Best', 'Random healthy Slave', 'client_strategy', 'latency_ms_by_node'] as $forbidden) {
    assert_not_contains($webSource, $forbidden);
}
```

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/precision_grid_tenant_forward_regression_test.php
php master/tests/precision_grid_privacy_regression_test.php
```

Expected: the template test fails; privacy must remain green throughout implementation.

- [ ] **Step 3: Migrate service and Forward pages without strategy UI**

Use breadcrumbs for Service and Forward context. Group target, local port, client assignment, and eligibility fields into ruled sections. Do not restore route, backend, client-mode, or strategy selectors. Web pages may show panel-known endpoint/node availability but never the client-selected strategy or client latency.

- [ ] **Step 4: Run Forward regressions**

```powershell
php master/tests/precision_grid_tenant_forward_regression_test.php
php master/tests/precision_grid_privacy_regression_test.php
php master/tests/tenant_forward_edit_regression_test.php
php master/tests/tenant_forward_install_management_regression_test.php
php master/tests/tenant_forward_combination_regression_test.php
```

Expected: PASS.

- [ ] **Step 5: Commit tenant Forward migration**

```powershell
git add master/src/Controllers/TenantController.php master/tests/precision_grid_tenant_forward_regression_test.php master/tests/precision_grid_privacy_regression_test.php
git commit -m "feat: redesign tenant forward workflows"
```

### Task 9: Migrate Tenant Clients, Transport Endpoints, Slave Groups, and Diagnostics

**Files:**
- Modify: `master/src/Controllers/TenantController.php:804-1350,1978-2088,2264-2505`
- Modify: `master/src/Controllers/BaseController.php:300-900`
- Create: `master/tests/precision_grid_tenant_infrastructure_regression_test.php`

- [ ] **Step 1: Write failing tenant infrastructure tests**

Require List and Operations templates, bounded install commands, accessible diagnostics dialog, stable action columns, textual health, and mobile-safe long hostnames/tokens.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/precision_grid_tenant_infrastructure_regression_test.php
```

Expected: FAIL on old client/transport/group card markup.

- [ ] **Step 3: Migrate tenant infrastructure pages**

Reuse shared BaseController renderers rather than duplicating admin markup. Keep tenant ownership and authorization behavior unchanged. Delete only obsolete presentational wrappers; do not remove install, update, diagnostics, join/leave, sharing, or delete commands.

- [ ] **Step 4: Run tenant infrastructure regressions**

```powershell
php master/tests/precision_grid_tenant_infrastructure_regression_test.php
php master/tests/tenant_client_page_simplification_regression_test.php
php master/tests/tenant_client_profile_limit_regression_test.php
php master/tests/tenant_bond_leg_reputation_visibility_test.php
```

Expected: PASS.

- [ ] **Step 5: Commit tenant infrastructure migration**

```powershell
git add master/src/Controllers/TenantController.php master/src/Controllers/BaseController.php master/tests/precision_grid_tenant_infrastructure_regression_test.php
git commit -m "feat: redesign tenant infrastructure pages"
```

### Task 10: Enforce Responsive, Accessibility, and Localization Rules

**Files:**
- Modify: `master/public/assets/precision-grid.css`
- Modify: `master/src/Controllers/BaseController.php`
- Modify: `master/public/i18n/en.json`
- Modify: `master/public/i18n/zh-Hans.json`
- Modify: `master/public/i18n/zh-TW.json`
- Create: `master/tests/precision_grid_responsive_accessibility_test.php`

- [ ] **Step 1: Write failing responsive/accessibility guards**

Parse source and require:

```php
foreach ([':focus-visible', 'min-height:40px', 'overflow-wrap:anywhere', '@media(max-width:900px)', 'aria-expanded', 'aria-controls'] as $required) {
    assert_contains($normalizedSource, str_replace(' ', '', $required));
}
assert_not_contains($css, 'font-size:clamp(');
assert_not_contains($css, 'letter-spacing:-');
```

Require every generated dialog to have `aria-labelledby`, every input to have a label/accessible name, and status styles to include visible text.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/precision_grid_responsive_accessibility_test.php
```

Expected: FAIL on missing mobile menu ARIA/state or wrapping rules.

- [ ] **Step 3: Implement responsive and accessibility completion**

Add a 40 px mobile control floor, explicit table scrollers, label-row mobile fallback, `overflow-wrap:anywhere` for identifiers, one-column forms, non-overlapping metric tracks, keyboard-visible focus, and an ARIA-synchronized mobile navigation toggle. Keep font sizes fixed by component, not viewport width.

- [ ] **Step 4: Run accessibility and locale tests**

```powershell
php master/tests/precision_grid_responsive_accessibility_test.php
php master/tests/i18n_locale_regression_test.php
python master/tests/knowledgebase_seed_utf8_regression_test.py
```

Expected: PASS.

- [ ] **Step 5: Commit responsive/accessibility completion**

```powershell
git add master/public/assets/precision-grid.css master/src/Controllers/BaseController.php master/public/i18n master/tests/precision_grid_responsive_accessibility_test.php
git commit -m "fix: complete precision grid responsive access"
```

### Task 11: Run Superpowers Visual Review on Every Visible Route Family

**Files:**
- Modify only when review finds a defect in files already owned by Tasks 1-10.
- Evidence: `.superpowers/brainstorm/` and gstack `/browse` session screenshots; these stay untracked.

- [ ] **Step 1: Start a testable panel server and invoke gstack `/browse`**

Use an isolated test database or a read-only snapshot of the panel database. Start PHP on an unused port:

```powershell
$port = 18080
php -S 127.0.0.1:$port -t master/public
```

Keep that process running. Invoke the repository-mandated gstack `/browse` skill, import the test login session if required, and open `http://127.0.0.1:18080/login`. If the application cannot bootstrap against the isolated database, stop here and repair the test fixture through a failing bootstrap regression; do not point local code at the production database.

- [ ] **Step 2: Capture desktop screenshots**

At 1440x900, visit representative routes from every family:

```text
/login
/admin
/admin/tenants
/admin/services
/admin/clients
/admin-transport-endpoints
/admin/node-groups
/admin/nodes
/tenant
/tenant/account
/tenant/plans
/tenant/forwards
/tenant/clients
/tenant-transport-endpoints
/tenant/slave-groups
/knowledgebase
```

For each route, verify no console error, no missing CSS/image, no nested card composition, correct active nav, stable tables/forms, and no client strategy/latency text.

- [ ] **Step 3: Capture mobile screenshots**

At 390x844, repeat the route-family representatives. Open and close the mobile nav, focus each form family, open one diagnostics dialog, and inspect the longest hostname/command/translated label. Require no page-level horizontal overflow and no text overlap.

- [ ] **Step 4: Apply only review-backed fixes and rerun focused tests**

Invoke the `/design-review` skill against the captured desktop/mobile route set. For each discovered issue, write a failing focused regression first, verify RED, apply the minimal CSS/markup fix, and rerun the owning test plus:

```powershell
php master/tests/precision_grid_design_system_regression_test.php
php master/tests/precision_grid_responsive_accessibility_test.php
php master/tests/precision_grid_privacy_regression_test.php
```

- [ ] **Step 5: Commit visual-review fixes**

```powershell
git add master/public/assets/precision-grid.css master/src/Controllers master/public/i18n master/tests
git commit -m "fix: resolve precision grid visual review findings"
```

Skip the commit only when review produces no source change.

### Task 12: Full Verification and Deployment-Ready Handoff

**Files:**
- Modify only when a new failing test identifies a defect.

- [ ] **Step 1: Run all focused Precision Grid tests**

```powershell
Get-ChildItem master\tests\precision_grid_*.php | ForEach-Object { php $_.FullName; if ($LASTEXITCODE -ne 0) { throw "failed: $($_.Name)" } }
```

Expected: every test prints `ok`.

- [ ] **Step 2: Run related existing Web regressions**

```powershell
php master/tests/admin_nav_consistency_regression_test.php
php master/tests/tenant_controller_regressions_test.php
php master/tests/admin_dashboard_actions_regression_test.php
php master/tests/tenant_dashboard_service_row_regression_test.php
php master/tests/i18n_locale_regression_test.php
php master/tests/knowledgebase_reader_regression_test.php
```

Expected: PASS.

- [ ] **Step 3: Run the broad master suite and compare to baseline**

Run each PHP test, capture pass/fail names, and compare against the preimplementation baseline. The known environment-only baseline failures must not grow; any changed or new failure blocks completion.

```powershell
$results = foreach ($test in Get-ChildItem master\tests\*.php) {
    php $test.FullName *> $null
    [pscustomobject]@{ Test=$test.Name; ExitCode=$LASTEXITCODE }
}
$results | Where-Object ExitCode -ne 0 | Format-Table -AutoSize
```

- [ ] **Step 4: Invoke Superpowers completion verification**

Use `superpowers:verification-before-completion`. Provide it the approved specification, both implementation plans, focused test output, broad baseline comparison, and desktop/mobile screenshot evidence. Do not claim completion until that gate accepts the evidence.

- [ ] **Step 5: Verify the final repository state**

```powershell
git diff --check
git status --short --branch
git log --oneline --decorate -15
```

Expected: clean worktree, no whitespace errors, all implementation commits based on current `master`. Deployment to the panel is a separate operator step requiring a fresh remote backup and live canary; do not silently deploy from this plan.
