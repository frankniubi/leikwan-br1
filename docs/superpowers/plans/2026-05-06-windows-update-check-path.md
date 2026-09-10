# Windows Update Check Path Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the installed Windows app's “Check update” action work reliably end-to-end, then publish the new Windows build to panel so update checks succeed in production.

**Architecture:** Treat the update flow as an end-to-end chain: installed app click handler, local updater/service logic, panel manifest/artifact endpoints, and published installer/binary metadata. Fix the smallest authoritative break in source, then publish and verify against the installed app.

**Tech Stack:** C# Windows desktop app, panel PHP artifact endpoints, local Windows install files, published Windows artifacts.

---

### Task 1: Trace the live installed Windows app behavior

**Files:**
- Inspect: `C:\Users\frankniubi\AppData\Local\LeiKwan Bridge\*`
- Inspect: `C:\ProgramData\Leikwan\ClientAgent\logs\*`
- Inspect: local installed manifest/update files

- [ ] **Step 1: Reproduce the “Check update” click on the installed app**
- [ ] **Step 2: Capture local logs/state/files touched by the action**
- [ ] **Step 3: Identify whether the failure is UI-only, local updater-only, or panel-response-related**

### Task 2: Trace the source update path

**Files:**
- Inspect: `LeikwanBridge.Desktop/src/LeikwanBridge.App/**`
- Inspect: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/**`
- Inspect: `install/manage-client.ps1`
- Inspect: `install/install-client.ps1`

- [ ] **Step 1: Find the click handler / command path for “Check update”**
- [ ] **Step 2: Find how the app queries panel metadata and decides update availability**
- [ ] **Step 3: Map any local helper or manifest dependency used by the app**

### Task 3: Trace panel Windows artifact and manifest path

**Files:**
- Inspect: `master/src/Controllers/ArtifactController.php`
- Inspect: `master/src/Bootstrap.php`
- Inspect: `artifacts/windows/**`
- Inspect: `artifacts/versions.json`

- [ ] **Step 1: Identify the exact endpoint(s) the Windows updater depends on**
- [ ] **Step 2: Verify the panel is serving current Windows artifact metadata correctly**
- [ ] **Step 3: Determine whether the bug is source logic, stale published artifact, or missing manifest wiring**

### Task 4: Add failing regression coverage if source is wrong

**Files:**
- Modify/Create: `LeikwanBridge.Desktop/tests/**`
- Modify/Create: `master/tests/**` if panel logic needs coverage

- [ ] **Step 1: Add the smallest failing regression test that reproduces the broken update-check decision path**
- [ ] **Step 2: Run the narrow test and verify it fails correctly**

### Task 5: Implement the minimal fix

**Files:**
- Modify only the exact files identified in Tasks 2-3

- [ ] **Step 1: Implement the smallest source fix for the broken update-check path**
- [ ] **Step 2: Re-run failing tests and verify they pass**
- [ ] **Step 3: Run targeted related suites**

### Task 6: Publish and verify end-to-end

**Files:**
- Publish: updated Windows artifact(s)
- Sync: panel-published Windows files
- Verify: installed Windows app

- [ ] **Step 1: Build/publish the new Windows app artifact**
- [ ] **Step 2: Sync installer/manifest/version metadata to panel**
- [ ] **Step 3: Verify panel endpoints serve the new Windows version**
- [ ] **Step 4: Trigger “Check update” on the installed app and verify response**
- [ ] **Step 5: If update is offered, verify download/apply flow or exact availability message**
