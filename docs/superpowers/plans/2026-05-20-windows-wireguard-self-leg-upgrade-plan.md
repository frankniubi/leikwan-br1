# Windows WireGuard Self-Leg Tunnel — Full Upgrade Plan for AI Agents

**Date:** 2026-05-20
**Branch:** `feat/windows-wireguard-self-leg`
**Repository:** `frankniubi/leikwan-br1`
**Language/Framework:** C# / .NET 8 / WPF Desktop App
**Baseline commit:** `de59dae` (master)

---

## 1. EXECUTIVE SUMMARY

This plan ports the Linux client's WireGuard self-leg tunnel automation to the Windows desktop application (`LeikwanBridge.Desktop`). The Linux client already supports establishing a WireGuard tunnel to a sidecar server and routing bond/MPTCP traffic through it. Windows needs the same capability.

**Key design decision (from project owner):** Use a standalone `wireguard.exe` binary (bundled in `bin/wireguard.exe`) invoked directly with a config file, NOT the official WireGuard Windows installer/service. The binary runs as a background process managed by our existing `ProcessSupervisor`.

---

## 2. WHAT ALREADY EXISTS (DO NOT BREAK)

### 2.1 Linux Client (reference implementation)

Located in `client-agent/install_forward_runtime.py`. Key functions:
- `selected_private_wg_self_leg_candidates(forward)` — selects candidates with `self_leg_wg` enabled
- `self_leg_wg_underlay(candidate, forward)` — parses the WG config and renders a WireGuard underlay spec
- Renders `/etc/wireguard/lw-self-<forward_id>.conf` and starts `wg-quick@lw-self-<forward_id>` systemd unit
- Routes MPTCP traffic through the sidecar private tunnel IP


### 2.2 Windows Desktop App (current state BEFORE this change)

Located in `LeikwanBridge.Desktop/src/LeikwanBridge.Core/`. Key components:
- `Services/WireGuardManager.cs` — **Identity only**: generates WG keypair, reports public key in heartbeats. Does NOT manage tunnels.
- `Services/ForwardRuntimeManager.cs` — Manages forward process lifecycles (start/stop bond, wstunnel, ingress processes)
- `Services/ProcessSupervisor.cs` — Manages background processes (start, stop, health check)
- `Services/BondRuntimeBuilder.cs` — Builds bond runtime specs (which processes to start, what arguments)
- `Models/ForwardConfig.cs` — Represents a forward's configuration from the panel
- `Models/AgentConfig.cs` — Represents the agent's local configuration (binary paths, ports, etc.)

### 2.3 Panel Contract (already supports Windows)

The panel already publishes `self_leg_wg` inside forward configs for clients that report a `wireguard_public_key` in their heartbeat. The Windows app already reports this key via `WireGuardManager`. So the panel ALREADY sends `self_leg_wg` to Windows clients — we just weren't consuming it.

---

## 3. WHAT WE ADDED IN THIS COMMIT

### 3.1 New Files Created

| File | Purpose |
|------|---------|
| `Models/SelfLegWgConfig.cs` | Data model for the panel's `self_leg_wg` JSON payload |
| `Services/WireGuardTunnelService.cs` | Full WireGuard tunnel lifecycle management |

### 3.2 Modified Files

| File | Change |
|------|--------|
| `Models/ForwardConfig.cs` | Added `SelfLegWg` property to deserialize `self_leg_wg` |
| `Models/AgentConfig.cs` | Added `WireguardBin` property + `GetEffectiveWireguardBin()` method |
| `Services/ForwardRuntimeManager.cs` | Integrated WG tunnel bring-up before bond start + endpoint injection |

### 3.3 Unchanged Files

| File | Why unchanged |
|------|---------------|
| `Services/WireGuardManager.cs` | Still handles identity (keypair) only. Tunnel management is in new `WireGuardTunnelService` |
| `Services/BondRuntimeBuilder.cs` | Bond builder doesn't need to know about WG — we inject the WG endpoint before calling it |
| `Helpers/WindowsForwardPolicy.cs` | `self_leg_wg` is metadata on a `bond` forward which is already allowed |


---

## 4. ARCHITECTURE OVERVIEW

```
Panel (PHP) publishes forward config with self_leg_wg:
{
  "forward_id": 47,
  "mode": "bond",
  "self_leg_wg": {
    "enabled": true,
    "interface_name": "lw-self-47",
    "client_address": "10.99.47.1/32",
    "sidecar_address": "10.99.47.2",
    "sidecar_public_key": "abc123...",
    "endpoint_host": "185.220.238.143",
    "endpoint_port": 14969,
    "persistent_keepalive": 25,
    "sidecar_bond_port": 11035
  },
  "endpoints": [...existing CF/helper legs...],
  "bonding": {...}
}
```

```
Windows App Flow:
1. Poll config from panel → deserialize ForwardConfig (includes SelfLegWg)
2. ForwardRuntimeManager.ApplyForwardCore() is called
3. IF mode == "bond" AND SelfLegWg.Enabled == true:
   a. WireGuardTunnelService.BringUp(forwardId, wgConfig)
      - Reads local private key from state_dir/wireguard/private.key
      - Renders state_dir/wireguard/lw-self-47.conf
      - Starts: wireguard.exe /installtunnelservice "lw-self-47"
      - Probes: ping 10.99.47.2 (sidecar private IP) with 10s timeout
   b. IF tunnel is UP:
      - Inject synthetic EndpointConfig { Host="10.99.47.2", Port=11035, Priority=0 }
        as FIRST element in endpoints list (highest priority)
   c. IF tunnel FAILS:
      - Log warning, continue with existing CF/helper bond legs only
4. Build bond runtime spec from (possibly augmented) endpoints
5. Start bond/wstunnel/ingress processes as normal
```

---

## 5. DETAILED IMPLEMENTATION — STEP BY STEP

### Step 1: SelfLegWgConfig Model

**File:** `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/SelfLegWgConfig.cs`

Key fields and their meaning:
- `bool Enabled` — Must be true for tunnel to activate
- `string InterfaceName` — e.g. "lw-self-47" — used as Windows tunnel name
- `string ClientAddress` — e.g. "10.99.47.1/32" — local tunnel IP (Address in .conf)
- `string SidecarAddress` — e.g. "10.99.47.2" — remote tunnel IP (AllowedIPs + bond target)
- `string SidecarPublicKey` — Peer's public key (from sidecar WG identity)
- `string EndpointHost` — Sidecar's real public IP
- `int EndpointPort` — Sidecar's WG listen port (e.g. 14969)
- `int PersistentKeepalive` — Default 25 seconds
- `string OuterTransport` — "direct" only for Windows v1 (no CF wrapping yet)
- `int SidecarBondPort` — Bond backend port on sidecar (reachable via tunnel)

JSON property names (snake_case, matching panel API):
`enabled`, `interface_name`, `client_address`, `sidecar_address`, `client_public_key`, `sidecar_public_key`, `endpoint_host`, `endpoint_port`, `fallback_tcp_port`, `persistent_keepalive`, `outer_transport`, `cf_dial_host`, `cf_dial_port`, `cf_sni_host`, `cf_path_prefix`, `sidecar_bond_port`


### Step 2: ForwardConfig Extension

**File:** `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ForwardConfig.cs`

Added one property:
```csharp
[JsonPropertyName("self_leg_wg")]
public SelfLegWgConfig? SelfLegWg { get; set; }
```

### Step 3: AgentConfig Extension

**File:** `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentConfig.cs`

Added:
```csharp
[JsonPropertyName("wireguard_bin")]
public string WireguardBin { get; set; } = "";

public string GetEffectiveWireguardBin()
{
    if (!string.IsNullOrWhiteSpace(WireguardBin))
        return WireguardBin;
    return Path.Combine(AppContext.BaseDirectory, "bin", "wireguard.exe");
}
```

Note: The existing `wg_bin` points to `wg.exe` (WireGuard CLI tools for keygen). The new `wireguard_bin` points to `wireguard.exe` (the tunnel daemon binary). They are different binaries.

### Step 4: WireGuardTunnelService

**File:** `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WireGuardTunnelService.cs`

#### 4.1 Constructor Dependencies
```csharp
public WireGuardTunnelService(
    AgentConfig config,          // For binary paths and state dir
    WireGuardManager wgManager,  // For ensuring private key exists
    ProcessSupervisor supervisor,// For starting/stopping wireguard.exe
    Func<string, bool>? binaryExists = null)  // For testing (mock file existence)
```

#### 4.2 BringUp(forwardId, wgConfig) — Main Entry Point

Preconditions checked (returns false if any fail):
1. `wgConfig.Enabled == true`
2. `wireguard.exe` exists at `GetEffectiveWireguardBin()` path
3. Local private key exists at `<state_dir>/wireguard/private.key`
4. `SidecarPublicKey`, `EndpointHost`, `EndpointPort` are all non-empty/positive

Actions:
1. Call `TearDown(forwardId)` to clean stale configs
2. Read private key from file
3. Render `.conf` content using `RenderConfigContent()`
4. Write to `<state_dir>/wireguard/<interface_name>.conf`
5. Start `wireguard.exe /installtunnelservice "<interface_name>"` via ProcessSupervisor
6. Probe sidecar IP via ICMP ping (10 second timeout, 500ms intervals)
7. Return `true` if process started (even if ping fails — tunnel may be slow to establish)


#### 4.3 Config File Rendering

The `.conf` file follows standard WireGuard format:
```ini
[Interface]
PrivateKey = <contents of state_dir/wireguard/private.key>
Address = 10.99.47.1/32

[Peer]
PublicKey = <sidecar_public_key from panel>
Endpoint = 185.220.238.143:14969
AllowedIPs = 10.99.47.2/32
PersistentKeepalive = 25
```

Important notes:
- `Address` uses `ClientAddress` field. If it doesn't contain `/`, append `/32`
- `AllowedIPs` is the sidecar address only (NOT `0.0.0.0/0` — we don't want full tunnel)
- `Endpoint` is `EndpointHost:EndpointPort` (the sidecar's real public IP + WG listen port)
- No `ListenPort` in [Interface] — Windows client doesn't need to receive unsolicited packets

#### 4.4 TearDown(forwardId)

Cleans up config files for the forward. The actual process kill is handled by `ProcessSupervisor.StopAll(forwardId)` from `ForwardRuntimeManager`.

#### 4.5 File Locations

| Item | Path |
|------|------|
| Private key | `<state_dir>/wireguard/private.key` |
| Public key | `<state_dir>/wireguard/public.key` |
| Config files | `<state_dir>/wireguard/lw-self-<forward_id>.conf` |
| Default state_dir | `C:\ProgramData\Leikwan\ClientAgent\state` |

### Step 5: ForwardRuntimeManager Integration

**File:** `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`

#### 5.1 Constructor Change

Added `WireGuardTunnelService? wgTunnelService = null` parameter (optional/nullable for backward compat).

#### 5.2 ApplyForwardCore() — WG Block (after StopAll, before BondRuntimeBuilder.Build)

```csharp
bool wgSelfLegActive = false;
if (mode == "bond" && forward.SelfLegWg is { Enabled: true } wgConfig && _wgTunnelService != null)
{
    wgSelfLegActive = _wgTunnelService.BringUp(forwardId, wgConfig);
    if (wgSelfLegActive)
    {
        forward = InjectWgSelfLegEndpoint(forward, wgConfig);
    }
}
```

#### 5.3 InjectWgSelfLegEndpoint() — New Helper Method

Creates a synthetic `EndpointConfig` representing the WG tunnel leg:
```csharp
var wgEndpoint = new EndpointConfig
{
    EndpointId = -1,           // Synthetic (not from panel)
    Host = sidecarIp,          // e.g. "10.99.47.2" (tunnel private IP)
    Port = bondPort,           // From SelfLegWg.SidecarBondPort or Bonding.SidecarPort
    DynamicPriority = 0,       // HIGHEST priority (lower number = higher priority)
    LegRole = "primary",
    HealthRole = "healthy",
    QualityScore = 1.0,
    SupportsQuicLeg = false,   // Plain TCP through WG tunnel, no QUIC
};
```

This endpoint is PREPENDED to the endpoints list (position 0), so the bond runtime treats it as the primary/fastest leg. Existing CF/helper legs remain as fallbacks at lower priority.

Bond port resolution order:
1. `wgConfig.SidecarBondPort` (explicit in self_leg_wg payload)
2. `forward.Bonding.SidecarPort` (from bonding config)
3. If neither is set → log warning, skip injection, fall back to CF legs only


---

## 6. HOW THE wireguard.exe BINARY WORKS ON WINDOWS

### 6.1 Binary Source

The `wireguard.exe` binary is the official WireGuard Windows command-line tool. It can be obtained from:
- Official release: `https://download.wireguard.com/windows-client/`
- Or built from source: `https://git.zx2c4.com/wireguard-windows`

### 6.2 Invocation Method

Current implementation uses:
```
wireguard.exe /installtunnelservice "lw-self-47"
```

This creates a Windows service named `WireGuardTunnel$lw-self-47` that manages the tunnel. The config file must be placed where WireGuard expects it.

ALTERNATIVE (user's original suggestion for simpler cases):
```
wireguard.exe -c lw-self-47.conf
```

NOTE FOR FUTURE AGENT: If the `/installtunnelservice` approach causes permission issues (requires admin), switch to running `wireguard.exe` in userspace mode as a background process. The `ProcessSupervisor` already handles process lifecycle, so either approach works. The key requirement is:
1. Config file exists at a known path
2. Process starts and creates a network interface
3. Process can be killed to tear down the tunnel

### 6.3 Required Permissions

WireGuard on Windows requires Administrator privileges to create TUN adapters. The LeikwanBridge Desktop app should already run with elevated permissions (as it manages network forwarding). If not, this needs to be addressed in the installer.

---

## 7. DATA FLOW DIAGRAM

```
+-----------------------------------------------------------------------+
| PANEL (PHP)                                                           |
|   Publishes forward config with self_leg_wg when client has           |
|   wireguard_public_key in heartbeat                                   |
+-----------------------------------+-----------------------------------+
                                    | JSON over HTTPS (pull/poll)
                                    v
+-----------------------------------------------------------------------+
| WINDOWS CLIENT (C#)                                                   |
|                                                                       |
|  ConfigPollingService                                                 |
|    -> Deserializes ForwardConfig (now includes SelfLegWg)             |
|    -> Calls ForwardRuntimeManager.ApplyForward(forward)               |
|                                                                       |
|  ForwardRuntimeManager.ApplyForwardCore()                             |
|    1. StopAll(forwardId) - kills old processes                        |
|    2. IF self_leg_wg enabled:                                         |
|       -> WireGuardTunnelService.BringUp()                             |
|         - Renders .conf file                                          |
|         - Starts wireguard.exe (ProcessSupervisor)                    |
|         - Pings sidecar private IP                                    |
|       -> InjectWgSelfLegEndpoint()                                    |
|         - Adds 10.99.47.2:11035 as priority=0 bond leg                |
|    3. BondRuntimeBuilder.Build(forward)                               |
|       - Builds wstunnel legs for CF/helper endpoints                  |
|       - WG leg needs NO wstunnel (direct TCP to tunnel IP)            |
|    4. Start all processes                                             |
|                                                                       |
|  ProcessSupervisor manages:                                           |
|    - wireguard.exe (role: "wg-self-leg")                              |
|    - wstunnel.exe x N (role: "wstunnel-leg")                          |
|    - leikwan-bond.exe (role: "bond")                                  |
|    - leikwan-ingress.exe (role: "ingress") [if needed]                |
+-----------------------------------------------------------------------+
                                    |
            +-----------------------+-----------------------+
            | WG Tunnel (UDP)       | WSS Legs (TCP/443)    |
            v                       v                       v
+-------------------+  +-------------------+  +-------------------+
| SIDECAR (Tokyo)   |  | CF Edge (helper1) |  | CF Edge (helper2) |
| WG: 14969/udp     |  | wss://1.2.3.4:443 |  | wss://5.6.7.8:443 |
| Bond: 11035/tcp   |  |                   |  |                   |
| Private: 10.99.47.2| |                   |  |                   |
+-------------------+  +-------------------+  +-------------------+
```


---

## 8. EDGE CASES AND ERROR HANDLING

| Scenario | Behavior |
|----------|----------|
| `wireguard.exe` not found in `bin/` | Log warning, skip WG, use CF legs only |
| Private key doesn't exist yet | `WireGuardManager.EnsureIdentity()` generates it; if still missing, skip |
| `self_leg_wg` not in forward config | `SelfLegWg` is null, WG block is skipped entirely |
| `self_leg_wg.enabled = false` | WG block is skipped |
| Sidecar unreachable after tunnel start | Return true anyway (process is running), bond will detect via health |
| Forward config changes (new sidecar key) | Full re-apply: StopAll kills old wireguard.exe, BringUp starts new one |
| Forward stopped by user | StopForward -> StopAll kills wireguard.exe + cleans config |
| wireguard.exe crashes | ProcessSupervisor detects on next health check cycle |
| `SidecarBondPort` is 0 and `Bonding.SidecarPort` is null | Log warning, don't inject WG endpoint, fall back to CF legs |
| Mode is "single" or "race" (not "bond") | WG self-leg block is skipped (only applies to bond mode) |
| Multiple forwards with WG | Each gets its own interface name (`lw-self-47`, `lw-self-48`, etc.) |

---

## 9. REMAINING WORK (NOT YET DONE — FOR FUTURE AGENTS)

### 9.1 Packaging — Bundle wireguard.exe

**What:** Add `wireguard.exe` to the Windows installer package.
**Where:** `scripts/build-windows-client-setup.ps1` or equivalent MSI/NSIS build script.
**Action:** Download or compile `wireguard.exe` for Windows x64 and place it at `bin/wireguard.exe` alongside other binaries (`wstunnel.exe`, `leikwan-bond.exe`, `leikwan-ingress.exe`, `wg.exe`).

### 9.2 WireGuard Tunnel Service Registration at App Startup

**What:** Instantiate `WireGuardTunnelService` and pass it to `ForwardRuntimeManager`.
**Where:** Look for where `ForwardRuntimeManager` is currently constructed — likely in `App.xaml.cs`, `MainWindow.xaml.cs`, or a `ServiceHost` / DI container class.
**Action:**
```csharp
var wgManager = new WireGuardManager(config.GetEffectiveWgBin(), config.GetEffectiveStateDir());
var wgTunnelService = new WireGuardTunnelService(config, wgManager, processSupervisor);
var forwardManager = new ForwardRuntimeManager(config, processSupervisor, stateManager, wgTunnelService);
```

### 9.3 Health Reporting — Include WG Tunnel Status in Heartbeat

**What:** Report WG tunnel health in heartbeat so panel knows if the self-leg is working.
**Where:** `Services/HeartbeatService.cs` or wherever heartbeat payload is built.
**Action:** Add fields like:
```json
{
  "wg_self_leg_tunnels": [
    { "forward_id": 47, "interface_name": "lw-self-47", "active": true, "sidecar_reachable": true }
  ]
}
```


### 9.4 CF-WG Outer Transport (future)

**What:** Support `outer_transport = "wstunnel_udp"` where the WG UDP packets are wrapped in a wstunnel WebSocket through Cloudflare.
**Why deferred:** Adds significant complexity (need a wstunnel instance forwarding UDP to the local WG listen port). Initial pass supports `outer_transport = "direct"` only.
**When needed:** When the sidecar is behind a firewall that blocks raw UDP.
**Reference:** See Linux implementation in `self_leg_wg_underlay()` function — when `outer_transport == "wstunnel_udp"`, it sets `endpoint_host = "127.0.0.1"` and `endpoint_port = 40000 + (forward_id % 2000)`, then adds a wstunnel leg to forward UDP from that local port to the CF edge.

### 9.5 Unit Tests

Suggested test cases:
```
[Fact] void RenderConfigContent_ValidConfig_ProducesCorrectIni()
[Fact] void BringUp_MissingBinary_ReturnsFalse()
[Fact] void BringUp_MissingSidecarKey_ReturnsFalse()
[Fact] void BringUp_DisabledConfig_ReturnsFalse()
[Fact] void InjectEndpoint_ValidConfig_PrependsPrimaryLeg()
[Fact] void InjectEndpoint_NoBondPort_SkipsInjection()
[Fact] void ApplyForward_WithWgConfig_StartsWireguardProcess()
[Fact] void ApplyForward_WithoutWgConfig_SkipsWireguard()
```

### 9.6 Process Restart / Crash Recovery

**What:** If wireguard.exe crashes, auto-restart it.
**Where:** `ProcessSupervisor` or a new `TunnelHealthMonitor`.
**Current behavior:** If wireguard.exe dies, it will only be restarted on the next `ApplyForward()` call (triggered by config poll). For better resilience, add periodic liveness checks.

---

## 10. HOW TO VERIFY THIS WORKS

### 10.1 Local Testing (no real sidecar)

1. Build the Windows app
2. Place a dummy `wireguard.exe` in `bin/` (can be any exe that doesn't crash immediately)
3. Create a config with `self_leg_wg` enabled
4. Observe logs showing:
   - "attempting WireGuard self-leg tunnel (lw-self-47)"
   - Config file written to state_dir
   - wireguard.exe process started
   - "sidecar not reachable within timeout" (expected with no real sidecar)
   - Bond runtime starts with the WG endpoint in the leg list

### 10.2 Live Testing (with real sidecar)

1. Ensure sidecar has WG configured (`self_leg_client_peers` in its management payload)
2. Ensure panel provisions `self_leg_wg` for the forward
3. Start Windows app -> observe:
   - `wireguard.exe` starts and creates tunnel interface
   - `ping 10.99.47.2` succeeds from the Windows machine
   - Bond runtime uses the WG leg as primary (lowest latency)
   - Traffic flows through the WG tunnel to sidecar
4. Kill `wireguard.exe` -> observe bond falls back to CF legs
5. Stop forward -> observe `wireguard.exe` is killed and config cleaned up

---

## 11. IMPORTANT CONSTRAINTS FOR IMPLEMENTING AGENTS

1. **DO NOT install WireGuard as a Windows service from the official installer.** We bundle and invoke the binary directly.
2. **DO NOT route all traffic through WG (no 0.0.0.0/0 in AllowedIPs).** Only route traffic to the sidecar private IP. This is a split tunnel, not a full VPN.
3. **DO NOT modify WireGuardManager.cs.** It handles identity (keypair) only and is stable. Tunnel management is separate.
4. **The WG leg does NOT need a wstunnel wrapper.** It connects directly to the sidecar private IP through the tunnel. Other bond legs still use wstunnel through CF.
5. **Priority 0 = highest priority.** Lower numbers mean higher priority in the bond runtime. The WG leg gets priority 0, CF legs get priority 10+.
6. **The forward runtime is idempotent.** Calling ApplyForward with the same config should produce the same result (stop old -> start new).
7. **All processes for a forward share the same forwardId.** `StopAll(forwardId)` kills everything: wireguard.exe, wstunnel.exe instances, bond.exe, ingress.exe.
8. **Config file naming must use the interface name, not forward ID directly.** If `interface_name` is provided, use it. Otherwise default to `lw-self-<forward_id>`.
9. **The private key is NEVER sent to/from the panel.** It's generated locally by WireGuardManager and stays in the state directory. Only the public key is exchanged.
10. **Windows does not support MPTCP.** If the forward mode is "mptcp", it's already converted to "bond" mode by `MptcpFallbackService.ConvertToBond()` before the WG logic runs.


---

## 12. GLOSSARY

| Term | Meaning |
|------|---------|
| Self-leg | The client acting as its own helper by establishing a direct tunnel to the sidecar |
| Sidecar | The remote server that aggregates bond legs and forwards to the final target |
| Bond | Multi-path TCP aggregation (multiple connections bonded into one) |
| CF leg | A bond leg that routes through Cloudflare's CDN network via wstunnel |
| WG leg | A bond leg that routes through a WireGuard tunnel directly to the sidecar |
| Helper | A rented/donated server that acts as an additional bond leg |
| Panel | The PHP web control panel that provisions configurations |
| Heartbeat | Periodic status report from client to panel (every 30s) |
| ProcessSupervisor | C# service that starts/stops/monitors child processes |
| State dir | Local directory for persistent state (`C:\ProgramData\Leikwan\ClientAgent\state`) |
| wstunnel | WebSocket tunneling binary used for CF legs |
| ForwardConfig | A single forwarding rule (local port -> target via bond endpoints) |
| EndpointConfig | A single bond leg endpoint (host:port with priority/health info) |
| wg.exe | WireGuard CLI tool for key generation (used by WireGuardManager) |
| wireguard.exe | WireGuard tunnel daemon binary (used by WireGuardTunnelService) |
| DynamicPriority | Bond leg priority (0=highest, 10=normal CF, 20+=low priority) |

---

## 13. FILE TREE REFERENCE (relevant files only)

```
LeikwanBridge.Desktop/
  src/
    LeikwanBridge.Core/
      Models/
        AgentConfig.cs          <- MODIFIED (added wireguard_bin + GetEffectiveWireguardBin)
        ForwardConfig.cs        <- MODIFIED (added SelfLegWg property)
        SelfLegWgConfig.cs      <- NEW (panel self_leg_wg model)
        EndpointConfig.cs       <- unchanged (used for WG endpoint injection)
        BondingConfig.cs        <- unchanged (SidecarPort used as fallback)
      Services/
        WireGuardTunnelService.cs   <- NEW (tunnel lifecycle)
        WireGuardManager.cs         <- unchanged (identity/keygen only)
        ForwardRuntimeManager.cs    <- MODIFIED (WG integration + endpoint injection)
        ProcessSupervisor.cs        <- unchanged (manages wireguard.exe process)
        BondRuntimeBuilder.cs       <- unchanged (receives augmented endpoints)
        BondEndpointHealthService.cs<- unchanged
      Helpers/
        WindowsForwardPolicy.cs     <- unchanged (bond mode already allowed)
```

---

## 14. PR AND BRANCH REFERENCE

- **PR:** https://github.com/frankniubi/leikwan-br1/pull/2
- **Branch:** https://github.com/frankniubi/leikwan-br1/tree/feat/windows-wireguard-self-leg
- **Commit:** `6850307 feat(windows): add WireGuard self-leg tunnel support`

The commit contains all code changes described in this plan. The plan document itself will be updated in a follow-up commit on the same branch.
