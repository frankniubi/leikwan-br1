# LeiGod as a First-Class Forward Mode (`leigod_mode`) — Feature Record

**Date:** 2026-06-03
**Branch merged to:** `master` (commits `bc5b48f`, `57394ea`, `5ad777c`, `d0d3834`, on top of merge `a9f3405`)
**Status:** Panel code **complete, tested, deployed to prod (inert)**. Live cutover of production combo 5 was attempted, **failed (flapped), and rolled back**. The panel feature is sound; the *live leigod-direct data path* is not yet stable enough to drop CF. See [Test Results](#test-results) and [What To Do Next](#what-to-do-next).

> **UPDATE 2026-06-03 (post-investigation):** the "panel feature is sound" claim was **incomplete**. A follow-up investigation found a real **panel automation gap** (a combo Forward-Mode flip did not converge the client/slave *ingress* axis — `forwards` row left stale) and applied two surgical, panel-only fixes (the ingress re-sync + a mode-aware leg cooldown). See **§10**. The "operator error / just restart all 3 roles" framing in §6.4 was partly a **mis-attribution** of that automation gap.

---

## 1. Goal & Motivation

Previously, "LeiGod" was only reachable as a **feature-flagged self-leg "basis" picker** (`self_leg_outer_transport ∈ {cnix, cf, leigod}`), hidden behind the `LEIGOD_BASIS_ENABLED` config flag. To get a LeiGod forward an admin had to pick `cf_mode` (or `current_mode`) on the "Backend Mode" selector **and separately** set the leigod basis. This was:

- **Confusing** — leigod looked like a sub-branch of CF mode.
- **A production trap** — production combo 5 was `cf_mode + self_leg=leigod`, which kept a **Cloudflare ingress + a CF "self-leg" primary bond leg** (`wss://162.159.39.x:2053`) alongside the leigod self-leg.

**Intended outcome:** make `leigod_mode` a **dedicated, top-level Forward Mode**, peer to `current_mode` and `cf_mode` — behaving like `current_mode` on the ingress (direct, no Cloudflare) and differing only on the slave↔sidecar hop (LeiGod line via the `lguot` UoT carrier).

---

## 2. Architecture

A forward combination's transport is modeled on **two independent axes**:

| Axis | Column | Values | Hop it controls |
|---|---|---|---|
| **Ingress** ("Forward/Backend Mode") | `backend_mode` | `current_mode`, `cf_mode`, **`leigod_mode`** (new) | client → slave |
| **Self-leg (basis)** | `self_leg_outer_transport` | `cnix`, `cf`, `leigod` | slave ↔ sidecar (the WG hop) |

The change makes `backend_mode` a **3-valued user-facing "Forward Mode"** and **derives** the self-leg from it (the standalone picker is retired):

| Forward Mode (`backend_mode`) | Ingress (client→slave) | Derived `self_leg_outer_transport` | CF coupling |
|---|---|---|---|
| `current_mode` | direct | `cnix` | cleared |
| `cf_mode` | Cloudflare | `cf` | `cf_location_group` required |
| **`leigod_mode`** | **direct (like current_mode)** | **`leigod`** (+ `leigod_uot_impl='lguot'`) | **all cleared; `leigod_line_id` required** |

**Key invariant — why no agent change was needed:** the Python agents key the ingress off `ingress_protocol`/`backend_mode` and the self-leg off `self_leg_outer_transport`. Realization keys CF behaviour off `backend_mode` (`=== 'cf_mode'` / `str_starts_with('cf')`), and **`staticSidecarCombinationUsesCf()` does not read `self_leg_outer_transport`**. So a non-cf `leigod_mode` naturally takes the DIRECT path everywhere, and `cf_mode → self_leg='cf'` is a realization no-op (`'cf'` and `'cnix'` are both just "not leigod"). The change is **panel + DB only**; agents stay at `2026.06.05.2`.

---

## 3. Files Changed & Functions

All paths under the repo root `leikwan-br1/`.

### 3.1 `master/src/Support/ProvisioningService.php` — `saveForwardCombination()` (commit `bc5b48f`)
- **Validation** (`~:487`): `backend_mode` whitelist now `['current_mode','cf_mode','leigod_mode']`.
- **Derivation block** (inserted right after `$selfLegOuterTransport` is read, before the enum check + leigod guards so the existing line/sidecar guards and SOCKS-cred minting fire):
  ```php
  $leigodUotImpl = strtolower(trim((string)($payload['leigod_uot_impl'] ?? 'gost'))) === 'lguot' ? 'lguot' : 'gost';
  $selfLegOuterTransport = strtolower(trim((string)($payload['self_leg_outer_transport'] ?? 'cnix'))) ?: 'cnix';
  if ($backendMode === 'leigod_mode') {
      $selfLegOuterTransport = 'leigod';
      $allowPrivateSelfLeg   = false;       // suppress the self-leg CF primary leg
      $cfLocationGroupId     = 0;           // direct ingress, no CF
      $selfLegCfTransport    = 'cf_direct';
      $leigodUotImpl         = 'lguot';     // leigod_mode default carrier
  } elseif ($backendMode === 'cf_mode') {
      $selfLegOuterTransport = 'cf';
  } else {
      $selfLegOuterTransport = 'cnix';
  }
  ```
- **Persist `leigod_uot_impl`** — added to the `$normalized` array and the UPDATE SQL. (`saveForwardCombination` never wrote this column before; the realization default stays `'gost'` so `provisioning_leigod_uot_impl_test` is unaffected.)
- **Reused unchanged:** the leigod line/sidecar guards (`leigod_line_required`, `sidecar_required_for_leigod`), the SOCKS credential mint/preserve block, and `republishActiveForwardsForCombination()` (called on every save).

### 3.2 `master/src/Controllers/AdminController.php` — forward-combination form (commit `57394ea`)
- Added `<option value="leigod_mode">` to the `backend_mode` `<select>` with a hint: *"leigod_mode = direct ingress (like current_mode, no Cloudflare) + a LeiGod self-leg."*
- **Retired** the `LEIGOD_BASIS_ENABLED` gate and the standalone `self_leg_outer_transport` picker.
- Replaced with always-rendered **LeiGod line id** + **LeiGod UoT carrier** (`lguot`/`gost`) fields tagged `data-mode-field="leigod_mode"`, plus an inline `<script>` that shows them only when `leigod_mode` is selected (and the Cloudflare Location Group only for `cf_mode`).

### 3.3 `master/migrations/20260603_promote_leigod_mode.sql` (commit `5ad777c`)
Idempotent. (1) Converts every `self_leg_outer_transport='leigod'` combination to `backend_mode='leigod_mode'` and strips CF coupling (`cf_location_group_id=NULL`, `allow_private_self_leg=0`, `self_leg_cf_transport='cf_direct'`, `leigod_uot_impl='lguot'`). (2) **Critical landmine fix:** clears `forwards.cf_location_group_id` for those forwards — otherwise `forwardUsesCfMode()` (which checks `cf_location_group_id > 0`) keeps reporting CF even after the combo flips.
- `master/migrations/schema.sql`: documented the 3 `backend_mode` values (comment only; `VARCHAR(32)` already fits, `self_leg_outer_transport` ENUM already has `leigod`).

### 3.4 i18n (commit `d0d3834`)
- `master/public/i18n/zh-Hans.json`, `zh-TW.json`: registered the `leigod_mode` label.

### 3.5 Tests
| Test | Purpose | Result |
|---|---|---|
| `master/tests/saveforwardcombination_leigod_mode_test.php` (new) | mode accepted; derivation + `leigod_uot_impl` persistence; CF cleared; back-compat (current→cnix→gost) | **PASS** |
| `master/tests/provisioning_leigod_mode_direct_ingress_test.php` (new) | source-grep guard: cf branches still gate on `cf_mode`/`cf`; `leigod_mode` never lumped into a cf comparison; realization default stays `gost` | **PASS** |
| `master/tests/leigod_mode_migration_shape_test.php` (new) | migration has the flip + CF-strip + the `forwards` clear + idempotency guard | **PASS** |
| `master/tests/saveforwardcombination_leigod_guard_test.php` (updated) | leigod line/sidecar guards now fire under `leigod_mode` | **PASS** |
| `master/tests/admin_forward_combination_leigod_field_test.php` (updated) | `leigod_mode` option + line-id + carrier present; `LEIGOD_BASIS_ENABLED` + basis picker retired | **PASS** |

---

## 4. How To Use It (post-deploy)

1. Admin → Forward Combinations → edit/create.
2. **Forward Mode** dropdown → **`leigod_mode`**.
3. Fields that appear: **LeiGod line id** (e.g. `453`) and **LeiGod UoT carrier** (`lguot`, default). The CF location-group field hides automatically.
4. Pick a **Sidecar point** (required) + **Client mode**. Save.

On save the panel automatically: derives `self_leg_outer_transport='leigod'`, defaults `leigod_uot_impl='lguot'`, mints/preserves the shared SOCKS creds, clears all CF coupling, and calls `republishActiveForwardsForCombination($id)`. Agents reconcile the LeiGod line + lguot units on their next heartbeat — **no redeploy**.

---

## 5. Test Results

### 5.1 Tested — SUCCESS ✅
- **Panel unit suite:** 142 pass / 16 pre-existing failures (the 16 are environmental — no MySQL PDO driver on the dev box — or pre-existing source-grep/i18n-coverage debt; they fail identically on the pre-change baseline, i.e. **0 regressions**). All 8 leigod/route tests green.
- **prod↔git divergence resolved:** the entire leigod/lguot/forward-route feature lived only on `feat/leigod-and-forward-route` (36 commits, deployed via pscp, never merged). Merged into `master` (`a9f3405`) cleanly, preserving the lone prod-only commit `15ff013`. Verified prod's `ProvisioningService.php`/`AdminController.php` were byte-identical to the merge base before deploying.
- **Code deploy to prod:** uploaded `ProvisioningService.php`, `AdminController.php`, i18n, migration to `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/`; both PHP files lint clean on prod; backups saved (`*.bak-leigodmode`).
- **DB cutover of combo 5:** migration flipped combo 5 + forwards 65 (port 11035) & 67 (port 11036) to `leigod_mode`, `cf_location_group_id=NULL`. Verified in DB.
- **Data-plane CF removal verified:** after republish + client-agent restart, forward 65's bond became **3 direct legs** to slaves node20/node2/node19 with **zero CF markers** (no `self_leg`, `wss://`, `162.159`). The CF "self-leg primary" leg was genuinely gone.

### 5.2 Tested — FAILED ❌
- **Live leigod-direct (no-CF) throughput was NOT stable.** With CF removed, the bond legs **flapped hard** — up to **173 cooldown events in 2 minutes** on forward 65/67 — yielding wildly unstable, low throughput (single-stream reads of 55.9 → 2.36 → 18.4 → 1.39 Mbit/s, control sockets dropping). The leigod self-leg WG handshakes were healthy (0–85 s fresh) the whole time; the *delivery over them* under sustained bond load was not.
- **The cutover was rolled back** to the prior `cf_mode` config to restore service.
- **My remediation made it temporarily worse:** I restarted the client, slaves, and sidecar **repeatedly** chasing convergence, which tripped the slaves' **circuit breakers** ("3 restarts in 600 s; backing off") and caused a reconcile storm. The system needed ~10 min untouched to self-heal.

### 5.3 Post-recovery measured state (cf_mode restored)
- Both forwards settled to **0 cooldowns**.
- **Forward 67 (11036): stable ~300 Mbit/s** (user-confirmed) — *better* than the pre-change ~25, thanks to the healthy `lguot` legs now carrying the bulk while CF acts as a stability anchor.
- **Forward 65 (11035): variable** (e.g. 300 sender / 23 receiver = ~92% loss in bad moments) — see root cause.

---

## 6. Root-Cause Analysis

1. **The CF self-leg was masking instability, not just "dragging."** Combo 5's `cf_mode` bond carried a CF self-leg (`wss://…:2053`) as a stable anchor leg *plus* the leigod legs. Removing it (pure `leigod_mode`) exposed the leigod slave→sidecar delivery's jitter with no fallback → flapping. The earlier "CF is the drag" framing was incomplete: CF was also the **stability floor**.
2. **2-vCPU slave CPU ceiling (the real throughput cap).** `node2` runs at **100% `sy` (kernel WireGuard crypto), load 2.42 on 2 vCPUs** under load. The 3 slaves deliver **~300 Mbit/s total**, not per-forward. The "300 sender / 23 receiver" on 65 is the saturated slave dropping ~92% of packets — exactly the documented leigod CPU bound.
3. **Two forwards to the same target contend.** 11035 (65) and 11036 (67) hit the *same* endpoint over the *same* CPU-bound slaves, so they split the ~300 ceiling — 67 "won" (300), 65 starved (23). They cannot both run at 300.
4. **Multi-role convergence was mishandled (operator error).** A `backend_mode` change must re-converge **all three roles** (client + slaves + sidecar). I only restarted the client at first, leaving the slaves/sidecar on the old config → mismatch → flapping; then over-restarted, tripping circuit breakers.

---

## 7. Current Production State

- **Combo 5 = `cf_mode`** (rolled back), settled, both forwards reachable and stable; 67 ≈ 300 Mbit/s. The bond uses the fast `lguot` leigod legs for throughput with the CF self-leg as a stability anchor.
- **Panel `leigod_mode` code is deployed but INERT** — combo 5 is not using it; harmless, and a clean foundation for a future "leigod-primary + CF-backup" mode.
- Sidecar `leikwan-bond-sidecar-5-iperf3-5201` is the managed iperf3 target for testing.
- Prod PHP backups: `ProvisioningService.php.bak-leigodmode`, `AdminController.php.bak-leigodmode`.
- Local git: `master` includes the merge + leigod_mode commits; **not yet pushed to origin** (GitHub was unreachable during the session — push when connectivity returns).

---

## 8. What To Do Next

**Priority 1 — decide the production forward shape (operator):**
- 11035 and 11036 forward to the *same* endpoint over the *same* CPU-bound slaves. **Use one.** If 11035 should be the 300 port, disable/remove forward 67 so 65 gets the full slave capacity; otherwise keep 11036.

**Priority 2 — make "no CF" actually viable (engineering):** pure `leigod_mode` flapped because it removed the stability anchor. The right design is **leigod-primary + CF-backup**: keep a CF (or cnix) leg as a low-priority stability/fallback leg while the leigod legs carry the bulk. Concretely, this is closer to what `cf_mode`-with-healthy-leigod-legs already does (300 stable). Consider a `leigod_mode` variant that **keeps a demoted CF/cnix anchor leg** rather than removing it entirely. (The Forward Route racing work — B3/B4, deferred — is the structured way to express "race leigod, fall back to cnix/cf".)

**Priority 3 — root-cause the leigod delivery jitter (engineering):** with CF removed and the slaves *not* saturated, the legs still flapped. Investigate the `lguot`/WG self-leg under sustained bond load: packet loss vs CPU, the bond's leg health-check sensitivity, and whether the sidecar (`nrt1`, also 2 vCPU) is the aggregation bottleneck (`sy`-saturated). This is a data-path problem, separate from the panel feature.

**Priority 4 — raise the ceiling (infra):** the ~300 aggregate cap is the 2-vCPU slaves' WG-crypto. Bigger-vCPU slaves (and/or a bigger-core sidecar) are the only lever for higher aggregate or 300-per-forward. Mode choice does not change this.

**Operational discipline learned:** a `backend_mode` change is a **3-role convergence**. Change the DB → republish → then re-provision **client AND slaves AND sidecar** (or wait for one heartbeat cycle), and verify with **one spaced measurement**. Do **not** restart agents repeatedly — it trips circuit breakers and prolongs the outage. Let it settle.

**Housekeeping:** push `master` to origin when GitHub is reachable; remove the prod `*.bak-leigodmode` files once satisfied; if `leigod_mode` is to be retired from the UI, revert commit `57394ea` (UI only — the data model is harmless).

---

## 9. Related Memory / Docs
- `[[leigod-mode-toplevel]]` — this feature, condensed.
- `[[leigod-uot-cpu-bound]]` — the 2-vCPU WG-crypto ceiling + the lguot work that lifted per-leg throughput.
- `[[tokyo-forward-no-cf-ingress]]` — the earlier (also reverted) attempt to drop CF via `current_mode`; same instability class.
- `[[ssh-password-trailing-dot]]` — host credentials (the root passwords end in a literal `.`).

---

## 10. Post-Investigation Update — automation gap fixed + stability remediation (2026-06-03, later)

A follow-up systematic investigation (9-agent workflow + independent code re-read + 3 adversarial verifications) revised §5/§6: the *"panel feature is sound; only the data path is unstable"* framing was **incomplete**. Two distinct defects existed; both now have surgical, **panel-only** fixes (no agent/Rust rebuild — they flow to clients on the next config pull).

### 10.1 Automation gap (NEW root cause — §6.4's "operator error" was a mis-attribution)

`leigod_mode` was **not** automation-usable from the panel alone. `saveForwardCombination()` updates only the `forward_combinations` row (and `republishActiveForwardsForCombination()` only reconciles), but the published task reads the **denormalized `forwards` row**: `publishTaskPayloadForForward()` keys `backend_mode` + the `cf_mode` ingress block off `$forward['backend_mode']` (`ProvisioningService.php:6399,6435`), and `forwardUsesCfMode()` returns true while `forwards.cf_location_group_id > 0` (`:7967`). Those two columns are synced from the combination **only at forward-save time** (`saveForward` `:4147/4468`) — *not* on combo save.

So a live panel flip `cf_mode → leigod_mode` shipped an **internally incoherent task**: a leigod self-leg (recomputed live from the combination) **plus a still-armed CF ingress leg** (stale `forwards` row) — exactly the cf/leigod mismatch that flaps bond legs. **No agent restart fixes this** — the served config is genuinely stale. The combo-5 test only looked clean because the operator hand-ran the migration (`20260603_promote_leigod_mode.sql` step 2, whose own comment says *"CRITICAL: without this the cutover is incomplete"*) — a one-time manual SQL, not automation.

**Fix:** `saveForwardCombination()` now re-syncs the bound forwards' `backend_mode` + `cf_location_group_id` from the combination *before* republish (mirroring the migration). It generalizes to all 3 modes (leigod→`cf_location_group_id=NULL`, cf→the group, current→NULL).
- `master/src/Support/ProvisioningService.php` — `UPDATE forwards SET backend_mode/cf_location_group_id … WHERE forward_combination_id` immediately before `republishActiveForwardsForCombination($id)`.
- Test: `master/tests/saveforwardcombination_syncs_forwards_ingress_test.php`. **PASS** (RED→GREEN verified).

### 10.2 Stability — mode-aware leg cooldown

The combo-5 flap (173 cooldowns/2 min) was confirmed a **data-path** problem on an *already-coherent* config (so NOT the §10.1 gap — the migration had synced it). The bond's `apply_leg_cooldown` (`leikwan-bond/src/session.rs:4638`) is a **fixed duration with no escalating backoff**, controlled entirely by `--leg-cooldown-ms`. The published default was **30 minutes** (`BOND_LEG_COOLDOWN_MS`), which on a CPU-bound 2-vCPU leigod slave turns a transient ~2 s stall into a 30-min capacity loss on a scarce few-leg bond.

**Fix:** `leg_cooldown_ms` is now **mode-aware** in `buildBondClientConfig()`:
- `cf_mode` / legacy: keeps the 30-min isolation (a bad CF edge IP should stay sidelined).
- `leigod_mode`: short cooldown, default **20 s**, tunable via the new env `BOND_LEIGOD_LEG_COOLDOWN_MS`. (Relies on §10.1: `forwards.backend_mode` is now reliably `leigod_mode`, so the mode check fires.)
- `master/src/Support/ProvisioningService.php:7714` (mode-aware ternary).
- Test: `master/tests/bond_leigod_leg_cooldown_publish_test.php`. **PASS** (RED→GREEN verified).

This **mitigates** (does not eliminate) the flap: a transiently-stalled leigod leg rejoins in seconds instead of 30 min. Under *sustained* saturation the 2-vCPU WG-crypto ceiling (§6.2) still caps aggregate throughput — infra (Priority 4), unchanged by mode choice.

### 10.3 Remaining durable work — leigod-primary + demoted-anchor (design scope, NOT implemented)

Pure `leigod_mode` removed the CF **stability floor** (§6.1). The durable fix is **leigod-primary + a demoted CF/cnix anchor leg**: leigod legs carry the bulk, plus one low-priority standby leg (CF or cnix) that only carries traffic when all leigod legs are momentarily unavailable. Sketch:
- **Panel:** for `leigod_mode`, additionally publish ONE demoted anchor leg, reusing the existing `ws_priority`/`quic_priority`/`wss_standby_enabled` leg-priority machinery (the bond already supports warm-passive standby legs). Gate behind a per-combo opt-in (e.g. `leigod_anchor_basis ∈ {none, cnix, cf}`).
- **Agent:** render the anchor leg alongside the leigod legs (the slave already supports cnix/cf self-legs; this is a config-shape change, not a new transport).
- **Rust:** likely no change — standby + priority ordering already express "use only when preferred legs are down"; verify the standby leg is not counted toward the active CPU-bound set.
- **Tradeoff:** reintroduces a (demoted) CF/cnix international leg — the thing `leigod_mode` set out to drop — but only as a floor, not the primary path. Alternative lever: bigger-vCPU slaves (Priority 4) make pure-leigod viable without an anchor.

This is a product decision (accept a demoted anchor vs. invest in bigger slaves) and a multi-role change; it warrants its own plan + review before implementation.

### 10.4 Other findings (logged, not yet fixed)
- **Slave:** the reconcile-issued `wg-quick` restart bypasses the circuit breaker (`slave_agent.py:4477`) — not throttled by nor counted against it. (Steady-state reconcile is idempotent; self-leg cutover is clean.)
- **Client:** only a 30 s per-forward restart throttle, no circuit breaker (`client_agent.py:1968`) — amplifies transient jitter.
- **Sidecar:** leaves an *inert* stale `cf-routes` row after a flip (cosmetic; the dead unit is removed). `leigod_uot_impl=lguot` silently no-ops on a node missing the `lguot` binary.
- **Tests:** still no automated coverage of a live 3-role mode transition (the §10.1/§10.2 tests cover the panel publish side only).

### 10.5 Live cutover retry of combo 5 (2026-06-03) — fixes validated in prod, pure-leigod reverted

Committed locally (`207bed9`). PHP deployed to prod (`ProvisioningService.php`, sha `d4ec5f3e`, `php -l` clean; backup `…/master/src/Support/ProvisioningService.php.bak-20260603-211156-ingressfix`). Combo 5 + forwards 65/67 flipped `cf_mode → leigod_mode` (targeted DB UPDATE mirroring the derivation) and republished via a one-off `republishActiveForwardsForCombination(5)` + `…ForSidecarPoint(5)`.

**Validated LIVE (both fixes work):**
- **Coherent config:** the client bond for fwd 65 (`:11035`) and 67 (`:11036`) came up with the **3 slave legs only and zero CF (`162.159`) legs** — §10.1's ingress sync produced a clean leigod config (the pre-flip slave tasks had `162.159`; the post-flip ones did not).
- **20s cooldown live:** both forwards ran with `--leg-cooldown-ms 20000` (vs the untouched fwd 66 still at `1800000`) — §10.2 confirmed end-to-end.
- **Auto-convergence, no manual restart:** the client agent reconciled and restarted only the two affected forward services on its own heartbeat (~2.5 min after DB+republish). No restart storm. **This is the automation gap closed, proven in prod.**

**Reverted (pure-leigod still not throughput-stable — as §10.3 predicted):**
- Post-flip warmup churned (~330 cooldowns/6 min) then settled to ~2–4/min idle, but under an iperf3 load test the path **could not complete a transfer** (the bond accepted the local connection but delivery stalled) — the 2-vCPU WG-crypto ceiling with no stability anchor. fwd 67 had been the stable ~300 Mbit/s producer on the hybrid.
- Per the "one spaced measurement, then decide" discipline, combo 5 was **reverted to the `cf_mode` hybrid** (DB + republish; slaves re-applied, CF restored, verified). The improved PHP stays deployed (harmless under `cf_mode`; ready for a future leigod cutover).

**Conclusion:** the automation gap (§10.1) and the cooldown pathology (§10.2) are **fixed and verified in prod**. Pure `leigod_mode` is now *automation-usable and recoverable* (catastrophic 30-min-sideline flap → recoverable 20 s churn) but still needs **§10.3 (leigod-primary + demoted anchor)** or bigger-vCPU slaves before it can replace the `cf_mode` hybrid for sustained throughput. The fixes cannot raise the CPU ceiling.
