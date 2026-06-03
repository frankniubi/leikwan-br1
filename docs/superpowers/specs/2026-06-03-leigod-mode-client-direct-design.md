# LeiGod Mode — Client-Direct Redesign (no slave, CF floor, client-metered billing, brand-hidden)

**Date:** 2026-06-03
**Status:** Design approved (brainstorming); pending spec review → phased implementation plans.
**Supersedes:** `docs/superpowers/specs/2026-06-03-leigod-mode-demoted-cf-anchor-design.md` (the demoted-CF-anchor is now Phase 1's floor leg, but the topology moved from slave-relayed to client-direct).
**Related:** `docs/2026-06-03-leigod-mode-toplevel-feature.md` (§6 CPU ceiling, §10 fixes), `[[leigod-mode-automation-gap]]`, `[[leigod-uot-cpu-bound]]`, `[[leigod-account-facts]]`.

---

## 1. Goal & motivation

The slave-relayed `leigod_mode` (client→slave→sidecar, slave runs LeiGod) is bottlenecked by the **2-vCPU slaves' WG-crypto + UoT CPU ceiling**. Under load the leg(s) stall, cool, and the path collapses (the 2026-06-03 combo-5 retry: sender 1.7 Gbit/s, **receiver 0 bytes**, flap storm that pegged the client host). The anchor floor mitigated but did not remove the bottleneck.

**Redesign:** make `leigod_mode` **client-direct** — the *client* (LeiKwan Bridge) runs LeiGod itself and reaches the customer endpoint through a LeiKwan **sidecar**, with **no slave in the path at all**. This removes the slave CPU bottleneck (the root cause) and makes failover a clean client-side leg choice.

**Decisions (from brainstorming):**
- **Topology:** `client → LeiGod-accelerated leg → sidecar → endpoint`. Slave removed; client runs LeiGod.
- **Failover:** the bond races a **LeiGod leg (primary)** against a **demoted CF leg (floor)**, both client-originated, both terminating at the sidecar. Leg-level (the forward stays `leigod_mode`; `cf_mode` the mode is never entered — see §4).
- **Egress:** always via the **sidecar** → endpoint sees LeiKwan's IP; **LeiGod is never seen by the endpoint**.
- **Billing:** the **sidecar is the authoritative meter** (tamper-resistant); the client counts only for real-time display.
- **Branding:** LeiGod is **hidden in end-user/tenant UI**; internal admins still see/manage it.

**Success criteria (validated by re-cutting-over combo 5):**
1. Bulk rides the client LeiGod leg; the CF leg stays dormant.
2. When LeiGod is unavailable, throughput **floors on CF instead of collapsing**.
3. No slave in the path; no slave-CPU flap storm; sidecar-metered usage matches client display within tolerance.

---

## 2. Topology & data path

```
            ┌── LeiGod leg (UoT/WG over LeiGod line, priority 10) ──┐
 client ───►│                                                       ├─► sidecar ─► endpoint
 (Bridge)   └── CF leg     (wstunnel over Cloudflare, priority 20) ─┘   (LeiKwan egress)
            bond races/fails-over between legs        no slave anywhere
```

- The client runs the **same LeiGod stack the slave runs today** (LeiGod line unit + shared session + gost/lguot UoT carrier + WG to the sidecar), producing a local leg endpoint the bond consumes. This is a *move* of `slave_agent.py`'s leigod self-leg provisioning onto the client.
- The CF leg is the existing private self-leg (`buildSelfLegCandidate`) published **demoted** (priority 20, `role=helper`).
- The bond's existing priority-tier selector (`session.rs:4366-4397`, `4505-4519`) uses the LeiGod leg while it is uncooled and falls to the CF leg only when LeiGod is cooled/down — automatic floor, **no Rust change**.
- The sidecar terminates both legs (it already terminates a leigod WG/UoT leg and a CF backend today) and forwards to the endpoint.

---

## 3. Why this beats the slave-relayed design
- **Removes the CPU-bound slave hop** entirely — the documented throughput ceiling and flap storm source.
- **Fewer relays** in the path (client → sidecar, vs client → slave → sidecar).
- **Failover is local to the client** (leg choice), not a cross-role convergence — no slave/sidecar restart storms.
- LeiGod multi-machine session sharing already supports running LeiGod on the client (`[[leigod-account-facts]]`: one session.json, multiple concurrent machines).

Residual ceiling: the **sidecar** (also ~2 vCPU) still terminates the UoT/WG — it can become the next bottleneck at high aggregate. Out of scope here (infra: bigger sidecar); not a regression vs today.

---

## 4. Failover is leg-level, NOT mode-level (preserves sibling-mode independence)
`backend_mode` stays `leigod_mode` throughout; the bond fails over between **legs** (LeiGod ↔ CF), never between **modes**. The CF leg lives on the self-leg/leg axis, independent of the ingress-mode axis, so `leigod_mode` and `cf_mode` remain independent siblings — no parent/child nesting. (Naming: the CF leg is the "stability floor leg," not `cf_mode`.) A mode-level failover to `cf_mode` would also be ineffective here because `cf_mode` still routes through slaves.

---

## 5. Phased implementation

### Phase 1 — Client-direct data path (the core win)
**Panel (`ProvisioningService.php`):** redefine the `leigod_mode` derivation (~:470) for the client-direct shape — publish (a) a **client-side LeiGod self-leg** (line id, shared session ref, gost/lguot carrier, WG-to-sidecar params, `leigod_uot_impl='lguot'`) targeting the sidecar, and (b) the **demoted CF floor leg** (`allow_private_self_leg=1`, `dynamic_priority=20`, `leg_role='helper'`; opt-out via `leigod_disable_anchor`). No slave endpoints are published for the bond. Ingress stays direct.
**Client agent (`client-agent/`):** port the slave's leigod provisioning (`ensure_leigod_line_service`, `apply_leigod_session`, the gost/lguot UoT client + WG-to-sidecar arm from `slave_agent.py`) into the client agent, driven by the published self-leg; `install_forward_runtime.py self_leg_endpoint` reads `leg_role`/`dynamic_priority` from the candidate (default `primary`/10 for back-compat). The bond legs become: LeiGod leg (10) + CF leg (20).
**Sidecar agent:** confirm it terminates a *client*-originated leigod leg (same mechanism as the slave-originated one today); adjust peer/identity scoping if it assumed slave origin.
**No Rust change.** Mode-aware 20s leg cooldown (already shipped) applies.

### Phase 2 — Billing (sidecar-authoritative + client display)
- **Sidecar meters** per-forward bytes and reports to `traffic_usage_hourly (node_id=sidecar, endpoint_id, bytes_in, bytes_out, period_start)` — the table `billing-deduct.php` already consumes.
- **Pricing:** add a `traffic_prices` entry for the sidecar/leigod egress; `billing-deduct.php` currently calls `resolveTrafficPrice(nodeId,'slave')` — generalize the resource type so sidecar-metered leigod traffic is priced (no slave exists to bill).
- **Client display:** the client counts its own forwarded bytes and reports to a client-facing usage endpoint for real-time UX only — **never** the billing source of truth.
- **Integrity:** sidecar count is authoritative; optionally log client-vs-sidecar drift for abuse detection.

### Phase 3 — Branding (hide LeiGod in end-user UI)
- Tenant/end-user surfaces (Bridge app, customer labels, connection info) show only "LeiKwan Bridge / accelerated line"; never "LeiGod".
- Internal **admin** panel keeps LeiGod line IDs/accounts/session management visible for ops.
- Audit user-facing i18n strings + API responses for leaked "leigod" tokens.

---

## 6. Testing (TDD, per phase)
- **P1 panel:** leigod_mode publishes a client LeiGod self-leg + demoted CF leg (priority 20/helper), no slave bond endpoints; opt-out → pure leigod; cf_mode/current_mode unchanged.
- **P1 agent:** client renders the LeiGod UoT/WG leg + the CF leg with correct priorities; self_leg_endpoint honors candidate role/priority (back-compat default).
- **P1 selector guard (Rust, no change):** LeiGod@10 used while uncooled; CF@20 only when LeiGod cooled.
- **P2:** sidecar emits `traffic_usage_hourly` rows per forward; `billing-deduct` prices sidecar/leigod traffic and deducts; client display count present but non-authoritative.
- **P3:** end-user surfaces contain no "leigod" token; admin surfaces still do.

---

## 7. Rollout / validation
1. Ship Phase 1 (panel + **client-agent version bump** + the three-artifact deploy — `client-agent/`, `artifacts/`, `master/public/artifacts/`, per `[[panel-artifact-serving]]`; **sidecar** change if any). Backward-compatible agent defaults so a partially-updated fleet stays correct.
2. **Re-cutover combo 5** to the client-direct shape; validate §1 success criteria with one spaced measurement + prepared revert; watch the client host load (no flap storm) and sidecar load.
3. Phase 2, then Phase 3, each its own plan + validation.

---

## 8. Risks & open questions
- **Largest change is the client agent** (it gains the LeiGod stack) → fleet update + artifact-shadow discipline; back-compat defaults mitigate partial rollout.
- **Sidecar as the new ceiling** (2 vCPU UoT/WG termination). Acceptable vs today; infra lever later.
- **Billing trust:** sidecar-authoritative is robust; the client display is advisory. Pricing resource-type generalization must not regress existing slave/cf billing.
- **Does the client reliably reach the sidecar over LeiGod from each client's network?** (e.g., sh36→nrt1). Validate on combo 5 before fleet-wide.
- **Existing slave-relayed leigod combos** migrate to client-direct on save/republish; define the migration explicitly in the Phase 1 plan (combo 5 is the only live one).
- **Open:** exact default leg-role when `role=` absent (lock via the selector guard test); whether the sidecar needs per-client identity changes to terminate a client (vs slave) leg.

## 9. Out of scope
Bigger sidecar/infra; cnix/private-WG floor basis; forward-route racing (B3/B4); any Rust/bond binary change.
