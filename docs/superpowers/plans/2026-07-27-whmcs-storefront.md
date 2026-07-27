# WHMCS-style Storefront Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the tenant plans content area with the approved WHMCS-faithful storefront without changing purchase or billing behavior.

**Architecture:** Keep `TenantController::plansPage()` as the server-rendered source and add only storefront-scoped selectors to the existing panel stylesheet. Categorization remains presentation-only and falls back to All Packages when reliable direction metadata is unavailable.

**Tech Stack:** PHP 8, server-rendered HTML, CSS, repository PHP regression scripts, gstack `/browse`.

---

### Task 1: Lock the storefront contract with tests

**Files:**
- Modify: `master/tests/tenant_plan_storefront_regression_test.php`
- Inspect: `master/src/Controllers/TenantController.php:576-738`

- [ ] Add assertions for `storefront-shell`, `storefront-categories`, `storefront-products`, `storefront-product`, `storefront-product-price`, `storefront-order`, and the unchanged `/tenant/plans/purchase` form action.
- [ ] Add forbidden assertions for the old nested purchase wrapper `<section class="card" style="margin-top:20px;">` and inline product layout styles.
- [ ] Run `php master/tests/tenant_plan_storefront_regression_test.php`; expect failure on the first missing scoped selector.
- [ ] Commit the failing contract as `test: define whmcs storefront structure`.

### Task 2: Render the WHMCS-style storefront

**Files:**
- Modify: `master/src/Controllers/TenantController.php:576-738`
- Test: `master/tests/tenant_plan_storefront_regression_test.php`

- [ ] Replace the old product card markup with a `storefront-shell` containing an accessible category navigation and `storefront-products` grid.
- [ ] Always render All Packages. Render JP Aggregate or Single Route only when the plan rows expose reliable structured direction/aggregation data; otherwise do not guess from the plan name.
- [ ] Render escaped feature lines on the left and price, period, traffic, bandwidth, client limits, reset price, and order action on the right.
- [ ] Preserve the hidden CSRF token, plan ID, service-name input, bandwidth input, POST action, failure redirect, and cancel URL exactly.
- [ ] Preserve the empty-store state and current-services table after the store workspace.
- [ ] Run `php -l master/src/Controllers/TenantController.php` and the focused regression test; expect both to pass.
- [ ] Commit as `feat: render whmcs-style tenant storefront`.

### Task 3: Add scoped responsive styling

**Files:**
- Modify: `master/public/assets/leikwan-ui.css`
- Test: `master/tests/tenant_plan_storefront_regression_test.php`

- [ ] Add storefront-only layout rules: a stable category column, two equal product tracks, fixed price column constraints, compact bordered rows, and green primary order action.
- [ ] At tablet width, allow a single product track; at mobile width, move categories above products and prevent horizontal overflow.
- [ ] Do not redefine global `.card`, `.grid`, `.button`, or shell selectors.
- [ ] Extend the regression test to assert the scoped stylesheet selectors and responsive breakpoint exist.
- [ ] Run the focused storefront test plus `php master/tests/tenant_controller_regressions_test.php`; expect both to pass.
- [ ] Commit as `style: add responsive whmcs storefront layout`.

### Task 4: Verify and deploy the committed storefront

**Files:**
- Verify: committed files from Tasks 1-3
- Deploy target: path discovered from `leikwan-v3.txt`, verified against the active web service before upload

- [ ] Run `git diff --check`, PHP syntax checks for changed PHP files, storefront tests, tenant controller regressions, and plan visibility tests.
- [ ] Start the local app using its documented entrypoint and render `/tenant/plans` with an authenticated fixture.
- [ ] Use gstack `/browse` for desktop and mobile screenshots; verify no overlap, overflow, duplicate products, or broken expanded order form.
- [ ] Create file and database backups on the server, record their paths, and verify they are non-empty.
- [ ] Deploy only committed bytes using a git archive overlay.
- [ ] Run the production migration runner and prove zero pending migrations.
- [ ] Verify tracked-file equality, PHP syntax, origin HTTP, and public HTTP through `http://127.0.0.1:2080`.

