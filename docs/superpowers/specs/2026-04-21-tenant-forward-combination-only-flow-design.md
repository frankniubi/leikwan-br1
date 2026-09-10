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

## NOT in scope

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

## What already exists

There is no repo-root `DESIGN.md` in this project, so the review should anchor on the existing tenant panel patterns already used in `master/src/Controllers/TenantController.php`.

Existing UI primitives worth reusing:

- `hero` header blocks with page title and top-right actions
- `card` sections for each management surface
- `table-wrap` plus table layout for dense operational data
- `button` and `button ghost` actions
- `flash danger` / `flash success` feedback blocks
- `field-note` helper text under form controls
- per-resource `Edit` actions already used on tenant `clients` and `sidecars`

Existing product context worth preserving:

- `quickCreateForward()` already provides a dedicated create page using the `hero + card + form-grid + actions` structure
- the service detail page already functions as the tenant's forward management hub
- existing rows already expose delete, install commands, and endpoint status details, so the forward table is the natural home for "manage existing rules"

## Final Tenant Experience

### Service detail / manage-rules page

The tenant service detail page should:

- keep the existing forward list / rules table
- remove the embedded create form entirely
- show a single `Create Forward` CTA that links to `/tenant/services/{id}/create-forward`
- show an `Edit` action for each existing forward row

The page should no longer present any of these creation controls or backend-topology columns:

- client group
- route mode
- forward chain
- connection mode
- sidecar point
- slave group

The forward table itself should be simplified so tenants see only user-meaningful information:

- `Local Listen`
- `Forward Combination`
- `Target`
- `Transport`
- `Endpoint Status`
- `Actions`

Any secondary operational detail that must remain available should stay inside collapsed details, not as always-visible topology columns. The default scan of the page should remain backend-blind.

If the service has zero forwards, the page should not show an empty bare table. It should show a proper empty state:

- short explanation that no forward rules exist yet
- one primary action: `Create Forward`
- one secondary action: back to service overview or knowledge base
- no mention of slave groups, sidecars, or route chains

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

The create page should also specify the combination-summary states explicitly, so the UI does not ship with placeholder copy:

- **Default state:** helper text explains that the admin-defined package controls the route and the tenant only needs to provide backend target info
- **Selected state:** show the combination's `tenant_label`, optional badge, and short description in a read-only summary block
- **Unavailable state:** if no combinations exist, show the existing danger flash, keep the create button disabled, and focus the page on the "ask admin to publish a forward combination first" message

### Edit page

Each forward row on the service detail page gets an `Edit` button that links to:

- `GET /tenant/services/{id}/forward/{fid}/edit`

The edit page should:

- prefill current forward values
- show the current forward combination as read-only summary text
- allow changes only to tenant-owned backend-target fields
- submit back to a dedicated POST handler
- redirect back to the service detail page on success

The edit page should mirror the visual structure of `quickCreateForward()` so create and edit feel like the same product surface:

- same `hero`
- same `card`
- same `form-grid`
- same bottom `actions` row

This is a deliberate design choice. Tenant `clients` and `sidecars` use same-page `?edit=` patterns, but forward edit should use a dedicated page because the service detail page is already a dense management surface and should not grow another inline form after create was intentionally removed from it.

If a forward cannot be edited safely, the tenant should get a clear blocked state:

- flash error explaining why edit is unavailable
- no silent failure
- no hidden partial save
- return path back to the service detail page

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

The service-detail render path must also simplify the forward table itself. Removing the create form is not enough if the table still leaks admin-owned routing concepts.

Add a dedicated input normalizer for edit:

- `normalizeTenantForwardEditPayload(array $payload): array`

It must accept only:

- `target_host`
- `target_port`
- `listen_port`
- `transport_mode`

Do not reuse the create payload normalizer for edit. Create and edit have different security boundaries and should stay explicit.

### `master/src/Controllers/ServiceController.php`

The authenticated user API must follow the same permission model as the tenant panel.

Update `PUT /api/user/forwards/{id}` so it cannot mutate structural routing fields. It should route through the same non-structural update path used by tenant edit.

This keeps the product contract honest:

- admin owns routing / combination choice
- tenant and authenticated end-user API callers can edit only backend-target fields

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

### API parity

Keep the existing authenticated API route shape:

- `PUT /api/user/forwards/{id}`

but narrow its update contract so it matches tenant edit restrictions. The route remains available, but its allowed fields become non-structural only.

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

Validation feedback should be field-specific where possible. A tenant should immediately understand whether the problem is an invalid host, an invalid port, or an unavailable edit state.

For both tenant edit and authenticated user API update:

- structural fields in the request body must be ignored or rejected explicitly
- accepted update payload must be merged onto the existing forward snapshot
- full forward validation must run on the merged payload before any live endpoint teardown begins

## Provisioning Behavior

Editing a forward must preserve the existing combination snapshot and runtime identity.

After a valid edit:

- save only the allowed non-structural fields
- republish or reconcile the forward using the already snapped combination/runtime configuration

Edit is target-tuning only, not route reselection.

### Safe update sequence

Do not reuse the current unsafe order where live endpoints are revoked before the new payload is known-valid.

Required update sequence:

```text
load existing forward
  -> merge non-structural edit payload
    -> validate merged forward snapshot
      -> revoke old endpoints
        -> save updated forward
          -> queue replacement publish
```

If validation fails, the sequence must stop before revoke. The old forward stays live.

## Interaction States

### Service detail page

- **Zero forwards:** show a designed empty state with primary CTA
- **Has forwards:** show simplified table plus actions
- **Create disabled by plan/account:** show clear flash message, but still keep the page readable and focused on existing rules

### Create page

- **Default:** no combination selected yet
- **Selected combination:** summary block updates in place
- **No combinations published:** danger flash, disabled submit
- **Validation error:** preserve entered values and keep the tenant on the same page

### Edit page

- **Normal edit:** prefilled fields, locked combination summary
- **Validation error:** preserve entered values and show field-level feedback
- **Blocked edit:** flash error + safe return path
- **Save success:** redirect to service detail page with success flash

## Responsive and accessibility requirements

This design now depends on reducing visible table complexity. That is the responsive strategy, not "keep every old column and hope horizontal scroll saves us."

Required behavior:

- the service detail table must stay scannable on narrower widths by using fewer always-visible columns
- action buttons should stack cleanly in the actions cell when space is tight
- the combination summary block on create and edit pages must use text, not badge color alone, to convey meaning
- labels and helper text must remain visible for keyboard and screen-reader users
- touch targets for `Create Forward`, `Edit`, and `Delete` must remain usable on mobile-sized widths

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
- service detail page no longer shows backend-topology columns such as route mode, chain, line groups, or client mode in the default forward table
- service detail page still links to `/tenant/services/{id}/create-forward`
- zero-forward state renders a primary `Create Forward` action instead of an empty legacy table

### Add tenant edit regression coverage

Add a dedicated tenant edit regression test that verifies:

- edit page loads for the owning tenant
- edit page does not load for another tenant
- edit form shows the current forward combination as read-only summary
- edit updates only allowed fields
- edit cannot change `forward_combination_id`
- blocked edit path returns a clear error without partial mutation

### Add create-page state coverage

Verify:

- default combination helper text is real copy, not placeholder text
- selected combination summary renders tenant label / badge / description
- no-combination state disables submit and shows clear next step

### Add safe-update behavior coverage

Add a `ProvisioningService` behavior test using the existing fake-DB pattern already present in tests like `master/tests/provisioning_sidecar_republish_test.php`.

It must verify:

- invalid non-structural edit fails validation before `revokeForwardEndpoints()`
- existing live endpoints remain untouched on failed edit
- valid edit revokes/re-publishes only after merged-payload validation succeeds
- structural fields such as `forward_combination_id`, `endpoint_mode`, `forward_chain_id`, `node_group_key`, and `client_mode` remain unchanged under tenant edit

### Add authenticated user API contract coverage

Add regression coverage for `PUT /api/user/forwards/{id}` so it verifies:

- non-structural edits still work
- structural routing mutations are rejected or ignored
- API callers cannot bypass the tenant panel's routing restrictions

### Update stale tenant regression coverage

`master/tests/tenant_controller_regressions_test.php` currently asserts legacy route-mode / forward-chain UI behavior. That test must be updated or replaced so it validates the new combination-only direction instead of pinning the old tenant create form in place.

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
