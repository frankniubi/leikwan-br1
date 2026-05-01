# Plan Forward Combination Management Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Switch the admin `Plan` page from low-level route selectors to grouped `Forward Combination` management while keeping untouched legacy plans working until first edit/save.

**Architecture:** Add a shared plan mode/cutover path in plan persistence, update the admin plan UI to render grouped forward-combination selectors, and keep runtime authorization dual-mode: legacy rules for untouched plans, combination allowlist for edited/new plans. Strengthen regression coverage around admin rendering and plan authorization behavior before shipping.

**Tech Stack:** PHP controllers, ProvisioningService persistence logic, PHP source/regression tests

---

### Task 1: Add failing regression coverage for plan management cutover

**Files:**
- Create: `master/tests/admin_plan_forward_combination_management_test.php`
- Modify: `master/tests/provisioning_forward_combination_regression_test.php`
- Modify: `master/tests/tenant_forward_combination_regression_test.php`

- [ ] **Step 1: Write a failing admin page regression**
- [ ] **Step 2: Write a failing persistence/runtime regression for legacy-vs-combination plan behavior**
- [ ] **Step 3: Run the focused tests to confirm they fail for the right reason**

### Task 2: Implement plan persistence and cutover behavior

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`

- [ ] **Step 1: Add storage/load handling for allowed forward combination IDs and plan cutover mode**
- [ ] **Step 2: Keep untouched legacy plans on old behavior**
- [ ] **Step 3: Switch edited/new plans to combination-based authorization on save**
- [ ] **Step 4: Run persistence/runtime tests and confirm green**

### Task 3: Replace admin plan UI with grouped forward combinations

**Files:**
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Controllers/BaseController.php` (only if helper extraction is needed)

- [ ] **Step 1: Remove old low-level plan selectors from the form UI**
- [ ] **Step 2: Render grouped forward-combination selection UI with useful summary metadata**
- [ ] **Step 3: Update plan list/table summaries to show forward combinations instead of old low-level columns**
- [ ] **Step 4: Run admin-page regressions and syntax checks**

### Task 4: Verify and deploy

**Files:**
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/tests/admin_plan_forward_combination_management_test.php`

- [ ] **Step 1: Run the full focused verification set locally**
- [ ] **Step 2: Upload the changed panel files to the panel host with backup**
- [ ] **Step 3: Re-run the verification subset remotely**
- [ ] **Step 4: Record rollout notes and any legacy-plan caveats**
