# Windows CF Bond Linux Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Windows desktop client behave like Linux `client-agent 2026.04.27.2` for CF-mode bond health: detect toxic CF bond legs, quarantine them, and rebuild the forward using only healthy legs when possible.

**Architecture:** Add a small C# bond endpoint health service that mirrors Linux `bond-endpoint-health.json` semantics. Health checks will run a stronger one-leg CF bond connect probe for `backend_mode=cf_mode + bond + plain_tcp_mux`; failures force endpoint quarantine and refresh the forward runtime. Forward rendering will select healthy/probation endpoints from the health file before starting `leikwan-ingress.exe` and `leikwan-bond.exe`.

**Tech Stack:** .NET 8, WPF desktop backend, System.Text.Json state files, xUnit tests, existing `leikwan-bond.exe` and `leikwan-ingress.exe` runtimes.

---

### Task 1: Model CF backend mode and endpoint health state

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ForwardConfig.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/BondEndpointHealthState.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/StateManager.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondEndpointHealthServiceTests.cs`

- [ ] Write failing tests for Linux-style quarantine/probation/healthy selection.
- [ ] Verify tests fail because the service does not exist.
- [ ] Implement the minimal state model and endpoint selection logic.
- [ ] Verify tests pass.

### Task 2: Render Windows CF bond from health-filtered endpoints

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs`

- [ ] Write a failing test proving a quarantined CF bond endpoint is excluded from the rendered runtime.
- [ ] Verify the test fails with two ingress legs.
- [ ] Clone the forward with selected endpoints before calling `BondRuntimeBuilder.Build`.
- [ ] Verify the test passes.

### Task 3: Add Linux-style CF one-leg bond probe and quarantine refresh

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs`

- [ ] Write a failing health-check test where a CF one-leg probe failure quarantines the endpoint and restarts the forward.
- [ ] Verify it fails because no CF one-leg probe exists.
- [ ] Implement injected probe support for tests and a default `leikwan-bond.exe client` probe for production.
- [ ] Verify tests pass.

### Task 4: Rebuild and push Windows package to panel

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj`
- Modify: `artifacts/versions.json`
- Modify: `artifacts/windows/leikwan-bridge-setup.exe`

- [ ] Bump Windows app version.
- [ ] Run `dotnet test LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln`.
- [ ] Publish WPF app and rebuild setup.
- [ ] Verify local setup hashes and runtime versions.
- [ ] Push setup and metadata to panel through WSL SSH and verify panel hashes.
