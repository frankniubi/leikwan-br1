# Deployment Plan - Slave Transit CF Mode Fix

**Date:** 2026-05-27  
**Branch:** codex/slave-preconnect-base-20260527  
**Target:** Fix sh36 slave to Tokyo sidecar (sidecar 5) connectivity

## Commits to Deploy

1. **95abb1f** - feat(panel): implement slaveSidecarUplinkManagementForPoint
2. **e97adea** - fix(sidecar): create wstunnel backends for cf_mode slave transit underlays
3. **6d68a66** - feat(client): restore slave-relayed cfwg WG leg (lw-cfsr-*)
4. **866bd55** - fix(client): restore lw-cfsr-* interface cleanup support

## Deployment Steps

### 1. Deploy Panel Changes

```bash
# On master server (v3.leikwanhost.com)
cd /var/www/leikwan-panel
git fetch origin
git checkout codex/slave-preconnect-base-20260527
git pull origin codex/slave-preconnect-base-20260527

# Run migrations if needed
php artisan migrate

# Clear caches
php artisan cache:clear
php artisan config:clear
```

### 2. Deploy Sidecar Agent to Sidecar 5

```bash
# Upload new sidecar_agent.py to master artifacts
# From local machine:
cd C:\Users\frankniubi\Downloads\leikwan-br1
scp sidecar-agent/sidecar_agent.py root@v3.leikwanhost.com:/var/www/leikwan-panel/public/artifacts/sidecar-agent/

# On master, update version and trigger update
# Edit the sidecar agent version in the database or admin panel
```

Or manually on sidecar 5:

```bash
# SSH to sidecar 5
ssh root@185.220.238.143 -p 14950

# Backup current agent
cp /usr/local/bin/leikwan-sidecar-agent.py /usr/local/bin/leikwan-sidecar-agent.py.backup

# Upload new agent
# (use scp or copy from master)

# Restart agent
systemctl restart leikwan-bond-sidecar-5-agent.service

# Check logs
journalctl -u leikwan-bond-sidecar-5-agent.service -f
```

### 3. Verify Sidecar Configuration

On sidecar 5, check that uplinks are created:

```bash
# Check sidecar uplink state
cat /var/lib/leikwan-sidecar-5/slave-sidecar-uplink-state.json

# Check WireGuard interfaces
wg show

# Should see lw-sc-5-* interfaces
# Example: lw-sc-5-53 for forward 47, node 12

# Check wstunnel backend services
systemctl list-units | grep leikwan-cf-slave

# Should see services like: leikwan-cf-slave-lw-sc-5-53.service

# Check CF metadata
cat /var/lib/leikwan-sidecar-5/cf-managed.json | jq '.[] | select(.backend_type == "slave_transit_cf_wg")'

# Check CF routes
cat /var/lib/leikwan-sidecar-5/cf-routes-2053.json | jq '.routes[] | select(.service_name | startswith("leikwan-cf-slave"))'
```

### 4. Verify Connectivity from Slave

On sh36 slave (47.116.11.144):

```bash
# Check WireGuard interface
wg show lwf47n12

# Should show:
# - peer: <sidecar public key>
# - endpoint: (may be empty if using wstunnel)
# - latest handshake: should be recent (<2 minutes)
# - transfer: should show both sent AND received bytes

# Ping sidecar through WireGuard
ping -c 5 10.203.201.175

# Should get replies

# Check preconnect relay
systemctl status leikwan-preconnect-2-500.service

# Check logs
journalctl -u leikwan-preconnect-2-500.service -n 50
```

### 5. Verify End-to-End

From a client using forward 47:

```bash
# Test connectivity
curl -v http://your-target-through-forward-47

# Check client agent logs for MPTCP subflow establishment
journalctl -u leikwan-client-agent.service | grep -i mptcp
```

## Rollback Plan

If issues occur:

### Rollback Panel

```bash
cd /var/www/leikwan-panel
git checkout master
php artisan cache:clear
php artisan config:clear
```

### Rollback Sidecar Agent

```bash
# On sidecar 5
cp /usr/local/bin/leikwan-sidecar-agent.py.backup /usr/local/bin/leikwan-sidecar-agent.py
systemctl restart leikwan-bond-sidecar-5-agent.service
```

## Expected Results

After deployment:

1. **Sidecar agent creates WireGuard interfaces** for each slave endpoint
   - Interface names: `lw-sc-5-0`, `lw-sc-5-1`, etc.
   - Listen on stable ports (e.g., 16856 for forward 47, node 12)

2. **Sidecar agent creates wstunnel backends** for CF mode slaves
   - Service names: `leikwan-cf-slave-lw-sc-5-*`
   - Listen on ports 26000-26099
   - Forward UDP to WireGuard listen ports

3. **CF mux routes traffic** to backends based on path prefix
   - Path: `content` (or `content/...`)
   - Target: `127.0.0.1:26000+`

4. **Slave WireGuard establishes handshake** with sidecar
   - Bidirectional traffic (both sent and received > 0)
   - Fresh handshake (<2 minutes)

5. **Preconnect relay connects** to sidecar MPTCP listener
   - TCP connection established
   - MPTCP subflows created

6. **Client traffic flows** through slave preconnect relay
   - Client → Slave relay (plain TCP)
   - Slave → Sidecar (MPTCP over WireGuard)
   - Sidecar → Target

## Monitoring

After deployment, monitor:

1. **Sidecar agent logs**:
   ```bash
   journalctl -u leikwan-bond-sidecar-5-agent.service -f
   ```

2. **WireGuard handshakes**:
   ```bash
   watch -n 5 'wg show | grep -A 5 "lw-sc-5"'
   ```

3. **Wstunnel backend logs**:
   ```bash
   journalctl -u leikwan-cf-slave-lw-sc-5-53.service -f
   ```

4. **Slave preconnect relay logs**:
   ```bash
   # On sh36
   journalctl -u leikwan-preconnect-2-500.service -f
   ```

## Troubleshooting

### Issue: No uplinks created on sidecar

**Check:**
- Panel is sending uplinks: Check sidecar agent heartbeat response
- Forwards have `runtime_preference` set to MPTCP
- Slave endpoints have `wireguard_public_key` in agent_config_json

### Issue: Wstunnel backend not created

**Check:**
- Uplink has `cf_path_prefix`, `cf_target_host`, `cf_target_port` fields
- Slave `wireguard_outer_transport` is set to `wstunnel_udp`
- Backend port allocation succeeded (26000-26099 range)

### Issue: WireGuard handshake fails

**Check:**
- Slave wstunnel client is running and connecting
- Slave wstunnel target is correct (sidecar IP, not CF IP)
- Path prefix matches between slave and sidecar
- CF mux is routing to correct backend

### Issue: Preconnect relay can't connect

**Check:**
- WireGuard tunnel is established (bidirectional traffic)
- Sidecar MPTCP listener is running
- Firewall allows traffic on MPTCP listen port
- Slave has correct sidecar address in WireGuard config

## Success Criteria

✅ Sidecar creates WireGuard interfaces for slave endpoints  
✅ Sidecar creates wstunnel backends for CF mode slaves  
✅ WireGuard handshake established (bidirectional traffic)  
✅ Ping from slave to sidecar succeeds  
✅ Preconnect relay connects to sidecar MPTCP listener  
✅ Client traffic flows through slave preconnect relay  
✅ No packet loss on slave-to-sidecar WireGuard tunnel  

## Next Steps After Deployment

1. Monitor stability for 24 hours
2. Test lw-cfsr-* (client-as-a-slave) feature
3. Fix slave agent wstunnel configuration (Priority 3)
4. Roll out to other slaves and sidecars
