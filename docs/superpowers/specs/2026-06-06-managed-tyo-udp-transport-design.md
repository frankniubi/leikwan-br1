# Managed TYO UDP Transport Design

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make UDP forwards converge automatically to a TYO-terminated, panel-provisioned transport path that rides the existing LeiGod carrier via `lguot`, with no customer-facing provider branding and no dependence on the SG experimental `wg-uot` path.

**Architecture:** The production data plane stays within the existing static sidecar uplink model already supported by the slave and sidecar agents. The panel publishes a per-forward/per-node uplink payload that selects the TYO sidecar, the LeiGod line, and `leigod_uot_impl=lguot`; the slave agent turns that into a WG interface whose peer endpoint is the local `lguot` UDP listener, and the sidecar agent terminates the framed UDP on the TYO sidecar. The existing emergency TCP-only per-port shim can remain for diagnostics, but the durable product path must be lifecycle-managed by panel/agent reconcile for new installs and every future publish.

**Tech Stack:** PHP panel/provisioning, Python slave agent, Python sidecar agent, Rust `lguot`, systemd, WireGuard, existing test suites in `master/tests`, `slave-agent/tests`, and `sidecar-agent/tests`.

---

## Problem Statement

The current sh36 validation host has a durable TCP forwarding fix, but UDP/12022 is still routed through the old SG/private path and not through the managed TYO transport. The experimental `wg-uot` path is not suitable as a production customer path because it terminates in SG, depends on hand-built state, and is not automatically provisioned for new installs. The product needs a neutral, customer-facing architecture that automatically converges from panel publish state on fresh clients and all future forwards.

## Goals

- Make UDP forwards use a TYO-terminated managed transport path instead of the SG experimental path.
- Keep the transport automatic for newly installed clients/slaves/sidecars.
- Ensure panel publish of a new forward config triggers all required reconcile on slave and sidecar.
- Keep provider names out of customer-facing UI, docs, logs, and install output.
- Reuse existing static sidecar uplink primitives rather than inventing a new transport.
- Preserve the existing emergency TCP shims only as diagnostics, not as the product architecture.

## Non-Goals

- Do not redesign the whole MPTCP system.
- Do not introduce a raw SOCKS-UDP dependency on LeiGod.
- Do not reuse the SG experimental `wg-uot` path as production.
- Do not require a manually preconfigured sh36-only tunnel for correctness.
- Do not rename internal code symbols unless the implementation work requires it.

## User-Facing Naming Rules

- Never show the provider name in customer-facing UI, install output, docs, or public config labels.
- Use neutral labels such as `Managed TYO transport`, `Accelerated TYO route`, or `Regional transport`.
- Internal field names may still reference existing implementation names where required by compatibility, but user-visible text must not.

## Architecture

### Data Plane

The data plane should reuse the existing static sidecar uplink shape already consumed by the agents:

- `ProvisioningService::buildMptcpSlaveUnderlayPayload(...)` already emits a static uplink payload containing `outer_transport`, `sidecar_endpoint_host`, `sidecar_endpoint_port`, `local_uot_udp_port`, `sidecar_address`, `overlay_peer_allowed_ips`, `tcp_session_token`, and `udp_session_token`.
- `slave-agent/slave_agent.py::apply_sidecar_uplink(...)` already knows how to consume `outer_transport=leigod` and `leigod_uot_impl=lguot` by starting the `lguot` client and repinning the WG endpoint to the local UDP listener.
- `sidecar-agent/sidecar_agent.py::apply_slave_sidecar_uplink(...)` already knows how to consume `outer_transport=leigod` and `leigod_uot_impl=lguot` by starting the `lguot` server on the sidecar and terminating the framed UDP into the local WG listener.
- `tools/lguot/main.go` already provides the carrier primitive: a persistent TCP stream through the LeiGod SOCKS endpoint carrying framed UDP datagrams.

The production TYO transport is therefore not a new tunnel type. It is a control-plane-managed selection of the existing static sidecar uplink fields so the slave and sidecar agents converge automatically to a TYO endpoint.

### Control Plane

The panel must publish a TYO-sidecar uplink for each eligible forward/combination/node/lane.

The published payload must include:

- `outer_transport = "leigod"`
- `leigod_uot_impl = "lguot"`
- a TYO sidecar public host
- a collision-free TCP listen port for the sidecar-side `lguot` server
- a stable local UDP listen port on the slave for the `lguot` client
- a stable scoped token or password
- WireGuard local/peer addresses
- overlay AllowedIPs and MTU
- the correct TYO overlay/service endpoint for the actual target

### Lifecycle

The system must converge automatically on:

- new client/slave installation
- forward create
- forward update
- forward disable/delete
- sidecar point changes
- line changes
- agent version changes

The provisioning path must be authoritative so a publish event is sufficient to produce the correct runtime state. No manual shell intervention should be required for correctness.

## Loadflows

### TCP Loadflow

Current emergency/direct shims can keep using a direct TCP listener path, but the durable target architecture is still the managed TYO uplink:

```text
customer TCP
  -> slave public TCP listener
  -> slave-managed forward runtime
  -> TYO sidecar overlay endpoint
  -> static WG uplink
  -> local lguot UDP listener
  -> lguot client TCP stream through LeiGod SOCKS
  -> TYO sidecar lguot server
  -> TYO sidecar WG interface
  -> TYO target TCP service
```

### UDP Loadflow

UDP must use the same managed TYO uplink and must not depend on the SG experimental interface:

```text
customer UDP
  -> slave public UDP listener
  -> slave-managed forward runtime
  -> TYO sidecar overlay endpoint
  -> static WG uplink
  -> local lguot UDP listener
  -> lguot client TCP stream through LeiGod SOCKS
  -> TYO sidecar lguot server
  -> TYO sidecar WG interface
  -> TYO target UDP service
```

## Required Control-Plane Behavior

### Panel publish

When the panel publishes a new forward config:

- the correct TYO sidecar endpoint must be selected automatically
- the correct LeiGod line must be selected automatically
- `leigod_uot_impl` must be set to `lguot` for the TYO path
- the payload must contain stable peer/address/port/token material
- the payload must encode the correct TYO target endpoint, not the SG experimental path

### New installs

A newly installed client/slave/sidecar must converge from the published payload alone:

- the slave agent must install the correct WG uplink and `lguot` client
- the sidecar agent must install the matching `lguot` server
- the public forward runtime must target the TYO overlay endpoint
- the customer must not need any manual `wg-uot` or SG-specific setup

### Port and token safety

- Sidecar TCP listen ports must be collision-free and owned by the control plane.
- Token material must be stable across republish so live lanes do not churn.
- Cleanup must remove stale managed services and listeners on delete/disable.

## File-Level Boundaries

- `master/src/Support/ProvisioningService.php` remains the control-plane source of truth for static sidecar uplink payload generation.
- `slave-agent/slave_agent.py` remains the slave-side reconciliation engine for WG + `lguot` client provisioning.
- `sidecar-agent/sidecar_agent.py` remains the sidecar-side reconciliation engine for WG + `lguot` server provisioning.
- `tools/lguot/main.go` remains the carrier primitive and should not become a policy engine.
- `artifacts/slave-agent.py` and `artifacts/sidecar-agent.py` must stay aligned with the source copies when code changes are made.

## Testing Strategy

- Add/expand PHP tests for the provisioning payload so the published uplink explicitly selects the TYO `lguot` carrier and TYO endpoint.
- Add/expand Python tests for slave-side and sidecar-side reconciliation so `outer_transport=leigod` + `leigod_uot_impl=lguot` produce the expected services.
- Add tests for token/port stability and collision detection.
- Add cleanup tests for forward disable/delete.
- Keep the existing sh36 live validation as a final smoke test only, not as the architecture itself.

## Acceptance Criteria

- A newly published forward config automatically converges to the TYO transport without manual intervention.
- New installs automatically acquire the correct TYO path.
- The user-facing product wording stays provider-neutral.
- The SG experimental `wg-uot` path is not required for production correctness.
- Current sh36 validation still works, but the architecture is not sh36-specific.
- TCP/UDP both use the managed TYO transport model.
