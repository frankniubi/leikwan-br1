# Parameter Optimization Implementation Plan (Part 1 + Part 3 + Part 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Optimize MPTCP kernel 6.1 parameters and bond aggregation settings to push MPTCP toward 500Mbps and bond toward 400Mbps, without architectural changes.

**Architecture:** Pure config/parameter changes across 3 layers: (1) Linux sysctl for MPTCP and TCP tuning, (2) WireGuard route initcwnd/initrwnd, (3) Bond binary CLI parameters. All changes flow from Panel `.env` → ProvisioningService → agent config → runtime application.

**Tech Stack:** PHP (Panel), Python (agents), bash (sysctl), Linux iproute2 (route tuning)

---

### Task 1: Add new MPTCP/Bond environment variables to Panel

**Files:**
- Modify: `master/.env.example:52-63`
- Modify: `master/src/Support/ProvisioningService.php:3691-3735` (buildMptcpClientConfig)
- Modify: `master/src/Support/ProvisioningService.php:3632-3685` (buildBondClientConfig)

- [ ] **Step 1: Update `.env.example` with new variables**

```
# Bond tuning (applies to bond-mode forwards)
BOND_AGGREGATOR_HOST=
BOND_AGGREGATOR_PORT=
BOND_DEFAULT_REMOTE_PORT=9000
BOND_TOKEN_SECRET=
BOND_TOKEN_TTL_SECONDS=604800
BOND_CHUNK_SIZE=131072
BOND_MAX_WINDOW_BYTES=16777216
BOND_RETRANSMIT_MS=300
BOND_CONNECT_TIMEOUT_MS=3000
BOND_JOIN_TIMEOUT_MS=400
BOND_ACTIVE_LEG_LIMIT=0
# MPTCP tuning (applies to mptcp-mode forwards)
MPTCP_SCHEDULER=default
MPTCP_STALE_LOSS_CNT=2
MPTCP_INITCWND=80
MPTCP_INITRWND=80
MPTCP_CONNECT_TIMEOUT_MS=3000
MPTCP_ADD_ADDR_TIMEOUT=5
MPTCP_TCP_WMEM_MAX=16777216
MPTCP_TCP_RMEM_MAX=16777216
MPTCP_SOCKET_BUFFER_BYTES=8388608
MPTCP_TCP_CONGESTION_CONTROL=bbr
```

Replace lines 46-63 in `master/.env.example` with the above. Key changes:
- `BOND_CHUNK_SIZE`: 65536 → 131072
- `BOND_MAX_WINDOW_BYTES`: 8388608 → 16777216
- `BOND_RETRANSMIT_MS`: 700 → 300
- New: `BOND_ACTIVE_LEG_LIMIT=0` (0 = unlimited)
- `MPTCP_INITCWND`: 40 → 80
- `MPTCP_INITRWND`: 40 → 80
- Removed: `MPTCP_SUBFLOW_WARMUP_TIMEOUT` (no longer used)
- New: `MPTCP_ADD_ADDR_TIMEOUT`, `MPTCP_TCP_WMEM_MAX`, `MPTCP_TCP_RMEM_MAX`, `MPTCP_SOCKET_BUFFER_BYTES`, `MPTCP_TCP_CONGESTION_CONTROL`

- [ ] **Step 2: Add new fields to `buildMptcpClientConfig()` in ProvisioningService.php**

In `master/src/Support/ProvisioningService.php`, inside the return array of `buildMptcpClientConfig()` (around line 3720, after the `'allow_tcp_fallback'` line), add:

```php
'tcp_congestion_control' => (string) ($this->config->get('MPTCP_TCP_CONGESTION_CONTROL', 'bbr') ?? 'bbr'),
'socket_buffer_bytes' => max(1048576, (int) ($this->config->getInt('MPTCP_SOCKET_BUFFER_BYTES', 8388608) ?? 8388608)),
'initcwnd' => max(10, (int) ($this->config->getInt('MPTCP_INITCWND', 80) ?? 80)),
'initrwnd' => max(10, (int) ($this->config->getInt('MPTCP_INITRWND', 80) ?? 80)),
'add_addr_timeout' => max(1, (int) ($this->config->getInt('MPTCP_ADD_ADDR_TIMEOUT', 5) ?? 5)),
'tcp_wmem_max' => max(1048576, (int) ($this->config->getInt('MPTCP_TCP_WMEM_MAX', 16777216) ?? 16777216)),
'tcp_rmem_max' => max(1048576, (int) ($this->config->getInt('MPTCP_TCP_RMEM_MAX', 16777216) ?? 16777216)),
```

Insert these after line 3720 (`'allow_tcp_fallback' => ...`), before `'manage_mptcp_endpoints'`.

- [ ] **Step 3: Update `buildBondClientConfig()` defaults**

In `master/src/Support/ProvisioningService.php`, in `buildBondClientConfig()`, change the default values:

Line 3657: Change `64 * 1024` → `128 * 1024` (chunk_size default 131072)
Line 3658: Change `8 * 1024 * 1024` → `16 * 1024 * 1024` (max_window_bytes default 16MB)
Line 3659: Change `700` → `300` (retransmit_ms default 300)
Line 3674: Change `2` → `0` for `BOND_ACTIVE_LEG_LIMIT` default. Also update `max(1, ...)` to allow 0:
```php
'active_leg_limit' => max(0, (int) ($this->config->getInt('BOND_ACTIVE_LEG_LIMIT', 0) ?? 0)),
```
Line 3675: Same for `quic_active_leg_limit`:
```php
'quic_active_leg_limit' => max(0, (int) ($this->config->getInt('BOND_QUIC_ACTIVE_LEG_LIMIT', 0) ?? 0)),
```

- [ ] **Step 4: Commit**

```bash
git add master/.env.example master/src/Support/ProvisioningService.php
git commit -m "feat: update MPTCP and bond default parameters for performance optimization

- MPTCP: add tcp_congestion_control, socket_buffer_bytes, initcwnd/initrwnd, add_addr_timeout, tcp_wmem/rmem_max to config
- Bond: chunk_size 64K→128K, max_window 8M→16M, retransmit 700→300ms, active_leg_limit 2→0 (unlimited)"
```

---

### Task 2: Update sysctl template in install-bond-sidecar.sh

**Files:**
- Modify: `install/install-bond-sidecar.sh:18-24`

- [ ] **Step 1: Expand the sysctl configuration**

Replace the `enable_mptcp_runtime()` function (lines 18-24) in `install/install-bond-sidecar.sh` with:

```bash
enable_mptcp_runtime() {
  if running_kernel_has_mptcp; then
    sysctl -w net.mptcp.enabled=1 >/dev/null 2>&1 || true
    mkdir -p /etc/sysctl.d
    cat > /etc/sysctl.d/90-leikwan-mptcp.conf <<'SYSCTL'
net.mptcp.enabled=1
net.mptcp.add_addr_timeout=5
net.mptcp.stale_loss_cnt=2
net.ipv4.tcp_wmem=4096 65536 16777216
net.ipv4.tcp_rmem=4096 131072 16777216
net.core.wmem_max=16777216
net.core.rmem_max=16777216
net.ipv4.tcp_syn_retries=2
net.ipv4.tcp_no_metrics_save=1
SYSCTL
    sysctl --system >/dev/null 2>&1 || true
  fi
}
```

- [ ] **Step 2: Commit**

```bash
git add install/install-bond-sidecar.sh
git commit -m "feat: expand MPTCP sysctl tuning for kernel 6.1 performance

- add_addr_timeout 120→5, stale_loss_cnt=2
- tcp_wmem/rmem max 16MB, wmem_max/rmem_max 16MB
- tcp_syn_retries 6→2, tcp_no_metrics_save=1"
```

---

### Task 3: Apply sysctl from agent startup (client + sidecar)

**Files:**
- Modify: `client-agent/leikwan_mptcp_client.py:305-312` (configure_mptcp_policy)
- Modify: `sidecar-agent/leikwan_mptcp_sidecar.py` (configure_mptcp_policy equivalent)

- [ ] **Step 1: Add sysctl application to client's configure_mptcp_policy()**

In `client-agent/leikwan_mptcp_client.py`, inside `configure_mptcp_policy()` (after line 312 where `ip mptcp limits set` runs), add sysctl application:

```python
    # Apply MPTCP and TCP sysctls from config
    add_addr_timeout = int(config.get("add_addr_timeout", 5) or 5)
    tcp_wmem_max = int(config.get("tcp_wmem_max", 16777216) or 16777216)
    tcp_rmem_max = int(config.get("tcp_rmem_max", 16777216) or 16777216)
    sysctls = {
        "net.mptcp.add_addr_timeout": str(add_addr_timeout),
        "net.mptcp.stale_loss_cnt": "2",
        "net.ipv4.tcp_wmem": f"4096 65536 {tcp_wmem_max}",
        "net.ipv4.tcp_rmem": f"4096 131072 {tcp_rmem_max}",
        "net.core.wmem_max": str(tcp_wmem_max),
        "net.core.rmem_max": str(tcp_rmem_max),
        "net.ipv4.tcp_syn_retries": "2",
        "net.ipv4.tcp_no_metrics_save": "1",
    }
    for key, value in sysctls.items():
        run_command(["sysctl", "-w", f"{key}={value}"], check=False)
```

- [ ] **Step 2: Add same sysctl application to sidecar**

In `sidecar-agent/leikwan_mptcp_sidecar.py`, find the `configure_mptcp_policy()` function and add the same sysctl block after the `ip mptcp limits set` call. The code is identical to Step 1.

- [ ] **Step 3: Commit**

```bash
git add client-agent/leikwan_mptcp_client.py sidecar-agent/leikwan_mptcp_sidecar.py
git commit -m "feat: apply MPTCP/TCP sysctls from runtime config on both client and sidecar"
```

---

### Task 4: Add initcwnd/initrwnd to WireGuard route PostUp

**Files:**
- Modify: `client-agent/leikwan_mptcp_client.py:193` (route_lines.append PostUp)
- Modify: `sidecar-agent/leikwan_mptcp_sidecar.py:182` (same location)

- [ ] **Step 1: Update client WireGuard route to include initcwnd/initrwnd**

In `client-agent/leikwan_mptcp_client.py`, the function `build_wireguard_interface_config()` builds WG config. Find line 193:

```python
route_lines.append(f"PostUp = ip -4 route replace {route_target}/32 dev {interface_name} src {local_address}")
```

Replace with:

```python
initcwnd = int(config.get("initcwnd", 80) or 80)
initrwnd = int(config.get("initrwnd", 80) or 80)
route_lines.append(f"PostUp = ip -4 route replace {route_target}/32 dev {interface_name} src {local_address} initcwnd {initcwnd} initrwnd {initrwnd}")
```

Note: `config` must be accessible in this scope. Check the function signature — `build_wireguard_interface_config(config)` takes `config` as parameter, so it's available.

- [ ] **Step 2: Update sidecar WireGuard route to include initcwnd/initrwnd**

In `sidecar-agent/leikwan_mptcp_sidecar.py`, find line 182:

```python
route_lines.append(f"PostUp = ip -4 route replace {route_target}/32 dev {interface_name} src {local_address}")
```

Replace with the same pattern:

```python
initcwnd = int(config.get("initcwnd", 80) or 80)
initrwnd = int(config.get("initrwnd", 80) or 80)
route_lines.append(f"PostUp = ip -4 route replace {route_target}/32 dev {interface_name} src {local_address} initcwnd {initcwnd} initrwnd {initrwnd}")
```

- [ ] **Step 3: Commit**

```bash
git add client-agent/leikwan_mptcp_client.py sidecar-agent/leikwan_mptcp_sidecar.py
git commit -m "feat: add initcwnd/initrwnd to WireGuard overlay routes (80 default, configurable)"
```

---

### Task 5: Update socket buffer sizes to be configurable

**Files:**
- Modify: `client-agent/leikwan_mptcp_client.py:25` (STREAM_SOCKET_BUFFER_BYTES)
- Modify: `client-agent/leikwan_mptcp_client.py:63-75` (tune_socket)
- Modify: `sidecar-agent/leikwan_mptcp_sidecar.py:23` (STREAM_SOCKET_BUFFER_BYTES)
- Modify: `sidecar-agent/leikwan_mptcp_sidecar.py:61-73` (tune_socket)

- [ ] **Step 1: Make client socket buffer configurable**

In `client-agent/leikwan_mptcp_client.py`, change line 25:

```python
STREAM_SOCKET_BUFFER_BYTES = 4 * 1024 * 1024
```

to:

```python
DEFAULT_SOCKET_BUFFER_BYTES = 8 * 1024 * 1024
```

Then update `tune_socket()` to accept a buffer size parameter. Change lines 63-75:

```python
def tune_socket(sock: socket.socket | None, tcp_congestion_control: str | None = None, socket_buffer_bytes: int = 0) -> None:
    if sock is None:
        return
    buf_size = socket_buffer_bytes if socket_buffer_bytes > 0 else DEFAULT_SOCKET_BUFFER_BYTES
    with contextlib_suppress():
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_SNDBUF, buf_size)
    with contextlib_suppress():
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, buf_size)
    with contextlib_suppress():
        sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
    desired_cc = str(tcp_congestion_control or "").strip()
    if desired_cc != "" and hasattr(socket, "TCP_CONGESTION"):
        with contextlib_suppress():
            sock.setsockopt(socket.IPPROTO_TCP, socket.TCP_CONGESTION, desired_cc.encode("ascii"))
```

Then find all call sites of `tune_socket()` in the client file and pass `socket_buffer_bytes=int(config.get("socket_buffer_bytes", 0) or 0)` where config is available.

- [ ] **Step 2: Make sidecar socket buffer configurable**

Same changes in `sidecar-agent/leikwan_mptcp_sidecar.py`:

Change line 23:
```python
STREAM_SOCKET_BUFFER_BYTES = 4 * 1024 * 1024
```
to:
```python
DEFAULT_SOCKET_BUFFER_BYTES = 8 * 1024 * 1024
```

Update `tune_socket()` (lines 61-73) with same signature change as Step 1.

Update `create_server_socket()` (line 664) to pass the buffer size:
```python
def create_server_socket(host: str, port: int, allow_tcp_fallback: bool, tcp_congestion_control: str | None = None, socket_buffer_bytes: int = 0) -> socket.socket:
```
And inside, change the `tune_socket` call:
```python
tune_socket(sock, tcp_congestion_control, socket_buffer_bytes)
```

Update the caller at line 692:
```python
socket_buffer_bytes = int(config.get("socket_buffer_bytes", 0) or 0)
sock = create_server_socket(host, port, truthy(config.get("allow_tcp_fallback", False)), tcp_congestion_control, socket_buffer_bytes)
```

- [ ] **Step 3: Commit**

```bash
git add client-agent/leikwan_mptcp_client.py sidecar-agent/leikwan_mptcp_sidecar.py
git commit -m "feat: make socket buffer size configurable (default 8MB, was hardcoded 4MB)"
```

---

### Task 6: Pass new params through install_forward_runtime.py

**Files:**
- Modify: `client-agent/install_forward_runtime.py:345-367` (mptcp config_payload)
- Modify: `client-agent/install_forward_runtime.py:101-102` (active_leg_limit)

- [ ] **Step 1: Add new MPTCP params to config_payload**

In `client-agent/install_forward_runtime.py`, in the mptcp config_payload dict (around lines 345-367), add after the `"relay_idle_timeout_seconds"` line:

```python
"tcp_congestion_control": str(mptcp.get("tcp_congestion_control", "bbr") or "bbr"),
"socket_buffer_bytes": int_or_default(mptcp.get("socket_buffer_bytes"), 8388608),
"initcwnd": int_or_default(mptcp.get("initcwnd"), 80),
"initrwnd": int_or_default(mptcp.get("initrwnd"), 80),
"add_addr_timeout": int_or_default(mptcp.get("add_addr_timeout"), 5),
"tcp_wmem_max": int_or_default(mptcp.get("tcp_wmem_max"), 16777216),
"tcp_rmem_max": int_or_default(mptcp.get("tcp_rmem_max"), 16777216),
```

- [ ] **Step 2: Update active_leg_limit to allow 0 (unlimited)**

In `client-agent/install_forward_runtime.py`, change lines 101-102:

```python
def active_leg_limit(bond: dict, default: int = 2) -> int:
    return max(1, int_or_default(bond.get("active_leg_limit", bond.get("quic_active_leg_limit", default)), default))
```

to:

```python
def active_leg_limit(bond: dict, default: int = 0) -> int:
    value = int_or_default(bond.get("active_leg_limit", bond.get("quic_active_leg_limit", default)), default)
    return max(0, value)
```

Then update `select_active_quic_legs()` (lines 109-119) to handle 0 as unlimited:

```python
def select_active_quic_legs(endpoints: list[dict], bond: dict) -> list[str]:
    limit = active_leg_limit(bond)
    selected: list[str] = []
    for endpoint in endpoints:
        leg = render_quic_leg_for_endpoint(endpoint, bond)
        if leg is None:
            continue
        selected.append(leg)
        if limit > 0 and len(selected) >= limit:
            break
    return selected
```

The key change is line `if limit > 0 and len(selected) >= limit:` — when limit=0, no leg selection limit is applied.

- [ ] **Step 3: Commit**

```bash
git add client-agent/install_forward_runtime.py
git commit -m "feat: pass new MPTCP params to runtime config, allow unlimited active bond legs"
```

---

### Task 7: Fix sidecar congestion control default alignment

**Files:**
- Modify: `sidecar-agent/leikwan_mptcp_sidecar.py:688`

- [ ] **Step 1: Change sidecar runtime default from "cubic" to "bbr"**

In `sidecar-agent/leikwan_mptcp_sidecar.py`, line 688:

```python
tcp_congestion_control = str(config.get("tcp_congestion_control", "cubic") or "cubic").strip()
```

Change to:

```python
tcp_congestion_control = str(config.get("tcp_congestion_control", "bbr") or "bbr").strip()
```

This aligns the sidecar runtime fallback with the sidecar agent default (line 187 of sidecar_agent.py already defaults to "bbr") and the client default.

- [ ] **Step 2: Commit**

```bash
git add sidecar-agent/leikwan_mptcp_sidecar.py
git commit -m "fix: align sidecar congestion control default to bbr (was cubic fallback)"
```

---

### Task 8: Deploy and validate Part 1 (MPTCP parameters)

**Files:** None (deployment + testing)

- [ ] **Step 1: Upload Panel changes**

```bash
pscp -r master/ panel-server:/var/www/master/
```

- [ ] **Step 2: Upload agent changes**

Update `artifacts/versions.json` with incremented version numbers for client-agent and sidecar-agent, then upload artifacts.

- [ ] **Step 3: Trigger republish**

```bash
ssh panel-server 'php /var/www/master/cli/republish-mptcp.php'
```

- [ ] **Step 4: Validate MPTCP sysctl applied on client**

SSH to client and verify:
```bash
sysctl net.mptcp.add_addr_timeout    # expect: 5
sysctl net.ipv4.tcp_wmem             # expect: 4096 65536 16777216
sysctl net.ipv4.tcp_rmem             # expect: 4096 131072 16777216
sysctl net.core.wmem_max             # expect: 16777216
sysctl net.core.rmem_max             # expect: 16777216
```

- [ ] **Step 5: Validate initcwnd/initrwnd on WG routes**

```bash
ip route show dev lw-mptcp-20 | grep initcwnd  # expect: initcwnd 80 initrwnd 80
```

- [ ] **Step 6: Run iperf3 baseline test**

```bash
iperf3 -c 127.0.0.1 -p 11009 -R -t 10
```

Compare with previous baseline (333 Mbps avg). Target: ~400 Mbps with parameter changes alone.

- [ ] **Step 7: Check MPTCP counters**

```bash
nstat -az | grep -i mptcp
```

Check that `MPTcpExtMPCapableFallbackSYNACK` is not increasing rapidly.

---

### Task 9: Deploy and validate Part 3 (Bond parameters)

**Files:** None (deployment + testing)

- [ ] **Step 1: Update production .env with new bond defaults**

On Panel server, update `/var/www/master/.env`:
```
BOND_CHUNK_SIZE=131072
BOND_MAX_WINDOW_BYTES=16777216
BOND_RETRANSMIT_MS=300
BOND_ACTIVE_LEG_LIMIT=0
```

- [ ] **Step 2: Trigger republish for bond forwards**

```bash
ssh panel-server 'php /var/www/master/cli/republish-mptcp.php'
```

- [ ] **Step 3: Validate bond parameters on client**

Check the running `leikwan-bond` process arguments:
```bash
ps aux | grep leikwan-bond
```

Verify: `--chunk-size 131072`, `--max-window-bytes 16777216`, `--retransmit-ms 300`, no `--active-leg-limit` (or 0).

- [ ] **Step 4: Run bond iperf3 test**

Test a bond-mode forward and compare throughput with previous results.
