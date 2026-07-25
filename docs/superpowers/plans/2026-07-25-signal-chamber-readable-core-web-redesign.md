# Signal Chamber and Readable Core Web Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a cinematic C2 Signal Chamber homepage, N2 focused core workflows, and a readable Sheet standard across all existing Admin and Tenant tables without changing application behavior or client-local privacy.

**Architecture:** Replace the current competing embedded/external CSS stack with three authoritative assets: shared UI foundation, public Signal Chamber, and readable operator console. Extend the existing stateless `PrecisionGridUi` renderer with tabs, status, resource cells, row menus, and detail sections, then migrate only the approved core workflows to focused list/detail views while every other table inherits the Sheet layer. Keep all POST routes, CSRF, authorization, persistence, and provisioning contracts unchanged.

**Tech Stack:** PHP 8.2+ server-rendered HTML, vanilla JavaScript, Canvas 2D, CSS Grid/Flexbox, existing i18n patch, PHPUnit-style PHP regression scripts, gstack `/browse`, production archive-overlay deployment through WSL SSH.

---

## File Map

### Shared UI foundation

- Create: `master/public/assets/leikwan-ui.css` - tokens, typography, controls, focus, dialogs, common responsive behavior.
- Create: `master/public/assets/operator-console.css` - navigation, focused views, Sheet tables, detail forms, mobile resource rows.
- Create: `master/public/assets/signal-chamber.css` - C2 homepage layout, colors, lane scene, light detail section, reduced motion.
- Create: `master/public/assets/signal-chamber.js` - bounded Canvas 2D lane animation with reduced-motion and visibility handling.
- Delete after migration: `master/public/assets/precision-grid.css` - no longer loaded once all three assets are verified.
- Modify: `master/src/Controllers/BaseController.php` - authoritative asset loading; remove embedded public/application CSS; shared shell classes and scripts.
- Modify: `master/src/Support/PrecisionGridUi.php` - tabs, Sheet primitives, status, row menu, detail section, and view normalization.
- Modify: `master/public/index.php` - foundation asset for bootstrap errors.
- Modify: `master/public/install.php` - foundation/operator assets for installer Sheet/forms.
- Modify: `master/src/Controllers/HealthController.php` - foundation/public assets for health entry.

### Public homepage

- Modify: `master/src/Controllers/AuthController.php:16-127` - C2 homepage markup and truthful server-known summary values.
- Modify: `master/public/i18n/en.json` - changed homepage strings where the dictionary requires explicit English entries.
- Modify: `master/public/i18n/zh-Hans.json` - Simplified Chinese homepage strings.
- Modify: `master/public/i18n/zh-TW.json` - Traditional Chinese homepage strings.

### Focused core workflows

- Modify: `master/src/Controllers/AdminController.php:1267-1413` - Tenants list/create/edit view separation.
- Modify: `master/src/Controllers/AdminController.php:2154-2355` - Admin Clients focused list/detail/update views.
- Modify: `master/src/Controllers/AdminController.php:2840-3043` - Admin Transport Endpoints focused list/create/edit views.
- Modify: `master/src/Controllers/AdminController.php:3178-3442` - Slave Nodes `nodes/install/defaults/updates/detail` views.
- Modify: `master/src/Controllers/AdminController.php:3445-3554` - Services list/create view separation.
- Modify: `master/src/Controllers/TenantController.php:135-230` - Tenant Forwards list workspace.
- Modify: `master/src/Controllers/TenantController.php:804-1121` - Tenant Clients list/install/detail views.
- Modify: `master/src/Controllers/TenantController.php:1122-1261` - Tenant Transport Endpoints list/create/edit views.
- Modify: `master/src/Controllers/TenantController.php:1351-1883` - service Forward detail/edit presentation.

### Tests

- Create: `master/tests/leikwan_ui_asset_regression_test.php`
- Create: `master/tests/readable_sheet_design_system_regression_test.php`
- Create: `master/tests/signal_chamber_homepage_regression_test.php`
- Create: `master/tests/focused_slave_nodes_views_regression_test.php`
- Create: `master/tests/focused_core_workflows_regression_test.php`
- Create: `master/tests/core_mobile_resource_rows_regression_test.php`
- Modify: `master/tests/homepage_positioning_regression_test.php`
- Modify: `master/tests/precision_grid_design_system_regression_test.php` - convert to a migration guard or retire after the replacement asset test is green.
- Modify: `master/tests/precision_grid_public_pages_regression_test.php`
- Modify: `master/tests/precision_grid_responsive_accessibility_test.php`
- Retain: `master/tests/precision_grid_privacy_regression_test.php`

## Locked Constraints

- Scope is homepage plus Slave Nodes, Services, Clients, Tenants, Forwards, and Transport Endpoints.
- Other pages receive the Sheet visual standard only.
- No route action, CSRF, authorization, persistence, provisioning, or agent behavior changes.
- No client strategy or client latency appears in Web or panel telemetry.
- Global navigation does not restore CF Location Groups, Forward Combinations, Forward Routes, or Chains.
- Homepage uses a real configured logo and a Canvas scene; it does not use generated SVG illustration.
- Console remains light and readable; cinematic black is homepage-only.

---

### Task 1: Make the New Asset Stack Authoritative

**Files:**
- Create: `master/tests/leikwan_ui_asset_regression_test.php`
- Create: `master/public/assets/leikwan-ui.css`
- Create: `master/public/assets/operator-console.css`
- Create: `master/public/assets/signal-chamber.css`
- Modify: `master/src/Controllers/BaseController.php:1165-1775,1789-2440`
- Modify: `master/public/index.php`
- Modify: `master/public/install.php`
- Modify: `master/src/Controllers/HealthController.php`

- [ ] **Step 1: Write the failing authoritative-asset test**

Create `master/tests/leikwan_ui_asset_regression_test.php`:

```php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$base = (string) file_get_contents($root . '/src/Controllers/BaseController.php');
$index = (string) file_get_contents($root . '/public/index.php');
$install = (string) file_get_contents($root . '/public/install.php');
$health = (string) file_get_contents($root . '/src/Controllers/HealthController.php');

foreach (['leikwan-ui.css', 'operator-console.css', 'signal-chamber.css'] as $asset) {
    if (!is_file($root . '/public/assets/' . $asset)) {
        throw new RuntimeException('missing UI asset ' . $asset);
    }
}
foreach (['/assets/leikwan-ui.css?v=20260725', '/assets/operator-console.css?v=20260725'] as $href) {
    if (!str_contains($base, $href)) {
        throw new RuntimeException('application shell missing ' . $href);
    }
}
foreach (['/assets/leikwan-ui.css?v=20260725', '/assets/signal-chamber.css?v=20260725'] as $href) {
    if (!str_contains($base, $href)) {
        throw new RuntimeException('public shell missing ' . $href);
    }
}
if (str_contains($base, '<style>') || str_contains($base, 'publicPageStyles()')) {
    throw new RuntimeException('BaseController still emits competing embedded CSS');
}
if (str_contains($base . $index . $install . $health, 'precision-grid.css')) {
    throw new RuntimeException('retired precision-grid asset is still loaded');
}
echo "ok\n";
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
php master/tests/leikwan_ui_asset_regression_test.php
```

Expected: FAIL with `missing UI asset leikwan-ui.css`.

- [ ] **Step 3: Create the shared foundation tokens and controls**

Create `master/public/assets/leikwan-ui.css` with these complete base contracts:

```css
:root{
  --page:#eef0ed;--surface:#fff;--surface-soft:#e7ebe7;--ink:#17201c;
  --muted:#5f6964;--line:#b9c0bb;--line-soft:#d4d9d5;--signal:#ef4938;
  --healthy:#139b68;--warning:#b87308;--danger:#c84538;--route:#3188d8;
  --radius:5px;--rail:244px;
}
*{box-sizing:border-box}
html{background:var(--page)}
body{margin:0;min-height:100vh;color:var(--ink);font-family:Inter,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;letter-spacing:0}
a{color:inherit}
:focus-visible{outline:3px solid var(--route);outline-offset:2px}
button,input,select,textarea{font:inherit;letter-spacing:0}
input,select,textarea{min-height:42px;width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:var(--radius);background:#fff;color:var(--ink)}
button,.button{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:8px 12px;border:1px solid var(--ink);border-radius:var(--radius);background:var(--ink);color:#fff;font-weight:700;cursor:pointer}
.button.secondary,.button.ghost{background:#fff;color:var(--ink);border-color:var(--line)}
.button.danger,button.danger{background:var(--danger);border-color:var(--danger);color:#fff}
.muted{color:var(--muted)}
.pg-visually-hidden{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
```

- [ ] **Step 4: Move shell, form, and compatibility rules to external assets**

Create `operator-console.css` with the existing shell class names (`.topbar`, `.layout`, `.sidebar`, `.content-card`, `.nav-link`, `.form-grid`, `.flash`, `.dialog`) and the new typography sizes. Create `signal-chamber.css` with only public shell/homepage/auth rules. Move any still-required rule from `BaseController::publicPageStyles()` and the application `<style>` heredoc into the appropriate asset before deleting those embedded blocks.

The application shell must emit:

```php
<link rel="stylesheet" href="/assets/leikwan-ui.css?v=20260725">
<link rel="stylesheet" href="/assets/operator-console.css?v=20260725">
```

The public shell must emit:

```php
<link rel="stylesheet" href="/assets/leikwan-ui.css?v=20260725">
<link rel="stylesheet" href="/assets/signal-chamber.css?v=20260725">
```

Remove the application `<style>` element, remove the public `<style>` element, and delete `publicPageStyles()`.

- [ ] **Step 5: Align health, bootstrap error, and installer assets**

Load `leikwan-ui.css` and `signal-chamber.css` in `HealthController` and the bootstrap error. Load `leikwan-ui.css` and `operator-console.css` in `install.php`. Use the same `20260725` cache key in every entrypoint.

After all entrypoints load the replacement assets, run:

```powershell
git rm master/public/assets/precision-grid.css
```

Update `precision_grid_design_system_regression_test.php` into a migration guard that requires the new three-asset stack and forbids loading `precision-grid.css`; keep the filename during this release so existing local and deployment test runners do not lose coverage.

- [ ] **Step 6: Run asset, shell, and syntax tests**

Run:

```powershell
php master/tests/leikwan_ui_asset_regression_test.php
php master/tests/i18n_locale_regression_test.php
php master/tests/admin_nav_consistency_regression_test.php
php master/tests/precision_grid_navigation_regression_test.php
php -l master/src/Controllers/BaseController.php
php -l master/src/Controllers/HealthController.php
php -l master/public/index.php
php -l master/public/install.php
```

Expected: every command exits `0` and PHP tests print `ok`.

- [ ] **Step 7: Commit the authoritative asset stack**

```powershell
git add master/public/assets master/src/Controllers/BaseController.php master/src/Controllers/HealthController.php master/public/index.php master/public/install.php master/tests/leikwan_ui_asset_regression_test.php master/tests/precision_grid_design_system_regression_test.php
git commit -m "feat: establish authoritative web design assets"
```

---

### Task 2: Add Reusable Focused-View and Sheet Primitives

**Files:**
- Modify: `master/src/Support/PrecisionGridUi.php`
- Create: `master/tests/readable_sheet_design_system_regression_test.php`
- Modify: `master/public/assets/operator-console.css`

- [ ] **Step 1: Write failing renderer tests**

Create `master/tests/readable_sheet_design_system_regression_test.php` that autoloads `PrecisionGridUi` and asserts escaped, accessible output:

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Support/PrecisionGridUi.php';
use Leikwan\Master\Support\PrecisionGridUi;

$tabs = PrecisionGridUi::viewTabs([
    ['key' => 'nodes', 'label' => 'Nodes', 'href' => '/admin/nodes?view=nodes'],
    ['key' => 'install', 'label' => 'Install', 'href' => '/admin/nodes?view=install'],
], 'nodes');
foreach (['role="tablist"', 'aria-selected="true"', 'data-view="nodes"'] as $needle) {
    if (!str_contains($tabs, $needle)) throw new RuntimeException('tab contract missing ' . $needle);
}

$status = PrecisionGridUi::status('Healthy', 'ok');
if (!str_contains($status, 'pg-status-dot') || !str_contains($status, '>Healthy<')) {
    throw new RuntimeException('status must combine symbol and text');
}

$cell = PrecisionGridUi::resourceCell('shanghai-01', '49.235.160.246');
if (!str_contains($cell, 'pg-resource-primary') || !str_contains($cell, 'pg-resource-secondary')) {
    throw new RuntimeException('resource cell hierarchy missing');
}

$menu = PrecisionGridUi::rowMenu('shanghai-01 actions', '<a href="/edit">Edit</a>');
if (!str_contains($menu, '<details') || !str_contains($menu, 'aria-label="shanghai-01 actions"')) {
    throw new RuntimeException('row menu accessibility missing');
}

$css = (string) file_get_contents(__DIR__ . '/../public/assets/operator-console.css');
foreach (['font-size:14px', 'min-height:52px', '.pg-sheet', '.pg-mobile-resource-row', 'font-variant-numeric:tabular-nums'] as $needle) {
    if (!str_contains(str_replace(' ', '', $css), str_replace(' ', '', $needle))) {
        throw new RuntimeException('Sheet CSS contract missing ' . $needle);
    }
}
echo "ok\n";
```

- [ ] **Step 2: Run the test and verify RED**

Run:

```powershell
php master/tests/readable_sheet_design_system_regression_test.php
```

Expected: fatal error for undefined `PrecisionGridUi::viewTabs()`.

- [ ] **Step 3: Implement the renderer APIs**

Add these complete methods to `PrecisionGridUi`:

```php
public static function normalizeView(?string $value, array $allowed, string $fallback): string
{
    $value = strtolower(trim((string) $value));
    return in_array($value, $allowed, true) ? $value : $fallback;
}

/** @param array<int,array{key:string,label:string,href:string}> $tabs */
public static function viewTabs(array $tabs, string $active): string
{
    $html = '<nav class="pg-view-tabs" role="tablist" aria-label="Page views">';
    foreach ($tabs as $tab) {
        $selected = $tab['key'] === $active;
        $html .= '<a role="tab" data-view="' . self::escape($tab['key']) . '" aria-selected="'
            . ($selected ? 'true' : 'false') . '" class="pg-view-tab' . ($selected ? ' active' : '')
            . '" href="' . self::escape($tab['href']) . '">' . self::escape($tab['label']) . '</a>';
    }
    return $html . '</nav>';
}

public static function status(string $label, string $state): string
{
    return '<span class="pg-status" data-state="' . self::escape($state) . '"><i class="pg-status-dot" aria-hidden="true"></i>'
        . self::escape($label) . '</span>';
}

public static function resourceCell(string $primary, string $secondary = ''): string
{
    return '<span class="pg-resource-cell"><strong class="pg-resource-primary">' . self::escape($primary) . '</strong>'
        . ($secondary !== '' ? '<small class="pg-resource-secondary">' . self::escape($secondary) . '</small>' : '')
        . '</span>';
}

public static function rowMenu(string $label, string $commandsHtml): string
{
    return '<details class="pg-row-menu"><summary aria-label="' . self::escape($label)
        . '">&#8226;&#8226;&#8226;</summary><div class="pg-row-menu-panel">' . $commandsHtml . '</div></details>';
}

public static function detailSection(string $title, string $bodyHtml, string $description = ''): string
{
    return '<section class="pg-detail-section"><header><h2>' . self::escape($title) . '</h2>'
        . ($description !== '' ? '<p>' . self::escape($description) . '</p>' : '')
        . '</header><div class="pg-detail-body">' . $bodyHtml . '</div></section>';
}
```

- [ ] **Step 4: Implement the Sheet CSS contract**

Add `.pg-sheet`, `.pg-sheet-toolbar`, `.pg-sheet-scroll`, `.pg-resource-cell`, `.pg-status`, `.pg-row-menu`, `.pg-detail-section`, and `.pg-mobile-resource-row`. Set table data to `14px`, primary list rows to `min-height:52px`, header text to at least `11px`, and numeric cells to tabular numerals. Core mobile resource rows appear below `720px`; desktop tables remain available above that breakpoint.

- [ ] **Step 5: Run tests and commit**

```powershell
php master/tests/readable_sheet_design_system_regression_test.php
php master/tests/precision_grid_responsive_accessibility_test.php
php -l master/src/Support/PrecisionGridUi.php
git add master/src/Support/PrecisionGridUi.php master/public/assets/operator-console.css master/tests/readable_sheet_design_system_regression_test.php
git commit -m "feat: add readable sheet and focused view primitives"
```

Expected: all tests print `ok` and lint reports no syntax errors.

---

### Task 3: Build the C2 Signal Chamber Homepage

**Files:**
- Create: `master/public/assets/signal-chamber.js`
- Modify: `master/public/assets/signal-chamber.css`
- Modify: `master/src/Controllers/AuthController.php:16-127`
- Create: `master/tests/signal_chamber_homepage_regression_test.php`
- Modify: `master/tests/homepage_positioning_regression_test.php`
- Modify: `master/public/i18n/zh-Hans.json`
- Modify: `master/public/i18n/zh-TW.json`

- [ ] **Step 1: Write the failing homepage contract**

Create a test that reads the homepage method and public assets, requiring:

```php
$requiredMarkup = [
    'class="signal-hero"',
    '<h1>利群會聚 LeiKwan Bridge</h1>',
    'class="signal-canvas"',
    'Open Console',
    'View Network',
    'class="signal-light-detail"',
];
$forbidden = ['pg-home-proof-list', 'pg-home-entry-list', 'client_strategy', 'Latency Best', 'Speed max', 'Random'];
```

Also require `prefers-reduced-motion`, `IntersectionObserver`, `document.visibilityState`, a maximum canvas device-pixel ratio of `2`, and no `requestAnimationFrame` loop when reduced motion is active.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/signal_chamber_homepage_regression_test.php
```

Expected: FAIL on missing `signal-hero`.

- [ ] **Step 3: Render truthful homepage summary values**

In `AuthController::home`, query only server-known counts already available to the panel:

```php
$onlineSlaves = (int) ($this->db->fetchOne(
    'SELECT COUNT(*) AS total FROM nodes WHERE status = :status',
    ['status' => 'active']
)['total'] ?? 0);
$sidecarEndpoints = (int) ($this->db->fetchOne(
    'SELECT COUNT(*) AS total FROM sidecar_points'
)['total'] ?? 0);
$regions = (int) ($this->db->fetchOne(
    'SELECT COUNT(DISTINCT region) AS total FROM nodes WHERE status = :status',
    ['status' => 'active']
)['total'] ?? 0);
```

Label this value `Sidecar Endpoints`; do not call it online state. The existing Admin dashboard derives Sidecar online state through heartbeat inspection rather than a persisted `status` column, and the public homepage must not duplicate that controller-private calculation.

- [ ] **Step 4: Replace homepage markup with C2 composition**

Render:

```html
<section class="signal-hero">
  <canvas class="signal-canvas" aria-hidden="true"></canvas>
  <div class="signal-hero-copy">
    <span class="signal-eyebrow">Multi-Slave Transport</span>
    <h1>利群會聚 LeiKwan Bridge</h1>
    <p class="signal-offer">Speed has more than one way through.</p>
    <div class="signal-actions">...</div>
  </div>
  <dl class="signal-summary">...</dl>
</section>
<section id="network" class="signal-light-detail">...</section>
```

The first command points to the viewer's dashboard or `/login`. `View Network` points to `#network`. The light section contains a concise architecture strip (`Client -> Slave -> Sidecar -> Target`) and server-known availability summary; do not render client strategy or client RTT.

- [ ] **Step 5: Implement bounded Canvas animation**

`signal-chamber.js` must:

```js
(() => {
  const canvas = document.querySelector('.signal-canvas');
  if (!(canvas instanceof HTMLCanvasElement)) return;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  const context = canvas.getContext('2d');
  if (!context) return;
  let visible = true;
  let frame = 0;
  const resize = () => {
    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    const rect = canvas.getBoundingClientRect();
    canvas.width = Math.max(1, Math.floor(rect.width * ratio));
    canvas.height = Math.max(1, Math.floor(rect.height * ratio));
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
  };
  const draw = (time = 0) => {
    const width = canvas.clientWidth;
    const height = canvas.clientHeight;
    context.clearRect(0, 0, width, height);
    ['#ef4938', '#21c58b', '#3188d8', '#f5f5f1'].forEach((color, index) => {
      const y = height * (.38 + index * .1);
      context.strokeStyle = color;
      context.lineWidth = 2;
      context.beginPath();
      context.moveTo(width * .46, y);
      context.bezierCurveTo(width * .62, y, width * .72, height * .56, width * .96, height * .56);
      context.stroke();
    });
    if (!reduced.matches && visible && document.visibilityState === 'visible') frame = requestAnimationFrame(draw);
  };
  new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; if (visible && !frame) draw(); }).observe(canvas);
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible' && visible && !frame) draw(); });
  window.addEventListener('resize', () => { resize(); draw(); }, {passive: true});
  resize(); draw();
})();
```

Animate a bounded phase offset in the production implementation, but retain the same visibility and reduced-motion gates.

- [ ] **Step 6: Implement C2 CSS and translations**

Use a full-width near-black first viewport with no decorative card around the hero. Use signal red, healthy green, route blue, and white lanes. Keep the H1 within `clamp(38px, 6vw, 78px)` and do not scale unrelated console type with viewport width. Make the hero height `min(760px, calc(100svh - 64px))` with a mobile minimum that leaves the next light section visible.

Add or update exact locale keys in Simplified and Traditional Chinese for changed visible strings. Do not translate wire values or route labels.

- [ ] **Step 7: Run homepage tests and commit**

```powershell
php master/tests/signal_chamber_homepage_regression_test.php
php master/tests/homepage_positioning_regression_test.php
php master/tests/precision_grid_privacy_regression_test.php
php master/tests/i18n_locale_regression_test.php
php -l master/src/Controllers/AuthController.php
git add master/src/Controllers/AuthController.php master/public/assets/signal-chamber.css master/public/assets/signal-chamber.js master/public/i18n master/tests/signal_chamber_homepage_regression_test.php master/tests/homepage_positioning_regression_test.php
git commit -m "feat: build signal chamber homepage"
```

---

### Task 4: Rebuild Slave Nodes as the N2 Reference Workflow

**Files:**
- Modify: `master/src/Controllers/AdminController.php:3178-3442`
- Create: `master/tests/focused_slave_nodes_views_regression_test.php`
- Modify: `master/public/assets/operator-console.css`

- [ ] **Step 1: Write the failing focused-view source test**

Extract `nodesPage` from `AdminController` and require:

```php
foreach (['nodes', 'install', 'defaults', 'updates', 'detail'] as $view) {
    assert_contains($method, "'{$view}'");
}
foreach (['pg-view-tabs', 'pg-sheet', 'pg-mobile-resource-row', 'pg-detail-section'] as $class) {
    assert_contains($method, $class);
}
assert_not_contains($nodesListBranch, 'Manual Install Command');
assert_not_contains($nodesListBranch, 'Additional Agent Config JSON');
assert_not_contains($nodesListBranch, '<th>Credential</th>');
assert_not_contains($nodesListBranch, '<th>SSL Certificate</th>');
```

Also assert every existing POST action string remains present in the full method.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/focused_slave_nodes_views_regression_test.php
```

Expected: FAIL because the method has no normalized `view` branches.

- [ ] **Step 3: Normalize view selection and render page tabs**

At the top of `nodesPage` after authorization:

```php
$view = PrecisionGridUi::normalizeView(
    (string) $request->input('view', 'nodes'),
    ['nodes', 'install', 'defaults', 'updates', 'detail'],
    'nodes'
);
$editId = (int) $request->input('edit', 0);
if ($editId > 0) $view = 'detail';
$tabs = PrecisionGridUi::viewTabs([
    ['key'=>'nodes','label'=>'Nodes','href'=>'/admin/nodes?view=nodes'],
    ['key'=>'install','label'=>'Install','href'=>'/admin/nodes?view=install'],
    ['key'=>'defaults','label'=>'Edge Defaults','href'=>'/admin/nodes?view=defaults'],
    ['key'=>'updates','label'=>'Updates','href'=>'/admin/nodes?view=updates'],
], $view);
```

Add `use Leikwan\Master\Support\PrecisionGridUi;` if not already imported.

- [ ] **Step 4: Render the six-column Nodes Sheet**

Build desktop rows with Node/IP, Region/Group, accessible status, load, heartbeat, and `rowMenu`. Keep the selection checkbox only when selected-node update remains applicable. Render a parallel `.pg-mobile-resource-row` for each node with labelled Region, Group, Load, and Heartbeat values.

The row menu contains the existing Edit link, Request Update form, Usage Calendar disclosure, and Delete form. Edit must point to `/admin/nodes?view=detail&edit=<id>`.

- [ ] **Step 5: Move existing content into focused branches**

Assign the existing unmodified form bodies to:

- `$installView`: manual install and new-identity commands
- `$defaultsView`: existing Edge Defaults POST form
- `$updatesView`: group and selected-node update controls
- `$detailView`: existing node edit fields split with `detailSection` into Identity, TLS and Credentials, Agent Runtime, Traffic Pricing, Advanced JSON, and Danger Zone

Only render one branch:

```php
$viewBody = match ($view) {
    'install' => $installView,
    'defaults' => $defaultsView,
    'updates' => $updatesView,
    'detail' => $detailView,
    default => $nodesView,
};
$body = $tabs . $viewBody;
```

- [ ] **Step 6: Run node and existing action tests**

```powershell
php master/tests/focused_slave_nodes_views_regression_test.php
php master/tests/client_install_simplification_regression_test.php
php master/tests/admin_dashboard_actions_regression_test.php
php master/tests/admin_nav_consistency_regression_test.php
php -l master/src/Controllers/AdminController.php
```

Expected: all tests print `ok` and lint passes.

- [ ] **Step 7: Commit Slave Nodes reference workflow**

```powershell
git add master/src/Controllers/AdminController.php master/public/assets/operator-console.css master/tests/focused_slave_nodes_views_regression_test.php
git commit -m "feat: focus slave node operations"
```

---

### Task 5: Focus Admin Tenants, Services, and Clients

**Files:**
- Modify: `master/src/Controllers/AdminController.php:1267-1413,2154-2355,3445-3554`
- Create: `master/tests/focused_admin_resource_views_regression_test.php`

- [ ] **Step 1: Write failing resource-view tests**

For each method require a normalized view, `.pg-sheet`, resource cells, status, row menu, and mobile rows. Require these allowed view sets:

```php
'tenantsPage' => ['list', 'create', 'edit'],
'servicesPage' => ['list', 'create'],
'clientsPage' => ['list', 'create', 'detail', 'updates'],
```

Assert every pre-existing POST action URL remains in its method source.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/focused_admin_resource_views_regression_test.php
```

Expected: FAIL because Admin resource methods still stack forms with lists.

- [ ] **Step 3: Refactor Tenants into list/create/edit views**

Use `view=list` by default. Convert the `edit` query into `view=edit&edit=<id>`. Keep Name/Email/Status/Services/Created or other existing high-value values in the list; move feature flags and account forms to create/edit. Preserve `/admin/tenants`, `/admin/tenants/{id}`, and delete POST targets exactly.

- [ ] **Step 4: Refactor Services into list/create views**

Keep Service/Owner/Plan/Status/Expiry/Bandwidth or Traffic as the visible list fields. Move the creation form out of the list branch. Preserve `/admin/services` POST behavior and existing tenant/plan option generation.

- [ ] **Step 5: Refactor Clients into list/create/detail/update views**

Keep Client/Owner or Group/Status/Last Seen/Version and menu in the list. Put diagnostics, config, update, and destructive commands into detail or updates. Preserve all current client action forms and status derivation.

- [ ] **Step 6: Add mobile resource rows and run tests**

```powershell
php master/tests/focused_admin_resource_views_regression_test.php
php master/tests/admin_client_actions_regression_test.php
php master/tests/admin_dashboard_actions_regression_test.php
php master/tests/tenant_controller_regressions_test.php
php -l master/src/Controllers/AdminController.php
```

- [ ] **Step 7: Commit Admin resource views**

```powershell
git add master/src/Controllers/AdminController.php master/tests/focused_admin_resource_views_regression_test.php
git commit -m "feat: focus admin resource workflows"
```

---

### Task 6: Focus Tenant Forwards and Clients

**Files:**
- Modify: `master/src/Controllers/TenantController.php:135-230,804-1121,1351-1883`
- Create: `master/tests/focused_tenant_resource_views_regression_test.php`

- [ ] **Step 1: Write failing Tenant workflow tests**

Require:

```php
'dashboard' => ['list'],
'clientsPage' => ['list', 'install', 'detail', 'advanced'],
'serviceDetail' => ['overview', 'forwards'],
'editForward' => ['detail'],
```

Require `.pg-sheet`, `.pg-mobile-resource-row`, and existing create/edit/delete/token action URLs. Forbid client strategy names and client latency fields in every extracted method.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/focused_tenant_resource_views_regression_test.php
```

Expected: FAIL on missing focused view markers.

- [ ] **Step 3: Render Tenant Forwards as the primary list workspace**

Keep Service/Forward/Local Port/Target/Status and row menu as high-value fields. Use the existing service detail and quick-create routes. Do not add a Web strategy selector. Mobile rows expose the same values with labels.

- [ ] **Step 4: Split Tenant Clients into list/install/detail/advanced**

Move Linux install commands and advanced defaults out of the default list. Keep update, diagnostics, token, and delete actions in detail or row menus, preserving feature-flag gates.

- [ ] **Step 5: Simplify service and Forward detail presentation**

Use `detailSection` for Summary, Forwards, Client Configuration, and Danger Zone. Preserve all existing endpoint and feature calculations. Do not merge unrelated services or Forwards into generic cards.

- [ ] **Step 6: Run Tenant tests and commit**

```powershell
php master/tests/focused_tenant_resource_views_regression_test.php
php master/tests/tenant_controller_regressions_test.php
php master/tests/tenant_forward_combination_regression_test.php
php master/tests/precision_grid_privacy_regression_test.php
php -l master/src/Controllers/TenantController.php
git add master/src/Controllers/TenantController.php master/tests/focused_tenant_resource_views_regression_test.php
git commit -m "feat: focus tenant forward and client workflows"
```

Expected: focused and existing regression tests pass without exposing strategy state.

---

### Task 7: Focus Admin and Tenant Transport Endpoints

**Files:**
- Modify: `master/src/Controllers/AdminController.php:2840-3043`
- Modify: `master/src/Controllers/TenantController.php:1122-1350`
- Create: `master/tests/focused_transport_endpoints_views_regression_test.php`

- [ ] **Step 1: Write the failing transport endpoint tests**

Require `list/create/edit/updates` views for Admin and `list/create/edit` for Tenant. Require Endpoint/Region or Sidecar/Status/Address/Heartbeat/Menu as list values and preserve all current save, update, and delete POST targets.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/focused_transport_endpoints_views_regression_test.php
```

Expected: FAIL because endpoint forms and lists share one stacked page.

- [ ] **Step 3: Refactor Admin Transport Endpoints**

Default to `view=list`; move creation and editing to their branches; move batch update controls to `updates`. Keep existing Sidecar terminology only where it describes the runtime object; navigation remains `Transport Endpoints`.

- [ ] **Step 4: Refactor Tenant Transport Endpoints**

Default to the readable list. Use existing permission gates for create/edit/update/delete. Render a mobile resource row for each endpoint.

- [ ] **Step 5: Run endpoint and privacy tests, then commit**

```powershell
php master/tests/focused_transport_endpoints_views_regression_test.php
php master/tests/tenant_controller_regressions_test.php
php master/tests/precision_grid_privacy_regression_test.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Controllers/TenantController.php
git add master/src/Controllers/AdminController.php master/src/Controllers/TenantController.php master/tests/focused_transport_endpoints_views_regression_test.php
git commit -m "feat: focus transport endpoint workflows"
```

---

### Task 8: Apply the Sheet Standard to Every Remaining Table

**Files:**
- Modify: `master/public/assets/operator-console.css`
- Modify: `master/src/Controllers/BaseController.php`
- Create: `master/tests/all_tables_readable_sheet_regression_test.php`

- [ ] **Step 1: Write the failing global Sheet coverage test**

The test scans AdminController, TenantController, AuthController Knowledgebase output, HealthController, and install.php. It counts `<table` occurrences and requires each table to be inside `.table-scroll`, `.table-wrap`, or `.pg-sheet-scroll`; it also requires BaseController to wrap application page bodies with `.pg-console`.

Allow explicit exceptions only for mail/plain-text templates that cannot render in the application shell. Encode each exception as an exact file/method pair in the test rather than a broad regex.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/all_tables_readable_sheet_regression_test.php
```

Expected: FAIL listing the first unwrapped table method.

- [ ] **Step 3: Add global compatibility selectors**

In `operator-console.css`, style `.pg-console table`, `.table-wrap table`, and `.table-scroll table` with the approved type, padding, divider, sticky header, hover, numeric alignment, and bounded overflow. Do not force a mobile row transformation on non-core tables; retain horizontal scrolling there.

- [ ] **Step 4: Wrap remaining unbounded tables**

Add the smallest existing wrapper class around each failing table. Do not change its columns, actions, routes, or data. Re-run the test after each controller file until no unwrapped tables remain.

- [ ] **Step 5: Run table coverage and representative controller tests**

```powershell
php master/tests/all_tables_readable_sheet_regression_test.php
php master/tests/admin_knowledgebase_regression_test.php
php master/tests/knowledgebase_reader_regression_test.php
php master/tests/tenant_controller_regressions_test.php
php master/tests/admin_nav_consistency_regression_test.php
```

- [ ] **Step 6: Commit all-table readability**

```powershell
git add master/public/assets/operator-console.css master/src/Controllers master/public/install.php master/tests/all_tables_readable_sheet_regression_test.php
git commit -m "fix: make all panel sheets readable"
```

---

### Task 9: Complete Responsive, Accessibility, and Privacy Gates

**Files:**
- Create: `master/tests/core_mobile_resource_rows_regression_test.php`
- Modify: `master/tests/precision_grid_responsive_accessibility_test.php`
- Modify: `master/tests/precision_grid_privacy_regression_test.php`
- Modify: `master/public/assets/leikwan-ui.css`
- Modify: `master/public/assets/operator-console.css`
- Modify: `master/public/assets/signal-chamber.css`

- [ ] **Step 1: Add mobile resource and interaction source guards**

Require each core method to emit `.pg-mobile-resource-row`, each tablist to expose ARIA states, each menu summary to have an accessible label, and every input to retain a visible label. Require `@media(max-width:720px)` rules and stable button/menu dimensions.

- [ ] **Step 2: Expand privacy guards**

Search all Web controllers, UI helpers, CSS, JS, and locale files for forbidden client state:

```php
foreach (['client_strategy', 'strategy_mode', 'latency_ms_by_node', 'Latency Best', 'Speed max', '>Random<'] as $forbidden) {
    if (str_contains($webSource, $forbidden)) throw new RuntimeException('Web exposes local state ' . $forbidden);
}
```

Exclude client application source trees from this Web-only test because Windows/Android must show their local choices.

- [ ] **Step 3: Run accessibility, mobile, privacy, and i18n tests**

```powershell
php master/tests/core_mobile_resource_rows_regression_test.php
php master/tests/precision_grid_responsive_accessibility_test.php
php master/tests/precision_grid_privacy_regression_test.php
php master/tests/i18n_locale_regression_test.php
```

Expected: four `ok` results.

- [ ] **Step 4: Commit completed UI gates**

```powershell
git add master/public/assets master/tests/core_mobile_resource_rows_regression_test.php master/tests/precision_grid_responsive_accessibility_test.php master/tests/precision_grid_privacy_regression_test.php master/public/i18n
git commit -m "test: guard readable responsive web workflows"
```

---

### Task 10: Run Full Regression and Static Verification

**Files:**
- No production edits unless a regression is traced to this branch.

- [ ] **Step 1: Run every PHP regression and compare the known baseline**

Use the repository's per-file runner:

```powershell
$passed=0; $failed=@()
Get-ChildItem master/tests -Filter '*.php' | Sort-Object Name | ForEach-Object {
  $output = & php $_.FullName 2>&1
  if ($LASTEXITCODE -eq 0) { $passed++ } else { $failed += $_.Name }
}
"PHP_PASS=$passed PHP_FAIL=$($failed.Count)"
$failed
```

Expected: no failure that is absent from the pre-branch baseline. Re-run every failure on the base commit worktree before classifying it as baseline.

- [ ] **Step 2: Run PHP syntax across changed PHP files**

```powershell
git diff --name-only master...HEAD -- '*.php' | ForEach-Object {
  php -l $_
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}
```

- [ ] **Step 3: Run diff and privacy checks**

```powershell
git diff --check master...HEAD
php master/tests/precision_grid_privacy_regression_test.php
rg -n 'CF Location Groups|Forward Combinations|Forward Routes|>Chains<' master/src/Controllers/BaseController.php master/src/Support/PrecisionGridUi.php
```

Expected: diff check and privacy test pass; the final `rg` finds no restored navigation labels.

---

### Task 11: Perform gstack Visual QA and Fix Findings

**Files:**
- Modify only the smallest controller or CSS/JS asset responsible for an observed defect.
- Store ignored screenshots under `.superpowers/qa/2026-07-25-readable-web/` or a temp directory.

- [ ] **Step 1: Start a local representative server or use the authenticated live staging path**

Use the gstack `/browse` binary required by `AGENTS.md`. Do not use Claude-in-Chrome tools.

- [ ] **Step 2: Verify homepage viewports**

At `1440x1024`, `1024x768`, `390x844`, and `360x800`, verify:

- Canvas has nonblank pixels.
- The brand/H1 is visible in the first viewport.
- The next light section is partially visible.
- Page-level horizontal overflow is false.
- No console errors occur.
- Reduced-motion emulation produces a static scene without a running animation loop.

- [ ] **Step 3: Verify authenticated core workflows**

For Slave Nodes, Services, Admin Clients, Tenants, Tenant Forwards, Tenant Clients, and Admin/Tenant Transport Endpoints:

- capture desktop and mobile screenshots;
- open every page-local tab/view;
- exercise one filter and one row menu;
- open one detail view;
- verify direct-link refresh keeps the selected view;
- verify no overlapping menu, table, header, or form controls;
- verify all visible text is readable at default browser zoom.

- [ ] **Step 4: Sample non-core Sheet pages**

Open Plans, Credentials, Slave Groups, Knowledgebase, and installer tables. Confirm the Sheet type, padding, headers, overflow, and status treatment apply without changing their workflows.

- [ ] **Step 5: Fix each finding with a focused regression**

For every source bug, add or extend a PHP test before the code fix. For every visual-only defect, record the failing viewport and selector, change the smallest CSS rule, and recapture the same viewport.

- [ ] **Step 6: Commit visual QA corrections**

```powershell
git add master/public/assets master/src/Controllers master/tests
git commit -m "fix: resolve readable web visual qa findings"
```

Skip this commit only if no source changes were required.

---

### Task 12: Merge, Deploy, and Verify Production

**Files:**
- Production overlay contains only `git diff --name-only 4eede3b..master` tracked paths.

- [ ] **Step 1: Use Superpowers verification-before-completion**

Re-run the final focused UI tests, full PHP baseline comparison, syntax checks, `git diff --check`, and `git status --porcelain`. Do not proceed if a new failure or uncommitted file exists.

- [ ] **Step 2: Merge the feature branch into clean `master`**

Use `git merge --ff-only` when possible. Verify `master` points at the reviewed feature head and remains clean.

- [ ] **Step 3: Build a narrow tracked-file release archive**

```powershell
$baseCommit = '4eede3b'
$files = @(git diff --name-only $baseCommit HEAD)
git archive --format=tar --output="$env:TEMP\leikwan-readable-web.tar" HEAD -- $files
Get-FileHash "$env:TEMP\leikwan-readable-web.tar" -Algorithm SHA256
```

- [ ] **Step 4: Back up and overlay the panel tree through WSL SSH**

Use credentials from `leikwan-v3.txt` without printing them. Target:

```text
root@10.0.10.196:/www/wwwroot/v3.leikwanhost.com/leikwan-v3
```

On the server:

1. verify the uploaded archive SHA-256;
2. reject absolute or parent-traversal archive paths;
3. archive every existing destination file under `/root/codex-backups/readable-web-<UTC timestamp>/`;
4. extract with `--no-same-owner`;
5. lint every changed PHP file;
6. run focused UI, i18n, privacy, and Sheet tests.

- [ ] **Step 5: Bust production assets**

The final asset query version must differ from the currently deployed `20260725.3`. Use one release key across all entrypoints and verify the live network panel requests the new URL with HTTP 200.

- [ ] **Step 6: Run post-deploy gstack canary checks**

Verify public homepage desktop/mobile, authenticated Slave Nodes desktop/mobile, one Admin list, one Tenant list, and one non-core Sheet page. Confirm:

- HTTP 200 for HTML/CSS/JS;
- no console errors;
- no page-level overflow;
- new asset version loaded;
- old Precision Grid homepage classes absent;
- client strategy/latency absent from Web output.

- [ ] **Step 7: Report exact outcome**

Report master commit, production backup path, asset version, test counts, sampled URLs, and any known baseline failures. Do not claim client fleet updates or live Forward throughput unless separately verified.

---

## Execution Order and Checkpoints

1. Tasks 1-3 establish the assets and approved homepage.
2. Task 4 is the reference N2 workflow; stop for a visual checkpoint after Slave Nodes.
3. Tasks 5-7 extend the pattern only after the reference page passes review.
4. Tasks 8-9 apply full-site Sheet/readability and harden accessibility/privacy.
5. Tasks 10-11 complete regression and visual QA.
6. Task 12 merges and deploys only after fresh verification evidence.

The implementation must not deploy an intermediate state where the new markup references an old cached stylesheet.
