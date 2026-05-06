# Bond Leg Filtering and Windows Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permanently prevent fragile bond legs from degrading forwarded throughput by filtering poor legs before runtime generation, and keep Windows behavior in parity with Linux.

**Architecture:** Apply the fix at the authoritative config/rendering layer, not by hand-editing runtime scripts. The panel or shared config selection path should produce safer endpoint lists for bond forwards, and both Linux and Windows clients should consume that same filtered list. Use existing health evidence and minimum-leg floors so behavior stays adaptive and safe.

**Tech Stack:** PHP panel controllers/support code, Python Linux client runtime, C# Windows desktop client, existing repo regression tests.

---

### Task 1: Map the authoritative endpoint selection layer

**Files:**
- Modify: `docs/superpowers/plans/2026-05-06-bond-leg-filtering-and-windows-parity.md`
- Inspect: `master/src/Support/ProvisioningService.php`
- Inspect: `master/src/Controllers/ClientAgentController.php`
- Inspect: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/*.cs`
- Inspect: `client-agent/client_agent.py`

- [ ] **Step 1: Trace where bond endpoint lists are built for Linux and Windows**
- [ ] **Step 2: Confirm whether filtering belongs in panel provisioning, Linux client, Windows client, or a shared fallback in clients**
- [ ] **Step 3: Update this plan file with the exact chosen layer and touched files**

### Task 2: Write failing panel regression tests

**Files:**
- Create or Modify: `master/tests/*bond*filter*test.php`
- Inspect: `master/tests/bond_quic_capability_regression_test.php`
- Inspect: `master/tests/default_client_provisioning_test.php`

- [ ] **Step 1: Add a failing regression test proving degraded legs are filtered from bond endpoint output when stronger healthy legs exist**
- [ ] **Step 2: Add a failing regression test proving a minimum endpoint floor is preserved**
- [ ] **Step 3: Run only those tests and verify they fail for the expected reason**

### Task 3: Write failing client parity tests

**Files:**
- Modify: `client-agent/tests/test_client_agent_bond_endpoint_health.py`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/*`

- [ ] **Step 1: Add a failing Linux-side regression test for consuming already-filtered endpoints without re-expanding bad ones**
- [ ] **Step 2: Add a failing Windows-side regression test proving filtered bond endpoint lists are respected in runtime launch/config generation**
- [ ] **Step 3: Run the narrow test commands and verify they fail correctly**

### Task 4: Implement minimal permanent filtering logic

**Files:**
- Modify: `master/src/Support/ProvisioningService.php` or exact authoritative builder from Task 1
- Modify: any small helper file chosen in Task 1
- Modify: `client-agent/client_agent.py` only if needed for fallback parity
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/*` only if needed for parity

- [ ] **Step 1: Implement filtering using existing health evidence and a minimum floor**
- [ ] **Step 2: Keep the diff small, no unrelated refactors**
- [ ] **Step 3: Re-run the failing tests and verify they pass**

### Task 5: Full targeted verification

**Files:**
- Verify only

- [ ] **Step 1: Run all touched PHP regression tests**
- [ ] **Step 2: Run all touched Python client tests**
- [ ] **Step 3: Run all touched Windows desktop tests**
- [ ] **Step 4: Confirm no targeted regression remains**

### Task 6: Deploy and live-verify

**Files:**
- Modify live panel files corresponding to the final source diff
- Verify on `sh36.ctyun.mcbar.cc`
- Verify on installed Windows app logic if applicable

- [ ] **Step 1: Back up live panel files before deploy**
- [ ] **Step 2: Sync the authoritative source/artifact files to panel**
- [ ] **Step 3: Refresh/restart only what is needed**
- [ ] **Step 4: Reproduce `iperf3 -c 127.0.0.1 -p 11030 -R -b 400M` on sh36 and capture the result**
- [ ] **Step 5: Verify Windows app logic uses the same filtered endpoint behavior path**
