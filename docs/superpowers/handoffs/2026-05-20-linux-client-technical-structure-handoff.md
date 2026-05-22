# LeiKwan Br1 Linux Client Technical Structure Handoff

Date: 2026-05-20  
Audience: Windows and Android client engineering team  
Scope: technical architecture only. Linux has no GUI, so this describes the headless control-plane and data-plane structure that Windows/Android should use as the behavioral reference.

No credentials, tenant secrets, host passwords, or live access details are included here.

---

## 1. Executive summary

The Linux client is a **pull-based host agent plus generated per-forward runtimes**:

```text
Panel / master API
  -> leikwan-client-agent.service
  -> candidate selection + health policy + runtime renderer
  -> generated per-forward unit(s)
  -> local listener on the assigned listen_port
  -> one of: single / race / bond / mptcp data plane
  -> slave ingress leg(s)
  -> sidecar
  -> target service
```

The part to copy for Windows/Android is the **contract and state machine**, not Linux-only details such as systemd, nftables, `wg-quick`, `/etc`, or `/usr/local/bin`.

Core rule: **the panel is the source of truth**, but the client is allowed to make local runtime choices inside the panel-published candidate set, especially for latency-first selection, endpoint quarantine, self-leg decisions, and MPTCP-vs-bond fallback.

---

## 2. Key source files

```text
client-agent/client_agent.py                  # Main Linux client agent: pull/reconcile/health/heartbeat
client-agent/install_forward_runtime.py       # Forward JSON -> unit/script/config render logic
client-agent/leikwan_mptcp_client.py          # Python MPTCP client runtime + WireGuard underlay setup
client-agent/cfwg_latency_watchdog.py         # CF-WG latency watchdog integrated into client-agent loop
client-agent/leikwan_uot.py                   # UDP-over-TCP compatibility helper
client-agent/leikwan_uot_bond.py              # UDP sidepath merge/bond helper
client-agent/leikwan_rate_limit.py            # Local TCP/UDP token bucket limiter
client-agent/leikwan_netns.py                 # Optional Linux netns/NAT helper
client-agent/leikwan_port_bridge.py           # Optional local bridge helper

install/install-client-agent.sh               # Preferred Linux headless client-agent installer
install/client-agent-update.sh                # Self-update helper invoked by the agent
install/install-client.sh                     # Older per-forward/manual installer path
install/uninstall-client-agent.sh             # Agent uninstall
install/uninstall-client.sh                   # Per-forward runtime uninstall

master/src/Controllers/ClientAgentController.php
master/src/Support/ProvisioningService.php
master/src/Controllers/BaseController.php
master/src/Bootstrap.php

artifacts/versions.json                       # Published artifact manifest: versions, hashes, URLs
wstunnel-main/leikwan-bond/                   # Rust app-layer bond runtime
wstunnel-main/leikwan-ingress/                # Rust plain_tcp_mux ingress helper
wstunnel-main/leikwan-mptcp-relay/            # Rust MPTCP relay; preferred over Python when present
```

Current checkout anchors inspected for this handoff:

```text
client-agent/client_agent.py                  # AGENT_VERSION = 2026.05.20.1
client-agent/cfwg_latency_watchdog.py         # CFWG latency watchdog implementation
install/install-client-agent.sh               # INSTALLER_VERSION = 2026.05.20.1
master/src/Controllers/ClientAgentController.php
master/src/Support/ProvisioningService.php
artifacts/versions.json
```

---

## 3. Linux runtime roles

### 3.1 Panel / master

The panel owns inventory, assignments, entitlements, artifact metadata, and the published runtime contract.

Responsibilities:

- authenticate a client agent by bearer `agent_token`;
- decide which forwards are assigned to a client or client group;
- build forward configs from DB rows, sidecar point config, node endpoints, and forward combination policy;
- publish desired client artifact versions and hashes;
- receive heartbeat/runtime capability/usage/diagnostic state;
- ingest client-side bond leg reputation snapshots;
- expose app login/select endpoints for desktop/mobile-style clients.

Important routes registered by `master/src/Bootstrap.php`:

```text
POST /api/client/login
POST /api/client/select
GET  /api/client/configs/pull
POST /api/client/heartbeat
POST /api/client/diagnostics/result

GET  /artifacts/client-agent.py
GET  /artifacts/install-client-agent.sh
GET  /artifacts/client-agent-update.sh
GET  /artifacts/install-forward-runtime.py
GET  /artifacts/cfwg-latency-watchdog.py
```

### 3.2 Client agent

`client-agent/client_agent.py` is the host-level reconciler. It runs as:

```text
leikwan-client-agent.service
```

It does not normally proxy user traffic itself. It:

1. pulls desired config;
2. merges panel-managed client-agent config;
3. validates runtime dependencies;
4. selects candidates and primary/helper legs;
5. chooses `single`, `race`, `bond`, or `mptcp` runtime family;
6. renders per-forward unit/scripts/config through `install_forward_runtime.py`;
7. starts/restarts only changed per-forward services;
8. monitors runtime health;
9. runs the CF-WG latency watchdog when enabled;
10. posts heartbeat, usage, hashes, WireGuard public key, and reputation back to panel.

Default loop timing in current code:

```text
poll_interval_seconds         default 30
health_check_interval_seconds default 20
main sleep                    5 seconds
```

### 3.3 Per-forward runtime units

Each active forward becomes one primary runtime:

```text
/etc/systemd/system/leikwan-client-<forward_id>.service
/usr/local/bin/leikwan-client-start-<forward_id>.sh     # for non-trivial modes
/etc/leikwan/mptcp-forward-<forward_id>.json             # for MPTCP mode
```

Some modes may add auxiliary units, for example:

```text
leikwan-client-<forward_id>-udp-uot.service
```

For Windows/Android, copy this as a **supervisor model**:

```text
one persistent app/service process
  -> reconciles desired forwards
  -> creates one managed runtime object per forward
  -> tracks ownership of generated files/processes/state
  -> avoids restart churn when the contract did not change
```

Do not copy systemd literally; copy the lifecycle boundary.

---

## 4. Installation layout on Linux

### 4.1 Agent config

Installed at:

```text
/etc/leikwan-client-agent/config.json
```

Important keys:

```json
{
  "master_url": "https://panel.example",
  "artifact_base_url": "https://panel-or-mirror.example",
  "client_id": 123,
  "agent_token": "secret bearer token",
  "state_dir": "/var/lib/leikwan-client-agent",
  "bond_bin": "/usr/local/bin/leikwan-bond",
  "bond_ingress_bin": "/usr/local/bin/leikwan-ingress",
  "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
  "log_level": "INFO",
  "installer_version": "...",
  "installer_sha256": "..."
}
```

For Windows/Android, this maps to secure app-private storage plus a small runtime settings object. `agent_token` must be treated as a secret.

### 4.2 Installed runtime artifacts

The Linux client-agent installer downloads:

```text
/usr/local/bin/leikwan-client-agent.py
/usr/local/bin/cfwg_latency_watchdog.py
/usr/local/bin/leikwan-client-agent-update
/usr/local/bin/install_forward_runtime.py
/usr/local/bin/leikwan-rate-limit.py
/usr/local/bin/leikwan-mptcp-client.py
/usr/local/bin/leikwan-uot.py
/usr/local/bin/leikwan-uot-bond.py
/usr/local/bin/leikwan-ingress
/usr/local/bin/leikwan-bond
/var/lib/leikwan-client-agent/leikwan-mptcp-relay
```

The renderer also references `/usr/local/bin/wstunnel` for WSS/CF direct legs. Packaging for Windows/Android must audit any generated runtime shape that still expects `wstunnel`, even if the main Linux installer path focuses on `leikwan-bond`, `leikwan-ingress`, and MPTCP helpers.

### 4.3 Host prerequisites

Linux-specific prerequisites:

- Python 3;
- `systemd`;
- `curl`;
- `wireguard-tools`;
- optional MPTCP-capable Linux kernel;
- `iproute2` for MPTCP endpoint/interface policy;
- `nft` for traffic accounting.

The installer can enable MPTCP runtime when `/proc/sys/net/mptcp/enabled` exists and can optionally install a newer kernel on supported apt-based systems. Windows/Android should not assume native Linux MPTCP semantics.

---

## 5. Local state files

The Linux client stores durable state under:

```text
/var/lib/leikwan-client-agent
```

Important files:

```text
applied.json
config_pull_state.json
usage_state.json
agent_update_state.json
bond-endpoint-health.json
cfwg-latency-watchdog-state.json
runtime-refresh-request.json
wireguard/private.key
wireguard/public.key
cfwg-watchdog-pin/<forward_id>.json
leikwan-mptcp-relay
```

Runtime snapshots outside the state dir:

```text
/run/leikwan/bond-leg-health-forward-<forward_id>.json
/etc/leikwan/mptcp-forward-<forward_id>.json
/etc/wireguard/<interface>.conf
/etc/systemd/system/leikwan-client-<forward_id>.service
/usr/local/bin/leikwan-client-start-<forward_id>.sh
```

Meaning of key state files:

- `applied.json`
  - ownership table for per-forward units and managed paths;
  - stores published mode, chosen runtime mode, contract signature, rendered signature, and last update time;
  - prevents restart churn when only observation fields changed.
- `config_pull_state.json`
  - last pull status, success/error time, applied/no-op/invalid/inactive forward IDs, panel clock metadata.
- `bond-endpoint-health.json`
  - per-forward/per-endpoint health role, quality score, RTT/loss data, quarantine/probation state.
- `cfwg-latency-watchdog-state.json`
  - CF-WG latency watchdog best RTT, last restart, sample window, and last event.
- `runtime-refresh-request.json`
  - local trigger for forced runtime refresh of specific forwards.
- `wireguard/private.key` and `wireguard/public.key`
  - client self-leg / MPTCP underlay identity.

Windows/Android should maintain equivalent app-private state with an explicit schema and migration policy.

---

## 6. Control-plane contract

### 6.1 Authentication

Linux uses:

```http
Authorization: Bearer <agent_token>
User-Agent: Leikwan-Client-Agent/<AGENT_VERSION>
Accept: application/json
Content-Type: application/json
```

Windows/Android can obtain the same `agent_token` through:

```text
POST /api/client/login
POST /api/client/select
```

After selection, the app should use the same pull/heartbeat contract as Linux.

### 6.2 Pull config

Request:

```text
GET /api/client/configs/pull
Authorization: Bearer <agent_token>
```

Response shape:

```json
{
  "ok": true,
  "client": {
    "id": 123,
    "name": "client name",
    "display_host": "client display host",
    "group_id": 1,
    "diag_request_token": "...",
    "agent_config_json": {},
    "agent_update_request_token": "...",
    "desired_agent_version": "...",
    "desired_agent_sha256": "...",
    "desired_installer_version": "...",
    "desired_installer_sha256": "...",
    "desired_bond_version": "...",
    "desired_bond_sha256": "...",
    "desired_mptcp_runtime_version": "...",
    "desired_mptcp_runtime_sha256": "...",
    "desired_cfwg_watchdog_version": "...",
    "desired_cfwg_watchdog_sha256": "...",
    "online_device_limit": 1,
    "active_online_devices": 1,
    "current_device_id": "..."
  },
  "forwards": []
}
```

Panel-managed `agent_config_json` currently includes:

```text
master_url
artifact_base_url
state_dir
poll_interval_seconds
health_check_interval_seconds
log_level
udp_uot_sidepath
cfwg_latency_watchdog
```

The client should merge only whitelisted keys. Do not let arbitrary panel JSON mutate unrelated local app settings.

### 6.3 Heartbeat

Request:

```text
POST /api/client/heartbeat
Authorization: Bearer <agent_token>
```

Linux posts:

```json
{
  "agent_version": "2026.05.20.1",
  "usage_bytes_in": 0,
  "usage_bytes_out": 0,
  "supports_mptcp": true,
  "mptcp_capability": {},
  "kernel_release": "...",
  "installer_version": "...",
  "installer_sha256": "...",
  "install_forward_runtime_sha256": "...",
  "mptcp_client_sha256": "...",
  "cfwg_watchdog_sha256": "...",
  "wireguard_public_key": "...",
  "wireguard_listen_port": 0,
  "wireguard_fallback_tcp_port": 0,
  "clock_sync": {},
  "bond_leg_reputation": {
    "forwards": []
  }
}
```

Windows/Android should send analogous fields:

```text
app_version
runtime_engine_version/hash
platform capability flags
active forwards
bytes in/out
health/reputation snapshots
WireGuard/public underlay identity if applicable
clock/device metadata
```

### 6.4 Diagnostics result

When panel sets a new `diag_request_token`, Linux runs diagnostics and posts:

```text
POST /api/client/diagnostics/result
```

The app versions should preserve this pattern as the remote support hook.

---

## 7. Forward config contract

Each `forwards[]` entry is a complete desired runtime contract for one local port.

Important top-level fields:

```text
service_id
forward_id
client_token
service_name
listen_port
target
bond_sidecar_point_id
bond_sidecar_point_name
forward_combination_id
forward_combination_name
forward_combination_runtime_preference
forward_combination_self_leg_cf_transport
runtime_preference
self_leg_cf_transport
helper_group_cap
max_legs_used
desired_latency_ms
backend_mode
bond_remote_host
bond_remote_port
line_client_id
line_client_group_id
sni
path_prefix
mode                            # single, race, bond, mptcp
transport                       # tcp, udp, tcp+udp
service_status
speed_limit_mbps
bonding
mptcp
connection_policy
endpoints
candidate_pool
```

### 7.1 Endpoint fields

`endpoints[]` describes slave ingress endpoints.

Important fields:

```text
endpoint_id
node
host
port
cdn_host
priority
weight
health_status
supports_quic_leg
origin_group_key
last_chain_latency_ms
delivered_ewma
ingress_protocol
ingress_auth_token
```

`endpoint_id` must remain stable and visible in logs/state because it correlates health, quarantine, latency, and rendered legs.

### 7.2 Candidate pool fields

`candidate_pool[]` is the newer selection layer. It may include:

```text
candidate_kind = self_leg | rented_group_leg | donated_slave_leg
endpoint_id
runtime_capabilities = [bond, mptcp]
host
port
measured_rtt_ms / last_latency_ms / one_leg_rtt_ms / latency_ewma_ms
origin_group_key
origin_group_order
ready
selected_primary
leg_role = primary | helper | standby
backup_only
```

Self-leg candidates can also carry:

```text
probe_transport = cf_direct_wss | private_wg | cf_wg
self_leg_cf_candidates
cf_dial_host
cf_dial_port
cf_sni_host
cf_path_prefix
target_sidecar_port
target_sidecar_bond_port
target_sidecar_mptcp_port
self_leg_wg
self_leg_mptcp_solo_allowed
```

The Linux agent sorts candidates locally by measured RTT, readiness, self-leg tie-break, helper group order, and endpoint ID. It then marks one primary and later entries as helper/standby depending on runtime mode.

### 7.3 Bonding block

`bonding` is consumed by `install_forward_runtime.py` and `leikwan-bond`.

Important fields:

```text
enabled
ingress_mode                    # wss_path_mux or plain_tcp_mux
route_key
remote_target
session_token
udp_session_token
chunk_size
max_window_bytes
retransmit_ms
connect_timeout_ms
join_timeout_ms
adaptive_join_timeout
interactive_chunk_bytes
latency_guard_bytes
interactive_rtt_slack_ms
warmup_trigger_bytes
warmup_slice_bytes
warmup_leg_delay_ms
bulk_promote_bytes
leg_cooldown_ms
quic_enabled
quic_via_slave
active_leg_limit
quic_active_leg_limit
wss_standby_enabled
policy_mode                     # often latency_first
udp_primary_only
primary_reselect_on_failure_only
cf_primary_selection_mode
```

Do not invent separate Windows/Android semantics for these knobs. If the app uses `leikwan-bond`, these fields should map one-to-one.

### 7.4 MPTCP block

`mptcp` is consumed by the Linux MPTCP helper or Rust MPTCP relay.

Important fields:

```text
enabled
sidecar_host
sidecar_port
relay_host
relay_port
remote_host
remote_port
tcp_session_token
udp_session_token
udp_mode                         # stream_compat currently in local runtime
path_manager
scheduler
subflow_limit
connect_timeout_ms
relay_idle_timeout_seconds
allow_tcp_fallback
tcp_congestion_control
socket_buffer_bytes
policy_mode
initcwnd
initrwnd
add_addr_timeout
tcp_wmem_max
tcp_rmem_max
manage_mptcp_endpoints
mptcp_endpoint_id_base
mptcp_endpoints[]
underlay
underlays[]
token_expires_at
```

Linux supports both old single `underlay` and newer `underlays[]` formats.

---

## 8. Agent reconciliation state machine

The core Linux flow in `ClientAgent.sync_once()` is:

```text
1. GET /api/client/configs/pull
2. store latest forwards in memory
3. apply panel-managed client meta/config
4. consume local runtime refresh requests
5. for each forward:
   a. skip/stop inactive service_status
   b. validate usable endpoints for non-MPTCP modes
   c. validate required helper binaries
   d. validate MPTCP capability or allow_tcp_fallback
   e. compute stable forward contract signature
   f. if unchanged and runtime exists: mark no-op and skip restart
   g. otherwise apply_forward()
6. stop stale forwards no longer desired
7. reconcile global MPTCP state
8. run diagnostics if requested
9. collect nft usage deltas
10. ensure WireGuard identity
11. POST /api/client/heartbeat
12. save config_pull_state.json
```

The most important production behavior is **no-op stability**:

- `forward_contract_signature` strips observation-only fields;
- `rendered_runtime_signature` hashes the actual generated unit/script/config;
- `applied.json` remembers both signatures;
- if both contract/runtime are unchanged and files still exist, the agent does not rewrite/restart.

Windows/Android must preserve this concept. Without it, the app will restart tunnels every poll interval when latency/health observations drift.

---

## 9. Runtime rendering model

`install_forward_runtime.py` is the boundary between panel JSON and OS runtime materialization.

Input:

```text
forward config dict
agent semantic config dict
```

Output:

```json
{
  "service_name": "leikwan-client-47",
  "service_path": "/etc/systemd/system/leikwan-client-47.service",
  "service_unit": "...",
  "start_script_path": "/usr/local/bin/leikwan-client-start-47.sh",
  "start_script": "...",
  "files": [
    {"path": "/etc/leikwan/mptcp-forward-47.json", "content": "...", "mode": 384}
  ],
  "extra_units": []
}
```

For Windows/Android, implement the same separation:

```text
ForwardContract -> RuntimePlan -> PlatformRuntimeManager.apply(plan)
```

Where:

- `ForwardContract` is panel JSON after local selection/health policy;
- `RuntimePlan` is deterministic and easy to test;
- `PlatformRuntimeManager` starts/stops OS processes, VPN services, foreground services, or embedded runtimes.

---

## 10. Data-plane modes

### 10.1 Single mode

Single mode chooses one endpoint and exposes `listen_port` locally.

Linux rendering shape:

```text
wstunnel client
  -L tcp://[::]:<listen_port>:<target>
  -L udp://[::]:<listen_port>:<target>?timeout_sec=0
  --tls-sni-override <sni>
  --http-headers Host:<sni>
  --http-upgrade-path-prefix <path_prefix>
  wss://<endpoint_host>:<endpoint_port>
```

Use case:

- simplest compatibility path;
- easiest fallback on Windows/Android;
- no local aggregation.

### 10.2 Race / failover mode

Race mode uses multiple endpoints but is not true single-stream aggregation.

Linux rendering shape:

```text
for each endpoint:
  local wstunnel leg on 127.0.0.1:<derived_port>
then:
  leikwan-tcp-race.py for TCP
  leikwan-udp-fanout.py for UDP if tcp+udp
optional:
  leikwan-rate-limit.py if speed_limit_mbps is set
```

This is a legacy/compatibility mode. For new Windows/Android development, prioritize:

1. single mode for simplest fallback;
2. bond mode for real app-layer aggregation;
3. MPTCP only where the platform can honestly support it.

### 10.3 Bond mode

Bond mode is the main cross-platform candidate because it is app-layer aggregation and already has Rust runtime code.

Linux bond pipeline:

```text
local app
  -> 0.0.0.0:<listen_port>
  -> leikwan-bond client
  -> one local leg listener per selected endpoint
  -> wstunnel client or leikwan-ingress client
  -> slave ingress socket
  -> sidecar bond server
  -> target
```

For `plain_tcp_mux`, each local leg uses:

```text
leikwan-ingress client
  --listen 127.0.0.1:<local_leg_port>
  --remote <slave_host>:<slave_port>
  --route-key <route_key>
  --auth-token <ingress_auth_token>
```

Then `leikwan-bond client` starts above those local legs:

```text
leikwan-bond client
  --protocol tcp|udp
  --listen 0.0.0.0:<listen_port>
  --latency-first
  --udp-primary-only
  --single-leg-fast-path
  --legs 'tcp://127.0.0.1:<leg>?priority=10&endpoint_id=...&role=primary,...'
  --session-token <session_token>
  --chunk-size ...
  --max-window-bytes ...
  --retransmit-ms ...
  --connect-timeout-ms ...
  --join-timeout-ms ...
  --interactive-chunk-bytes ...
  --latency-guard-bytes ...
  --interactive-rtt-slack-ms ...
  --warmup-trigger-bytes ...
  --warmup-slice-bytes ...
  --warmup-leg-delay-ms ...
  --bulk-promote-bytes ...
  --leg-cooldown-ms ...
```

Bond runtime emits leg snapshots to:

```text
/run/leikwan/bond-leg-health-forward-<forward_id>.json
```

The agent reads those snapshots and updates `bond-endpoint-health.json`.

App guidance:

- Windows should use the same `leikwan-bond` and `leikwan-ingress` protocol contract where possible.
- Android can port/embed the same protocol runtime or use a native Kotlin/NDK binding around equivalent Rust code.
- UI should show health from the same model, but the UI should not decide which legs exist; reconciliation does.

### 10.4 MPTCP mode

Linux MPTCP mode is a native Linux feature path. It should not be blindly promised on Windows/Android.

Linux MPTCP pipeline:

```text
local app
  -> 0.0.0.0:<listen_port>
  -> leikwan-mptcp-relay client if executable
     otherwise Python leikwan-mptcp-client.py
  -> IPPROTO_MPTCP socket to sidecar relay
  -> optional per-leg WireGuard transit underlays
  -> sidecar MPTCP listener
  -> target
```

Generated runtime config:

```text
/etc/leikwan/mptcp-forward-<forward_id>.json
```

Start script sequence:

```text
1. unset HTTP_PROXY/HTTPS_PROXY/ALL_PROXY and set NO_PROXY=*
2. start optional direct self-leg wstunnel helpers
3. start optional CF-WG wstunnel UDP helper
4. run leikwan-mptcp-client.py --setup-underlays
5. exec /var/lib/leikwan-client-agent/leikwan-mptcp-relay client --config ... if present
6. fallback to python leikwan-mptcp-client.py --config ...
```

WireGuard underlay interface names include:

```text
lw-mptcp-<forward_id>-<index>      # slave transit underlay
lw-cfwg-<forward_id>               # CF-WG self-leg underlay
```

MPTCP helper duties:

- write/refresh `/etc/wireguard/<iface>.conf`;
- bring up `wg-quick@<iface>.service`;
- remove stale/conflicting transit interfaces;
- configure `ip mptcp limits` and endpoint entries;
- tune TCP/MPTCP sysctls;
- open `IPPROTO_MPTCP` sockets;
- fallback to TCP only when `allow_tcp_fallback` is true;
- send a length-prefixed JSON handshake with `protocol`, `session_token`, and `forward_id`;
- relay TCP streams;
- support UDP through `udp_mode=stream_compat` length-framed datagrams.

Cross-platform stance:

- Windows and Android should consume MPTCP fields for compatibility/reporting, but usually select bond/single fallback unless there is a real platform MPTCP implementation or a Linux aggregation gateway.
- If an app advertises MPTCP capability, it must prove equivalent behavior: multiple subflows, per-leg policy, health, fallback, and observability.

---

## 11. Runtime family selection

The panel may publish `mode=mptcp` or a forward combination with:

```text
runtime_preference = mptcp_preferred_bond_fallback
```

Linux then decides locally:

```text
if runtime_preference is not mptcp_preferred_bond_fallback:
    use requested mode
else if selected primary is self_leg:
    use bond for CF direct WSS self-leg into sidecar bond backend
    use mptcp only if MPTCP is supported and candidate allows/can do it
    otherwise use bond
else if MPTCP is supported and MPTCP runtime config exists and candidate is MPTCP-capable:
    use mptcp
else:
    use bond
```

Reason: self-leg CF direct WSS and MPTCP application framing are not interchangeable. The Linux code intentionally keeps CF direct WSS self-leg on the mature bond runtime.

Windows/Android should implement the same decision rules or send explicit capability flags so the panel can avoid publishing impossible modes.

---

## 12. Candidate selection and health policy

### 12.1 Selection inputs

Linux considers:

```text
candidate_pool[]
endpoints[]
last_chain_latency_ms
one_leg_rtt_ms
latency_ewma_ms
last_latency_ms
bond-endpoint-health.json
self-leg live probes
helper_group_cap / max_legs_used
desired_latency_ms
runtime_capabilities
```

### 12.2 Selection output

The selected runtime contract is mutated locally before rendering:

```text
candidate_pool entries get:
  selected_primary = true on exactly one candidate
  leg_role = primary/helper/standby as appropriate
  backup_only for standby MPTCP helpers

endpoints get:
  health_role
  quality_score
  one_leg_rtt_ms
  latency_ewma_ms
  last_latency_ms
  dynamic_priority
```

Observation fields are stripped before stable render signatures are calculated, so normal health measurement does not trigger constant restarts.

### 12.3 Quarantine/probation

`bond-endpoint-health.json` stores role transitions:

```text
healthy -> probation -> quarantined
```

Current constants in `client_agent.py`:

```text
CLIENT_BOND_ENDPOINT_PROBATION_SECONDS   60 seconds
CLIENT_BOND_ENDPOINT_QUARANTINE_SECONDS  30 minutes
```

Health checks can refresh rendered endpoints without restarting the whole forward when only endpoint ordering/selection needs to change.

### 12.4 Desired latency escalation

When self-leg is selected but exceeds `desired_latency_ms`, Linux can activate helper legs up to `helper_group_cap` / `max_legs_used`.

This is important for Windows/Android parity: do not treat self-leg as always solo. It can be primary with helpers when latency target requires support.

---

## 13. CF-WG latency watchdog

The current client-agent integrates `client-agent/cfwg_latency_watchdog.py`.

Purpose:

- monitor actual RTT to the WireGuard peer over an `lw-cfwg-*` interface;
- detect latency degradation, not just dead handshakes;
- restart the runtime pieces that tend to recover the path.

How it finds a target:

```text
/etc/leikwan/mptcp-forward-<forward_id>.json
  -> underlays[] / underlay
  -> interface_name starts with lw-cfwg-
  -> mode is empty or client_self_leg
  -> leg outer_transport is wstunnel_udp
  -> peer_address or mptcp_remote_address
```

Probe command:

```text
ping -I <interface_name> -c 1 -W <timeout> <peer_address>
```

Default panel-side watchdog config currently merged by `ClientAgentController`:

```text
enabled                         true by default from env/config
forward_ids                     default 47
probe_interval_seconds          default 5
window_samples                  default 3 in panel defaults; watchdog fallback is 12 if absent
dwell_windows                   default 2
critical_rtt_ms                 default 55
critical_rtt_delta_ms           default 10
baseline_rtt_ms                 default 47:31 from env/config default
relative_degradation_factor     default 1.35
min_relative_degradation_ms     default 8
critical_loss_pct               default 0.34 from panel defaults; watchdog fallback is 0.20 if absent
cooldown_seconds                default 240 from panel defaults; watchdog fallback is 300 if absent
ping_timeout_seconds            default 1
restart_actions                 default [wstunnel, wireguard]
state_write_interval_seconds    default 15 from panel defaults; watchdog fallback is 30 if absent
```

Evaluation logic:

```text
collect N samples
compute loss, p95 RTT, avg RTT
critical if:
  loss >= critical_loss_pct
  OR p95 >= critical_rtt_ms
  OR p95 >= baseline + critical_rtt_delta_ms
  OR p95 >= max(baseline * relative_degradation_factor, baseline + min_relative_degradation_ms)
require dwell_windows consecutive bad windows
respect cooldown_seconds
execute restart actions
write cfwg-latency-watchdog-state.json
```

Restart actions:

```text
wstunnel / forward_service / client_service
  -> systemctl restart leikwan-client-<forward_id>.service

wireguard / wg
  -> systemctl restart wg-quick@<interface>.service
  -> fallback wg-quick down/up if systemd restart fails

client_agent
  -> restart forward service, then restart leikwan-client-agent.service

rotate_cf_ip / cf_ip / path_correction
  -> optional pin rotation under cfwg-watchdog-pin/<forward_id>.json if enable_cf_ip_rotation=true
```

For Windows/Android:

- implement the same state machine;
- replace `ping -I` with platform-specific per-interface/underlay RTT measurement;
- replace systemd restarts with app runtime object restarts;
- keep the same thresholds and cooldown semantics in the control-plane config;
- expose watchdog state in diagnostics.

---

## 14. Health checks beyond CF-WG

The Linux agent's `ensure_runtime_health()` handles:

```text
systemd active check
local listener presence check
local TCP/UDP runtime probe
remote WSS ready probe
plain_tcp_mux ready probe
CF bond one-leg connect probe
MPTCP sidecar TCP probe
MPTCP leg probe and partial refresh
bond snapshot reconciliation
live slave-connection mismatch check
```

Important behavior:

- If a forward is inactive, stop it.
- If a service is inactive, restart it.
- If MPTCP local listener is not ready shortly after service start, defer briefly before restart.
- If all sidecar leg probes fail in MPTCP, refresh runtime endpoints instead of blindly restarting.
- If self-leg is selected primary, helper failures should not destroy the primary path.
- For bond/race, if all slave endpoints fail and no self-leg primary exists, restart the forward service.
- For race mode, healthy endpoints can be reordered ahead of unhealthy ones.

App teams should map these to a platform-neutral health engine with explicit actions:

```text
No action
Refresh runtime shape
Restart one forward runtime
Restart underlay only
Restart full app service
Quarantine endpoint
Run diagnostics/report
```

---

## 15. Self-update and artifact versioning

The panel publishes desired versions/hashes in the pull response from `artifacts/versions.json`.

Linux self-update flow:

```text
client-agent sees agent_update_request_token or auto update token
  -> compares desired agent/runtime hashes to installed hashes
  -> starts /usr/local/bin/leikwan-client-agent-update via transient systemd unit
  -> update helper downloads install-client-agent.sh from artifact_base_url
  -> runs installer with LEIKWAN_SKIP_UPDATE_HELPER=1
  -> restarts forward units after agent update
  -> records agent_update_state.json
```

Important design points for apps:

- updates are tokenized/idempotent;
- update result is persisted;
- update does not reuse stale request tokens;
- forward units are restarted after binary/runtime update;
- installed hashes are reported in heartbeat;
- failed updates must not erase working runtime state.

Windows/Android equivalents:

- Windows: app updater/installer service with signed packages and rollback;
- Android: normal APK update channel or in-app prompt if outside Play-style deployment;
- both: keep runtime artifact hash reporting so panel can see drift.

---

## 16. Traffic accounting

Linux client uses nftables counters with comments like:

```text
lw_client_<forward_id>_in_tcp
lw_client_<forward_id>_out_tcp
lw_client_<forward_id>_in_udp
lw_client_<forward_id>_out_udp
```

The agent reads current counters, diffs against `usage_state.json`, and posts deltas in heartbeat.

Windows/Android need equivalent counters. Options:

- count bytes inside the local relay runtime;
- count bytes per socket/proxy session;
- count bytes in VPNService/TUN layer on Android;
- count bytes in a Windows service-level forwarding engine.

Do not rely only on UI-side counters; heartbeat must be generated by the runtime owner.

---

## 17. Security and secret handling

Sensitive items:

```text
agent_token
client_token
bond session tokens
udp session tokens
ingress_auth_token
WireGuard private key
self-leg peer/private underlay material
```

Rules to preserve:

- treat panel JSON as untrusted except for whitelisted config keys;
- do not print bearer tokens or session tokens in normal logs;
- store private keys with restricted permissions where the platform supports it;
- separate runtime state from shipped app code;
- keep artifact hashes and versions visible but not secrets;
- validate host/port fields before rendering/starting runtime;
- stable signatures should exclude observations but not exclude security-relevant contract fields.

---

## 18. Windows and Android parity guidance

### 18.1 What must be shared across platforms

The following should be identical or intentionally mapped:

```text
/api/client/login and /api/client/select behavior
/api/client/configs/pull parsing
/api/client/heartbeat payload concept
/api/client/diagnostics/result payload concept
forward config schema
candidate_pool schema
bonding block semantics
MPTCP block capability reporting
health/quarantine state model
no-op/reconcile signatures
artifact/version/hash reporting
latency watchdog config semantics
```

### 18.2 What should be platform-specific

Linux-specific pieces to replace:

```text
systemd units/timers
nftables accounting
wg-quick services
ip mptcp endpoint commands
/etc and /usr/local/bin filesystem layout
journalctl diagnostics
bash start scripts
```

Platform replacements:

```text
Windows:
  Windows Service or user-mode supervisor
  tray/UI process separate from runtime service
  Windows Firewall/WFP or runtime byte counters
  WireGuardNT/wireguard-go or embedded tunnel manager if needed
  Rust leikwan-bond/leikwan-ingress Windows binaries where possible

Android:
  foreground service for long-running runtime
  VpnService/TUN boundary for app-wide routing if needed
  app-private encrypted storage for tokens/keys
  native library/NDK or Kotlin coroutine runtime for bond/single paths
  explicit battery/network-change recovery logic
```

### 18.3 Recommended platform capability tiers

```text
Tier 1: single mode
  Must work everywhere. Lowest risk fallback.

Tier 2: bond mode
  Best target for Windows first because app-layer bond is portable.
  Reuse Rust protocol/runtime if possible.

Tier 3: CF-WG self-leg watchdog
  Add when the app owns a WireGuard/underlay path and can measure RTT on that path.

Tier 4: native MPTCP
  Linux-first. Windows/Android should only claim this if the actual runtime can create equivalent multipath behavior.
```

### 18.4 Suggested app architecture

```text
UI layer
  -> login/select client
  -> display runtime state
  -> request start/stop/update/diagnostics

Runtime supervisor
  -> stores token and client config
  -> poll loop
  -> reconcile loop
  -> heartbeat loop
  -> diagnostics loop

Runtime planner
  -> normalizes panel forward config
  -> selects candidates and runtime family
  -> calculates stable contract/render signatures
  -> outputs per-forward RuntimePlan

Data-plane engines
  -> SingleEngine
  -> BondEngine
  -> MptcpEngine where platform supports it
  -> Underlay/WireGuardEngine where needed

Health engine
  -> local listener probes
  -> remote endpoint probes
  -> bond leg snapshot/reputation
  -> quarantine/probation
  -> latency watchdog

Persistent state store
  -> applied forwards
  -> pull state
  -> endpoint health
  -> update state
  -> watchdog state
  -> diagnostics results
```

---

## 19. Implementation checklist for new apps

Minimum viable parity:

- [ ] Implement login/select or manual token import.
- [ ] Store `master_url`, `artifact_base_url`, `client_id`, `agent_token` securely.
- [ ] Implement authenticated `GET /api/client/configs/pull`.
- [ ] Parse `client.agent_config_json` with a whitelist.
- [ ] Parse assigned `forwards[]` and skip inactive forwards.
- [ ] Implement stable contract signatures excluding observation-only fields.
- [ ] Implement `applied` state and no-op detection.
- [ ] Implement single mode.
- [ ] Implement bond mode with `leikwan-bond`/`leikwan-ingress` protocol compatibility or exact equivalent.
- [ ] Implement local runtime health checks and per-endpoint quarantine/probation.
- [ ] Implement heartbeat with version, capability, byte counters, active forward state, and health/reputation summary.
- [ ] Implement diagnostics result upload.
- [ ] Implement clean stop/remove for forwards no longer assigned.
- [ ] Implement update/hash reporting.

Recommended next parity:

- [ ] Implement `candidate_pool` local selection and `max_legs_used` cap.
- [ ] Implement latency-first bond selection and role tags.
- [ ] Implement self-leg CF direct WSS / CF-WG paths only after the basic bond path is stable.
- [ ] Implement CF-WG latency watchdog if the app owns the WG/underlay path.
- [ ] Implement UDP sidepath or stream-compatible UDP only with clear performance caveats.
- [ ] Implement MPTCP only where platform capability is real.

---

## 20. Common failure patterns to avoid

- Restarting every poll because latency/health observations changed.
- Treating panel source JSON as fully trusted runtime instructions without validation.
- Ignoring `runtime_preference=mptcp_preferred_bond_fallback` and forcing MPTCP on unsupported platforms.
- Treating declared multi-leg config as proof of multi-leg runtime; verify active leg snapshots/connections.
- Bypassing slave ingress and connecting directly to sidecar in environments where topology is `client -> slave -> sidecar`.
- Hiding runtime errors only in GUI state; persist machine-readable diagnostics.
- Losing per-forward ownership and leaving stale listeners/processes after a forward is removed.
- Logging tokens or generated session credentials.
- Mixing UI login/session state with the long-running runtime token state without clear ownership.

---

## 21. Useful Linux diagnostics reference

These are Linux-only commands, but they show what state apps should expose equivalently.

```bash
systemctl status leikwan-client-agent.service --no-pager -n 100
journalctl -u leikwan-client-agent.service --no-pager -n 200

cat /var/lib/leikwan-client-agent/config_pull_state.json
cat /var/lib/leikwan-client-agent/applied.json
cat /var/lib/leikwan-client-agent/bond-endpoint-health.json
cat /var/lib/leikwan-client-agent/cfwg-latency-watchdog-state.json

systemctl status leikwan-client-<forward_id>.service --no-pager -n 100
journalctl -u leikwan-client-<forward_id>.service --no-pager -n 200
cat /etc/leikwan/mptcp-forward-<forward_id>.json
cat /run/leikwan/bond-leg-health-forward-<forward_id>.json

wg show
ip mptcp endpoint show
ss -ltnup | grep <listen_port>
```

For Windows/Android, create an in-app diagnostic export with the same concepts:

```text
pull status
applied forwards
runtime plans
active process/engine state
endpoint health
last probe errors
watchdog state
artifact/app versions and hashes
byte counters
```

---

## 22. Test map

Existing Linux client regression tests are in:

```text
client-agent/tests/
```

Important categories:

```text
test_artifact_versions_consistency.py
test_client_agent_installer_helper.py
test_client_agent_bond_endpoint_health.py
test_client_agent_mptcp_health_grace.py
test_cfwg_latency_watchdog.py
test_mptcp_bond_regressions.py
test_mptcp_setup_and_cleanup_regressions.py
test_runtime_preference_mptcp_bond_fallback.py
test_self_leg_candidate_policy.py
test_self_leg_cf_wg_runtime.py
test_self_leg_direct_cf_runtime_shape.py
test_self_leg_mptcp_solo_runtime.py
test_uot_bond_runtime.py
test_uot_process_cleanup.py
```

For new apps, build equivalent golden tests around:

- pull response parsing;
- runtime family decision;
- candidate sorting and max-leg cap;
- rendered command/runtime plan stability;
- no-op detection;
- bond endpoint health transitions;
- CF-WG latency watchdog thresholds;
- update token idempotency;
- diagnostics payload shape.

---

## 23. One-page architecture to hand to implementers

```text
Panel contract:
  GET /api/client/configs/pull -> client meta + forwards + desired artifacts
  POST /api/client/heartbeat  -> versions + capabilities + usage + health
  POST /api/client/diagnostics/result -> support bundle result

Client app/service:
  authenticate/select client
  persist token securely
  poll panel
  normalize/validate forwards
  select candidate primary/helpers locally
  choose runtime family using platform capabilities
  render RuntimePlan
  apply only if contract/render signature changed
  supervise one runtime per forward
  probe local and remote health
  quarantine/probation bad endpoints
  run latency watchdog for CF-WG paths
  heartbeat state back to panel

Data plane priority:
  single  -> universal fallback
  bond    -> primary cross-platform aggregation target
  mptcp   -> Linux-native / gateway-backed only unless real platform support exists
```
