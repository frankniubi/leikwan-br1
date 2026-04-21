# Tenant Forward Combination-Only Flow Design

**Date:** 2026-04-21  
**Status:** Approved design  
**Scope:** LeiKwan tenant panel forward creation and forward editing flow

## Goal

Finish the tenant-side simplification that started with forward combinations:

- tenant forward creation must have exactly one supported path
- tenant forward creation must expose only admin-published **Forward Combinations**
- the service detail / manage-rules page must stop exposing legacy routing controls
- existing forwards should gain an **Edit** action for non-topology changes

This keeps forward-chain matching and backend-routing choices under admin control only.

## Product Decision

The tenant panel will use **one create path only**:

- `GET/POST /tenant/services/{id}/create-forward`

The tenant service detail page remains a management page, not a creation wizard. It lists existing rules and links to create a new one, but does not embed a create form.

## In Scope

- remove the legacy inline tenant create form from the service detail page
- keep `/tenant/services/{id}/create-forward` as the only supported tenant create path
- retire old wizard-style tenant routes and UI affordances
- add tenant-visible `Edit` buttons for existing forward rules
- add dedicated tenant edit page and save handler
- ensure edit is limited to non-structural forward properties
- add/update regression coverage for the new tenant flow

## Out of Scope

- changing admin forward-combination CRUD
- allowing tenants to switch combinations during edit
- exposing slave groups, sidecar points, route mode, forward chains, or connection mode back to tenants
- redesigning provisioning snapshot semantics
- changing `client -> slave -> sidecar` topology

## Existing Context

The repository already contains the newer combination-only create page in `master/src/Controllers/TenantController.php` via `quickCreateForward()`.  
The repo also still contains older tenant creation surfaces:

- an inline create form on the tenant service detail page
- wizard-era routes like `/tenant/services/{id}/forward-wizard`
- legacy tenant POST create path `/tenant/services/{id}/forwards`

This design removes those overlapping create experiences so the tenant model becomes coherent.

## Final Tenant Experience

### Service detail / manage-rules page

The tenant service detail page should:

- keep the existing forward list / rules table
- remove the embedded create form entirely
- show a single `Create Forward` CTA that links to `/tenant/services/{id}/create-forward`
- show an `Edit` action for each existing forward row

The page should no longer present any of these creation controls:

- client group
- route mode
- forward chain
- connection mode
- sidecar point
- slave group

### Create page

`/tenant/services/{id}/create-forward` remains the only supported create surface.

The create form continues exposing only:

- `Forward Combination`
- `Target Host`
- `Target Port`
- `Local Listen Port`
- `Transport`

The tenant should not see backend topology or routing details.  
Admin chooses and publishes combinations. Tenant chooses one published package and provides backend target info.

### Edit page

Each forward row on the service detail page gets an `Edit` button that links to:

- `GET /tenant/services/{id}/forward/{fid}/edit`

The edit page should:

- prefill current forward values
- show the current forward combination as read-only summary text
- allow changes only to tenant-owned backend-target fields
- submit back to a dedicated POST handler
- redirect back to the service detail page on success

## Edit Permission Model

### Editable by tenant

- `target_host`
- `target_port`
- `listen_port`
- `transport_mode`

### Not editable by tenant

- `forward_combination_id`
- client group selection
- route mode
- forward chain
- connection mode
- sidecar point
- slave group
- backend mode / Cloudflare mode details
- any other topology-defining field

## Why forward combination stays locked

The user intent is explicit: forward-chain match and backend routing decisions must be determined by admin only.

Allowing tenants to change `forward_combination_id` during edit would recreate a hidden route-selection surface. That would conflict with the forward-combination abstraction and make tenant edit effectively equivalent to topology selection. This design forbids that.

## Controller Changes

### `master/src/Controllers/TenantController.php`

#### Service detail page

Update the tenant service-detail render path so it:

- removes the legacy embedded create form
- keeps the forward rules table
- adds a create CTA linking to `/tenant/services/{id}/create-forward`
- adds `Edit` actions to each forward row

#### `quickCreateForward()`

Keep `quickCreateForward()` as the only tenant create handler.

It remains combination-only. It may improve the read-only combination help text, but it should not reintroduce any backend selectors.

#### Legacy wizard handlers

`forwardWizardRedirect()` may remain only as a compatibility redirect for stale bookmarks.

`forwardWizard()` should no longer be part of the tenant-supported flow. It should be removed from supported usage and, if possible, removed from routing entirely.

#### Legacy create handler

The old tenant create POST path that used the service-detail form should no longer be part of the supported UI flow. The route should be removed if nothing else depends on it.

#### New edit handlers

Add:

- `editForward(Request $request, array $params): Response`
- `saveForwardEdit(Request $request, array $params): Response`

These handlers must:

- verify tenant authentication
- verify that the service belongs to the tenant
- verify that the forward belongs to that service
- render/save only the approved editable fields
- preserve the existing forward combination and snapped runtime topology

## Route Changes

### Keep

- `GET /tenant/services/{id}/create-forward`
- `POST /tenant/services/{id}/create-forward`

### Add

- `GET /tenant/services/{id}/forward/{fid}/edit`
- `POST /tenant/services/{id}/forward/{fid}/edit`

### Redirect compatibility

- `GET /tenant/services/{id}/forward-wizard`
- `POST /tenant/services/{id}/forward-wizard`

Both should only redirect to `/tenant/services/{id}/create-forward`.

### Remove as tenant-supported create path

- `POST /tenant/services/{id}/forwards`

There should no longer be a second active tenant create route.

## Validation Rules

### Create validation

Creation through `/create-forward` continues requiring:

- valid `forward_combination_id`
- valid `target_host`
- valid `target_port`
- valid optional `listen_port`
- valid `transport_mode`

### Edit validation

Edit must enforce:

- tenant owns the target service
- tenant owns the target forward
- only allowed fields are accepted
- `forward_combination_id` is unchanged
- `target_port` is within valid range
- `listen_port`, when provided, is within valid range
- `transport_mode` is one of the supported values

If a forward is in a state where edit should be blocked safely, the request should fail cleanly and leave the row unchanged.

## Provisioning Behavior

Editing a forward must preserve the existing combination snapshot and runtime identity.

After a valid edit:

- save only the allowed non-structural fields
- republish or reconcile the forward using the already snapped combination/runtime configuration

Edit is target-tuning only, not route reselection.

## Testing Strategy

### Update existing tenant forward-combination regression coverage

Update `master/tests/tenant_forward_combination_regression_test.php` so it verifies:

- service detail page no longer renders the old tenant create form
- service detail page no longer shows:
  - client group
  - route mode
  - forward chain
  - connection mode
  - sidecar point
  - slave group
- service detail page still links to `/tenant/services/{id}/create-forward`

### Add tenant edit regression coverage

Add a dedicated tenant edit regression test that verifies:

- edit page loads for the owning tenant
- edit page does not load for another tenant
- edit form shows the current forward combination as read-only summary
- edit updates only allowed fields
- edit cannot change `forward_combination_id`

### Route behavior tests

Verify:

- `/forward-wizard` redirects to `/create-forward`
- old `/forwards` tenant create path is gone or rejected as unsupported

## Rollout Plan

1. Remove the old inline tenant create UI from the service detail page.
2. Add per-row `Edit` actions and edit handlers.
3. Retire wizard-era and legacy create-route usage.
4. Run tenant regression tests and PHP lint for touched files.
5. Manually verify service detail, create page, edit page, and forward table behavior.

## Success Criteria

- tenant has exactly one supported create path
- tenant no longer sees backend selection controls on the manage-rules page
- create remains combination-only
- existing forwards can be edited without exposing topology
- admin remains the sole owner of combination-level route and chain decisions

## Final Design Summary

This design finishes the forward-combination simplification on the tenant side. Creation becomes single-path and combination-only. The manage-rules page stops being a shadow create form and returns to being a management surface. Existing forwards gain an edit affordance, but edit remains intentionally narrow so tenants can tune backend target settings without regaining topology control.
