# sh36 Slave MPTCP Connectivity Issue - Investigation Summary

**Date:** 2026-05-27  
**Slave:** sh36 (47.116.11.144, node_id=12)  
**Sidecar:** Point 5 (185.220.238.143)  
**Forward:** 47  

## Issue Summary

The slave preconnect relay and WireGuard MPTCP underlay for forward 47 are non-functional due to broken connectivity between sh36 slave and sidecar 5.

## Root Cause Analysis

### 1. **lw-cfsr-* Code Fix** ✅ COMPLETE
- **Issue:** The slave preconnect implementation accidentally removed support for client-as-a-slave CF-BOND (`lw-cfsr-*` interfaces)
- **Fix Applied:** 
  - Commit `866bd55`: Restored `lw-cfsr-*` interface cleanup in `leikwan_mptcp_client.py`
  - Commit `6d68a66`: Restored `self_leg_slave_relay_wg_underlay()` and `ensure_cfwg_relay_service()`
- **Status:** Code fix complete and committed to branch `codex/slave-preconnect-base-20260527`

### 2. **Slave-to-Sidecar WireGuard Connectivity** ❌ BROKEN
- **Issue:** WireGuard tunnel between sh36 slave (10.203.201.174) and sidecar 5 (10.203.201.175) has 100% packet loss
- **Symptoms:**
  - Slave WG interface `lwf47n12` shows `0 B received, X KiB sent`
  - Sidecar WG interface `lw-sc-5-53` shows stale handshake (>1 hour old)
  - Ping from slave to sidecar fails completely
  - Preconnect relay cannot connect to sidecar MPTCP listener on `10.203.201.175:8898`

### 3. **Wstunnel Configuration Issues** ❌ BROKEN
- **Issue:** The wstunnel backend for forwarding slave WG traffic through Cloudflare is misconfigured
- **Problems Found:**
  - Slave wstunnel service `leikwan-mptcp-cf-lwf47n12` keeps restarting
  - Path prefix mismatch: slave uses `content/2fc4e062/episode-143.mp4` but sidecar restrict config expects `^content$`
  - Target IP confusion: slave wstunnel alternates between CF IP (`162.159.39.26:2053`) and sidecar IP (`185.220.238.143:2053`)
  - TLS handshake completes but WebSocket upgrade fails silently
  - Slave agent keeps overwriting the wstunnel service configuration

## Current State

### Slave (sh36 - 47.116.11.144)
```
- leikwan-slave-agent: STOPPED (manually stopped for testing)
- leikwan-preconnect-2-500: RUNNING (failing to connect to sidecar)
- leikwan-mptcp-cf-lwf47n12: RUNNING (wstunnel, but not working)
- WG interface lwf47n12: UP (peer 10.203.201.175, 0 B received)
```

### Sidecar (Point 5 - 185.220.238.143)
```
- leikwan-bond-sidecar-5-agent: RUNNING
- WG interface lw-sc-5-53: UP (listening on port 16856, stale handshake)
- wstunnel server: RUNNING (port 2053, restrict config active)
```

## Architecture

The intended flow for slave MPTCP WG underlay:
```
Slave WG (lwf47n12:7329) 
  → Local wstunnel client (127.0.0.1:7030)
  → TLS/WSS to sidecar (185.220.238.143:2053)
  → Sidecar wstunnel server (path: "content")
  → Sidecar WG (lw-sc-5-53:16856)
```

## What Needs to be Fixed

### Priority 1: Wstunnel Path Configuration
1. **Sidecar restrict config** needs to allow the full path or slave needs to use just `content`
2. **Slave wstunnel** target should be `wss://185.220.238.143:2053` (sidecar IP, not CF IP)
3. **Slave agent** should not keep overwriting the wstunnel service config

### Priority 2: Sidecar Agent Bug ✅ FIXED (commit e97adea)
The sidecar agent is NOT creating wstunnel backends for `cf_mode` + `outer_transport: udp` slave transit underlays. The config shows:
```json
{
  "cf_path_prefix": "content/2fc4e062/episode-143.mp4",
  "cf_target_host": "127.0.0.1",
  "cf_target_port": 16856
}
```
But no wstunnel service is created to handle incoming connections on this path.

**Fix:** Added `ensure_sidecar_cf_wstunnel_backend()` method that creates wstunnel server backends when uplink has cf_mode config. Backends are registered in CF metadata and added to CF mux routes.

### Priority 2.5: Panel Configuration Bug ✅ FIXED (commit 95abb1f)
The panel's `slaveSidecarUplinkManagementForPoint()` was returning empty array, so no uplink configuration was being sent to the sidecar agent.

**Fix:** Implemented the function to query MPTCP forwards, build uplink configurations with WireGuard settings and CF mode parameters.

### Priority 3: Slave Agent Configuration
The slave agent is generating the wrong wstunnel configuration:
- Using CF IP instead of sidecar IP
- Using full path instead of just prefix
- Constantly restarting the service

## Recommended Fix Strategy

1. ✅ **Fix panel code** to send slave uplink configuration (commit 95abb1f)
2. ✅ **Fix sidecar agent code** to properly create wstunnel backends for slave transit CF mode underlays (commit e97adea)
3. **Fix slave agent code** to generate correct wstunnel configuration (sidecar IP, correct path)
4. **Deploy fixes** to panel and sidecar agent
5. **Restart sidecar agent** to regenerate all configurations
6. **Verify connectivity** with ping and preconnect relay tests

## Testing Checklist

- [ ] Ping from slave to sidecar succeeds (10.203.201.174 → 10.203.201.175)
- [ ] WireGuard handshake is fresh (<2 minutes)
- [ ] WireGuard shows bidirectional traffic (both sent and received > 0)
- [ ] Preconnect relay successfully connects to sidecar MPTCP listener
- [ ] Preconnect relay can establish TCP and UDP streams
- [ ] lw-cfsr-* interfaces can be created on clients (separate test)

## Files Modified

### Code Changes (Committed)
- `client-agent/leikwan_mptcp_client.py` - Restored lw-cfsr-* cleanup
- `client-agent/install_forward_runtime.py` - Restored self_leg_slave_relay_wg_underlay()
- `slave-agent/slave_agent.py` - Restored ensure_cfwg_relay_service()
- `client-agent/tests/test_cfwg_slave_relay_runtime.py` - Test coverage

### Manual Fixes Applied (Temporary)
- `/etc/systemd/system/leikwan-mptcp-cf-lwf47n12.service` on sh36 - Updated wstunnel config
- `/etc/wireguard/lw-sc-5-53.conf` on sidecar 5 - Removed endpoint (listen-only mode)

## Next Steps

1. Investigate and fix sidecar agent wstunnel backend creation logic
2. Investigate and fix slave agent wstunnel configuration generation
3. Deploy fixes to both agents
4. Restart agents and verify full connectivity
5. Test lw-cfsr-* feature with actual client deployment
