# Client Donation as Public Slave + Fastest-4 Slave Selection Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Date:** 2026-05-18
**Branch baseline:** `master`
**Status:** Ready to start (Task 0 first)
**Artifact path:** `docs/superpowers/plans/2026-05-18-client-donation-and-fastest-slave-selection-plan.md`
**Design doc:** `docs/superpowers/specs/2026-05-18-client-donation-and-fastest-slave-selection-design.md`

**Goal:** Land two related features in one phased rollout:

1. **Donate Your Client-as-a-Slave** — admin opts a forward combination into
   public client-as-a-slave donation. A donor's client-agent is auto-published
   as a slave for that combination only when its measured
   `slave -> sidecar -> endpoint` RTT is at or below 75% of the admin's
   desired latency AND it has at least one open inbound port. Withdraw on any
   gate failure.
2. **Multiple slaves per forward combination, fastest-4 chain** — admin picks
   multiple slaves (rented + donated + admin-pinned) for one forward
   combination. The runtime keeps only the **4 fastest** in the active chain,
   measured by `slave -> sidecar -> endpoint` RTT. All forwards under a
   forward combination share the same slave selection.

**Architecture invariants:**

- Topology stays `client -> slave -> sidecar -> target`. No new bypass.
- Donation is per forward combination, never global.
- Donation is panel-decided; slaves only report raw measurements.
- Fastest-N selection is per combination, not per forward.
- The cap reuses the existing `helper_group_cap` column (label "Max Legs
  Used (cap)") with default `4`. **No new cap column.**
- Tenant-facing surface stays unchanged.

**Tech stack:**

- PHP panel: `master/src/Support/ProvisioningService.php`,
  `master/src/Controllers/AdminController.php`,
  `master/src/Controllers/NodeController.php` (heartbeat ingest),
  `master/src/Controllers/SidecarAgentController.php` (sidecar heartbeat).
- MySQL migrations under `master/migrations/`.
- Slave-agent: `slave-agent/slave_agent.py`.
- Sidecar-agent: `sidecar-agent/sidecar_agent.py`.
- Client-agent (Linux only, phase one): `client-agent/client_agent.py`.
- Worker CLI: `master/scripts/evaluate_donations.php`.
- Systemd: `deploy/systemd/leikwan-donation-worker.{service,timer}`.
- Tests: focused PHP regressions under `master/tests/`, focused Python
  `unittest` regressions under `slave-agent/tests/`,
  `sidecar-agent/tests/`, `client-agent/tests/`.

---

## Execution Discipline / 执行纪律

This section is binding for any AI worker (or human) executing this plan.

### Global stop rule (when to stop and report)

The worker MUST stop and report back to the user instead of continuing in any
of the following situations:

- The current task's "Stop condition" is met. Even if later tasks look easy,
  stop, hand back, wait for "go".
- A regression captured at Task 0 Step 0b becomes red after a change here and
  the cause cannot be explained by the active task within 15 minutes.
- A file outside the explicit "Files:" list of the active task needs to be
  edited to make the active task pass. Stop, propose the new file in chat,
  wait for approval.
- A schema change beyond what each migration task names is required. Do not
  invent extra `ALTER`s.
- The branch state on origin has moved (someone else pushed). Stop. Do not
  auto-rebase silently.
- Any test scaffold requires installing a new package, runtime, or system
  tool. Stop.
- A Python or PHP version error appears that suggests the sandbox is
  mismatched with the panel target. Stop.

### Anti-divergence rules / 不要自我发散

- Do NOT add a new "max legs" column. Reuse `helper_group_cap`.
- Do NOT touch tenant-facing controllers (`TenantController.php`) except
  read-only fields the existing summary already returns. Tenant surface stays
  unchanged.
- Do NOT broaden donation eligibility beyond the gates listed in §3 of the
  design doc.
- Do NOT replace `forward_combination_helper_groups`. The new
  `forward_combination_slaves` table is **additive**.
- Do NOT change the meaning or set of `runtime_preference` values.
- Do NOT change the meaning of `helper_group_cap`. Default stays `4`. Label
  stays "Max Legs Used (cap)". Wire/DB key stays `helper_group_cap`.
- Do NOT introduce client-agent code paths for donation. The slave-agent
  inside the donor box is the only donor-side surface. The consumer's
  client-agent only learns donor candidates as ordinary `candidate_pool`
  entries with `candidate_kind = 'donated_slave_leg'`.
- Do NOT bump dependencies, lockfiles, or build configs.
- Do NOT change git config, force-push, rebase, or amend. One commit per
  task; never `--amend` after a hook fails.
- Do NOT run `Republish All Forwards` against any live environment. The
  donation worker plus existing `republishActiveForwardsForCombination`
  is enough.
- Do NOT add tenant-facing English text changes; reuse existing i18n keys.
- Do NOT write end-to-end smoke tests. Only the focused regressions named
  in each task.
- Do NOT modify `LeikwanBridge.Desktop/`, `leikwan-android/`,
  `windows-client/`, `relay-rust/`, or any Windows-specific binary path in
  phase one.

### Global acceptance criteria (must hold at the end of Task 12)

Plan-level pass requires all to be true simultaneously on `master`:

1. `git diff origin/master..HEAD --stat` lists ONLY files declared under any
   task's "Files:" block (plus this plan doc itself).
2. Every regression test named in this plan exits 0.
3. Pre-existing tests captured at Task 0 Step 0b still exit 0 (no
   regressions).
4. `php -l` on every modified PHP file is `No syntax errors detected`.
5. `python -m py_compile` on every modified Python file exits 0.
6. The plan-level invariants below all hold (verifiable by re-running the
   named regression):
   - Editing a forward combination persists `desired_latency_ms`,
     `allow_public_self_leg_donation`, `donation_qualify_window_seconds`,
     `donation_revoke_window_seconds`, `donation_min_open_ports`. (Task 2
     regression)
   - Saving a combination still triggers
     `republishActiveForwardsForCombination`. (Existing Task 2 regression
     from 2026-05-17 plan still passes)
   - Combination-bound forwards select up to `helper_group_cap` slaves
     ranked by `last_chain_latency_ms`. (Task 5 regression)
   - `forward_combination_slaves` rows survive the slave-agent's heartbeat
     ingest path with `selection_kind = 'admin_pin'`. (Task 4 regression)
   - The donation worker activates a donor exactly when all six gates of
     §8 hold. (Task 8 regression)
   - The donation worker revokes a donor on any of the four revoke triggers.
     (Task 9 regression)
   - Heartbeat ingest stores `donation_self_check[]` and
     `donation_reverse_probe[]` without losing existing fields. (Tasks 6, 7
     regressions)
7. No file under `LeikwanBridge.Desktop/`, `leikwan-android/`,
   `windows-client/`, `relay-rust/` has been modified.
8. Branch is pushed (no local-only commits) and the PR/handoff doc references
   this plan path.

### Failure handling (when not to silently retry)

- One regression failing twice in a row with the same error → stop and
  report. Do not "creatively" rewrite the test or the production code.
- A `git push` rejected as non-fast-forward → stop. Do not force, do not
  auto-rebase. Ask user.
- A migration runner error (`column already exists`, `table exists`, etc.) →
  stop. Do not switch to `IF NOT EXISTS` workarounds without approval.
- A panel route returning 500 in manual smoke test → stop, attach the
  relevant log line, do not edit unrelated controllers to "smooth it out".
- The donation worker enters a tight loop revoking and re-activating the
  same donor more than once per minute → stop, attach worker logs.

### Time and step budgets per task (soft guardrails)

| Bucket | Soft cap |
| --- | --- |
| Per-task wall time | 45 min |
| Per-task net diff lines | "fix" tasks ≤ 40, "feat" tasks ≤ 180 |
| Per-task new files | ≤ 3 (one production, one test, one migration) unless task names more |
| Failed test re-runs without code change | ≤ 2 |
| Task retries after a stop | 0 (always wait for user) |

If a soft cap is exceeded, stop and report; do not silently push past it.

---

## File Structure

### Control plane / schema

- Create: `master/migrations/20260518_add_forward_combination_donation_and_latency.sql`
- Create: `master/migrations/20260518_add_forward_combination_slaves.sql`
- Create: `master/migrations/20260518_add_forward_combination_donations.sql`
- Modify: `master/migrations/schema.sql` (mirror all three)

### Panel + provisioning

- Modify: `master/src/Support/ProvisioningService.php`
  - Persist + read the 5 new combination columns.
  - Add `pickFastestSlavesForCombination(int $combinationId, int $cap)`.
  - Replace, for combination-bound forwards, the `pickNodesForGroups` call
    inside `reconcileForwardEndpoints` with the new picker.
  - Add `evaluateDonationStateForCombination(int $combinationId): void`.
  - Add `recordDonationSelfCheck(int $nodeId, array $payload): void`.
  - Add `recordDonationReverseProbe(int $sidecarPointId, array $payload): void`.
- Modify: `master/src/Controllers/AdminController.php`
  - Render donation policy section on combination edit form.
  - Render multi-slave picker (admin-pin + helper-group preview + live
    donations table) on combination edit form.
  - POST handler persists the new fields and the
    `forward_combination_slaves` rows.
  - Add `/admin/forward-combinations/{id}/donations/{donationId}/revoke`
    route handler.
- Modify: `master/src/Controllers/NodeController.php`
  - In heartbeat handler, route `donation_self_check[]` to
    `recordDonationSelfCheck()`.
- Modify: `master/src/Controllers/SidecarAgentController.php`
  - In heartbeat handler, route `donation_reverse_probe[]` to
    `recordDonationReverseProbe()`.
- Modify: `master/src/Bootstrap.php`
  - Wire the new admin route.

### Worker

- Create: `master/scripts/evaluate_donations.php`
- Create: `deploy/systemd/leikwan-donation-worker.service`
- Create: `deploy/systemd/leikwan-donation-worker.timer`

### Slave-agent

- Modify: `slave-agent/slave_agent.py`
  - Add `donation_self_check_loop` coroutine.
  - Probe slave→sidecar→endpoint RTT (extend existing CF probe path).
  - Probe outside-in TCP/UDP reachability for each forward listener.
  - Attach `donation_self_check[]` to heartbeat payload.

### Sidecar-agent

- Modify: `sidecar-agent/sidecar_agent.py`
  - Add reverse port probes per active slave/forward.
  - Attach `donation_reverse_probe[]` to heartbeat payload.

### Client-agent (consumer side, Linux only)

- Modify: `client-agent/client_agent.py`
  - Treat `candidate_kind = 'donated_slave_leg'` identically to
    `rented_group_leg` for selection, probing, reputation. Add a small
    label so logs distinguish them.

### Tests

- Create: `master/tests/forward_combination_donation_columns_schema_regression_test.php`
- Create: `master/tests/forward_combination_donation_columns_persistence_regression_test.php`
- Create: `master/tests/forward_combination_slaves_table_schema_regression_test.php`
- Create: `master/tests/forward_combination_donations_table_schema_regression_test.php`
- Create: `master/tests/admin_combination_multi_slave_picker_regression_test.php`
- Create: `master/tests/provisioning_pick_fastest_slaves_for_combination_regression_test.php`
- Create: `master/tests/node_heartbeat_donation_self_check_ingest_regression_test.php`
- Create: `master/tests/sidecar_heartbeat_donation_reverse_probe_ingest_regression_test.php`
- Create: `master/tests/donation_worker_qualify_to_active_regression_test.php`
- Create: `master/tests/donation_worker_revoke_paths_regression_test.php`
- Create: `slave-agent/tests/test_donation_self_check_payload.py`
- Create: `sidecar-agent/tests/test_donation_reverse_probe_payload.py`
- Create: `client-agent/tests/test_donated_slave_leg_consumed_as_helper.py`

---

## Per-task Stop Conditions and 验收指标 (Acceptance Criteria)

This table is the single source of truth for "when does the worker stop, and
what proves the task is done?". It is binding alongside each task's step list.

| # | Task | Stop condition | 验收指标 / Acceptance criteria |
| --- | --- | --- | --- |
| 0 | Pre-flight | Step 0b finishes; any pre-existing failure is captured in chat. | `git status` clean on `master`. All Step 0b commands exit 0 OR the failures are listed verbatim in chat before Task 1 begins. |
| 1 | Add migrations + schema mirrors | All three new migration files exist and `schema.sql` includes mirrored DDL. The schema regression tests for the three new shapes print `ok`. Stop. | (a) `forward_combination_donation_columns_schema_regression_test.php` passes (5 columns + comments). (b) `forward_combination_slaves_table_schema_regression_test.php` passes (table + indexes). (c) `forward_combination_donations_table_schema_regression_test.php` passes (table + state column + indexes). (d) `schema.sql` mirrors all three. (e) No other column or table touched. |
| 2 | Persist 5 new combination columns end-to-end | `forward_combination_donation_columns_persistence_regression_test.php` prints `ok`. The earlier `forward_combination_save_update_persistence_regression_test.php` (from 2026-05-17 plan) still passes. Stop. | (a) `saveForwardCombination()` reads + writes `desired_latency_ms`, `allow_public_self_leg_donation`, `donation_qualify_window_seconds`, `donation_revoke_window_seconds`, `donation_min_open_ports`. (b) Both UPDATE and INSERT branches include them. (c) `forwardCombinationById()` returns them. (d) Defaults match design (`0`, `0`, `60`, `30`, `1`). (e) `desired_latency_ms` is clamped to `>= 0`. (f) `allow_public_self_leg_donation` is normalized to `0` or `1`. |
| 3 | Admin combination edit form: donation section | `master/tests/admin_forward_combination_self_leg_policy_test.php` (extended) prints `ok` plus the new section snippets. Stop. | (a) Form renders `name="desired_latency_ms"`, `name="allow_public_self_leg_donation"`, `name="donation_qualify_window_seconds"`, `name="donation_revoke_window_seconds"`, `name="donation_min_open_ports"`. (b) Helper text includes the literal substring `Donation gate is 75% of this value`. (c) Section is hidden when `client_mode === 'single'`. (d) POST handler validates `desired_latency_ms >= 0`. (e) "Max Legs Used (cap)" helper text is updated to mention `slave -> sidecar -> endpoint` RTT. (f) Existing tenant tests still pass. |
| 4 | Multi-slave picker UI + persistence | `admin_combination_multi_slave_picker_regression_test.php` prints `ok`. Stop. | (a) Combination edit form renders a multi-select listing nodes whose `group_key` matches the combination's `node_group_key` or any helper-group key. (b) POST handler diffs `forward_combination_slaves` rows with `selection_kind = 'admin_pin'`: insert added, soft-disable removed (set `is_active = 0`). (c) Donor rows (`selection_kind = 'donated_self_leg'`) are NOT touched by admin save. (d) Save calls `republishActiveForwardsForCombination($id)` (existing helper). (e) Form renders a read-only "Live donations" table from `forward_combination_donations` where `state = 'active'`, with a Revoke button posting to `/admin/forward-combinations/{id}/donations/{donationId}/revoke`. |
| 5 | `pickFastestSlavesForCombination` + reconcile integration | `provisioning_pick_fastest_slaves_for_combination_regression_test.php` prints `ok`. Existing `provisioning_forward_combination_helper_groups_regression_test.php` still passes. Stop. | (a) `pickFastestSlavesForCombination(int $combinationId, int $cap): array` exists and returns at most `cap` rows when cap > 0. (b) Source query unions: helper-group nodes + active `forward_combination_slaves` rows where `is_active = 1`. (c) Sort: `last_chain_latency_ms ASC NULLS LAST`, then `delivered_ewma DESC`, then `node_id ASC`. (d) `cap = 0` returns the full ranked list (unbounded). (e) `reconcileForwardEndpoints()` calls the new picker only when `forward_combination_id` is set; otherwise legacy `pickNodesForGroups` path runs unchanged. (f) All forwards under one combination receive the same selected node set within one panel pass (proven by reconciling 2 forwards in one combination and asserting identical node_id sets). |
| 6 | Slave heartbeat ingest: `donation_self_check` | `node_heartbeat_donation_self_check_ingest_regression_test.php` prints `ok`. Existing node heartbeat tests still pass. Stop. | (a) `NodeController::heartbeat` reads `donation_self_check` from request body when present. (b) Calls `ProvisioningService::recordDonationSelfCheck(int $nodeId, array $payload): void`. (c) Persists most-recent self-check into `node_donation_self_check_state` (a JSON column on `nodes` OR a new lightweight table — see Step 6.1). (d) Pre-existing fields (`status`, `cpu_usage`, etc.) keep their values. (e) Empty / missing array is a no-op (no error). |
| 7 | Sidecar heartbeat ingest: `donation_reverse_probe` | `sidecar_heartbeat_donation_reverse_probe_ingest_regression_test.php` prints `ok`. Existing `sidecar_heartbeat_republish_gate_regression_test.php` still passes. Stop. | (a) `SidecarAgentController::heartbeat` reads `donation_reverse_probe` when present. (b) Calls `ProvisioningService::recordDonationReverseProbe(int $sidecarPointId, array $payload): void`. (c) Persists per-(node_id, forward_id, protocol) the most-recent `ok`/`latency_ms`/`checked_at` to a state column or table. (d) Existing republish-gate sentinels still match. (e) Empty / missing array is a no-op. |
| 8 | Donation worker: qualify → active path | `donation_worker_qualify_to_active_regression_test.php` prints `ok`. Stop. | (a) `evaluateDonationStateForCombination(int $combinationId): void` exists. (b) Function transitions `qualifying -> active` exactly when ALL of: `allow_public_self_leg_donation = 1`, `desired_latency_ms > 0`, last `donation_self_check.measured_chain_latency_ms <= floor(desired_latency_ms * 0.75)` for `donation_qualify_window_seconds`, last `donation_self_check.open_ports` count `>= donation_min_open_ports`, last sidecar reverse probe for that (node, forward, protocol) is `ok = true`, donor heartbeat is `<= 120 s` old. (c) On activation: writes a `forward_combination_slaves` row with `selection_kind = 'donated_self_leg'`, sets `donations.activated_at`, calls `republishActiveForwardsForCombination`. (d) Activation is idempotent (re-running the worker does NOT spam republishes). (e) The threshold `0.75` is encoded as a class constant `DONATION_GATE_RATIO = 0.75` for easy auditing. |
| 9 | Donation worker: revoke paths | `donation_worker_revoke_paths_regression_test.php` prints `ok`. Stop. | (a) Revoke triggers when `measured_chain_latency_ms > desired_latency_ms` for `donation_revoke_window_seconds` (sets `revoke_reason = 'latency_above_target'`). (b) Revoke triggers on two consecutive sidecar reverse-probe failures (`'port_probe_failed'`). (c) Revoke triggers when donor's `desktop_client_sessions` row expires (`'session_ended'`). (d) Revoke triggers when admin clears `allow_public_self_leg_donation` (`'admin_disabled'`). (e) Each revoke deletes the matching `forward_combination_slaves` row. (f) Cooldown is `300 s`; donor cannot re-qualify within cooldown. (g) Manual admin revoke from §7.2 sets `state = 'revoked'` (terminal until manual re-enable), distinct from `'cooldown'`. (h) All transitions emit one `error_log` line with the donation_state format from §15. |
| 10 | Slave-agent self-check loop | `slave-agent/tests/test_donation_self_check_payload.py` passes. Stop. | (a) New coroutine `donation_self_check_loop` runs every 30 s. (b) For each active forward whose combination has `desired_latency_ms > 0`, probes slave→sidecar→endpoint RTT through the existing CF probe path; for `current_mode` extends with a TCP-connect probe via the sidecar's published listener. (c) Probes outside-in TCP reachability via the existing wstunnel/CF return path; for UDP only when `transport_mode in {udp, tcp+udp}`. (d) Heartbeat payload includes `donation_self_check[]` shaped per design §6.4. (e) Loop never blocks the main heartbeat loop more than 5 s. (f) Failures are logged at `WARNING` and become `ok = false` entries, not exceptions. |
| 11 | Sidecar-agent reverse-probe | `sidecar-agent/tests/test_donation_reverse_probe_payload.py` passes. Stop. | (a) Sidecar adds a per-active-forward background loop that, for each slave currently bound, attempts a TCP connect to `slave_public_ip:listen_port` (and UDP echo when applicable). (b) Result attached to heartbeat as `donation_reverse_probe[]` per design §13. (c) Probe interval is `60 s` and never overlaps itself for the same target. (d) Existing CF/MPTCP probe loops are not regressed (gated by `republish` test). (e) Probe failures are logged once per minute, not on every retry. |
| 12 | Client-agent: consume donor candidates | `client-agent/tests/test_donated_slave_leg_consumed_as_helper.py` passes. Existing client-agent self-leg / runtime-preference tests still pass. Stop. | (a) When `candidate_pool` contains a `candidate_kind = 'donated_slave_leg'` entry, it is treated identically to `rented_group_leg` for `select_forward_candidates`, `apply_latency_target_candidate_escalation`, and `filter_mptcp_capable_candidates`. (b) Logs include a `donor=true` flag in the leg-info line. (c) `selected_primary` selection respects existing latency rules; donor entries are not preferred over rented helpers solely because they are donor. (d) `subflow_limit` honors `max_legs_used` and donor entries count against it. (e) No new runtime-family branches introduced. |

### What "stop" means in practice

When a stop condition is hit, the worker MUST in this exact order:

1. Stage and commit only the files declared in the active task.
2. Run `git status` and `git log --oneline -5` and post both outputs verbatim
   in chat.
3. Push the branch via the sandbox push tool.
4. Post a short status block: `Task <n>: <pass|blocked>` + the acceptance
   bullets that hold + any that don't.
5. Wait for explicit user instruction. Do NOT begin the next task
   automatically.

---

## Pre-flight: confirm baseline before any code change

- [ ] **Step 0a: Confirm branch and clean state.**

```bash
git status
git rev-parse --abbrev-ref HEAD
```

Expected: on `master`, working tree clean.

- [ ] **Step 0b: Run existing self-leg / helper / republish regressions.**

```bash
php master/tests/forward_combination_save_update_persistence_regression_test.php
php master/tests/forward_combination_save_auto_republish_regression_test.php
php master/tests/forward_combination_default_max_legs_regression_test.php
php master/tests/admin_forward_combination_self_leg_policy_test.php
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
php master/tests/provisioning_self_leg_wg_contract_regression_test.php
php master/tests/provisioning_max_legs_cap_candidate_pool_regression_test.php
php master/tests/sidecar_heartbeat_republish_gate_regression_test.php
PYTHONPATH=. python -m unittest \
  client-agent.tests.test_self_leg_candidate_policy \
  client-agent.tests.test_runtime_preference_mptcp_bond_fallback \
  client-agent.tests.test_max_legs_cap_runtime \
  client-agent.tests.test_self_leg_mptcp_solo_runtime \
  slave-agent.tests.test_slave_cf_location_group_selection -v
```

Expected: all `ok` / `OK`. Capture any pre-existing failures so they are not
blamed on this plan.

---

### Task 1: Add three migrations and mirror into `schema.sql`

**Why:** All later tasks depend on the new columns and tables.

**Files:**

- Create: `master/migrations/20260518_add_forward_combination_donation_and_latency.sql`
- Create: `master/migrations/20260518_add_forward_combination_slaves.sql`
- Create: `master/migrations/20260518_add_forward_combination_donations.sql`
- Modify: `master/migrations/schema.sql`
- Create: `master/tests/forward_combination_donation_columns_schema_regression_test.php`
- Create: `master/tests/forward_combination_slaves_table_schema_regression_test.php`
- Create: `master/tests/forward_combination_donations_table_schema_regression_test.php`

- [ ] **Step 1: Write the three schema regression tests first (failing).**

Each test reads `master/migrations/schema.sql` plus the matching new
migration file and asserts the exact DDL strings from the design doc §6.

Sample skeleton:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql') ?: '';
$migration = file_exists(__DIR__ . '/../migrations/20260518_add_forward_combination_donation_and_latency.sql')
    ? (file_get_contents(__DIR__ . '/../migrations/20260518_add_forward_combination_donation_and_latency.sql') ?: '')
    : '';

foreach ([
    'ADD COLUMN desired_latency_ms INT NOT NULL DEFAULT 0',
    'ADD COLUMN allow_public_self_leg_donation TINYINT(1) NOT NULL DEFAULT 0',
    'ADD COLUMN donation_qualify_window_seconds INT NOT NULL DEFAULT 60',
    'ADD COLUMN donation_revoke_window_seconds INT NOT NULL DEFAULT 30',
    'ADD COLUMN donation_min_open_ports INT NOT NULL DEFAULT 1',
] as $snippet) {
    if (strpos($schema, $snippet) === false || strpos($migration, $snippet) === false) {
        fail('missing snippet: ' . $snippet);
    }
}

echo "ok\n";
```

Repeat the same shape for the `forward_combination_slaves` and
`forward_combination_donations` tests, asserting `CREATE TABLE`, columns,
indexes, and FK names from §6.2 / §6.3.

- [ ] **Step 2: Confirm all three regressions fail.**

```bash
php master/tests/forward_combination_donation_columns_schema_regression_test.php
php master/tests/forward_combination_slaves_table_schema_regression_test.php
php master/tests/forward_combination_donations_table_schema_regression_test.php
```

Expected: each FAIL with the first missing snippet.

- [ ] **Step 3: Author the three migration files** matching design §6.

- [ ] **Step 4: Mirror the DDL into `master/migrations/schema.sql`.**

Add the three blocks at the end of `schema.sql` in the same order as the
migrations.

- [ ] **Step 5: Re-run all three regressions.**

```bash
php master/tests/forward_combination_donation_columns_schema_regression_test.php
php master/tests/forward_combination_slaves_table_schema_regression_test.php
php master/tests/forward_combination_donations_table_schema_regression_test.php
```

Expected: all `ok`.

- [ ] **Step 6: Commit.**

```bash
git add master/migrations master/tests/forward_combination_donation_columns_schema_regression_test.php master/tests/forward_combination_slaves_table_schema_regression_test.php master/tests/forward_combination_donations_table_schema_regression_test.php
git commit -m "feat(panel): add migrations for client donation + multi-slave picker"
```

---

### Task 2: Persist 5 new combination columns end-to-end

**Why:** The save path must round-trip the new fields, otherwise the donation
worker has nothing to read.

**Files:**

- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/forward_combination_donation_columns_persistence_regression_test.php`

- [ ] **Step 1: Write a failing persistence regression** that asserts both
  the `INSERT` and `UPDATE` branches of `saveForwardCombination()` include
  all 5 column assignments, and that `forwardCombinationById()` returns
  them.
- [ ] **Step 2: Patch `saveForwardCombination()`** to:
  - Pull from `$payload`: `desired_latency_ms` (clamp to `>= 0`),
    `allow_public_self_leg_donation` (normalize to `0|1`),
    `donation_qualify_window_seconds` (clamp `>= 5`),
    `donation_revoke_window_seconds` (clamp `>= 5`),
    `donation_min_open_ports` (clamp `>= 0`).
  - Add to the `$normalized` array.
  - Add to the `INSERT` payload.
  - Add to the `UPDATE` SET clause.
- [ ] **Step 3: Patch `forwardCombinationById()`** (and any helper that
  selects from `forward_combinations.*`) to expose these 5 columns.
- [ ] **Step 4: Re-run persistence regression + 2026-05-17 plan's
  `forward_combination_save_update_persistence_regression_test.php`.**
- [ ] **Step 5: Commit** with message
  `feat(panel): persist donation policy fields on forward combinations`.

---

### Task 3: Admin combination edit form — donation section

**Why:** No way to set the new fields without UI.

**Files:**

- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/tests/admin_forward_combination_self_leg_policy_test.php`

- [ ] **Step 1:** Extend the existing admin self-leg policy regression to
  assert all 5 new form names + the helper-text substring `Donation gate
  is 75% of this value` + the updated "Max Legs Used (cap)" helper text.
- [ ] **Step 2:** Confirm failure.
- [ ] **Step 3:** Add the new form fields under a `<fieldset>` titled
  "Public donation". Hide the fieldset for `client_mode === 'single'`
  combinations.
- [ ] **Step 4:** In the POST handler (existing combination save), pass the
  five new request inputs into `saveForwardCombination`.
- [ ] **Step 5:** Update the existing helper text near the
  `helper_group_cap` input to mention `slave -> sidecar -> endpoint` RTT.
- [ ] **Step 6:** Re-run regression + tenant regression to confirm tenant
  surface unchanged.
- [ ] **Step 7:** Commit
  `feat(panel): add donation policy section to combination edit page`.

---

### Task 4: Multi-slave picker UI + persistence

**Why:** Admin needs to pick individual slaves for a combination, beyond the
group ordering.

**Files:**

- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/admin_combination_multi_slave_picker_regression_test.php`

- [ ] **Step 1:** Write failing regression asserting:
  - Form renders `<select name="combination_slaves[]" multiple>` populated
    from helper-group + primary-group nodes.
  - POST handler diffs `forward_combination_slaves` rows: insert new with
    `selection_kind='admin_pin'`, soft-disable removed (`is_active=0`).
  - Donor rows (`selection_kind='donated_self_leg'`) are untouched.
  - Save calls `republishActiveForwardsForCombination($id)`.
  - "Live donations" read-only table renders with a Revoke button posting
    to `/admin/forward-combinations/{id}/donations/{donationId}/revoke`.
  - New route exists in `Bootstrap.php`.
- [ ] **Step 2:** Add `ProvisioningService::saveCombinationAdminPinnedSlaves(
    int $combinationId, array $nodeIds): void` and call it from the POST
  handler after `saveForwardCombination()`.
- [ ] **Step 3:** Add `ProvisioningService::revokeDonationManually(
    int $combinationId, int $donationId): void`.
- [ ] **Step 4:** Add the route in `Bootstrap.php`:

```php
$this->router->add('POST', '/admin/forward-combinations/{id}/donations/{donationId}/revoke', [$admin, 'revokeDonation']);
```

- [ ] **Step 5:** Re-run regression + 2026-05-17 plan's helper-groups
  regression (must stay green).
- [ ] **Step 6:** Commit
  `feat(panel): admin can pick multiple slaves and manually revoke donors per combination`.

---

### Task 5: `pickFastestSlavesForCombination` + reconcile integration

**Why:** This is the runtime fastest-N selector.

**Files:**

- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/provisioning_pick_fastest_slaves_for_combination_regression_test.php`

- [ ] **Step 1:** Write failing regression asserting:
  - Method exists with the exact signature
    `pickFastestSlavesForCombination(int $combinationId, int $cap): array`.
  - Source contains the literal sentinel comment
    `fastest_slave_per_combination_selector`.
  - Sort order is `last_chain_latency_ms ASC NULLS LAST`, then
    `delivered_ewma DESC`, then `node_id ASC`.
  - `cap = 0` returns the full ranked list.
  - `reconcileForwardEndpoints()` calls the new picker only when
    `forward_combination_id IS NOT NULL`.
  - Two forwards under the same combination yield the same node-id set.
- [ ] **Step 2:** Implement the method:
  - Source query unions:
    - nodes whose `node_groups.group_key` is in
      `forward_combination_helper_groups.node_group_key` for this combo, OR
      equals the combination's `node_group_key`.
    - nodes referenced from `forward_combination_slaves` where
      `is_active = 1`.
  - Filter on `nodes.status IN ('active', 'degraded')`.
  - Project `nodes.id`, `last_chain_latency_ms` (latest from
    `node_heartbeats.payload_json -> '$.forward_chain_remediation[*].best_latency_ms'`
    OR a precomputed cache column — the test should be agnostic via
    fake DB rows), `delivered_ewma` (from `bond_leg_reputation_current`).
  - `ORDER BY` with the specified tie-breakers.
  - `LIMIT $cap` when `$cap > 0`.
- [ ] **Step 3:** Patch `reconcileForwardEndpoints()` to call the new picker
  for combination-bound forwards. Keep the legacy path intact.
- [ ] **Step 4:** Confirm regression passes; re-run helper-groups + non-
  structural-update regressions.
- [ ] **Step 5:** Commit
  `feat(panel): pick fastest 4 slaves per forward combination via slave-to-sidecar-to-endpoint RTT`.

---

### Task 6: Slave heartbeat ingest for `donation_self_check`

**Files:**

- Modify: `master/src/Controllers/NodeController.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/node_heartbeat_donation_self_check_ingest_regression_test.php`

- [ ] **Step 1:** Decide storage shape (Step 6.1 below). Default to
  reusing `node_heartbeats.payload_json` (already stored) and adding a
  small per-node cache table `node_donation_self_check_state` only if a
  later task demands it. Phase one: re-derive the latest values by
  selecting the most recent `node_heartbeats` row per node.
- [ ] **Step 2:** Write failing regression asserting:
  - `recordDonationSelfCheck(int $nodeId, array $payload): void` exists.
  - `NodeController::heartbeat` calls it when
    `donation_self_check` is present.
  - Empty / missing array is a no-op.
  - Existing heartbeat fields stay intact.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Re-run 2026-05-17 + Task 0b regressions.
- [ ] **Step 5:** Commit
  `feat(panel): ingest donation_self_check from slave heartbeat`.

#### Step 6.1 — storage decision rule

If 80% of donation-worker queries can be answered by reading the latest
`node_heartbeats.payload_json -> '$.donation_self_check'`, do not add a new
table. Add the table only if those queries become >50 ms in dev. Document
the choice in the commit body.

---

### Task 7: Sidecar heartbeat ingest for `donation_reverse_probe`

**Files:**

- Modify: `master/src/Controllers/SidecarAgentController.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/sidecar_heartbeat_donation_reverse_probe_ingest_regression_test.php`

- [ ] **Step 1:** Write failing regression asserting:
  - `recordDonationReverseProbe(int $sidecarPointId, array $payload): void`
    exists.
  - `SidecarAgentController::heartbeat` calls it when
    `donation_reverse_probe` is present.
  - Persists the most-recent `(node_id, forward_id, protocol)` tuple to a
    state column.
- [ ] **Step 2:** Storage decision: store latest probes in
  `sidecar_points.agent_config_json -> '$.donation_reverse_probe'` (already
  used for other rolling fingerprints) keyed by
  `node_id + forward_id + protocol`. Cap retention to last 5 results per
  key.
- [ ] **Step 3:** Implement and verify.
- [ ] **Step 4:** Re-run 2026-05-17 + Task 0b regressions.
- [ ] **Step 5:** Commit
  `feat(panel): ingest donation_reverse_probe from sidecar heartbeat`.

---

### Task 8: Donation worker — qualify → active path

**Files:**

- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/scripts/evaluate_donations.php`
- Create: `deploy/systemd/leikwan-donation-worker.service`
- Create: `deploy/systemd/leikwan-donation-worker.timer`
- Create: `master/tests/donation_worker_qualify_to_active_regression_test.php`

- [ ] **Step 1:** Write failing regression asserting:
  - `evaluateDonationStateForCombination(int $combinationId): void` exists.
  - Class constant `DONATION_GATE_RATIO = 0.75` is declared.
  - Activation requires all 6 gates from §8 of the design.
  - On activation: writes `forward_combination_slaves` row with
    `selection_kind = 'donated_self_leg'`, sets `donations.activated_at`,
    calls `republishActiveForwardsForCombination`.
  - Re-running the worker is idempotent (no second republish).
- [ ] **Step 2:** Implement.
- [ ] **Step 3:** Add `master/scripts/evaluate_donations.php` (CLI entry,
  iterates combinations with `allow_public_self_leg_donation = 1`).
- [ ] **Step 4:** Add the systemd unit + timer (60s tick).
- [ ] **Step 5:** Re-run regression.
- [ ] **Step 6:** Commit
  `feat(panel): donation worker qualify-to-active state machine`.

---

### Task 9: Donation worker — revoke paths

**Files:**

- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/donation_worker_revoke_paths_regression_test.php`

- [ ] **Step 1:** Write failing regression asserting all four revoke
  triggers, the cooldown timer, the manual-revoke terminal state, and the
  `error_log` line format `donation_state donor=<id> combo=<id> from=<s> to=<s> reason=<r>`.
- [ ] **Step 2:** Implement.
- [ ] **Step 3:** Re-run Task 8 regression to confirm no regression.
- [ ] **Step 4:** Commit
  `feat(panel): donation worker revoke + cooldown + manual revoke`.

---

### Task 10: Slave-agent self-check loop

**Files:**

- Modify: `slave-agent/slave_agent.py`
- Create: `slave-agent/tests/test_donation_self_check_payload.py`

- [ ] **Step 1:** Write failing pytest asserting the heartbeat payload
  contains a well-formed `donation_self_check[]` after the loop runs once
  with mocked probe results.
- [ ] **Step 2:** Implement the coroutine. Reuse the existing CF probe
  helper (`probe_cf_websocket_path`) for `cf_mode`. For `current_mode`,
  fall back to a TCP connect through the sidecar's published listener
  using the existing `bond_protocols` configuration.
- [ ] **Step 3:** Add the outside-in port reachability probe (TCP, plus UDP
  echo when transport supports UDP). Use the existing wstunnel/CF return
  path; do not add new outbound channels.
- [ ] **Step 4:** Attach `donation_self_check[]` to `send_heartbeat`.
- [ ] **Step 5:** Re-run regression + Task 0b slave-agent regressions.
- [ ] **Step 6:** Commit
  `feat(slave-agent): emit donation_self_check on heartbeat`.

---

### Task 11: Sidecar-agent reverse-port probe

**Files:**

- Modify: `sidecar-agent/sidecar_agent.py`
- Create: `sidecar-agent/tests/test_donation_reverse_probe_payload.py`

- [ ] **Step 1:** Write failing pytest asserting the heartbeat payload
  contains `donation_reverse_probe[]` after one probe pass with mocked
  results.
- [ ] **Step 2:** Implement a 60s loop that, per active forward, attempts
  TCP connect to `slave_public_ip:listen_port`; UDP echo where applicable.
- [ ] **Step 3:** Attach the array to the existing heartbeat payload.
- [ ] **Step 4:** Re-run regression + Task 0b sidecar regressions
  (`test_sidecar_cf_mode_runtime`).
- [ ] **Step 5:** Commit
  `feat(sidecar-agent): emit donation_reverse_probe on heartbeat`.

---

### Task 12: Client-agent — consume `donated_slave_leg`

**Files:**

- Modify: `client-agent/client_agent.py`
- Create: `client-agent/tests/test_donated_slave_leg_consumed_as_helper.py`

- [ ] **Step 1:** Write failing pytest with a `candidate_pool` containing
  one `donated_slave_leg` and two `rented_group_leg` entries; assert:
  - `select_forward_candidates` treats them uniformly (sorted purely by
    measured RTT).
  - `apply_latency_target_candidate_escalation` admits the donor like any
    helper.
  - `filter_mptcp_capable_candidates` includes donor entries when their
    `runtime_capabilities` contain `mptcp`.
  - `subflow_limit` honors `max_legs_used` and counts donor entries.
  - Log lines from the helper code path include the substring
    `donor=true` for donor entries and `donor=false` otherwise.
- [ ] **Step 2:** Implement minimal, additive changes:
  - Add `donated_slave_leg` to the set of recognized helper kinds
    everywhere `rented_group_leg` is checked.
  - Add a small `is_donor_candidate(item)` helper used only for log
    decoration.
- [ ] **Step 3:** Re-run Task 0b client-agent regressions.
- [ ] **Step 4:** Commit
  `feat(client-agent): consume donated_slave_leg as standard helper candidate`.

---

## Final Verification (Task 12 done)

- [ ] **Step 1: Local test sweep.**

```bash
php master/tests/forward_combination_donation_columns_schema_regression_test.php
php master/tests/forward_combination_slaves_table_schema_regression_test.php
php master/tests/forward_combination_donations_table_schema_regression_test.php
php master/tests/forward_combination_donation_columns_persistence_regression_test.php
php master/tests/admin_forward_combination_self_leg_policy_test.php
php master/tests/admin_combination_multi_slave_picker_regression_test.php
php master/tests/provisioning_pick_fastest_slaves_for_combination_regression_test.php
php master/tests/node_heartbeat_donation_self_check_ingest_regression_test.php
php master/tests/sidecar_heartbeat_donation_reverse_probe_ingest_regression_test.php
php master/tests/donation_worker_qualify_to_active_regression_test.php
php master/tests/donation_worker_revoke_paths_regression_test.php
php master/tests/forward_combination_save_update_persistence_regression_test.php
php master/tests/forward_combination_save_auto_republish_regression_test.php
php master/tests/forward_combination_default_max_legs_regression_test.php
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
php master/tests/provisioning_max_legs_cap_candidate_pool_regression_test.php
php master/tests/sidecar_heartbeat_republish_gate_regression_test.php
PYTHONPATH=. python -m unittest \
  slave-agent.tests.test_donation_self_check_payload \
  sidecar-agent.tests.test_donation_reverse_probe_payload \
  client-agent.tests.test_donated_slave_leg_consumed_as_helper \
  client-agent.tests.test_self_leg_candidate_policy \
  client-agent.tests.test_runtime_preference_mptcp_bond_fallback \
  client-agent.tests.test_max_legs_cap_runtime \
  client-agent.tests.test_self_leg_mptcp_solo_runtime \
  slave-agent.tests.test_slave_cf_location_group_selection -v
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Controllers/NodeController.php
php -l master/src/Controllers/SidecarAgentController.php
php -l master/src/Bootstrap.php
python -m py_compile slave-agent/slave_agent.py sidecar-agent/sidecar_agent.py client-agent/client_agent.py
```

Expected: every command exits 0.

- [ ] **Step 2 (manual, NOT for the worker).**

The worker MUST NOT run any of the following against a live environment.
Post these exact commands for the human operator and stop.

```bash
# 1. Apply migrations on staging
mysql -u <user> -p <db> < master/migrations/20260518_add_forward_combination_donation_and_latency.sql
mysql -u <user> -p <db> < master/migrations/20260518_add_forward_combination_slaves.sql
mysql -u <user> -p <db> < master/migrations/20260518_add_forward_combination_donations.sql

# 2. Install donation worker timer
sudo cp deploy/systemd/leikwan-donation-worker.service /etc/systemd/system/
sudo cp deploy/systemd/leikwan-donation-worker.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now leikwan-donation-worker.timer

# 3. Smoke test: enable donation on one combination
#    - Set desired_latency_ms=60 in admin panel.
#    - Tick "Allow public client-as-a-slave donation".
#    - Confirm a qualifying client transitions to 'active' within 60 s.
#    - Confirm forward_combination_slaves gains a donated_self_leg row.
#    - Confirm a non-qualifying client never reaches 'active'.

# 4. Smoke test: multi-slave picker
#    - Pick 6 nodes for one combination.
#    - Set helper_group_cap = 4.
#    - Confirm only the 4 fastest (by last_chain_latency_ms) appear in
#      service_endpoints across every forward of that combination.
```

- [ ] **Step 3:** Worker confirms global acceptance criteria and posts a
  short status block. Stop.

---

## Appendix A — Why these gate values

- `DONATION_GATE_RATIO = 0.75` mirrors common SLO buffer practice (qualify
  comfortably under target, revoke at target). Encoded as a class constant so
  it can be audited and changed without schema migration.
- `donation_qualify_window_seconds = 60` (default) gives ~2 heartbeats of
  headroom for a 30s heartbeat cadence.
- `donation_revoke_window_seconds = 30` keeps revocation responsive.
- `donation_min_open_ports = 1` is the minimum that makes physical sense.
- `helper_group_cap = 4` (default) is unchanged from the 2026-05-17 plan.

## Appendix B — Files NOT touched in phase one

- `LeikwanBridge.Desktop/`
- `leikwan-android/`
- `windows-client/`, `relay-rust/`
- `client-agent/install_forward_runtime.py` (no MPTCP renderer change
  required; donor candidates flow through the same renderer as rented
  helpers)

If a worker finds it must touch any of these, **stop and ask**.

## Appendix C — Task ↔ Acceptance criteria mapping

```
Task 1 -> §6 (data model) + 3 schema regressions
Task 2 -> §6.1 (5 columns) + persistence regression
Task 3 -> §7.1 (donation section UI)
Task 4 -> §7.2 (multi-slave picker UI + persistence)
Task 5 -> §10 (fastest-N selector + per-combination uniformity)
Task 6 -> §6.4 (slave heartbeat ingest)
Task 7 -> §13 (sidecar heartbeat ingest)
Task 8 -> §8 (qualify gates)
Task 9 -> §8 (revoke triggers + cooldown + manual revoke)
Task 10 -> §12 (slave-agent self-check loop)
Task 11 -> §13 (sidecar-agent reverse probe)
Task 12 -> §14 (client-agent consumes donor candidates)
```

## Appendix D — Sentinel comments to add (for future grep)

For long-term searchability, add the following exact comments somewhere in
the named functions (the regressions assert their presence):

| Sentinel | Where |
| --- | --- |
| `donation_qualify_gate_ratio` | `evaluateDonationStateForCombination` |
| `fastest_slave_per_combination_selector` | `pickFastestSlavesForCombination` |
| `donation_self_check_ingest` | `recordDonationSelfCheck` |
| `donation_reverse_probe_ingest` | `recordDonationReverseProbe` |
| `donor_candidate_consumed_as_helper` | client-agent helper-kind check |

These keep grep-driven audits cheap.
