# Cloudflare Location Groups + Forward Combinations Design

**Date:** 2026-04-20  
**Status:** Approved design  
**Scope:** LeiKwan panel + provisioning + slave-agent, phase-one support for `single` and `bond`

## Goal

Add a new Cloudflare-backed forwarding mode that lets each **slave** choose the best Cloudflare IP from one admin-defined location group, while keeping the existing `client -> slave -> sidecar` topology and hiding all backend details from tenants behind admin-defined **forward combinations**.

## Hard Rules

- Keep topology `client -> slave -> sidecar`.
- Sidecars do **not** know which Cloudflare IP was selected.
- Slaves know only the forwarding identity and the assigned Cloudflare location group.
- One concrete forward binds to **one** Cloudflare location group only.
- One slave shares the same best-IP selection result under one location group.
- Tenant panel never knows what backend they are choosing.
- Tenant chooses **forward combination only**.
- Lowest latency, lowest jitter, and highest real transfer speed always win.
- Slave ingress for this environment remains within `7000-9000`.

## Phase-One Scope

### In scope

- New independent Cloudflare location-group asset model
- New admin-managed forward-combination model
- New `cf_mode` path:
  - `single`
  - `bond`
- Existing `current_mode` path expressed through forward combinations
- Slave-side best-IP selection, cache, cooldown, and reprobe logic
- Admin observability for current selected Cloudflare IP and quality stats

### Out of scope

- `mptcp` support in the new Cloudflare-backed mode
- Explicit `race` option
- Tenant-visible backend internals such as slave group, sidecar, or CF group
- Direct client-to-sidecar topology changes

## Product Model

### Admin-visible abstraction

Admin manages three asset layers:

1. **Cloudflare Location Group**  
   A reusable set of CIDRs or single IPs for one region, for example `JP`, `SG`.

2. **Sidecar Point**  
   Existing target-side sidecar definition.

3. **Forward Combination**  
   An admin-facing composition that packages backend details into one tenant-facing choice.

### Tenant-visible abstraction

Tenant sees only friendly combination names such as:

- `SHA -> HKG`
- `SHA -> NRT`

Tenant does **not** see:

- slave group
- sidecar point
- Cloudflare location group
- backend mode
- internal routing strategy

## Forward Combination Model

A forward combination is the only backend selector available to tenants.

Suggested internal fields:

- `id`
- `name`
- `tenant_label`
- `node_group_key`
- `backend_mode`
  - `current_mode`
  - `cf_mode`
- `sidecar_point_id`
- `cf_location_group_id` (required for `cf_mode`, null for `current_mode`)
- `client_mode`
  - `single`
  - `bond`
- `bond_ingress_mode`
- `is_active`
- `sort_order`
- `notes`

Examples:

- `SHA -> HKG` = `slave group A + current_mode + sidecar A + bond`
- `SHA -> NRT` = `slave group A + cf_mode + CF location group JP + sidecar B + bond`

## Cloudflare Location Group Model

Cloudflare location groups are independent assets, not fields on slave groups.

### Table: `cf_location_groups`

Suggested fields:

- `id`
- `name`
- `region_code`
- `probe_mode` (`tcp`, `icmp`, `auto`)
- `cache_ttl_seconds`
- `switch_improvement_ms`
- `max_candidates`
- `is_active`
- `created_at`
- `updated_at`

### Table: `cf_location_group_cidrs`

Suggested fields:

- `id`
- `group_id`
- `cidr`
- `priority`
- `is_active`
- `created_at`
- `updated_at`

## Forward Data Contract

A concrete forward stores the selected combination id plus a resolved runtime snapshot.

Suggested forward additions:

- `forward_combination_id`
- `backend_mode`
- `cf_location_group_id`
- `resolved_sidecar_point_id`
- resolved `node_group_key`
- resolved `client_mode`
- resolved `bond_ingress_mode`

This design should snapshot the combination into the forward at create or republish time so runtime behavior stays stable even if an admin later edits the combination.

## Runtime Identity Rules

Forward identity remains based on the existing runtime elements:

- `sni`
- `port`
- `ws path / route_key`
- `ingress_auth_token`

The Cloudflare group changes only **which remote IP the slave dials**, not how the target forward is identified at the sidecar.

## Data Path

### `current_mode`

The path remains the current production shape.

### `cf_mode`

The phase-one path is:

```text
client -> slave -> selected CF IP from one location group -> WSS -> sidecar
```

Concretely:

- socket target: `selected_cf_ip:port`
- TLS SNI: original `sni`
- Host header: original `sni`
- path / route key: original `route_key`
- sidecar receives a normal WSS connection and stays unaware of the chosen Cloudflare IP

## Slave Responsibilities

The slave is the only runtime component that selects the Cloudflare IP.

For `cf_mode`, the slave receives:

- `cf_location_group_id`
- group probe settings
- group CIDR list
- `sni`
- target port
- `route_key`
- `ingress_auth_token`

The slave then:

1. expands and samples candidates from the CIDR list
2. probes candidates locally
3. chooses the best IP for this slave under this group
4. caches the result
5. uses that best IP for outbound WSS toward the sidecar
6. reselects only on expiry or degradation

## Shared Latency / Best-IP Cache

Cache scope is:

```text
one slave + one cf_location_group_id
```

Not per-forward.

This means multiple forwards on the same slave and same location group share one best-IP decision.

Suggested per-group cached state:

- `selected_ip`
- `backup_ips[]`
- `last_latency_ms`
- `last_jitter_ms`
- `last_probe_at`
- `consecutive_failures`
- `cooldown_until`
- `last_switch_at`
- `probe_mode`

## Selection Strategy

### Priority order

1. can connect
2. lower TCP latency
3. lower jitter
4. better recent real-flow performance
5. fewer recent failures

### Probe mode

Preferred default is `auto`, which behaves like:

- prefer TCP probing against the real remote port
- use ICMP only as a fallback or supplemental signal

Reason: the real user outcome depends on fast, stable WSS establishment, so TCP probe quality is more meaningful than ICMP alone.

### Candidate handling

- support `/32` and CIDR ranges
- large ranges must be sampled or capped by `max_candidates`
- prefer higher-priority CIDR entries first
- keep a small backup list for fast failover within the same group

### Switching rules

Do **not** reselect on every connection.

Reselect only when:

- cache TTL expires
- current IP fails repeatedly
- current IP degrades clearly
- a new IP is measurably better by at least `switch_improvement_ms`
- admin requests a refresh

This keeps latency low without creating probe-induced jitter.

## Compatibility Matrix

### Phase-one allowed combinations

#### `current_mode`
- `single`
- `bond`

#### `cf_mode`
- `single`
- `bond`

### Explicitly blocked in phase one

- `race`
- `mptcp`

Validation must reject:

- `cf_mode + race`
- `cf_mode + mptcp`
- any forward combination outside `single` or `bond`

## UDP / WireGuard Boundary

### `current_mode`
Current direct path continues carrying existing advanced capabilities outside this project scope.

### `cf_mode`
Phase one is optimized for low-latency and high-speed Cloudflare-backed WSS transport in `single` and `bond`.

For this phase:

- do not promise `mptcp`
- do not model this as native sidecar-underlay `WireGuard`
- keep UDP support only where the existing compatibility path already makes sense

This keeps the new mode honest and protects speed-first delivery.

## Admin UI Changes

### New admin pages

1. **Cloudflare Location Groups**
   - create / edit groups
   - manage CIDRs
   - set probe policy and cache knobs

2. **Forward Combinations**
   - create / edit combinations
   - choose slave group
   - choose `current_mode` or `cf_mode`
   - choose sidecar point
   - choose Cloudflare location group when `cf_mode`
   - choose `single` or `bond`

### Existing forward creation logic

Tenant forward creation should no longer expose backend pieces directly.

## Tenant UI Changes

Tenant forward creation should expose only:

- `Forward Combination`
- `Target Host`
- `Target Port`
- `Local Listen Port`
- `Transport`

Tenant must not see:

- slave group
- sidecar point
- CF location group
- backend mode
- `race`
- `mptcp`

## Provisioning Changes

`ProvisioningService` should resolve the chosen forward combination into a forward runtime snapshot.

For `cf_mode`, provisioning should include enough data for the slave to operate independently:

- `cf_location_group_id`
- `probe_mode`
- `cache_ttl_seconds`
- `switch_improvement_ms`
- `max_candidates`
- `cidrs[]`
- `sni`
- `port`
- `route_key`
- `ingress_auth_token`

For `current_mode`, provisioning continues using the existing direct path contract.

## Observability

Admin should be able to see, per slave and per Cloudflare location group:

- current selected Cloudflare IP
- latency
- jitter
- failure count
- last switch time
- last reprobe time

Forward detail should show the forward combination name and id, but tenant views should remain backend-blind.

## Error Handling

### Invalid configuration

Reject at save or publish time when:

- a combination in `cf_mode` has no location group
- a combination in `cf_mode` has no sidecar point
- a combination requests disallowed client modes
- all CIDRs are disabled or empty

### Runtime degradation

If the current selected IP degrades:

- first use the same-group backup set
- then reprobe the same group
- never switch to another location group automatically, because one forward binds to one group only

### Last-resort behavior

When every candidate in the assigned group looks bad:

- keep the least-bad usable candidate rather than thrashing continuously
- log and report degraded state to panel observability

## Testing Strategy

### Panel tests

Add coverage for:

- CRUD of location groups and CIDRs
- CRUD of forward combinations
- forward create/save from combination snapshot
- validation rejects `race` and `mptcp`
- provisioning payload includes the correct Cloudflare group block only for `cf_mode`

### Slave tests

Add coverage for:

- per-slave per-group shared cache behavior
- CIDR expansion and candidate cap logic
- TCP probe scoring and fallback
- cooldown and reprobe decisions
- best-IP reuse across multiple forwards in the same group
- `bond` mode using selected CF IP without changing `route_key` identity

### Integration tests

Validate:

- `current_mode + single`
- `current_mode + bond`
- `cf_mode + single`
- `cf_mode + bond`

## Rollout Plan

### Step 1
Add panel schema and admin CRUD for location groups and forward combinations.

### Step 2
Move tenant forward creation to combination-only selection.

### Step 3
Extend provisioning payload and validation.

### Step 4
Implement slave-side group-scoped best-IP cache and probe logic.

### Step 5
Wire `cf_mode` into `single`.

### Step 6
Wire `cf_mode` into `bond`.

### Step 7
Add admin observability and selective refresh tools.

## Non-Goals For This Design

- redesigning sidecar runtime ownership
- changing the existing sidecar's awareness model
- making tenants aware of backend complexity
- adding explicit `race`
- adding `mptcp` into the new Cloudflare-backed path

## Final Design Summary

This design introduces an admin-controlled, tenant-hidden backend selection layer using forward combinations. Cloudflare location groups are independent reusable assets. Each forward binds to exactly one location group, and each slave shares one best-IP decision per group. Sidecars remain unaware of the selected Cloudflare IP. Phase one stays intentionally tight: only `single` and `bond`, no explicit `race`, no `mptcp`, with latency, jitter, and real speed treated as the top priority.
