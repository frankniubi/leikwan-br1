# LeiGod Slave Preconnect Reuse Design

## Goal

Add a Linux-client-only fast path for `leigod_mode` + MPTCP forwards where the client connects to a slave-owned preconnect relay instead of creating its own local WG/UoT leg. The slave keeps the existing slave -> sidecar managed LeiGod/lguot uplink warm, and Linux clients reuse that relay.

## Current runtime evidence

Linux support is already mostly present:

- `client-agent/leikwan_mptcp_client.py` prefers `slave_relay_host` + `slave_relay_port` and connects to that relay without sending the sidecar handshake locally.
- `wstunnel-main/leikwan-mptcp-relay/src/client.rs` does the same in the Rust relay: `tcp_remote_addr()` prefers `slave_relay_host`/`slave_relay_port` and logs slave-preconnect mode.
- `wstunnel-main/leikwan-mptcp-relay/src/bond.rs` can derive per-leg slave relay targets from `slave_preconnect` + `preconnect_relay_port`.
- `client-agent/install_forward_runtime.py` already suppresses direct self-leg endpoints when top-level slave preconnect is enabled.
- `slave-agent/slave_agent.py` can start `leikwan-preconnect-relay.py` when a slave underlay has `slave_preconnect` and `preconnect_relay_port`.

The blocker is the panel contract, not the Linux relay primitive. `master/src/Support/ProvisioningService.php` currently suppresses preconnect for `backend_mode=leigod_mode` so clients do not get `slave_relay_host`, `slave_relay_port`, `slave_preconnect`, or `preconnect_relay_port` for that mode.

## Non-goals

- Do not change Android in this pass. Current Android hides MPTCP forwards and has no preconnect contract support.
- Do not change Windows in this pass. Windows already has generic slave-preconnect support when a Windows-visible relay is emitted, but this feature is scoped to Linux client-agent/runtime first.
- Do not make clients connect directly to sidecars.
- Do not create client-local WG/UoT for this fast path.
- Do not use ports outside the slave ingress constraint. For this environment, public slave ingress ports must stay within `7000-9000`.

## Architecture

The final path is:

```text
Linux client -> slave public preconnect relay -> slave local WG endpoint -> slave static leigod/lguot uplink -> sidecar WG -> sidecar MPTCP service
```

The client only sees a normal MPTCP runtime with:

```json
{
  "slave_relay_host": "<slave public host>",
  "slave_relay_port": 7xxx,
  "underlays": [],
  "manage_mptcp_endpoints": false,
  "subflow_limit": 1
}
```

The slave receives the real underlay payload and keeps the expensive transport warm:

```json
{
  "slave_preconnect": true,
  "preconnect_relay_port": 7xxx,
  "preconnect_listen_host": "0.0.0.0",
  "preconnect_advertise_host": "<slave public host>",
  "sidecar_static_uplink": {
    "outer_transport": "leigod",
    "leigod_uot_impl": "lguot"
  }
}
```

## Port policy

Use a dedicated LeiGod preconnect allocator, not the old generic `38000-39999` preconnect range.

- Data port range: `7000-8999`
- Health port: `data_port + 1`, therefore always `7001-9000`
- Add config keys:
  - `LEIGOD_PRECONNECT_RELAY_PORT_RANGE_START`, default `7000`
  - `LEIGOD_PRECONNECT_RELAY_PORT_RANGE_END`, default `8999`

The allocator must clamp to `7000-8999` even if config is mis-set, because Canton/Shanghai slave public ingress constraints require `7000-9000`.

## Panel contract changes

Add an opt-in gate:

```text
LEIGOD_SLAVE_PRECONNECT_REUSE=true
```

When the gate is enabled and a forward is `backend_mode=leigod_mode` + `client_mode=mptcp`:

1. Keep publishing `sidecar_uplink` / `sidecar_uplink_selector` so slave and sidecar agents maintain the managed leigod/lguot uplink.
2. Publish slave underlay preconnect fields to the slave agent.
3. Publish a relay-only Linux client MPTCP config:
   - top-level `slave_relay_host` is the selected slave public host, not the private `slave_address`;
   - top-level `slave_relay_port` is the new 7000-8999 data port;
   - client `underlays` is empty;
   - `manage_mptcp_endpoints=false`;
   - `subflow_limit=1`.

When the gate is disabled, keep current behavior exactly. The existing `provisioning_leigod_no_preconnect_test.php` must keep passing for default config.

## Slave-agent changes

`slave-agent/slave_agent.py::ensure_preconnect_relay()` currently binds the relay to `slave_address`. For this feature it must honor:

- `preconnect_listen_host`, defaulting to the old `slave_address` behavior when absent;
- `preconnect_advertise_host`, used only for metadata/logging/future heartbeat, not binding;
- `preconnect_health_port`, optional. If absent, keep current `relay_port + 1` behavior.

The relay still connects to the sidecar via `sidecar_address` and `sidecar_mptcp_port`. For leigod-mode reuse, `sidecar_address` should be the effective address reachable through the already-managed static sidecar uplink.

## Linux client behavior

No new Linux binary behavior should be required. The client runtime already has the right fast path:

- top-level `slave_relay_host` + `slave_relay_port` means connect to slave relay;
- no local sidecar handshake;
- no client-local WG/UoT if panel emits empty `underlays`;
- Rust and Python relays both understand the slave relay fields.

The implementation should add regression tests to lock this down for the leigod path.

## Success criteria

- A leigod-mode MPTCP Linux client config can be generated with slave preconnect reuse enabled.
- That config does not ask the client to create local WG/UoT underlays.
- The slave receives and starts a public preconnect relay on a `7000-9000` port.
- The relay uses the slave's already-managed leigod/lguot sidecar uplink.
- Default leigod behavior stays unchanged unless `LEIGOD_SLAVE_PRECONNECT_REUSE=true`.
- Android remains hidden/unsupported for this path.
- Windows generic preconnect behavior remains unchanged.

## Verification commands

```powershell
php master/tests/provisioning_leigod_no_preconnect_test.php
php master/tests/provisioning_leigod_slave_preconnect_reuse_test.php
py -3 -m pytest --import-mode=importlib slave-agent/tests/test_preconnect_relay_leigod_reuse.py client-agent/tests/test_leigod_slave_preconnect_reuse_runtime.py
go test ./...
```

Run the Go command from `wstunnel-main/leikwan-mptcp-relay` if the plan touches Rust tests. If Rust code is not modified, existing Rust tests are evidence only, not required for this feature.