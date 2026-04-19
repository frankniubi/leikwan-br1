# BOND_OPERATIONS

This document captures the current production `bond` operating model for LeiKwan Bridge.

It has two jobs:

1. Preserve the Linux production reference path that is already shipping today.
2. Explain how a Windows app should integrate with that same contract, using the Linux `leikwan-bond` runtime as the reference prototype.

This is an operations and integration document, not a product spec. It describes what exists, what must stay true, and where the current Windows code is still behind production.

## Scope

This document is written against the current `forward 26` production path:

- mode: `bond`
- listen port: `11024`
- ingress mode: `plain_tcp_mux`
- topology: `client -> slave -> sidecar`

It does **not** describe a direct `client -> sidecar` topology.

It does **not** describe a UDP-capable production path for `plain_tcp_mux`.

## Hard Invariants

These are the rules current production depends on:

- Keep the topology `client -> slave -> sidecar`.
- Do not bypass slaves and connect the client directly to the sidecar.
- Bond ingress and MPTCP ingress on slave public hosts must stay within `7000-9000`.
- For the current production `bond` path, slaves are ingress/relay nodes, not bond aggregators.
- Sidecar is the point where all legs reconverge before traffic reaches the target.
- Current `plain_tcp_mux` production usage is TCP-focused. Do not claim UDP support from `11024` validation.

## End-to-End Data Path

The current `forward 26` TCP data path is:

```text
local app
  -> 127.0.0.1:11024 (`leikwan-bond client`)
  -> 5 local leg listeners (`127.0.0.1:20962-20966`)
  -> 5 ingress helpers (`leikwan-ingress client`)
  -> 5 slave public ingress sockets (`*:7001`, plain_tcp_mux)
  -> slave relay
  -> sidecar `leikwan-bond server :9000`
  -> target `193.227.109.82:5201`
```

Current live leg mapping for `forward 26`:

- `127.0.0.1:20962 -> 8.163.90.132:7001`
- `127.0.0.1:20963 -> 8.163.90.235:7001`
- `127.0.0.1:20964 -> 8.163.87.42:7001`
- `127.0.0.1:20965 -> 8.163.69.176:7001`
- `127.0.0.1:20966 -> 8.163.71.73:7001`

What is important here:

- The `bond` protocol runs end to end between client and sidecar.
- Each slave only carries one leg stream.
- Sidecar sees one TCP connection per accepted leg.
- If 5 legs are up, sidecar should see 5 simultaneous connections from the slave hosts.

## What “Bond Aggregation” Means Here

In the current system, aggregation is not happening at the slave layer.

The actual aggregation behaviors live in `wstunnel-main/leikwan-bond/src/session.rs`:

- stream open / open-ack
- sticky -> warmup -> bulk striping
- retransmit selection
- pending window management
- fin / ack / reset handling
- transport session lifecycle

Practical interpretation:

- Slaves are transport carriers.
- `leikwan-bond client` is the sender-side scheduler.
- `leikwan-bond server` is the receiver-side reconvergence point.

## Current Throughput Interpretation

The current production pattern on 5 legs is:

- first second can burst to roughly `480-600+ Mbit/s`
- steady-state typically falls back into the `330-410 Mbit/s` range

What this means operationally:

- This is not evidence that 5 legs never formed.
- This is not evidence that sidecar is underpowered.
- This is not evidence that `bond` never entered `Warmup` or `Bulk`.

It is much closer to:

- all 5 legs participate
- the current 5-leg pool's steady-state total capacity is in the `300-400 Mbit/s` band
- tuning inside `bond` can still fix lifecycle bugs, but it probably will not magically turn this exact 5-leg set into stable `500+ Mbit/s`

If the goal is stable `500+ Mbit/s`, the most likely high-leverage levers are:

- stronger slaves
- more slaves
- different mixed-region / mixed-network-leg combinations

## Panel Contract

The panel is the source of truth for client runtime configuration.

The key entry point is:

- `master/src/Support/ProvisioningService.php`
  - `buildClientConfig(...)`

For `bond` forwards, the panel is responsible for producing:

1. The final sidecar target for bond
2. The set of slave endpoints
3. The ingress protocol details for those endpoints
4. The `bonding` block consumed by client runtime builders

### Required Forward-Level Fields

The current production `bond` contract depends on these forward-level ideas:

- `client_mode = bond`
- `bond_ingress_mode = plain_tcp_mux`
- `listen_port`
- `bond_sidecar_point_id`
- resolved `bond_remote_host`
- resolved `bond_remote_port`

For point-backed forwards, panel resolves sidecar host/port from the selected sidecar point.

For current production point `4`:

- bond sidecar host: `138.252.162.120`
- bond sidecar port: `9000`

### Required Endpoint-Level Fields

Each endpoint used by a production bond client must carry enough information to start one slave leg.

At minimum, the runtime layer needs:

- slave host
- slave port
- `route_key`
- `ingress_auth_token`
- whether the ingress protocol is `plain_tcp_mux`

In current Linux production, this information is ultimately consumed by `client-agent/install_forward_runtime.py`.

### Required `bonding` Block Fields

The client runtime also needs the `bonding` block. The most important fields are:

- `remote_target`
- `session_token`
- `udp_session_token`
- `ingress_mode`
- `route_key`
- `chunk_size`
- `max_window_bytes`
- `retransmit_ms`
- `connect_timeout_ms`
- `join_timeout_ms`
- `interactive_chunk_bytes`
- `latency_guard_bytes`
- `interactive_rtt_slack_ms`
- `warmup_trigger_bytes`
- `warmup_slice_bytes`
- `warmup_leg_delay_ms`
- `bulk_promote_bytes`
- `leg_cooldown_ms`

If these fields drift between panel and client implementations, runtime behavior drifts.

## Linux Client Reference Implementation

The current Linux truth is:

- configuration source:
  - `master/src/Support/ProvisioningService.php`
- runtime materialization:
  - `client-agent/install_forward_runtime.py`
- runtime behavior:
  - `wstunnel-main/leikwan-bond/src/main.rs`
  - `wstunnel-main/leikwan-bond/src/session.rs`

### What `install_forward_runtime.py` Does for Bond

For `bond + plain_tcp_mux`, Linux client runtime generation does the following:

1. Compute deterministic local leg ports
2. Start one ingress helper per slave leg
3. Wait for those local leg listeners to become ready
4. Start `leikwan-bond client` above them

The ingress helper shape is:

```bash
/usr/local/bin/leikwan-ingress client \
  --listen 127.0.0.1:<leg_port> \
  --remote <slave_host>:7001 \
  --route-key <route_key> \
  --auth-token <ingress_auth_token>
```

The bond runtime shape is:

```bash
/usr/local/bin/leikwan-bond client \
  --protocol tcp \
  --listen 0.0.0.0:<listen_port> \
  --legs 'tcp://127.0.0.1:<leg1>?priority=10,...' \
  --session-token '<session_token>' \
  --chunk-size 131072 \
  --max-window-bytes 16777216 \
  --retransmit-ms 300 \
  --connect-timeout-ms 3000 \
  --join-timeout-ms 400 \
  --interactive-chunk-bytes 16384 \
  --latency-guard-bytes 262144 \
  --interactive-rtt-slack-ms 30 \
  --warmup-trigger-bytes 65536 \
  --warmup-slice-bytes 65536 \
  --warmup-leg-delay-ms 80 \
  --bulk-promote-bytes 262144 \
  --leg-cooldown-ms 3000
```

### Current Production Caveat: UDP

For the current `plain_tcp_mux` production path:

- Linux runtime generation currently skips the UDP bond runtime
- `11024` validation is therefore a TCP-path validation

Do not translate current `11024` success into “UDP is already supported.”

## Slave Cooperation Model

The slave role is defined by:

- `slave-agent/slave_agent.py`

When the panel emits:

- `ingress_protocol = plain_tcp_mux`

the slave side is expected to:

1. Listen on a shared ingress port, currently `7001`
2. Accept a raw TCP leg stream
3. Validate the leg using:
   - `route_key`
   - `ingress_auth_token`
4. Forward that accepted leg to the configured backend target

In this model:

- one inbound leg creates one outbound TCP connection to sidecar
- slave does not interpret bond frames
- slave does not aggregate streams
- slave does not terminate the application protocol

The slave's job is intentionally simple.

## Sidecar Cooperation Model

The sidecar role is:

- accept one TCP bond leg per slave
- participate in transport/session lifecycle
- aggregate legs into the target stream
- forward to the target service

Current production sidecar service for point `4`:

- `leikwan-bond-sidecar-4.service`
- listens on `0.0.0.0:9000`

The sidecar is where you should expect to see:

- all production legs reconverge
- lifecycle logs for stream open, fin, ack, reset
- evidence that bulk striping really happened

## Build, Deploy, Rollback Baseline

Linux bond artifacts must be built on the panel host `10.0.10.196` using the bullseye baseline.

Do not use local WSL Linux ELF builds for production deployment.

Reference commands:

```bash
export HOME=/root
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3/wstunnel-main
/root/.cargo/bin/cargo test -p leikwan-bond
/root/.cargo/bin/cargo build --release -p leikwan-bond
cp target/release/leikwan-bond /www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/wstunnel/leikwan-bond_linux_amd64
```

Reference deploy order:

1. upload new binary to `/root/leikwan-bond.new`
2. stop service
3. move into `/usr/local/bin/leikwan-bond`
4. `chmod 755`
5. start service

Do not overwrite a running `/usr/local/bin/leikwan-bond` in place.

## Operations Verification Checklist

Client:

```bash
systemctl is-active leikwan-client-26.service
ss -ltnp | grep ':11024'
ps -ef | grep '[l]eikwan-bond client'
journalctl -u leikwan-client-26.service --no-pager -n 200
```

Sidecar:

```bash
systemctl is-active leikwan-bond-sidecar-4.service
ss -tnp | egrep ':9000'
journalctl -u leikwan-bond-sidecar-4.service --no-pager -n 200
```

Slave:

```bash
systemctl is-active leikwan-slave-agent
systemctl is-active leikwan-tcp-mux-7001
ss -ltn | grep :7001
```

## Windows App Integration Overview

The Windows app should not invent a different bond model.

It should mirror the Linux production contract as closely as possible, using Linux `bond` behavior as the reference prototype.

### Reference Layers

Treat these as the current truth sources:

- panel contract:
  - `master/src/Support/ProvisioningService.php`
- Linux runtime builder:
  - `client-agent/install_forward_runtime.py`
- Linux runtime behavior:
  - `wstunnel-main/leikwan-bond/src/main.rs`
  - `wstunnel-main/leikwan-bond/src/session.rs`

Current Windows-oriented code in this repo:

- .NET desktop path:
  - `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondRuntimeBuilder.cs`
  - `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- older Tauri prototype:
  - `wstunnel-main/leikwan-client-win/src-tauri/src/bond.rs`
  - `wstunnel-main/leikwan-client-win/src-tauri/src/commands.rs`

These Windows paths are useful references, but they are not yet fully aligned with current Linux production.

### Minimum Windows App Responsibilities

A production-ready Windows app for current bond mode should:

1. Authenticate with panel and fetch the same forward JSON the Linux client uses
2. Normalize mode selection the same way the Linux side does
3. For `bond + plain_tcp_mux`, start one ingress helper per endpoint
4. Wait until all required local leg listeners are ready
5. Start `leikwan-bond.exe client` above those legs
6. Supervise all child processes
7. Persist enough applied state to restart / reconnect cleanly

### Required Windows Runtime Shape

For the current production `bond` path, Windows should conceptually do the same thing Linux does:

```text
Windows app supervisor
  -> N ingress helpers (one per slave leg)
  -> one `leikwan-bond.exe client`
```

That means:

- one ingress helper per endpoint
- one local leg listen port per helper
- one shared bond listen port exposed to the local app

The Windows app should treat the Linux launch sequence as the canonical order:

1. ingress helpers first
2. wait for local leg readiness
3. bond runtime second

### Most Important Gap in Current Windows Code

Current `.NET` Windows bond builder still models a legacy path:

- `BondRuntimeBuilder.cs` currently renders `wstunnel` legs
- current Linux production `forward 26` does **not** use `wstunnel` for bond ingress
- it uses `leikwan-ingress client` + `plain_tcp_mux`

This is the single most important architectural gap.

If Windows app work starts from the current `.NET` builder without correcting this, it will diverge from production immediately.

### Config Model Gaps to Close

Current Windows config models are incomplete relative to production bond ingress.

Examples:

- `LeikwanBridge.Desktop/.../EndpointConfig.cs` currently carries only:
  - `host`
  - `port`
  - `cdn_host`
  - `supports_quic_leg`
- but production `plain_tcp_mux` needs ingress metadata such as:
  - `route_key`
  - `ingress_auth_token`
  - ingress protocol semantics

Similarly, current Windows `BondingConfig` does not yet represent the full production bond block:

- no explicit `ingress_mode`
- no warmup/bulk tuning fields
- no leg cooldown field

### Recommended Windows Integration Contract

For current production compatibility, the Windows app should consume the panel contract with these assumptions:

- if `mode == bond`
- and `bonding.ingress_mode == plain_tcp_mux`

then the app should:

1. create one local leg port per endpoint
2. start one ingress helper per endpoint toward `<slave_host>:<slave_port>`
3. pass `route_key` and `ingress_auth_token` into that helper
4. render bond `--legs` as local TCP legs only
5. pass the same bond tuning parameters Linux uses

### `leikwan-bond.exe` Argument Compatibility

Windows launcher code should pass the same core flags Linux production uses:

- `--protocol`
- `--listen`
- `--legs`
- `--session-token`
- `--chunk-size`
- `--max-window-bytes`
- `--retransmit-ms`
- `--connect-timeout-ms`
- `--join-timeout-ms`
- `--interactive-chunk-bytes`
- `--latency-guard-bytes`
- `--interactive-rtt-slack-ms`
- `--warmup-trigger-bytes`
- `--warmup-slice-bytes`
- `--warmup-leg-delay-ms`
- `--bulk-promote-bytes`
- `--leg-cooldown-ms`

Current Tauri `bond.rs` and `.NET` builder code do not yet pass the full current set.

### MPTCP on Windows

Current `.NET` Windows runtime manager already falls back from `mptcp` to `bond`.

That is a reasonable short-term behavior.

But this document is only describing the current `bond` integration path. Do not mix MPTCP design work into this file.

### UDP on Windows

For the current production `plain_tcp_mux` path:

- UDP forwarding is not yet supported

The Windows app overview should therefore assume:

- bond TCP first
- UDP later

Do not design Windows UDP support by guessing. It should follow the Linux production contract only after Linux UDP on `plain_tcp_mux` is actually supported end to end.

## Practical Guidance for Future Windows Work

If someone resumes Windows app implementation later, the safest order is:

1. Align config models with current panel JSON
2. Replace legacy `wstunnel`-based bond ingress with per-leg ingress helpers
3. Align the bond CLI flags with Linux production
4. Only then work on process supervision, polish, and UX

In other words:

- contract first
- runtime shape second
- UX third

That is the shortest path to a Windows app that actually matches production bond behavior.

## Forward 26 UDP UoT sidepath hotfix

This hotfix is local-only. It does not require panel schema changes.

Apply the `udp_uot_sidepath` block to:

- client `/etc/leikwan-client-agent/config.json`
- published Guangzhou slave `/etc/leikwan-slave-agent/config.json`
- sidecar point config `/etc/leikwan-sidecar/config.json`

Required values:

    {
      "udp_uot_sidepath": {
        "enabled_forward_ids": [26],
        "shadow_route_suffix": ".uot",
        "sidecar_port": 8999,
        "reorder_window_packets": 4096,
        "reorder_window_bytes": 16777216,
        "reorder_timeout_ms": 25
      }
    }

Restart sequence:

    systemctl restart leikwan-bond-sidecar-4
    systemctl restart leikwan-slave-agent
    systemctl restart leikwan-client-agent
    systemctl restart leikwan-client-26
    systemctl restart leikwan-client-26-udp-uot

Rollback:

    systemctl stop leikwan-client-26-udp-uot
    systemctl disable leikwan-client-26-udp-uot
    systemctl stop leikwan-bond-sidecar-4-udp-uot
    systemctl disable leikwan-bond-sidecar-4-udp-uot
