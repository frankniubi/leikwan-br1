# Panel Registration, I18n, and Package Forward Combination Design

**Date:** 2026-04-21  
**Status:** Approved design  
**Scope:** LeiKwan panel admin UX, translations, failed-task cleanup, and membership package model

## Goal

Ship three product changes together:

1. finish Chinese localization by adding **Simplified Chinese** and completing **Traditional Chinese**
2. add an admin action to **clear all failed tasks**
3. move tenant self-registration management into a **dedicated admin page**, and evolve membership packages so they sell **Forward Combinations** plus package pricing, instead of exposing low-level infrastructure permissions

## In Scope

- add `zh-Hans` as a supported locale
- keep `zh-TW` as the existing Traditional Chinese locale and make its translations complete
- update locale switchers and i18n bootstrap so all three locales are first-class:
  - `en`
  - `zh-TW`
  - `zh-Hans`
- add a dedicated admin **Registration** page and nav item
- move registration settings and registration code management out of `/admin/tenants`
- add an admin **Clear All Failed Tasks** action
- change membership package entitlements from low-level selections to **Allowed Forward Combinations**
- add package pricing fields for:
  - base package price
  - bandwidth-related pricing
  - slave price
  - sidecar price
- make service/package filtering of visible forward combinations follow package-combination mapping directly

## NOT in Scope

- a full billing system rewrite
- changing the runtime `client -> slave -> sidecar` topology
- renaming the existing Traditional locale key from `zh-TW` to `zh-Hant` right now
- adding failed-task retry orchestration
- changing admin forward-combination CRUD beyond what is needed to integrate package selection
- redesigning the public registration flow beyond the new admin management placement and toggle semantics

## What already exists

### Translations

- `master/src/Controllers/BaseController.php` currently exposes a locale switcher with:
  - `en`
  - `zh-TW`
- `master/public/i18n/zh-TW.json` already exists
- `master/public/install.php` also exposes Traditional Chinese only today

### Registration management

- tenant registration backend already exists in:
  - `master/src/Support/TenantRegistrationService.php`
- admin management for registration already exists, but is embedded inside `/admin/tenants`:
  - registration settings
  - code generation
  - enable/disable codes
- registration routes already exist in `master/src/Bootstrap.php`

### Failed-task operations

- admin already has stale pending/running task cleanup behavior
- admin already renders failed task visibility in existing dashboard/diagnostics surfaces
- there is not yet a dedicated bulk action for **failed** tasks

### Membership packages

- package edit UI currently uses low-level infrastructure selectors:
  - allowed slave groups
  - allowed client groups
  - allowed sidecar points
- package pricing already includes:
  - base fee
  - traffic pricing
  - bandwidth step pricing
  - extra client pricing
- `ProvisioningService::availableForwardCombinationsForService(...)` currently filters combinations through package-level node-group and sidecar visibility

## Product decisions

## 1. Admin information architecture

### Dedicated admin Registration page

Add a dedicated admin page and nav item:

- `/admin/registration`

This page becomes the home for:

- registration enabled/disabled controls
- whether invite / registration code is required
- registration code generation
- registration code list
- code enable/disable actions

### Tenants page responsibility after the change

`/admin/tenants` should focus on tenant account management only:

- create/edit tenant
- tenant status
- tenant services
- tenant deletion

It should no longer be the place where operators manage self-signup policy.

### Failed-task action placement

Do **not** introduce a separate tasks product area unless the future scope grows.

Instead:

- keep failed-task visibility where it already exists in admin task/diagnostic surfaces
- add one explicit bulk action there:
  - **Clear All Failed Tasks**

This keeps the feature boring and operational.

## 2. Translation / locale model

### Supported locales

Final supported locales:

- `en`
- `zh-TW`
- `zh-Hans`

### Locale-key decision

Keep the existing Traditional locale key as `zh-TW`.

Reason:

- current code already references `zh-TW`
- this avoids unnecessary migration risk
- it keeps the diff smaller while still achieving the product outcome

### Translation completeness rule

- English remains the source-of-truth fallback
- `zh-TW` must be made complete for current panel/UI coverage
- `zh-Hans` must ship as an explicit reviewed locale file, not as an invisible runtime transform

### Surfaces to update

- admin pages
- tenant pages
- public pages
- install page
- locale switcher UI
- i18n bootstrap
- new registration page
- failed-task cleanup button/copy
- package / forward-combination terminology

### Vocabulary consistency

The translation pass must consistently use the new product model:

- membership package
- forward combination
- registration code
- registration enabled/disabled
- clear all failed tasks
- slave price
- sidecar price

Do not ship mixed old/new wording where the package UI still sounds like raw infrastructure permission management.

## 3. Failed-task cleanup behavior

### New admin action

Add an admin-only bulk action:

- **Clear All Failed Tasks**

### Behavior

- only deletes task records whose status is `failed`
- does not touch:
  - `pending`
  - `running`
  - `completed`
- does not retry, republish, or mutate runtime state
- is CSRF-protected and POST-only

### User experience

- if failed tasks exist:
  - action runs
  - show success flash with deleted count
- if no failed tasks exist:
  - button may be disabled, or the POST may no-op with a clean flash message

### Scope decision

This is an operational cleanup affordance, not a retry engine. Keep it narrow and explicit.

## 4. Membership package model

This is the structural product shift.

### Old model

Membership packages currently gate infrastructure pieces:

- allowed slave groups
- allowed client groups
- allowed sidecar points

### New model

Membership packages should gate **what tenants can actually buy/use**:

- **Allowed Forward Combinations**

The package should no longer expose or sell raw topology pieces directly.

### Package pricing model

Package pricing remains owned by the package, not by the forward combination.

Forward combinations remain routing definitions. Packages remain commercial products.

The package must own:

- base package price
- bandwidth limit / bandwidth pricing
- slave price
- sidecar price

### Recommended pricing fields

Keep existing relevant package pricing where still useful:

- `monthly_base_fee`
- bandwidth-related fields
- traffic-related fields if they still apply

Add explicit package fields for:

- `slave_unit_price`
- `sidecar_unit_price`

These should be package-owned commercial knobs, not combination-owned routing metadata.

### Package edit UI

Replace the three low-level entitlement sections with one package section:

- **Allowed Forward Combinations**

Each choice should render admin-readable combination info:

- tenant label
- optional badge
- optional short description

Remove from package edit UI:

- Allowed Slave Groups
- Allowed Client Groups
- Allowed Sidecar Points

### Service/runtime filtering behavior

`ProvisioningService::availableForwardCombinationsForService(...)` should evolve so visible combinations are determined by package-combination mapping directly.

The package should be the source of truth for commercial entitlement.

### Migration stance

Do not force a risky one-shot data migration.

Recommended migration approach:

- admin UI moves fully to package-combination mapping
- backend keeps legacy fields tolerated during migration if needed
- runtime/read paths prefer the new package-combination mapping
- legacy low-level package mappings can be treated as transitional compatibility only

## Architecture diagram

```text
Admin package page
  -> select Allowed Forward Combinations
  -> set package pricing
       - base
       - bandwidth
       - slave
       - sidecar
  -> save package

Tenant service / create-forward
  -> serviceSummary(service)
  -> availableForwardCombinationsForService(service)
  -> package-combination mapping
  -> render only allowed combinations

Admin registration page
  -> registration enabled/disabled
  -> require code toggle
  -> generate code
  -> list/toggle codes

Admin failed-task cleanup
  -> existing failed-task surface
  -> clear all failed tasks
  -> delete failed task rows only
```

## Data model direction

### Forward combination entitlements

Introduce a direct mapping between membership packages and forward combinations.

Recommended table shape:

- `service_plan_forward_combinations`
  - `id`
  - `plan_id`
  - `forward_combination_id`
  - `created_at`
  - `updated_at`

This mirrors the actual commercial relationship much better than deriving visibility through node-group and sidecar intersections.

### Package pricing additions

Recommended package columns:

- `slave_unit_price`
- `sidecar_unit_price`

Keep them on `service_plans`.

## Routes and admin navigation

### Add

- `GET /admin/registration`
- `POST /admin/registration/settings`
- `POST /admin/registration/codes`
- `POST /admin/registration/codes/{id}/toggle`
- `POST /admin/provision-tasks/clear-failed`

### Navigation

Add admin nav item:

- `Registration`

If task cleanup stays on an existing admin diagnostics/dashboard surface, no new nav item is required for tasks.

## Error handling

### Registration

- if registration is disabled, public register page should clearly say so
- if code is required, public page should clearly say so
- admin settings page should show current mode plainly

### Failed-task cleanup

- if delete fails, show clean admin flash error
- if there are zero failed tasks, do not treat that as an exception

### Package-combination mapping

- if a package has zero allowed combinations, tenant create surfaces should show a clean empty state
- tenant cannot fix that themselves
- admin must attach combinations to the package

## Testing strategy

### Translation coverage

Add regression coverage for:

- locale switcher supports:
  - `en`
  - `zh-TW`
  - `zh-Hans`
- install page exposes Simplified Chinese
- i18n bootstrap supports the three locales
- `zh-TW` / `zh-Hans` files include required keys for the changed admin/package/task surfaces

### Registration page coverage

Add regression coverage for:

- admin registration page route exists
- admin nav includes Registration
- registration page renders:
  - registration enabled/disabled setting
  - require code setting
  - code generation form
  - code list / toggle actions
- `/admin/tenants` no longer carries the registration-management UI sections

### Failed-task cleanup coverage

Add regression coverage for:

- clear-failed route exists
- admin UI exposes Clear All Failed Tasks
- cleanup deletes only `failed` tasks
- pending/running/completed tasks survive
- zero-failed-task cleanup behaves cleanly

### Package model coverage

Add regression and behavior coverage for:

- package edit page renders Allowed Forward Combinations
- package edit page no longer renders allowed slave/client/sidecar entitlement selectors
- package save path persists package-combination mapping
- available combinations for a service are filtered by package-combination mapping
- package pricing fields include:
  - slave price
  - sidecar price

## Rollout plan

### Phase 1

- add locale support and translation files
- add dedicated Registration page and nav item
- add Clear All Failed Tasks action

### Phase 2

- add package-combination mapping table + save/load paths
- move package UI from low-level entitlement selectors to Allowed Forward Combinations
- add slave/sidecar pricing fields

### Phase 3

- switch service/package forward-combination filtering to the new mapping
- keep transitional compatibility only as needed during migration

## Success criteria

- panel supports `en`, `zh-TW`, and `zh-Hans`
- Traditional Chinese is complete for the affected surfaces
- admin registration management has its own page + nav item
- admin can clear all failed tasks explicitly
- membership packages select allowed forward combinations directly
- membership packages can price bandwidth, slave, and sidecar separately
- tenant-visible combination choices come from package-combination mapping, not raw infrastructure permission bundles

## Final design summary

This design cleans up three places where the panel is still half-transitioned. Localization becomes a real 3-locale system. Registration management becomes a first-class admin area instead of a tenants-page appendix. Membership packages stop selling hidden infrastructure pieces and start selling actual tenant-facing route products through Forward Combinations, with package-owned commercial pricing for bandwidth, slave usage, and sidecar usage.
