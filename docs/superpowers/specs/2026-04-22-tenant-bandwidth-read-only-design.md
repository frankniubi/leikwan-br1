# Tenant Bandwidth Read-Only Design

**Date:** 2026-04-22  
**Status:** Approved design  
**Scope:** LeiKwan tenant panel service detail bandwidth controls

## Goal

Remove the tenant-side ability to adjust or pay for higher bandwidth from the tenant panel, while keeping current bandwidth and billing information visible as read-only reference.

## In Scope

- remove the tenant self-serve bandwidth adjustment form from the tenant service detail page
- remove the tenant route that updates flexible bandwidth options
- keep bandwidth and billing summaries visible
- replace editable/action-oriented copy with read-only explanatory copy
- add regression coverage so tenant self-serve bandwidth controls do not reappear

## NOT in Scope

- removing billing visibility
- removing traffic reset
- changing admin-side package or bandwidth management
- changing pricing calculations
- redesigning tenant billing pages beyond this service-detail control removal

## What already exists

- `master/src/Controllers/TenantController.php` currently renders a tenant service detail section showing:
  - `Base Bandwidth`
  - `Current Bandwidth`
  - `Bandwidth Add-on`
  - extra client pricing details
  - current estimate
- the same service detail page currently renders a form posting to:
  - `/tenant/services/{id}/flexible-options`
- `master/src/Bootstrap.php` currently registers:
  - `POST /tenant/services/{id}/flexible-options`
- `TenantController::updateFlexibleOptions(...)` currently allows tenant-side bandwidth updates

## Product Decision

Tenant service detail should become **read-only for bandwidth/package changes**.

The page may still explain:

- what bandwidth the tenant currently has
- what their billing model is
- what add-ons are currently being charged

But it must not offer tenant self-serve upgrade/change controls from this page.

## Tenant UX

### Keep visible

- current bandwidth
- base bandwidth
- current-cycle billing summary
- bandwidth add-on values if already part of the bill
- extra client charge values if already part of the bill
- traffic reset section

### Remove

- the `Update Flexible Options` form
- the tenant-entered `bandwidth_limit_mbps` field
- any tenant-facing submit button that changes package bandwidth

### Replacement copy

Where the form currently appears, replace it with short read-only explanatory text such as:

- package and bandwidth changes are managed by admin
- this page shows current package and billing state only

The page should feel transparent, not interactive, in this area.

## Route and controller behavior

### Route

Remove the tenant route:

- `POST /tenant/services/{id}/flexible-options`

### Controller

Tenant controller should stop rendering a form for bandwidth changes and should stop exposing tenant self-serve bandwidth update behavior.

Admin remains the sole owner of package and bandwidth changes.

## Billing copy behavior

### Keep terminology

- `Base Bandwidth`
- `Current Bandwidth`
- `Bandwidth Add-on`
- `Current Estimate`

### Remove or rewrite actionable wording

Do not ship tenant-facing copy that implies:

- update bandwidth here
- adjust package here
- submit a new bandwidth value

### Replace with reference wording

Use wording like:

- shown for reference
- package and bandwidth changes are managed by admin

## Architecture diagram

```text
Tenant service detail
  -> read service billing snapshot
  -> render bandwidth + billing summary
  -> render traffic reset section
  -> no bandwidth-edit form
  -> no POST flexible-options route

Admin package management
  -> still owns bandwidth/package updates
```

## Testing strategy

### Tenant service-detail regression

Add/update regression coverage so it verifies:

- no `Update Flexible Options` button
- no tenant form posting to `/tenant/services/{id}/flexible-options`
- bandwidth and billing summary still render
- read-only explanatory copy renders

### Route regression

Add/update regression coverage so it verifies:

- `/tenant/services/{id}/flexible-options` is removed from routing

If temporary compatibility is kept in implementation, the fallback behavior must still not change service bandwidth. But the preferred design is route removal.

## Success criteria

- tenant can see current bandwidth and billing information
- tenant cannot adjust or purchase higher bandwidth from the tenant panel
- admin remains sole owner of package/bandwidth change actions

## Final design summary

This change makes the tenant bandwidth area honest. Tenants still get visibility into current bandwidth and billing, but the panel stops pretending they can self-serve package/bandwidth upgrades there. The service detail page becomes read-only for bandwidth changes, and the tenant update route is removed.
