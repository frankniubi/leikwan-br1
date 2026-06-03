# LeiGod Mode — Demoted CF Anchor (stability floor) — Design Spec

**Date:** 2026-06-03
**Status:** Design approved (brainstorming); pending spec review → implementation plan.
**Related:** `docs/2026-06-03-leigod-mode-toplevel-feature.md` §10.3 (the durable fix this spec realizes), `[[leigod-mode-automation-gap]]`, `[[leigod-uot-cpu-bound]]`.

---

## 1. Goal & motivation

`leigod_mode` (direct ingress + LeiGod slave self-legs, no Cloudflare) is automation-usable and recoverable but **not throughput-stable**: with no Cloudflare stability floor, when the 2-vCPU slaves saturate (WG-crypto + UoT CPU ceiling) the bond legs all stall and cool, and the data path **collapses to ~0 throughput** with a flap storm. The live combo-5 retry (2026-06-03) proved this: iperf3 showed **sender 1.70 Gbit/s, receiver 0 bytes, "control socket closed"**, a cooldown storm (~1249/min) pegged the client host (load ~48), and it had to be reverted.

**Goal:** add a **demoted Cloudflare anchor leg** to `leigod_mode` so the LeiGod slave-legs carry the bulk (primary tier) and a CF leg provides a **stability floor that only carries traffic when all LeiGod legs are cooled**. This converts "collapse to 0" into "fall back to the CF floor's steady throughput," without putting CF on the hot path.

**Success criteria (validated on combo 5):**
1. When slaves have capacity → traffic rides LeiGod legs only; the CF anchor stays dormant (no CF latency on the hot path).
2. When slaves saturate → throughput **floors on the CF anchor instead of collapsing to 0**.
3. No flap storm / no client-host overload.

**Non-goals:** raising the aggregate ceiling (that's bigger-vCPU slaves, infra); a cnix/private-WG anchor basis; forward-route racing (B3/B4). See §9.

---

## 2. Key enabler — the bond already does demoted-tier fallback

No Rust change is needed because `leikwan-bond` already selects by priority tier with automatic fallback:

- `select_leg_with_mode_filtered` computes `primary_priority = min(priority)` over the **currently-available (non-cooled)** legs and selects within that tier (`session.rs:4505-4519`).
- `select_leg_for_data_with_cooldown` falls through to all-active legs when the non-cooled set is empty (`session.rs:4366-4397`).

Consequence with LeiGod legs at `priority=10` and a CF anchor at `priority=20`:
- LeiGod legs available → `primary_priority=10` → only LeiGod used; anchor dormant.
- **All LeiGod legs cooled** → the still-healthy anchor (priority 20, CF, not CPU-bound) is the only available leg → it carries traffic = **the floor**.
- LeiGod legs recover → back to LeiGod.

The anchor is a **client→sidecar direct CF leg that bypasses the CPU-bound slaves**, so it stays healthy exactly when the slaves saturate.

**Gotcha the implementation must respect:** the UDP and stream selectors short-circuit on `role==Primary` *before* priority is consulted (`select_udp_leg` `session.rs:4456`; `select_stream_affinity_leg` `session.rs:4415-4420`). Therefore the anchor must be published as a **non-primary role (`helper`)** as well as a higher priority number — otherwise it would be picked first and become the very "co-primary CF drag" we are removing.

---

## 3. This is NOT the old "production trap"

The original trap (the reason `leigod_mode` was created) was a CF self-leg published as a **co-primary** (`role=primary`, priority 10) that dragged *every* transfer through Cloudflare. The anchor is the **same self-leg machinery published demoted** (`role=helper`, priority 20) → it carries traffic **only** when LeiGod is fully cooled. CF never touches the hot path.

---

## 4. Decisions (from brainstorming)

- **Anchor basis: CF (demoted).** Reliable from China even when direct China→Tokyo paths are GFW-impaired. Reintroduces a CF hop only as a fallback floor.
- **Enablement: on by default, with a per-combo opt-out.** `leigod_mode` includes the demoted CF anchor unless a new `leigod_disable_anchor` flag is set (advanced use, e.g. bigger-vCPU slaves). Default-off flag means existing/new leigod combos get the floor automatically.

---

## 5. Design

### 5.1 Topology
`leigod_mode` keeps **direct ingress** (client→slave, `cf_location_group_id=0`) and the **LeiGod slave→sidecar hop** (`self_leg_outer_transport='leigod'`). It **adds one leg**: a direct **client→sidecar CF self-leg** at `priority=20`, `role=helper` — the demoted floor.

### 5.2 Panel — `master/src/Support/ProvisioningService.php`
- **`saveForwardCombination` leigod branch (~:470).** Read `leigod_disable_anchor` (default `0`). Anchor on → `allow_private_self_leg = true` and force the self-leg onto a CF basis (`self_leg_cf_transport`); ingress stays direct (`cf_location_group_id = 0`). Opt-out → `allow_private_self_leg = false` (today's pure-leigod behavior). Persist `leigod_disable_anchor`.
- **`buildSelfLegCandidate` (~:3138).** When the combo is `leigod_mode` (anchor on), tag the published candidate as demoted: `dynamic_priority = 20` and a role hint `leg_role = 'helper'` (new fields the candidate carries to the agent). For all other modes, preserve current behaviour (the cf_mode hybrid self-leg stays `role=primary`, priority 10) — backward compatible.
- The existing leigod line/sidecar guards, SOCKS-cred mint/preserve, and `republishActiveForwardsForCombination` are unchanged. The §10.1 forwards-row ingress sync (already shipped) keeps `forwards.backend_mode='leigod_mode'`.

### 5.3 Client agent — `client-agent/install_forward_runtime.py`
- **`self_leg_endpoint` (~:343).** Today it hardcodes `leg_role="primary"` (:362) and defaults `dynamic_priority` to 10 (:364). Change: read `leg_role` from the candidate (**default `"primary"`** for backward compatibility) and keep reading `dynamic_priority` from the candidate. With the panel publishing `leg_role='helper'`+`dynamic_priority=20` for the leigod anchor, the rendered leg spec becomes `...?priority=20&...&role=helper` (`_leg_query_params` :106-121 already emits `role=` when it is `primary|helper`).
- No change to leg ordering is required (selection is by role/priority, not list position); the existing prepend of the self-leg (:753-754) is harmless for a helper leg.

### 5.4 Schema/migration — `master/migrations/`
- Add `leigod_disable_anchor TINYINT NOT NULL DEFAULT 0` to `forward_combinations` (idempotent migration; update `schema.sql` comment). Default 0 → every existing/new leigod combo gets the floor on its next save/republish; no backfill.

### 5.5 Prerequisites the implementation must handle
- **Sidecar must be CF-reachable** for the anchor (CF dial host/SNI + the self-leg CF route on the sidecar). `selfLegCfTarget` must resolve; if it can't (sidecar has no CF fronting), the anchor cannot publish.
- **Client device session required** (`buildSelfLegCandidate` needs `deviceSession`) — same constraint the old hybrid had.
- **Define the no-anchor fallback explicitly:** if anchor-on but the anchor cannot be published (no CF target or no device session), the panel **logs a warning** and ships pure-leigod (documented, not silent). This avoids pretending a floor exists when it does not.

---

## 6. Files to change
| File | Change |
|---|---|
| `master/src/Support/ProvisioningService.php` | `saveForwardCombination` leigod branch (anchor on/off + `leigod_disable_anchor`); `buildSelfLegCandidate` demoted tags for leigod_mode |
| `master/migrations/20260603_leigod_disable_anchor.sql` + `schema.sql` | new column |
| `client-agent/install_forward_runtime.py` | `self_leg_endpoint` reads `leg_role`/`dynamic_priority` from candidate (backward-compatible defaults) |
| `master/public/i18n/*.json`, `AdminController.php` | optional: surface `leigod_disable_anchor` checkbox |
| tests (see §7) | new + updated |

No `wstunnel-main/leikwan-bond` (Rust) change.

---

## 7. Testing (TDD)
1. **Panel derivation** (`saveforwardcombination_*` test): leigod_mode default → `allow_private_self_leg=1` + `leigod_disable_anchor=0`; opt-out payload → `allow_private_self_leg=0`; cf_mode/current_mode unchanged.
2. **Self-leg candidate demotion** (panel): for leigod_mode, `buildSelfLegCandidate` output carries `dynamic_priority=20` + `leg_role='helper'`; for cf_mode hybrid it stays `primary`/10.
3. **Agent leg-spec** (`install_forward_runtime` test): a candidate with `leg_role='helper'`+`dynamic_priority=20` renders `role=helper` and `priority=20`; a candidate without those fields still renders `role=primary`/`priority=10` (back-compat).
4. **Migration shape** test (column + default + idempotency).
5. **Selector guard test** (Rust, no code change): assert with one helper@20 + N primary@10 legs that, while a primary@10 is uncooled the helper is not selected, and when all primary@10 are cooled the helper@20 is selected. (Locks the behavior we depend on.)

---

## 8. Rollout / validation
1. Ship panel + migration + the **client-agent change (version bump + the three-artifact deploy: `client-agent/` source, `artifacts/`, `master/public/artifacts/` — see `[[panel-artifact-serving]]` to avoid the static-shadow churn)**.
2. Confirm the client agent updates fleet-wide on heartbeat.
3. **Re-cutover combo 5** (anchor on) and validate the §1 success criteria with the disciplined single spaced measurement + the prepared revert: (a) LeiGod carries the bulk; (b) under saturation throughput floors on CF instead of 0; (c) no flap storm / no client overload (watch sh36 load + cooldown rate).

---

## 9. Risks & open questions
- **Anchor floor capacity is one CF leg.** Under sustained slave saturation, throughput floors at the CF anchor's single-leg rate (stable but modest). This is by design — it prevents collapse; raising the ceiling is the separate infra lever (bigger slaves).
- **Client-agent change = fleet update.** Pulls the artifact-shadow risk; mitigated by the three-artifact deploy discipline. Backward-compatible defaults mean partially-updated fleet stays correct (old agents render the self-leg as primary/10 = today's behavior).
- **CF anchor reliability for this route.** fwd 65's *slave-transit* CF was degraded; the *client→sidecar* CF self-leg is a different path. If it proves unreliable as a floor, the cnix/private-WG basis (deferred) is the next lever.
- **Open:** exact default leg-role when `role=` is absent (verify in the §7.5 guard test that LeiGod legs remain the selected tier).

## 10. Out of scope
Bigger-vCPU slaves; cnix/private-WG anchor basis; forward-route racing (B3/B4); any Rust/bond binary change.
