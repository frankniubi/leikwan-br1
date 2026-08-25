# Tenant Service Detail Simplification Design

Date: 2026-08-25

Status: Approved for implementation planning

## Decision

Simplify the tenant `/tenant/services/{id}` page so it shows a user the small set
of things they actually need and nothing more. Concretely:

1. Remove the per-forward "View client config" `<details>` block and the raw
   `client_token` dump. A tenant does not need the machine-readable config JSON
   or the bearer token on this page.
2. Collapse the "Service Info" card to four always-visible facts (plan name,
   expiry + status, bandwidth, current-cycle traffic vs limit) and move the
   rest behind a "更多详情" (`<details>`) toggle.
3. Replace the single "Delete" action on each forward row with
   **Start / Pause / Modify / Delete** actions.
4. Add a per-forward "本期用量" (this-cycle usage) column: a progress bar plus a
   byte count, computed from `traffic_usage_hourly` via the forward's endpoints.

"Start" and "Pause" are new backend semantics, not cosmetic: Pause must actually
stop traffic on the relay nodes, and Start must bring it back.

## Goals

1. Hide operational/config noise the tenant does not need (`View client config`,
   raw token, WHMCS ID, flexible-options form, billing breakdown, billing
   history, traffic reset form, available-lines list, rotate-token form).
2. Keep a single glanceable Service Info header: plan + expiry + status,
   bandwidth, current-cycle traffic progress.
3. Add per-forward lifecycle control: Start (enable), Pause (disable), Modify
   (edit target), Delete (soft delete).
4. Show each forward's traffic usage within the current billing cycle.

## Non-goals

- No change to the service-level billing counter
  (`services.billing_cycle_traffic_used_bytes`) or to how billing/suspension is
  computed. Per-forward usage is display-only.
- No new per-forward metering table. We reuse `traffic_usage_hourly` (already
  keyed by endpoint) and join through `service_endpoints.forward_id`.
- No change to `serviceSummary()`'s forward filter for other callers. The paused
  list is fetched separately so existing consumers (dashboard, API, provisioning)
  keep their current "enabled only" behavior.
- No change to the public API routes under `/api/user/...` or `/api/v1/...`.
- No new confirmation dialogs or undo beyond what the existing Delete action
  already has (an inline POST form).

## Forward lifecycle states

`forwards.enabled` + `user_deleted_at` + `balance_suspended_at` already encode
three states. We add a fourth (user-paused) by using the combination that is
currently unused by any flow:

| State | `enabled` | `user_deleted_at` | `balance_suspended_at` | Visible on service page |
| --- | --- | --- | --- | --- |
| Running | 1 | NULL | NULL | yes (运行) |
| Paused (new) | 0 | NULL | NULL | yes (已暂停) |
| Balance-suspended | 0 | NULL | set | no (unchanged) |
| Deleted | 0 | set | NULL | no (unchanged) |

Because `serviceSummary()` returns only `enabled = 1` forwards, the service page
must fetch paused forwards separately so a Pause'd forward remains visible and
its Start button reachable.

## Backend changes

### `ProvisioningService::setForwardEnabled()`

New public method mirroring the existing `deleteForward` / `updateForward`
transaction shape:

```php
public function setForwardEnabled(
    int $forwardId,
    bool $enabled,
    string $actor = 'user',
    ?int $expectedRevision = null,
    ?array $mutationPolicy = null
): array
```

Within one transaction:

- `SELECT * FROM forwards WHERE id = :id LIMIT 1 FOR UPDATE` (404 if missing).
- `lockForwardMutationService(service_id, mutationPolicy, 'forward_not_found')`
  to enforce the `allow_manage_forwards` feature and active-service/tenant gates.
- `assertExpectedForwardRevision(...)`.
- Bump `api_revision`.
- **Pause** (`enabled = false`): `revokeForwardEndpoints($forwardId, 'pause')`,
  then `UPDATE forwards SET enabled = 0, balance_suspended_at = NULL,
  api_revision = :r, updated_at = :now` (leave `user_deleted_at` NULL), then
  `audit('forward.paused', ...)`. Do **not** call `reconcileForwardEndpoints`.
- **Start** (`enabled = true`): `UPDATE forwards SET enabled = 1,
  balance_suspended_at = NULL, api_revision = :r, updated_at = :now`, then
  `reconcileForwardEndpoints($forwardId)`, then `audit('forward.started', ...)`.
  (`reconcileForwardEndpoints` early-returns unless `enabled = 1`, so the UPDATE
  must precede it.)

Return `buildClientConfig($forwardId)` for a consistent API, as the other
mutators do.

### `ProvisioningService::forwardCycleUsageMap()`

New public method returning `[forward_id => bytes]` for one service:

```php
public function forwardCycleUsageMap(int $serviceId, string $cycleStartedAt): array
```

```sql
SELECT se.forward_id,
       COALESCE(SUM(tuh.bytes_in + tuh.bytes_out), 0) AS used_bytes
FROM traffic_usage_hourly tuh
INNER JOIN service_endpoints se ON se.id = tuh.endpoint_id
WHERE tuh.service_id = :service_id
  AND tuh.period_start >= :cycle_started_at
GROUP BY se.forward_id
```

`$cycleStartedAt` comes from `$summary['billing']['cycle_started_at']` (already
computed by `billingOverviewFromService`). If empty, fall back to the service
`created_at`.

### `ProvisioningService::pausedForwardsForService()`

New public method returning the paused (non-deleted, non-suspended) forwards for
the service page:

```sql
SELECT f.*, combo.name AS forward_combination_name, combo.tenant_label AS forward_combination_label
FROM forwards f
LEFT JOIN forward_combinations combo ON combo.id = f.forward_combination_id
WHERE f.service_id = :service_id
  AND f.enabled = 0
  AND f.user_deleted_at IS NULL
  AND f.balance_suspended_at IS NULL
ORDER BY f.id ASC
```

Paused forwards have no live endpoints (they were revoked on pause), so no
endpoint hydration is needed.

## Controller changes

### `TenantController::serviceDetail()`

- Fetch `$summary` as today (running forwards), then
  `$paused = $this->provisioning->pausedForwardsForService($serviceId)` and
  `$forwardRows = array_merge($summary['forwards'], $paused)` sorted by id.
- Compute `$usageMap = $this->provisioning->forwardCycleUsageMap($serviceId,
  $billing['cycle_started_at'])` once.
- For each forward, derive `$isRunning = (int)$forward['enabled'] === 1`, render
  a status badge (运行 / 已暂停), a usage cell (progress bar + `formatTrafficBytes`),
  and the action cluster:
  - Running → Pause (POST toggle, `action=pause`), Modify (link to
    `/tenant/services/{id}/forward/{fid}/edit`), Delete (existing POST form).
  - Paused → Start (POST toggle, `action=start`), Modify, Delete.
  - All gated by `allow_manage_forwards` (Start/Pause/Modify) and
    `allow_delete_forwards` (Delete).
- Remove the "View client config" `<details>` and the `configJson` computation
  if it is no longer referenced elsewhere in this method.
- Rewrite the "Service Info" card into:
  - A hero/stat strip with plan, expiry, status, bandwidth, and the current-cycle
    traffic progress bar.
  - A `更多详情` `<details>` containing the previously-always-visible extra
    fields (WHMCS ID, allowed client groups, traffic policy, lifetime traffic,
    flexible-options form, billing-this-cycle, traffic-reset form, billing
    history table, available lines, rotate-token form). Keep the raw token
    removed entirely.
- Update the Forwards table `<thead>` to add the `本期用量` and `状态` columns
  (status is currently implicit; make it explicit).

### `TenantController::toggleForward()` + route

- New `POST /tenant/services/{id}/forward/{fid}/toggle` route in `Bootstrap.php`.
- New controller method reads `action` (`start` | `pause`), validates CSRF,
  loads the forward with `enabled`-agnostic ownership check (`user_id` match),
  asserts service active, calls `setForwardEnabled(..., $action === 'start',
  'tenant:'.user_id, api_revision, ['user_id'=>..., 'feature'=>'allow_manage_forwards'])`,
  flashes success, redirects back to `/tenant/services/{id}`.
- On `feature_denied_manage_forwards` or other `ApiException`, flash error and
  redirect.

## Known limitation

Per-forward usage sums `traffic_usage_hourly` through endpoint→forward. It is an
**approximation** and may differ from the service-level
`billing_cycle_traffic_used_bytes` counter because:

- Ultra forwards record usage into `ultra_usage_events` (keyed by `forward_id`),
  not `traffic_usage_hourly`.
- Some code paths increment the service counter without writing an hourly row.

For the current production forward modes (leigod_mode / current_mode / bond) the
discrepancy is expected to be small. Ultra per-forward usage is out of scope for
this change and can be folded in later if needed.

## Feature gates

- Start/Pause/Modify → `allow_manage_forwards`.
- Delete → `allow_delete_forwards`.
- `allow_view_client_config` is no longer rendered on this page (the flag remains
  honored on the API `client-config` endpoint).

## Files

- Modify `master/src/Support/ProvisioningService.php` — add `setForwardEnabled`,
  `forwardCycleUsageMap`, `pausedForwardsForService`.
- Modify `master/src/Controllers/TenantController.php` — rewrite `serviceDetail`,
  add `toggleForward`.
- Modify `master/src/Bootstrap.php` — add the toggle route.
- Create `master/tests/tenant_forward_toggle_and_usage_regression_test.php` —
  source-contract regression test (matches existing test style).

## Testing

- Source-contract regression test asserts: `setForwardEnabled` signature and
  `audit('forward.paused'|'forward.started')`, `forwardCycleUsageMap` query with
  `period_start >=`, the new `/toggle` route, `action=pause`/`action=start`
  handling, absence of `View client config` and `client_token` `<pre>` on the
  service page, and presence of the `本期用量` / `状态` columns.
- Manual: on a live service with running forwards, pause one and confirm the
  endpoint is revoked and the row shows 已暂停 with a Start button; start it and
  confirm endpoints reconcile back.
