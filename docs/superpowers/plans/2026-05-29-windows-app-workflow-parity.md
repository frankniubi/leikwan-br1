# Windows App Workflow Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Update `LeikwanBridge.Desktop` so the Windows client matches the current client -> slave -> sidecar workflow at the contract/orchestration layer: effective-config + signature-based apply/skip, preconnect relay health awareness, panel `health_status` gating, relay-first runtime rendering, and explicit fallback behavior.

**Architecture:** Keep Windows on the current compatibility path: the desktop app consumes panel desired state, builds an effective runtime config, computes a stable contract signature, and only reapplies when runtime intent changes or health forces rebuild. Linux-specific transport mechanics remain delegated to the slave and bundled runtime binaries; Windows acts as the desired-state consumer/orchestrator, not the owner of WireGuard or kernel MPTCP plumbing.

**Tech Stack:** .NET 8 / C#, WPF desktop app, xUnit tests, `System.Text.Json`, existing `LeikwanBridge.Core` services and runtime builders.

---

## Scope gate before implementation

Before touching code, implementation must capture a real Windows payload sample from `https://v3-mirror.leikwanhost.com/` for a client record with active forwards.

This is a required execution gate because the currently bound local sample (`/c/Users/frankniubi/AppData/Local/Leikwan/WindowsClientApp/client-binding.json`) points at `https://v3.leikwanhost.com` and returns `forwards: []`, which is not enough to prove the current mirror-side payload shape.

The live mirror sample must confirm whether Windows currently receives:

- `mptcp.slave_relay_host` / `mptcp.slave_relay_port`
- `mptcp.underlays[].legs[]` fields such as `slave_preconnect`, `preconnect_relay_port`, `health_status`, `outer_transport`, and any CF/slave dial metadata
- any payload that truly requires Windows direct CF-WG self-leg behavior

If Gate-0 shows the mirror payload shape differs from this plan, update the affected tasks before implementation continues.

## File structure / decomposition

### Core model files
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs`
  - Add panel-facing `HealthStatus`
  - Ensure `Clone()` copies all new properties
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UnderlayConfig.cs`
  - Extend `UnderlayLeg` with confirmed preconnect / health / CF metadata
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/MptcpConfig.cs`
  - Add any confirmed top-level relay/health metadata needed after Gate-0
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ForwardConfig.cs`
  - Add `RuntimePreference`, `CandidatePool`, or other confirmed mirror fields only if Gate-0 proves Windows receives and needs them
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentState.cs`
  - Add `ContractSignature` and optional apply-reason metadata to `AppliedForwardState`

### New helper files
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/EffectiveForwardConfigBuilder.cs`
  - Build effective runtime intent from raw desired state + panel health + local relay health selection
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/ForwardContractSignature.cs`
  - Canonicalize effective config, strip observation-only fields, compute stable signature

### Core service files
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`
  - Replace liveness-only apply gating with effective-signature apply/skip logic
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
  - Support no-op short-circuit, force-rebuild path, explicit fallback wiring, and persisted signature writes
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
  - Add preconnect `/health` relay probing and ensure health-triggered rebuild bypasses signature no-op
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs`
  - Apply panel `health_status == active` gating before local ranking
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MptcpRuntimeBuilder.cs`
  - Use relay-first rendering, consume new structured preconnect fields, keep loopback-hiding semantics aligned with workflow
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MptcpFallbackService.cs`
  - Keep implementation as-is, but wire it into runtime decision flow

### Test files
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ConfigPollingServiceTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondEndpointHealthServiceTests.cs`
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/EffectiveForwardConfigBuilderTests.cs`
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardContractSignatureTests.cs`

### Deferred / out of scope for this plan
- Do not add Rust relay `stats_path` production or usage telemetry in this plan
- Do not implement Windows direct CF-WG self-leg unless Gate-0 proves it is required
- Do not implement Windows-managed WireGuard or Linux kernel-MPTCP parity

---

### Task 1: Verify the mirror-side live payload contract

**Files:**
- Reference: `docs/superpowers/specs/2026-05-29-windows-app-workflow-parity-design.md`
- Reference: `/c/Users/frankniubi/AppData/Local/Leikwan/WindowsClientApp/client-binding.json`
- Reference: `/c/ProgramData/LeikwanClient/forward_14/forward_14.json`
- Create: `artifacts/windows-mirror-payload-sample.json` (sanitized sample for local implementation use)
- Create: `artifacts/windows-mirror-payload-notes.md`

- [ ] **Step 1: Capture a sanitized mirror payload sample for a Windows client with active forwards**

Use a one-off script/command that queries `https://v3-mirror.leikwanhost.com/api/client/configs/pull` with a real Windows client token, then redact secrets before saving the sample.

```bash
# Example shape only — substitute the real client token/client record during execution.
curl -sS \
  -H "Authorization: Bearer <WINDOWS_AGENT_TOKEN>" \
  https://v3-mirror.leikwanhost.com/api/client/configs/pull \
  > artifacts/windows-mirror-payload-raw.json
```

- [ ] **Step 2: Save a redacted implementation sample**

Create `artifacts/windows-mirror-payload-sample.json` containing the real field shape but with secrets/tokens redacted:

```json
{
  "ok": true,
  "client": {
    "id": 123,
    "name": "windows-desktop",
    "agent_config_json": {
      "master_url": "https://v3-mirror.leikwanhost.com"
    }
  },
  "forwards": [
    {
      "forward_id": 62,
      "mode": "mptcp",
      "mptcp": {
        "slave_relay_host": "redacted-host",
        "slave_relay_port": 38012,
        "underlays": [
          {
            "legs": [
              {
                "endpoint_id": 1001,
                "slave_preconnect": true,
                "preconnect_relay_port": 38012,
                "health_status": "active",
                "outer_transport": "wstunnel_udp_via_slave"
              }
            ]
          }
        ]
      },
      "endpoints": [
        {
          "endpoint_id": 1001,
          "health_status": "active"
        }
      ]
    }
  ]
}
```

- [ ] **Step 3: Write mirror payload notes that lock implementation scope**

Create `artifacts/windows-mirror-payload-notes.md` with bullets for:

```md
- Does Windows receive `mptcp.slave_relay_host` / `slave_relay_port`? yes/no
- Do `underlays[].legs[]` include `slave_preconnect`? yes/no
- Do `underlays[].legs[]` include `preconnect_relay_port`? yes/no
- Do endpoints include `health_status`? yes/no
- Are CF-prefixed combinations still relay-mediated for Windows? yes/no
- Does any payload require direct Windows CF-WG self-leg? yes/no
- Which fields must be modeled in C# immediately?
```

- [ ] **Step 4: Confirm the captured sample is usable before code changes**

Run:

```bash
python - <<'PY'
import json
from pathlib import Path
p = Path('artifacts/windows-mirror-payload-sample.json')
obj = json.loads(p.read_text(encoding='utf-8'))
assert obj['ok'] is True
assert 'forwards' in obj
print('OK: payload sample ready, forwards =', len(obj['forwards']))
PY
```

Expected: `OK: payload sample ready, forwards = <N>`

- [ ] **Step 5: Commit the sanitized sample and notes**

```bash
git add artifacts/windows-mirror-payload-sample.json artifacts/windows-mirror-payload-notes.md
git commit -m "docs: capture windows mirror payload sample"
```

---

### Task 2: Add the missing contract model fields

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UnderlayConfig.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/MptcpConfig.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ForwardConfig.cs` (only if Gate-0 confirms extra fields)
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentState.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardContractSignatureTests.cs`

- [ ] **Step 1: Write the failing model-state test for new fields and signature persistence**

Create `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardContractSignatureTests.cs` with an initial failing test like:

```csharp
using System.Text.Json;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class ForwardContractSignatureTests
{
    [Fact]
    public void AppliedForwardState_RoundTripsContractSignature_AndEndpointClonePreservesHealthStatus()
    {
        var endpoint = new EndpointConfig
        {
            EndpointId = 184,
            Host = "8.163.90.132",
            Port = 7001,
            HealthStatus = "active",
            HealthRole = "healthy",
            QualityScore = 0.9,
        };

        var clone = endpoint.Clone();
        Assert.Equal("active", clone.HealthStatus);

        var state = new AppliedForwardState
        {
            ForwardId = 26,
            Mode = "bond",
            ContractSignature = "abc123",
        };

        var json = JsonSerializer.Serialize(state);
        Assert.Contains("contract_signature", json);
    }
}
```

- [ ] **Step 2: Run the model test and verify it fails**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter AppliedForwardState_RoundTripsContractSignature_AndEndpointClonePreservesHealthStatus
```

Expected: FAIL because `HealthStatus` / `ContractSignature` do not exist yet.

- [ ] **Step 3: Add the minimal model fields**

Update `EndpointConfig.cs` to add and clone:

```csharp
[JsonPropertyName("health_status")]
public string HealthStatus { get; set; } = "active";
```

and in `Clone()`:

```csharp
HealthStatus = HealthStatus,
```

Update `AgentState.cs` to add:

```csharp
[JsonPropertyName("contract_signature")]
public string ContractSignature { get; set; } = "";

[JsonPropertyName("last_apply_reason")]
public string LastApplyReason { get; set; } = "";
```

Update `UnderlayLeg` in `UnderlayConfig.cs` with Gate-0-confirmed fields, starting from:

```csharp
[JsonPropertyName("slave_preconnect")]
public bool SlavePreconnect { get; set; }

[JsonPropertyName("preconnect_relay_port")]
public int PreconnectRelayPort { get; set; }

[JsonPropertyName("health_status")]
public string HealthStatus { get; set; } = "active";
```

Update `MptcpConfig.cs` only if Gate-0 proves top-level health-related fields are needed.

- [ ] **Step 4: Re-run the model test and verify it passes**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter AppliedForwardState_RoundTripsContractSignature_AndEndpointClonePreservesHealthStatus
```

Expected: PASS.

- [ ] **Step 5: Commit the model-field change**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/UnderlayConfig.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/MptcpConfig.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ForwardConfig.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentState.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardContractSignatureTests.cs
git commit -m "feat: add windows workflow parity contract fields"
```

---

### Task 3: Add effective-config builder and stable contract signature helper

**Files:**
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/EffectiveForwardConfigBuilder.cs`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/ForwardContractSignature.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/EffectiveForwardConfigBuilderTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardContractSignatureTests.cs`

- [ ] **Step 1: Write the failing signature-stability tests**

Create `EffectiveForwardConfigBuilderTests.cs` and extend `ForwardContractSignatureTests.cs` with:

```csharp
[Fact]
public void Build_MarksPanelInactiveEndpointAsUnavailableBeforeLocalRanking()
{
    var forward = new ForwardConfig
    {
        ForwardId = 40,
        Mode = "bond",
        BackendMode = "cf_mode",
        Endpoints =
        [
            new EndpointConfig { EndpointId = 254, Host = "a", Port = 1, HealthStatus = "active" },
            new EndpointConfig { EndpointId = 255, Host = "b", Port = 1, HealthStatus = "inactive" },
        ],
        Bonding = new BondingConfig { PolicyMode = "latency_first", WsPriority = 10 },
    };

    var effective = EffectiveForwardConfigBuilder.Build(forward);
    Assert.DoesNotContain(effective.Endpoints, endpoint => endpoint.EndpointId == 255);
}

[Fact]
public void Compute_IgnoresObservationOnlyFields_ButChangesWhenRelayPortChanges()
{
    var forward = new ForwardConfig
    {
        ForwardId = 24,
        Mode = "mptcp",
        ListenPort = 11023,
        Mptcp = new MptcpConfig { SlaveRelayHost = "relay.example.com", SlaveRelayPort = 38012 },
        Endpoints = [ new EndpointConfig { EndpointId = 184, Host = "x", Port = 7001, QualityScore = 0.2 } ],
    };

    var a = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
    forward.Endpoints[0].QualityScore = 0.95;
    var b = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));
    forward.Mptcp.SlaveRelayPort = 38013;
    var c = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward));

    Assert.Equal(a, b);
    Assert.NotEqual(b, c);
}
```

- [ ] **Step 2: Run the helper tests and verify they fail**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "Build_MarksPanelInactiveEndpointAsUnavailableBeforeLocalRanking|Compute_IgnoresObservationOnlyFields_ButChangesWhenRelayPortChanges"
```

Expected: FAIL because the helper files do not exist yet.

- [ ] **Step 3: Add the minimal effective-config builder**

Create `EffectiveForwardConfigBuilder.cs` with a focused API:

```csharp
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

public static class EffectiveForwardConfigBuilder
{
    public static ForwardConfig Build(ForwardConfig forward)
    {
        var clone = new ForwardConfig
        {
            ForwardId = forward.ForwardId,
            ServiceStatus = forward.ServiceStatus,
            Mode = forward.Mode,
            ListenPort = forward.ListenPort,
            Target = forward.Target,
            Sni = forward.Sni,
            PathPrefix = forward.PathPrefix,
            Transport = forward.Transport,
            BackendMode = forward.BackendMode,
            SpeedLimitMbps = forward.SpeedLimitMbps,
            Bonding = forward.Bonding,
            Mptcp = forward.Mptcp,
            Endpoints = forward.Endpoints
                .Where(endpoint => string.Equals(endpoint.HealthStatus, "active", StringComparison.OrdinalIgnoreCase))
                .Select(endpoint => endpoint.Clone())
                .ToList(),
        };

        return clone;
    }
}
```

- [ ] **Step 4: Add the minimal signature helper**

Create `ForwardContractSignature.cs` with canonical, observation-stripped JSON hashing:

```csharp
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

public static class ForwardContractSignature
{
    public static string Compute(ForwardConfig forward)
    {
        var payload = new
        {
            forward.ForwardId,
            forward.Mode,
            forward.ListenPort,
            forward.Target,
            forward.Transport,
            forward.BackendMode,
            Mptcp = forward.Mptcp is null ? null : new
            {
                forward.Mptcp.SlaveRelayHost,
                forward.Mptcp.SlaveRelayPort,
                forward.Mptcp.SidecarHost,
                forward.Mptcp.SidecarPort,
                forward.Mptcp.TcpSessionToken,
                forward.Mptcp.UdpSessionToken,
                forward.Mptcp.SessionToken,
                forward.Mptcp.AllowTcpFallback,
            },
            Endpoints = forward.Endpoints.Select(endpoint => new
            {
                endpoint.EndpointId,
                endpoint.Host,
                endpoint.Port,
                endpoint.HealthStatus,
                endpoint.RouteKey,
                endpoint.IngressProtocol,
                endpoint.IngressAuthToken,
            }).ToList(),
        };

        var json = JsonSerializer.Serialize(payload);
        var bytes = SHA256.HashData(Encoding.UTF8.GetBytes(json));
        return Convert.ToHexString(bytes).ToLowerInvariant();
    }
}
```

- [ ] **Step 5: Re-run the helper tests and verify they pass**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "Build_MarksPanelInactiveEndpointAsUnavailableBeforeLocalRanking|Compute_IgnoresObservationOnlyFields_ButChangesWhenRelayPortChanges"
```

Expected: PASS.

- [ ] **Step 6: Commit the helper layer**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/EffectiveForwardConfigBuilder.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Helpers/ForwardContractSignature.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/EffectiveForwardConfigBuilderTests.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardContractSignatureTests.cs
git commit -m "feat: add windows forward effective config and signature"
```

---

### Task 4: Make config polling use signature-based apply/skip

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ConfigPollingServiceTests.cs`

- [ ] **Step 1: Write the failing no-op and changed-payload tests**

Add to `ConfigPollingServiceTests.cs`:

```csharp
[Fact]
public async Task SyncOnceAsync_SkipsApply_WhenSignatureMatchesAndRuntimeIsRunning()
{
    using var tempDir = new TempStateDirectory();
    using var server = new PullApiServer(BuildPullResponseJson());
    var config = new AgentConfig { MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = tempDir.Path };
    var stateManager = new StateManager(tempDir.Path);
    var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager) { IsRunningOverride = true };
    using var api = new MasterApiClient(server.BaseUrl, "token");
    var service = new ConfigPollingService(api, runtimeManager, stateManager);

    var forward = RecordingForwardRuntimeManager.DeserializeSingleForward(BuildPullResponseJson());
    stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
    {
        ["26"] = new AppliedForwardState
        {
            ForwardId = 26,
            Mode = "bond",
            ContractSignature = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(forward)),
        }
    });

    await service.SyncOnceAsync();

    Assert.Empty(runtimeManager.AppliedForwardIds);
}

[Fact]
public async Task SyncOnceAsync_Reapplies_WhenSignatureChangesEvenIfRuntimeIsRunning()
{
    using var tempDir = new TempStateDirectory();
    using var server = new PullApiServer(BuildPullResponseJson().Replace("tcp-session", "rotated-session"));
    var config = new AgentConfig { MasterUrl = server.BaseUrl, AgentToken = "token", ClientId = 1, StateDir = tempDir.Path };
    var stateManager = new StateManager(tempDir.Path);
    var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager) { IsRunningOverride = true };
    using var api = new MasterApiClient(server.BaseUrl, "token");
    var service = new ConfigPollingService(api, runtimeManager, stateManager);

    var oldForward = RecordingForwardRuntimeManager.DeserializeSingleForward(BuildPullResponseJson());
    stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
    {
        ["26"] = new AppliedForwardState
        {
            ForwardId = 26,
            Mode = "bond",
            ContractSignature = ForwardContractSignature.Compute(EffectiveForwardConfigBuilder.Build(oldForward)),
        }
    });

    await service.SyncOnceAsync();

    Assert.Equal([26], runtimeManager.AppliedForwardIds);
}
```

- [ ] **Step 2: Run the polling tests and verify they fail**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "SyncOnceAsync_SkipsApply_WhenSignatureMatchesAndRuntimeIsRunning|SyncOnceAsync_Reapplies_WhenSignatureChangesEvenIfRuntimeIsRunning"
```

Expected: FAIL because polling logic still uses liveness-only gating.

- [ ] **Step 3: Add signature-aware polling logic**

Update `ConfigPollingService.SyncOnceAsync()` so the apply block becomes conceptually:

```csharp
var effectiveForward = EffectiveForwardConfigBuilder.Build(forward);
var newSignature = ForwardContractSignature.Compute(effectiveForward);
var needsApply = !applied.TryGetValue(key, out var appliedState)
    || string.IsNullOrWhiteSpace(appliedState.ContractSignature)
    || !string.Equals(appliedState.ContractSignature, newSignature, StringComparison.Ordinal)
    || !_runtimeManager.IsForwardRunning(forward.ForwardId);

if (needsApply)
{
    _runtimeManager.ApplyForward(effectiveForward, newSignature, "config_sync");
}
```

and update `ForwardRuntimeManager` to accept the new signature/apply-reason and persist them into `AppliedForwardState`.

- [ ] **Step 4: Re-run the polling tests and verify they pass**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "SyncOnceAsync_SkipsApply_WhenSignatureMatchesAndRuntimeIsRunning|SyncOnceAsync_Reapplies_WhenSignatureChangesEvenIfRuntimeIsRunning"
```

Expected: PASS.

- [ ] **Step 5: Commit the polling/signature integration**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ConfigPollingServiceTests.cs
git commit -m "feat: use contract signatures for windows forward apply"
```

---

### Task 5: Apply panel health-status gating before local ranking

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondEndpointHealthServiceTests.cs`

- [ ] **Step 1: Write the failing active-only selection test**

Add to `BondEndpointHealthServiceTests.cs`:

```csharp
[Fact]
public void SelectRuntimeEndpoints_IgnoresPanelInactiveEndpointEvenWhenLocalQualityIsBest()
{
    using var tempDir = new TempStateDirectory();
    var stateManager = new StateManager(tempDir.Path);
    var service = new BondEndpointHealthService(stateManager);

    stateManager.SaveBondEndpointHealth(new Dictionary<string, Dictionary<string, BondEndpointHealthEntry>>
    {
        ["40"] = new()
        {
            ["254"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 1.0 },
            ["256"] = new BondEndpointHealthEntry { Role = "healthy", ConsecutiveSuccesses = 4, QualityScore = 0.4 },
        }
    });

    var forward = CreateLatencyFirstNormalBondForward();
    forward.Endpoints[0].HealthStatus = "inactive";
    forward.Endpoints[1].HealthStatus = "active";

    var selected = service.SelectRuntimeEndpoints(forward, nowUnixSeconds: 100);

    Assert.Equal([256], selected.Select(endpoint => endpoint.EndpointId).ToArray());
}
```

- [ ] **Step 2: Run the bond health test and verify it fails**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter SelectRuntimeEndpoints_IgnoresPanelInactiveEndpointEvenWhenLocalQualityIsBest
```

Expected: FAIL because inactive endpoints are not filtered yet.

- [ ] **Step 3: Add panel-active filtering before ranking**

Update `SelectRuntimeEndpoints()` in `BondEndpointHealthService.cs` so it filters inactive endpoints before role/quality ranking:

```csharp
var endpoints = forward.Endpoints
    .Where(endpoint => string.Equals(endpoint.HealthStatus, "active", StringComparison.OrdinalIgnoreCase))
    .Select(endpoint => endpoint.Clone())
    .ToList();
```

If every endpoint is filtered out, keep a safe fallback behavior explicit in code rather than silently reusing inactive candidates.

- [ ] **Step 4: Re-run the bond health test and verify it passes**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter SelectRuntimeEndpoints_IgnoresPanelInactiveEndpointEvenWhenLocalQualityIsBest
```

Expected: PASS.

- [ ] **Step 5: Commit the active-only gating change**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/BondEndpointHealthServiceTests.cs
git commit -m "feat: gate windows bond selection on panel health status"
```

---

### Task 6: Add relay `/health` probing and force-rebuild semantics

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs`

- [ ] **Step 1: Write the failing relay-health and force-rebuild tests**

Add to `HealthCheckServiceTests.cs`:

```csharp
[Fact]
public async Task RunCheckOnceAsync_MptcpPreconnectHealthFailure_RequestsForceRestart()
{
    using var tempDir = new TempStateDirectory();
    var supervisor = new TestProcessSupervisor();
    var stateManager = new StateManager(tempDir.Path);
    var runtimeManager = new TestForwardRuntimeManager(stateManager, supervisor);
    stateManager.SaveApplied(new Dictionary<string, AppliedForwardState>
    {
        ["24"] = new AppliedForwardState { ForwardId = 24, Mode = "mptcp", ContractSignature = "same-signature" }
    });
    supervisor.RunningRoles.Add("mptcp-runtime");

    var service = new HealthCheckService(
        supervisor,
        runtimeManager,
        stateManager,
        portListeningProbe: port => port == 11023,
        config: new AgentConfig());

    var forward = new ForwardConfig
    {
        ForwardId = 24,
        Mode = "mptcp",
        ServiceStatus = "active",
        ListenPort = 11023,
        Mptcp = new MptcpConfig { SlaveRelayHost = "relay.example.com", SlaveRelayPort = 38012 }
    };

    await service.RunCheckOnceAsync([forward]);

    Assert.Contains((24, true), runtimeManager.RestartRequests);
}
```

Also extend `TestForwardRuntimeManager` so it records whether a restart was requested as forceful.

- [ ] **Step 2: Run the health test and verify it fails**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter RunCheckOnceAsync_MptcpPreconnectHealthFailure_RequestsForceRestart
```

Expected: FAIL because there is no relay health probe or force restart signal yet.

- [ ] **Step 3: Add relay-health probing and explicit force rebuild**

In `HealthCheckService.cs`, add a small helper with behavior like:

```csharp
private virtual async Task<bool> IsPreconnectRelayHealthyAsync(string host, int dataPort, int? legRelayPort, CancellationToken ct)
{
    var probePort = (legRelayPort.HasValue && legRelayPort.Value > 0 ? legRelayPort.Value : dataPort) + 1;
    using var http = new HttpClient { Timeout = TimeSpan.FromSeconds(2) };
    using var response = await http.GetAsync($"http://{host}:{probePort}/health", ct);
    return ((int)response.StatusCode) >= 200 && ((int)response.StatusCode) < 300;
}
```

When health-driven rebuild is needed, call a force path on `ForwardRuntimeManager`, for example:

```csharp
_runtimeManager.RestartForward(forward, force: true, reason: "preconnect_relay_unhealthy");
```

- [ ] **Step 4: Re-run the health test and verify it passes**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter RunCheckOnceAsync_MptcpPreconnectHealthFailure_RequestsForceRestart
```

Expected: PASS.

- [ ] **Step 5: Commit the relay-health / force-rebuild changes**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs
git commit -m "feat: add windows preconnect relay health checks"
```

---

### Task 7: Wire explicit MPTCP-to-bond fallback into runtime selection

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MptcpFallbackService.cs` (only if a tiny helper extraction is needed)
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs`

- [ ] **Step 1: Write the failing fallback test**

Add to `ForwardRuntimeManagerTests.cs`:

```csharp
[Fact]
public void ApplyForward_MptcpWithoutUsableRelay_FallsBackToBondRuntime()
{
    using var tempDir = new TempStateDirectory();
    var supervisor = new TestProcessSupervisor();
    var stateManager = new StateManager(tempDir.Path);
    var config = CreateConfig();
    config.StateDir = tempDir.Path;
    config.MptcpRelayBin = @"C:\leikwan\bin\leikwan-mptcp-relay.exe";
    var manager = new ForwardRuntimeManager(config, supervisor, stateManager, _ => true, _ => true);

    var forward = CreateMptcpForward();
    forward.Mptcp!.SlaveRelayHost = "";
    forward.Mptcp.SlaveRelayPort = 0;
    forward.Mptcp.SidecarHost = "";
    forward.Mptcp.SidecarPort = 0;
    forward.Mptcp.RelayHost = "";
    forward.Mptcp.RelayPort = 0;

    manager.ApplyForward(forward, contractSignature: "sig", applyReason: "config_sync");

    Assert.Contains("bond-runtime", supervisor.StartedRoles);
    Assert.DoesNotContain("mptcp-runtime", supervisor.StartedRoles);
}
```

- [ ] **Step 2: Run the fallback test and verify it fails**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter ApplyForward_MptcpWithoutUsableRelay_FallsBackToBondRuntime
```

Expected: FAIL because the current MPTCP path throws instead of falling back.

- [ ] **Step 3: Add the runtime fallback guard**

In `ForwardRuntimeManager.ApplyForwardCore`, before calling `_mptcpBuilder.Build(forward)`, add logic like:

```csharp
var selectedForward = forward;
if (mode == "mptcp" && !HasUsableMptcpRelay(forward))
{
    selectedForward = MptcpFallbackService.ConvertToBond(forward);
    mode = "bond";
}
```

and add a small helper:

```csharp
private static bool HasUsableMptcpRelay(ForwardConfig forward)
{
    var mptcp = forward.Mptcp;
    if (mptcp is null) return false;
    if (!string.IsNullOrWhiteSpace(mptcp.SlaveRelayHost) && mptcp.SlaveRelayPort > 0) return true;
    if (!string.IsNullOrWhiteSpace(mptcp.RelayHost) && mptcp.RelayPort > 0) return true;
    if (!string.IsNullOrWhiteSpace(mptcp.SidecarHost) && mptcp.SidecarPort > 0) return true;
    return false;
}
```

- [ ] **Step 4: Re-run the fallback test and verify it passes**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter ApplyForward_MptcpWithoutUsableRelay_FallsBackToBondRuntime
```

Expected: PASS.

- [ ] **Step 5: Commit the fallback wiring**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MptcpFallbackService.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs
git commit -m "feat: wire windows mptcp fallback to bond runtime"
```

---

### Task 8: Preserve relay-first rendering and loopback-hiding semantics in the MPTCP runtime builder

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MptcpRuntimeBuilder.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs`

- [ ] **Step 1: Write the failing relay-first rendering test with leg-level preconnect data**

Add to `ForwardRuntimeManagerTests.cs`:

```csharp
[Fact]
public void ApplyForward_MptcpPreconnectLeg_UsesSlaveRelayAndPreservesStructuredUnderlayFields()
{
    using var tempDir = new TempStateDirectory();
    var supervisor = new TestProcessSupervisor();
    var stateManager = new StateManager(tempDir.Path);
    var config = CreateConfig();
    config.StateDir = tempDir.Path;
    config.MptcpRelayBin = @"C:\leikwan\bin\leikwan-mptcp-relay.exe";
    var manager = new ForwardRuntimeManager(config, supervisor, stateManager, _ => true, _ => true);

    var forward = CreateMptcpForward();
    forward.Mptcp!.SlaveRelayHost = "relay.example.com";
    forward.Mptcp.SlaveRelayPort = 38012;
    forward.Mptcp.Underlays =
    [
        new UnderlayConfig
        {
            InterfaceName = "lw-mptcp-62-0",
            Legs =
            [
                new UnderlayLeg
                {
                    EndpointId = 184,
                    PeerAddress = "10.203.15.202",
                    SlavePreconnect = true,
                    PreconnectRelayPort = 38012,
                    HealthStatus = "active",
                    OuterTransport = "wstunnel_udp_via_slave"
                }
            ]
        }
    ];

    manager.ApplyForward(forward, contractSignature: "sig", applyReason: "config_sync");

    var runtimeConfigPath = Path.Combine(tempDir.Path, "runtime", "mptcp-forward-24.json");
    var payload = File.ReadAllText(runtimeConfigPath);
    Assert.Contains("\"remote_host\": \"relay.example.com\"", payload);
    Assert.Contains("\"slave_relay_port\": 38012", payload);
    Assert.Contains("\"outer_transport\": \"wstunnel_udp_via_slave\"", payload);
}
```

- [ ] **Step 2: Run the runtime-builder test and verify it fails**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter ApplyForward_MptcpPreconnectLeg_UsesSlaveRelayAndPreservesStructuredUnderlayFields
```

Expected: FAIL until the new fields are rendered end-to-end.

- [ ] **Step 3: Update the runtime payload rendering minimally**

In `MptcpRuntimeBuilder.BuildRuntimePayload`, preserve relay-first behavior and include structured underlay data already present on the model. Keep the core decision:

```csharp
if (!string.IsNullOrWhiteSpace(slaveRelayHost) && slaveRelayPort > 0)
{
    remoteHost = slaveRelayHost;
    remotePort = slaveRelayPort;
}
```

Ensure the serialized payload still includes:

```csharp
["underlays"] = mptcp.Underlays,
["slave_relay_host"] = FirstNonEmpty(mptcp.SlaveRelayHost),
["slave_relay_port"] = mptcp.SlaveRelayPort,
```

and do not regress into direct-sidecar-first behavior.

- [ ] **Step 4: Re-run the runtime-builder test and verify it passes**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter ApplyForward_MptcpPreconnectLeg_UsesSlaveRelayAndPreservesStructuredUnderlayFields
```

Expected: PASS.

- [ ] **Step 5: Commit the runtime-builder change**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MptcpRuntimeBuilder.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ForwardRuntimeManagerTests.cs
git commit -m "feat: keep windows mptcp runtime relay-first"
```

---

### Task 9: Run the focused and full Core test suites, then document deferred items

**Files:**
- Reference: `docs/superpowers/specs/2026-05-29-windows-app-workflow-parity-design.md`
- Modify: `docs/superpowers/plans/2026-05-29-windows-app-workflow-parity.md` (check off items during execution only)

- [ ] **Step 1: Run the focused runtime tests added in this plan**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "ForwardContractSignatureTests|EffectiveForwardConfigBuilderTests|ConfigPollingServiceTests|ForwardRuntimeManagerTests|HealthCheckServiceTests|BondEndpointHealthServiceTests"
```

Expected: PASS with all newly added tests green.

- [ ] **Step 2: Run the full Core test project**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj
```

Expected: PASS for the full `LeikwanBridge.Core.Tests` project.

- [ ] **Step 3: Record the explicitly deferred follow-up work in the execution notes**

Add these bullets to the execution notes / handoff summary after implementation:

```md
- Deferred: Rust relay `stats_path` production and usage telemetry
- Deferred: Windows direct CF-WG self-leg support unless future mirror payloads require it
- Deferred: Windows-managed WireGuard or Linux kernel-MPTCP parity
```

- [ ] **Step 4: Commit the validated implementation batch**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests \
  artifacts/windows-mirror-payload-sample.json \
  artifacts/windows-mirror-payload-notes.md \
  docs/superpowers/specs/2026-05-29-windows-app-workflow-parity-design.md \
  docs/superpowers/plans/2026-05-29-windows-app-workflow-parity.md
git commit -m "feat: align windows client with current workflow contract"
```

---

## Self-review checklist

### Spec coverage
- Gate-0 mirror payload capture: Task 1
- Model updates: Task 2
- Effective-config builder: Task 3
- Contract signature / no-op apply: Tasks 3-4
- Panel `health_status` gating: Task 5
- Preconnect `/health` probing: Task 6
- Force rebuild bypassing signature no-op: Task 6
- MPTCP fallback wiring: Task 7
- Relay-first / loopback-hiding-oriented runtime rendering: Task 8
- Deferred items explicitly preserved: Task 9

### Placeholder scan
- No `TBD`, `TODO`, or “similar to Task N” shortcuts are intentionally left in executable tasks
- Commands and expected outcomes are concrete
- Code snippets use actual project file paths and current type names

### Type consistency
- New field/property names are kept consistent across plan tasks:
  - `HealthStatus`
  - `ContractSignature`
  - `LastApplyReason`
  - `SlavePreconnect`
  - `PreconnectRelayPort`
- New helper names are consistent across tasks:
  - `EffectiveForwardConfigBuilder`
  - `ForwardContractSignature`

## Notes for the implementing engineer

- Do not expand scope into Windows direct WireGuard or Linux MPTCP mechanics unless Gate-0 proves it is required.
- Keep the signature helper narrowly focused on runtime intent. Observation-only fields must not create churn.
- Keep health-triggered rebuild logic forceful and independent from config no-op gating.
- Prefer the smallest change that makes the new tests pass at each task.
