# Full Panel i18n Pass Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend the Traditional Chinese and Simplified Chinese locale packs so the admin and tenant panel surfaces are covered consistently, then deploy the updated locale assets to the panel.

**Architecture:** Reuse the existing JSON dictionary patch model in `master/public/i18n/`. Strengthen the locale regression to scan the scoped controller files for uncovered strings, allowlist non-localizable config/value tokens, add missing human-facing translations to `zh-TW.json`, regenerate `zh-Hans.json`, then verify locally and remotely.

**Tech Stack:** PHP controllers, JSON locale dictionaries, Python helper scripts, OpenCC, PHP regression tests, SSH/SCP deployment

---

### Task 1: Expand regression scope to admin and tenant controllers

**Files:**
- Modify: `master/tests/i18n_locale_regression_test.php`
- Test: `master/tests/i18n_locale_regression_test.php`

- [ ] **Step 1: Write the failing test**
- [ ] **Step 2: Run `php master/tests/i18n_locale_regression_test.php` and capture the uncovered strings**
- [ ] **Step 3: Keep only non-localizable fragments in the allowlist**
- [ ] **Step 4: Re-run to confirm the remaining failures are genuine translation gaps**

### Task 2: Fill missing admin and tenant translations

**Files:**
- Modify: `master/public/i18n/zh-TW.json`
- Modify: `master/public/i18n/zh-Hans.json`

- [ ] **Step 1: Extract missing human-facing text/attribute keys from the red test output**
- [ ] **Step 2: Add polished Traditional Chinese translations to `zh-TW.json`**
- [ ] **Step 3: Regenerate `zh-Hans.json` from the updated Traditional Chinese pack and adjust any awkward terms if needed**
- [ ] **Step 4: Re-run the locale regression until it passes**

### Task 3: Verify panel-wide locale coverage and deploy

**Files:**
- Modify: `master/src/Controllers/BaseController.php` (only if locale wiring changes are still needed)
- Modify: `master/public/install.php` (only if locale wiring changes are still needed)
- Test: `master/tests/i18n_locale_regression_test.php`

- [ ] **Step 1: Run local verification (`php master/tests/i18n_locale_regression_test.php`, PHP syntax checks, heuristic scan)**
- [ ] **Step 2: Upload the updated locale/test files to the panel host with a remote backup first**
- [ ] **Step 3: Re-run the locale regression and syntax checks on the panel host**
- [ ] **Step 4: Record deployment evidence in the planning files**
