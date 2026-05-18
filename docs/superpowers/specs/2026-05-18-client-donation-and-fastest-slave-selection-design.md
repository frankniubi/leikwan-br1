# Client Donation as Public Slave + Multi-Slave Forward Combination Design

**Date:** 2026-05-18
**Status:** Draft design (awaiting approval)
**Scope:** LeiKwan panel (PHP) + provisioning + slave-agent + client-agent + admin UI
**Branch baseline:** `master`
**Related prior work:**
- `docs/superpowers/specs/2026-05-14-multi-slave-group-self-leg-cf-combination-design.md`
- `docs/superpowers/plans/2026-05-17-panel-republish-client-as-slave-and-multigroup-leg-cap-plan.md`

---

**Reliability amendment, 2026-05-19:** This design uses **client self-leg donor identity**, not a rented `nodes` identity. A donated client is represented by a dedicated `forward_combination_self_leg_donors` table keyed by `(forward_combination_id, client_id, desktop_client_session_id/device_id)`. Rented slaves still use `nodes` + `service_endpoints`; donated self-leg candidates are emitted directly into `candidate_pool` as `candidate_kind = 'donated_slave_leg'` and count against `helper_group_cap`. Sidecar and slave agents receive explicit probe/policy payloads from the panel; they do not infer donation policy from unrelated runtime fields. Security claims are limited to panel/API/UI/log redaction and CF/WSS masking where configured; phase one does **not** promise that low-level runtime tools can hide endpoint IP:PORT.

## 1. Problem Statement

### Feature 1 — "Donate your client-as-a-slave to all users who can use this forward combination"

Today the **self-leg** (a.k.a. "client-as-a-slave") is strictly device-private:
the client's own device may use it as a latency anchor, but no other tenant
can borrow it. We want to let an admin opt-in to **public donation** for a
specific forward combination, gated by:

1. an **admin-set desired latency target** on the forward combination (e.g. `45 ms`),
2. an **automatic measured-latency check** on the donating client (must measure
   `<= 75% * desired_latency_ms`, i.e. comfortably under target, with hysteresis),
3. **port openness detection** — the donating client must have at least one
   reachable inbound port through which other tenants' traffic can be brought
   in (NAT type / UPnP / explicit port mapping).

When all gates hold, that client is **shared publicly** as a candidate slave
**only for the same forward combination** — never globally, never for unrelated
combinations. Withdraw the donation as soon as any gate stops holding.

### Feature 2 — Multiple slaves selectable per forward combination, fastest-4 chain

Today a forward combination already references multiple ordered helper slave
groups (`forward_combination_helper_groups`), but the panel UI surface to pick
**individual slaves** inside one combination is missing, and chain assembly is
based on group ordering alone.

We want:

1. The admin panel to allow **selecting multiple individual slaves** (and/or
   groups) for one forward combination.
2. The runtime to keep only the **4 fastest slaves** in the active chain,
   measured by the link `slave -> sidecar -> endpoint` (round-trip / one-leg
   interactive RTT, the same signal the panel already collects on heartbeat).
3. **All forwards** under one forward combination share the **same** slave
   selection (i.e. the chain is per-combination, not per-forward).
4. The cap of `4` is the **default**, but reuses the existing
   `helper_group_cap` (a.k.a. `Max Legs Used (cap)`) knob so admins can override.

---

## 2. Goals and Non-Goals

### Goals

- Let admins safely **donate eligible client-as-a-slave** sessions to all
  tenants entitled to a specific forward combination.
- Make donation gates **measurable, observable, and revocable** without
  redeploying the client.
- Let admins **pick multiple slaves** for one forward combination, in one
  panel surface, and have all forwards under that combination share the
  selection.
- Pick the **fastest 4** at runtime by the existing slave-to-sidecar-to-endpoint
  RTT signal.
- Keep the existing `client -> slave -> sidecar -> target` topology unchanged.
- Reuse `helper_group_cap` semantics (the "Max Legs Used (cap)" knob).

### Non-goals

- **No** new direct `client -> target` shortcut.
- **No** cross-combination donation (a donated client is reachable only via the
  forward combination it was donated for).
- **No** persistent donation across device sessions: donation is bound to a
  device session and revoked on session expiry.
- **No** inbound traffic from arbitrary third-party tenants directly onto the
  donor's home network — donor traffic always lands at the **panel-controlled
  sidecar** for the same combination, never at the donor's LAN.
- **No** Windows / Android / Desktop client implementation in phase one.
  Phase one targets Linux client-agent, panel, and slave-agent.
- **No** rewrite of `forward_combination_helper_groups`. The new "individual
  slave selection" coexists with the helper-group ordering.
- **No** change to the meaning of `runtime_preference` values.

---

## 3. Hard Rules (binding)

These rules are non-negotiable for any implementer.

1. **Donation is per forward combination.** A client donated for combination
   `A` is never visible to combination `B`, even if `B` is owned by the same
   admin or shares a sidecar.
2. **Donation requires admin opt-in.** A new combination-level boolean
   `allow_public_self_leg_donation` defaults to `0`. No client is donated
   unless the admin flips it on for that combination.
3. **Donation requires a measurable upper bound.** The combination must define
   `desired_latency_ms > 0`. Donations only occur when measured RTT
   `<= floor(desired_latency_ms * 0.75)` (the **donation gate threshold**) for
   `donation_qualify_window_seconds` continuously.
4. **Donation requires at least one open inbound port.** The donor's slave-side
   listener must be reachable from the sidecar's probe path. If port openness
   cannot be confirmed, donation does not happen.
5. **Donation is automatically revoked** when:
   - measured RTT `> desired_latency_ms` for `donation_revoke_window_seconds`, OR
   - the open-port probe fails twice in a row, OR
   - the donor's device session ends, OR
   - the admin disables `allow_public_self_leg_donation`.
6. **Donor traffic still goes through the panel-controlled sidecar.** Topology
   stays `consumer-client -> donor-client(slave role) -> sidecar -> target`.
   The donor never sees the consumer's plaintext target.
7. **Multi-slave selection in panel is per-combination, not per-forward.**
   All forwards under a forward combination MUST receive the same slave list.
8. **Fastest-4 selection uses RTT for `slave -> sidecar -> endpoint`** (the
   real chain), not raw client-to-slave latency. `4` is a default cap; the
   stored knob is `helper_group_cap` (label "Max Legs Used (cap)").
9. **Donation never overrides `helper_group_cap`.** A donated client self-leg
   counts against the cap exactly like any other slave candidate.
10. **Donor identity is client self-leg, not `nodes`.** Rented slaves remain
    `nodes` rows. Donated clients are tracked in
    `forward_combination_self_leg_donors`; do not create fake node rows for
    clients.
11. **No tenant-visible surface change.** Tenants still see "Forward
    Combination" only.

---

## 4. Glossary

- **Donor** — a client running client-agent that has been opted into
  donation by its owner and qualifies the latency + port gates.
- **Consumer** — any other tenant whose service is bound to the same forward
  combination and may use the donor as a relay slave.
- **Donation gate threshold** — `floor(desired_latency_ms * 0.75)`. A
  conservative lower bound the donor must beat to qualify.
- **Open inbound port** — a TCP (and optionally UDP) port that the
  panel-controlled sidecar can connect to from outside the donor's NAT.
- **Slave list (per combination)** — the set of slave node IDs (rented +
  donated + admin-pinned) the panel attaches to one forward combination.
- **Active chain** — the (up to) 4 slaves chosen at runtime for a session
  based on slave-to-sidecar-to-endpoint RTT.

---

## 5. Architecture Overview

```text
+--------------------------------------------------------------------+
|                       Admin panel (PHP)                            |
|--------------------------------------------------------------------|
|  forward_combinations:                                             |
|    + allow_public_self_leg_donation (new)                          |
|    + desired_latency_ms (new)                                      |
|    + donation_qualify_window_seconds (new)                         |
|    + donation_revoke_window_seconds (new)                          |
|    helper_group_cap (existing, default 4) <- "Max Legs Used (cap)" |
|                                                                    |
|  forward_combination_slaves (new): explicit rented slave pinning  |
|  forward_combination_self_leg_donors (new): client donor state     |
+--------------------------------------------------------------------+
            |                       ^
            | publish/republish     | heartbeat (measured RTT,
            v                       |   port-open results, donation
+--------------------------+        |   eligibility)
|  Slave-agent (donor)     |--------+
|  + measures slave->       |
|     sidecar->endpoint RTT |
|  + listens on inbound     |
|     port; sidecar probes  |
|     reachability          |
|  + reports donation_self_check
+--------------------------+
            ^                       |
            | task pull             v
+--------------------------+        +-------------------------------+
|  Sidecar-agent           |<------>| Master panel selects fastest 4|
|  + probes donors         |        | per combination, publishes    |
|  + relays to endpoint    |        | task plan to slaves           |
+--------------------------+        +-------------------------------+
            |
            v
+--------------------------+
|  Endpoint (target)       |
+--------------------------+
```

---

## 6. Data Model Changes

All new columns/tables are **additive**. No existing column is renamed or
dropped.

### 6.1 `forward_combinations` — new columns

| Column | Type | Default | Notes |
|---|---|---|---|
| `desired_latency_ms` | `INT NOT NULL` | `0` | `0` disables both donation eligibility and chain ordering hints. Admin-only. |
| `allow_public_self_leg_donation` | `TINYINT(1) NOT NULL` | `0` | Master switch for Feature 1. |
| `donation_qualify_window_seconds` | `INT NOT NULL` | `60` | RTT must stay under threshold this long. |
| `donation_revoke_window_seconds` | `INT NOT NULL` | `30` | RTT may exceed `desired_latency_ms` this long before revoke. |
| `donation_min_open_ports` | `INT NOT NULL` | `1` | Minimum open inbound ports required. |

> The existing `helper_group_cap` (alias "Max Legs Used (cap)") column stays
> as-is. Its default of `4` is reused as the fastest-N selection cap.

Migration file: `master/migrations/20260518_add_forward_combination_donation_and_latency.sql`.

### 6.2 New table `forward_combination_slaves`

Explicit per-combination slave pinning, beyond what `forward_combination_helper_groups`
provides.

```sql
CREATE TABLE IF NOT EXISTS forward_combination_slaves (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    selection_kind VARCHAR(32) NOT NULL DEFAULT 'admin_pin',
        -- phase one values: 'admin_pin'. Donated client self-legs live in
        -- forward_combination_self_leg_donors, not this node table.
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fc_slaves_combo
      FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_slaves_node
      FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_fc_slaves (forward_combination_id, node_id),
    INDEX idx_fc_slaves_kind (forward_combination_id, selection_kind, is_active)
);
```

Migration file: `master/migrations/20260518_add_forward_combination_slaves.sql`.

### 6.3 New table `forward_combination_self_leg_donors`

Runtime ledger for valid **client self-leg donors**. This is the source of truth
for "valid client's client-as-a-slave" capacity. It deliberately references
`clients` / `desktop_client_sessions`, not `nodes`, because the donor identity is
the client self-leg.

```sql
CREATE TABLE IF NOT EXISTS forward_combination_self_leg_donors (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    desktop_client_session_id BIGINT UNSIGNED NULL,
    device_id VARCHAR(191) NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'qualifying',
        -- 'qualifying' | 'active' | 'revoked' | 'cooldown'
    donor_public_host VARCHAR(255) NULL,
    donor_public_port INT NULL,
    donor_transport VARCHAR(32) NOT NULL DEFAULT 'tcp',
        -- 'tcp' | 'udp' | 'tcp+udp' | 'cf_wss' | 'cf_wg'
    last_measured_latency_ms DECIMAL(10,2) NULL,
    qualify_started_at DATETIME NULL,
    activated_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoke_reason VARCHAR(64) NULL,
        -- 'latency_above_target' | 'port_probe_failed' | 'session_ended'
        -- 'admin_disabled' | 'cap_full'
    open_ports_json TEXT NULL,
        -- JSON array of {protocol, port, last_probe_at, ok}
    last_self_check_json JSON NULL,
    last_reverse_probe_json JSON NULL,
    last_heartbeat_at DATETIME NULL,
    owner_opt_in TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fc_self_leg_donors_combo
      FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_self_leg_donors_client
      FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_fc_self_leg_donors_desktop_session
      FOREIGN KEY (desktop_client_session_id) REFERENCES desktop_client_sessions(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_fc_self_leg_donor_session (forward_combination_id, client_id, desktop_client_session_id, device_id),
    INDEX idx_fc_self_leg_donors_state (forward_combination_id, state, updated_at),
    INDEX idx_fc_self_leg_donors_client (client_id, state),
    INDEX idx_fc_self_leg_donors_endpoint (donor_public_host, donor_public_port)
);
```

Migration file: `master/migrations/20260518_add_forward_combination_self_leg_donors.sql`.

When a donation reaches `state = 'active'`, provisioning **does not** write a
fake node row and does not insert a `forward_combination_slaves` row. Instead,
active rows from this donor table are merged into the per-combination fastest-N
candidate set and emitted to consumers as `candidate_kind = 'donated_slave_leg'`.

### 6.4 Heartbeat payload extensions (slave-agent)

`POST /api/node/heartbeat` body adds (all optional, backward-compatible):

```json
{
  "donation_self_check": [
    {
      "forward_combination_id": 12,
      "measured_chain_latency_ms": 38.4,
      "measure_window_seconds": 60,
      "open_ports": [
        {"protocol": "tcp", "port": 24443, "ok": true},
        {"protocol": "udp", "port": 24443, "ok": true}
      ],
      "checked_at": "2026-05-18T03:14:15Z"
    }
  ]
}
```

`measured_chain_latency_ms` MUST be the slave-side measured RTT for
`slave -> sidecar -> endpoint`, not for `client -> slave`.

### 6.5 Existing tables left **unchanged**

- `forward_combination_helper_groups` — keep ordering semantics.
- `service_endpoints` — chain materialization stays here.
- `bond_leg_reputation_current` — keep per-leg reputation.

---

## 7. Admin Panel UX

All changes live on the existing **Edit Forward Combination** page
(`AdminController::handleForwardCombinationEdit`).

### 7.1 New "Donation" section (Feature 1)

Rendered only when `backend_mode in {'current_mode', 'cf_mode'}` and
`client_mode in {'bond', 'mptcp'}` (single-slave forwards do not need
donation):

- **Desired latency (ms)** — int input, mapped to `desired_latency_ms`.
  Helper text: *"Slaves under this combination prefer not to exceed this RTT.
  Donation gate is 75% of this value."*
- **Allow public client-as-a-slave donation** — checkbox, mapped to
  `allow_public_self_leg_donation`.
- **Qualify window (s)** — default `60`, mapped to
  `donation_qualify_window_seconds`.
- **Revoke window (s)** — default `30`, mapped to
  `donation_revoke_window_seconds`.
- **Minimum open inbound ports** — default `1`, mapped to
  `donation_min_open_ports`.

### 7.2 New "Slaves" section (Feature 2)

Rendered for any combination:

- A **multi-select** widget listing nodes whose `group_key` belongs to one
  of the helper-group keys for this combination, plus the combination's
  primary `node_group_key`.
  - Filter chips: `Active`, `Degraded`, `Admin pinned`. Donors are shown in the separate Live donations table, not inside the node picker.
  - Selection persists into `forward_combination_slaves` with
    `selection_kind = 'admin_pin'`.
- A read-only table below: **Live donations** (`forward_combination_self_leg_donors`
  rows where `state = 'active'`). Columns: client name/session/device, donor endpoint (redacted by default), last RTT, qualifying
  since, open ports, action button **Revoke**.
- The "Max Legs Used (cap)" field already on this page (existing
  `helper_group_cap`) keeps its meaning. New helper text:
  *"Default 4. The runtime keeps only the fastest N slaves in the active
  chain, where speed = `slave -> sidecar -> endpoint` RTT."*

### 7.3 Tenant-facing surface

**No change.** Tenants still pick "Forward Combination" only.

---

## 8. Donation State Machine (Feature 1)

```
        (admin enables                  (qualify_window
         allow_public_self_leg_donation) elapsed under threshold)
              |                                |
   none ---->[qualifying]------------------->[active]
              ^   |                              |
              |   | (latency exceeds target,     | (latency above target
              |   |  or port probe fails twice)  |  for revoke_window,
              |   v                              |  or admin disables)
              | [cooldown]<----------------------+
              |   |
              |   | (cooldown expires)
              +---+
```

State transitions are computed by a **panel worker**, not the slave-agent.
Worker tick = `60 s` (matches existing SSL task worker cadence). The worker reads
and writes `forward_combination_self_leg_donors`; the donor-side agent only
reports raw measurements for the panel's active policy payload.

### Qualify gate (transition `none -> qualifying -> active`)

All must be true:

1. `forward_combinations.allow_public_self_leg_donation = 1`.
2. `forward_combinations.desired_latency_ms > 0`.
3. The donor row's latest `donation_self_check` reports
   `measured_chain_latency_ms <= floor(desired_latency_ms * 0.75)`
   for `donation_qualify_window_seconds` continuously.
4. The donor row's latest `donation_self_check` reports at least
   `donation_min_open_ports` open inbound ports.
5. The donor client session has a healthy heartbeat in the last `120 s`.
6. The donor row has `owner_opt_in = 1` and is not at `state = 'cooldown'`
   (cooldown = `300 s`).

When all hold, transition the `forward_combination_self_leg_donors` row to
`active` and call `republishActiveForwardsForCombination($combinationId)`.

### Revoke gate (transition `active -> cooldown`)

Any one triggers revoke:

- `measured_chain_latency_ms > desired_latency_ms` for
  `donation_revoke_window_seconds`.
- Two consecutive port-probe failures.
- The donor's `desktop_client_sessions` row expires or the donor device
  heartbeat is stale.
- Admin clears `allow_public_self_leg_donation` or clears the donor row's
  `owner_opt_in`.

On revoke: set `state = 'cooldown'`, set `revoked_at`/`revoke_reason`, remove
the donor from candidate emission, and call `republishActiveForwardsForCombination`.

### Hysteresis

- Donor must hold the threshold for the **full** qualify window before
  becoming active.
- Donor must hold the violation for the **full** revoke window before
  cooldown.
- Cooldown is `300 s`; during cooldown the donor stays out of qualifying.

---

## 9. Open Inbound Port Detection

Two cooperating checks; both must pass for donation to qualify.

### 9.1 Slave-side self-test

Slave-agent already binds listeners for each published forward. On every
heartbeat, for each forward bound to a donation-eligible combination, it:

1. Confirms the listener is bound (existing logic).
2. Tries an **outside-in** TCP connect to its own `public_ip:listen_port`,
   from the slave's existing wstunnel/CF probe path. This proves NAT
   traversal from the public Internet, not just localhost.
3. For UDP, attempts a UDP echo through the same path.
4. Emits results in `donation_self_check[*].open_ports`.

### 9.2 Sidecar-side reverse probe

The sidecar-agent already maintains a probe path back toward each slave
for `bond_leg_reputation`. We add a lightweight **reverse-port check**:

1. Sidecar opens a TCP (and UDP, if the forward supports it) probe toward
   `slave_public_ip : forward.listen_port`.
2. Result is reported via the existing sidecar heartbeat under a new
   `donation_reverse_probe` array.
3. Panel worker requires the **most recent sidecar reverse probe** to be
   `ok = true` before transitioning a donor to `active`.

Either probe failing twice in a row revokes donation (rule 5 in §3).

---

## 10. Multi-Slave Selection and Fastest-4 Chain (Feature 2)

### 10.1 Sources of slave candidates per combination

The candidate set for a combination is the union of:

1. Rented node members of every **helper group** referenced by
   `forward_combination_helper_groups`.
2. Explicitly pinned rented nodes from `forward_combination_slaves` with
   `selection_kind = 'admin_pin'`.
3. Active client self-leg donors from
   `forward_combination_self_leg_donors` where `state = 'active'` and
   `owner_opt_in = 1`.

### 10.2 Ranking signal

Use the **slave-to-sidecar-to-endpoint** RTT, already collected today:

- Slave-agent's existing `forward_chain_remediation` payload reports
  `best_latency_ms` per chain.
- Sidecar-agent's existing `bond_leg_reputation_current` carries
  `effective_weight` and `delivered_ewma`.
- Panel chooses RTT as primary key, with `delivered_ewma` as tiebreaker.

### 10.3 Selection algorithm (per combination)

```
candidates = rented_nodes(combination_id) UNION active_self_leg_donors(combination_id)
candidates = filter(c -> c.last_chain_latency_ms is not null)
candidates = sort_by(c -> (c.last_chain_latency_ms ascending,
                           -c.delivered_ewma,
                           c.stable_id))
selected   = take(candidates, helper_group_cap or 4)
```

Tie-breakers (when RTT is missing or equal):

- Higher `delivered_ewma` wins.
- Lower stable id wins (`node_id` for rented nodes, donor table `id` for client self-leg donors).

### 10.4 Per-forward propagation

All forwards under one combination receive **the same** `selected` list.

The existing `reconcileForwardEndpoints(forwardId)` still builds
`service_endpoints` rows for rented `nodes` only. We add
`pickFastestSlavesForCombination(int $combinationId, int $cap)` for the unified
ranking. When the selected item is a rented node, reconciliation materializes it
as a `service_endpoints` row. When the selected item is a client self-leg donor,
provisioning emits it directly into `candidate_pool` as
`candidate_kind = 'donated_slave_leg'`; it is not represented as a fake node.
The legacy code path stays for forwards without a combination.

### 10.5 Recompute trigger

Recompute the selection when any of the following happen:

- `helper_group_cap` is changed for the combination.
- `forward_combination_slaves` rows for the combination change.
- A row in `forward_combination_self_leg_donors` enters or leaves `state = 'active'`.
- `desired_latency_ms` changes.
- A node's `last_chain_latency_ms` changes by more than
  `switch_improvement_ms` (existing knob, default 15).

Each trigger calls `republishActiveForwardsForCombination`, which we already
have from the 2026-05-17 plan.

---

## 11. Provisioning Boundary Changes

`ProvisioningService.php`:

1. **Loader**: extend `forwardCombinationById` to hydrate the new fields.
2. **Saver**: extend `saveForwardCombination` to persist the new fields and
   call `republishActiveForwardsForCombination` (already present).
3. **Slave selection**: for combination-bound forwards, call
   `pickFastestSlavesForCombination($combinationId, $maxLegsUsed)` and keep the
   legacy `pickNodesForGroups` path for non-combination forwards. Rented results
   become `service_endpoints`; client self-leg donor results become direct
   `candidate_pool` entries.
4. **Donation worker hook**: a new public method
   `evaluateDonationStateForCombination(int $combinationId): void` runs from
   a CLI (`master/scripts/evaluate_donations.php`) and is wired into a
   systemd timer (mirror the SSL task worker).
5. **Agent payloads**: publish explicit `donation_policy` data to donor-side
   slave-agent/self-leg runtimes and explicit `donation_reverse_probe_targets`
   to sidecar management payloads.
6. **Candidate pool emission**: when emitting `candidate_pool` for the
   client-agent, mark donor candidates with `candidate_kind =
   'donated_slave_leg'` so the client-agent can debug and count them against
   `helper_group_cap`.

### Worker entry point

Add `master/scripts/evaluate_donations.php` and the systemd unit:
`deploy/systemd/leikwan-donation-worker.service` + `.timer` (60s tick).

---

## 12. Slave-Agent Changes

### 12.1 New donation policy payload

Task publish / management payloads for donor-capable forwards include a
`donation_policy` object:

```json
{
  "forward_combination_id": 12,
  "desired_latency_ms": 60,
  "donation_gate_ratio": 0.75,
  "donation_qualify_window_seconds": 60,
  "donation_revoke_window_seconds": 30,
  "donation_min_open_ports": 1,
  "donor_client_id": 44,
  "desktop_client_session_id": 123,
  "device_id": "stable-device-id"
}
```

The slave-agent must treat absence of `donation_policy` as donation disabled for
that forward.

### 12.2 New self-check loop

A new background coroutine in `slave-agent/slave_agent.py`:

- Every `30 s`, for each active forward whose payload has
  `donation_policy.desired_latency_ms > 0`:
  - Probe `slave -> sidecar -> endpoint` RTT (existing CF probe path already
    exists; extend for `current_mode` if needed).
  - Probe each listener for outside-in reachability (TCP, and UDP when
    `transport_mode in {udp, tcp+udp}`).
  - Cache the most recent result.
- On heartbeat, attach `donation_self_check[]`.

### 12.3 No new decision authority

Donation is **a panel-side decision**. Slave-agent only reports facts for the
specific policy payload the panel sent; it does not declare itself donor.

---

## 13. Sidecar-Agent Changes

### 13.1 New reverse-probe target payload

The panel adds `donation_reverse_probe_targets[]` to sidecar management payloads.
Each entry is explicit; sidecar-agent does not infer targets from route files:

```json
{
  "forward_combination_id": 12,
  "donor_id": 55,
  "client_id": 44,
  "forward_id": 47,
  "protocol": "tcp",
  "public_host": "example-donor-edge",
  "public_port": 24443,
  "expected_transport": "cf_wss"
}
```

### 13.2 New reverse-port probe loop

Add a sidecar function/loop that consumes `donation_reverse_probe_targets[]` and
reports `donation_reverse_probe[]` on heartbeat, one entry per target:

- `forward_combination_id`
- `donor_id`
- `client_id`
- `forward_id`
- `protocol` (`tcp` / `udp`)
- `ok`
- `latency_ms`
- `checked_at`

Probe interval is `60 s`, non-overlapping per target, and failures are rate
limited in logs.

## 14. Client-Agent Changes (Linux phase one)

The consumer's client-agent: when receiving a `candidate_pool` entry with
`candidate_kind = 'donated_slave_leg'`, treat it identically to a rented helper
leg for selection, MPTCP capability filtering, and latency target escalation.
Add only a small `donor=true|false` log decoration for operator clarity. The
runtime family selection is unchanged.

The donor-side client self-leg identity is maintained by the panel donor table
and donor-side policy payload; do not add a second client-agent donation decision
path in phase one.

## 15. Observability

Admin panel additions:

1. **Donation list view** under
   `/admin/forward-combinations/{id}/donations`:
   - donor client/session/device plus redacted endpoint summary
   - state, last RTT, donation threshold, qualify_started_at, activated_at,
     revoked_at, revoke_reason
   - open ports JSON
2. **Per-forward chain view**: extend the existing forward-detail page with
   the `selected` list (fastest-4) and per-slave latency. Show whether the
   slot came from `admin_pin`, `helper_group`, or `donated_slave_leg`.
3. **Logs**: panel writes one line to `error_log` on each
   donation state transition (qualify, activate, revoke, cooldown). Format:
   `donation_state donor=<donor_id> client=<client_id> combo=<id> from=<state> to=<state> reason=<reason>`.

## 16. Failure Modes

| Scenario | Behavior |
|---|---|
| Donor's RTT is `null` (never measured) | Stay in `qualifying`; never enter `active`. |
| Sidecar reverse probe fails once | Stay in current state; don't revoke yet. |
| Sidecar reverse probe fails twice | Revoke active donor, set `revoke_reason = 'port_probe_failed'`. |
| `desired_latency_ms` set to `0` | Donation worker treats combination as "donation disabled"; revoke any active donors with `revoke_reason = 'admin_disabled'`. |
| `helper_group_cap = 0` | Treat as unlimited; do not slice the chain. |
| Selected donor client's heartbeat/session ages out (>120 s) | Revoke with `revoke_reason = 'session_ended'`. |
| Multiple self-leg donors qualify; cap is 4 | Pick the four fastest by RTT across rented nodes + donors; remaining donors stay `active` in DB but are not emitted in the chain (informational); recompute when chain changes. |
| Admin manually revokes donor via panel | Set `state = 'revoked'` (terminal until manual re-enable), set `revoke_reason = 'admin_disabled'`. |
| Two donors have identical RTT | Tie-break on `delivered_ewma` desc, then donor table `id` asc. |

## 17. Rollout Plan

Phase 1 (this design): Linux client-agent + panel + slave-agent + sidecar
heartbeat fields.

Phase 2 (later): Windows / Android donors.

Phase 3 (later): Tenant-visible "this route includes donor capacity"
disclosure (off by default).

## 18. Security Considerations

- Donor traffic is mediated by the sidecar; the donor should not receive the
  consumer's plaintext target from the panel. (Sidecar already terminates the
  consumer side and re-dials the target.)
- IP hiding is **best-effort in phase one**. Prefer CF/WSS or CF-WG donor
  endpoints and redact donor endpoint values in panel UI, logs, exported configs,
  and docs. Do not claim that `wg show`, local runtime JSON, or packet captures
  can hide an endpoint IP:PORT by design.
- If a direct donor endpoint is emitted to a consumer client, that endpoint may
  be visible on that consumer machine. That is acceptable only when the donor row
  explicitly opted in and the admin understands the exposure; otherwise require a
  CF-masked endpoint.
- Donation requires combination-level admin opt-in **and** donor-row opt-in
  (`owner_opt_in = 1`). The new combination-level switch is additive to the
  donor-side row, not a replacement.
- Revocation is fast-path: `next heartbeat + 60 s worker tick` worst case.

## 19. Decision Boundary Summary

> **The most important non-obvious decision:**
>
> Donation eligibility is computed by a **panel worker**, not announced by
> the slave. The slave only reports raw measurements (`measured_chain_latency_ms`,
> `open_ports`). This keeps the trust boundary at the panel and lets us tune
> qualify/revoke windows without redeploying clients.

> **The second most important decision:**
>
> Fastest-4 selection is **per combination, not per forward**. All forwards
> under a combination share the same slave list. This ties together with
> the existing rule that one combination is one tenant-visible route.

