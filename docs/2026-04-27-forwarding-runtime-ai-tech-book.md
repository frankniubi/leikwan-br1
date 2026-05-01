# Forwarding Runtime AI Tech Book

## Purpose

This document is the AI-oriented technical book for the current Leikwan forwarding stack as of **April 27, 2026**.

It records:

- what was changed during the April 26-27 `forward 47` investigation and repair
- the released artifact versions that matter for live hosts
- the working logic for both **normal mode** and **CF mode**
- the end-to-end control plane and runtime data flow
- the update/install logic and the main operational pitfalls
- the exact runtime failure boundary that was fixed

This is written for future AI agents and human operators who need a compact but comprehensive source of truth.

---

## Workspace anchor

### Repository root

Current workspace root during this documentation pass:

```text
C:\Users\frankniubi\Downloads\leikwan-br1
```

### Active branch

The work documented here was produced on:

```text
codex/android-single-bond-linux-parity
```

### Clean git worktree

A clean git worktree was created for inspection and clean-baseline work at:

```text
C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\clean-copy-20260427
```

Its branch name is:

```text
clean-copy-20260427
```

Important note:

- this worktree is a clean snapshot of the committed git state at the time it was created
- it does **not** automatically include uncommitted local modifications from the main workspace

### Key top-level directories

These directories matter most when navigating the repo:

```text
master/                  panel / control plane
slave-agent/             slave runtime + publish executor
client-agent/            client poller + runtime health / render logic
sidecar-agent/           sidecar runtime + CF route manager
install/                 install and self-update scripts
artifacts/               published runtime artifacts + versions manifest
wstunnel-main/           Rust runtime crates, especially leikwan-bond
docs/                    handoff notes, deploy notes, AI tech book
```

### Most important files for future AI work

```text
master/src/Support/ProvisioningService.php
master/src/Controllers/ClientAgentController.php
master/src/Controllers/SidecarAgentController.php
slave-agent/slave_agent.py
client-agent/client_agent.py
client-agent/install_forward_runtime.py
sidecar-agent/sidecar_agent.py
install/install-client-agent.sh
install/client-agent-update.sh
wstunnel-main/leikwan-bond/src/session.rs
artifacts/versions.json
```

---

## Scope and invariants

### Environment-specific topology rules

For this project, keep these rules in mind:

- SSH access prefers **WSL** on the operator machine.
- Sidecars are not meant to be direct client targets in this environment.
- The intended topology is:

```text
client -> slave -> sidecar
```

- Do not redesign the topology to direct client -> sidecar unless the user explicitly asks for it.
- For the slave hosts in this environment, MPTCP ingress and bond ingress must stay inside ports `7000-9000`.

### Working definitions

There are two separate mode families:

1. **client mode**: how the client consumes a forward
   - `single`
   - `race`
   - `bond`
   - `mptcp`

2. **backend mode**: how the slave reaches the backend
   - `current_mode` = “normal mode” in this document
   - `cf_mode`

These are orthogonal. The most important combination for the repaired live path is:

```text
client_mode = bond
backend_mode = cf_mode
ingress_mode = plain_tcp_mux
```

---

## Released state

### Released artifacts

The currently relevant released artifacts are:

- `client_agent`: `2026.04.27.2`
- `slave_agent`: `2026.04.27.1`
- `leikwan_bond_linux_amd64`: `0.1.14`

### Important release properties

- `leikwan-bond 0.1.14` is published as a **static musl** Linux amd64 binary.
- This matters because a glibc-linked `0.1.14` build required `GLIBC_2.38` and was not usable on the live hosts.
- The panel-hosted Linux bond artifact must remain the static/musl build for compatibility with older Debian/Ubuntu class hosts.

### Final validated artifact hashes

- `client-agent.py`
  - sha256 `09ae15e6dcecb03edb4d214b42812dff6abd9884fd9ca26aba4f5bf89cfe78bd`
- `slave-agent.py`
  - sha256 `b86a5ee57afbee50a6e5a24fca383184c4c2bf02acd0feb28c5a1c0a1379f995`
- `leikwan-bond_linux_amd64`
  - sha256 `8f5f8da7efe93a65e3a6217ef51002b632ca3add019e2a0a3689edcde9c51fbe`

---

## High-level architecture

### Core components

#### 1. Panel / master

Primary files:

- `master/src/Support/ProvisioningService.php`
- `master/src/Controllers/ClientAgentController.php`
- `master/src/Controllers/SidecarAgentController.php`
- `master/src/Controllers/ArtifactController.php`

Responsibilities:

- decide which forward config each client/slave/sidecar should receive
- publish artifact metadata and sha256 values
- generate session tokens and ingress auth tokens
- encode the difference between `current_mode` and `cf_mode`

#### 2. Slave agent

Primary file:

- `slave-agent/slave_agent.py`

Responsibilities:

- receive publish tasks from panel
- materialize backend services under systemd
- for `cf_mode`, select a Cloudflare IP and build the CF backend runtime
- host ingress listeners for client-facing traffic

#### 3. Client agent

Primary files:

- `client-agent/client_agent.py`
- `client-agent/install_forward_runtime.py`

Responsibilities:

- poll the panel for assigned forwards
- install/render runtime scripts and systemd units
- monitor local and remote forward health
- now: run stronger CF bond leg validation and quarantine bad legs faster

#### 4. Sidecar agent

Primary file:

- `sidecar-agent/sidecar_agent.py`

Responsibilities:

- host the point-side bond server / sidecar runtime
- host CF runtime routes on the sidecar
- manage the CF route mapping from path prefix to local backend service

#### 5. Bond runtime

Primary Rust crate:

- `wstunnel-main/leikwan-bond`

Key file:

- `wstunnel-main/leikwan-bond/src/session.rs`

Responsibilities:

- multiplex and stripe traffic across multiple legs
- maintain per-leg transport health
- emit the bond health snapshot consumed by the client agent

---

## Mode model

## A. Normal mode (`backend_mode = current_mode`)

### Normal mode semantics

“Normal mode” means the slave does **not** select a Cloudflare IP.

The slave backend connects directly to the configured target:

- origin service target, or
- sidecar direct target, depending on client mode and panel provisioning

### Normal mode publish logic

Important panel path:

- `master/src/Support/ProvisioningService.php`
  - `publishTaskPayloadForForward(...)`

Relevant behavior:

- `backend_mode = current_mode`
- if `client_mode` is `bond` or `mptcp`, panel may resolve a direct sidecar target
- otherwise the slave target is the service origin target

### Normal mode runtime behavior by client mode

#### `single`

- client uses one endpoint
- slave runs a normal backend unit
- client reaches the slave through WSS path mux or the matching ingress mode

#### `race`

- client starts multiple competing legs
- first healthy path tends to win for a connection
- useful for fast failover, not for sustained striping like bond mode

#### `bond`

- client spawns multiple ingress client legs
- slave ingress accepts each leg
- slave backend forwards toward the normal target
- the point-side sidecar bond server is used when panel resolves a sidecar direct target

#### `mptcp`

- client and sidecar use the MPTCP runtime path
- still panel-driven, but logically separate from the bond runtime

---

## B. CF mode (`backend_mode = cf_mode`)

### CF mode semantics

In `cf_mode`, the slave does **not** dial the origin or sidecar host directly.

Instead it:

1. selects a Cloudflare IP from the configured location group
2. keeps the real SNI / Host / path prefix
3. sends WSS traffic through that selected CF IP

The sidecar then terminates the CF route and forwards locally into the bond server or UDP sidepath.

### CF mode publish logic

Important panel paths:

- `master/src/Support/ProvisioningService.php`
  - `buildCfModePayload(...)`
  - `publishTaskPayloadForForward(...)`
- `master/src/Controllers/SidecarAgentController.php`

Important fields:

- `cf_mode.cidrs`
- `cf_mode.dial_host`
- `cf_mode.dial_port`
- `cf_mode.sni_host`
- `cf_mode.path_prefix`
- `cf_mode.location_group_id`

### CF mode slave behavior

Important slave path:

- `slave-agent/slave_agent.py`
  - `select_cf_location_ip(...)`
  - `probe_cf_candidates(...)`
  - `probe_cf_websocket_path(...)`
  - `publish_task(...)`

Current working logic:

1. the panel gives the slave a CF location group payload
2. the slave expands candidate IPs from the group CIDRs
3. the slave probes candidates using a **real wstunnel-based path probe** against the actual target
4. the slave remembers candidate cooldown/failure state
5. the slave picks a selected CF IP
6. the slave renders a backend unit like:

```text
wstunnel client ... wss://<selected_cf_ip>:2053
```

while preserving:

- `--tls-sni-override`
- `Host: ...`
- `--http-upgrade-path-prefix`

### CF mode sidecar behavior

Important sidecar paths:

- `sidecar-agent/sidecar_agent.py`
- point-side `cf-forward-<id>.service`

Current working logic:

- sidecar receives panel `cf_runtime`
- route file maps `path_prefix` to a local target
- for bond forward 47, that route ultimately feeds the sidecar bond listener
- UDP sidepath gets its own `*.uot` route

### CF mode client behavior

For `bond + plain_tcp_mux + cf_mode`, the client does **not** know the CF IPs directly.

Instead:

1. client opens legs to slave ingress endpoints
2. slave ingress hands traffic to the local CF backend
3. slave CF backend dials selected CF IP
4. sidecar CF runtime lands the path and forwards into the sidecar bond server

So the effective path is:

```text
client
  -> slave ingress
  -> slave local CF backend
  -> selected CF IP
  -> point-5 CF route
  -> sidecar bond server
  -> target
```

---

## Mode matrix

| Client mode | Backend mode | Client -> slave | Slave -> backend | Sidecar role |
|---|---|---|---|---|
| `single` | `current_mode` | direct single leg | direct backend/origin | optional / not central |
| `race` | `current_mode` | competing legs | direct backend/origin | optional / not central |
| `bond` | `current_mode` | multi-leg ingress | direct sidecar/origin backend | bond server if used |
| `bond` | `cf_mode` | multi-leg ingress | slave CF backend via selected CF IP | CF route -> bond server |
| `mptcp` | `current_mode` | MPTCP runtime | direct sidecar target | MPTCP sidecar runtime |
| `mptcp` | `cf_mode` | uncommon / not the main validated path here | CF path if provisioned | depends on forward design |

---

## What was fixed during the April 26-27 investigation

## 1. Panel control-plane fixes

What changed:

- bond + CF publishing now points slave-side CF backends at the **loopback bond target** instead of leaking origin/sidecar direct targets incorrectly
- sidecar CF runtime generation also uses the proper local loopback target

Why it mattered:

- before this, the CF path could be logically correct in the UI but wrong in the actual runtime target chain

Result:

- the control plane now publishes the intended local sidecar/bond targets

## 2. Slave CF candidate selection fixes

What changed:

- `slave-agent` now performs a stronger real-path probe
- candidate cooldown/failure bookkeeping was added
- cached CF IP handling became more disciplined

Why it mattered:

- raw TCP-only or fake HTTP-ish probes were too weak
- bad CF IPs could look acceptable and remain sticky

Result:

- slave CF selection is now materially closer to the real runtime path

## 3. Bond runtime toxic-leg suppression fix

What changed:

- `leikwan-bond 0.1.14` now unpins / cools down / penalizes the preferred TCP leg on:
  - `stream_open_timeout`
  - `local_read_failed`
  - `local_write_failed`
  - `close_timeout`

Key file:

- `wstunnel-main/leikwan-bond/src/session.rs`

Why it mattered:

- the previous behavior let bad legs remain influential inside mixed-quality sessions
- that created:
  - large backlog growth
  - repeated resets
  - full-session poisoning

Result:

- mixed sessions degrade much less severely
- full automated `forward 47` now recovers from the previously toxic mix

## 4. Client CF bond leg quarantine fix

What changed:

- `client-agent 2026.04.27.2` gained a stronger **one-leg bond connect probe** for CF bond runtimes
- this probe runs after the cheap ingress-ready probe and can quarantine endpoints that still fail to establish a real bond session

Key file:

- `client-agent/client_agent.py`

Why it mattered:

- plain ingress PING/ready probes were too shallow
- several legs could look “healthy” while still poisoning real data-plane traffic

Result:

- client health is now much closer to the actual bond data path

---

## Final validated live state

### Full automated runtime

Validated with:

```bash
iperf3 -c 127.0.0.1 -p 11035 -R -b 300M
```

Final validated full automated TCP result:

- receiver around **297 Mbits/sec**

Validated UDP sanity:

```bash
iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -u
```

Final validated UDP result:

- receiver around **301 Mbits/sec**
- `0%` loss in the captured sanity run

### Why this is important

Earlier in the investigation:

- one-leg tests proved several legs were individually fast
- full multi-leg runtime collapsed badly

After the fixes:

- the **automated** full runtime is now close to line rate
- the client agent can remain enabled

That is the correct target for new installs.

---

## Update / install logic

### Client agent install flow

Primary file:

- `install/install-client-agent.sh`

Main steps:

1. parse CLI args
2. check MPTCP runtime capability
3. check time sync
4. check WireGuard tools
5. download all required artifacts into `/usr/local/bin`
6. write `/etc/leikwan-client-agent/config.json`
7. write systemd unit
8. daemon-reload / enable / restart

### Client self-update flow

Primary files:

- `client-agent/client_agent.py`
- `install/client-agent-update.sh`
- panel `master/src/Controllers/ClientAgentController.php`

Flow:

1. panel pull returns desired versions + sha256 values
2. client agent compares local vs desired
3. if needed, it schedules the helper
4. helper downloads and re-runs the installer
5. client heartbeats back and panel DB row updates

### Important update lesson

During the investigation, stale panel-side `agent_version` state looked like a panel bug, but it was actually an **effect** of update execution state on the host.

Practical rule:

- if panel still thinks the client is old, first verify whether the host actually completed the update and heartbeated back
- do not assume panel token generation is wrong before checking host execution state

---

## Installer performance finding

### Root cause of slowness

`install-client-agent.sh` is primarily slow because of **sequential artifact downloads**, especially the larger binary artifacts:

- `wstunnel/leikwan-ingress_linux_amd64`
- `wstunnel/leikwan-bond_linux_amd64`

Observed behavior:

- small Python/shell artifacts download quickly
- the large binaries dominate wall-clock time
- the mirror and main panel domain can both be slow/flaky for these large transfers

### What is not the main bottleneck

On the traced host:

- `ensure_mptcp_ready` was fast
- `ensure_time_sync` was fast
- `install_wireguard_tools` was fast
- `systemctl restart` was not the dominant cost

So the current installer slowness is mainly **network transfer time**, not local shell logic.

---

## How to reason about a healthy path

### Normal mode healthy path

Healthy normal mode usually means:

- client runtime starts
- slave ingress accepts
- slave backend reaches target directly
- ready probes pass
- no repeated resets in bond logs

### CF mode healthy path

Healthy CF mode usually means:

- client reaches slave ingress
- slave CF backend uses a selected CF IP
- sidecar CF route maps the path correctly
- bond runtime can establish a real data-plane stream through that leg
- one-leg bond probe and/or runtime health snapshot can confirm it

---

## AI troubleshooting playbook

If a future AI sees low throughput, use this order:

### 1. Confirm artifact/version state

Check:

- panel `artifacts/versions.json`
- local `client_agent.py` version
- local `slave_agent.py` version
- local `leikwan-bond` hash/version

### 2. Check whether client-agent is actually active

Commands:

```bash
systemctl is-active leikwan-client-agent.service
systemctl is-active leikwan-client-47.service
```

### 3. Check the rendered runtime

Commands:

```bash
pgrep -af '^/usr/local/bin/leikwan-bond client --protocol tcp --listen 0.0.0.0:11035'
sed -n '1,200p' /usr/local/bin/leikwan-client-start-47.sh
```

### 4. Check the bond snapshot

Command:

```bash
cat /run/leikwan/bond-leg-health-forward-47.json
```

### 5. Run isolated one-leg tests

This is the fastest discriminator between:

- bad-leg quality problem
- multi-leg runtime poisoning problem

### 6. Check sidecar bond logs

On point-5, look for:

- `local_write_failed`
- `close_timeout`
- repeated reset loops
- large pending backlog growth

### 7. Check installer slowness separately from runtime correctness

If install is slow, do not immediately blame runtime logic.

First measure:

- binary artifact download time
- package/tool setup time
- final restart time

---

## Important operational lessons

1. Use **static/musl** Linux bond binaries for compatibility.
2. Do not trust shallow ready probes as a full substitute for real data-plane validation.
3. For CF bond paths, a **one-leg real bond connect probe** is much more trustworthy than plain ingress PING.
4. If a full multi-leg runtime is slow but several one-leg runs are fast, suspect **toxic-leg poisoning**, not total path collapse.
5. Panel-stored version lag is usually a **host execution/heartbeat symptom** before it is a panel logic bug.

---

## Files that matter most

### Control plane

- `master/src/Support/ProvisioningService.php`
- `master/src/Controllers/ClientAgentController.php`
- `master/src/Controllers/SidecarAgentController.php`

### Slave runtime

- `slave-agent/slave_agent.py`

### Client runtime

- `client-agent/client_agent.py`
- `client-agent/install_forward_runtime.py`
- `install/install-client-agent.sh`
- `install/client-agent-update.sh`

### Sidecar runtime

- `sidecar-agent/sidecar_agent.py`

### Bond runtime

- `wstunnel-main/leikwan-bond/src/session.rs`
- `wstunnel-main/leikwan-bond/Cargo.toml`

---

## Final summary

As of **April 27, 2026**:

- the released client/slave artifacts are published
- the static `leikwan-bond 0.1.14` artifact is published
- the client update/install path is coherent
- the full automated `forward 47` runtime is healthy again
- both **normal mode** and **CF mode** logic are documented here from panel to runtime

For future AI work, the safest interpretation is:

- **normal mode** = direct backend/origin path
- **CF mode** = slave-selected CF IP path with sidecar CF routing
- the key reliability boundary is no longer simple endpoint liveness, but whether the bond runtime quickly suppresses toxic legs before they poison the stream
