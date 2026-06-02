# Forward Route — slave-orchestrated cross-basis racing — Design Spec

Date: 2026-06-02
Status: **Design approved direction; pending spec review → implementation plan**
Depends on: `2026-06-02-leigod-third-forwarding-basis-design.md` (provides leigod as a 3rd raceable basis)
Target repo: `leikwan-br1`, baseline branch `master`

---

## 1. Goal

Introduce a user-facing **Forward Route**: a named bundle of multiple **forward combinations** (the hidden
"bases": cnix-bond / cf-bond / leigod). In the add-forward UI (admin **and** client/tenant), the user
selects a **Route**, not a combination. The ingress slave **auto-races** across the route's *enabled*
combinations and runs traffic over the best-performing one, **auto-failing-over** when a basis is blocked or
de-accelerated (the motivating case: **CF-bond blocked → switch to cnix-bond or leigod**). Admin can:
- turn **racing off** for a route (falls back to the highest-priority enabled combination),
- **enable/disable** each combination within a route.

### Non-goals (v1)
- **Cross-slave routing.** A route's enabled combinations must **share the same ingress slave group**
  (they differ in the slave↔sidecar *basis*, not in which slave the client connects to). Racing across
  combinations pinned to *different* ingress slaves is deferred (would require a client/panel coordinator —
  see §10).
- No change to the **client→slave ingress** itself (the WSS path-mux). Racing swaps the slave↔sidecar basis,
  which the client never sees. **(v1 requires a route's combinations to present the *same* client→slave
  ingress — see the §3 assumption on where CF sits.)**
- No new probe protocol — reuse the existing slave selector probe + the client 3-strike health model.

---

## 2. Resolved decisions (brainstorm, 2026-06-02)

1. **Racing model = shared-ingress, slave-orchestrated (Option B).** A route's enabled combinations share the
   ingress slave group and differ in the slave↔sidecar basis. The slave races them via the **existing
   selector engine**, generalized from "candidate = WG lane" to "candidate = basis/combination."
2. **Race OFF → lowest `sort_order` among enabled combinations** is authoritative (behaves like today's
   single-combination forward).
3. **Migration = dual-column.** Add `forwards.forward_route_id`; keep `forward_combination_id` seeded from
   the active/primary combination for back-compat. Backfill each legacy forward into a singleton route.
4. **Plans gate Routes** (new `service_plan_forward_routes`), since combinations are now hidden from users.
5. **Payload field named `route_race_candidates`** — NOT `forward_routes` (that JSON key is already used,
   unrelated, at `SidecarAgentController.php:292`).

---

## 3. Architecture & data flow

A **Forward Route** is a bundle of combinations that all share the ingress slave group and the client→slave
ingress, differing only in the slave↔sidecar **basis** = `(sidecar_point, backend_mode, self_leg_outer_transport,
cf_location_group)`. Because cnix(`udp`) / cf(`wstunnel_udp`) / leigod(`leigod`) are exactly the three
`sidecar_uplink.outer_transport` values, each enabled combination resolves to **one candidate sidecar uplink**
from the same ingress slave.

```
                         ┌──────────── one ingress slave (shared) ───────────┐
 client → slave WSS mux →│  selector (evaluate_sidecar_uplink_selector)       │
 (ingress UNCHANGED)     │    ├─ candidate A: cnix uplink  → sidecar X → target│
                         │    ├─ candidate B: cf uplink    → sidecar Y → target│  ← all warm; probe each;
                         │    └─ candidate C: leigod uplink → sidecar Z → target│    active = best; failover
                         └────────────────────────────────────────────────────┘
```

- The slave brings up **all enabled combinations' uplinks** (warm standby), probes each end-to-end, and the
  **selector proxy** rewrites the active backend target to the winning basis — reusing the existing
  `switch_improvement_ms` hysteresis, `restart_cooldown`, monitor-only mode, and the `STATIC_SIDECAR_SELECTOR_ENABLED`
  kill-switch.
- **Failover trigger:** a basis whose probe fails/degrades (CF-bond blocked/de-accelerated) is demoted; the
  selector switches to the next-best healthy basis within sub-second, with hysteresis to prevent flapping.
- **Race OFF:** the panel publishes only the lowest-`sort_order` enabled combination (one candidate) →
  selector degrades to monitor-only/no-switch → behaves exactly like today's single-combination forward.

> **Key assumption (VERIFY in implementation).** This slave-orchestrated design works only if cnix-bond /
> cf-bond / leigod differ **on the slave↔sidecar hop** (the basis the leigod spec models), so the slave can
> swap the active basis while the client→slave ingress stays identical. The leigod spec frames cf as a
> slave↔sidecar transport (CF-wstunnel→Japan VM). If, in the live code, `backend_mode=cf_mode` *also* alters
> the **client→slave ingress** backend (per the wf#2 note that `backend_mode` governs ingress), then cnix vs
> cf cannot be raced slave-side — those bases would move to the deferred client-coordinated set (§10), and a
> v1 route would race only bases that share the ingress (e.g. cnix vs leigod, both `current_mode` ingress with
> different outer transports). **Confirm before building:** does cf-bond change the ingress, or only the
> slave↔sidecar uplink? `saveForwardRoute` enforces "same client→slave ingress backend" across a route's
> enabled combos regardless, so an ingress-changing basis is simply rejected from a slave-raced route.

---

## 4. Component design

### 4.1 Data model & migrations (`master/migrations/20260602_add_forward_routes.sql` + `schema.sql`)

| Table | Columns | Purpose |
|---|---|---|
| `forward_routes` (NEW) | `id, name, tenant_label, tenant_description, tenant_badge, race_enabled TINYINT DEFAULT 1, is_active TINYINT DEFAULT 1, sort_order INT, created_at, updated_at` | The user-facing bundle. `tenant_*` mirror `forward_combinations` so the Route reuses label/badge presentation. `race_enabled` = admin race ON/OFF. |
| `forward_route_combinations` (NEW, model on `service_plan_forward_combinations` schema.sql L268-277) | `id, forward_route_id FK, forward_combination_id FK, enabled TINYINT DEFAULT 1, sort_order INT, UNIQUE(forward_route_id, forward_combination_id)` | Per-combination enable flag (admin enable/disable each basis). `sort_order` = priority order used when race is OFF. |
| `forwards` (ALTER) | `forward_route_id BIGINT UNSIGNED NULL` (mirror `forward_combination_id` L343); `active_combination_id BIGINT UNSIGNED NULL` | Bind a forward to a Route; `active_combination_id` records the currently-winning basis for admin observability + stable port-seed. Keep `forward_combination_id` (seeded from active/primary combo) during transition. |
| `service_plan_forward_routes` (NEW, mirror `service_plan_forward_combinations`) | `id, plan_id FK, forward_route_id FK, UNIQUE(plan_id, forward_route_id)` | Plans gate Routes (combinations are hidden from users). |

### 4.2 Admin surface

- **New `/admin/forward-routes`** page (GET list/edit + POST save), wired in `Bootstrap.php` (mirror
  `/admin/forward-combinations` L133-134) → new `AdminController::forwardRoutesPage` + `saveForwardRoute`
  (mirror `forwardCombinationsPage` L1885 / `saveForwardCombination` handler L2134).
- **Route editor:** lists existing `forward_combinations` as rows with a per-row **enabled** checkbox
  (writes `forward_route_combinations.enabled`) + drag/number **sort_order**, plus a route-level **race ON/OFF**
  toggle (`forward_routes.race_enabled`). Reuse the checkbox-set machinery prior art:
  `renderForwardCombinationPlanGroups` (L4125, `name='...[]'` L4160) + savePlan ingest pattern (L3090).
- **Shared-ingress constraint validation:** `saveForwardRoute` rejects a route whose enabled combinations do
  not share the same **client→slave ingress realization** — `node_group_key` + pinned
  `forward_combination_slaves` + `client_mode` + `bond_ingress_mode` + the ingress backend (whatever
  `backend_mode` contributes to the client-facing relay). Only the **slave↔sidecar basis**
  (`sidecar_point_id` / `self_leg_outer_transport` / `cf_location_group_id` / `leigod_line_id`, and the
  downstream portion of `backend_mode`) may differ. Error `forward_route_combos_ingress_mismatch`. (See the
  §3 assumption: confirm whether `cf_mode` touches the ingress; if it does, cnix vs cf is rejected from a
  slave-raced route and handled by the deferred client coordinator §10.)
- Combinations remain authored unchanged on `forwardCombinationsPage` (the hidden bases).
- **Republish on change:** editing a route (toggling a combo, race on/off, sort_order) calls a NEW
  `republishActiveForwardsForRoute` generalizing `republishActiveForwardsForCombination` (L4979) to reconcile
  every forward whose `forward_route_id` matches.
- Plan editor gains a Route checkbox group (writes `service_plan_forward_routes`).

### 4.3 Client / tenant surface

- Replace the single-combination dropdown in `TenantController::quickCreateForward` (L1717-1724, L1744) with a
  single **Route** dropdown `<select name="forward_route_id">`; options from a NEW
  `availableForwardRoutesForService` (repurpose `availableForwardCombinationsForService` L606 — already filters
  by plan-gated set + `is_active`). User sees only the Route's `tenant_label/description/badge`.
- `normalizeTenantForwardPayload` (L2450) parses `forward_route_id` (accept legacy `forward_combination_id`
  during transition). `editForward` chip (L1794) shows the Route label.
- `buildClientConfig` (L3301) additionally carries `forward_route_id`/name (+ keeps `forward_combination_*`
  for back-compat, seeded from the active combination). The desktop/mobile client does **not** coordinate the
  race in v1 (slave-orchestrated) — it only needs the active combination's config, which the existing fields carry.

### 4.4 Publish path (panel)

- `reconcileForwardEndpoints` (L5537): generalize the single-combination branch (L5556-5566) to **loop the
  route's enabled combinations** (all sharing the ingress slave), producing one candidate per enabled combo.
- `publishTaskPayloadForForward` (L6019, today emits a single `forward_combination_id` L6058 + one uplink):
  emit a **`route_race_candidates`** list — one entry per enabled combination, each carrying its own
  `forward_combination_id`, `sidecar_uplink` pair (from `buildStaticSidecarUplinkPairForNode`, incl. the
  leigod keys when that combo is leigod), `enabled`, `sort_order` — plus a `route_race` policy block
  (`race_enabled`, hysteresis/cooldown knobs, kill-switch). When `race_enabled=0`, emit only the lowest-`sort_order`
  enabled candidate.
- Generalize `staticSidecarUplinkSelectorPayload` (L5942) so its "candidates" are the route's per-combination
  uplinks (it already supports ≥2 candidates, per-candidate `enabled`, kill-switch).
- **Selector port seed must become route/active-stable:** `collisionFreeSidecarSelectorPort` seeds on
  `combinationId` (L7689/L7709) — reseed on `forward_route_id` (or `active_combination_id`) so a winning-basis
  flip does NOT churn the loopback selector port (avoids the prior selector-port storm class of bug).

### 4.5 Slave-agent (selector generalization)

- `evaluate_sidecar_uplink_selector` (L2608) + `SidecarUplinkSelectorProxy` (L936) +
  `probe_sidecar_selector_candidate` (L2523): generalize a "candidate" from a WG lane to a **basis** (a full
  per-combination sidecar uplink). The slave stands up the uplinks for all enabled candidates (warm), probes
  each end-to-end (score = `latency + 2*mdev`, reusing the existing kernel), and the proxy rewrites
  `active_target_host/port` to the winning basis. Reuse `switch_improvement_ms`, `restart_cooldown`,
  monitor-only, kill-switch.
- **Blocked/de-accelerated detection:** a candidate whose probe times out / loses reachability or whose
  latency degrades past threshold is demoted (this is the CF-blocked failover). Bound candidates per route
  (cap, e.g. ≤3) to limit simultaneous uplinks/units (heeds the EADDRINUSE selector-storm + provision-task
  deadlock precedents).
- **Reverse feedback (observability):** the slave reports the active candidate's `forward_combination_id` in
  its heartbeat → panel persists `forwards.active_combination_id` so admin sees which basis is live. (Not
  required for the data plane to work; the switch is local.)
- **Dead code caution:** `classify_forward_chain_remediation` (L4553) is **not** wired (zero callers,
  `candidate_latencies_ms` never populated) — the selector path above is the real engine; do not assume that
  function is reusable as-is.

---

## 5. Migration of existing forwards

Keep both columns during transition. Backfill: wrap each legacy `forward_combination` in a **singleton
`forward_route`** (`race_enabled=0` + one `forward_route_combinations` row `enabled=1`), then set the forward's
`forward_route_id` to it. Existing forwards keep their `forward_combination_id` and realize unchanged
(`publishTaskPayloadForForward`/`buildClientConfig` still read it; seed it + `active_combination_id` from the
route's primary/winning combo). Plan gating migrates to `service_plan_forward_routes` (each legacy
plan→combination becomes plan→singleton-route).

---

## 6. Tests to add

- **schema/migration**: `forward_routes`, `forward_route_combinations`, `forwards.forward_route_id` +
  `active_combination_id`, `service_plan_forward_routes` present; FKs + UNIQUE constraints.
- **panel**: `saveForwardRoute` rejects ingress-mismatched combos (`forward_route_combos_ingress_mismatch`);
  `publishTaskPayloadForForward` emits `route_race_candidates` (one per enabled combo, incl. leigod keys) +
  `route_race` policy; race-OFF emits only lowest-`sort_order` enabled; `availableForwardRoutesForService`
  returns plan-gated active routes; `republishActiveForwardsForRoute` reconciles all matching forwards;
  selector port seed is route/active-stable (no churn on winner flip).
- **slave-agent**: selector treats per-combination uplinks as candidates; warm standby brought up for each
  enabled candidate; probe demotes a blocked candidate and switches with hysteresis; single-candidate (race
  OFF) → monitor-only/no-switch; candidate cap enforced; heartbeat reports active `forward_combination_id`.
- **tenant UI**: add-forward shows a Route dropdown (not a combination); `normalizeTenantForwardPayload` parses
  `forward_route_id`; editForward chip shows the Route label.
- **regression**: existing single-combination forwards (post-backfill singleton routes) behave identically;
  `route_race_candidates` payload key does NOT collide with `SidecarAgentController.php:292` `forward_routes`.

---

## 7. Risks & mitigations

| Risk | Mitigation |
|---|---|
| **Cross-slave bundling silently un-raceable** | v1 constrains a route's enabled combos to share the ingress realization; `saveForwardRoute` validates and errors otherwise. Cross-slave deferred to §10. |
| **Winner flapping cnix↔cf↔leigod** churns selector loopback port + client start scripts | Route/active-stable selector port seed; reuse `switch_improvement_ms` hysteresis + `restart_cooldown`; sticky failover. |
| **N warm uplinks per forward** multiplies WG interfaces/selector units (selector-storm + deadlock precedent) | Cap candidates per route (≤3); reuse the deterministic collision-free port allocator; load-test the per-node unit count. |
| **Naming collision** `forward_routes` payload key (L292) | New payload field is `route_race_candidates`; DB concept is the only `forward_routes` *table*. |
| **Double-gating** (plan gates raw combos AND routes) | Plans gate **routes** (`service_plan_forward_routes`); route-internal combos are implicitly allowed; drop plan→combination gating for route-based plans. |
| **`classify_forward_chain_remediation` mistaken for reuse** | It's dead code; the selector engine is the real path — build/wire, don't assume. |
| **No reverse channel today** | Add `forwards.active_combination_id` via heartbeat (observability only; switch is local so absence doesn't break the data plane). |

---

## 8. Phased implementation scope (for the plan)

1. **Data model** — migration (`forward_routes`, `forward_route_combinations`, `forwards.forward_route_id` +
   `active_combination_id`, `service_plan_forward_routes`) + schema.sql + panel tests.
2. **Admin Route CRUD** — `/admin/forward-routes` page, route editor (enable/sort/race toggle),
   ingress-mismatch validation, `republishActiveForwardsForRoute`, plan→route gating.
3. **Publish path** — `reconcileForwardEndpoints` loops enabled combos; `publishTaskPayloadForForward` emits
   `route_race_candidates` + policy; generalize `staticSidecarUplinkSelectorPayload`; route-stable port seed.
4. **Slave selector** — generalize candidate lane→basis; warm standby; blocked-basis demotion; candidate cap;
   active-combo heartbeat; tests.
5. **Tenant/client surface** — Route dropdown replaces combination dropdown; `availableForwardRoutesForService`;
   payload/back-compat; `buildClientConfig` route fields.
6. **Migration/backfill** — singleton routes for legacy forwards + plan gating migration.
7. **Live validation** — a route bundling {cnix-bond, cf-bond, leigod} on one ingress slave: confirm the
   selector picks the best, block CF and confirm sub-second failover to leigod/cnix, confirm no flap.

---

## 9. Relationship to leigod (sequencing)

Forward Route **races across bases**, and leigod is one basis. It depends on combinations being able to
represent cnix-bond / cf-bond / **leigod** as comparable candidates — which the leigod spec provides
(leigod as a combination via `self_leg_outer_transport`). **Sequence: implement leigod basis first** (so a
route has 3 real bases to race), **then Forward Route**. The combined implementation plan orders them this way.

---

## 10. Deferred: cross-slave routing (future phase)

If a route must later bundle combinations pinned to *different* ingress slaves, the slave can no longer
coordinate (it can't measure another slave's chain). Two additive options for a later phase:
- **Client-orchestrated (A):** the client races heterogeneous candidates end-to-end (reuse
  `select_forward_candidates` L2781 + `bond-endpoint-health.json` 3-strike kernel L2061), tagging each by
  `forward_combination_id`. Per-device winners; only protects client-driven forwards.
- **Panel arbitration (C):** extend `BondLegReputationService.ingestSnapshots` (L106) with a combination
  dimension; a panel loop picks a fleet-wide winner per route and republishes. Consistent but heartbeat-slow.
Both require a per-combination reverse health channel (the `active_combination_id` + reputation-dimension work
started here is the foundation).
