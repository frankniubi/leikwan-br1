# Panel Client Install Simplification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Simplify the admin and tenant client pages around `Client ID + Token`, app downloads, collapsed advanced settings, and remove panel-driven remote SSH install for Linux client/slave flows.

**Architecture:** Refactor the shared client install helper in `BaseController` so both admin and tenant pages inherit the new install story, then simplify each page's surrounding layout without changing the underlying client model. Remove the remote-SSH product surface by deleting the relevant UI copy, controller methods, and router/artifact exposure.

**Tech Stack:** PHP 8 controllers/views, string-based regression tests under `master/tests`

---

### Task 1: Add regression coverage for the new install story and remote-SSH removal

**Files:**
- Create: `master/tests/client_install_simplification_regression_test.php`
- Test: `master/tests/client_install_simplification_regression_test.php`

- [ ] **Step 1: Write the failing regression test**

Add a new PHP regression script that reads:

- `master/src/Controllers/BaseController.php`
- `master/src/Controllers/TenantController.php`
- `master/src/Controllers/AdminController.php`
- `master/src/Bootstrap.php`
- `master/src/Controllers/ArtifactController.php`

and asserts the presence of the new primary strings:

- `App Download`
- `Client ID`
- `Token`
- `Advanced client settings`

and the absence of remote SSH product-surface strings/routes:

- `Remote SSH Install`
- `/admin/nodes/install-remote`
- `/artifacts/remote-install-slave.sh`
- `/artifacts/remote-install-client.sh`
- `/artifacts/remote-install-client-agent.sh`

- [ ] **Step 2: Run the regression test to verify it fails**

Run:

```powershell
php master/tests/client_install_simplification_regression_test.php
```

Expected:

- FAIL because the current sources still contain the old install UI and remote-install routes/copy

### Task 2: Refactor the shared client install helper

**Files:**
- Modify: `master/src/Controllers/BaseController.php`
- Test: `master/tests/client_install_simplification_regression_test.php`

- [ ] **Step 1: Implement the new helper shape**

Update `renderClientAgentInstallDetails(...)` so it:

- makes `App Download` the primary section
- promotes `Client ID` and `Token`
- keeps `Windows App`, `Android App`, and `Linux Install` primary
- demotes Windows manager/helper scripts, Android import string, and uninstall into secondary details

- [ ] **Step 2: Re-run the regression test**

Run:

```powershell
php master/tests/client_install_simplification_regression_test.php
```

Expected:

- still FAIL because page-level layout and remote-SSH removal are not done yet

### Task 3: Simplify the tenant client page layout

**Files:**
- Modify: `master/src/Controllers/TenantController.php`
- Test: `master/tests/client_install_simplification_regression_test.php`

- [ ] **Step 1: Update the tenant page**

Adjust `/tenant/clients` so it:

- adds a top `App Download` section
- keeps the per-client overview concise
- moves low-frequency fields into a collapsed `Advanced client settings`

- [ ] **Step 2: Re-run the regression test**

Run:

```powershell
php master/tests/client_install_simplification_regression_test.php
```

Expected:

- partial progress; tenant-related assertions pass, admin/remote-install assertions may still fail

### Task 4: Simplify the admin client page layout

**Files:**
- Modify: `master/src/Controllers/AdminController.php`
- Test: `master/tests/client_install_simplification_regression_test.php`

- [ ] **Step 1: Update the admin clients page**

Adjust `/admin/clients` so it:

- matches the same app-first install story
- shows `Client ID + Token` clearly
- keeps admin ownership/management controls
- moves advanced fields into a collapsed `Advanced client settings`

- [ ] **Step 2: Re-run the regression test**

Run:

```powershell
php master/tests/client_install_simplification_regression_test.php
```

Expected:

- still FAIL on remote-install route/controller assertions until cleanup is done

### Task 5: Remove panel-driven remote SSH install

**Files:**
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/ArtifactController.php`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Controllers/TenantController.php`
- Test: `master/tests/client_install_simplification_regression_test.php`

- [ ] **Step 1: Remove remote-install UI and handlers**

Delete or remove references to:

- admin node remote SSH install form/copy
- admin client remote install method
- tenant client remote install method
- admin node remote install method

- [ ] **Step 2: Remove remote-install artifact routes and passthrough methods**

Delete the router registrations and `ArtifactController` passthroughs for:

- `/artifacts/remote-install-slave.sh`
- `/artifacts/remote-install-client.sh`
- `/artifacts/remote-install-client-agent.sh`

- [ ] **Step 3: Re-run the regression test**

Run:

```powershell
php master/tests/client_install_simplification_regression_test.php
```

Expected:

- PASS

### Task 6: Run supporting regression checks

**Files:**
- Test: `master/tests/client_install_simplification_regression_test.php`
- Test: `master/tests/tenant_controller_regressions_test.php`
- Test: `master/tests/admin_dashboard_actions_regression_test.php`
- Test: `master/tests/client_agent_update_artifact_regression_test.php`

- [ ] **Step 1: Run focused regression suite**

Run:

```powershell
php master/tests/client_install_simplification_regression_test.php
php master/tests/tenant_controller_regressions_test.php
php master/tests/admin_dashboard_actions_regression_test.php
php master/tests/client_agent_update_artifact_regression_test.php
```

Expected:

- all selected regression checks print `ok`

- [ ] **Step 2: Review diff**

Run:

```powershell
git diff -- master/src/Bootstrap.php master/src/Controllers/ArtifactController.php master/src/Controllers/BaseController.php master/src/Controllers/TenantController.php master/src/Controllers/AdminController.php master/tests/client_install_simplification_regression_test.php docs/superpowers/plans/2026-04-18-panel-client-install-simplification.md
```

Expected:

- diff only shows the client-page simplification and remote-install cleanup needed by the approved spec
