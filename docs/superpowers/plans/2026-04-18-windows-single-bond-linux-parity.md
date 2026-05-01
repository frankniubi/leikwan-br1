# Windows Single/Bond Linux Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `LeikwanBridge.Desktop` match the current Linux backend behavior for `single` and `bond`, including `plain_tcp_mux` ingress helpers, readiness-gated bond startup, and whole-forward automatic recovery.

**Architecture:** Extend the Windows config/runtime model so the desktop backend can consume Linux-era bond metadata directly from panel JSON, produce a structured runtime plan, and orchestrate `single` and `bond` with the same effective shape Linux uses. Recovery will be forward-centric: any critical child exit or missing required listener rebuilds the entire forward rather than patching one process in place.

**Tech Stack:** .NET 8, WPF desktop backend, `System.Text.Json`, process supervision via `System.Diagnostics.Process`, xUnit test project for Core logic

---

### Task 1: Add Core Test Project And Lock Current Runtime Expectations

**Files:**
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj`
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondRuntimeBuilderTests.cs`
- Modify: `LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondRuntimeBuilderTests.cs`

- [ ] **Step 1: Write the failing test project and first builder tests**

```xml
<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <TargetFramework>net8.0</TargetFramework>
    <ImplicitUsings>enable</ImplicitUsings>
    <Nullable>enable</Nullable>
    <IsPackable>false</IsPackable>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="Microsoft.NET.Test.Sdk" Version="17.11.1" />
    <PackageReference Include="xunit" Version="2.9.2" />
    <PackageReference Include="xunit.runner.visualstudio" Version="2.8.2">
      <IncludeAssets>runtime; build; native; contentfiles; analyzers; buildtransitive</IncludeAssets>
      <PrivateAssets>all</PrivateAssets>
    </PackageReference>
    <PackageReference Include="coverlet.collector" Version="6.0.2">
      <IncludeAssets>runtime; build; native; contentfiles; analyzers; buildtransitive</IncludeAssets>
      <PrivateAssets>all</PrivateAssets>
    </PackageReference>
  </ItemGroup>
  <ItemGroup>
    <ProjectReference Include="..\\..\\src\\LeikwanBridge.Core\\LeikwanBridge.Core.csproj" />
  </ItemGroup>
</Project>
```

```csharp
[Fact]
public void BuildSingle_UsesDirectWstunnelRuntime()
{
    var builder = CreateBuilder();
    var spec = builder.BuildSingle(CreateSingleForward());

    spec.Mode.Should().Be("single");
    spec.Processes.Should().ContainSingle();
    spec.Processes[0].Role.Should().Be("single-runtime");
    spec.Processes[0].Arguments.Should().Contain("wss://edge-a.example.com:443");
}

[Fact]
public void BuildBond_PlainTcpMux_UsesIngressHelpersAndSkipsUdp()
{
    var builder = CreateBuilder();
    var spec = builder.Build(CreatePlainTcpMuxBondForward());

    spec.Mode.Should().Be("bond");
    spec.IngressMode.Should().Be("plain_tcp_mux");
    spec.LegPorts.Should().HaveCount(2);
    spec.Processes.Should().Contain(p => p.Role == "bond-ingress-leg-0");
    spec.Processes.Should().Contain(p => p.Role == "bond-ingress-leg-1");
    spec.Processes.Should().ContainSingle(p => p.Role == "bond-runtime");
    spec.Processes.Should().NotContain(p => p.Role == "bond-udp-runtime");
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj"`
Expected: FAIL because the test project is not yet in the solution and `BondRuntimeBuilder` does not expose the structured runtime plan asserted by the tests.

- [ ] **Step 3: Add the test project to the solution**

```bash
dotnet sln "LeikwanBridge.Desktop\\LeikwanBridge.Desktop.sln" add "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj"
```

- [ ] **Step 4: Run tests again to verify the failures are now about missing production behavior**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj"`
Expected: FAIL on missing `Mode`, `IngressMode`, `Role`, and `LegPorts` members in the current runtime builder output.

- [ ] **Step 5: Commit**

```bash
git add "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj" "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\Runtime\\BondRuntimeBuilderTests.cs" "LeikwanBridge.Desktop\\LeikwanBridge.Desktop.sln"
git commit -m "test: add desktop runtime builder coverage"
```

### Task 2: Extend Config Models And Build Linux-Parity Runtime Plans

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/BondingConfig.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentConfig.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondRuntimeBuilderTests.cs`

- [ ] **Step 1: Write failing tests for ingress metadata, warmup fields, and compatibility branching**

```csharp
[Fact]
public void BuildBond_PlainTcpMux_PassesWarmupAndCooldownFlagsToBondRuntime()
{
    var builder = CreateBuilder();
    var spec = builder.Build(CreatePlainTcpMuxBondForward());
    var runtime = spec.Processes.Single(p => p.Role == "bond-runtime");

    runtime.Arguments.Should().Contain("--warmup-trigger-bytes 65536");
    runtime.Arguments.Should().Contain("--warmup-slice-bytes 65536");
    runtime.Arguments.Should().Contain("--warmup-leg-delay-ms 80");
    runtime.Arguments.Should().Contain("--bulk-promote-bytes 262144");
    runtime.Arguments.Should().Contain("--leg-cooldown-ms 3000");
}

[Fact]
public void BuildBond_LegacyIngressMode_FallsBackToPerLegWstunnel()
{
    var builder = CreateBuilder();
    var spec = builder.Build(CreateLegacyBondForward());

    spec.Processes.Should().Contain(p => p.Role == "bond-wss-leg-0");
    spec.Processes.Should().ContainSingle(p => p.Role == "bond-runtime");
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj" --filter BondRuntimeBuilderTests`
Expected: FAIL because the Windows model and builder still only know the old WSS-leg bond shape.

- [ ] **Step 3: Implement the minimal model and builder changes**

```csharp
public sealed class EndpointConfig
{
    [JsonPropertyName("route_key")]
    public string RouteKey { get; set; } = "";

    [JsonPropertyName("ingress_auth_token")]
    public string IngressAuthToken { get; set; } = "";

    [JsonPropertyName("ingress_protocol")]
    public string IngressProtocol { get; set; } = "";

    [JsonPropertyName("backend_protocol")]
    public string BackendProtocol { get; set; } = "";

    [JsonPropertyName("dynamic_priority")]
    public int DynamicPriority { get; set; }
}
```

```csharp
public record RuntimeProcess(string Role, string Label, string ExePath, string Arguments, bool RequiredForReadiness, bool CriticalForForwardHealth);
public record RuntimeSpec(string Mode, string IngressMode, int ListenPort, List<int> LegPorts, List<RuntimeProcess> Processes);
```

```csharp
processes.Add(new RuntimeProcess(
    $"bond-ingress-leg-{i}",
    $"bond-ingress-leg-{i}",
    ingressBin,
    $"client --listen 127.0.0.1:{tcpBack} --remote {ep.Host}:{ep.Port} --route-key {routeKey} --auth-token {authToken}",
    requiredForReadiness: true,
    criticalForForwardHealth: true));
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj" --filter BondRuntimeBuilderTests`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add "LeikwanBridge.Desktop\\src\\LeikwanBridge.Core\\Models\\EndpointConfig.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.Core\\Models\\BondingConfig.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.Core\\Models\\AgentConfig.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.Core\\Services\\BondRuntimeBuilder.cs" "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\Runtime\\BondRuntimeBuilderTests.cs"
git commit -m "feat: align desktop runtime builder with linux single and bond"
```

### Task 3: Enforce Staged Startup And Persist Runtime Topology

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentState.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ProcessSupervisor.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/NetworkProbe.cs`
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs`

- [ ] **Step 1: Write failing orchestration tests**

```csharp
[Fact]
public void ApplyForward_BondPlainTcpMux_StartsIngressBeforeBondRuntime()
{
    var supervisor = new FakeProcessSupervisor();
    var manager = CreateManager(supervisor);

    manager.ApplyForward(CreatePlainTcpMuxBondForward());

    supervisor.StartedLabels.Should().StartWith(new[]
    {
        "bond-ingress-leg-0",
        "bond-ingress-leg-1"
    });
    supervisor.StartedLabels.Last().Should().Be("bond-runtime");
}

[Fact]
public void ApplyForward_BondPlainTcpMux_PersistsLegTopology()
{
    var stateManager = CreateStateManager();
    var manager = CreateManager(new FakeProcessSupervisor(), stateManager);

    manager.ApplyForward(CreatePlainTcpMuxBondForward());

    var applied = stateManager.LoadApplied()["26"];
    applied.IngressMode.Should().Be("plain_tcp_mux");
    applied.EndpointCount.Should().Be(2);
    applied.LegPorts.Should().NotBeEmpty();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj" --filter ForwardRuntimeManagerTests`
Expected: FAIL because startup ordering and persisted topology are not implemented.

- [ ] **Step 3: Implement minimal orchestration and state changes**

```csharp
foreach (var process in spec.Processes.Where(p => p.RequiredForReadiness))
{
    _supervisor.StartProcess(forwardId, process.Role, process.Label, process.ExePath, process.Arguments);
}

if (!WaitForRequiredPorts(spec.LegPorts, TimeSpan.FromSeconds(15)))
{
    _supervisor.StopAll(forwardId);
    throw new InvalidOperationException($"forward {forwardId} leg readiness failed");
}

foreach (var process in spec.Processes.Where(p => !p.RequiredForReadiness))
{
    _supervisor.StartProcess(forwardId, process.Role, process.Label, process.ExePath, process.Arguments);
}
```

```csharp
public sealed class AppliedForwardState
{
    [JsonPropertyName("ingress_mode")]
    public string IngressMode { get; set; } = "";

    [JsonPropertyName("endpoint_count")]
    public int EndpointCount { get; set; }

    [JsonPropertyName("leg_ports")]
    public List<int> LegPorts { get; set; } = [];

    [JsonPropertyName("process_roles")]
    public List<string> ProcessRoles { get; set; } = [];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj" --filter ForwardRuntimeManagerTests`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add "LeikwanBridge.Desktop\\src\\LeikwanBridge.Core\\Models\\AgentState.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.Core\\Services\\ForwardRuntimeManager.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.Core\\Services\\ProcessSupervisor.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.Core\\Helpers\\NetworkProbe.cs" "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\Runtime\\ForwardRuntimeManagerTests.cs"
git commit -m "feat: stage desktop bond startup and persist runtime topology"
```

### Task 4: Rebuild Whole Forwards On Critical Failure And Verify End-To-End

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs`

- [ ] **Step 1: Write failing recovery tests**

```csharp
[Fact]
public async Task CheckAllAsync_BondMissingLegPort_RestartsWholeForward()
{
    var runtimeManager = new FakeRuntimeManager();
    var supervisor = FakeProcessSupervisor.ForBondForward(processAlive: true);
    var service = new HealthCheckService(supervisor, runtimeManager);

    await service.RunCheckOnceAsync(new[] { CreatePlainTcpMuxBondForward() });

    runtimeManager.RestartedForwardIds.Should().Contain(26);
}

[Fact]
public void OnCriticalProcessExit_BondIngressExit_RebuildsForward()
{
    var runtimeManager = new FakeRuntimeManager();
    var supervisor = new ProcessSupervisor();

    // simulate ProcessExited for a critical leg role
    // assert whole-forward restart was requested once
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj" --filter HealthCheckServiceTests`
Expected: FAIL because health checks are still too coarse and process-exit recovery is not yet wired.

- [ ] **Step 3: Implement minimal forward-centric recovery**

```csharp
if (mode == "bond")
{
    if (!AllRequiredLegPortsListening(applied.LegPorts) || !AllCriticalRolesAlive(fid, applied.ProcessRoles))
    {
        RestartForward(forward);
        return;
    }
}
```

```csharp
_supervisor.ProcessExited += (forwardId, role) =>
{
    if (IsCriticalRole(forwardId, role))
    {
        RequestForwardRebuild(forwardId, $"critical_process_exit:{role}");
    }
};
```

- [ ] **Step 4: Run the full verification set**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj"`
Expected: PASS

Run: `dotnet build "LeikwanBridge.Desktop\\LeikwanBridge.Desktop.sln"`
Expected: PASS, with no new errors introduced by the desktop runtime refactor

- [ ] **Step 5: Commit**

```bash
git add "LeikwanBridge.Desktop\\src\\LeikwanBridge.Core\\Services\\HealthCheckService.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.Core\\Services\\ForwardRuntimeManager.cs" "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\Runtime\\HealthCheckServiceTests.cs"
git commit -m "feat: rebuild desktop forwards on critical runtime failure"
```
