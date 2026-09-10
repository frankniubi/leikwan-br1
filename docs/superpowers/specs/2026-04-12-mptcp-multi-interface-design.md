# MPTCP Multi-Interface Architecture Design

## Problem

Single WG interface with multiple peers cannot support true MPTCP multipath. WireGuard cryptokey routing selects one peer based on destination IP. Since all MPTCP subflows target the same sidecar overlay IP, only 1-2 subflows establish (ESTAB), the rest remain SYN-SENT. Measured: 269 Mbps avg on 5 legs instead of target 500+ Mbps.

## Solution

One WG interface per slave leg. N slaves = N interfaces. Each interface has exactly 1 peer, 1 overlay address. MPTCP subflow endpoints bind to their respective interface `dev`. The kernel sends subflows from different interfaces, achieving true multipath.

## Implementation: Approach B — Evolve `slave_transit` Mode

Keep the existing `slave_transit` mode and structured `legs` data. Change from "1 underlay with N legs" to "N underlays each with 1 leg". Preserves UoT management, PostUp route logic, and structured config parsing.

## Phase 1: Manual Validation (No Code Changes)

On forward 20's client machine:

1. Stop current `lw-mptcp-20` single interface
2. Create 5 interfaces `lw-mptcp-20-0` through `lw-mptcp-20-4`, each with 1 peer
3. Register 5 MPTCP endpoints, each bound to its interface's `dev`
4. Do the same split on sidecar side
5. Run iperf — verify 5 subflows all reach ESTAB state
6. Measure throughput — target: significant improvement over 269 Mbps

## Phase 2: Code Changes

### 2.1 Panel — `ProvisioningService.php`

#### `buildMptcpClientConfig()` (line ~3691)

**Before:**
```php
'underlay' => [
    'type' => 'wireguard',
    'mode' => 'slave_transit',
    'interface_name' => 'lw-mptcp-' . $fwdId,
    'legs' => $allLegs,   // N legs, 1 interface
],
'mptcp_endpoint_device' => 'lw-mptcp-' . $fwdId,
```

**After:**
```php
'underlays' => array_map(function($leg, $idx) use ($fwdId, $config) {
    return [
        'type' => 'wireguard',
        'mode' => 'slave_transit',
        'interface_name' => 'lw-mptcp-' . $fwdId . '-' . $idx,
        'legs' => [$leg],   // 1 leg per interface
        // inherit: local_private_key_path, listen_port, peer_keepalive_seconds,
        //          initcwnd, initrwnd from parent config
    ];
}, $underlayLegs, array_keys($underlayLegs)),

'mptcp_endpoints' => array_map(function($leg, $idx) use ($fwdId) {
    return [
        'address' => $leg['local_address'],
        'dev' => 'lw-mptcp-' . $fwdId . '-' . $idx,
        'subflow' => true,
        'signal' => false,
        'backup_only' => $leg['backup_only'] ?? false,
    ];
}, $underlayLegs, array_keys($underlayLegs)),
// Remove: 'mptcp_endpoint_device' (no longer a single global device)
```

#### `mptcpSidecarManagementForPoint()` (line ~3849)

Same pattern: `underlay` → `underlays` array. Each underlay gets 1 leg.

**Sidecar interface naming:** `lw-sc-{pointId}-{legIdx}` (shortened to stay under 15-char IFNAMSIZ limit)

Sidecar endpoints use `signal: true` (not `subflow`):
```php
'mptcp_endpoints' => array_map(function($leg, $idx) use ($pointId) {
    return [
        'address' => $leg['local_address'],
        'dev' => 'lw-sc-' . $pointId . '-' . $idx,
        'signal' => true,
        'subflow' => false,
    ];
}, $legs, array_keys($legs)),
```

#### `mptcpUnderlayLegs()` (line ~3749)

No structural change. Each leg already contains all needed fields. The per-leg addressing via `stableMptcpLegAddressing()` already produces unique /30 subnets.

#### WG Listen Port

Each interface needs a unique listen port. Use: `base_listen_port + legIdx`. The base is already derived from `stableMptcpSlavePort()`. Add offset per leg:
```php
'listen_port' => $baseListenPort + $idx,
```

### 2.2 Client Agent — `leikwan_mptcp_client.py`

#### `apply_wireguard_transit_underlay()` (line ~219)

Rename to `apply_wireguard_transit_underlay_single()`. Add new wrapper:

```python
def apply_wireguard_transit_underlays(config):
    """Handle multiple underlay interfaces (one per leg)."""
    underlays = config.get('underlays', [])
    if not underlays and 'underlay' in config:
        # Backward compat: old single-underlay format
        underlays = [config['underlay']]

    active_iface_names = set()
    for underlay in underlays:
        apply_wireguard_transit_underlay_single(underlay, config)
        active_iface_names.add(underlay['interface_name'])

    # Clean up stale interfaces from previous config
    cleanup_stale_transit_interfaces(config, active_iface_names)
```

`apply_wireguard_transit_underlay_single()` is the current function body — it already works correctly with a 1-leg `legs` array. No change needed to `build_wireguard_interface_config()`.

#### MPTCP Endpoint Registration

`configure_mptcp_policy()` (line ~308) already supports per-endpoint `dev` field in the dict format:
```python
{"address": "10.203.x.y", "dev": "lw-mptcp-20-0", "subflow": true}
```
The `ip mptcp endpoint add` command at line ~450 already appends `dev {dev}` when present. **No change needed.**

#### Cleanup on Config Change

Add `cleanup_stale_transit_interfaces()`:
```python
def cleanup_stale_transit_interfaces(config, active_names):
    """Remove WG interfaces that are no longer in the config."""
    # List interfaces matching lw-mptcp-{fwdId}-*
    # wg-quick down any not in active_names
```

### 2.3 Sidecar Agent — `leikwan_mptcp_sidecar.py`

Mirror the client changes:
- `apply_wireguard_transit_underlay()` → wrapper + `_single()` pattern
- Backward compat for `underlay` vs `underlays`
- Stale interface cleanup

### 2.4 Rust Relay

#### `main.rs` — `derive_subflow_endpoints_from_underlay()` (line ~152)

**Before:** reads `config.underlay.legs[]`, uses global `mptcp_endpoint_device` for all.

**After:**
```rust
fn derive_subflow_endpoints_from_underlays(config: &Value) -> Vec<EndpointEntry> {
    let mut endpoints = Vec::new();
    let id_base = config["mptcp_endpoint_id_base"].as_u64().unwrap_or(100) as u32;

    let underlays = config.get("underlays")
        .and_then(|v| v.as_array())
        .unwrap_or(&vec![]);

    // Fallback: single underlay (backward compat)
    let single_underlay;
    let underlays = if underlays.is_empty() {
        single_underlay = vec![config["underlay"].clone()];
        &single_underlay
    } else {
        underlays
    };

    for (idx, underlay) in underlays.iter().enumerate() {
        let iface_name = underlay["interface_name"].as_str().unwrap_or("");
        if let Some(legs) = underlay.get("legs").and_then(|l| l.as_array()) {
            for leg in legs {
                let addr = leg["local_address"].as_str().unwrap_or("");
                endpoints.push(EndpointEntry {
                    address: addr.to_string(),
                    id: id_base + (idx as u32) + 1,
                    device: iface_name.to_string(),  // per-interface device
                    flags: "subflow".to_string(),
                });
            }
        }
    }
    endpoints
}
```

#### `endpoint.rs` — No changes needed

`EndpointEntry.device` is already per-entry. `add_endpoint()` already handles per-endpoint `dev` in the `ip mptcp endpoint add` command.

### 2.5 Slave Agent — No Changes

Already uses per-(forward, node) interfaces: `lwf{fwdId}n{nodeId}`.

## Interface Naming Convention

| Side | Pattern | Example | Max Length |
|------|---------|---------|------------|
| Client | `lw-mptcp-{fwdId}-{legIdx}` | `lw-mptcp-20-0` | 14 chars (OK, ≤15) |
| Sidecar | `lw-sc-{pointId}-{legIdx}` | `lw-sc-5-0` | 10 chars (OK) |
| Slave | `lwf{fwdId}n{nodeId}` (unchanged) | `lwf20n3` | 8 chars |

Note: Linux interface name limit is 15 characters (IFNAMSIZ - 1). Sidecar naming shortened from `lw-sidecar-` to `lw-sc-` to stay under limit for larger pointId values.

## Backward Compatibility

**Deployment order:** Agent first, Panel second.

1. Upgrade client/sidecar agents to support both `underlay` (old) and `underlays` (new)
2. Upgrade Rust relay binary to read `underlays`
3. Upgrade Panel to emit `underlays` format
4. Run `republish-mptcp.php` to push new configs

Agents that receive the new `underlays` key will use it. If they only see `underlay`, they fall back to single-interface mode.

## Verification Criteria

### Phase 1 (Manual)
- All 5 WG interfaces come up with correct peer
- `ip mptcp endpoint show` shows 5 endpoints, each with different `dev`
- `ss -M` shows 5 subflows in ESTAB state
- iperf throughput significantly exceeds 269 Mbps baseline

### Phase 2 (Code)
- Panel generates correct `underlays` JSON with per-leg interfaces
- Agent creates N WG interfaces from config
- MPTCP endpoints are registered with correct per-interface `dev` binding
- Stale interfaces from old config are cleaned up
- Rust relay discovers endpoints from `underlays` array
- End-to-end iperf shows comparable throughput to manual test
