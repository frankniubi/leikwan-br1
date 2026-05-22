# Slave Pre-Connect MPTCP Leg Design

> **Goal:** Move the MPTCP-to-sidecar connection responsibility from the client to the slave. The slave pre-establishes a persistent, health-checked MPTCP tunnel to the sidecar and exposes a local TCP endpoint. The client only needs to reach the slave's local relay port — the slave guarantees the sidecar path is already alive.

---

## 1. Problem Statement

Today's architecture:

```
Client (WG) ──MPTCP──→ [slave WG transit] ──→ Sidecar (MPTCP listener)
```

The client must:
1. Wait for slave WG underlay to be fully provisioned
2. Open an MPTCP socket through the WG tunnel to the sidecar
3. Complete a handshake with session token

**Failure modes observed:**
- Slave WG interface not ready when client attempts connect (provisioning race)
- Transient systemd failures on slave leave WG interface half-configured
- WG peer drift: sidecar peer disappears from live interface but config is unchanged
- Client retries flood the provisioning queue when the underlay is broken
- Debugging is hard because failures manifest on the *client* but root cause is the *slave*

## 2. Proposed Architecture: Slave Pre-Connect

```
Client ──plain TCP──→ Slave local relay ──pre-established MPTCP──→ Sidecar
```

The slave takes ownership of the MPTCP session:

1. **Slave provisions WG underlay** (as today)
2. **Slave opens MPTCP to sidecar** and completes handshake — this happens at publish time, not at client request time
3. **Slave exposes a local TCP relay port** on its WG address (the address the client already routes to)
4. **Client connects to slave's relay port** via plain TCP through the WG tunnel — no MPTCP socket needed on client
5. **Slave splices/relays** client TCP ↔ pre-established MPTCP to sidecar

### 2.1 Key Benefits

| Before | After |
|--------|-------|
| Client must have MPTCP kernel support | Client only needs plain TCP |
| Client debugs connection failures | Slave owns and health-checks the tunnel |
| Race between provision and client start | Slave won't expose relay until tunnel is proven |
| Client-side MPTCP endpoint management | Not needed — slave handles it |
| One MPTCP stream per client connection | Slave can pool/multiplex connections |

### 2.2 Health Model

The slave runs a **pre-connect health loop**:

```
every 10s:
  if mptcp_tunnel is None or mptcp_tunnel.is_dead():
    mptcp_tunnel = connect_mptcp_to_sidecar()
    send_handshake(mptcp_tunnel)
    verify_sidecar_ack(mptcp_tunnel)
  update_health_status(ok=mptcp_tunnel.is_alive())
```

The relay port is only advertised as ready when the pre-connect tunnel is verified.

## 3. Data Flow (per-connection)

```
1. Client opens TCP to slave_wg_address:relay_port
2. Slave accepts → opens new MPTCP stream to sidecar (from pool or fresh)
3. Slave sends handshake on behalf of client (session_token, forward_id)
4. Slave relays bidirectionally: client TCP ↔ sidecar MPTCP
5. On client disconnect → close MPTCP stream (or return to pool)
```

## 4. Implementation Plan

### 4.1 New Component: `SlavePreConnectRelay` (Python, in slave_agent.py)

A lightweight TCP server that:
- Listens on the slave's WG address (e.g., `10.203.201.134:8898`)
- On each incoming TCP connection, opens MPTCP to sidecar
- Sends the session handshake
- Relays bidirectionally using asyncio

### 4.2 Changes to `slave_agent.py`

1. Add `SlavePreConnectRelay` class
2. In `apply_mptcp_underlay()`, after WG interface is up:
   - Start a systemd unit for the pre-connect relay
   - The relay validates sidecar reachability before going ready
3. Add health probe endpoint (HTTP `/healthz` on relay port+1 or via existing mux)
4. Report relay health in heartbeat to master

### 4.3 Changes to Client

1. In MPTCP mode with `slave_preconnect: true`:
   - Client connects to `slave_wg_address:relay_port` via **plain TCP** (not MPTCP)
   - No handshake needed from client (slave does it)
   - Fallback: if `slave_preconnect` is false or relay unreachable, use old direct MPTCP

### 4.4 Changes to Panel/Master

1. `ProvisioningService` adds `slave_preconnect_port` to the client config
2. The publish payload includes `relay_port` in `mptcp_underlay`
3. Client config gets `slave_relay_host` + `slave_relay_port` fields

### 4.5 Systemd Unit Template

```ini
[Unit]
Description=Leikwan MPTCP Pre-Connect Relay %i
After=wg-quick@%i.service
Wants=wg-quick@%i.service

[Service]
Type=simple
ExecStart=/usr/bin/python3 /var/lib/leikwan-slave/preconnect-relay.py \
    --config /var/lib/leikwan-slave/preconnect-%i.json
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

## 5. Backward Compatibility

- The pre-connect model is opt-in via `mptcp_underlay.slave_preconnect: true`
- If unset or false, the current pass-through transit model continues to work
- Client agent detects presence of `slave_relay_host`/`slave_relay_port` to pick mode
- Windows client can benefit immediately (no MPTCP kernel needed, just TCP to slave)

## 6. File Changes Summary

| File | Change |
|------|--------|
| `slave-agent/slave_agent.py` | Add `SlavePreConnectRelay` class + systemd unit render |
| `slave-agent/preconnect_relay.py` | New standalone relay script (systemd-managed) |
| `client-agent/leikwan_mptcp_client.py` | Add `slave_preconnect` mode in `open_remote_stream()` |
| `master/src/Support/ProvisioningService.php` | Add `slave_preconnect_port` to mptcp_underlay |
| `LeikwanBridge.Desktop/.../MptcpRuntimeBuilder.cs` | Prefer slave relay endpoint when available |

---

## 7. Risks and Mitigations

| Risk | Mitigation |
|------|-----------|
| Slave relay is single point of failure | Health check + fast failover to direct MPTCP |
| Extra hop adds latency | splice(2) on Linux, minimal user-space copy |
| Slave resource exhaustion from many connections | Connection pooling + per-forward limits |
| Sidecar session token rotation | Slave re-handshakes on token refresh from master |
