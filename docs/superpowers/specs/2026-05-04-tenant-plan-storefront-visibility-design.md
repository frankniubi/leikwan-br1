# Tenant plan storefront and internal-test visibility design

Date: 2026-05-04
Status: Approved for planning

## Outcome

Replace the tenant package purchase page with a card-style storefront that looks closer to the provided screenshot, keep purchase as an inline card expansion instead of immediate checkout, and add an admin-only visibility control so plans can stay active internally while being hidden from tenant self-purchase.

## Why

The current tenant plans page is still a control panel table plus a generic purchase form. It works, but it does not sell the package clearly.

Admins also need a way to keep a plan active for internal testing or manual workflows without exposing it to tenants as a public self-purchase option.

## Scope

In scope:

- Redesign tenant `/tenant/plans` purchase area into a storefront card grid.
- Reuse existing `service_plans.description` as tenant-visible feature copy.
- Change admin plan `Description` editing from single-line input to textarea.
- Add plan visibility flag for tenant storefront exposure.
- Add plan-level client device limit field and display it on tenant package cards.
- Keep existing current-services table below the new storefront.
- Add regression coverage for the new filtering and markup wiring.

Out of scope:

- Full visual parity with every pixel or label in the reference image.
- New WYSIWYG package-content editor.
- Reworking the existing purchase endpoint or billing engine.
- Rebinding existing tenant clients automatically to plan device-limit changes.

## Current state

### Tenant plans page

Current tenant purchase UX is implemented in `master/src/Controllers/TenantController.php` inside `plansPage()`. It renders:

- summary cards
- one generic purchase form
- one table listing available packages
- one table listing current services

### Admin plans page

Current admin plan UX is implemented in `master/src/Controllers/AdminController.php` inside `plansPage()`. It already supports:

- active vs inactive status
- pricing and billing fields
- allowed forward combinations
- feature flags

### Purchasable plan filter

`master/src/Support/ProvisioningService.php` currently exposes purchasable plans via `listPurchasablePlans()`, which filters only on `is_active = 1`.

## Design

### 1. Tenant storefront layout

Tenant `/tenant/plans` becomes a storefront-first page.

The top of the page keeps the existing high-level summary cards, then replaces the current purchase form + package table with a package card grid.

Each package card contains:

- package name
- feature list derived from `service_plans.description`
- prominent monthly price block
- included traffic
- included client count
- client device limit
- smaller pricing detail row for add-ons or reset pricing when helpful
- `Order Now` action

The tenant current-services table remains below the storefront, so the page still works as both a sales surface and a service overview.

### 2. Card content rules

The existing `service_plans.description` field becomes the card body source.

Rendering rule:

- split on line breaks
- trim whitespace
- ignore empty lines
- render each remaining line as one visible feature line in the card body

Admin guidance will explicitly tell operators to enter one feature per line.

If description is empty, the tenant card shows a muted fallback such as `No package details provided yet.`

### 3. Inline purchase expansion

Clicking `Order Now` does not immediately submit a purchase.

Instead, the selected card expands inline and shows a compact purchase form with:

- hidden `plan_id`
- optional `service_name`
- optional `bandwidth_limit_mbps`
- purchase submit button
- cancel/collapse action

Implementation preference:

- use server-rendered expansion keyed by query state such as `?buy=<planId>#plan-<planId>`
- avoid relying on JavaScript for the core expand/collapse flow
- keep only one card expanded at a time by design

This keeps the UX simple, bookmarkable, and easy to preserve across redirects and flash errors.

### 4. Purchase form behavior

The expanded inline form reuses the existing purchase POST route and purchase service logic.

No business-logic duplication in client-side code.

Validation and errors stay server-side. On purchase failure, redirect should preserve the selected card expansion so the tenant does not lose context.

### 5. Package visibility model

Add a new plan-level boolean field:

- `is_tenant_visible TINYINT(1) NOT NULL DEFAULT 1`

Meaning:

- `1`: visible on tenant storefront if the plan is also active
- `0`: hidden from tenant storefront, but still available for admin/internal/manual use

Approved behavior:

- hidden plans are not shown to tenants on `/tenant/plans`
- hidden plans do not break existing purchased services
- admin can still see and edit hidden plans
- active hidden plans remain valid for non-tenant-public workflows

Tenant self-purchase filter becomes:

- `is_active = 1`
- `is_tenant_visible = 1`

### 6. Client device limit model

Add a new plan-level integer field:

- `default_client_online_device_limit INT NOT NULL DEFAULT 1`

Purpose in this iteration:

- admin can set the advertised package device limit
- tenant storefront displays the device limit on the package card
- the field is persisted as part of plan configuration

Important boundary:

- runtime enforcement today still lives on client records through `clients.online_device_limit`
- this plan field does not retroactively rewrite existing client limits in this iteration
- existing per-client `online_device_limit` remains authoritative after a client exists

This is intentional. A tenant can own multiple services and multiple clients, while the current runtime limit is per client, not per service. Auto-overwriting all existing clients from plan purchases would be ambiguous and risky.

### 7. Admin plan editor changes

Admin `/admin/plans` gets three UX changes.

#### Description field

Change `Description` from single-line text input to textarea.

Helper copy:

- one line per tenant-visible feature
- these lines render directly on the tenant purchase card

#### Tenant visibility field

Add a plan editor field such as:

- `Tenant Visibility`
  - `Visible to tenants`
  - `Internal test package`

The plan list also gets a visibility badge or column so admins can distinguish:

- active public plan
- active hidden/internal plan
- inactive plan

#### Client device limit field

Add a numeric field:

- `Client Device Limit`

Helper copy should explain:

- this is the package¡¯s tenant-visible default entitlement value
- per-client online enforcement still lives on each client record

### 8. Tenant empty state

If no plans satisfy the tenant storefront filter, show a clean empty state message instead of an empty package table.

Example intent:

- no visible packages available right now
- contact the administrator if you expected to self-purchase

### 9. Styling direction

The storefront should take cues from the reference image:

- clean white cards
- soft card border
- large plan title
- feature list aligned on the left
- price block aligned to the right or upper-right area on larger screens
- strong green primary action button
- 2-column grid on desktop
- 1-column stack on narrow screens

This is directional, not a pixel clone. The page should still feel like the existing panel.

## Data flow

### Tenant GET flow

1. tenant opens `/tenant/plans`
2. controller asks `ProvisioningService::listPurchasablePlans()`
3. service returns plans filtered by `is_active` and `is_tenant_visible`
4. controller renders storefront cards and current-services table
5. if query includes selected plan id, that card renders its inline form expanded

### Tenant POST flow

1. tenant submits inline purchase form
2. existing tenant purchase endpoint receives `plan_id`, optional `service_name`, optional `bandwidth_limit_mbps`
3. `purchaseFlexibleService()` performs existing validation and purchase logic
4. success redirects to the new service detail
5. failure redirects back to `/tenant/plans?buy=<planId>#plan-<planId>` with flash message

### Admin plan edit flow

1. admin edits plan in `/admin/plans`
2. form submits existing plan save route
3. save flow persists new fields:
   - `is_tenant_visible`
   - `default_client_online_device_limit`
4. description persists as multi-line text
5. plan list reflects active/inactive plus public/internal visibility state

## Schema changes

Add to `service_plans`:

- `is_tenant_visible TINYINT(1) NOT NULL DEFAULT 1`
- `default_client_online_device_limit INT NOT NULL DEFAULT 1`

Backfill behavior:

- existing plans default to tenant-visible
- existing plans default to device limit `1`

## Error handling

### Tenant side

- hidden or inactive plans must not appear on the storefront
- direct POST purchase to a hidden/inactive plan should still be rejected server-side by authoritative plan validation
- failed purchase returns to the same plan card expansion with flash error

### Admin side

- invalid numeric values keep existing validation style
- description can be blank
- device limit must be at least `1`
- visibility toggle must always persist explicitly

## Testing plan

Add or extend regression coverage for:

1. `ProvisioningService::listPurchasablePlans()` excludes plans with `is_tenant_visible = 0`
2. admin plans page contains tenant visibility control
3. admin plans page uses textarea for plan description
4. tenant plans page renders package storefront card structure
5. tenant plans page includes inline purchase markers instead of only one generic purchase form
6. tenant package card renders included client count
7. tenant package card renders plan-level client device limit
8. purchase flow wiring remains on the existing POST route

Also run targeted syntax checks for touched PHP files.

## Risks and mitigations

### Risk: package description formatting is messy

Mitigation:

- line-based parsing only
- ignore blank lines
- keep admin helper copy explicit

### Risk: confusion between included client count and device limit

Mitigation:

- label both separately in admin and tenant UI
- helper copy explains the difference

### Risk: hidden internal plans still purchased by crafted POST

Mitigation:

- keep authoritative backend validation aligned with storefront filter, not just GET rendering

## Files expected to change

Primary targets:

- `master/src/Controllers/TenantController.php`
- `master/src/Controllers/AdminController.php`
- `master/src/Support/ProvisioningService.php`
- `master/migrations/schema.sql`
- new migration for plan visibility and device-limit columns
- regression tests under `master/tests/`

## Acceptance criteria

- tenant sees package cards instead of package table for self-purchase
- `Order Now` opens inline purchase form in the selected card
- admin can hide a plan from tenant storefront while keeping it active internally
- admin can edit package card copy with existing description field as multi-line text
- tenant storefront shows included client count and client device limit
- existing services continue working after a plan is hidden
- regression tests cover filtering and UI wiring