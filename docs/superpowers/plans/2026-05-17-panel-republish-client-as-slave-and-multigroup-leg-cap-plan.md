# Panel Auto-Republish for Client-as-a-Slave + Multi-Slave-Group Leg-Cap Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Date:** 2026-05-17
**Branch:** `snapshot/upload-20260517-220609`
**Status:** Plan only (no code change yet)

**Goal:** Finish the panel side of the client-as-a-slave (private self-leg) feature so that:

1. Saving a forward combination on the admin panel **automatically republishes** every active forward bound to that combination, so client-as-a-slave / helper-group / leg-cap edits reach the client/sidecar agents without any manual republish.
2. Client-as-a-slave (self-leg) can be the **only MPTCP leg** when its measured RTT beats every rented helper for the same forward, instead of being forced to coexist with helpers.
3. Client-as-a-slave is **routed via the selected CF location group's CF IP** (not via the private WireGuard tunnel) whenever the forward combination's `backend_mode = cf_mode` and `client_mode in {bond, cf_bond}` (i.e. CF Bond family). Private WG self-leg stays available only for non-CF combinations.
4. Multiple slave groups may be selected for a single forward combination (already supported via `forward_combination_helper_groups` and `helper_node_group_keys_text`). For **bond, CF Bond and MPTCP runtime preference**, the **default `helper_group_cap` (= max legs used) is 4**, and the admin can override the value on the panel.

**Architecture:** Tighten the existing forward-combination control plane:

- Fix `saveForwardCombination()` UPDATE bug so policy fields persist on edit.
- Add a new `republishActiveForwardsForCombination(int $combinationId)` helper and call it at the end of `saveForwardCombination()`.
- Add `max_legs_used` semantics on top of `helper_group_cap` (the same column, but with a clearer admin label and a default-of-4 seeding rule for bond / CF-bond / MPTCP combinations).
- Make the candidate pool builder respect `max_legs_used` so the client never receives more than that many usable legs.
- Add a "self-leg-only MPTCP allowed" gate to the candidate pool / runtime selection: when `runtime_preference = mptcp_preferred_bond_fallback` AND self-leg's `runtime_capabilities` include `mptcp`, the client may run MPTCP with **just** the self-leg even if helper candidates are present but slower.
- Make CF Bond's self-leg `probe_transport` always be `cf_direct_wss` (not `private_wg`) so client-as-a-slave goes through the CF IP, regardless of whether WG keys are exchanged.

**Tech Stack:** PHP panel (`master/src/Support/ProvisioningService.php`, `master/src/Controllers/AdminController.php`), MySQL schema migration, Python Linux client-agent (`client-agent/client_agent.py`, `client-agent/install_forward_runtime.py`), focused PHP regression tests (`master/tests/*.php`), Python `unittest` regressions (`client-agent/tests/*.py`).

---

## Execution Discipline / 执行纪律

This section is binding for any AI worker (or human) executing this plan. Read it before starting Task 1 and re-read it whenever a task's stop condition is hit.

### Global stop rule (when to stop and report)

The worker MUST stop and report back to the user, instead of continuing, in any of the following situations:

- The current task's "Stop condition" (see per-task table below) is met. Even if later tasks look easy, stop, hand back, wait for "go".
- A regression test that was green at Step 0b becomes red after a change in this plan and the cause cannot be explained by the change in the active task within 15 minutes of investigation.
- A file outside the explicit "Files:" list of the active task needs to be edited to make the active task pass. Stop, propose the new file in chat, wait for approval.
- A schema change beyond Task 9's `MODIFY COLUMN ... DEFAULT 4 COMMENT '...'` is needed. Stop, do not invent ALTERs.
- The branch state on origin has moved (someone else pushed). Stop, do not auto-merge or rebase silently.
- Any test scaffold requires installing a new package, runtime, or system tool. Stop.
- A Python or PHP version error appears that suggests the sandbox is mismatched with the panel target. Stop.

### Anti-divergence rules / 不要自我发散

These are explicit "do NOT" rules. They override any other instinct.

- Do NOT introduce new admin form fields beyond the rename of "Helper Group Cap" → "Max Legs Used (cap)" and the new helper text. No new dropdowns, no new policy knobs.
- Do NOT add new DB columns. Reuse `helper_group_cap`. Task 9 only adjusts default + comment.
- Do NOT refactor surrounding methods. Touch only the lines named in the task. If the diff exceeds ~40 lines for a "fix"-type task or ~120 lines for a "feat"-type task, stop and ask.
- Do NOT broaden CF Bond self-leg suppression to non-CF backends. Gate strictly on `backend_mode === 'cf_mode'`.
- Do NOT change the meaning of `runtime_preference` values, do not add new ones (e.g. no `mptcp_only_self_leg`). Use the existing `mptcp_preferred_bond_fallback` plus the new `self_leg_mptcp_solo_allowed` flag.
- Do NOT remove or rename `helper_group_cap` from payloads or DB. The new label `max_legs_used` is panel-facing only; the wire/DB key stays `helper_group_cap` for backward compatibility.
- Do NOT add tenant-facing surfaces. Tenant visibility tests must keep passing as-is.
- Do NOT touch slave-agent, sidecar-agent, Rust relay, Windows/Android/Desktop clients. If a regression there appears, stop.
- Do NOT bump dependencies, lockfiles, build configs.
- Do NOT change git config, force-push, rebase, or amend. One commit per task; never `--amend` after a hook fails.
- Do NOT run `Republish All Forwards` against any live environment from inside the plan. The auto-republish wired in Task 2 is enough.
- Do NOT add docstrings, type hints, or comments beyond what each task specifies.
- Do NOT write end-to-end smoke tests or integration tests. Only the focused regressions named in each task.

### Global acceptance criteria (must hold at the end of Task 10)

Plan-level pass requires ALL of the following to be true simultaneously on `snapshot/upload-20260517-220609`:

1. `git diff origin/snapshot/upload-20260517-220609..HEAD --stat` lists ONLY files declared under any task's "Files:" block (plus the plan doc itself).
2. Every regression test named in this plan exits 0.
3. Pre-existing tests captured at Step 0b still exit 0 (no regressions).
4. `php -l` on every modified PHP file is `No syntax errors detected`.
5. `python -m py_compile` on every modified Python file exits 0.
6. The plan-level invariants below all hold (verifiable by re-running the named regression):
   - Editing a forward combination via panel persists all six policy fields. (Task 1 regression)
   - Saving a forward combination triggers `republishActiveForwardsForCombination` for every active forward bound to it. (Task 2 regression)
   - For new bond / CF Bond / `mptcp_preferred_bond_fallback` combinations with empty cap input, stored cap is 4. (Task 3 regression)
   - Admin form shows "Max Legs Used (cap)" with helper text "Default 4 for bond, CF Bond and MPTCP". (Task 4 regression)
   - Candidate pool emitted to clients has `len(rented_helpers) <= max_legs_used` whenever cap > 0. (Task 5 regression)
   - Self-leg under `backend_mode = cf_mode` carries `probe_transport = cf_direct_wss` and `self_leg_wg = null`. (Task 6 regression)
   - Self-leg under `runtime_preference = mptcp_preferred_bond_fallback` carries `self_leg_mptcp_solo_allowed = true`. (Task 7 regression)
   - Client-agent rendered MPTCP `subflow_limit` does not exceed `max_legs_used`. (Task 8 regression)
7. No file under `slave-agent/`, `sidecar-agent/`, `relay-rust/`, `LeikwanBridge.Desktop/`, `windows-client/`, `android/` has been modified.
8. Branch is pushed (no local-only commits) and the PR/handoff doc references the plan path.

### Failure handling (when not to silently retry)

- One regression failing twice in a row with the same error → stop and report. Do not "creatively" rewrite the test or the production code.
- A `git push` rejected as non-fast-forward → stop. Do not force, do not auto-rebase. Ask user.
- A migration runner error (`column already exists`, etc.) → stop. Do not switch to `IF NOT EXISTS` workarounds without approval.
- A panel route returning 500 in manual smoke test → stop, attach the relevant log line, do not edit unrelated controllers to "smooth it out".

### Time and step budgets per task (soft guardrails)

| Bucket | Soft cap |
| --- | --- |
| Per-task wall time | 30 min |
| Per-task net diff lines | "fix" tasks ≤ 40, "feat" tasks ≤ 120 |
| Per-task new files | ≤ 2 (one production, one test) unless task names more |
| Failed test re-runs without code change | ≤ 2 |
| Task retries after a stop | 0 (always wait for user) |

If a soft cap is exceeded, stop and report; do not silently push past it.

---

## File Structure

### Control plane / schema

- Modify: `master/migrations/schema.sql`
- Create: `master/migrations/20260517_add_forward_combination_default_max_legs.sql`

### Panel + provisioning

- Modify: `master/src/Support/ProvisioningService.php`
  - Fix `saveForwardCombination()` UPDATE clause to include policy fields.
  - Add `republishActiveForwardsForCombination(int $combinationId)` helper.
  - Call `republishActiveForwardsForCombination($id)` at the end of `saveForwardCombination()`.
  - Default `helper_group_cap` to 4 when missing/0 for `client_mode = bond` or `runtime_preference = mptcp_preferred_bond_fallback` or `backend_mode = cf_mode`.
  - Treat `helper_group_cap` as `max_legs_used`: cap the number of items in the rented helper portion of the candidate pool, not just the number of helper groups.
  - Force `probe_transport = cf_direct_wss` and skip `self_leg_wg` when `backend_mode = cf_mode` for the self-leg candidate.
  - Mark self-leg as "self-leg-only MPTCP eligible" via a new explicit boolean `self_leg_mptcp_solo_allowed` derived from combination policy.
- Modify: `master/src/Controllers/AdminController.php`
  - Rename the visible "Helper Group Cap" label to "Max Legs Used (cap)" and pre-fill `4` for new combinations.
  - Show a small inline hint: "Default 4 for bond, CF Bond and MPTCP".
  - Add a server-side default-seed step in the POST handler for combinations.

### Linux client runtime

- Modify: `client-agent/client_agent.py`
  - Honor `self_leg_mptcp_solo_allowed = true` so the runtime can select MPTCP family with only the self-leg in the active set.
  - Honor `max_legs_used` (= `helper_group_cap`) when sorting candidates and choosing the active leg set; never exceed it for bond / CF-bond / MPTCP families.
  - Stop forcing helper escalation when the self-leg already meets `desired_latency_ms`.
- Modify: `client-agent/install_forward_runtime.py`
  - When the selected primary is the self-leg under CF Bond, render the self-leg as the single direct leg with CF dial host/port/SNI/path; do not render any private WG underlay for that forward.
  - Cap the rendered MPTCP `subflow_limit` at `max_legs_used` (default 4) so kernel limits match panel intent.

### Tests

- Create: `master/tests/forward_combination_save_update_persistence_regression_test.php`
  - Asserts the UPDATE statement persists `runtime_preference`, `allow_private_self_leg`, `self_leg_scope`, `self_leg_role`, `helper_group_cap`, `helper_escalation_policy`.
- Create: `master/tests/forward_combination_save_auto_republish_regression_test.php`
  - Asserts `saveForwardCombination()` calls `republishActiveForwardsForCombination()`.
  - Asserts `republishActiveForwardsForCombination(int $combinationId): void` is defined.
- Create: `master/tests/forward_combination_default_max_legs_regression_test.php`
  - Asserts `helper_group_cap` is normalized to 4 when missing/0 for bond / CF Bond / `mptcp_preferred_bond_fallback`.
- Modify: `master/tests/admin_forward_combination_self_leg_policy_test.php`
  - Add label `Max Legs Used` and the default value `4` snippet.
- Create: `master/tests/provisioning_self_leg_cf_bond_uses_cf_ip_regression_test.php`
  - Asserts CF Bond combinations emit `probe_transport = cf_direct_wss` and do not emit `self_leg_wg` for the self-leg.
- Create: `master/tests/provisioning_self_leg_mptcp_solo_regression_test.php`
  - Asserts the self-leg candidate carries `self_leg_mptcp_solo_allowed = true` when combination is `mptcp_preferred_bond_fallback`.
- Create: `master/tests/provisioning_max_legs_cap_candidate_pool_regression_test.php`
  - Asserts the rented helper portion of the candidate pool is sliced to `helper_group_cap` items.
- Create: `client-agent/tests/test_self_leg_mptcp_solo_runtime.py`
  - Asserts when only self-leg is in the active set and `self_leg_mptcp_solo_allowed = true`, runtime family stays `mptcp`.
- Modify: `client-agent/tests/test_runtime_preference_mptcp_bond_fallback.py`
  - Adds a case where helpers are present but slower and self-leg is alone in the active set.
- Create: `client-agent/tests/test_max_legs_cap_runtime.py`
  - Asserts `subflow_limit` rendered to MPTCP runtime never exceeds `max_legs_used`.

---

## Per-task Stop Conditions and 验收指标 (Acceptance Criteria)

This table is the single source of truth for "when does the worker stop, and what proves the task is done?". It is binding alongside each task's step list.

| # | Task | Stop condition (worker stops here, reports, waits) | 验收指标 / Acceptance criteria (must all hold) |
| --- | --- | --- | --- |
| 0 | Pre-flight | Step 0b finishes; any pre-existing failure is captured in chat. | `git status` clean on `snapshot/upload-20260517-220609`. All Step 0b commands exit 0 OR the failures are listed verbatim in chat before Task 1 begins. |
| 1 | Persist policy fields on UPDATE | The new regression `forward_combination_save_update_persistence_regression_test.php` prints `ok`, the commit lands, and no other test regresses. Stop. | (a) Regression exits 0. (b) `UPDATE forward_combinations SET …` block contains all six column assignments. (c) `php -l` clean. (d) Diff limited to `ProvisioningService.php` + the new test. (e) Commit message exactly `fix(panel): persist self-leg/helper policy on forward combination edit`. |
| 2 | Auto-republish on save | `forward_combination_save_auto_republish_regression_test.php` prints `ok` and Task 1's regression still passes. Stop. | (a) Method `public function republishActiveForwardsForCombination(int $combinationId): void` exists. (b) `saveForwardCombination()` calls it once at the end. (c) The query filters on `f.forward_combination_id`, `f.enabled = 1`, `s.status = "active"`. (d) Republish errors are caught with `try/catch` and logged via `error_log`, save still returns the combination row. (e) Existing republish wrapper regression `provisioning_republish_wrappers_regression_test.php` still passes. |
| 3 | Default cap = 4 seeding | `forward_combination_default_max_legs_regression_test.php` prints `ok`. Stop. | (a) `public const DEFAULT_MAX_LEGS_USED = 4;` exists. (b) `defaultMaxLegsUsedForCombination()` returns 4 for `client_mode='bond'`, for `runtime_preference='mptcp_preferred_bond_fallback'`, for `backend_mode='cf_mode'`. (c) Returns 0 for everything else. (d) Seeding only fires when `helper_group_cap <= 0`. (e) Existing combos with explicit non-zero cap are unchanged. |
| 4 | Admin form rename + default 4 | `admin_forward_combination_self_leg_policy_test.php` prints `ok`, including the new `Max Legs Used` and helper-text snippets. Stop. | (a) Form input still uses `name="helper_group_cap"`. (b) Visible label is `Max Legs Used (cap)`. (c) Helper text contains exact substring `Default 4 for bond, CF Bond and MPTCP`. (d) New combos render `value="4"` when `editing` is empty; edits keep DB value. (e) No other admin field renamed or removed. |
| 5 | Cap rented pool | `provisioning_max_legs_cap_candidate_pool_regression_test.php` prints `ok`. Stop. | (a) `applyMaxLegsCapToPool(array $pool, int $cap): array` exists. (b) Self-leg items are preserved before the slice; rented items are sliced via `array_slice($pool, 0, $cap)`. (c) `cap <= 0` returns the pool unchanged. (d) Each emitted forward config has `'max_legs_used' => $maxLegs` mirrored from `helper_group_cap`. (e) Existing `provisioning_self_leg_payload_regression_test.php` and `provisioning_forward_combination_helper_groups_regression_test.php` still pass. |
| 6 | CF IP for self-leg under CF Bond | `provisioning_self_leg_cf_bond_uses_cf_ip_regression_test.php` prints `ok` AND `provisioning_self_leg_wg_contract_regression_test.php` still passes. Stop. | (a) Source contains the literal sentinel comment `self_leg_cf_only_under_cf_bond`. (b) When `backend_mode === 'cf_mode'`: `probe_transport = 'cf_direct_wss'`, `self_leg_wg = null`, `runtime_capabilities = ['bond', 'mptcp']` (no `wireguard`). (c) When `backend_mode !== 'cf_mode'` and WG profile is available: `probe_transport = 'private_wg'`, `self_leg_wg` is the profile, `runtime_capabilities` includes `wireguard`. (d) No change to non-self-leg candidates. |
| 7 | Self-leg-only MPTCP allowed | Both `provisioning_self_leg_mptcp_solo_regression_test.php` and `client-agent/tests/test_self_leg_mptcp_solo_runtime.py` pass. Stop. | (a) Self-leg payload carries `self_leg_mptcp_solo_allowed = true` iff `runtime_preference === 'mptcp_preferred_bond_fallback'`, `false` otherwise. (b) `client_agent.choose_runtime_family()` returns `"mptcp"` when primary is self-leg with `mptcp` capability and `mptcp_supported=True`. (c) `install_forward_runtime` renders a runnable MPTCP config with `subflow_limit = 1` when only self-leg is in the active set. (d) `test_runtime_preference_mptcp_bond_fallback` and `test_highloss_self_leg_runtime_regression` still green. |
| 8 | Honor `max_legs_used` in client | `client-agent/tests/test_max_legs_cap_runtime.py` passes. Stop. | (a) Renderer slices `endpoints` to `max_legs_used` and clamps `subflow_limit` to `max_legs_used` when cap > 0. (b) `client_agent.select_forward_candidates()` slices `ready` to `max_legs_used` after sorting by latency. (c) When `max_legs_used <= 0`, behavior is unchanged from baseline. (d) Existing `test_mptcp_bond_regressions.py` still green. |
| 9 | Schema comment + default | Migration applies cleanly OR is skipped per the team's choice. Stop either way. | (a) New migration file ends with the exact `MODIFY COLUMN helper_group_cap INT NOT NULL DEFAULT 4 COMMENT '...'`. (b) `master/migrations/schema.sql` matches. (c) No other column touched. (d) If the team chooses to skip Task 9, the worker MUST stop before creating the migration file and ask for confirmation; do not auto-skip. |
| 10 | End-to-end verification | All commands in Step 1 of Task 10 exit 0. Stop. Manual Steps 2–4 are reported to user with raw outputs and NOT performed against production by the worker. | (a) Local test sweep is fully green. (b) Worker posts the raw outputs of Step 1 in chat. (c) Worker DOES NOT run Step 2/3/4 against any live environment; instead it posts the exact commands for the human operator to run. (d) Worker confirms the global acceptance criteria from "Execution Discipline" all hold. |

### What "stop" means in practice

When a stop condition is hit, the worker MUST in this exact order:

1. Stage and commit only the files declared in the active task.
2. Run `git status` and `git log --oneline -5` and post both outputs verbatim in chat.
3. Push the branch via the sandbox push tool.
4. Post a short status block: `Task <n>: <pass|blocked>` + the acceptance bullets that hold + any that don't.
5. Wait for explicit user instruction. Do NOT begin the next task automatically.

---

## Pre-flight: confirm baseline before any code change

- [ ] **Step 0a: Confirm branch and clean state.**

```bash
git status
git rev-parse --abbrev-ref HEAD
```

Expected: on `snapshot/upload-20260517-220609`, working tree clean.

- [ ] **Step 0b: Run the existing self-leg/helper regressions to capture current green state.**

```bash
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
php master/tests/provisioning_self_leg_wg_contract_regression_test.php
php master/tests/admin_forward_combination_self_leg_policy_test.php
php master/tests/provisioning_multi_slave_group_candidate_pool_regression_test.php
PYTHONPATH=. python -m unittest \
  client-agent.tests.test_self_leg_candidate_policy \
  client-agent.tests.test_runtime_preference_mptcp_bond_fallback \
  client-agent.tests.test_highloss_self_leg_runtime_regression \
  client-agent.tests.test_self_leg_best_ip_override -v
```

Expected: all `ok` / `OK`. Capture any pre-existing failures so they are not blamed on this plan.

---

### Task 1: Fix the `saveForwardCombination()` UPDATE bug so policy fields actually persist

**Why:** Today the UPDATE branch in `saveForwardCombination()` writes `$normalized + ['id' => $id]` parameters but the `UPDATE forward_combinations SET ...` clause is missing the new policy column names, so editing an existing combination silently drops `runtime_preference`, `allow_private_self_leg`, `self_leg_scope`, `self_leg_role`, `helper_group_cap`, `helper_escalation_policy`. This is the root reason "panel changes don't take effect".

**Files:**

- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/forward_combination_save_update_persistence_regression_test.php`

- [ ] **Step 1: Write the failing regression for SET-clause coverage.**

Create `master/tests/forward_combination_save_update_persistence_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($source === false) {
    fail('failed to read ProvisioningService.php');
}

// Locate the UPDATE forward_combinations block.
if (!preg_match('/UPDATE forward_combinations\s+SET(.*?)WHERE id = :id/s', $source, $m)) {
    fail('UPDATE forward_combinations block not found');
}
$updateBlock = $m[1];

foreach ([
    'runtime_preference = :runtime_preference',
    'allow_private_self_leg = :allow_private_self_leg',
    'self_leg_scope = :self_leg_scope',
    'self_leg_role = :self_leg_role',
    'helper_group_cap = :helper_group_cap',
    'helper_escalation_policy = :helper_escalation_policy',
] as $snippet) {
    if (strpos($updateBlock, $snippet) === false) {
        fail('UPDATE statement missing column: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the regression to confirm failure.**

```bash
php master/tests/forward_combination_save_update_persistence_regression_test.php
```

Expected: FAIL with "UPDATE statement missing column: runtime_preference" (or the first missing one).

- [ ] **Step 3: Patch the UPDATE clause.**

In `master/src/Support/ProvisioningService.php`, inside `saveForwardCombination()`, replace the existing `UPDATE forward_combinations SET ... WHERE id = :id` block with one that also sets the policy columns:

```php
$this->db->execute(
    'UPDATE forward_combinations
     SET name = :name,
         tenant_label = :tenant_label,
         tenant_description = :tenant_description,
         tenant_badge = :tenant_badge,
         node_group_key = :node_group_key,
         backend_mode = :backend_mode,
         sidecar_point_id = :sidecar_point_id,
         cf_location_group_id = :cf_location_group_id,
         client_mode = :client_mode,
         runtime_preference = :runtime_preference,
         allow_private_self_leg = :allow_private_self_leg,
         self_leg_scope = :self_leg_scope,
         self_leg_role = :self_leg_role,
         helper_group_cap = :helper_group_cap,
         helper_escalation_policy = :helper_escalation_policy,
         bond_ingress_mode = :bond_ingress_mode,
         is_active = :is_active,
         sort_order = :sort_order,
         notes = :notes,
         updated_at = :updated_at
     WHERE id = :id',
    $normalized + ['id' => $id]
);
```

- [ ] **Step 4: Re-run the regression.**

```bash
php master/tests/forward_combination_save_update_persistence_regression_test.php
```

Expected: `ok`.

- [ ] **Step 5: Commit.**

```bash
git add master/src/Support/ProvisioningService.php master/tests/forward_combination_save_update_persistence_regression_test.php
git commit -m "fix(panel): persist self-leg/helper policy on forward combination edit"
```

---

### Task 2: Add `republishActiveForwardsForCombination()` and call it after every save

**Why:** `saveForwardCombination()` currently does not republish any forward, so `client_mode`, `helper_group_cap`, helper-group keys etc. only reach clients after a separate manual republish.

**Files:**

- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/forward_combination_save_auto_republish_regression_test.php`

- [ ] **Step 1: Write the failing regression.**

Create `master/tests/forward_combination_save_auto_republish_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($source === false) {
    fail('failed to read ProvisioningService.php');
}

foreach ([
    'public function republishActiveForwardsForCombination(int $combinationId): void',
    '$this->republishActiveForwardsForCombination($id);',
    'WHERE forward_combination_id = :forward_combination_id',
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Confirm failure.**

```bash
php master/tests/forward_combination_save_auto_republish_regression_test.php
```

Expected: FAIL with the first missing snippet.

- [ ] **Step 3: Add the helper.**

In `master/src/Support/ProvisioningService.php`, near the other `republishActiveForwardsFor*` methods (around line 4373), add:

```php
public function republishActiveForwardsForCombination(int $combinationId): void
{
    if ($combinationId <= 0) {
        return;
    }
    $forwards = $this->db->fetchAll(
        'SELECT f.id
         FROM forwards f
         INNER JOIN services s ON s.id = f.service_id
         WHERE f.forward_combination_id = :forward_combination_id
           AND f.enabled = 1
           AND s.status = "active"',
        ['forward_combination_id' => $combinationId]
    );
    foreach ($forwards as $row) {
        $forwardId = (int) ($row['id'] ?? 0);
        if ($forwardId <= 0) {
            continue;
        }
        try {
            $this->republishForwardEndpoints($forwardId, 'forward_combination_save');
        } catch (\Throwable $e) {
            // Republish errors must not block the save.
            error_log('republishActiveForwardsForCombination failed for forward ' . $forwardId . ': ' . $e->getMessage());
        }
    }
}
```

> If `republishForwardEndpoints()` does not exist with that exact name, reuse the existing per-forward republish entrypoint already used by `republishActiveForwardsForService()`.

- [ ] **Step 4: Wire the call into `saveForwardCombination()`.**

At the very end of `saveForwardCombination()`, just before the `return $this->forwardCombinationById($id) ?? [];` line, insert:

```php
$this->republishActiveForwardsForCombination($id);
```

- [ ] **Step 5: Re-run.**

```bash
php master/tests/forward_combination_save_auto_republish_regression_test.php
php master/tests/forward_combination_save_update_persistence_regression_test.php
```

Expected: both `ok`.

- [ ] **Step 6: Commit.**

```bash
git add master/src/Support/ProvisioningService.php master/tests/forward_combination_save_auto_republish_regression_test.php
git commit -m "feat(panel): auto-republish active forwards on forward combination save"
```

---

### Task 3: Default `helper_group_cap` to 4 (= max legs used) for bond / CF Bond / MPTCP

**Why:** The user requirement is: "By default, for bond mode (including CF Bond) and MPTCP, the max legs used number should be 4. Admin can adjust the actual leg number on panel." Today `helper_group_cap` defaults to `0` (no cap), so multi-group leg admission is effectively unbounded.

**Files:**

- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/forward_combination_default_max_legs_regression_test.php`

- [ ] **Step 1: Write the failing regression.**

Create `master/tests/forward_combination_default_max_legs_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($source === false) {
    fail('failed to read ProvisioningService.php');
}

foreach ([
    'private function defaultMaxLegsUsedForCombination(',
    "['bond', 'mptcp_preferred_bond_fallback']",
    "if (\$helperGroupCap <= 0)",
    'DEFAULT_MAX_LEGS_USED = 4',
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing default-max-legs snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Confirm failure.**

```bash
php master/tests/forward_combination_default_max_legs_regression_test.php
```

Expected: FAIL.

- [ ] **Step 3: Add the helper and seed default.**

Near the other constants/helpers in `ProvisioningService.php`, add:

```php
public const DEFAULT_MAX_LEGS_USED = 4;

private function defaultMaxLegsUsedForCombination(string $clientMode, string $runtimePreference, string $backendMode): int
{
    $hybrid = ['bond', 'mptcp_preferred_bond_fallback'];
    if (in_array($clientMode, ['bond'], true)) {
        return self::DEFAULT_MAX_LEGS_USED;
    }
    if (in_array($runtimePreference, $hybrid, true)) {
        return self::DEFAULT_MAX_LEGS_USED;
    }
    if ($backendMode === 'cf_mode') {
        return self::DEFAULT_MAX_LEGS_USED;
    }
    return 0;
}
```

In `saveForwardCombination()` after the existing line:

```php
$helperGroupCap = max(0, (int) ($payload['helper_group_cap'] ?? 0));
```

add:

```php
if ($helperGroupCap <= 0) {
    $helperGroupCap = $this->defaultMaxLegsUsedForCombination($clientMode, $runtimePreference, $backendMode);
}
```

- [ ] **Step 4: Re-run.**

```bash
php master/tests/forward_combination_default_max_legs_regression_test.php
```

Expected: `ok`.

- [ ] **Step 5: Commit.**

```bash
git add master/src/Support/ProvisioningService.php master/tests/forward_combination_default_max_legs_regression_test.php
git commit -m "feat(panel): default max legs used to 4 for bond/CF-bond/MPTCP combinations"
```

---

### Task 4: Surface "Max Legs Used" on the admin form with sensible default

**Files:**

- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/tests/admin_forward_combination_self_leg_policy_test.php`

- [ ] **Step 1: Update the regression to require the new label and default value.**

Add to the snippets array in `master/tests/admin_forward_combination_self_leg_policy_test.php`:

```php
foreach ([
    'name="runtime_preference"',
    'name="allow_private_self_leg"',
    'name="self_leg_scope"',
    'name="self_leg_role"',
    'name="helper_group_cap"',
    'Max Legs Used',
    'Default 4 for bond, CF Bond and MPTCP',
    'name="helper_escalation_policy"',
    'helper_node_group_keys_text',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing admin snippet: ' . $snippet);
    }
}
```

- [ ] **Step 2: Confirm failure.**

```bash
php master/tests/admin_forward_combination_self_leg_policy_test.php
```

Expected: FAIL on `Max Legs Used`.

- [ ] **Step 3: Patch the admin form.**

In `master/src/Controllers/AdminController.php`, replace the existing line:

```php
<div class="field"><label>Helper Group Cap</label><input type="number" name="helper_group_cap" min="0" value="' . $this->escape((string) ($editing['helper_group_cap'] ?? 0)) . '"></div>
```

with:

```php
<div class="field">
  <label>Max Legs Used (cap)</label>
  <input type="number" name="helper_group_cap" min="0" value="' . $this->escape((string) ($editing['helper_group_cap'] ?? 4)) . '">
  <p class="muted">Default 4 for bond, CF Bond and MPTCP. 0 = unlimited.</p>
</div>
```

Note the default-value change from `0` to `4` for new combinations (the saved DB row still wins for edits).

- [ ] **Step 4: Re-run.**

```bash
php master/tests/admin_forward_combination_self_leg_policy_test.php
```

Expected: `ok`.

- [ ] **Step 5: Commit.**

```bash
git add master/src/Controllers/AdminController.php master/tests/admin_forward_combination_self_leg_policy_test.php
git commit -m "feat(admin): rename helper cap to max legs used and default to 4"
```

---

### Task 5: Cap the candidate pool's rented helper count by `max_legs_used`

**Why:** The user wants the leg-cap to actually constrain how many helpers are admissible. Today `buildForwardCandidatePool()` emits **every** rented helper endpoint without slicing.

**Files:**

- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/provisioning_max_legs_cap_candidate_pool_regression_test.php`

- [ ] **Step 1: Write the failing regression.**

Create `master/tests/provisioning_max_legs_cap_candidate_pool_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($source === false) {
    fail('failed to read ProvisioningService.php');
}

foreach ([
    'private function applyMaxLegsCapToPool(',
    'array_slice($pool, 0, $cap)',
    "'max_legs_used' => \$maxLegs",
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing cap snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Add the helper and use it in `buildForwardCandidatePool()`.**

In `master/src/Support/ProvisioningService.php`, add:

```php
private function applyMaxLegsCapToPool(array $pool, int $cap): array
{
    if ($cap <= 0) {
        return $pool;
    }
    $selfLegs = [];
    $rented = [];
    foreach ($pool as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (($item['candidate_kind'] ?? '') === 'self_leg') {
            $selfLegs[] = $item;
        } else {
            $rented[] = $item;
        }
    }
    $rented = array_slice($rented, 0, $cap);
    return array_merge($selfLegs, $rented);
}
```

In `assignedForwardConfigsForClient()` near the existing line:

```php
$config['helper_group_cap'] = max(0, (int) ($config['forward_combination_helper_group_cap'] ?? 0));
```

extend to:

```php
$maxLegs = max(0, (int) ($config['forward_combination_helper_group_cap'] ?? 0));
$config['helper_group_cap'] = $maxLegs;
$config['max_legs_used'] = $maxLegs;
$config['candidate_pool'] = $this->applyMaxLegsCapToPool(
    $this->buildForwardCandidatePool($config, $client, $deviceSession),
    $maxLegs
);
```

- [ ] **Step 3: Re-run.**

```bash
php master/tests/provisioning_max_legs_cap_candidate_pool_regression_test.php
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
```

Expected: all `ok`.

- [ ] **Step 4: Commit.**

```bash
git add master/src/Support/ProvisioningService.php master/tests/provisioning_max_legs_cap_candidate_pool_regression_test.php
git commit -m "feat(panel): cap rented candidate pool by max legs used"
```

---

### Task 6: Force CF IP routing for self-leg under CF Bond mode

**Why:** Requirement 2: "Client-as-a-slave should also be routed via CF IP if CF bond mode is selected for this forward combination." Current code prefers `private_wg` whenever WG keys exist, even for `cf_mode` combinations.

**Files:**

- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/provisioning_self_leg_cf_bond_uses_cf_ip_regression_test.php`

- [ ] **Step 1: Write the failing regression.**

Create `master/tests/provisioning_self_leg_cf_bond_uses_cf_ip_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($source === false) {
    fail('failed to read ProvisioningService.php');
}

foreach ([
    "if ((string) (\$config['backend_mode'] ?? '') === 'cf_mode')",
    "'probe_transport' => 'cf_direct_wss'",
    "'self_leg_wg' => null",
    'self_leg_cf_only_under_cf_bond',
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing CF Bond self-leg snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Confirm failure.**

```bash
php master/tests/provisioning_self_leg_cf_bond_uses_cf_ip_regression_test.php
```

Expected: FAIL.

- [ ] **Step 3: Patch `buildSelfLegCandidate()`.**

Around the existing `buildSelfLegCandidate()` (`master/src/Support/ProvisioningService.php`, line ~2779), wrap the `runtime_capabilities` / `probe_transport` / `self_leg_wg` decisions:

```php
// Regression sentinel: self_leg_cf_only_under_cf_bond
$cfBond = ((string) ($config['backend_mode'] ?? '') === 'cf_mode');
$wgProfile = $cfBond ? null : $this->buildSelfLegWireGuardProfile($config, $client, $deviceSession);
if ($cfBond && $wgProfile !== null) {
    error_log('self-leg under cf_mode forced to CF IP path; ignoring WG profile');
}
$runtimeCaps = $wgProfile !== null ? ['bond', 'mptcp', 'wireguard'] : ['bond', 'mptcp'];
$probeTransport = $wgProfile !== null ? 'private_wg' : 'cf_direct_wss';
```

Then update the returned array to use `$runtimeCaps`, `$probeTransport`, and `'self_leg_wg' => $wgProfile`.

- [ ] **Step 4: Re-run.**

```bash
php master/tests/provisioning_self_leg_cf_bond_uses_cf_ip_regression_test.php
php master/tests/provisioning_self_leg_wg_contract_regression_test.php  # must still pass for non-cf_mode
```

Expected: both `ok`.

- [ ] **Step 5: Commit.**

```bash
git add master/src/Support/ProvisioningService.php master/tests/provisioning_self_leg_cf_bond_uses_cf_ip_regression_test.php
git commit -m "feat(panel): route self-leg via CF IP under CF Bond mode (no WG)"
```

---

### Task 7: Self-leg-only MPTCP allowed under `mptcp_preferred_bond_fallback`

**Why:** Requirement 1: "Client-as-a-slave can be the only MPTCP leg if it's better than other provided slaves."

**Files:**

- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/install_forward_runtime.py`
- Create: `master/tests/provisioning_self_leg_mptcp_solo_regression_test.php`
- Create: `client-agent/tests/test_self_leg_mptcp_solo_runtime.py`

- [ ] **Step 1: Write the failing PHP regression.**

Create `master/tests/provisioning_self_leg_mptcp_solo_regression_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($source === false) {
    fail('failed to read ProvisioningService.php');
}

foreach ([
    "'self_leg_mptcp_solo_allowed' =>",
    "(string) (\$config['runtime_preference'] ?? '') === 'mptcp_preferred_bond_fallback'",
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing solo-mptcp snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Patch `buildSelfLegCandidate()` to publish the flag.**

Inside `buildSelfLegCandidate()`, add to the returned array:

```php
'self_leg_mptcp_solo_allowed' => (string) ($config['runtime_preference'] ?? '') === 'mptcp_preferred_bond_fallback',
```

- [ ] **Step 3: Write the failing client-agent regression.**

Create `client-agent/tests/test_self_leg_mptcp_solo_runtime.py`:

```python
import importlib.util
import pathlib
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, REPO_ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent = load_module("client_agent_solo_under_test", "client-agent/client_agent.py")


class SelfLegMptcpSoloTests(unittest.TestCase):
    def test_self_leg_alone_runs_mptcp_when_solo_allowed(self) -> None:
        agent = client_agent.ClientAgent.__new__(client_agent.ClientAgent)
        family = client_agent.ClientAgent.choose_runtime_family(
            agent,
            {
                "mode": "mptcp",
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "self_leg_mptcp_solo_allowed": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                        "probe_transport": "cf_direct_wss",
                    },
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("mptcp", family)


if __name__ == "__main__":
    unittest.main()
```

If `choose_runtime_family()` does not already return `mptcp` for this case, patch the self-leg branch:

```python
if isinstance(primary, dict) and str(primary.get("candidate_kind", "") or "") == "self_leg":
    primary_caps = [str(value).strip().lower() for value in primary.get("runtime_capabilities", [])]
    if mptcp_supported and "mptcp" in primary_caps:
        return "mptcp"
    if bool(primary.get("self_leg_mptcp_solo_allowed", False)) and mptcp_supported:
        return "mptcp"
    return "bond"
```

- [ ] **Step 4: Patch `install_forward_runtime.py` so MPTCP runtime renders without rented helpers when only self-leg is active.**

In `client-agent/install_forward_runtime.py`, locate where `mptcp_endpoints` is built and where the renderer asserts `len(mptcp_endpoints) >= 1`. Allow the rendered runtime to start with just the self-leg direct underlay when `self_leg_mptcp_solo_allowed` is true and the active set has only self-leg. Adjust `subflow_limit` to `1` for this case.

- [ ] **Step 5: Re-run all related tests.**

```bash
php master/tests/provisioning_self_leg_mptcp_solo_regression_test.php
PYTHONPATH=. python -m unittest \
  client-agent.tests.test_self_leg_mptcp_solo_runtime \
  client-agent.tests.test_runtime_preference_mptcp_bond_fallback \
  client-agent.tests.test_highloss_self_leg_runtime_regression -v
```

Expected: all green.

- [ ] **Step 6: Commit.**

```bash
git add master/src/Support/ProvisioningService.php client-agent/client_agent.py client-agent/install_forward_runtime.py master/tests/provisioning_self_leg_mptcp_solo_regression_test.php client-agent/tests/test_self_leg_mptcp_solo_runtime.py
git commit -m "feat: allow self-leg as the only MPTCP leg when solo allowed"
```

---

### Task 8: Honor `max_legs_used` in client runtime (`subflow_limit` cap)

**Files:**

- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/client_agent.py`
- Create: `client-agent/tests/test_max_legs_cap_runtime.py`

- [ ] **Step 1: Write the failing test.**

Create `client-agent/tests/test_max_legs_cap_runtime.py`:

```python
import importlib.util
import pathlib
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    spec = importlib.util.spec_from_file_location(name, REPO_ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


install_runtime = load_module("install_forward_runtime_cap_under_test", "client-agent/install_forward_runtime.py")


class MaxLegsCapTests(unittest.TestCase):
    def test_subflow_limit_capped_by_max_legs_used(self) -> None:
        forward = {
            "forward_id": 47,
            "max_legs_used": 4,
            "mptcp": {
                "subflow_limit": 8,
                "mptcp_endpoints": [
                    {"endpoint_id": i, "address": f"10.0.0.{i}", "dev": f"lw-mptcp-47-{i}", "subflow": True}
                    for i in range(8)
                ],
            },
        }
        rendered = install_runtime.render_mptcp_runtime_config(forward)
        self.assertLessEqual(int(rendered["subflow_limit"]), 4)
        self.assertLessEqual(len(rendered["mptcp_endpoints"]), 4)


if __name__ == "__main__":
    unittest.main()
```

> If `render_mptcp_runtime_config()` does not exist with that exact name, point the test at the actual public renderer used by the tests in `test_mptcp_bond_regressions.py`.

Run and confirm failure:

```bash
PYTHONPATH=. python -m unittest client-agent.tests.test_max_legs_cap_runtime -v
```

- [ ] **Step 2: Patch the renderer.**

In `client-agent/install_forward_runtime.py`, before computing `subflow_limit`, derive the cap:

```python
max_legs = int_or_default(forward.get("max_legs_used"), 0)
if max_legs > 0:
    endpoints = endpoints[:max_legs]
    if int(mptcp.get("subflow_limit", 0) or 0) > max_legs:
        mptcp["subflow_limit"] = max_legs
```

Apply the cap to `endpoints` and `mptcp["subflow_limit"]` before they are written to the rendered config.

- [ ] **Step 3: Mirror the cap in `client_agent.py` candidate selection.**

In `select_forward_candidates()` (around line ~2210), after sorting `ready` by latency, slice it:

```python
max_legs = int(forward.get("max_legs_used", forward.get("helper_group_cap", 0)) or 0)
if max_legs > 0:
    ready = ready[:max_legs]
```

- [ ] **Step 4: Re-run.**

```bash
PYTHONPATH=. python -m unittest \
  client-agent.tests.test_max_legs_cap_runtime \
  client-agent.tests.test_runtime_preference_mptcp_bond_fallback \
  client-agent.tests.test_self_leg_candidate_policy -v
```

Expected: all green.

- [ ] **Step 5: Commit.**

```bash
git add client-agent/install_forward_runtime.py client-agent/client_agent.py client-agent/tests/test_max_legs_cap_runtime.py
git commit -m "feat(client): honor max_legs_used cap for MPTCP/bond runtime"
```

---

### Task 9: Optional schema migration for explicit `max_legs_used` documentation

> The DB column is already `helper_group_cap`; we are reusing it semantically. This task only adds a comment migration for clarity. Skip if the team prefers not to touch schema.

**Files:**

- Create: `master/migrations/20260517_add_forward_combination_default_max_legs.sql`
- Modify: `master/migrations/schema.sql`

- [ ] **Step 1: Add column comment migration.**

Create `master/migrations/20260517_add_forward_combination_default_max_legs.sql`:

```sql
-- Reuse helper_group_cap as the panel-facing "Max Legs Used (cap)" knob.
-- The column already exists; this migration only adds a comment for operators.
ALTER TABLE forward_combinations
    MODIFY COLUMN helper_group_cap INT NOT NULL DEFAULT 4
    COMMENT 'Max legs used for bond/CF Bond/MPTCP. 0 = unlimited. Default 4.';
```

> Note: changing the default from `0` to `4` only affects rows inserted via raw SQL without explicit values. PHP-side seeding still applies via Task 3.

- [ ] **Step 2: Update `master/migrations/schema.sql`** to match the new default and comment.

- [ ] **Step 3: Commit.**

```bash
git add master/migrations/20260517_add_forward_combination_default_max_legs.sql master/migrations/schema.sql
git commit -m "chore(schema): document helper_group_cap as max legs used (default 4)"
```

---

### Task 10: End-to-end verification before live rollout

**Files:** verify only (no code changes)

- [ ] **Step 1: Local test sweep.**

```bash
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/AdminController.php
php master/tests/forward_combination_save_update_persistence_regression_test.php
php master/tests/forward_combination_save_auto_republish_regression_test.php
php master/tests/forward_combination_default_max_legs_regression_test.php
php master/tests/admin_forward_combination_self_leg_policy_test.php
php master/tests/provisioning_max_legs_cap_candidate_pool_regression_test.php
php master/tests/provisioning_self_leg_cf_bond_uses_cf_ip_regression_test.php
php master/tests/provisioning_self_leg_mptcp_solo_regression_test.php
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_self_leg_wg_contract_regression_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
php master/tests/provisioning_multi_slave_group_candidate_pool_regression_test.php

PYTHONPATH=. python -m unittest \
  client-agent.tests.test_self_leg_mptcp_solo_runtime \
  client-agent.tests.test_max_legs_cap_runtime \
  client-agent.tests.test_runtime_preference_mptcp_bond_fallback \
  client-agent.tests.test_self_leg_candidate_policy \
  client-agent.tests.test_self_leg_best_ip_override \
  client-agent.tests.test_highloss_self_leg_runtime_regression \
  client-agent.tests.test_mptcp_bond_regressions -v
```

Expected: every assertion passes.

- [ ] **Step 2: Manual panel smoke test (after deploy).**

1. Open the admin panel, edit an existing CF Bond combination.
2. Set `Allow Private Self Leg = Yes`, `Self Leg Scope = device_session`, `Self Leg Role = latency_anchor`, `Runtime Preference = mptcp_preferred_bond_fallback`, `Max Legs Used (cap) = 4`, helper node group keys `shanghai-a`, `shanghai-b`.
3. Save.
4. Tail panel logs: every active forward bound to that combination should emit a `republishForwardEndpoints` line within ~1 s.

- [ ] **Step 3: Live client pull verification.**

On the real Linux client, after the auto-republish landed:

```bash
sudo journalctl -u leikwan-client-agent.service --since '-2 min' -o short-iso | tail -n 80
sudo cat /var/lib/leikwan/last-pull-forward-47.json 2>/dev/null | jq '.candidate_pool, .runtime_preference, .max_legs_used'
sudo cat /etc/leikwan/mptcp-forward-47.json | jq '.subflow_limit, .direct_legs, .bond_mode'
```

Expected:

- `candidate_pool` carries the self-leg with `probe_transport = "cf_direct_wss"` (CF Bond) and `self_leg_mptcp_solo_allowed = true`.
- `max_legs_used = 4`.
- `subflow_limit <= 4`.
- If self-leg was the lowest-RTT candidate at probe time, `direct_legs` is non-empty and `bond_mode = "tcp"`.

- [ ] **Step 4: iperf3 sanity check (existing convention).**

```bash
iperf3 -c 127.0.0.1 -p 11035 -R -b 300M -t 12 --connect-timeout 3000
```

Expected: throughput materially higher than the previous `~8.66 Mbit/s` baseline recorded in the 2026-05-17 handover, or comparable on the helper path if self-leg lost the probe race.

---

## Cross-cutting non-goals

- This plan does not change the slave-agent or sidecar-agent.
- This plan does not change the Rust relay.
- This plan does not touch Canton/Guangzhou combinations.
- This plan does not introduce a new tenant-facing knob; tenants still see only the forward combination.

## Self-Review

### Spec coverage

- Auto-republish on panel save: Tasks 1, 2.
- Persist policy fields on edit (root cause of "no effect on save"): Task 1.
- Self-leg as only MPTCP leg if better: Tasks 7, 8.
- Self-leg via CF IP under CF Bond: Task 6.
- Multiple slave groups per combination + admin-adjustable cap with default 4 for bond/CF-bond/MPTCP: Tasks 3, 4, 5, 8, 9.

### Placeholder scan

- No `TODO` / `TBD` placeholders remain.
- Every task names exact files and exact snippets.
- Tests are written first; each task owns at least one regression.

### Type consistency

- `helper_group_cap`, `max_legs_used` (panel mirror), `forward_combination_helper_group_cap` are all `int >= 0`.
- `self_leg_mptcp_solo_allowed` is `bool`.
- `probe_transport` is `cf_direct_wss` or `private_wg`.
- `runtime_capabilities` is a string list, members in `{bond, mptcp, wireguard}`.

### Rollout safety

- All changes are additive at the schema level (no destructive ALTER).
- Republish failures are logged and do not abort the save (Task 2 wraps each forward in try/catch).
- Default seeding only fires when admin leaves the field empty; explicit values always win.
- CF Bond self-leg WG suppression is gated on `backend_mode = cf_mode`, leaving non-CF combos untouched.
