# LeiGod Mode — MPTCP-over-LeiGod (Compose Model) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or superpowers:executing-plans. Steps use `- [ ]`. **Phase 0 is investigation and GATES Phases 1–2** — it must (a) confirm the last-outage signature so we know this redesign actually targets it, and (b) confirm the runtime-selection facts the panel edit depends on. Phases 1–3 are code/config (TDD, **committable**). Phases 4–5 are **deploy + live validation and require explicit separate authorization** (see Authorization boundary).

**Goal:** Make `leigod_mode` run client→slave as **true MPTCP** (no `leikwan-bond` binary → no re-admit-churn), with the slave→sidecar hop carried by the **existing leigod static sidecar uplink** that the MPTCP underlay rides *over*. Spec: `docs/superpowers/specs/2026-06-04-leigod-mode-transparent-uot-mptcp-design.md`.

**Architecture (compose model):** Client MPTCP subflows = the N per-slave WG underlay interfaces (`lwf<fid>n<nid>`, client-peer-only, `uses_static_sidecar_uplink=true`). Each slave's slave→sidecar bytes route through that slave's **static uplink interface** (`outer_transport='leigod'`: lguot UoT over a LeiGod line, MTU 1280, sidecar lguot server). The carrier is reused, not re-implemented.

**Tech stack:** PHP 8.3 (`master/`, standalone-script tests via `php`, `php -l`), Python slave agent, `wg`/`iperf3`/`journalctl`/`ss`, plink/pscp + **panel (10.0.10.196) as jump host** to Shanghai slaves + Tokyo sidecar (185.220.238.143). Slave creds `leikwan-v3.txt`; sidecar `root@185.220.238.143`/`DefaultPasswd123.`.

**Validated foundation (do NOT re-litigate):** LeiGod TCP-only; one lguot UoT/slave ≈ 165–190 (canonical ~175); 3-slave concurrent dialing (30 s); MPTCP doesn't run the bond binary; the MPTCP underlay already composes over the static uplink. 65/67 currently disabled.

**Authorization boundary:** Phases 0–3 are commit-only (the current task). Phases 4–5 (pscp to prod, flip combo 5, live iperf3) require an **explicit deploy go** — mirror the `[[appendix-c-deploy-authorization]]` precedent. Do not deploy without it.

---

### Phase 0 — Confirm the target & the runtime-selection facts (GATE; read-only)

**Files:** none. Output: `docs/2026-06-04-mptcp-leigod-compose-findings.md`.

- [ ] **Step 1: Capture the ACTUAL last-outage signature of 65/67** (via panel jump host, read-only): `journalctl` restart counts for the slave/sidecar agent + selector units, any `EADDRINUSE`, CF 520s, selector port collisions (`ss -tlnp`). **Decision:** if the outage was an agent restart storm / port collision (per `[[selector-port-collision-storm]]`), record that MPTCP alone will NOT fix it and what must accompany this change (collision-free ports). If it was bond cooldown/re-admit, this redesign targets it directly.
- [ ] **Step 2: Confirm the runtime gate** by code read: `publishesBondRuntime = clientMode==='bond' || runtimePreference==='mptcp_preferred_bond_fallback'` (`ProvisioningService.php:3532`, `:6421`); the slave early-return at `slave_agent.py:3169-3209` skips `apply_sidecar_uplink` (`:3211`) when `mptcp_underlay.enabled && !publish_bond_runtime`. Record the exact lines.
- [ ] **Step 3: Confirm `forward['mptcp']` is runtime-ready for a leigod combo.** Verify `buildMptcpSlaveUnderlayPayload` emits `sidecar_mptcp_port>0` (`:9112`) + `tcp_session_token` (`:9113`), and that `client_agent.py:2619-2672` `forward_has_mptcp_runtime_config` will then return true (so `choose_runtime_family` returns `mptcp`). If the leigod combo does **not** populate the mptcp block, that is an additional Phase-2 task — record it.
- [ ] **Step 4: Exit criteria** — the findings doc must state, definitively: (i) does this redesign target the real outage; (ii) the exact early-return fix site; (iii) whether the mptcp block is already populated for leigod combos. Commit: `git add docs/2026-06-04-mptcp-leigod-compose-findings.md && git commit -m "docs(leigod): confirm compose-model target + runtime-selection facts"`

---

### Phase 1 — Slave agent: apply the static uplink alongside the MPTCP underlay (TDD, core fix)

**Files:** Modify `slave-agent/slave_agent.py` (dispatch `:3169-3209`); Test `slave-agent/tests/mptcp_underlay_applies_leigod_sidecar_uplink_test.py` (new, follow existing slave-agent test pattern).

- [ ] **Step 1: Failing test.** Given a task payload with `mptcp_underlay.enabled=true`, `publish_bond_runtime=false`, and `sidecar_uplink.enabled=true` (`outer_transport='leigod'`), assert the agent calls **both** `apply_mptcp_underlay` **and** `apply_sidecar_uplink` (spy/stub both; the existing tests stub `wg`/systemd). Today only `apply_mptcp_underlay` is called (then it returns).
- [ ] **Step 2: Run → verify it fails** (only `apply_mptcp_underlay` invoked; `apply_sidecar_uplink` not).
- [ ] **Step 3: Implement.** In the true-MPTCP branch (`:3169-3209`), before the `return`, add: `if sidecar_uplink.get("enabled"): self.apply_sidecar_uplink(service_name, sidecar_uplink)`. Keep the underlay applied first (the underlay iface needs to exist), then the static uplink. Ensure idempotent teardown is unaffected (the non-MPTCP path at `:3211` is unchanged).
- [ ] **Step 4: Run → PASS.** Then run the full slave-agent test suite → green, no regressions.
- [ ] **Step 5: Commit.** `git add slave-agent/slave_agent.py slave-agent/tests/mptcp_underlay_applies_leigod_sidecar_uplink_test.py && git commit -m "fix(slave/leigod): provision leigod static uplink alongside true-MPTCP underlay"`

---

### Phase 2 — Panel: make leigod_mode combos true-MPTCP + migrate existing combo (TDD)

**Files:** Modify `master/src/Support/ProvisioningService.php` (`saveForwardCombination` leigod branch ~`:469-600`); Tests `master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php` (new) + `master/tests/saveforwardcombination_leigod_rejects_bond_test.php` (new); Migration `master/migrations/20260604_leigod_mode_true_mptcp.sql` (new).

- [ ] **Step 1: Failing test — leigod_mode persists true-MPTCP runtime.** Assert that saving a `leigod_mode` combo stores `client_mode='mptcp'` and a `runtime_preference` that does **NOT** satisfy `publishesBondRuntime` (i.e. not `mptcp_preferred_bond_fallback` and not `bond`), while keeping `self_leg_outer_transport='leigod'` and `allow_private_self_leg=0` (unchanged). Run → fail.
- [ ] **Step 2: Failing test — leigod_mode rejects bond.** Assert saving `leigod_mode` with `client_mode='bond'` (or a bond-forcing runtime_preference) raises an `ApiException` (`invalid_leigod_runtime` or similar). Run → fail.
- [ ] **Step 3: Implement** the leigod branch: force `client_mode='mptcp'` + the non-bond-forcing runtime_preference; validate-reject bond; leave `self_leg_outer_transport`/`allow_private_self_leg` as-is. If Phase 0 Step 3 found the mptcp block not populated for leigod combos, also ensure the combo carries `sidecar_mptcp_port` + token. Run both tests → PASS; `php -l`.
- [ ] **Step 4: Migration (idempotent).** Mirror `migrations/20260603_promote_leigod_mode.sql`: flip existing `leigod_mode` combinations to `client_mode='mptcp'` + the chosen runtime_preference, and align `forwards.*`. Add `master/tests/leigod_mode_migration_shape_test.php` asserting the post-migration column shape. Run → PASS.
- [ ] **Step 5: Regression gate (named-set, not count).** Record the exact current failing test names **and their failure messages** (baseline 144 pass / 16 fail). After Phase 1–2, assert: the same 16 fail with the same messages, AND `provisioning_mptcp_preferred_bond_slave_payload_regression_test` + `provisioning_slave_mptcp_desired_state_regression_test` + `bond_leigod_leg_cooldown_publish_test` did not change failure mode (a logic flip while count stays 16 is a real regression). `bond_leigod_leg_cooldown_publish_test` must stay green — the leigod cooldown is **kept** for the bond-fallback path (not retired).
- [ ] **Step 6: Commit.** `git add master/src/Support/ProvisioningService.php master/migrations/20260604_leigod_mode_true_mptcp.sql master/tests/saveforwardcombination_leigod_mode_true_mptcp_test.php master/tests/saveforwardcombination_leigod_rejects_bond_test.php master/tests/leigod_mode_migration_shape_test.php && git commit -m "feat(panel/leigod): leigod_mode runs true MPTCP (+migration, reject bond)"`

---

### Phase 3 — MTU single-source (small)

**Files:** Modify `master/src/Support/ProvisioningService.php:5841`; Test `master/tests/leigod_static_uplink_mtu_config_test.php` (new).

- [ ] **Step 1: Failing test** — the leigod static-uplink slave payload carries `mtu` from a `BOND_LEIGOD_WG_MTU` config (default 1280), not a hardcoded literal. Run → fail.
- [ ] **Step 2: Implement** — replace the `$slave['mtu'] = 1280` literal (`:5841`) with `$this->config->getInt('BOND_LEIGOD_WG_MTU', 1280)`. Run → PASS; `php -l`. (No MPTCP-underlay MTU work — that interface does not traverse LeiGod in the compose model.)
- [ ] **Step 3: Commit.** `git commit -m "refactor(panel/leigod): MTU 1280 from config (single source)"`

---

### Phase 4 — Deploy (REQUIRES explicit go; version-safe)

- [ ] **Step 1:** Confirm prod `ProvisioningService.php` sha matches the pre-change git blob (no drift) before upload.
- [ ] **Step 2: Panel** — `git show HEAD:master/src/Support/ProvisioningService.php` → temp → back up prod (`*.bak-leigod-compose`) → `pscp` → `php -l` on prod → run the migration.
- [ ] **Step 3: Slave agent (three-artifact, UNCONDITIONAL — the slave agent changed).** Per `[[panel-artifact-serving]]`, deploy + sha-match all three: `slave-agent/`, `artifacts/`, `master/public/artifacts/`; bump `artifacts/versions.json`. **Ordering:** ship the new slave agent and confirm a canary slave pulls it **before** the panel publishes the true-MPTCP shape (so a leigod combo never reaches an old slave that would early-return without the uplink). (MPTCP client + sidecar agents are **unchanged** — no deploy.)
- [ ] **Step 4:** Confirm clean reconcile / no churn / no EADDRINUSE on the canary slave.

---

### Phase 5 — Validation gate + soak + chaos + rollback (REQUIRES explicit go)

- [ ] **Step 1: Flip combo 5 / re-enable forward 65 only** (67 stays parked — see Step 6) into the true-MPTCP leigod shape; republish; let agents reconcile on heartbeat (no restart storm).
- [ ] **Step 2: Confirm true MPTCP rides leigod:** client `choose_runtime_family`==`mptcp` (verify `forward_has_mptcp_runtime_config`: `sidecar_mptcp_port>0` + token) and **no `leikwan-bond` process**; slave has **both** the MPTCP underlay iface and the leigod static-uplink iface (lguot client up); sidecar lguot server up + WG fresh.
- [ ] **Step 3: Sustained measurement** (≥60 s, spaced): iperf3 through 65 — watch **no cooldown storm, no agent restart storm (EADDRINUSE)**, throughput **approaching ~223 sidecar cap** (NOT N×190), latency ≈ single-leg, per-slave one UoT, 0% loss.
- [ ] **Step 4: Soak + chaos.** ≥30 min, 3 slaves concurrent — watch LeiGod disconnect/IP-rotation/throttle (fraud-safety unproven at 30 s). Then kill `leigod@453` mid-iperf; record recovery vs wedge (no-fallback risk).
- [ ] **Step 5: Decision + rollback runbook.** If stable → record results in `docs/2026-06-03-leigod-mode-toplevel-feature.md` + memory. **If it falls back to bond, storms, or wedges → execute rollback:** re-disable 65 → revert combo-5 row to its prior `client_mode`/runtime via the migration-down values recorded in Phase 0 → republish → restore panel `*.bak-leigod-compose` and prior `versions.json` if needed. Do NOT leave 65 half-migrated.
- [ ] **Step 6: 67 — descoped by default.** 67 stays parked. Re-enabling requires a separate check: 65 with 3 slaves likely already approaches the ~223 cap, and 65/67 must have collision-free per-lane gost/lguot/uot ports (`stableSidecarGostSocksPort`/`stableLocalUotUdpPort`) on the shared sidecar (cf. `[[selector-port-collision-storm]]`). Gate on measured headroom, not "if capacity allows."
- [ ] **Step 7: Commit docs.**

---

## Self-review (against spec)
- **Spec §3 compose architecture** → Phase 1 (slave applies both) + Phase 2 (true-MPTCP runtime) ✓.
- **Spec §4 levers (slave early-return fix + panel true-MPTCP)** → Phases 1–2 ✓. The `local_uot_udp_port`/`allow_private_self_leg`/leigod-field-surgery non-changes are explicitly absent ✓.
- **Spec §4 "cooldown kept for bond, not retired"** → Phase 2 Step 5 keeps `bond_leigod_leg_cooldown_publish_test` green ✓.
- **Spec §6 MTU single-source (no underlay MTU)** → Phase 3 ✓.
- **Spec §9 bond-runs-away** → Phase 2 Step 2 (reject bond) ✓. **Spec §9 actual-outage attribution** → Phase 0 Step 1 (the gate) ✓. **Spec §9 no-fallback** → Phase 5 Step 4 chaos ✓.
- **Spec §7/§8 realistic cap (min(N×175,~223), not N×190)** → Phase 5 Step 3 ✓.
- **Migration of existing combo** → Phase 2 Step 4 ✓. **Rollback** → Phase 5 Step 5 ✓. **67 handling** → Phase 5 Step 6 (descoped) ✓.
- **Regression gate** → named-set, not count ✓.
- **Authorization:** Phases 0–3 committable; 4–5 gated on explicit deploy go ✓. No execution in this document.
