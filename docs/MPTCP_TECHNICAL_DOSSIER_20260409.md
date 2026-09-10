# MPTCP Technical Dossier

Date: 2026-04-09

Scope: current state of the Leikwan MPTCP testbed, validated fixes, live tuning decisions, and known remaining limits.

## 1. Snapshot

- Backup label: `v2-best`
- Backup archive: `artifacts/backups/v2-best/leikwan-br1-v2-best-20260409-021532.zip`
- Backup notes: `artifacts/backups/v2-best/README.md`
- This snapshot is the rollback point before the next client-side global MPTCP management rollout.

## 2. Test Topology

- Client host:
  `172.21.255.189`
- Sidecar point:
  `id=3`
- Sidecar public ingress for slaves:
  `161.248.136.183:14969`
- Forward under active tuning:
  `forward 19`
- Local client test port:
  `11009`
- Sidecar backend target:
  `127.0.0.1:5201`

## 3. Confirmed Root Causes Fixed

### 3.1 Wrong sidecar backend target

Earlier MPTCP forwards were issuing session tokens for `149.104.3.71:5201` instead of `127.0.0.1:5201`.
This caused the sidecar to loop back through its public address instead of using the local backend.

Current expected token payload:

```json
{"target_host":"127.0.0.1","target_port":5201,"target_protocol":"tcp","service_id":1,"forward_id":19}
```

### 3.2 Slave-to-sidecar peer endpoint learning was unreliable

The slave WireGuard underlay originally omitted an explicit `Endpoint` for the sidecar peer.
As a result, slaves learned whichever source address the sidecar happened to emit from.

Current fixed behavior:

- slave underlay peers explicitly point to `161.248.136.183:14969`
- sidecar remains reachable for both slave legs

### 3.3 Stale global MPTCP endpoints polluted later forwards

The Linux MPTCP endpoint table is global, not per-forward.
Old endpoints from retired forwards remained in the kernel and interfered with later MPTCP tests.

Current client-agent logic now:

- removes stale `10.203.*` endpoints not required by active forwards
- keeps the endpoint table aligned to active MPTCP forwards only

## 4. Live-Tuned Settings That Performed Best

### 4.1 WireGuard MTU

Validated best test result so far:

- `MTU = 1382`

Observed effect:

- much smaller reverse-direction startup hole
- better steady-state throughput
- no need to fall back to `1280` at this stage

### 4.2 TCP congestion control

Validated best result so far:

- keep `bbr`

Observed effect:

- `cubic` reduced throughput in this topology
- `bbr` remains the better current default

### 4.3 MPTCP endpoint flags

Validated best result so far:

- client endpoints: `subflow`
- sidecar endpoints: `signal`

Observed effect:

- forcing `fullmesh` on the client endpoints performed worse in the current topology
- current code should prefer conservative `subflow` over `fullmesh`

### 4.4 NAT-related MPTCP behavior

Recommended and now reflected in code for sidecar runtime:

- `net.mptcp.allow_join_initial_addr_port=0`

Reason:

- the sidecar sits behind a NAT/ingress arrangement
- extra subflows should prefer addresses explicitly signaled through `ADD_ADDR`

## 5. Measured Behavior

These are representative results from the current test campaign, not contractual limits.

### 5.1 Before MTU 1382

Typical reverse behavior:

- first 3 to 4 seconds near zero
- later seconds improve
- averages often stayed around `100 to 190 Mbit/s`

### 5.2 After MTU 1382

Representative reverse test on `11009`:

- middle and late intervals rose into the `300 to 396 Mbit/s` range
- full 12-second receiver average reached about `301 Mbit/s` in the best observed run

Representative follow-up reverse test after later rollbacks/tuning changes:

- steady-state often sits around `180 to 337 Mbit/s`
- averages still vary depending on early connection setup behavior

### 5.3 Current structural limit

Even when both legs carry traffic, the observed aggregation is still weaker than the theoretical sum.
This is likely due to a mix of:

- Linux global MPTCP state shared across forwards
- user-space Python relay overhead
- reverse-direction control/data socket startup behavior
- uneven path selection and subflow utilization

## 6. Code Changes Landed In Workspace

### Control plane / provisioning

- `master/src/Support/ProvisioningService.php`

Current responsibilities:

- emit correct MPTCP underlay metadata
- attach device hints to MPTCP endpoint definitions
- persist sidecar endpoint information for slave underlay peers
- emit default WireGuard MTU for MPTCP underlay generation

### Client control plane

- `client-agent/client_agent.py`
- `client-agent/install_forward_runtime.py`

Current responsibilities:

- optional `disable_auto_updates` switch for test stability
- global MPTCP endpoint cleanup for stale `10.203.*` state
- first-stage migration toward client-agent-owned global MPTCP state
- prevent per-forward helpers from owning global endpoint management

### Client runtime

- `client-agent/leikwan_mptcp_client.py`

Current responsibilities:

- build WG underlay configs with route pinning
- endpoint programming with exact flag matching
- reverse-direction half-close handling improvements
- support for endpoint `dev` bindings

### Sidecar runtime

- `sidecar-agent/sidecar_agent.py`
- `sidecar-agent/leikwan_mptcp_sidecar.py`

Current responsibilities:

- sidecar WG underlay config generation with route pinning
- sidecar endpoint programming with exact flag matching
- support for endpoint `dev` bindings
- sidecar-side `allow_join_initial_addr_port` configuration

### Slave runtime

- `slave-agent/slave_agent.py`

Current responsibilities:

- explicit sidecar peer endpoint for WireGuard underlay
- persistent keepalive for sidecar peer
- support for persisted MTU in slave underlay config generation

## 7. Temporary Test Controls

The test client currently supports a local switch to suppress automatic self-update noise:

- config key: `disable_auto_updates`

This is useful only for controlled tuning sessions and should not be assumed to be the production default.

## 8. What Still Needs Work

### 8.1 Client-side global MPTCP ownership

The current workspace already contains the first step:

- `client-agent` now cleans stale endpoints
- per-forward helper configs can disable direct endpoint management

The next logical step is to move all endpoint and limit programming fully into `client-agent`, so no per-forward runtime mutates global kernel MPTCP state.

### 8.2 Reverse-direction startup hole

Even after the MTU improvement, reverse tests can still show:

- low throughput for the first few seconds
- fluctuating averages across runs

This is still a live issue.

### 8.3 Data-plane efficiency

The current Python relay path is good enough to validate behavior but likely not good enough to fully saturate the combined line rate consistently.

## 9. Current Recommendation

For the next rollout stage:

1. Keep `MTU = 1382`
2. Keep `bbr`
3. Keep client endpoint flags conservative (`subflow`)
4. Keep sidecar endpoint flags conservative (`signal`)
5. Continue the refactor so only `client-agent` manages global MPTCP endpoint state

## 10. Source References Used For Configuration Decisions

- Linux MPTCP kernel documentation:
  https://www.kernel.org/doc/html/next/networking/mptcp.html
- Linux MPTCP sysctl documentation:
  https://www.kernel.org/doc/html/v6.12/networking/mptcp-sysctl.html
- MPTCP for Linux path-manager documentation:
  https://www.mptcp.dev/pm.html
- MPTCP for Linux load-balancer / ingress notes:
  https://www.mptcp.dev/load-balancer.html
