# Ultra Windows Runtime Parity Implementation Plan

> **Status:** Implemented on `codex/ultra-policy-v2`; Core/App tests and Windows
> helper cross-build completed.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (- [ ]) syntax.

**Goal:** Add Windows Ultra support using the same policy, one-time lease, direct provider, quota, expiry, and cleanup contract as Linux.

**Architecture:** The existing WPF/.NET client remains the control/lifecycle owner. It polls Panel for policy, requests a one-time lease, starts the shared Go Ultra helper, and sends the lease through a protected Windows named pipe. The helper owns direct provider TCP/UDP sessions and byte enforcement; the .NET layer never serializes or logs provider material.

**Prerequisite:** Complete
`2026-08-25-ultra-client-remove-cf-mode.md`. Windows must reject and clean
`cf_mode` before Ultra dispatch is added; Ultra must never fall through to a CF,
bond, or legacy runtime.

**Tech Stack:** .NET/WPF, C#, System.Text.Json, Windows Job Objects, named-pipe ACLs, Go cross-compilation, PowerShell/Inno Setup, xUnit tests.

---

## File Map

Create:

- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UltraPolicy.cs
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UltraLease.cs
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/UltraLeaseClient.cs
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsJobGroup.cs
- LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/UltraLeaseClientTests.cs
- LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsJobGroupTests.cs

Modify:

- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ForwardConfig.cs
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentConfig.cs
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MasterApiClient.cs
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ProcessSupervisor.cs
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HeartbeatService.cs
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/HeartbeatPayload.cs
- scripts/build-windows-client-setup.ps1
- scripts/windows-client-install-helper.ps1
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsAppUpdateService.cs
- the matching Core/App runtime tests

## In-Memory Model

Add these models. UltraLease must never be serialized by StateManager, logged by
Serilog, placed in ProcessStartInfo.Arguments, or copied into AppliedForwardState.

~~~csharp
public sealed class UltraPolicy
{
    [JsonPropertyName("protocol")] public string Protocol { get; init; } = "";
    [JsonPropertyName("forward_id")] public int ForwardId { get; init; }
    [JsonPropertyName("policy_generation")] public string PolicyGeneration { get; init; } = "";
    [JsonPropertyName("session_ticket")] public string SessionTicket { get; init; } = "";
    [JsonPropertyName("expires_at")] public DateTimeOffset ExpiresAt { get; init; }
    [JsonPropertyName("renew_after")] public DateTimeOffset RenewAfter { get; init; }
    [JsonPropertyName("transport")] public string Transport { get; init; } = "tcp+udp";
    [JsonPropertyName("rate_limit_mbps")] public long RateLimitMbps { get; init; }
    [JsonPropertyName("quota_remaining_bytes")] public long QuotaRemainingBytes { get; init; }
    [JsonPropertyName("target_digest")] public string TargetDigest { get; init; } = "";
}

public sealed class UltraLease
{
    [JsonIgnore] public string LeaseId { get; init; } = "";
    [JsonIgnore] public string PolicyGeneration { get; init; } = "";
    [JsonIgnore] public DateTimeOffset ExpiresAt { get; init; }
    [JsonIgnore] public string ProviderIp { get; init; } = "";
    [JsonIgnore] public int ProviderPort { get; init; }
    [JsonIgnore] public byte[] User { get; init; } = [];
    [JsonIgnore] public byte[] Password { get; init; } = [];
}
~~~

## Task 1: Add Models and API Lease Request

**Files:** the two new model files, ForwardConfig.cs, MasterApiClient.cs,
and UltraLeaseClientTests.cs.

- [ ] Step 1: Write a failing test that deserializes policy v2 and asserts that
  JSON contains no credential or line list.

~~~csharp
Assert.Equal("leikwan-ultra-policy-v2", policy.Protocol);
Assert.DoesNotContain("credential", JsonSerializer.Serialize(policy));
Assert.DoesNotContain("line_ips", JsonSerializer.Serialize(policy));
~~~

- [ ] Step 2: Run the focused test.

~~~powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter FullyQualifiedName~UltraLeaseClientTests --no-restore
~~~

Expected: FAIL because the models and API method do not exist.

- [ ] Step 3: Map JSON ultra_access to UltraPolicy and add a non-serialized
  RuntimeLease property to ForwardConfig.
- [ ] Step 4: Add MasterApiClient.ResolveUltraLeaseAsync. POST to
  /api/client/ultra/lease with forward ID, policy generation, one-time nonce,
  target digest, and transport. Require HTTP 200 and one lease whose forward,
  generation, and expiry match the policy. Reject all extra candidates.
- [ ] Step 5: Zero serialized request/response buffers in finally and never put
  lease values in exception messages.
- [ ] Step 6: Re-run the focused test; expected PASS.
- [ ] Step 7: Commit:

~~~powershell
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UltraPolicy.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UltraLease.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ForwardConfig.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MasterApiClient.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/UltraLeaseClientTests.cs
git commit -m "feat: add Windows Ultra policy and lease models"
~~~

## Task 2: Add Protected Named-Pipe Delivery

**Files:** UltraLeaseClient.cs, tools/ultra-client/control.go,
tools/ultra-client/control_windows.go, and their tests.

- [ ] Step 1: Write failing tests that start a helper control endpoint, send one
  lease, reject a second nonce use, and scan arguments/state files for the
  provider endpoint and password.
- [ ] Step 2: Run:

~~~powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter FullyQualifiedName~UltraLeaseClientTests --no-restore
(cd tools/ultra-client && go test . -run TestControl -count=1)
~~~

Expected: FAIL because Windows named-pipe transport is absent.
- [ ] Step 3: Implement a per-forward pipe named
  \\.\pipe\leikwan-ultra-forward-{id}-{noncePrefix}, with a DACL allowing only
  the current client/service identity. Accept one frame and close it. Use the
  same four-byte big-endian length plus JSON frame as Linux; reject frames over
  64 KiB. If an ACL cannot be installed, fail closed rather than falling back to
  open loopback TCP.
- [ ] Step 4: Implement UltraLeaseClient.SendAsync with a two-second timeout,
  one frame, an acknowledgement containing only accepted, forward_id, and
  policy_generation, and byte-buffer cleanup.
- [ ] Step 5: Make the Go helper report readiness only after the pipe exists, and
  allow the WPF manager to report running only after lease acknowledgement.
- [ ] Step 6: Run the two focused tests; expected PASS.
- [ ] Step 7: Commit:

~~~powershell
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/UltraLeaseClient.cs tools/ultra-client/control.go tools/ultra-client/control_windows.go LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/UltraLeaseClientTests.cs tools/ultra-client/control_test.go
git commit -m "feat: deliver Windows Ultra leases over protected pipe"
~~~

## Task 3: Dispatch Ultra Through the Existing Runtime Manager

**Files:** ForwardRuntimeManager.cs, ConfigPollingService.cs, AgentConfig.cs,
ForwardRuntimeManagerTests.cs, and ConfigPollingServiceTests.cs.

- [ ] Step 1: Write failing dispatch tests. For BackendMode ultra, assert exactly
  one ultra-runtime helper starts, no bond/ingress/wstunnel/WireGuard/MPTCP/SOCKS
  helper starts, and command arguments contain neither provider IP nor password.

~~~csharp
manager.ApplyUltraForward(forward, lease);
Assert.Equal(new[] { "ultra-runtime" }, supervisor.StartedRoles);
Assert.DoesNotContain(lease.ProviderIp, supervisor.StartedCommands.Single().arguments);
Assert.DoesNotContain(Convert.ToBase64String(lease.Password), supervisor.StartedCommands.Single().arguments);
~~~

- [ ] Step 2: Run the focused Core tests; expected FAIL because the manager
  ignores Ultra backend mode.
- [ ] Step 3: Add UltraBin to AgentConfig, defaulting to bin/ultra-client.exe,
  and include only its hash in the contract signature.
- [ ] Step 4: Add ApplyUltraForward. Validate service status, policy generation,
  target digest, transport, and lease expiry before replacing a runtime. Write
  static config, start helper, wait for pipe/readiness, deliver lease, and
  persist only role/PID/port/generation/timestamps.
- [ ] Step 5: Dispatch BackendMode ultra before the existing mode switch. A
  missing/invalid lease preserves the current runtime until expiry, then stops;
  it never falls back to bond or single.
- [ ] Step 6: In ConfigPollingService.SyncOnceAsync, request or renew a lease
  immediately before apply or renewal deadline. Apply policy changes before
  health checks. Stop on inactive service, expired policy, generation mismatch,
  quota zero, or device revocation.
- [ ] Step 7: Run:

~~~powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~ForwardRuntimeManagerTests|FullyQualifiedName~ConfigPollingServiceTests" --no-restore
~~~

Expected: PASS, including no command-line credentials and no legacy fallback.
- [ ] Step 8: Commit:

~~~powershell
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentConfig.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ConfigPollingServiceTests.cs
git commit -m "feat: dispatch Windows Ultra through the shared runtime"
~~~

## Task 4: Add Job Object Stop Semantics and Usage Counters

**Files:** WindowsJobGroup.cs, ProcessSupervisor.cs,
ForwardRuntimeManager.cs, HeartbeatService.cs, HeartbeatPayload.cs, and tests.

- [ ] Step 1: Write failing lifecycle tests. Start a helper that spawns a child,
  call StopForward, and assert child exit, local port closure, named-pipe
  disappearance, and IsForwardRunning false. Add a heartbeat test for monotonic
  Ultra byte deltas and policy generation only.
- [ ] Step 2: Run the focused tests; expected FAIL because no Job Object or Ultra
  heartbeat fields exist.
- [ ] Step 3: P/Invoke CreateJobObjectW, AssignProcessToJobObject,
  TerminateJobObject, and CloseHandle. Create one Job Object per forward, assign
  every helper before readiness, and terminate the entire group on stop/revoke.
- [ ] Step 4: Integrate jobs into ProcessSupervisor. Terminate the Job Object
  before disposing processes, wait five seconds, and report a bounded cleanup
  error if any child remains.
- [ ] Step 5: Define a credential-free UltraUsageSnapshot containing ForwardId,
  BytesIn, BytesOut, Sequence, and PolicyGeneration. Aggregate monotonic
  counters in StateManager only.
- [ ] Step 6: Extend heartbeat with bounded ultra_usage and generation fields.
  Successful delivery advances last-reported counters; failed delivery retains
  pending deltas. No provider fields are serialized.
- [ ] Step 7: Run:

~~~powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "FullyQualifiedName~WindowsJobGroupTests|FullyQualifiedName~HeartbeatServiceTests" --no-restore
~~~

Expected: PASS.
- [ ] Step 8: Commit:

~~~powershell
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsJobGroup.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ProcessSupervisor.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HeartbeatService.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/HeartbeatPayload.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsJobGroupTests.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HeartbeatServiceTests.cs
git commit -m "fix: make Windows Ultra stop and usage authoritative"
~~~

## Task 5: Package and Sign the Windows Helper

**Files:** scripts/build-windows-client-setup.ps1,
scripts/windows-client-install-helper.ps1, WindowsAppUpdateService.cs, and
their existing tests.

- [ ] Step 1: Write failing package tests asserting the helper is staged, PDBs
  are absent, SHA-256 is recorded, and unsigned helper/installer is rejected.
- [ ] Step 2: Run the Windows update tests; expected FAIL because the package
  script has no Ultra helper or signature gate.
- [ ] Step 3: Add deterministic GOOS=windows GOARCH=amd64 CGO_ENABLED=0 helper
  build, source commit/toolchain/hash metadata, and no source/PDB packaging.
- [ ] Step 4: Require Authenticode signing for helper, App/Core binaries, and
  installer; verify Valid signature and trusted timestamp before publishing.
- [ ] Step 5: Make WindowsAppUpdateService verify hash and Authenticode before
  atomic replacement, retaining the previous helper until readiness passes.
- [ ] Step 6: Run:

~~~powershell
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter FullyQualifiedName~WindowsAppUpdateServiceTests --no-restore
powershell -ExecutionPolicy Bypass -File scripts/build-windows-client-setup.ps1 -WhatIf
~~~

Expected: PASS; dry run lists helper, no PDB, hashes, and signature checks.
- [ ] Step 7: Commit:

~~~powershell
git add scripts/build-windows-client-setup.ps1 scripts/windows-client-install-helper.ps1 LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsAppUpdateService.cs LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsAppUpdateServiceTests.cs LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/Services/InstalledRuntimeCleanupServiceTests.cs
git commit -m "build: package and sign Windows Ultra runtime"
~~~

## Windows Self-Review

Run:

~~~powershell
rg -n "ProviderIp|ProviderPort|Password|Credential|sealed_material|line_ips|relay_ip" LeikwanBridge.Desktop/src scripts
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --no-restore
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj --no-restore
~~~

Credential property names may exist only in the transient lease model and
tests. They must not appear in command construction, JSON persistence, logging,
status, heartbeat, or installer metadata. Confirm the Windows Ultra path starts
no slave, sidecar, wstunnel, bond, MPTCP, or SOCKS process.

Confirm `WindowsForwardPolicy` rejects `cf_mode` and that no Windows runtime,
selection, health, or command branch still executes it.
