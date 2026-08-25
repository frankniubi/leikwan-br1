# Remove cf_mode Support from Linux and Windows Clients

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (- [ ]) syntax.

**Goal:** Remove client-side runtime support for backend_mode=cf_mode on Linux and Windows while preserving server-side legacy compatibility and any generic transport code still used by supported modes.

**Architecture:** Client configuration filtering rejects cf_mode before runtime construction. Existing cf_mode forward processes are stopped and cleaned during the same sync cycle. CF-specific selection/health branches are deleted or replaced with neutral generic bond behavior only where the same behavior is valid for current_mode, leigod_mode, or other supported contracts. Panel provisioning and sidecar server code are outside this client-only change.

**Tech Stack:** Python 3, Go-adjacent Linux runtime generation, C#/.NET, xUnit, pytest, and source-contract tests.

---

## Scope Boundary

In scope:

- client-agent/client_agent.py
- client-agent/install_forward_runtime.py
- client-agent/cfwg_latency_watchdog.py only where it accepts an already-rejected cf_mode forward
- LeikwanBridge.Desktop/src/LeikwanBridge.Core
- Linux and Windows client tests
- client runtime docs and acceptance scans

Out of scope:

- master/src provisioning and sidecar server behavior
- existing server-side cf_mode publication, migration, or legacy retirement
- Android client, unless a separate Android task is approved
- generic CFWG/self-leg transport used by a non-cf_mode supported contract

The accepted client contract is: backend_mode=cf_mode is unsupported, rejected,
and never reaches a data-plane builder.

## Task 1: Add Explicit Client Rejection Tests

**Files:**

- Create: client-agent/tests/test_cf_mode_client_removed.py
- Modify: LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsForwardPolicyTests.cs
- Modify: LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ConfigPollingServiceTests.cs
- Modify: LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs

- [x] Step 1: Write Python source-contract tests that require the client to
  recognize cf_mode as unsupported, call stop_forward for a previously applied
  cf_mode forward, and never render a runtime for it.

~~~python
def test_cf_mode_is_rejected_before_runtime_render():
    source = (ROOT / "client-agent" / "client_agent.py").read_text()
    runtime = (ROOT / "client-agent" / "install_forward_runtime.py").read_text()
    assert "unsupported_backend_mode" in source or "cf_mode_unsupported" in source
    assert "cf_mode" in source
    assert "cf_mode" in runtime
~~~

The test must also scan generated config/unit text and assert no CF sidecar
command is emitted for a rejected forward.

- [x] Step 2: Add C# tests asserting WindowsForwardPolicy.IsSupportedOnWindows
  returns false for BackendMode=cf_mode, and ConfigPollingService stops a
  previously applied cf_mode forward when it disappears from the supported list.

~~~csharp
var forward = CreateBondForward();
forward.BackendMode = "cf_mode";
Assert.False(WindowsForwardPolicy.IsSupportedOnWindows(forward));
~~~

- [x] Step 3: Run the focused tests and verify they fail because cf_mode is
  currently accepted or reaches legacy branches.

~~~powershell
python -m pytest client-agent/tests/test_cf_mode_client_removed.py -q
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~WindowsForwardPolicyTests|FullyQualifiedName~ConfigPollingServiceTests|FullyQualifiedName~ForwardRuntimeManagerTests" --no-restore
~~~

Expected: FAIL.

## Task 2: Reject cf_mode in the Linux Client

**Files:**

- Modify: client-agent/client_agent.py
- Modify: client-agent/install_forward_runtime.py
- Modify: client-agent/tests/test_cf_mode_client_removed.py
- Modify: client-agent/tests/test_client_agent_bond_endpoint_health.py
- Modify: client-agent/tests/test_self_leg_best_ip_override.py
- Modify: client-agent/tests/test_self_leg_cf_wg_runtime.py
- Modify: client-agent/tests/test_highloss_self_leg_runtime_regression.py

- [x] Step 1: Add one centralized predicate:

~~~python
def unsupported_client_backend_mode(forward: dict) -> str:
    backend = str(forward.get("backend_mode", "") or "").strip().lower()
    return "cf_mode" if backend == "cf_mode" else ""
~~~

Use it before candidate selection, runtime rendering, health checks, and
diagnostics. The predicate may retain the string only to produce the bounded
error code; it must not select a CF runtime.

- [x] Step 2: In sync_once or its forward filtering path, mark cf_mode invalid,
  stop any applied forward with delete_files=True, remove its nft rules and
  runtime files, and exclude it from desired_ids. A newly received cf_mode
  forward must never be started.

- [x] Step 3: In render_forward_runtime, reject cf_mode with the exact bounded
  RuntimeError code cf_mode_unsupported before reading endpoints, sidecar
  ports, CF paths, or creating a start script.

- [x] Step 4: Remove the cf_mode-specific fallback at the current
  bond_remote_target branch. Do not replace it with another CF or slave target.
  A rejected cf_mode forward must stop before this code executes.

- [x] Step 5: Remove the cf_mode-only selection behavior in
  select_bond_runtime_endpoints: delete is_cf_bond and the healthy[:3] branch.
  Supported latency-first bond modes use the normal healthy endpoint ordering.

- [x] Step 6: Delete reconcile_cf_bond_runtime_health and
  cf_bond_runtime_health_entry if their only caller is the bond selector. If
  runtime health is still needed for supported modes, keep one neutral
  reconcile_bond_runtime_health function that does not inspect backend_mode.

- [x] Step 7: Rename generic plain-tcp probe helpers from cf_bond_* to
  bond_* where they are used by supported modes. Replace error codes such as
  cf_bond_leg_probe_failed with bond_leg_probe_failed. Keep CFWG/self-leg
  helpers only when a supported non-cf_mode contract explicitly selects them.

- [x] Step 8: Update fixtures that used cf_mode only to reach generic bond or
  self-leg code. Use leigod_mode or current_mode when the behavior under test
  is still supported. Add a dedicated test that cf_mode is rejected rather than
  preserving a CF expectation.

- [x] Step 9: Run the Python suites:

~~~powershell
python -m pytest client-agent/tests/test_cf_mode_client_removed.py client-agent/tests/test_client_agent_bond_endpoint_health.py client-agent/tests/test_self_leg_best_ip_override.py client-agent/tests/test_self_leg_cf_wg_runtime.py client-agent/tests/test_highloss_self_leg_runtime_regression.py -q
~~~

Expected: PASS. No test may assert that a cf_mode forward starts a runtime.

- [x] Step 10: Commit Linux removal:

~~~powershell
git add client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/cfwg_latency_watchdog.py client-agent/tests/test_cf_mode_client_removed.py client-agent/tests/test_client_agent_bond_endpoint_health.py client-agent/tests/test_self_leg_best_ip_override.py client-agent/tests/test_self_leg_cf_wg_runtime.py client-agent/tests/test_highloss_self_leg_runtime_regression.py
git commit -m "remove: reject cf_mode in Linux client"
~~~

## Task 3: Reject cf_mode in the Windows Client

**Files:**

- Modify: LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/WindowsForwardPolicy.cs
- Modify: LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs
- Modify: LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs
- Modify: LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsForwardPolicyTests.cs
- Modify: LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondEndpointHealthServiceTests.cs
- Modify: LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs
- Modify: LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs

- [x] Step 1: In WindowsForwardPolicy.IsSupportedOnWindows, reject
  BackendMode=cf_mode before canonical-contract and sidecar-target checks:

~~~csharp
if (string.Equals(forward.BackendMode, "cf_mode", StringComparison.OrdinalIgnoreCase))
    return false;
~~~

- [x] Step 2: Ensure ConfigPollingService stops any previously applied
  cf_mode forward because filtering removes it from activeIds. The stop path
  must use the same cleanup used for an unassigned forward.

- [x] Step 3: Remove the cf_mode special healthy endpoint cap in
  BondEndpointHealthService. Normal supported bond modes use all healthy
  endpoints in their existing priority order.

- [x] Step 4: Remove IsCfPlainTcpBond and the CF-only HealthCheckService branch.
  If plain_tcp_mux health is still required for supported modes, rename the
  predicate and probe method to generic PlainTcpMuxBond and keep the same
  readiness semantics without checking cf_mode.

- [x] Step 5: Convert tests that were using cf_mode merely as a fixture to a
  supported backend mode. Replace tests that assert CF-specific selection or
  error codes with a rejection test in WindowsForwardPolicyTests.

- [x] Step 6: Run:

~~~powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~WindowsForwardPolicyTests|FullyQualifiedName~BondEndpointHealthServiceTests|FullyQualifiedName~HealthCheckServiceTests|FullyQualifiedName~ForwardRuntimeManagerTests|FullyQualifiedName~ConfigPollingServiceTests" --no-restore
~~~

Expected: PASS. No test may start a Windows cf_mode runtime.

- [x] Step 7: Commit Windows removal:

~~~powershell
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/WindowsForwardPolicy.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsForwardPolicyTests.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondEndpointHealthServiceTests.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ConfigPollingServiceTests.cs
git commit -m "remove: reject cf_mode in Windows client"
~~~

## Task 4: Prevent Reintroduction and Verify Existing Modes

**Files:**

- Modify: client-agent/tests/test_cf_mode_client_removed.py
- Modify: LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsForwardPolicyTests.cs
- Modify: docs/superpowers/specs/2026-08-25-ultra-panel-policy-ephemeral-line-lease-design.md
- Modify: docs/superpowers/plans/2026-08-25-ultra-panel-policy-ephemeral-line-lease.md
- Modify: docs/superpowers/plans/2026-08-25-ultra-linux-ephemeral-runtime.md
- Modify: docs/superpowers/plans/2026-08-25-ultra-windows-runtime.md
- Modify: docs/superpowers/plans/2026-08-25-ultra-accounting-stop-release.md

- [x] Step 1: Add source-contract scans that permit cf_mode only in the explicit
  rejection predicate, rejection error code, and rejection tests. Fail if a
  client runtime builder, endpoint selector, health branch, command generator,
  status serializer, or installer path contains a cf_mode execution branch.

~~~powershell
rg -n "cf_mode" client-agent LeikwanBridge.Desktop/src/LeikwanBridge.Core
~~~

Expected matches are limited to rejection logic and tests; server-side master
matches are intentionally outside this client-only gate.

- [x] Step 2: Run existing supported-mode tests for single, race, bond, mptcp,
  leigod_mode, Ultra, and generic self-leg transport. Verify no supported mode
  depends on the removed cf_mode branch.

- [x] Step 3: Add the design invariant: Linux and Windows reject cf_mode and
  stop old cf_mode processes; Panel/server-side cf_mode compatibility remains
  outside this task.

- [x] Step 4: Run the combined client gate:

~~~powershell
python -m pytest client-agent/tests -q
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --no-restore
~~~

Expected: PASS, with any unrelated pre-existing failures recorded separately.

- [x] Step 5: Commit the prevention and documentation update:

~~~powershell
git add client-agent/tests/test_cf_mode_client_removed.py LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsForwardPolicyTests.cs docs/superpowers/specs/2026-08-25-ultra-panel-policy-ephemeral-line-lease-design.md docs/superpowers/plans/2026-08-25-ultra-panel-policy-ephemeral-line-lease.md docs/superpowers/plans/2026-08-25-ultra-linux-ephemeral-runtime.md docs/superpowers/plans/2026-08-25-ultra-windows-runtime.md docs/superpowers/plans/2026-08-25-ultra-accounting-stop-release.md
git commit -m "docs: lock client cf_mode removal boundary"
~~~

## Acceptance

- A Panel cf_mode forward is rejected by Linux and Windows clients.
- A previously applied cf_mode forward is stopped and cleaned on the next sync.
- No cf_mode data-plane process, CF sidecar command, endpoint selector branch,
  or CF-only health branch runs on either client.
- Supported single, race, bond, mptcp, leigod_mode, Ultra, and non-cf self-leg
  tests still pass.
- Panel/server-side cf_mode compatibility is unchanged.
- No slave, sidecar, or new data Gateway is added by this client-only removal.
