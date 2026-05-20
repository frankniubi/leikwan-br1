# Windows WireGuard Self-Leg Tunnel Support — Upgrade Plan

**Date:** 2026-05-20
**Branch:** `feat/windows-wireguard-self-leg`
**Baseline:** `master`
**Goal:** Port the Linux client self-leg WireGuard automation to the Windows desktop app, using a bundled `wireguard.exe` binary invoked with a config file (no installer/service from official WireGuard).

---

## Background

The Linux client (`client-agent/install_forward_runtime.py`) already supports:
- Parsing `self_leg_wg` payload from the panel provisioning response
- Rendering `/etc/wireguard/lw-self-<forward_id>.conf`
- Bringing up `wg-quick@lw-self-<forward_id>` as a systemd unit
- Routing MPTCP/bond runtime traffic through the sidecar private tunnel IP when the WG leg wins probes

The Windows client currently:
- Has `WireGuardManager.cs` for **identity only** (keygen + heartbeat public key reporting)
- Reports `wireguard_public_key`, `wireguard_listen_port`, `wireguard_fallback_tcp_port` in heartbeats
- Does NOT consume `self_leg_wg` from pulled configs
- Does NOT create WireGuard tunnel interfaces

## Design Decisions

### WireGuard binary approach (user requirement)
- Use a standalone `wireguard.exe` binary bundled in `bin/wireguard.exe`
- Invoke as: `.\wireguard.exe /installtunnelservice <interface_name>` or use the userspace mode: `.\wireguard.exe -c <config_file>.conf` (running in background)
- **Chosen approach:** `wireguard.exe /installtunnelservice lw-self-<forward_id>` which reads from `C:\Program Files\WireGuard\Data\Configurations\lw-self-<forward_id>.conf.dpapi` — BUT this requires the official WireGuard Windows service model.
- **Actually chosen (per user request):** Use the **wireguard-go userspace** or **WireGuard Windows CLI** approach:
  - Write a `.conf` file to the state directory
  - Run `wireguard.exe /run /configfile <path_to_conf>` as a background process (stays in foreground of that process, effectively a daemon)
  - Kill the process to tear down the tunnel
  - This avoids needing the WireGuard Windows service installed

### Tunnel lifecycle
- The WireGuard tunnel process is managed by `ProcessSupervisor` just like bond/wstunnel legs
- Tunnel is brought up **before** bond runtime starts (similar to Linux's `wg-quick up` before MPTCP)
- Tunnel is torn down when forward is stopped or config changes
- Stale tunnels for the same forward are cleaned up on re-apply

### Bond integration
- When `self_leg_wg` is present and enabled, the sidecar's private tunnel IP becomes an additional bond leg endpoint
- The WG leg is treated as a low-latency private path (higher priority than CF/rented legs)
- Fallback: if WG tunnel fails to start or the binary is missing, the forward continues with existing CF bond legs only

---

## Implementation Tasks

### Task 1: Model — `SelfLegWgConfig`

**File:** `LeikwanBridge.Core/Models/SelfLegWgConfig.cs` (new)

```csharp
public sealed class SelfLegWgConfig
{
    bool Enabled
    string InterfaceName        // "lw-self-47"
    string ClientAddress        // "10.x.x.1/32"
    string SidecarAddress       // "10.x.x.2"
    string ClientPublicKey
    string ClientPrivateKey     // not from panel — read from local identity
    string SidecarPublicKey
    string EndpointHost         // sidecar real IP
    int EndpointPort            // sidecar WG listen port (e.g. 14969)
    int FallbackTcpPort         // sidecar WG fallback TCP port
    int PersistentKeepalive     // 25
    string OuterTransport       // "direct" | "wstunnel_udp" | "cf_wg"
    string CfDialHost           // for cf_wg outer transport
    int CfDialPort
    string CfSniHost
    string CfPathPrefix
}
```

### Task 2: Extend `ForwardConfig`

**File:** `LeikwanBridge.Core/Models/ForwardConfig.cs` (modify)

Add:
```csharp
[JsonPropertyName("self_leg_wg")]
public SelfLegWgConfig? SelfLegWg { get; set; }
```

### Task 3: Add `wireguard_bin` to `AgentConfig`

**File:** `LeikwanBridge.Core/Models/AgentConfig.cs` (modify)

Add:
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

### Task 4: `WireGuardTunnelService` (new)

**File:** `LeikwanBridge.Core/Services/WireGuardTunnelService.cs` (new)

Responsibilities:
1. **Render config file** — Write `<state_dir>/wireguard/lw-self-<forward_id>.conf` with:
   ```ini
   [Interface]
   PrivateKey = <client_private_key>
   Address = <client_address>
   ListenPort = <local_listen_port>

   [Peer]
   PublicKey = <sidecar_public_key>
   Endpoint = <endpoint_host>:<endpoint_port>
   AllowedIPs = <sidecar_address>/32
   PersistentKeepalive = 25
   ```
2. **Start tunnel** — Launch `wireguard.exe /run /configfile <conf_path>` as a background process via `ProcessSupervisor`
3. **Stop tunnel** — Kill the wireguard.exe process (ProcessSupervisor handles this)
4. **Cleanup stale** — Remove old `.conf` files for the same forward_id before re-rendering
5. **Probe readiness** — Ping/TCP-connect to `sidecar_address` to verify tunnel is up (with timeout)

### Task 5: Integrate into `ForwardRuntimeManager`

**File:** `LeikwanBridge.Core/Services/ForwardRuntimeManager.cs` (modify)

In `ApplyForwardCore()`:
1. After stopping existing processes, check if `forward.SelfLegWg?.Enabled == true`
2. If yes:
   - Render the WG config file
   - Start the wireguard.exe tunnel process via ProcessSupervisor (role: `"wg-self-leg"`)
   - Wait for tunnel readiness (ping sidecar private IP, timeout 10s)
   - If tunnel is up, inject the sidecar private address as an additional bond leg with high priority
   - If tunnel fails, log warning and continue without it
3. The WG tunnel process is tracked in the forward's process list so it's killed on `StopForward()`

### Task 6: Bond leg injection

When WG tunnel is up, modify the `ForwardConfig` endpoints list to include:
```
new EndpointConfig {
    Host = sidecar_address (e.g. "10.x.x.2"),
    Port = bond_backend_port (from bonding config),
    DynamicPriority = 0 (highest priority),
    LegRole = "primary",
    // Direct TCP to sidecar private IP — no wstunnel needed
}
```

This means the bond runtime gets a `tcp://10.x.x.2:<port>?priority=0&role=primary` leg that goes directly through the WG tunnel without needing a separate wstunnel instance.

### Task 7: WindowsForwardPolicy — no change needed

The existing policy already allows `bond` mode forwards. The `self_leg_wg` field is just additional metadata on a bond forward. No policy filter change required.

---

## File change summary

| File | Action | Description |
|------|--------|-------------|
| `Models/SelfLegWgConfig.cs` | Create | Panel self_leg_wg payload model |
| `Models/ForwardConfig.cs` | Modify | Add `SelfLegWg` property |
| `Models/AgentConfig.cs` | Modify | Add `WireguardBin` + `GetEffectiveWireguardBin()` |
| `Services/WireGuardTunnelService.cs` | Create | Tunnel lifecycle: render conf, start/stop wireguard.exe, probe |
| `Services/ForwardRuntimeManager.cs` | Modify | Integrate WG tunnel before bond startup |
| `Services/WireGuardManager.cs` | Keep | Identity management unchanged |

---

## Packaging changes (future)

- Bundle `wireguard.exe` (the standalone Windows userspace WireGuard binary) into `bin/wireguard.exe` alongside the existing `wstunnel.exe`, `leikwan-bond.exe`, `leikwan-ingress.exe`
- Update `scripts/build-windows-client-setup.ps1` to include `bin/wireguard.exe`
- The `wireguard.exe` binary can be obtained from the official WireGuard Windows release or built from wireguard-go

---

## Verification plan

1. **Unit tests:** Add tests for `WireGuardTunnelService` config rendering
2. **Integration:** Verify tunnel process starts and stops correctly
3. **Live:** With a real sidecar that has `self_leg_client_peers` configured, verify:
   - `wireguard.exe` starts and maintains the tunnel
   - Bond runtime uses the WG leg as primary
   - Fallback works when WG binary is missing

---

## Out of scope

- MPTCP on Windows (not supported by Windows kernel)
- CF-WG outer transport (`wstunnel_udp` wrapping) — deferred to follow-up
- Android parity
- Panel-side changes (panel already publishes `self_leg_wg` for capable clients)
- WireGuard service installation (we use the binary directly)
