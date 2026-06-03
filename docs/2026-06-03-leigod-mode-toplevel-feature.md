# LeiGod as a First-Class Forward Mode (`leigod_mode`) — Feature Record

**Date:** 2026-06-03
**Branch merged to:** `master` (commits `bc5b48f`, `57394ea`, `5ad777c`, `d0d3834`, on top of merge `a9f3405`)
**Status:** Panel code **complete, tested, deployed to prod (inert)**. Live cutover of production combo 5 was attempted, **failed (flapped), and rolled back**. The panel feature is sound; the *live leigod-direct data path* is not yet stable enough to drop CF. See [Test Results](#test-results) and [What To Do Next](#what-to-do-next).

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
