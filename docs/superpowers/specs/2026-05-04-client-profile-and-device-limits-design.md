# Client profile and device limits design

Date: 2026-05-04
Status: Approved for planning

## Outcome

Add two explicit tenant entitlements:

1. how many independent client profiles a tenant may create
2. how many devices may use the same client profile

Also improve the admin plan save failure around forward combinations so the panel shows a clear operator-facing message instead of the raw `invalid_plan_forward_combination_payload` error.

## Why

The platform already has a per-client runtime device limit through `clients.online_device_limit`, but there is no clear hard cap for how many independent client profiles a tenant may create.

That makes these two ideas easy to confuse:

- one client profile used by many devices
- many separate client profiles used independently

The admin plan page also fails with a raw error when no active forward combination is selected. That is correct validation, but bad operator UX.

## Scope

In scope:

- add a hard cap for independent client profile count
- keep and clarify the existing per-client device limit model
- expose both limits clearly in admin and tenant UI
- enforce the client-profile count cap when creating new tenant-owned client profiles
- grandfather existing usage when limits are lowered later
- improve admin-facing error handling for invalid forward-combination selection

Out of scope:

- rewriting the runtime online-device enforcement model
- retroactively rewriting existing client limits when plan values change
- deep service-to-client binding tables
- per-tenant custom overrides beyond plan/service defaults

## Current state

### Existing per-client device limit

Current runtime enforcement already exists through `clients.online_device_limit` and `ClientOnlineSessionService`.

Relevant files:

- `master/src/Support/ClientOnlineSessionService.php`
- `master/src/Support/ProvisioningService.php`
- `master/src/Controllers/TenantController.php`

### Existing plan-side client settings

Plans already expose billing-side client pricing:

- `included_client_count`
- `extra_client_price`
- `default_client_online_device_limit`

These appear on the admin plan form and the tenant storefront package card.

### Existing bug in plan save UX

`ProvisioningService::savePlan()` throws `invalid_plan_forward_combination_payload` when:

- no forward combination is selected
- or a selected combination is inactive / invalid

That validation is correct, but the admin currently sees the raw error message.

## Design

### 1. Two separate entitlements

The system will treat these as separate concepts.

#### A. Max client profiles

A hard cap for how many independent client profiles the tenant may create.

Example:

- max client profiles = 1
- tenant may create only one independent client profile

#### B. Devices per client profile

A per-client-profile device cap.

Example:

- devices per client profile = 5
- the same client profile may be installed on up to five devices

This keeps the model aligned with your example:

- one client profile, five devices
- or several client profiles, each with its own device limit

### 2. Enforcement scope

The new client-profile count cap will apply per purchased service / package entitlement model, but to avoid a large structural refactor, the first implementation will enforce it against the tenant-owned self-managed client pool used by the tenant client flow.

In practice, that means:

- creating a new tenant-owned client profile checks the active plan/service entitlement limit
- if the tenant is already at the allowed count, creation is rejected
- existing clients continue to work

This is intentionally a smaller and safer implementation than adding a full dedicated service-to-client-slot mapping layer.

### 3. Grandfathering rule

If the admin later lowers either:

- max client profiles
- default devices per client profile

then:

- existing clients and existing devices keep working
- only new additions are blocked

So this is an additive gate, not a destructive reconciliation pass.

### 4. Data model

Add one new plan-level field:

- `max_client_profiles INT NOT NULL DEFAULT 1`

Keep using the existing field:

- `default_client_online_device_limit INT NOT NULL DEFAULT 1`

Roles of the fields:

- `max_client_profiles`: hard entitlement cap for independent client profiles
- `default_client_online_device_limit`: default device cap per client profile

The existing per-client runtime source of truth remains:

- `clients.online_device_limit`

### 5. Creation behavior

When a tenant creates a new client profile:

1. count tenant-owned self-managed client profiles
2. compare count against the active plan/service entitlement `max_client_profiles`
3. if count is already at or above limit:
   - reject create
   - show a clear message like `This package allows up to X client profiles.`
4. if create is allowed:
   - initialize new client `online_device_limit` from the effective package default when no explicit override is provided

### 6. Edit behavior

For this iteration:

- existing clients keep their own `online_device_limit`
- changing a plan default does not automatically rewrite existing clients
- tenant/client edit flow may continue to expose client-level device limit unless later policy says it should be locked down

This avoids a risky hidden rewrite of active client records.

### 7. Admin plan UI

Add an explicit field on `/admin/plans`:

- `Max Client Profiles`

Keep the existing field:

- `Client Device Limit`

Helper copy should make the distinction explicit:

- `Included Client Count`: billing included count before extra client charges
- `Max Client Profiles`: hard creation cap for independent client profiles
- `Client Device Limit`: default devices allowed per client profile

This matters because billing and entitlement are not the same thing.

### 8. Tenant package card UI

Each tenant storefront package card should show both limits clearly:

- `Client Profiles: X`
- `Devices per Client: Y`

The goal is that a tenant can immediately tell the difference between:

- how many different client configs they can own
- how many devices may share one config

### 9. Tenant client page UI

Add a summary area near the top of `/tenant/clients`:

- current client profiles used / max client profiles

Keep each client card showing:

- active devices / online-device limit

When the tenant is at the profile cap:

- disable or hide the create-client form
- show a clear limit message
- still allow editing or viewing existing clients

### 10. Admin plan save error UX

When `savePlan()` hits invalid forward-combination payload, the UI should not show the raw internal code.

Instead, map it to a clear operator-facing message, such as:

- `Select at least one active forward combination before saving the plan.`

This fix should cover both cases:

- no forward combination selected
- selected combination became inactive or invalid

The validation itself stays strict. Only the operator-facing message improves.

## Data flow

### Tenant create-client flow

1. tenant opens `/tenant/clients`
2. system computes current owned client count
3. system computes allowed `max_client_profiles`
4. page shows usage summary
5. on client create:
   - if below cap, save client
   - if at cap, reject create with clear flash error

### Device-install/runtime flow

No structural change.

Existing device-limit enforcement continues to use:

- `clients.online_device_limit`

### Admin plan save flow

1. admin edits plan
2. submits `max_client_profiles` and device-limit defaults
3. `savePlan()` persists both fields
4. if forward-combination validation fails, admin sees clear message instead of raw payload code

## Schema changes

Add to `service_plans`:

- `max_client_profiles INT NOT NULL DEFAULT 1`

Backfill behavior:

- existing plans default to `1`

No change required to `clients.online_device_limit`, since it already exists.

## Error handling

### Tenant side

When profile cap is reached:

- reject only new client creation
- keep existing clients editable/viewable
- show friendly limit message

### Admin side

When forward combinations are missing/invalid:

- keep save blocked
- replace raw `invalid_plan_forward_combination_payload` with a friendly action message

## Testing plan

Add or extend regression coverage for:

1. admin plan page contains `max_client_profiles`
2. tenant storefront card renders both client-profile count and per-client device count labels
3. tenant client page contains profile usage summary
4. tenant client create path checks max profile cap
5. existing `online_device_limit` runtime wiring remains intact
6. lowering limits does not imply destructive auto-rewrite logic
7. admin plan save path maps invalid forward-combination payload to a human message

## Risks and mitigations

### Risk: billing count vs hard cap confusion

Mitigation:

- explicit helper copy on admin plan page
- distinct labels in tenant storefront and client page

### Risk: service-scoping ambiguity for client profiles

Mitigation:

- first implementation enforces against the tenant-owned self-managed client pool used by current tenant flow
- defer deeper service-slot modeling until actually needed

### Risk: breaking existing customers when limits shrink

Mitigation:

- grandfather existing usage
- block only future additions

## Files expected to change

Primary targets:

- `master/migrations/schema.sql`
- new migration for `max_client_profiles`
- `master/src/Support/ProvisioningService.php`
- `master/src/Controllers/AdminController.php`
- `master/src/Controllers/TenantController.php`
- regression tests under `master/tests/`

## Acceptance criteria

- admin can set both `max_client_profiles` and `Client Device Limit`
- tenant package cards show both limits clearly
- tenant client page shows profile usage summary
- tenant cannot create more client profiles than allowed
- existing over-limit usage continues working after limits are lowered
- admin sees a clear forward-combination validation message instead of raw `invalid_plan_forward_combination_payload`