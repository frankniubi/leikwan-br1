# Tenant Forward Combination Cleanup + CF Best IP Reality Check Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the tenant forward UI combination-first everywhere, remove stale tenant-side backend selectors, and close the gap between the current Cloudflare `cf_mode` implementation and the product claim of "Best IP".

**Architecture:** Reuse the existing combination-only `/tenant/services/{id}/create-forward` flow as the single tenant creation contract, then delete or redirect every stale tenant path that still renders route-mode, chain, sidecar, or slave-group selectors. Keep admin-only combination resolution in `ProvisioningService`. On the slave side, treat current `cf_mode` as transport-ready but ranking-incomplete, then either implement missing ranking/failover behavior or narrow the product claim before shipping.

**Tech Stack:** PHP tenant controller + source-string regressions, PHP provisioning/service summary logic, Python slave-agent runtime + unit tests, existing admin forward-combination model

---

## Scope Guardrails

- Keep topology `client -> slave -> sidecar`.
- Keep tenant surface backend-blind. Tenants should not choose or see slave group, forward chain, route mode, connection mode, sidecar point, or Cloudflare location group during creation.
- Keep admin-owned `forward_combinations` as the only tenant-visible routing package.
- Keep `cf_mode` scoped to `single` and `bond`.
- Do not touch unrelated Windows, Android, UDP-health, or artifact publishing work on this already-dirty branch.

## What Already Exists

- `master/src/Controllers/TenantController.php:1717-1789` already renders a combination-only quick-create page.
- `master/src/Controllers/TenantController.php:2600-2612` already normalizes tenant create payloads down to `forward_combination_id`, `listen_port`, `target_host`, `target_port`, and `transport_mode`.
- `master/src/Controllers/TenantController.php:2184-2213` already posts tenant service-page creates through that narrow payload shape.
- `master/src/Support/ProvisioningService.php:2804-2815` already snapshots `forward_combination_id` into the resolved forward shape.
- `master/src/Support/ProvisioningService.php:3843-3885`, `master/src/Controllers/SidecarAgentController.php:101-193`, `slave-agent/slave_agent.py:1804-1991`, and `sidecar-agent/sidecar_agent.py:590-640` already wire the `cf_mode` transport path end-to-end.

## NOT in scope

- Reworking plan authorization beyond existing allowed node-group / sidecar-point filters.
- Adding `mptcp` or `race` to `cf_mode`.
- Replacing the current sidecar WSS mux architecture.
- Unrelated docs/tutorial cleanup outside the tenant forward surface, unless a changed page becomes obviously misleading during implementation.

## Current Problems To Fix

### 1. Tenant panel is internally inconsistent

- The service detail page still renders the old create form at `master/src/Controllers/TenantController.php:1641-1689`.
- That form still asks tenants for `Line Client Groups`, `Route Mode`, `Forward Chain`, `Connection Mode`, `Sidecar Point`, and `Slave Group`.
- But the submit handler at `master/src/Controllers/TenantController.php:2184-2213` now ignores those fields because `normalizeTenantForwardPayload(...)` no longer returns them.

This is the core bug. The page looks powerful, but most of the knobs are dead and silently discarded.

### 2. The tenant forward table still leaks backend topology

- The table at `master/src/Controllers/TenantController.php:1392-1404` still shows line, line groups, route mode, and chain columns.
- That conflicts with the approved combination-only abstraction in `docs/superpowers/specs/2026-04-20-cf-location-groups-forward-combinations-design.md`.

### 3. "Best IP" is transport-ready, not ranking-complete

- `slave-agent/slave_agent.py:885-905` scores candidates with a single TCP connect RTT only.
- `jitter_ms` is hard-coded to `0.0`.
- `probe_mode == "icmp"` still falls back to the same TCP connect path.
- `switch_improvement_ms` is sent from `ProvisioningService` at `master/src/Support/ProvisioningService.php:3874-3877`, but never used by the slave selector.
- `cooldown_until`, `consecutive_failures`, and `backup_ips` are written into cache state at `slave-agent/slave_agent.py:920-929`, but no runtime logic consumes them.

So the path can dial a selected Cloudflare IP, but the "Best IP" decision engine is not fully implemented yet.

## Architecture Diagram

```text
Tenant service page / quick create
  -> shared Forward Combination form
  -> TenantController::normalizeTenantForwardPayload()
  -> explicit tenant-side combination requirement
  -> ProvisioningService::visibleForwardCombinationForService()
  -> forward snapshot (backend_mode, node_group_key, sidecar_point_id, cf_location_group_id)
  -> publishTaskPayloadForForward()

cf_mode runtime
  slave-agent
    -> select CF candidate
    -> wstunnel client to selected CF IP
    -> original SNI + Host + path preserved
    -> sidecar cf_runtime mux
    -> target backend
```

## File Map

- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/tests/tenant_controller_regressions_test.php`
- Modify: `master/tests/tenant_forward_combination_regression_test.php`
- Modify: `master/public/i18n/zh-TW.json`
- Modify: `slave-agent/slave_agent.py`
- Modify: `slave-agent/tests/test_slave_cf_location_group_selection.py`
- Optional: `master/tests/admin_cf_location_observability_test.php` if new CF stats fields are surfaced
- Optional: `docs/2026-04-20-cf-mode-forward-combinations-change-log.md` after implementation lands

## Task 1: Make Tenant Forward Creation Combination-Only Everywhere

**Files**
- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/tests/tenant_controller_regressions_test.php`
- Modify: `master/tests/tenant_forward_combination_regression_test.php`
- Modify: `master/public/i18n/zh-TW.json`

**Plan**
- Extract one private helper that renders the combination-only create form already used by `quickCreateForward(...)`.
- Reuse that helper from the service detail page instead of maintaining a second create form.
- Keep the legacy `/tenant/services/{id}/forward-wizard` URL as a redirect only, but stop carrying dead multi-step UI expectations anywhere in the tenant page copy.
- Change the service detail guidance from "pick a line / route / chain / sidecar" language to "pick a route package" language.
- Simplify the forward list columns so they show tenant-meaningful data only:
  - keep: ID, local listen, forward combination, target, transport, path, endpoint status, actions
  - remove from tenant HTML: line, line groups, route mode, chain, client mode

**Why**
- This is the highest-leverage cleanup. The current page is misleading because it presents knobs that no longer affect the saved forward.

## Task 2: Add A Guardrail So Tenant POSTs Cannot Silently Fall Back To Legacy Behavior

**Files**
- Modify: `master/src/Controllers/TenantController.php`
- Modify: `master/tests/tenant_forward_combination_regression_test.php`

**Plan**
- Add an explicit tenant-path validation before `ProvisioningService::createForward(...)` so `forward_combination_id <= 0` returns a clear tenant-facing error.
- Keep admin/API flexibility in `ProvisioningService`, but make the tenant controller refuse stale legacy posts.
- Update flash messaging so tenants see "Select a forward combination first" instead of generic creation failures.

**Why**
- Even after the UI cleanup, stale browser tabs or copied forms can still POST the old payload shape. Right now those requests can silently create the wrong thing with defaults.

## Task 3: Replace Regressions That Preserve The Old UI With Regressions That Forbid It

**Files**
- Modify: `master/tests/tenant_controller_regressions_test.php`
- Modify: `master/tests/tenant_forward_combination_regression_test.php`

**Plan**
- Delete the current assertions that require route-mode and forward-chain selectors to remain on tenant pages.
- Add new regression coverage that asserts:
  - the service detail page no longer renders the stale selector labels:
    - `Line Client Groups`
    - `Route Mode`
    - `Forward Chain`
    - `Connection Mode`
    - `Sidecar Point`
    - `Slave Group`
  - the page still renders `Forward Combination`
  - tenant create handling requires `forward_combination_id`
  - `/tenant/services/{id}/forward-wizard` remains a compatibility redirect to `/create-forward`

**Why**
- The current regression suite protects the wrong behavior. That has to flip first or the UI will regress again.

## Task 4: Finish Or Narrow The Cloudflare "Best IP" Contract

**Files**
- Modify: `slave-agent/slave_agent.py`
- Modify: `slave-agent/tests/test_slave_cf_location_group_selection.py`
- Optional: `master/tests/admin_cf_location_observability_test.php`

**Plan**
- Split the current selector into explicit steps:
  1. candidate expansion
  2. multi-sample probe collection
  3. score computation
  4. switch decision against the cached winner
  5. failure/cooldown fallback
- Use repeated probe samples per candidate so `last_latency_ms` and `last_jitter_ms` are real values, not placeholders.
- Either implement a real ICMP probe path or remove `icmp` from the supported admin vocabulary. Do not keep a dead knob.
- Honor `switch_improvement_ms` before replacing a healthy cached winner.
- Consume `consecutive_failures`, `cooldown_until`, and `backup_ips` during reselection instead of just writing them into state.
- Add tests for:
  - TTL reuse
  - jitter computation from multiple samples
  - switch-threshold hold behavior
  - backup-IP failover after repeated failures
  - probe-mode behavior after the `icmp` decision is made

**Why**
- Today the path can work, but it is not honest to call the selector "Best IP". It is just "lowest single TCP connect RTT among the first expanded candidates".

## Task 5: Verification And Exit Criteria

**Files**
- No new product code required if prior tasks are already covered

**Plan**
- Run the existing combination and CF regression suite:
  - `php master/tests/provisioning_forward_combination_regression_test.php`
  - `php master/tests/admin_forward_combination_page_test.php`
  - `php master/tests/tenant_forward_combination_regression_test.php`
  - `php master/tests/tenant_controller_regressions_test.php`
  - `php master/tests/admin_cf_location_observability_test.php`
  - `python -m unittest slave-agent.tests.test_slave_cf_location_group_selection`
- Add one new tenant-page regression that proves the service detail page uses combination-only language.
- Add one new slave-agent regression that proves `switch_improvement_ms` changes selection behavior.
- If the user wants a "fully functional" verdict, do one live sandbox smoke test after code lands:
  - one `current_mode` combination
  - one `cf_mode + single + tcp`
  - one `cf_mode + bond + tcp+udp`
- If live validation is skipped, report the result as "codepath complete, live CF verification still pending".

## Failure Modes Registry

| Area | Failure mode | Severity | Mitigation |
|---|---|---:|---|
| Tenant UI | Old service page still posts stale fields | High | Remove stale form and require combination id server-side |
| Tenant trust | Tenant still sees backend topology in list table | Medium | Hide backend columns in tenant HTML |
| CF ranking | Selector flips too often | High | Honor `switch_improvement_ms`, cooldown, and cached winner |
| CF ranking | `icmp` option lies | Medium | Implement it or delete it |
| CF rollout | "Best IP" marketing exceeds implementation | High | Narrow the claim until ranking/failover is real |

## Test Diagram

```text
Tenant create flow
  service detail page
    -> combination-only card rendered
    -> stale selectors absent
    -> POST requires forward_combination_id
  quick create page
    -> same combination contract
  legacy wizard URL
    -> redirect only

Provisioning flow
  forward_combination_id
    -> visibleForwardCombinationForService()
    -> resolved node_group / sidecar / cf group snapshot

CF selector flow
  cidr list
    -> candidate expansion
    -> repeated probes
    -> score + jitter
    -> switch threshold
    -> backup / cooldown reuse
```

## Decision Audit Trail

| # | Phase | Decision | Classification | Rationale | Rejected |
|---|---|---|---|---|---|
| 1 | Product | Use `Forward Combination` as the only tenant create selector everywhere | auto-decided | This matches the approved 2026-04-20 design and removes dead controls | keeping dual tenant UIs |
| 2 | Engineering | Keep `/forward-wizard` as a redirect, not a live second implementation | auto-decided | Preserves old links without carrying stale behavior | reviving the old wizard |
| 3 | Engineering | Add an explicit tenant-side `forward_combination_id` guard | auto-decided | Prevents silent fallback to defaults from stale legacy posts | relying on HTML `required` only |
| 4 | Product | Do not call current CF selector "fully functional Best IP" yet | high-severity finding | Runtime selection is RTT-only and ignores advertised switch/cooldown knobs | shipping the label as-is |
| 5 | Scope | Keep real throughput ranking outside this cleanup unless the user explicitly expands scope | deferred | It is materially larger than the tenant-panel cleanup and should be a conscious scope decision | silently folding it into this pass |

