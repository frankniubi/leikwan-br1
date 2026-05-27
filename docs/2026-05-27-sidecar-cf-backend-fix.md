# Sidecar Agent CF Backend Fix - 2026-05-27

**Commit:** e97adea  
**Branch:** codex/slave-preconnect-base-20260527  
**Issue:** Sidecar agent not creating wstunnel backends for cf_mode slave transit underlays

## Problem

The sidecar agent's `apply_slave_sidecar_uplink()` method only handled `outer_transport == "tcp"` by creating UoT server services, but did not handle `cf_mode` which requires creating wstunnel server backends.

When a slave connects to a sidecar through Cloudflare (cf_mode), the slave's wstunnel client connects to the CF mux on the sidecar, which should route the connection to a wstunnel backend that forwards UDP traffic to the sidecar's WireGuard listen port. Without this backend, the connection fails silently after TLS handshake, resulting in 100% packet loss.

## Root Cause

From the investigation document (docs/2026-05-27-sh36-mptcp-connectivity-investigation.md):

> The sidecar agent is NOT creating wstunnel backends for `cf_mode` + `outer_transport: udp` slave transit underlays. The config shows:
> ```json
> {
>   "cf_path_prefix": "content/2fc4e062/episode-143.mp4",
>   "cf_target_host": "127.0.0.1",
>   "cf_target_port": 16856
> }
> ```
> But no wstunnel service is created to handle incoming connections on this path.

## Solution

Added support for creating wstunnel backends when processing slave uplinks with cf_mode configuration:

### 1. Extract CF Configuration from Uplink

```python
cf_path_prefix = str(uplink.get("cf_path_prefix", "") or "").strip()
cf_target_host = str(uplink.get("cf_target_host", "") or "").strip()
cf_target_port = max(0, int(uplink.get("cf_target_port", 0) or 0))
```

### 2. Create Wstunnel Backend Service

New method `ensure_sidecar_cf_wstunnel_backend()`:
- Allocates a backend port dynamically (26000-26099 range)
- Creates a systemd service running wstunnel server
- Registers the backend in CF metadata for persistence
- Service listens on `ws://127.0.0.1:<backend_port>`
- Restricts to target (WireGuard listen port)
- Restricts to path prefix for routing

### 3. Register Backend with CF Mux

Modified CF runtime processing to include slave transit backends:
- Reads CF metadata to find `backend_type: "slave_transit_cf_wg"` entries
- Adds them to `rendered_routes` for the CF mux
- CF mux routes incoming WSS connections to the backend based on path prefix

### 4. Cleanup on Removal

New method `remove_sidecar_cf_wstunnel_backend()`:
- Stops and disables the systemd service
- Removes the unit file
- Removes the entry from CF metadata

## Architecture

The complete flow for slave-to-sidecar CF mode connectivity:

```
Slave WG (lwf47n12:7329)
  → Slave wstunnel client (127.0.0.1:7030)
  → TLS/WSS to CF IP (162.159.39.26:2053 or sidecar IP)
  → Sidecar CF mux (port 2053, TLS termination)
  → Routes by path prefix to backend
  → Sidecar wstunnel backend (127.0.0.1:26000-26099)
  → Sidecar WG (lw-sc-5-53:16856)
```

## Files Modified

- `sidecar-agent/sidecar_agent.py`:
  - `apply_slave_sidecar_uplink()`: Extract CF config and create backend
  - `remove_slave_sidecar_uplink()`: Clean up backend
  - `ensure_sidecar_cf_wstunnel_backend()`: New method
  - `remove_sidecar_cf_wstunnel_backend()`: New method
  - CF runtime processing: Add slave transit backends to routes

## Testing

To test this fix:

1. Deploy updated sidecar agent to sidecar 5
2. Ensure slave uplink configuration includes:
   - `cf_path_prefix`: Path prefix for routing (e.g., "content")
   - `cf_target_host`: Target host (usually "127.0.0.1")
   - `cf_target_port`: WireGuard listen port (e.g., 16856)
3. Verify wstunnel backend service is created:
   ```bash
   systemctl status leikwan-cf-slave-<interface_name>.service
   ```
4. Verify backend is registered in CF metadata:
   ```bash
   cat /var/lib/leikwan-sidecar-5/cf-managed.json
   ```
5. Verify backend is in CF routes:
   ```bash
   cat /var/lib/leikwan-sidecar-5/cf-routes-2053.json
   ```
6. Test connectivity from slave to sidecar:
   ```bash
   ping -c 5 10.203.201.175  # From slave
   wg show lwf47n12           # Should show bidirectional traffic
   ```

## Next Steps

1. Fix slave agent wstunnel configuration (Priority 3)
   - Use sidecar IP instead of CF IP
   - Use correct path prefix format
   - Prevent agent from constantly overwriting config

2. Deploy and test end-to-end connectivity

3. Verify preconnect relay functionality

4. Test lw-cfsr-* (client-as-a-slave) feature

## Related

- Investigation: docs/2026-05-27-sh36-mptcp-connectivity-investigation.md
- lw-cfsr-* code fix: commits 866bd55, 6d68a66
