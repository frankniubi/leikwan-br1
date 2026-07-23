# Windows Desktop Route Direction Client Rules Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Windows Desktop support for Route Direction + Client Rule pull data, helper-forward hiding, gated product-only route-rule UI, and conservative exact-destination TCP+UDP fail-closed planning without advertising support until Windows enforcement is proven safe.

**Architecture:** Windows Desktop keeps the existing local forward runtime for route-direction bindings but separates internal helper forwards from user-selectable forwards. Route-rule payloads are parsed into focused core models, combined into a `RouteRuleSnapshot` during config polling, and passed to a conservative `WindowsRouteRuleDispatcher` that generates deterministic TCP+UDP block/cleanup commands for fail-closed states while refusing to advertise active exact-destination support unless a verified backend can dispatch both protocols. The WPF route-rule surface is capability-gated and uses only Route Direction, Client Rule, destination, quota, and product status language.

**Tech Stack:** C# 12, .NET 8, WPF, CommunityToolkit.Mvvm, System.Text.Json, xUnit, Windows `netsh advfirewall` command generation.

---

## Scope

This plan modifies only files under:

`C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop`

It does not change backend, Linux, Android, admin-panel, or deployment code. Windows keeps `supports_route_direction_rules=false` and `supports_exact_destination_rules=false` by default. Those advertised values become true only if a Windows exact-destination backend reports TCP dispatch support, UDP dispatch support, and active enforcement test success through `WindowsRouteRuleDispatcher.CanAdvertiseExactDestinationRules`; this plan does not introduce such a backend.

## File Structure

### Core pull models

- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteDirection.cs`
  - Product-facing `route_directions[]` model: stable ID, label, status, availability, sort order.
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteRuleEntitlement.cs`
  - Pull model for `route_rule_entitlements[]`: service scope, quota, remaining count, allowed direction IDs.
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\ClientRouteRule.cs`
  - Pull model for `client_rules[]`: exact destination, fixed `tcp_udp`, bound direction, enabled state, effective state, sync version.
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\DirectionRuntimeBinding.cs`
  - Pull model for `direction_runtime_bindings[]`, including `runtime_ref` and `local_endpoint`.
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteRuleCapabilities.cs`
  - Pull and local advertised capability flags.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\PullResponse.cs`
  - Adds additive route-rule arrays and `Capabilities` while preserving existing `ok`, `client`, and `forwards` fields.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\ForwardConfig.cs`
  - Adds helper/non-selectable markers: `route_rule_helper` and nullable `selectable`.

### Core policy, snapshot, dispatcher, and polling

- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\RouteRuleSnapshot.cs`
  - Immutable snapshot of directions, entitlements, rules, runtime bindings, server capabilities, local advertised capabilities, and UI availability decision.
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\WindowsRouteRuleDispatcher.cs`
  - Conservative exact-destination dispatcher abstraction. It generates TCP+UDP fail-closed `netsh advfirewall` command plans and exposes a no-advertise gate based on verified active backend flags.
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\WindowsRouteRuleCapabilities.cs`
  - Builds local advertised capability payloads from a dispatcher. The default dispatcher returns false for route direction rules and exact destination rules.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Helpers\WindowsForwardPolicy.cs`
  - Keeps existing Windows runtime support checks and adds user-selectable filtering that hides helper/runtime forwards.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\ConfigPollingService.cs`
  - Builds `RouteRuleSnapshot`, builds a dispatcher plan, applies all supported runtime forwards, publishes only user-selectable forwards to UI, and publishes route-rule snapshots separately.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\HeartbeatPayload.cs`
  - Adds `supports_route_direction_rules` and `supports_exact_destination_rules`.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\HeartbeatService.cs`
  - Sends both route-rule advertised capability fields as false with the default dispatcher gate.

### WPF view models and UI

- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\ViewModels\RouteRuleViewModel.cs`
  - Product-only row view model for Client Rules. It exposes destination, Route Direction label, enabled state, effective state copy, quota scope, and sync version. It exposes no listener, forward, mode, transport, line, chain, slave, sidecar, or protocol UI properties.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\ViewModels\MainViewModel.cs`
  - Adds `RouteRules`, route-rule status text, route-rule UI availability, capability-gated nav property, polling snapshot subscription, and test hook.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\Views\MainWindow.xaml`
  - Adds route-rule navigation and panel behind capability gating. The panel contains only product terminology.
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\Views\MainWindow.xaml.cs`
  - Shows and hides `RouteRulesPanel` in `UpdateVisiblePanel`.

### Tests

All tests live under `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests`.

- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\RouteRulePullModelTests.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\WindowsForwardPolicyTests.cs`
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\WindowsRouteRuleDispatcherTests.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\ConfigPollingServiceTests.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\HeartbeatServiceTests.cs`
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\RouteRuleViewModelTests.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\MainViewModelTests.cs`
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\RouteRuleProductLanguageGuardTests.cs`

### Verification commands

- Core route-rule tests:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter "RouteRule|WindowsForwardPolicy|ConfigPollingService|HeartbeatService" -v normal
```

- App route-rule tests:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj" --filter "RouteRule|MainViewModel" -v normal
```

- Full Windows Desktop tests:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\LeikwanBridge.Desktop.sln" -v normal
```

## Tasks

### Task 1: Pull models for Route Direction Client Rules

**Files:**
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteDirection.cs`
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteRuleEntitlement.cs`
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\ClientRouteRule.cs`
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\DirectionRuntimeBinding.cs`
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteRuleCapabilities.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\PullResponse.cs`
- Test: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\RouteRulePullModelTests.cs`

- [ ] **Step 1: Write the failing pull model tests**

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\RouteRulePullModelTests.cs`:

```csharp
using System.Text.Json;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class RouteRulePullModelTests
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
    };

    [Fact]
    public void PullResponse_DeserializesRouteDirectionRulePayload()
    {
        var json = """
        {
          "ok": true,
          "client": { "id": 1, "name": "desktop" },
          "forwards": [],
          "route_directions": [
            {
              "id": "dir_sh_jp_1",
              "label": "沪日1",
              "status": "available",
              "is_available": true,
              "sort_order": 10
            }
          ],
          "route_rule_entitlements": [
            {
              "service_scope_id": "svc_scope_1001",
              "service_id": 1001,
              "subscription_id": 501,
              "max_rules": 5,
              "remaining_rules": 3,
              "allowed_direction_ids": ["dir_sh_jp_1"]
            }
          ],
          "client_rules": [
            {
              "id": "rule_1",
              "service_scope_id": "svc_scope_1001",
              "service_id": 1001,
              "subscription_id": 501,
              "destination_ip": "93.184.216.34",
              "destination_port": 443,
              "protocol": "tcp_udp",
              "direction_id": "dir_sh_jp_1",
              "enabled": true,
              "effective_state": "active",
              "sync_version": 17
            }
          ],
          "direction_runtime_bindings": [
            {
              "service_scope_id": "svc_scope_1001",
              "direction_id": "dir_sh_jp_1",
              "binding_state": "ready",
              "client_runtime_binding_id": "crb_svc_scope_1001_dir_sh_jp_1",
              "runtime_ref": {
                "type": "forward_ref",
                "forward_id": 10001,
                "selectable": false,
                "transport": "tcp_udp"
              },
              "local_endpoint": {
                "host": "127.0.0.1",
                "tcp_port": 11001,
                "udp_port": 11001
              },
              "tcp_ready": true,
              "udp_ready": true
            }
          ],
          "capabilities": {
            "route_direction_rules": true,
            "exact_destination_rules": true
          }
        }
        """;

        var response = JsonSerializer.Deserialize<PullResponse>(json, JsonOptions)!;

        Assert.True(response.Ok);
        var direction = Assert.Single(response.RouteDirections);
        Assert.Equal("dir_sh_jp_1", direction.Id);
        Assert.Equal("沪日1", direction.Label);
        Assert.Equal("available", direction.Status);
        Assert.True(direction.IsAvailable);
        Assert.Equal(10, direction.SortOrder);

        var entitlement = Assert.Single(response.RouteRuleEntitlements);
        Assert.Equal("svc_scope_1001", entitlement.ServiceScopeId);
        Assert.Equal(1001, entitlement.ServiceId);
        Assert.Equal(501, entitlement.SubscriptionId);
        Assert.Equal(5, entitlement.MaxRules);
        Assert.Equal(3, entitlement.RemainingRules);
        Assert.Equal(["dir_sh_jp_1"], entitlement.AllowedDirectionIds);

        var rule = Assert.Single(response.ClientRules);
        Assert.Equal("rule_1", rule.Id);
        Assert.Equal("svc_scope_1001", rule.ServiceScopeId);
        Assert.Equal(1001, rule.ServiceId);
        Assert.Equal(501, rule.SubscriptionId);
        Assert.Equal("93.184.216.34", rule.DestinationIp);
        Assert.Equal(443, rule.DestinationPort);
        Assert.Equal("tcp_udp", rule.Protocol);
        Assert.Equal("dir_sh_jp_1", rule.DirectionId);
        Assert.True(rule.Enabled);
        Assert.Equal("active", rule.EffectiveState);
        Assert.Equal(17, rule.SyncVersion);

        var binding = Assert.Single(response.DirectionRuntimeBindings);
        Assert.Equal("svc_scope_1001", binding.ServiceScopeId);
        Assert.Equal("dir_sh_jp_1", binding.DirectionId);
        Assert.Equal("ready", binding.BindingState);
        Assert.Equal("crb_svc_scope_1001_dir_sh_jp_1", binding.ClientRuntimeBindingId);
        Assert.Equal("forward_ref", binding.RuntimeRef.Type);
        Assert.Equal(10001, binding.RuntimeRef.ForId);
        Assert.False(binding.RuntimeRef.Selectable);
        Assert.Equal("tcp_udp", binding.RuntimeRef.Transport);
        Assert.Equal("127.0.0.1", binding.LocalEndpoint.Host);
        Assert.Equal(11001, binding.LocalEndpoint.TcpPort);
        Assert.Equal(11001, binding.LocalEndpoint.UdpPort);
        Assert.True(binding.TcpReady);
        Assert.True(binding.UdpReady);

        Assert.NotNull(response.Capabilities);
        Assert.True(response.Capabilities!.RouteDirectionRules);
        Assert.True(response.Capabilities.ExactDestinationRules);
    }

    [Fact]
    public void PullResponse_MissingRouteRuleFields_DefaultsToEmptyUnsupportedShape()
    {
        var response = JsonSerializer.Deserialize<PullResponse>("""
        { "ok": true, "client": {}, "forwards": [] }
        """, JsonOptions)!;

        Assert.Empty(response.RouteDirections);
        Assert.Empty(response.RouteRuleEntitlements);
        Assert.Empty(response.ClientRules);
        Assert.Empty(response.DirectionRuntimeBindings);
        Assert.Null(response.Capabilities);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter FullyQualifiedName~RouteRulePullModelTests -v normal
```

Expected: FAIL with compile errors naming missing pull model properties on `PullResponse`, including `RouteDirections`, `RouteRuleEntitlements`, `ClientRules`, `DirectionRuntimeBindings`, and `Capabilities`.

- [ ] **Step 3: Add route-rule pull model files**

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteDirection.cs`:

```csharp
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class RouteDirection
{
    [JsonPropertyName("id")]
    public string Id { get; set; } = "";

    [JsonPropertyName("label")]
    public string Label { get; set; } = "";

    [JsonPropertyName("status")]
    public string Status { get; set; } = "";

    [JsonPropertyName("is_available")]
    public bool IsAvailable { get; set; }

    [JsonPropertyName("sort_order")]
    public int SortOrder { get; set; }
}
```

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteRuleEntitlement.cs`:

```csharp
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class RouteRuleEntitlement
{
    [JsonPropertyName("service_scope_id")]
    public string ServiceScopeId { get; set; } = "";

    [JsonPropertyName("service_id")]
    public int ServiceId { get; set; }

    [JsonPropertyName("subscription_id")]
    public int SubscriptionId { get; set; }

    [JsonPropertyName("max_rules")]
    public int MaxRules { get; set; }

    [JsonPropertyName("remaining_rules")]
    public int RemainingRules { get; set; }

    [JsonPropertyName("allowed_direction_ids")]
    public List<string> AllowedDirectionIds { get; set; } = [];
}
```

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\ClientRouteRule.cs`:

```csharp
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class ClientRouteRule
{
    [JsonPropertyName("id")]
    public string Id { get; set; } = "";

    [JsonPropertyName("service_scope_id")]
    public string ServiceScopeId { get; set; } = "";

    [JsonPropertyName("service_id")]
    public int ServiceId { get; set; }

    [JsonPropertyName("subscription_id")]
    public int SubscriptionId { get; set; }

    [JsonPropertyName("destination_ip")]
    public string DestinationIp { get; set; } = "";

    [JsonPropertyName("destination_port")]
    public int DestinationPort { get; set; }

    [JsonPropertyName("protocol")]
    public string Protocol { get; set; } = "tcp_udp";

    [JsonPropertyName("direction_id")]
    public string DirectionId { get; set; } = "";

    [JsonPropertyName("enabled")]
    public bool Enabled { get; set; }

    [JsonPropertyName("effective_state")]
    public string EffectiveState { get; set; } = "";

    [JsonPropertyName("sync_version")]
    public int SyncVersion { get; set; }
}
```

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\DirectionRuntimeBinding.cs`:

```csharp
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class DirectionRuntimeBinding
{
    [JsonPropertyName("service_scope_id")]
    public string ServiceScopeId { get; set; } = "";

    [JsonPropertyName("direction_id")]
    public string DirectionId { get; set; } = "";

    [JsonPropertyName("binding_state")]
    public string BindingState { get; set; } = "";

    [JsonPropertyName("client_runtime_binding_id")]
    public string ClientRuntimeBindingId { get; set; } = "";

    [JsonPropertyName("runtime_ref")]
    public DirectionRuntimeRef RuntimeRef { get; set; } = new();

    [JsonPropertyName("local_endpoint")]
    public DirectionLocalEndpoint LocalEndpoint { get; set; } = new();

    [JsonPropertyName("tcp_ready")]
    public bool TcpReady { get; set; }

    [JsonPropertyName("udp_ready")]
    public bool UdpReady { get; set; }

    public bool IsReadyForTcpUdp()
    {
        return string.Equals(BindingState, "ready", StringComparison.OrdinalIgnoreCase)
            && TcpReady
            && UdpReady
            && LocalEndpoint.HasTcpUdpEndpoint();
    }
}

public sealed class DirectionRuntimeRef
{
    [JsonPropertyName("type")]
    public string Type { get; set; } = "";

    [JsonPropertyName("forward_id")]
    public int ForId { get; set; }

    [JsonPropertyName("selectable")]
    public bool Selectable { get; set; }

    [JsonPropertyName("transport")]
    public string Transport { get; set; } = "";
}

public sealed class DirectionLocalEndpoint
{
    [JsonPropertyName("host")]
    public string Host { get; set; } = "";

    [JsonPropertyName("tcp_port")]
    public int TcpPort { get; set; }

    [JsonPropertyName("udp_port")]
    public int UdpPort { get; set; }

    public bool HasTcpUdpEndpoint()
    {
        return !string.IsNullOrWhiteSpace(Host)
            && TcpPort is > 0 and <= 65535
            && UdpPort is > 0 and <= 65535;
    }
}
```

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteRuleCapabilities.cs`:

```csharp
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class RouteRuleCapabilities
{
    [JsonPropertyName("route_direction_rules")]
    public bool RouteDirectionRules { get; set; }

    [JsonPropertyName("exact_destination_rules")]
    public bool ExactDestinationRules { get; set; }

    public static RouteRuleCapabilities Disabled()
    {
        return new RouteRuleCapabilities
        {
            RouteDirectionRules = false,
            ExactDestinationRules = false,
        };
    }
}
```

- [ ] **Step 4: Replace `PullResponse.cs` with the additive pull shape**

Replace the full contents of `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\PullResponse.cs` with:

```csharp
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class PullResponse
{
    [JsonPropertyName("ok")]
    public bool Ok { get; set; }

    [JsonPropertyName("client")]
    public ClientMeta? Client { get; set; }

    [JsonPropertyName("forwards")]
    public List<ForwardConfig> Forwards { get; set; } = [];

    [JsonPropertyName("route_directions")]
    public List<RouteDirection> RouteDirections { get; set; } = [];

    [JsonPropertyName("route_rule_entitlements")]
    public List<RouteRuleEntitlement> RouteRuleEntitlements { get; set; } = [];

    [JsonPropertyName("client_rules")]
    public List<ClientRouteRule> ClientRules { get; set; } = [];

    [JsonPropertyName("direction_runtime_bindings")]
    public List<DirectionRuntimeBinding> DirectionRuntimeBindings { get; set; } = [];

    [JsonPropertyName("capabilities")]
    public RouteRuleCapabilities? Capabilities { get; set; }
}
```

- [ ] **Step 5: Run pull model tests to verify they pass**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter FullyQualifiedName~RouteRulePullModelTests -v normal
```

Expected: PASS with both `RouteRulePullModelTests` passing.

- [ ] **Step 6: Commit pull model work**

Run:

```powershell
git add "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteDirection.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteRuleEntitlement.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\ClientRouteRule.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\DirectionRuntimeBinding.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\RouteRuleCapabilities.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\PullResponse.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\RouteRulePullModelTests.cs"
git commit -m "feat(windows): parse route direction rule pull payload" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds.

### Task 2: Helper-forward filtering for user-selectable UI

**Files:**
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\ForwardConfig.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Helpers\WindowsForwardPolicy.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\WindowsForwardPolicyTests.cs`

- [ ] **Step 1: Write failing helper-forward filtering tests**

Append these tests inside the `WindowsForwardPolicyTests` class in `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\WindowsForwardPolicyTests.cs`, before the `CreateForward` helper method:

```csharp
    [Fact]
    public void FilterUserSelectableForwards_HidesRouteRuleHelperForwards()
    {
        var standalone = CreateForward(101, "single");
        var helperByMarker = CreateForward(102, "single");
        helperByMarker.RouteRuleHelper = true;
        var helperBySelectableFlag = CreateForward(103, "bond");
        helperBySelectableFlag.Selectable = false;

        var filtered = WindowsForwardPolicy.FilterUserSelectableForwards(
        [
            standalone,
            helperByMarker,
            helperBySelectableFlag,
        ]);

        Assert.Equal([101], filtered.Select(forward => forward.ForwardId).ToArray());
    }

    [Fact]
    public void FilterSupportedForwards_KeepsHelperRuntimeForBindingPreparation()
    {
        var helper = CreateForward(104, "single");
        helper.RouteRuleHelper = true;

        var filtered = WindowsForwardPolicy.FilterSupportedForwards([helper]);

        var kept = Assert.Single(filtered);
        Assert.Equal(104, kept.ForId);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter FullyQualifiedName~WindowsForwardPolicyTests -v normal
```

Expected: FAIL with compile errors for missing `ForwardConfig.RouteRuleHelper`, `ForwardConfig.Selectable`, `WindowsForwardPolicy.FilterUserSelectableForwards`, and `ForwardConfig.ForId` if the `ForId` compatibility alias has not been added yet.

- [ ] **Step 3: Replace `ForwardConfig.cs` with helper marker support and compatibility alias**

Replace the full contents of `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\ForwardConfig.cs` with:

```csharp
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class ForwardConfig
{
    [JsonPropertyName("forward_id")]
    public int ForwardId { get; set; }

    [JsonIgnore]
    public int ForId => ForwardId;

    [JsonPropertyName("service_status")]
    public string ServiceStatus { get; set; } = "active";

    [JsonPropertyName("mode")]
    public string Mode { get; set; } = "single";

    [JsonPropertyName("listen_port")]
    public int ListenPort { get; set; }

    [JsonPropertyName("target")]
    public string Target { get; set; } = "";

    [JsonPropertyName("sni")]
    public string Sni { get; set; } = "";

    [JsonPropertyName("path_prefix")]
    public string PathPrefix { get; set; } = "";

    [JsonPropertyName("transport")]
    public string Transport { get; set; } = "tcp+udp";

    [JsonPropertyName("backend_mode")]
    public string BackendMode { get; set; } = "current_mode";

    [JsonPropertyName("contract_version")]
    public int ContractVersion { get; set; }

    [JsonPropertyName("client_mode")]
    public string ClientMode { get; set; } = "";

    [JsonPropertyName("runtime_preference")]
    public string RuntimePreference { get; set; } = "single";

    [JsonPropertyName("bond_ingress_mode")]
    public string BondIngressMode { get; set; } = "";

    [JsonPropertyName("active_family")]
    public string ActiveFamily { get; set; } = "";

    [JsonPropertyName("aggregation_enabled")]
    public bool AggregationEnabled { get; set; }

    [JsonPropertyName("target_contract")]
    public string TargetContract { get; set; } = "";

    [JsonPropertyName("sidecar_required")]
    public bool SidecarRequired { get; set; }

    [JsonPropertyName("bond_sidecar_point_id")]
    public int? BondSidecarPointId { get; set; }

    [JsonPropertyName("route_rule_helper")]
    public bool RouteRuleHelper { get; set; }

    [JsonPropertyName("selectable")]
    public bool? Selectable { get; set; }

    [JsonPropertyName("speed_limit_mbps")]
    public double? SpeedLimitMbps { get; set; }

    [JsonPropertyName("endpoints")]
    public List<EndpointConfig> Endpoints { get; set; } = [];

    [JsonPropertyName("bonding")]
    public BondingConfig? Bonding { get; set; }

    [JsonPropertyName("mptcp")]
    public MptcpConfig? Mptcp { get; set; }
}
```

- [ ] **Step 4: Replace `WindowsForwardPolicy.cs` with user-selectable filtering**

Replace the full contents of `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Helpers\WindowsForwardPolicy.cs` with:

```csharp
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

public static class WindowsForwardPolicy
{
    public static bool IsSupportedOnWindows(ForwardConfig forward)
    {
        var mode = NormalizeModeOrEmpty(FirstNonEmpty(forward.ClientMode, forward.Mode));
        if (mode is not ("single" or "bond" or "mptcp"))
            return false;

        if (!HasCanonicalContract(forward))
            return true;

        if (IsSidecarAggregation(forward))
            return mode is "bond" or "mptcp" && HasSidecarRuntimeTarget(forward, mode);

        if (IsDedicatedLeiGod(forward))
            return mode == "single"
                && !forward.SidecarRequired
                && !forward.AggregationEnabled
                && !string.IsNullOrWhiteSpace(forward.Target);

        return mode is "single" or "bond" or "mptcp";
    }

    public static bool IsRouteRuleHelperForward(ForwardConfig forward)
    {
        return forward.RouteRuleHelper || forward.Selectable == false;
    }

    public static bool IsUserSelectableForward(ForwardConfig forward)
    {
        return IsSupportedOnWindows(forward) && !IsRouteRuleHelperForward(forward);
    }

    public static List<ForwardConfig> FilterSupportedForwards(IEnumerable<ForwardConfig> forwards)
    {
        return forwards
            .Where(IsSupportedOnWindows)
            .ToList();
    }

    public static List<ForwardConfig> FilterUserSelectableForwards(IEnumerable<ForwardConfig> forwards)
    {
        return forwards
            .Where(IsUserSelectableForward)
            .ToList();
    }

    private static bool HasCanonicalContract(ForwardConfig forward)
    {
        return forward.ContractVersion > 0
            || !string.IsNullOrWhiteSpace(forward.ClientMode)
            || !string.IsNullOrWhiteSpace(forward.RuntimePreference)
            || !string.IsNullOrWhiteSpace(forward.BondIngressMode)
            || !string.IsNullOrWhiteSpace(forward.ActiveFamily)
            || !string.IsNullOrWhiteSpace(forward.TargetContract)
            || forward.AggregationEnabled
            || forward.SidecarRequired
            || forward.BondSidecarPointId.HasValue;
    }

    private static bool IsSidecarAggregation(ForwardConfig forward)
    {
        return forward.AggregationEnabled
            || forward.SidecarRequired
            || string.Equals(forward.TargetContract, "sidecar_aggregation", StringComparison.OrdinalIgnoreCase)
            || string.Equals(forward.ActiveFamily, "sidecar_aggregation", StringComparison.OrdinalIgnoreCase);
    }

    private static bool IsDedicatedLeiGod(ForwardConfig forward)
    {
        return string.Equals(forward.BackendMode, "leigod_mode", StringComparison.OrdinalIgnoreCase)
            || string.Equals(forward.TargetContract, "dedicated_forward_node", StringComparison.OrdinalIgnoreCase)
            || string.Equals(forward.ActiveFamily, "leigod_only", StringComparison.OrdinalIgnoreCase);
    }

    private static bool HasSidecarRuntimeTarget(ForwardConfig forward, string mode)
    {
        if (!forward.BondSidecarPointId.HasValue)
            return false;

        return mode switch
        {
            "bond" => HasBondSidecarTarget(forward.Bonding),
            "mptcp" => HasMptcpSidecarTarget(forward.Mptcp),
            _ => false,
        };
    }

    private static bool HasBondSidecarTarget(BondingConfig? bonding)
    {
        if (bonding is null)
            return false;

        return HasHostPort(bonding.SidecarHost, bonding.SidecarPort)
            || HasHostPort(bonding.AggregatorHost, bonding.AggregatorPort)
            || HasHostPort(bonding.RelayHost, bonding.RelayPort)
            || !string.IsNullOrWhiteSpace(bonding.RemoteTarget);
    }

    private static bool HasMptcpSidecarTarget(MptcpConfig? mptcp)
    {
        if (mptcp is null)
            return false;

        return HasHostPort(mptcp.SidecarHost, mptcp.SidecarPort)
            || HasHostPort(mptcp.RelayHost, mptcp.RelayPort)
            || HasHostPort(mptcp.RemoteHost, mptcp.RemotePort)
            || HasHostPort(mptcp.SlaveRelayHost, mptcp.SlaveRelayPort);
    }

    private static bool HasHostPort(string host, int port)
    {
        return !string.IsNullOrWhiteSpace(host) && port > 0;
    }

    private static string NormalizeModeOrEmpty(string? mode)
    {
        try
        {
            return ModeNormalizer.Normalize(mode ?? "single");
        }
        catch
        {
            return "";
        }
    }

    private static string FirstNonEmpty(params string?[] values)
    {
        foreach (var value in values)
        {
            if (!string.IsNullOrWhiteSpace(value))
                return value.Trim();
        }

        return "";
    }
}
```

- [ ] **Step 5: Run helper filtering tests to verify they pass**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter FullyQualifiedName~WindowsForwardPolicyTests -v normal
```

Expected: PASS with existing Windows support tests and the two new helper-forward filtering tests passing.

- [ ] **Step 6: Commit helper filtering work**

Run:

```powershell
git add "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\ForwardConfig.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Helpers\WindowsForwardPolicy.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\WindowsForwardPolicyTests.cs"
git commit -m "feat(windows): hide route rule helper forwards" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds.

### Task 3: Conservative Windows TCP+UDP route-rule dispatcher

**Files:**
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\RouteRuleSnapshot.cs`
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\WindowsRouteRuleDispatcher.cs`
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\WindowsRouteRuleCapabilities.cs`
- Test: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\WindowsRouteRuleDispatcherTests.cs`

- [ ] **Step 1: Write failing dispatcher and snapshot tests**

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\WindowsRouteRuleDispatcherTests.cs`:

```csharp
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsRouteRuleDispatcherTests
{
    [Fact]
    public void BuildPlan_ActiveRuleWithoutVerifiedBackend_GeneratesTcpAndUdpFailClosedBlocks()
    {
        var dispatcher = new WindowsRouteRuleDispatcher();
        var snapshot = CreateSnapshot(ruleState: "active", enabled: true);

        var plan = dispatcher.BuildPlan(snapshot);

        Assert.False(plan.CanAdvertiseExactDestinationRules);
        var state = Assert.Single(plan.RuleStates);
        Assert.Equal("rule_1", state.RuleId);
        Assert.Equal("blocked_not_enforceable", state.InstallState);
        Assert.Equal("exact destination backend is not verified for TCP+UDP dispatch", state.Reason);
        Assert.Equal(4, plan.Commands.Count);
        Assert.Equal("netsh advfirewall firewall delete rule name=\"Leikwan Route Rule rule_1 TCP\"", plan.Commands[0].CommandLine);
        Assert.Equal("netsh advfirewall firewall add rule name=\"Leikwan Route Rule rule_1 TCP\" dir=out action=block enable=yes profile=any remoteip=93.184.216.34 remoteport=443 protocol=TCP", plan.Commands[1].CommandLine);
        Assert.Equal("netsh advfirewall firewall delete rule name=\"Leikwan Route Rule rule_1 UDP\"", plan.Commands[2].CommandLine);
        Assert.Equal("netsh advfirewall firewall add rule name=\"Leikwan Route Rule rule_1 UDP\" dir=out action=block enable=yes profile=any remoteip=93.184.216.34 remoteport=443 protocol=UDP", plan.Commands[3].CommandLine);
    }

    [Fact]
    public void BuildPlan_NonActiveEnabledRule_BlocksExactDestination()
    {
        var dispatcher = new WindowsRouteRuleDispatcher();
        var snapshot = CreateSnapshot(ruleState: "not_allowed_by_plan", enabled: true);

        var plan = dispatcher.BuildPlan(snapshot);

        var state = Assert.Single(plan.RuleStates);
        Assert.Equal("blocked_not_allowed_by_plan", state.InstallState);
        Assert.Equal("not_allowed_by_plan", state.Reason);
        Assert.Contains(plan.Commands, command => command.CommandLine.Contains("protocol=TCP", StringComparison.Ordinal));
        Assert.Contains(plan.Commands, command => command.CommandLine.Contains("protocol=UDP", StringComparison.Ordinal));
    }

    [Fact]
    public void BuildPlan_DisabledRule_RemovesExistingManagedRulesOnly()
    {
        var dispatcher = new WindowsRouteRuleDispatcher();
        var snapshot = CreateSnapshot(ruleState: "disabled", enabled: false);

        var plan = dispatcher.BuildPlan(snapshot);

        var state = Assert.Single(plan.RuleStates);
        Assert.Equal("not_installed", state.InstallState);
        Assert.Equal("disabled", state.Reason);
        Assert.Equal(2, plan.Commands.Count);
        Assert.All(plan.Commands, command => Assert.Equal("delete", command.Kind));
    }

    [Fact]
    public void CanAdvertiseExactDestinationRules_RequiresBothProtocolsAndActiveEnforcementTests()
    {
        var noBackend = new WindowsRouteRuleDispatcher();
        var tcpOnly = new WindowsRouteRuleDispatcher(new WindowsRouteRuleDispatcherOptions
        {
            ActiveTcpDispatchSupported = true,
            ActiveUdpDispatchSupported = false,
            ActiveEnforcementTestsPassed = true,
        });
        var untested = new WindowsRouteRuleDispatcher(new WindowsRouteRuleDispatcherOptions
        {
            ActiveTcpDispatchSupported = true,
            ActiveUdpDispatchSupported = true,
            ActiveEnforcementTestsPassed = false,
        });
        var verified = new WindowsRouteRuleDispatcher(new WindowsRouteRuleDispatcherOptions
        {
            ActiveTcpDispatchSupported = true,
            ActiveUdpDispatchSupported = true,
            ActiveEnforcementTestsPassed = true,
        });

        Assert.False(noBackend.CanAdvertiseExactDestinationRules);
        Assert.False(tcpOnly.CanAdvertiseExactDestinationRules);
        Assert.False(untested.CanAdvertiseExactDestinationRules);
        Assert.True(verified.CanAdvertiseExactDestinationRules);
    }

    [Fact]
    public void BuildPlan_VerifiedBackend_MarksActiveRuleDispatchableWithoutBlockCommand()
    {
        var dispatcher = new WindowsRouteRuleDispatcher(new WindowsRouteRuleDispatcherOptions
        {
            ActiveTcpDispatchSupported = true,
            ActiveUdpDispatchSupported = true,
            ActiveEnforcementTestsPassed = true,
        });
        var snapshot = CreateSnapshot(ruleState: "active", enabled: true);

        var plan = dispatcher.BuildPlan(snapshot);

        Assert.True(plan.CanAdvertiseExactDestinationRules);
        var state = Assert.Single(plan.RuleStates);
        Assert.Equal("dispatch_ready", state.InstallState);
        Assert.Equal("ready", state.Reason);
        Assert.Equal(2, plan.Commands.Count);
        Assert.All(plan.Commands, command => Assert.Equal("delete", command.Kind));
    }

    private static RouteRuleSnapshot CreateSnapshot(string ruleState, bool enabled)
    {
        var pull = new PullResponse
        {
            RouteDirections =
            [
                new RouteDirection
                {
                    Id = "dir_sh_jp_1",
                    Label = "沪日1",
                    Status = "available",
                    IsAvailable = true,
                    SortOrder = 10,
                },
            ],
            RouteRuleEntitlements =
            [
                new RouteRuleEntitlement
                {
                    ServiceScopeId = "svc_scope_1001",
                    ServiceId = 1001,
                    SubscriptionId = 501,
                    MaxRules = 5,
                    RemainingRules = 3,
                    AllowedDirectionIds = ["dir_sh_jp_1"],
                },
            ],
            ClientRules =
            [
                new ClientRouteRule
                {
                    Id = "rule_1",
                    ServiceScopeId = "svc_scope_1001",
                    ServiceId = 1001,
                    SubscriptionId = 501,
                    DestinationIp = "93.184.216.34",
                    DestinationPort = 443,
                    Protocol = "tcp_udp",
                    DirectionId = "dir_sh_jp_1",
                    Enabled = enabled,
                    EffectiveState = ruleState,
                    SyncVersion = 17,
                },
            ],
            DirectionRuntimeBindings =
            [
                new DirectionRuntimeBinding
                {
                    ServiceScopeId = "svc_scope_1001",
                    DirectionId = "dir_sh_jp_1",
                    BindingState = "ready",
                    ClientRuntimeBindingId = "crb_svc_scope_1001_dir_sh_jp_1",
                    RuntimeRef = new DirectionRuntimeRef
                    {
                        Type = "forward_ref",
                        ForId = 10001,
                        Selectable = false,
                        Transport = "tcp_udp",
                    },
                    LocalEndpoint = new DirectionLocalEndpoint
                    {
                        Host = "127.0.0.1",
                        TcpPort = 11001,
                        UdpPort = 11001,
                    },
                    TcpReady = true,
                    UdpReady = true,
                },
            ],
            Capabilities = new RouteRuleCapabilities
            {
                RouteDirectionRules = true,
                ExactDestinationRules = true,
            },
        };

        return RouteRuleSnapshot.FromPullResponse(
            pull,
            new RouteRuleCapabilities
            {
                RouteDirectionRules = true,
                ExactDestinationRules = true,
            });
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter FullyQualifiedName~WindowsRouteRuleDispatcherTests -v normal
```

Expected: FAIL with compile errors for missing `RouteRuleSnapshot`, `WindowsRouteRuleDispatcher`, `WindowsRouteRuleDispatcherOptions`, `WindowsRouteRulePlan`, and related dispatcher command/state types.

- [ ] **Step 3: Add `RouteRuleSnapshot.cs`**

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\RouteRuleSnapshot.cs`:

```csharp
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed record RouteRuleSnapshot(
    IReadOnlyList<RouteDirection> RouteDirections,
    IReadOnlyList<RouteRuleEntitlement> Entitlements,
    IReadOnlyList<ClientRouteRule> ClientRules,
    IReadOnlyList<DirectionRuntimeBinding> RuntimeBindings,
    bool ServerRouteDirectionRules,
    bool ServerExactDestinationRules,
    bool LocalRouteDirectionRules,
    bool LocalExactDestinationRules)
{
    public static RouteRuleSnapshot Empty { get; } = new(
        Array.Empty<RouteDirection>(),
        Array.Empty<RouteRuleEntitlement>(),
        Array.Empty<ClientRouteRule>(),
        Array.Empty<DirectionRuntimeBinding>(),
        ServerRouteDirectionRules: false,
        ServerExactDestinationRules: false,
        LocalRouteDirectionRules: false,
        LocalExactDestinationRules: false);

    public bool HasServerRouteRulePayload =>
        ServerRouteDirectionRules
        || ServerExactDestinationRules
        || RouteDirections.Count > 0
        || Entitlements.Count > 0
        || ClientRules.Count > 0
        || RuntimeBindings.Count > 0;

    public bool IsUiEnabled =>
        ServerRouteDirectionRules
        && ServerExactDestinationRules
        && LocalRouteDirectionRules
        && LocalExactDestinationRules;

    public IReadOnlyList<ClientRouteRule> EnabledRules => ClientRules
        .Where(rule => rule.Enabled)
        .ToList();

    public static RouteRuleSnapshot FromPullResponse(PullResponse pull, RouteRuleCapabilities localCapabilities)
    {
        var serverCapabilities = pull.Capabilities;
        return new RouteRuleSnapshot(
            pull.RouteDirections
                .OrderBy(direction => direction.SortOrder)
                .ThenBy(direction => direction.Label, StringComparer.Ordinal)
                .ToList(),
            pull.RouteRuleEntitlements.ToList(),
            pull.ClientRules.ToList(),
            pull.DirectionRuntimeBindings.ToList(),
            ServerRouteDirectionRules: serverCapabilities?.RouteDirectionRules == true,
            ServerExactDestinationRules: serverCapabilities?.ExactDestinationRules == true,
            LocalRouteDirectionRules: localCapabilities.RouteDirectionRules,
            LocalExactDestinationRules: localCapabilities.ExactDestinationRules);
    }

    public RouteDirection? FindDirection(string directionId)
    {
        return RouteDirections.FirstOrDefault(direction =>
            string.Equals(direction.Id, directionId, StringComparison.Ordinal));
    }

    public DirectionRuntimeBinding? FindReadyBinding(ClientRouteRule rule)
    {
        return RuntimeBindings.FirstOrDefault(binding =>
            string.Equals(binding.ServiceScopeId, rule.ServiceScopeId, StringComparison.Ordinal)
            && string.Equals(binding.DirectionId, rule.DirectionId, StringComparison.Ordinal)
            && binding.IsReadyForTcpUdp());
    }
}
```

- [ ] **Step 4: Add `WindowsRouteRuleCapabilities.cs`**

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\WindowsRouteRuleCapabilities.cs`:

```csharp
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public static class WindowsRouteRuleCapabilities
{
    public static RouteRuleCapabilities BuildAdvertisedCapabilities(WindowsRouteRuleDispatcher dispatcher)
    {
        var canAdvertise = dispatcher.CanAdvertiseExactDestinationRules;
        return new RouteRuleCapabilities
        {
            RouteDirectionRules = canAdvertise,
            ExactDestinationRules = canAdvertise,
        };
    }

    public static RouteRuleCapabilities BuildNoAdvertiseCapabilities()
    {
        return RouteRuleCapabilities.Disabled();
    }
}
```

- [ ] **Step 5: Add `WindowsRouteRuleDispatcher.cs`**

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\WindowsRouteRuleDispatcher.cs`:

```csharp
using System.Net;
using System.Net.Sockets;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed class WindowsRouteRuleDispatcherOptions
{
    public bool ActiveTcpDispatchSupported { get; init; }
    public bool ActiveUdpDispatchSupported { get; init; }
    public bool ActiveEnforcementTestsPassed { get; init; }
}

public class WindowsRouteRuleDispatcher
{
    private readonly WindowsRouteRuleDispatcherOptions _options;

    public WindowsRouteRuleDispatcher(WindowsRouteRuleDispatcherOptions? options = null)
    {
        _options = options ?? new WindowsRouteRuleDispatcherOptions();
    }

    public bool CanAdvertiseExactDestinationRules =>
        _options.ActiveTcpDispatchSupported
        && _options.ActiveUdpDispatchSupported
        && _options.ActiveEnforcementTestsPassed;

    public bool CanAdvertiseRouteDirectionRules => CanAdvertiseExactDestinationRules;

    public virtual WindowsRouteRulePlan BuildPlan(RouteRuleSnapshot snapshot)
    {
        var commands = new List<WindowsRouteRuleCommand>();
        var states = new List<WindowsRouteRuleInstallState>();

        foreach (var rule in snapshot.ClientRules)
        {
            var rulePlan = BuildRulePlan(snapshot, rule);
            commands.AddRange(rulePlan.Commands);
            states.Add(rulePlan.State);
        }

        return new WindowsRouteRulePlan(
            commands,
            states,
            CanAdvertiseRouteDirectionRules,
            CanAdvertiseExactDestinationRules);
    }

    private WindowsSingleRouteRulePlan BuildRulePlan(RouteRuleSnapshot snapshot, ClientRouteRule rule)
    {
        if (!IsDestinationCommandSafe(rule))
        {
            return new WindowsSingleRouteRulePlan(
                [],
                new WindowsRouteRuleInstallState(
                    rule.Id,
                    rule.DestinationIp,
                    rule.DestinationPort,
                    "not_installed",
                    "invalid_destination"));
        }

        if (!rule.Enabled)
        {
            return new WindowsSingleRouteRulePlan(
                DeleteCommands(rule),
                new WindowsRouteRuleInstallState(
                    rule.Id,
                    rule.DestinationIp,
                    rule.DestinationPort,
                    "not_installed",
                    "disabled"));
        }

        if (!string.Equals(rule.EffectiveState, "active", StringComparison.OrdinalIgnoreCase))
        {
            return new WindowsSingleRouteRulePlan(
                FailClosedCommands(rule),
                new WindowsRouteRuleInstallState(
                    rule.Id,
                    rule.DestinationIp,
                    rule.DestinationPort,
                    $"blocked_{NormalizeReason(rule.EffectiveState)}",
                    NormalizeReason(rule.EffectiveState)));
        }

        var direction = snapshot.FindDirection(rule.DirectionId);
        var binding = snapshot.FindReadyBinding(rule);
        if (direction is null || !direction.IsAvailable || !string.Equals(direction.Status, "available", StringComparison.OrdinalIgnoreCase))
        {
            return new WindowsSingleRouteRulePlan(
                FailClosedCommands(rule),
                new WindowsRouteRuleInstallState(
                    rule.Id,
                    rule.DestinationIp,
                    rule.DestinationPort,
                    "blocked_direction_unavailable",
                    "direction_unavailable"));
        }

        if (binding is null)
        {
            return new WindowsSingleRouteRulePlan(
                FailClosedCommands(rule),
                new WindowsRouteRuleInstallState(
                    rule.Id,
                    rule.DestinationIp,
                    rule.DestinationPort,
                    "blocked_runtime_missing",
                    "runtime_missing"));
        }

        if (!CanAdvertiseExactDestinationRules)
        {
            return new WindowsSingleRouteRulePlan(
                FailClosedCommands(rule),
                new WindowsRouteRuleInstallState(
                    rule.Id,
                    rule.DestinationIp,
                    rule.DestinationPort,
                    "blocked_not_enforceable",
                    "exact destination backend is not verified for TCP+UDP dispatch"));
        }

        return new WindowsSingleRouteRulePlan(
            DeleteCommands(rule),
            new WindowsRouteRuleInstallState(
                rule.Id,
                rule.DestinationIp,
                rule.DestinationPort,
                "dispatch_ready",
                "ready"));
    }

    private static bool IsDestinationCommandSafe(ClientRouteRule rule)
    {
        return IPAddress.TryParse(rule.DestinationIp, out var address)
            && address.AddressFamily == AddressFamily.InterNetwork
            && rule.DestinationPort is > 0 and <= 65535;
    }

    private static IReadOnlyList<WindowsRouteRuleCommand> FailClosedCommands(ClientRouteRule rule)
    {
        var result = new List<WindowsRouteRuleCommand>();
        result.AddRange(DeleteCommands(rule));
        result.Add(BlockCommand(rule, "TCP"));
        result.Add(BlockCommand(rule, "UDP"));
        return result;
    }

    private static IReadOnlyList<WindowsRouteRuleCommand> DeleteCommands(ClientRouteRule rule)
    {
        return
        [
            DeleteCommand(rule, "TCP"),
            DeleteCommand(rule, "UDP"),
        ];
    }

    private static WindowsRouteRuleCommand DeleteCommand(ClientRouteRule rule, string protocol)
    {
        var name = RuleName(rule, protocol);
        return new WindowsRouteRuleCommand(
            Kind: "delete",
            RuleId: rule.Id,
            Protocol: protocol,
            RuleName: name,
            CommandLine: $"netsh advfirewall firewall delete rule name=\"{name}\"");
    }

    private static WindowsRouteRuleCommand BlockCommand(ClientRouteRule rule, string protocol)
    {
        var name = RuleName(rule, protocol);
        return new WindowsRouteRuleCommand(
            Kind: "block",
            RuleId: rule.Id,
            Protocol: protocol,
            RuleName: name,
            CommandLine: $"netsh advfirewall firewall add rule name=\"{name}\" dir=out action=block enable=yes profile=any remoteip={rule.DestinationIp} remoteport={rule.DestinationPort} protocol={protocol}");
    }

    private static string RuleName(ClientRouteRule rule, string protocol)
    {
        var safeRuleId = new string(rule.Id
            .Where(ch => char.IsLetterOrDigit(ch) || ch is '_' or '-')
            .ToArray());
        if (string.IsNullOrWhiteSpace(safeRuleId))
            safeRuleId = "unnamed";
        return $"Leikwan Route Rule {safeRuleId} {protocol}";
    }

    private static string NormalizeReason(string value)
    {
        var normalized = string.IsNullOrWhiteSpace(value) ? "unknown" : value.Trim().ToLowerInvariant();
        return normalized.Replace(' ', '_');
    }

    private sealed record WindowsSingleRouteRulePlan(
        IReadOnlyList<WindowsRouteRuleCommand> Commands,
        WindowsRouteRuleInstallState State);
}

public sealed record WindowsRouteRulePlan(
    IReadOnlyList<WindowsRouteRuleCommand> Commands,
    IReadOnlyList<WindowsRouteRuleInstallState> RuleStates,
    bool CanAdvertiseRouteDirectionRules,
    bool CanAdvertiseExactDestinationRules)
{
    public static WindowsRouteRulePlan Empty { get; } = new(
        Array.Empty<WindowsRouteRuleCommand>(),
        Array.Empty<WindowsRouteRuleInstallState>(),
        CanAdvertiseRouteDirectionRules: false,
        CanAdvertiseExactDestinationRules: false);
}

public sealed record WindowsRouteRuleCommand(
    string Kind,
    string RuleId,
    string Protocol,
    string RuleName,
    string CommandLine);

public sealed record WindowsRouteRuleInstallState(
    string RuleId,
    string DestinationIp,
    int DestinationPort,
    string InstallState,
    string Reason);
```

- [ ] **Step 6: Run dispatcher tests to verify they pass**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter FullyQualifiedName~WindowsRouteRuleDispatcherTests -v normal
```

Expected: PASS with all dispatcher tests passing. The default dispatcher must report `CanAdvertiseExactDestinationRules=false` and generate TCP+UDP fail-closed block commands for active rules when active enforcement is not verified.

- [ ] **Step 7: Commit dispatcher work**

Run:

```powershell
git add "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\RouteRuleSnapshot.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\WindowsRouteRuleDispatcher.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\WindowsRouteRuleCapabilities.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\WindowsRouteRuleDispatcherTests.cs"
git commit -m "feat(windows): plan exact destination fail closed rules" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds.

### Task 4: ConfigPollingService route-rule snapshot handling

**Files:**
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\ConfigPollingService.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\ConfigPollingServiceTests.cs`

- [ ] **Step 1: Write failing config polling tests**

Append these tests inside the `ConfigPollingServiceTests` class in `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\ConfigPollingServiceTests.cs`, before `BuildPullResponseJson()`:

```csharp
    [Fact]
    public async Task SyncOnceAsync_PublishesRouteRuleSnapshotAndPlan()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildPullResponseWithRouteRulesJson());
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        var dispatcher = new RecordingRouteRuleDispatcher();
        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtimeManager, stateManager, dispatcher);
        RouteRuleSnapshot? published = null;
        service.RouteRuleSnapshotUpdated += snapshot => published = snapshot;

        await service.SyncOnceAsync();

        Assert.NotNull(published);
        Assert.True(published!.ServerRouteDirectionRules);
        Assert.True(published.ServerExactDestinationRules);
        Assert.False(published.LocalRouteDirectionRules);
        Assert.False(published.LocalExactDestinationRules);
        Assert.False(published.IsUiEnabled);
        Assert.Equal("dir_sh_jp_1", Assert.Single(published.RouteDirections).Id);
        Assert.Equal("rule_1", Assert.Single(published.ClientRules).Id);
        Assert.Same(published, dispatcher.LastSnapshot);
        Assert.Equal("blocked_not_enforceable", Assert.Single(service.LatestRouteRulePlan.RuleStates).InstallState);
    }

    [Fact]
    public async Task SyncOnceAsync_AppliesHelperRuntimeForwardButDoesNotPublishItToUserSelectableForwards()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new PullApiServer(BuildPullResponseWithRouteRulesJson());
        var config = new AgentConfig
        {
            MasterUrl = server.BaseUrl,
            AgentToken = "token",
            ClientId = 1,
            StateDir = tempDir.Path,
        };
        var stateManager = new StateManager(tempDir.Path);
        var runtimeManager = new RecordingForwardRuntimeManager(config, stateManager, new AlwaysRunningProcessSupervisor());
        using var api = new MasterApiClient(server.BaseUrl, "token");
        var service = new ConfigPollingService(api, runtimeManager, stateManager);
        List<ForwardConfig>? publishedForwards = null;
        service.ForwardsUpdated += forwards => publishedForwards = forwards;

        await service.SyncOnceAsync();

        Assert.Equal([26, 10001], runtimeManager.AppliedForwardIds);
        Assert.NotNull(publishedForwards);
        Assert.Equal([26], publishedForwards!.Select(forward => forward.ForwardId).ToArray());
        Assert.Equal([26], service.LatestForwards.Select(forward => forward.ForwardId).ToArray());
    }
```

Append this helper method inside `ConfigPollingServiceTests`, after `BuildPullResponseJson()` and before `BuildForwardFromPullResponse()`:

```csharp
    private static string BuildPullResponseWithRouteRulesJson()
    {
        return """
        {
          "ok": true,
          "client": {
            "id": 1,
            "name": "desktop",
            "display_host": "desktop",
            "group_id": null,
            "diag_request_token": "",
            "diag_status": "ok",
            "agent_config_json": {}
          },
          "forwards": [
            {
              "forward_id": 26,
              "service_status": "active",
              "mode": "bond",
              "listen_port": 11024,
              "target": "193.227.109.82:5201",
              "sni": "v3.leikwanhost.com",
              "path_prefix": "forward-26",
              "transport": "tcp+udp",
              "endpoints": [
                { "endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "route_key": "forward-26", "ingress_auth_token": "token-a", "ingress_protocol": "plain_tcp_mux", "dynamic_priority": 9 }
              ],
              "bonding": {
                "remote_target": "138.252.162.120:9000",
                "session_token": "tcp-session",
                "udp_session_token": "udp-session",
                "ingress_mode": "plain_tcp_mux",
                "route_key": "forward-26"
              }
            },
            {
              "forward_id": 10001,
              "service_status": "active",
              "mode": "single",
              "listen_port": 11001,
              "target": "193.227.109.82:5201",
              "sni": "v3.leikwanhost.com",
              "path_prefix": "route-rule-helper-10001",
              "transport": "tcp+udp",
              "route_rule_helper": true,
              "selectable": false,
              "endpoints": [
                { "endpoint_id": 501, "host": "127.0.0.1", "port": 443 }
              ]
            }
          ],
          "route_directions": [
            { "id": "dir_sh_jp_1", "label": "沪日1", "status": "available", "is_available": true, "sort_order": 10 }
          ],
          "route_rule_entitlements": [
            { "service_scope_id": "svc_scope_1001", "service_id": 1001, "subscription_id": 501, "max_rules": 5, "remaining_rules": 3, "allowed_direction_ids": ["dir_sh_jp_1"] }
          ],
          "client_rules": [
            { "id": "rule_1", "service_scope_id": "svc_scope_1001", "service_id": 1001, "subscription_id": 501, "destination_ip": "93.184.216.34", "destination_port": 443, "protocol": "tcp_udp", "direction_id": "dir_sh_jp_1", "enabled": true, "effective_state": "active", "sync_version": 17 }
          ],
          "direction_runtime_bindings": [
            { "service_scope_id": "svc_scope_1001", "direction_id": "dir_sh_jp_1", "binding_state": "ready", "client_runtime_binding_id": "crb_svc_scope_1001_dir_sh_jp_1", "runtime_ref": { "type": "forward_ref", "forward_id": 10001, "selectable": false, "transport": "tcp_udp" }, "local_endpoint": { "host": "127.0.0.1", "tcp_port": 11001, "udp_port": 11001 }, "tcp_ready": true, "udp_ready": true }
          ],
          "capabilities": { "route_direction_rules": true, "exact_destination_rules": true }
        }
        """;
    }
```

Append this test dispatcher inside `ConfigPollingServiceTests`, after `AlwaysRunningProcessSupervisor` and before `PullApiServer`:

```csharp
    private sealed class RecordingRouteRuleDispatcher : WindowsRouteRuleDispatcher
    {
        public RouteRuleSnapshot? LastSnapshot { get; private set; }

        public override WindowsRouteRulePlan BuildPlan(RouteRuleSnapshot snapshot)
        {
            LastSnapshot = snapshot;
            return base.BuildPlan(snapshot);
        }
    }
```

- [ ] **Step 2: Run config polling tests to verify they fail**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter FullyQualifiedName~ConfigPollingServiceTests -v normal
```

Expected: FAIL with compile errors for missing `ConfigPollingService.RouteRuleSnapshotUpdated`, the four-argument constructor, `LatestRouteRulePlan`, and current behavior publishing helper forwards to UI.

- [ ] **Step 3: Replace the `ConfigPollingService` fields, events, properties, and constructor block**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\ConfigPollingService.cs`, replace lines 13-32 with this full final block:

```csharp
    private readonly MasterApiClient _api;
    private readonly ForwardRuntimeManager _runtimeManager;
    private readonly StateManager _stateManager;
    private readonly WindowsRouteRuleDispatcher _routeRuleDispatcher;

    private List<ForwardConfig> _latestForwards = [];
    private RouteRuleSnapshot _latestRouteRuleSnapshot = RouteRuleSnapshot.Empty;
    private WindowsRouteRulePlan _latestRouteRulePlan = WindowsRouteRulePlan.Empty;
    private CancellationTokenSource? _cts;
    private Task? _pollingTask;

    public event Action<List<ForwardConfig>>? ForwardsUpdated;
    public event Action<RouteRuleSnapshot>? RouteRuleSnapshotUpdated;
    public event Action<PullState>? PullStateChanged;
    public event Action<Exception>? SyncError;

    public List<ForwardConfig> LatestForwards => _latestForwards;
    public RouteRuleSnapshot LatestRouteRuleSnapshot => _latestRouteRuleSnapshot;
    public WindowsRouteRulePlan LatestRouteRulePlan => _latestRouteRulePlan;

    public ConfigPollingService(
        MasterApiClient api,
        ForwardRuntimeManager runtimeManager,
        StateManager stateManager,
        WindowsRouteRuleDispatcher? routeRuleDispatcher = null)
    {
        _api = api;
        _runtimeManager = runtimeManager;
        _stateManager = stateManager;
        _routeRuleDispatcher = routeRuleDispatcher ?? new WindowsRouteRuleDispatcher();
    }
```

- [ ] **Step 4: Replace `SyncOnceAsync` with the full route-rule-aware method**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\ConfigPollingService.cs`, replace the full existing `public async Task SyncOnceAsync(CancellationToken ct = default)` method with this full final method:

```csharp
    public async Task SyncOnceAsync(CancellationToken ct = default)
    {
        var pull = await _api.PullConfigsAsync(ct);
        if (!pull.Ok)
        {
            Log.Warning("pull response ok=false");
            return;
        }

        _runtimeManager.ApplyAuthoritativeAgentConfig(pull.Client?.AgentConfigJson);

        var runtimeForwards = WindowsForwardPolicy.FilterSupportedForwards(pull.Forwards)
            .Where(f => f.ServiceStatus == "active")
            .ToList();
        var userSelectableForwards = WindowsForwardPolicy.FilterUserSelectableForwards(runtimeForwards)
            .Where(f => f.ServiceStatus == "active")
            .ToList();
        _latestForwards = userSelectableForwards;

        _latestRouteRuleSnapshot = RouteRuleSnapshot.FromPullResponse(
            pull,
            WindowsRouteRuleCapabilities.BuildAdvertisedCapabilities(_routeRuleDispatcher));
        _latestRouteRulePlan = _routeRuleDispatcher.BuildPlan(_latestRouteRuleSnapshot);

        var pullState = new PullState
        {
            Status = "ok",
            LastAttemptAt = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0,
            LastSuccessAt = DateTimeOffset.UtcNow.ToUnixTimeMilliseconds() / 1000.0,
            AgentVersion = MasterApiClient.AgentVersion,
            ForwardsTotal = userSelectableForwards.Count,
            AppliedForwardIds = runtimeForwards.Select(f => f.ForwardId).ToList(),
        };
        _stateManager.SavePullState(pullState);
        PullStateChanged?.Invoke(pullState);
        RouteRuleSnapshotUpdated?.Invoke(_latestRouteRuleSnapshot);

        var applied = _stateManager.LoadApplied();
        var activeIds = new HashSet<string>(runtimeForwards.Select(f => f.ForwardId.ToString()));
        var appliedIds = new HashSet<string>(applied.Keys);

        foreach (var id in appliedIds.Except(activeIds))
        {
            if (int.TryParse(id, out var fid))
            {
                try
                {
                    _runtimeManager.StopForward(fid);
                }
                catch (Exception ex)
                {
                    Log.Error(ex, "failed stopping removed forward {ForwardId}", fid);
                }
            }
        }

        foreach (var forward in runtimeForwards)
        {
            var key = forward.ForwardId.ToString();
            var hasAppliedState = applied.TryGetValue(key, out var appliedState);
            if (hasAppliedState && appliedState!.UserStopped && WindowsForwardPolicy.IsUserSelectableForward(forward))
                continue;

            var effectiveForward = EffectiveForwardConfigBuilder.Build(forward);
            var contractSignature = ForwardContractSignature.Compute(effectiveForward);
            var isRunning = _runtimeManager.IsForwardRunning(forward.ForwardId);
            var signatureMatches = hasAppliedState
                && string.Equals(appliedState!.ContractSignature, contractSignature, StringComparison.Ordinal);

            if (hasAppliedState && isRunning && signatureMatches)
                continue;

            try
            {
                _runtimeManager.ApplyForward(effectiveForward, contractSignature, "config_sync");
            }
            catch (Exception ex)
            {
                Log.Error(ex, "failed applying forward {ForwardId}", forward.ForId);
            }
        }

        ForwardsUpdated?.Invoke(userSelectableForwards);
    }
```

- [ ] **Step 5: Run config polling tests to verify they pass**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter FullyQualifiedName~ConfigPollingServiceTests -v normal
```

Expected: PASS. Existing tests still pass because payloads without route-rule fields produce `RouteRuleSnapshot.Empty`-equivalent unsupported snapshots. New tests pass because helper forwards are applied for runtime preparation but omitted from `ForwardsUpdated` and `LatestForwards`.

- [ ] **Step 6: Commit config polling work**

Run:

```powershell
git add "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\ConfigPollingService.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\ConfigPollingServiceTests.cs"
git commit -m "feat(windows): publish route rule snapshots from config sync" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds.

### Task 5: No-advertise heartbeat capability gate

**Files:**
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\HeartbeatPayload.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\HeartbeatService.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\HeartbeatServiceTests.cs`

- [ ] **Step 1: Write failing no-advertise heartbeat test**

Append this test inside `HeartbeatServiceTests` in `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\HeartbeatServiceTests.cs`, after `SendHeartbeatAsync_ReportsPortableWindowsMptcpSupport`:

```csharp
    [Fact]
    public async Task SendHeartbeatAsync_DoesNotAdvertiseRouteRuleSupportUntilExactDestinationBackendIsVerified()
    {
        using var tempDir = new TempStateDirectory();
        using var server = new HeartbeatCaptureServer();
        using var api = new MasterApiClient(server.BaseUrl, "agent-token");
        var service = new HeartbeatService(api, new StateManager(tempDir.Path));

        await service.SendHeartbeatAsync();

        using var document = JsonDocument.Parse(server.LastRequestBody);
        Assert.False(document.RootElement.GetProperty("supports_route_direction_rules").GetBoolean());
        Assert.False(document.RootElement.GetProperty("supports_exact_destination_rules").GetBoolean());
    }
```

- [ ] **Step 2: Run heartbeat tests to verify they fail**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter FullyQualifiedName~HeartbeatServiceTests -v normal
```

Expected: FAIL with `KeyNotFoundException` or `InvalidOperationException` when reading missing `supports_route_direction_rules` and `supports_exact_destination_rules` JSON properties.

- [ ] **Step 3: Replace `HeartbeatPayload.cs` with route-rule capability fields**

Replace the full contents of `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\HeartbeatPayload.cs` with:

```csharp
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class HeartbeatPayload
{
    [JsonPropertyName("agent_version")]
    public string AgentVersion { get; set; } = "";

    [JsonPropertyName("usage_bytes_in")]
    public long UsageBytesIn { get; set; }

    [JsonPropertyName("usage_bytes_out")]
    public long UsageBytesOut { get; set; }

    [JsonPropertyName("supports_mptcp")]
    public bool SupportsMptcp { get; set; }

    [JsonPropertyName("supports_route_direction_rules")]
    public bool SupportsRouteDirectionRules { get; set; }

    [JsonPropertyName("supports_exact_destination_rules")]
    public bool SupportsExactDestinationRules { get; set; }

    [JsonPropertyName("wireguard_public_key")]
    public string WireguardPublicKey { get; set; } = "";

    [JsonPropertyName("wireguard_listen_port")]
    public int WireguardListenPort { get; set; }

    [JsonPropertyName("wireguard_fallback_tcp_port")]
    public int WireguardFallbackTcpPort { get; set; }
}
```

- [ ] **Step 4: Replace the payload construction block in `HeartbeatService.SendHeartbeatAsync`**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\HeartbeatService.cs`, replace lines 37-46 with this full final payload block:

```csharp
        var advertisedRouteRuleCapabilities = WindowsRouteRuleCapabilities.BuildNoAdvertiseCapabilities();
        var payload = new HeartbeatPayload
        {
            AgentVersion = MasterApiClient.AgentVersion,
            UsageBytesIn = Math.Max(0, bytesIn),
            UsageBytesOut = Math.Max(0, bytesOut),
            SupportsMptcp = true,
            SupportsRouteDirectionRules = advertisedRouteRuleCapabilities.RouteDirectionRules,
            SupportsExactDestinationRules = advertisedRouteRuleCapabilities.ExactDestinationRules,
            WireguardPublicKey = _wireguardPublicKey,
            WireguardListenPort = _wireguardListenPort,
            WireguardFallbackTcpPort = _wireguardFallbackTcpPort,
        };
```

- [ ] **Step 5: Run heartbeat tests to verify they pass**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter FullyQualifiedName~HeartbeatServiceTests -v normal
```

Expected: PASS. The captured heartbeat JSON includes `supports_route_direction_rules:false` and `supports_exact_destination_rules:false`, while `supports_mptcp:true` remains unchanged.

- [ ] **Step 6: Commit heartbeat gate work**

Run:

```powershell
git add "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Models\HeartbeatPayload.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.Core\Services\HeartbeatService.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\Runtime\HeartbeatServiceTests.cs"
git commit -m "feat(windows): keep route rule capabilities unadvertised" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds.

### Task 6: Route-rule view models and capability gate

**Files:**
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\ViewModels\RouteRuleViewModel.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\ViewModels\MainViewModel.cs`
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\RouteRuleViewModelTests.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\MainViewModelTests.cs`

- [ ] **Step 1: Write failing route-rule view model tests**

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\RouteRuleViewModelTests.cs`:

```csharp
using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.App.Tests.ViewModels;

public sealed class RouteRuleViewModelTests
{
    [Fact]
    public void FromRule_UsesProductFieldsOnly()
    {
        var rule = new ClientRouteRule
        {
            Id = "rule_1",
            ServiceScopeId = "svc_scope_1001",
            ServiceId = 1001,
            SubscriptionId = 501,
            DestinationIp = "93.184.216.34",
            DestinationPort = 443,
            DirectionId = "dir_sh_jp_1",
            Enabled = true,
            EffectiveState = "active",
            SyncVersion = 17,
        };
        var direction = new RouteDirection
        {
            Id = "dir_sh_jp_1",
            Label = "沪日1",
            Status = "available",
            IsAvailable = true,
        };
        var entitlement = new RouteRuleEntitlement
        {
            ServiceScopeId = "svc_scope_1001",
            MaxRules = 5,
            RemainingRules = 3,
        };

        var vm = RouteRuleViewModel.FromRule(rule, direction, entitlement);

        Assert.Equal("rule_1", vm.RuleId);
        Assert.Equal("93.184.216.34:443", vm.DestinationText);
        Assert.Equal("沪日1", vm.DirectionLabel);
        Assert.Equal("Enabled", vm.EnabledText);
        Assert.Equal("Active", vm.StatusText);
        Assert.Equal("3 of 5 remaining", vm.QuotaText);
        Assert.Equal("svc_scope_1001", vm.ServiceScopeId);
        Assert.Equal(17, vm.SyncVersion);
        Assert.DoesNotContain("forward", vm.SearchableProductText, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("listener", vm.SearchableProductText, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("protocol", vm.SearchableProductText, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void FromRule_MapsUnavailableProductStatesToPlainStatusText()
    {
        var rule = new ClientRouteRule
        {
            Id = "rule_2",
            ServiceScopeId = "svc_scope_1001",
            DestinationIp = "8.8.8.8",
            DestinationPort = 443,
            DirectionId = "dir_sh_hk_1",
            Enabled = true,
            EffectiveState = "not_allowed_by_plan",
            SyncVersion = 4,
        };

        var vm = RouteRuleViewModel.FromRule(rule, direction: null, entitlement: null);

        Assert.Equal("8.8.8.8:443", vm.DestinationText);
        Assert.Equal("Route Direction unavailable", vm.DirectionLabel);
        Assert.Equal("Not allowed by plan", vm.StatusText);
        Assert.True(vm.IsUnavailable);
        Assert.Equal("No quota information", vm.QuotaText);
    }
}
```

Append these tests inside `MainViewModelTests` in `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\MainViewModelTests.cs`, before the helper methods:

```csharp
    [Fact]
    public void ApplyRouteRuleSnapshotForTests_HidesRouteRuleUi_WhenLocalExactDestinationCapabilityIsFalse()
    {
        var vm = new MainViewModel();
        var snapshot = CreateRouteRuleSnapshot(localExactDestinationRules: false);

        vm.ApplyRouteRuleSnapshotForTests(snapshot);

        Assert.False(vm.IsRouteRuleUiAvailable);
        Assert.False(vm.CanShowRouteRuleNav);
        Assert.Empty(vm.RouteRules);
        Assert.Equal("Route rules are not available on this Windows client.", vm.RouteRuleStatusMessage);
    }

    [Fact]
    public void ApplyRouteRuleSnapshotForTests_ShowsProductRows_WhenServerAndLocalCapabilitiesAllowUi()
    {
        var vm = new MainViewModel();
        var snapshot = CreateRouteRuleSnapshot(localExactDestinationRules: true);

        vm.ApplyRouteRuleSnapshotForTests(snapshot);

        Assert.True(vm.IsRouteRuleUiAvailable);
        Assert.True(vm.CanShowRouteRuleNav);
        var row = Assert.Single(vm.RouteRules);
        Assert.Equal("93.184.216.34:443", row.DestinationText);
        Assert.Equal("沪日1", row.DirectionLabel);
        Assert.Equal("Active", row.StatusText);
        Assert.Equal("3 of 5 remaining", row.QuotaText);
    }

    private static RouteRuleSnapshot CreateRouteRuleSnapshot(bool localExactDestinationRules)
    {
        var pull = new PullResponse
        {
            RouteDirections =
            [
                new RouteDirection
                {
                    Id = "dir_sh_jp_1",
                    Label = "沪日1",
                    Status = "available",
                    IsAvailable = true,
                    SortOrder = 10,
                },
            ],
            RouteRuleEntitlements =
            [
                new RouteRuleEntitlement
                {
                    ServiceScopeId = "svc_scope_1001",
                    ServiceId = 1001,
                    SubscriptionId = 501,
                    MaxRules = 5,
                    RemainingRules = 3,
                    AllowedDirectionIds = ["dir_sh_jp_1"],
                },
            ],
            ClientRules =
            [
                new ClientRouteRule
                {
                    Id = "rule_1",
                    ServiceScopeId = "svc_scope_1001",
                    ServiceId = 1001,
                    SubscriptionId = 501,
                    DestinationIp = "93.184.216.34",
                    DestinationPort = 443,
                    DirectionId = "dir_sh_jp_1",
                    Enabled = true,
                    EffectiveState = "active",
                    SyncVersion = 17,
                },
            ],
            Capabilities = new RouteRuleCapabilities
            {
                RouteDirectionRules = true,
                ExactDestinationRules = true,
            },
        };

        return RouteRuleSnapshot.FromPullResponse(
            pull,
            new RouteRuleCapabilities
            {
                RouteDirectionRules = localExactDestinationRules,
                ExactDestinationRules = localExactDestinationRules,
            });
    }
```

- [ ] **Step 2: Run app route-rule view model tests to verify they fail**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj" --filter "RouteRuleViewModel|ApplyRouteRuleSnapshot" -v normal
```

Expected: FAIL with compile errors for missing `RouteRuleViewModel`, `MainViewModel.RouteRules`, `IsRouteRuleUiAvailable`, `CanShowRouteRuleNav`, `RouteRuleStatusMessage`, and `ApplyRouteRuleSnapshotForTests`.

- [ ] **Step 3: Create `RouteRuleViewModel.cs`**

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\ViewModels\RouteRuleViewModel.cs`:

```csharp
using CommunityToolkit.Mvvm.ComponentModel;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.App.ViewModels;

public partial class RouteRuleViewModel : ObservableObject
{
    [ObservableProperty] private string _ruleId = "";
    [ObservableProperty] private string _serviceScopeId = "";
    [ObservableProperty] private string _destinationText = "";
    [ObservableProperty] private string _directionLabel = "";
    [ObservableProperty] private string _enabledText = "";
    [ObservableProperty] private string _statusText = "";
    [ObservableProperty] private string _quotaText = "";
    [ObservableProperty] private bool _isUnavailable;
    [ObservableProperty] private int _syncVersion;

    public string SearchableProductText => string.Join(" ",
        RuleId,
        ServiceScopeId,
        DestinationText,
        DirectionLabel,
        EnabledText,
        StatusText,
        QuotaText,
        SyncVersion.ToString());

    public static RouteRuleViewModel FromRule(
        ClientRouteRule rule,
        RouteDirection? direction,
        RouteRuleEntitlement? entitlement)
    {
        var statusText = ToProductStatusText(rule.Enabled, rule.EffectiveState);
        return new RouteRuleViewModel
        {
            RuleId = rule.Id,
            ServiceScopeId = rule.ServiceScopeId,
            DestinationText = $"{rule.DestinationIp}:{rule.DestinationPort}",
            DirectionLabel = string.IsNullOrWhiteSpace(direction?.Label)
                ? "Route Direction unavailable"
                : direction!.Label,
            EnabledText = rule.Enabled ? "Enabled" : "Disabled",
            StatusText = statusText,
            QuotaText = FormatQuota(entitlement),
            IsUnavailable = !string.Equals(statusText, "Active", StringComparison.Ordinal),
            SyncVersion = rule.SyncVersion,
        };
    }

    private static string ToProductStatusText(bool enabled, string effectiveState)
    {
        if (!enabled)
            return "Disabled";

        return (effectiveState ?? "").Trim().ToLowerInvariant() switch
        {
            "active" => "Active",
            "disabled" => "Disabled",
            "over_quota" => "Over quota",
            "not_allowed_by_plan" => "Not allowed by plan",
            "unavailable" => "Direction unavailable",
            "unsupported" => "Unavailable on this device",
            "invalid" => "Invalid destination",
            "conflict" => "Sync conflict",
            "runtime_missing" => "Direction unavailable",
            _ => "Direction unavailable",
        };
    }

    private static string FormatQuota(RouteRuleEntitlement? entitlement)
    {
        if (entitlement is null)
            return "No quota information";

        return $"{entitlement.RemainingRules} of {entitlement.MaxRules} remaining";
    }
}
```

- [ ] **Step 4: Replace the `MainViewModel` field and property block**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\ViewModels\MainViewModel.cs`, replace lines 16-52 with this full final block:

```csharp
    private AgentConfig _config;
    private MasterApiClient? _api;
    private StateManager? _stateManager;
    private ProcessSupervisor? _supervisor;
    private ForwardRuntimeManager? _runtimeManager;
    private ConfigPollingService? _pollingService;
    private HeartbeatService? _heartbeatService;
    private HealthCheckService? _healthCheckService;
    private WireGuardManager? _wgManager;
    private InstalledRuntimeCleanupService? _installedRuntimeCleanup;
    private WindowsClientAppArtifact? _availableUpdateArtifact;

    [ObservableProperty] private bool _isConnected;
    [ObservableProperty] private string _connectionStatus = "Disconnected";
    [ObservableProperty] private string _lastSyncTime = "Never";
    [ObservableProperty] private int _activeForwardCount;
    [ObservableProperty] private string _totalBytesIn = "0 B";
    [ObservableProperty] private string _totalBytesOut = "0 B";
    [ObservableProperty] private string _currentView = "Dashboard";
    [ObservableProperty] private bool _showSetupWizard = true;
    [ObservableProperty] private string _activeModeFilter = "LeiGod";
    [ObservableProperty] private bool _showClientPicker;
    [ObservableProperty] private int _pendingClientSelectionId;
    [ObservableProperty] private string _signedInUsername = "";
    [ObservableProperty] private string _signedInEmail = "";
    [ObservableProperty] private string _activeClientName = "";
    [ObservableProperty] private bool _showUpdatePrompt;
    [ObservableProperty] private string _availableUpdateVersion = "";
    [ObservableProperty] private ForwardViewModel? _selectedForward;
    [ObservableProperty] private bool _isRouteRuleUiAvailable;
    [ObservableProperty] private string _routeRuleStatusMessage = "Route rules are not available on this Windows client.";

    public ObservableCollection<ForwardViewModel> Forwards { get; } = [];
    public ObservableCollection<ForwardViewModel> VisibleForwards { get; } = [];
    public ObservableCollection<RouteRuleViewModel> RouteRules { get; } = [];
    public ObservableCollection<ConnectionQualityForwardViewModel> ConnectionQualityForwards { get; } = [];
    public ObservableCollection<string> AvailableModeFilters { get; } = ["LeiGod"];
    public ObservableCollection<DesktopClientChoice> AvailableClientChoices { get; } = [];
    public ObservableCollection<string> LogEntries { get; } = [];
    public bool ShouldAutoConnectOnStartup => !ShowSetupWizard && !IsConnected;
    public bool CanShowRouteRuleNav => IsRouteRuleUiAvailable;
```

- [ ] **Step 5: Replace `SignOutForTests` with the full final method**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\ViewModels\MainViewModel.cs`, replace the full existing `SignOutForTests` method with:

```csharp
    public void SignOutForTests()
    {
        _config.DesktopSessionToken = string.Empty;
        _config.SignedInUserId = 0;
        _config.SignedInUsername = string.Empty;
        _config.SignedInEmail = string.Empty;
        _config.AvailableClients = [];
        _config.ActiveClientId = 0;
        _config.ActiveClientName = string.Empty;
        _config.ClientId = 0;
        _config.AgentToken = string.Empty;
        AvailableClientChoices.Clear();
        SignedInUsername = string.Empty;
        SignedInEmail = string.Empty;
        ActiveClientName = string.Empty;
        SelectedForward = null;
        Forwards.Clear();
        VisibleForwards.Clear();
        RouteRules.Clear();
        IsRouteRuleUiAvailable = false;
        RouteRuleStatusMessage = "Route rules are not available on this Windows client.";
        ShowClientPicker = false;
        ShowSetupWizard = true;
        OnPropertyChanged(nameof(CanShowRouteRuleNav));
    }
```

- [ ] **Step 6: Replace `ConnectAsync` with the full final method including snapshot subscription**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\ViewModels\MainViewModel.cs`, replace the full existing `ConnectAsync` method with:

```csharp
    [RelayCommand]
    private async Task ConnectAsync(CancellationToken ct)
    {
        if (string.IsNullOrWhiteSpace(_config.MasterUrl) || string.IsNullOrWhiteSpace(_config.AgentToken))
            return;

        _api?.Dispose();
        _api = new MasterApiClient(
            _config.MasterUrl,
            _config.AgentToken,
            MasterApiClient.BuildWindowsDeviceId(_config.ActiveClientId > 0 ? _config.ActiveClientId : _config.ClientId, Environment.MachineName),
            $"Leikwan Bridge on {Environment.MachineName}",
            "windows-desktop");

        var ok = await _api.TestConnectionAsync(ct);
        if (!ok)
        {
            ConnectionStatus = string.IsNullOrWhiteSpace(_api.LastErrorMessage)
                ? GetString("DashDisconnected")
                : _api.LastErrorMessage;
            AddLog($"Connection failed: {ConnectionStatus}");
            IsConnected = false;
            return;
        }

        IsConnected = true;
        ConnectionStatus = GetString("DashConnected");
        ShowSetupWizard = false;

        _stateManager = new StateManager(_config.GetEffectiveStateDir());
        _supervisor = new ProcessSupervisor(OnProcessOutput);
        _runtimeManager = new ForwardRuntimeManager(_config, _supervisor, _stateManager);
        _pollingService = new ConfigPollingService(_api, _runtimeManager, _stateManager);
        _healthCheckService = new HealthCheckService(_supervisor, _runtimeManager, _stateManager, config: _config);
        _installedRuntimeCleanup = new InstalledRuntimeCleanupService();

        _wgManager = new WireGuardManager(_config.GetEffectiveWgBin(), _config.GetEffectiveStateDir());
        _wgManager.EnsureIdentity();

        _heartbeatService = new HeartbeatService(
            _api, _stateManager,
            _wgManager.PublicKey,
            _config.GetEffectiveWireguardListenPort(),
            _config.GetEffectiveWireguardFallbackTcpPort());

        _pollingService.ForwardsUpdated += OnForwardsUpdated;
        _pollingService.RouteRuleSnapshotUpdated += OnRouteRuleSnapshotUpdated;
        _pollingService.PullStateChanged += OnPullStateChanged;
        _pollingService.SyncError += OnSyncError;
        _healthCheckService.HealthIssue += OnHealthIssue;

        var installRoot = AppDefaults.GetInstallRoot();
        var cleaned = _installedRuntimeCleanup.KillProcessesUnderInstallRoot(installRoot, Environment.ProcessId);
        if (cleaned > 0)
            AddLog($"Stopped {cleaned} stale installed runtime process(es).");

        _pollingService.Start(_config.PollIntervalSeconds);

        AddLog("Connected to master panel.");
    }
```

- [ ] **Step 7: Add route-rule snapshot methods and property-change hook**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\ViewModels\MainViewModel.cs`, insert this full block after `ApplyForwardSnapshot` and before `BuildConnectionQuality`:

```csharp
    public void ApplyRouteRuleSnapshotForTests(RouteRuleSnapshot snapshot)
    {
        ApplyRouteRuleSnapshot(snapshot);
    }

    private void OnRouteRuleSnapshotUpdated(RouteRuleSnapshot snapshot)
    {
        InvokeOnUiThread(() => ApplyRouteRuleSnapshot(snapshot));
    }

    private void ApplyRouteRuleSnapshot(RouteRuleSnapshot snapshot)
    {
        RouteRules.Clear();

        if (!snapshot.IsUiEnabled)
        {
            IsRouteRuleUiAvailable = false;
            RouteRuleStatusMessage = "Route rules are not available on this Windows client.";
            OnPropertyChanged(nameof(CanShowRouteRuleNav));
            return;
        }

        foreach (var rule in snapshot.ClientRules)
        {
            var direction = snapshot.FindDirection(rule.DirectionId);
            var entitlement = snapshot.Entitlements.FirstOrDefault(item =>
                string.Equals(item.ServiceScopeId, rule.ServiceScopeId, StringComparison.Ordinal));
            RouteRules.Add(RouteRuleViewModel.FromRule(rule, direction, entitlement));
        }

        IsRouteRuleUiAvailable = true;
        RouteRuleStatusMessage = RouteRules.Count == 0
            ? "No Client Rules saved."
            : $"{RouteRules.Count} Client Rule{(RouteRules.Count == 1 ? "" : "s")} synced.";
        OnPropertyChanged(nameof(CanShowRouteRuleNav));
    }
```

Insert this full block after `OnActiveModeFilterChanged` and before `RebuildAvailableModeFilters`:

```csharp
    partial void OnIsRouteRuleUiAvailableChanged(bool value)
    {
        OnPropertyChanged(nameof(CanShowRouteRuleNav));
    }
```

- [ ] **Step 8: Run app route-rule view model tests to verify they pass**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj" --filter "RouteRuleViewModel|ApplyRouteRuleSnapshot" -v normal
```

Expected: PASS. The route-rule row model contains product text only, and `MainViewModel` hides route-rule UI unless server and local exact-destination capabilities are both true.

- [ ] **Step 9: Commit route-rule view model gate work**

Run:

```powershell
git add "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\ViewModels\RouteRuleViewModel.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\ViewModels\MainViewModel.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\RouteRuleViewModelTests.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\MainViewModelTests.cs"
git commit -m "feat(windows): add gated route rule view models" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds.

### Task 7: Product-only WPF Route Rules UI with source sweep

**Files:**
- Create: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\RouteRuleProductLanguageGuardTests.cs`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\Views\MainWindow.xaml`
- Modify: `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\Views\MainWindow.xaml.cs`

- [ ] **Step 1: Write failing product-language source sweep before changing WPF behavior**

Create `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\RouteRuleProductLanguageGuardTests.cs`:

```csharp
using Xunit;

namespace LeikwanBridge.App.Tests.ViewModels;

public sealed class RouteRuleProductLanguageGuardTests
{
    private static readonly string[] ForbiddenTerms =
    [
        "LeiGod",
        "current_mode",
        "cf_mode",
        "chain",
        "combination",
        "CF Location Group",
        "sidecar",
        "slave",
        "internal line",
        "listener",
        "listen port",
        "Forward #",
        "forward_id",
        "protocol",
        "transport",
    ];

    [Fact]
    public void RouteRulesPanel_UsesProductTermsOnly()
    {
        var desktopRoot = FindDesktopRoot();
        var xamlPath = Path.Combine(desktopRoot, "src", "LeikwanBridge.App", "Views", "MainWindow.xaml");
        var xaml = File.ReadAllText(xamlPath);
        var section = ExtractSection(xaml, "<!-- Route Rules View -->", "<!-- Log View -->");

        Assert.Contains("Client Rules", section, StringComparison.Ordinal);
        Assert.Contains("Route Direction", section, StringComparison.Ordinal);
        Assert.Contains("Destination", section, StringComparison.Ordinal);
        Assert.Contains("Status", section, StringComparison.Ordinal);
        foreach (var term in ForbiddenTerms)
            Assert.DoesNotContain(term, section, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void RouteRuleViewModel_SourceUsesProductTermsOnly()
    {
        var desktopRoot = FindDesktopRoot();
        var sourcePath = Path.Combine(desktopRoot, "src", "LeikwanBridge.App", "ViewModels", "RouteRuleViewModel.cs");
        var source = File.ReadAllText(sourcePath);

        Assert.Contains("Route Direction unavailable", source, StringComparison.Ordinal);
        Assert.Contains("ClientRouteRule", source, StringComparison.Ordinal);
        foreach (var term in ForbiddenTerms)
            Assert.DoesNotContain(term, source, StringComparison.OrdinalIgnoreCase);
    }

    private static string ExtractSection(string content, string startMarker, string endMarker)
    {
        var start = content.IndexOf(startMarker, StringComparison.Ordinal);
        Assert.True(start >= 0, $"Missing marker: {startMarker}");
        var end = content.IndexOf(endMarker, start, StringComparison.Ordinal);
        Assert.True(end > start, $"Missing marker after {startMarker}: {endMarker}");
        return content[start..end];
    }

    private static string FindDesktopRoot()
    {
        var current = new DirectoryInfo(AppContext.BaseDirectory);
        while (current != null)
        {
            var candidate = Path.Combine(current.FullName, "src", "LeikwanBridge.App", "Views", "MainWindow.xaml");
            if (File.Exists(candidate))
                return current.FullName;
            current = current.Parent;
        }

        throw new DirectoryNotFoundException("Could not locate LeikwanBridge.Desktop root from test output directory.");
    }
}
```

- [ ] **Step 2: Run the product-language guard to verify it fails before WPF changes**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj" --filter FullyQualifiedName~RouteRuleProductLanguageGuardTests -v normal
```

Expected: FAIL with assertion message `Missing marker: <!-- Route Rules View -->` because the WPF route-rules panel has not been added yet. If it fails because `RouteRuleViewModel_SourceUsesProductTermsOnly` finds a forbidden term introduced in Task 6, stop and change `RouteRuleViewModel.cs` so it uses only the tested product terms, then rerun this step.

- [ ] **Step 3: Add gated Route Rules nav button to `MainWindow.xaml`**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\Views\MainWindow.xaml`, replace lines 119-128 with this full final nav button block:

```xml
                    <!-- Nav Buttons -->
                    <StackPanel DockPanel.Dock="Top" Margin="8,0">
                        <Button Style="{StaticResource NavButton}" Content="{DynamicResource NavDashboard}"
                                Command="{Binding NavigateToCommand}" CommandParameter="Dashboard"/>
                        <Button Style="{StaticResource NavButton}" Content="{DynamicResource NavForwards}"
                                Command="{Binding NavigateToCommand}" CommandParameter="Forwards"/>
                        <Button Style="{StaticResource NavButton}" Content="Client Rules"
                                Visibility="{Binding CanShowRouteRuleNav, Converter={StaticResource BoolToVis}}"
                                Command="{Binding NavigateToCommand}" CommandParameter="RouteRules"/>
                        <Button Style="{StaticResource NavButton}" Content="{DynamicResource NavLog}"
                                Command="{Binding NavigateToCommand}" CommandParameter="Log"/>
                        <Button Style="{StaticResource NavButton}" Content="{DynamicResource NavSettings}"
                                Command="{Binding NavigateToCommand}" CommandParameter="Settings"/>
                    </StackPanel>
```

- [ ] **Step 4: Add the Route Rules panel before the Log View**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\Views\MainWindow.xaml`, insert this full block immediately before the existing `<!-- Log View -->` marker:

```xml
                <!-- Route Rules View -->
                <ScrollViewer x:Name="RouteRulesPanel" Visibility="Collapsed" VerticalScrollBarVisibility="Auto">
                    <StackPanel>
                        <Border Style="{StaticResource CardBorder}" Padding="20" Margin="0,0,0,12">
                            <StackPanel>
                                <TextBlock Style="{StaticResource SectionHeader}" Text="Client Rules" Margin="0,0,0,6"/>
                                <TextBlock Text="Route exact destinations to a Route Direction."
                                           Foreground="{StaticResource MutedTextBrush}"
                                           TextWrapping="Wrap"/>
                                <TextBlock Text="{Binding RouteRuleStatusMessage}"
                                           Foreground="{StaticResource MutedTextBrush}"
                                           Margin="0,12,0,0"/>
                            </StackPanel>
                        </Border>

                        <Border Style="{StaticResource CardBorder}">
                            <StackPanel>
                                <DockPanel Margin="0,0,0,12">
                                    <TextBlock Style="{StaticResource SectionHeader}" Text="Saved Client Rules" DockPanel.Dock="Left" Margin="0"/>
                                    <TextBlock Text="Route Direction rules are synced from the service."
                                               DockPanel.Dock="Right"
                                               VerticalAlignment="Center"
                                               Foreground="{StaticResource MutedTextBrush}"/>
                                </DockPanel>

                                <ItemsControl ItemsSource="{Binding RouteRules}">
                                    <ItemsControl.ItemTemplate>
                                        <DataTemplate>
                                            <Border Style="{StaticResource CardBorder}" Margin="0,0,0,10">
                                                <Grid>
                                                    <Grid.ColumnDefinitions>
                                                        <ColumnDefinition Width="2*"/>
                                                        <ColumnDefinition Width="2*"/>
                                                        <ColumnDefinition Width="*"/>
                                                        <ColumnDefinition Width="*"/>
                                                    </Grid.ColumnDefinitions>
                                                    <Grid.RowDefinitions>
                                                        <RowDefinition/>
                                                        <RowDefinition/>
                                                    </Grid.RowDefinitions>

                                                    <TextBlock Grid.Row="0" Grid.Column="0" Style="{StaticResource FieldLabel}" Text="Destination"/>
                                                    <TextBlock Grid.Row="1" Grid.Column="0" Text="{Binding DestinationText}"
                                                               FontSize="14" FontWeight="SemiBold" Foreground="{StaticResource TextBrush}"/>

                                                    <TextBlock Grid.Row="0" Grid.Column="1" Style="{StaticResource FieldLabel}" Text="Route Direction"/>
                                                    <TextBlock Grid.Row="1" Grid.Column="1" Text="{Binding DirectionLabel}"
                                                               FontSize="14" FontWeight="SemiBold" Foreground="{StaticResource TextBrush}"/>

                                                    <TextBlock Grid.Row="0" Grid.Column="2" Style="{StaticResource FieldLabel}" Text="Status"/>
                                                    <TextBlock Grid.Row="1" Grid.Column="2" Text="{Binding StatusText}"
                                                               FontSize="14" Foreground="{StaticResource TextBrush}"/>

                                                    <TextBlock Grid.Row="0" Grid.Column="3" Style="{StaticResource FieldLabel}" Text="Quota"/>
                                                    <TextBlock Grid.Row="1" Grid.Column="3" Text="{Binding QuotaText}"
                                                               FontSize="14" Foreground="{StaticResource TextBrush}"/>
                                                </Grid>
                                            </Border>
                                        </DataTemplate>
                                    </ItemsControl.ItemTemplate>
                                </ItemsControl>
                            </StackPanel>
                        </Border>
                    </StackPanel>
                </ScrollViewer>

```

- [ ] **Step 5: Replace `UpdateVisiblePanel` with route-rule panel handling**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\Views\MainWindow.xaml.cs`, replace the full existing `UpdateVisiblePanel` method with:

```csharp
    private void UpdateVisiblePanel()
    {
        DashboardPanel.Visibility = _vm.CurrentView == "Dashboard" ? Visibility.Visible : Visibility.Collapsed;
        RouteRulesPanel.Visibility = _vm.CurrentView == "RouteRules" && _vm.IsRouteRuleUiAvailable
            ? Visibility.Visible
            : Visibility.Collapsed;
        LogPanel.Visibility = _vm.CurrentView == "Log" ? Visibility.Visible : Visibility.Collapsed;
        SettingsPanel.Visibility = _vm.CurrentView == "Settings" ? Visibility.Visible : Visibility.Collapsed;

        if (_vm.CurrentView == "Forwards")
            DashboardPanel.Visibility = Visibility.Visible;
    }
```

- [ ] **Step 6: Replace `OnViewModelPropertyChanged` with route-rule visibility refresh**

In `C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\Views\MainWindow.xaml.cs`, replace the full existing `OnViewModelPropertyChanged` method with:

```csharp
    private void OnViewModelPropertyChanged(object? sender, PropertyChangedEventArgs e)
    {
        if (e.PropertyName == nameof(MainViewModel.CurrentView)
            || e.PropertyName == nameof(MainViewModel.IsRouteRuleUiAvailable)
            || e.PropertyName == nameof(MainViewModel.CanShowRouteRuleNav))
        {
            UpdateVisiblePanel();
        }
        if (e.PropertyName == nameof(MainViewModel.ActiveForwardCount)
            || e.PropertyName == nameof(MainViewModel.ConnectionStatus))
        {
            UpdateTrayText();
        }
        if (e.PropertyName == nameof(MainViewModel.ShowSetupWizard))
        {
            if (!_vm.ShowSetupWizard)
                PopulateSettings();
        }
    }
```

- [ ] **Step 7: Run product-language guard to verify it passes**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj" --filter FullyQualifiedName~RouteRuleProductLanguageGuardTests -v normal
```

Expected: PASS. The source sweep finds the Route Rules section and confirms it contains product terms while excluding the forbidden internal terms.

- [ ] **Step 8: Run app route-rule UI tests to verify they pass**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj" --filter "RouteRule|MainViewModel" -v normal
```

Expected: PASS. Route-rule view model, `MainViewModel`, and product-language guard tests pass together.

- [ ] **Step 9: Commit WPF route-rule UI work**

Run:

```powershell
git add "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\Views\MainWindow.xaml" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\src\LeikwanBridge.App\Views\MainWindow.xaml.cs" "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\ViewModels\RouteRuleProductLanguageGuardTests.cs"
git commit -m "feat(windows): add product-only route rule UI" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

Expected: commit succeeds.

### Task 8: Verify Windows Desktop route-rule implementation

**Files:**
- Verify only. Do not edit files in this task.

- [ ] **Step 1: Run focused core route-rule tests**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter "RouteRule|WindowsForwardPolicy|ConfigPollingService|HeartbeatService" -v normal
```

Expected: PASS. The output includes passing tests from `RouteRulePullModelTests`, `WindowsRouteRuleDispatcherTests`, `WindowsForwardPolicyTests`, `ConfigPollingServiceTests`, and `HeartbeatServiceTests`.

- [ ] **Step 2: Run focused app route-rule tests**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj" --filter "RouteRule|MainViewModel" -v normal
```

Expected: PASS. The output includes passing route-rule view model, `MainViewModel`, and product-language source sweep tests.

- [ ] **Step 3: Run full Windows Desktop solution tests**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\LeikwanBridge.Desktop.sln" -v normal
```

Expected: PASS for all projects in the solution.

- [ ] **Step 4: Confirm advertised route-rule capability remains false in source**

Run:

```powershell
dotnet test "C:\Users\frankniubi\Downloads\leikwan-br1\.claude\worktrees\leigod-only-activation-rollout\LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj" --filter FullyQualifiedName~SendHeartbeatAsync_DoesNotAdvertiseRouteRuleSupportUntilExactDestinationBackendIsVerified -v normal
```

Expected: PASS. This proves the Windows app does not advertise Route Direction Client Rule support until exact-destination enforcement is verified.

- [ ] **Step 5: Inspect git status without making a final verification commit**

Run:

```powershell
git status --short
```

Expected: no unexpected uncommitted source changes. If any verification command in this task fails or any unexpected source diff appears, stop execution and write a concrete follow-up task in the final response that names the failing command, the failing test or changed file, and the next exact file to inspect. Do not edit code, do not commit, and do not create a conditional final commit during this verification task.

## Self-Review Checklist

- Spec coverage:
  - Pull models for `route_directions`, `route_rule_entitlements`, `client_rules`, `direction_runtime_bindings`, and `capabilities`: Task 1.
  - `ConfigPollingService.SyncOnceAsync` route-rule snapshot handling with full final method: Task 4.
  - Helper/runtime forward filtering out of user-selectable UI while keeping runtime preparation: Tasks 2 and 4.
  - Product-only route-rule UI and capability gating: Tasks 6 and 7.
  - Conservative Windows exact-destination TCP+UDP dispatcher, fail-closed command generation, and state tests: Task 3.
  - No-advertise gate until exact-destination enforcement is safe: Tasks 3 and 5.
  - Tests under `LeikwanBridge.Desktop\tests`: all tasks.
- Placeholder scan:
  - The plan contains concrete file paths, commands, expected fail/pass states, and code blocks for new files and modified method/property blocks.
  - The plan does not ask the executor to invent validation, error handling, or missing code.
- Verification rule:
  - Task 8 is verify-only. If source fixes are required during verification, execution stops and reports a concrete follow-up task instead of editing or making a final conditional commit.
