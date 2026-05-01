# MPTCP Kernel 6.1 Optimization, Bond Enhancement & Windows Client Design

Date: 2026-04-12

Status: approved design

## Goal

1. **MPTCP on kernel 6.1**: Achieve stable 500Mbps+ single-flow throughput (up from 333 avg / 473 peak) without upgrading the kernel.
2. **Bond enhancement**: Optimize bond aggregation performance so it's a viable high-performance alternative, not just a degraded fallback.
3. **Windows client**: Full-featured Tauri desktop app with GUI, system tray, WireGuard tunnel management, and bond-based multi-path aggregation.

## Constraints

- Production clients run kernel 6.1 — no kernel upgrade.
- Local WSL (Debian 13, kernel 6.6+) available for dev/test.
- Kernel 6.1 MPTCP has only 6 sysctls: `enabled`, `add_addr_timeout`, `checksum_enabled`, `allow_join_initial_addr_port`, `pm_type`, `stale_loss_cnt`.
- No `net.mptcp.scheduler` sysctl (requires 6.10+). Hardcoded linger-time scheduler.
- `splice(2)` read from MPTCP sockets uses `sock_splice_read()` fallback (kernel-internal copy, not true zero-copy). Write side is efficient via `sendpage`.

## Current Performance Bottlenecks

1. **Python relay** (dossier 8.3): user-space recv()+send() per byte crosses kernel/user boundary 4x per direction.
2. **Sidecar uses cubic** while client uses bbr — mismatched congestion control.
3. **Buffer sizes**: SO_SNDBUF/SO_RCVBUF at 4MB may be insufficient for 5-subflow BDP.
4. **`active_leg_limit = 2`** in bond: only 2 of 5 legs active, wasting bandwidth.
5. **`retransmit_ms = 700`** in bond: too slow for the WG overlay's stable latency.

---

## Part 1: MPTCP Kernel 6.1 Parameter Optimization

### 1.1 sysctl Tuning

Applied via `/etc/sysctl.d/90-leikwan-mptcp.conf` on both client and sidecar.

| Parameter | Current | Target | Rationale |
|-----------|---------|--------|-----------|
| `net.mptcp.stale_loss_cnt` | 2 | 2 (keep) | Already optimized |
| `net.mptcp.add_addr_timeout` | 120 | **5** | Faster ADD_ADDR retransmission on loss |
| `net.mptcp.allow_join_initial_addr_port` | client=1, sidecar=0 | keep | Sidecar behind NAT needs 0 |
| `net.ipv4.tcp_wmem` | system default | **4096 65536 16777216** | Multi-subflow needs larger send buffer to fill all paths |
| `net.ipv4.tcp_rmem` | system default | **4096 131072 16777216** | Accommodate cross-subflow out-of-order data |
| `net.core.wmem_max` | system default | **16777216** | Unlock SO_SNDBUF ceiling |
| `net.core.rmem_max` | system default | **16777216** | Unlock SO_RCVBUF ceiling |
| `net.ipv4.tcp_syn_retries` | 6 | **2** | Reduce MP_JOIN SYN retransmit wait |
| `net.ipv4.tcp_no_metrics_save` | 0 | **1** | Prevent cached RTT/cwnd from interfering |

### 1.2 Route-Level Tuning

| Parameter | Current | Target | Rationale |
|-----------|---------|--------|-----------|
| `initcwnd` | 40 | **80** | Larger initial window for faster multi-subflow ramp-up |
| `initrwnd` | 40 | **80** | Match sender's initial window |
| WireGuard MTU | 1382 | 1382 (keep) | Validated best |

### 1.3 Socket-Level Tuning

| Parameter | Current | Target | Rationale |
|-----------|---------|--------|-----------|
| `SO_SNDBUF` | 4MB | **8MB** | 5 legs x 200Mbps x 50ms RTT = 6.25MB BDP |
| `SO_RCVBUF` | 4MB | **8MB** | Match sender |
| `TCP_CONGESTION` | client=bbr, sidecar=**cubic** | **both bbr** | BBR is RTT-sensitive, better with linger-time scheduler |
| `TCP_NODELAY` | 1 | 1 (keep) | Relay scenario needs it |

### 1.4 New Environment Variables

Added to `.env`, delivered via `republish-mptcp.php`:

```
MPTCP_ADD_ADDR_TIMEOUT=5
MPTCP_TCP_WMEM_MAX=16777216
MPTCP_TCP_RMEM_MAX=16777216
MPTCP_SOCKET_BUFFER_BYTES=8388608
MPTCP_SIDECAR_CONGESTION_CONTROL=bbr
```

### 1.5 Files Changed

- `client-agent/leikwan_mptcp_client.py`: SO_SNDBUF/SO_RCVBUF from config
- `sidecar-agent/leikwan_mptcp_sidecar.py`: TCP_CONGESTION default changed to bbr, buffer sizes from config
- `client-agent/install_forward_runtime.py`: pass new params to relay binary
- `master/src/Support/ProvisioningService.php`: emit new env vars in mptcp config
- `install/install-bond-sidecar.sh`: updated sysctl template
- Client/sidecar agent: apply sysctl on startup

---

## Part 2: Rust MPTCP Relay

Replace the Python relay (`leikwan_mptcp_client.py` / `leikwan_mptcp_sidecar.py`) with a Rust binary that uses `splice(2)` for data forwarding.

### 2.1 Architecture

```
Client side:
  [local app] --TCP--> [leikwan-mptcp-relay client] --MPTCP(262)--> [sidecar]
                              splice(2) via pipe

Sidecar side:
  [client MPTCP] --> [leikwan-mptcp-relay server] --TCP--> [target backend]
                              splice(2) via pipe
```

### 2.2 Binary Design

**Name:** `leikwan-mptcp-relay`

**Location:** `wstunnel-main/` Cargo workspace (alongside `leikwan-bond`)

**Modes:**
- `leikwan-mptcp-relay client --listen <host>:<port> --remote <sidecar_host>:<port> --session-token <token> ...`
- `leikwan-mptcp-relay server --listen <host>:<port> --token-secret <secret> ...`

### 2.3 Core Data Path: splice(2)

Per-connection, two pipes are created (forward + backward):

```
Forward:  splice(local_tcp_fd, pipe_wr, 1MB) -> splice(pipe_rd, mptcp_fd, 1MB)
Backward: splice(mptcp_fd, pipe_wr, 1MB) -> splice(pipe_rd, local_tcp_fd, 1MB)

Pipe buffer: fcntl(F_SETPIPE_SZ, 1048576)  // 1MB
Splice flags: SPLICE_F_MOVE | SPLICE_F_NONBLOCK
```

Note: On kernel 6.1, `splice()` reading from an MPTCP socket falls back to `sock_splice_read()` (kernel-internal copy, not true zero-copy). The write side uses `sendpage` efficiently. This is still ~30-40% faster than userspace recv()+send() because it eliminates 2 user/kernel boundary crossings.

### 2.4 Features

| Feature | Description |
|---------|-------------|
| TCP listener | Local port, accept application connections |
| MPTCP connect | `socket(AF_INET, SOCK_STREAM, 262)` to sidecar |
| Bidirectional splice | Pipe buffer 1MB, splice in loop |
| Session token | JWT-HMAC verification (reuse from leikwan-bond) |
| UDP forwarding | Length-prefixed datagram over MPTCP stream (stream_compat mode) |
| MPTCP endpoint management | `ip mptcp endpoint` CLI or netlink |
| Health reporting | Byte counts, subflow count, connection state -> stats JSON |
| Graceful shutdown | Half-close handling, idle timeout |
| TCP fallback | Optional `allow_tcp_fallback` for non-MPTCP kernels |

### 2.5 MPTCP Socket Configuration (in Rust)

```rust
// Socket creation
let fd = unsafe { libc::socket(AF_INET, SOCK_STREAM, 262) }; // IPPROTO_MPTCP

// Congestion control
setsockopt(fd, IPPROTO_TCP, TCP_CONGESTION, b"bbr");

// Buffer sizes
setsockopt(fd, SOL_SOCKET, SO_SNDBUF, 8 * 1024 * 1024);
setsockopt(fd, SOL_SOCKET, SO_RCVBUF, 8 * 1024 * 1024);

// No delay
setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, 1);
```

### 2.6 MPTCP Endpoint Management

Move from Python to Rust. Implemented via:
- Option A: Shell out to `ip mptcp endpoint add/delete/show` (simpler, current approach)
- Option B: Netlink API directly (more robust, no process spawn)

Recommend Option A initially for consistency with current approach, migrate to B later.

### 2.7 Integration Points

| Component | Change |
|-----------|--------|
| `install_forward_runtime.py` | mptcp mode launches `leikwan-mptcp-relay` instead of Python |
| `client_agent.py` | Download `mptcp-relay` artifact, manage its lifecycle |
| `sidecar_agent.py` | Same — launch relay server instead of Python |
| `ProvisioningService.php` | Emit relay binary params in mptcp config |
| `versions.json` | Add `mptcp-relay` artifact entry |
| Python runtime files | Preserved but not used for mptcp mode (backup reference) |

---

## Part 3: Bond Aggregation Performance Optimization

### 3.1 Parameter Tuning

| Parameter | Current | Target | Rationale |
|-----------|---------|--------|-----------|
| `chunk_size` | 65536 (64KB) | **131072** (128KB) | Reduce scheduling overhead per chunk |
| `max_window_bytes` | 8388608 (8MB) | **16777216** (16MB) | 5-leg high-BDP needs larger window |
| `retransmit_ms` | 700 | **300** | WG overlay latency is stable, 300ms is sufficient |
| `active_leg_limit` | 2 | **0** (unlimited) | Use all available legs, don't artificially limit |

### 3.2 QUIC Leg Optimization

1. **QUIC 0-RTT session resumption**: Enable if leikwan-bond supports it, reduce leg rebuild latency.
2. **QUIC congestion control**: Ensure QUIC legs use BBR (check if leikwan-bond exposes this parameter).
3. **Priority differentiation**: Low-latency legs get higher priority for latency-sensitive traffic.

### 3.3 Bond Scheduling Improvements

Source is in `wstunnel-main/` Cargo workspace (Rust). Changes require recompilation and artifact update.

1. **Adaptive chunk sizing**: Dynamically adjust per-leg chunk size based on each leg's RTT. High-bandwidth low-latency legs get larger chunks, high-latency legs get smaller chunks. Exposed as `--adaptive-chunk` CLI flag.
2. **Dead leg fast detection**: "N consecutive unacked chunks -> mark degraded" instead of waiting full `retransmit_ms`. Exposed as `--dead-leg-threshold <N>` CLI flag (default: 3).

### 3.4 Panel Configuration Extension

New defaults in `ProvisioningService.php` bond config:

```php
'bond_chunk_size' => env('BOND_CHUNK_SIZE', 131072),
'bond_max_window_bytes' => env('BOND_MAX_WINDOW_BYTES', 16777216),
'bond_retransmit_ms' => env('BOND_RETRANSMIT_MS', 300),
'bond_active_leg_limit' => env('BOND_ACTIVE_LEG_LIMIT', 0),
```

### 3.5 Files Changed

- `master/src/Support/ProvisioningService.php`: new bond default parameters
- `client-agent/install_forward_runtime.py`: pass updated bond params
- `master/.env.example`: document new BOND_* environment variables

---

## Part 4: Bond Enhancement for MPTCP-Unavailable Scenarios

### 4.1 auto-mode Optimized Degradation

When `resolveEffectiveClientMode()` degrades from mptcp to bond, pass optimized parameters:

```
if storedMode = 'auto':
    if sidecarReady:
        if clientSupportsMptcp:
            -> 'mptcp' (Rust relay + full optimization)
        else:
            -> 'bond' with enhanced params:
                - active_leg_limit = 0
                - chunk_size = 131072
                - retransmit_ms = 300
                - use all available legs
```

### 4.2 Bond-Only Scenario Enhancements

For Windows/Android clients where bond is the primary mode:

1. **WSS legs as active**: When QUIC is unavailable, WSS legs should be active (not standby). Change `wss_standby_enabled` logic in `install_forward_runtime.py` to detect QUIC availability and auto-promote WSS to active.
2. **Bond over WireGuard**: Even without MPTCP, bond can run over WG overlay for encryption + MTU control. This is already supported by the underlay infrastructure; needs explicit documentation and auto-mode awareness.

### 4.3 Panel UI Enhancement

When forward's `client_mode = 'auto'` resolves to bond, display:
- Current effective mode (bond)
- Degradation reason (client doesn't support MPTCP)
- Whether optimized parameters are applied

### 4.4 Files Changed

- `master/src/Support/ProvisioningService.php`: enhanced bond params in degradation path
- `master/src/Controllers/TenantController.php`: display effective mode info

---

## Part 5: Windows Tauri Client

Full-featured Windows desktop client built with Tauri (Rust backend + Web UI frontend).

### 5.1 Architecture

```
┌──────────────────────────────────────────────┐
│  Tauri App (leikwan-client-win)              │
│  ┌─────────────────┐  ┌──────────────────┐   │
│  │  Web UI Layer   │  │  Rust Backend    │   │
│  │  (HTML/CSS/JS)  │←→│  (Tauri Core)    │   │
│  │  - Login page   │  │  - Panel API     │   │
│  │  - Forward list │  │  - Config pull   │   │
│  │  - Live stats   │  │  - Heartbeat     │   │
│  │  - Settings     │  │  - WG management │   │
│  └─────────────────┘  └───────┬──────────┘   │
│                               │              │
│               ┌───────────────┼──────┐       │
│               ↓               ↓      ↓       │
│     leikwan-bond       WireGuard   Stats     │
│     (child process)    (wintun)    JSON      │
└──────────────────────────────────────────────┘
```

### 5.2 Technology Stack

| Layer | Technology | Rationale |
|-------|-----------|-----------|
| App framework | Tauri 2.x | Rust backend shares Cargo workspace with leikwan-bond/mptcp-relay |
| Frontend | HTML/CSS/JS (vanilla or lightweight framework) | Small bundle, fast load |
| WireGuard | wintun driver (wireguard-windows official) | Windows kernel tunnel interface |
| Bond runtime | leikwan-bond (embedded child process) | Existing Rust binary, no MPTCP on Windows |
| System tray | tauri-plugin-autostart + system-tray API | Native Windows integration |

### 5.3 Features

| Feature | Description |
|---------|-------------|
| Account login | Authenticate against Panel API, store session token securely (Windows Credential Manager via `keyring` crate) |
| Config pull | Heartbeat to Panel every 30s, pull forward list + config (same API as Linux client-agent) |
| Forward management | List all forwards, one-click start/stop, show effective mode (bond/race/single) |
| Live status dashboard | Real-time bandwidth, latency, leg count, connection state per forward |
| System tray | Minimize to tray, quick-access menu (connect/disconnect, status summary) |
| Auto-start | Windows startup registration via registry or Task Scheduler |
| WireGuard tunnel | Install/manage wintun driver, create per-leg WG interfaces, route table management |
| Auto-update | Check Panel for new artifact versions, download + restart |
| Logging | Structured logs to `%APPDATA%\leikwan\logs\`, log viewer in UI |

### 5.4 Windows Connection Mode

Windows has no `IPPROTO_MPTCP`. The auto-mode resolver delivers `bond` with optimized parameters from Part 3/4:
- `active_leg_limit = 0` (use all available legs)
- `chunk_size = 131072`, `max_window_bytes = 16777216`, `retransmit_ms = 300`
- WireGuard overlay for encrypted multi-path transport
- QUIC legs preferred, TCP/WSS as fallback

### 5.5 Panel API Integration

The Windows client uses the same Panel HTTP API as the Linux client-agent:

| API | Purpose |
|-----|---------|
| `POST /api/client/login` | Authenticate, get API token |
| `GET /api/client/heartbeat` | Report status, check config version |
| `GET /api/client/config/{forward_id}` | Pull forward config |
| `POST /api/client/stats` | Upload per-forward stats |

If these APIs don't exist yet, they need to be added to the Panel. Currently the Linux client-agent uses a different heartbeat mechanism — this needs to be unified or the Windows client needs its own API surface.

### 5.6 WireGuard on Windows

- Use `wintun.dll` (official WireGuard Windows driver) for kernel-mode tunnel interfaces
- Rust bindings via `wintun` crate
- Per-leg WG interface creation: `lw-bond-{forward_id}-{leg_index}`
- Route table: `route add` via Windows `netsh` or Rust IP helper API
- MTU: 1382 (same as Linux)

### 5.7 Cargo Workspace Integration

```
wstunnel-main/
├── Cargo.toml          (workspace root)
├── leikwan-bond/       (existing)
├── leikwan-mptcp-relay/ (Part 2, new)
├── leikwan-client-win/  (Part 5, new — Tauri app)
│   ├── src-tauri/       (Rust backend)
│   │   ├── src/main.rs
│   │   └── Cargo.toml  (depends on shared crates)
│   └── src/             (Web UI)
│       ├── index.html
│       ├── styles.css
│       └── app.js
└── leikwan-common/      (new shared crate)
    ├── src/lib.rs
    ├── token.rs         (JWT-HMAC, from leikwan-bond)
    ├── config.rs        (forward config parsing)
    ├── stats.rs         (stats reporting)
    └── wireguard.rs     (WG config generation)
```

### 5.8 Files Changed / Created

- `wstunnel-main/leikwan-client-win/` — new Tauri app
- `wstunnel-main/leikwan-common/` — shared crate extracted from leikwan-bond
- `master/src/Support/ProvisioningService.php` — ensure Windows client config is emitted correctly
- `master/src/Controllers/ApiController.php` (or new) — client login/heartbeat/config APIs if not existing
- `versions.json` — add `client-win` artifact entry

---

## Performance Projections

| Metric | Current | After Part 1 | After Part 1+2 | Bond (After Part 3) |
|--------|---------|--------------|-----------------|---------------------|
| MPTCP avg throughput | 333 Mbps | ~400 Mbps | **500+ Mbps** | N/A |
| MPTCP peak throughput | 473 Mbps | ~500 Mbps | **600+ Mbps** | N/A |
| Bond throughput | ~250 Mbps (est.) | N/A | N/A | **400-500 Mbps** |
| Subflow join time | ~2s | ~1.5s | ~1s | N/A |
| Startup hole | 3-4s | ~2s | ~1s | N/A |

## Implementation Order

1. **Part 1** (Parameter optimization) — lowest risk, fastest to deploy, immediate benefit
2. **Part 3** (Bond parameter tuning) — config changes only, deploy alongside Part 1
3. **Part 2** (Rust relay) — the big lift, requires build + artifact pipeline
4. **Part 4** (Bond enhancement) — depends on Part 3 being validated
5. **Part 5** (Windows Tauri client) — can start in parallel with Part 2 (shared Cargo workspace); depends on Part 3 for bond optimizations and leikwan-common crate extraction

## Testing Plan

### Part 1 Validation
- [ ] iperf3 TCP reverse test on forward 20 (single-interface multi-peer)
- [ ] Verify all 5 subflows active with new buffer sizes
- [ ] Compare `nstat` MPTCP counters before/after (especially `MPTcpExtMPCapableFallbackSYNACK`)
- [ ] Verify sidecar bbr vs cubic throughput difference

### Part 2 Validation
- [ ] Rust relay iperf3 TCP reverse: target 500Mbps+ sustained
- [ ] Rust relay iperf3 TCP forward: verify symmetric performance
- [ ] UDP stream_compat: verify no regression
- [ ] Session token authentication: verify JWT validation
- [ ] Half-close handling: verify graceful shutdown
- [ ] Compare splice overhead: strace to verify splice syscalls, not read/write

### Part 3 Validation
- [ ] Bond mode iperf3 with active_leg_limit=0: verify all legs used
- [ ] Bond mode iperf3 with chunk_size=128KB: measure throughput improvement
- [ ] Bond mode leg failure test: verify 300ms retransmit is sufficient

### Part 4 Validation
- [ ] auto-mode degradation to bond: verify enhanced params applied
- [ ] Windows client bond test: verify WSS active legs

### Part 5 Validation
- [ ] Tauri app builds on Windows (cargo tauri build)
- [ ] Login flow: authenticate against Panel, persist token
- [ ] Config pull: forward list populates after login
- [ ] Bond connection: start forward, verify leikwan-bond child process runs
- [ ] WireGuard: wintun interface created, routes applied
- [ ] Live stats: bandwidth/latency update in real-time
- [ ] System tray: minimize, restore, quick menu
- [ ] Auto-start: verify startup registration works
- [ ] Multi-leg bond: verify all legs active on Windows
