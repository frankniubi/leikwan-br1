# Tenant Default Client Design

## Goal

Ensure every tenant always has one real shared default client, created immediately at registration time, so tenant forwards have a deterministic client landing point and Linux client-agent install has a clean target from day one.

This change must also repair older data:

- create one default client for tenants that do not have any client yet
- mark one existing owned client as default when a tenant has clients but no default marker
- associate only currently unbound forwards to the tenant default client

## Current Reality

Today the panel has an awkward gap:

- tenant registration creates a `users` row, but does not create a `clients` row
- tenant forward creation no longer exposes a specific client selector in the tenant UI
- when no explicit client is provided, backend forward creation falls back to the first available client for the service
- if the tenant has no client at all, the flow becomes confusing because there is no clean default landing point

Relevant current behavior:

- `master/src/Support/TenantRegistrationService.php` creates or updates the tenant user only
- `master/src/Support/ProvisioningService.php::availableClientsForService()` returns active clients visible to the service
- `master/src/Support/ProvisioningService.php::createForward()` currently auto-picks the first available client when no explicit client or group binding is provided
- `master/src/Support/ProvisioningService.php::assignedForwardConfigsForClient()` returns all forwards matched to a client by explicit client id or client group matching
- `master/src/Controllers/ClientAgentController.php` and `client-agent/client_agent.py` make the client-agent pull and apply every forward assigned to that client

## Product Decision

Each tenant gets exactly one shared default client.

This is a real `clients` row, visible in `/tenant/clients`, not a virtual placeholder.

That default client is:

- created immediately when the tenant is created
- reused across all tenant-created forwards unless a more explicit binding is chosen elsewhere
- the fallback landing point for previously unbound forwards during backfill

## Alternatives Considered

### Option A: Explicit default client marker on `clients`

Chosen.

Store the default-client truth on the `clients` row itself, using an explicit flag.

Why this wins:

- clear source of truth
- easiest to reason about in support and debugging
- supports multi-client tenants cleanly later
- keeps the rule close to the resource that is actually the default

### Option B: Store `users.default_client_id`

Rejected.

This is explicit, but more fragile. If the pointed client is deleted or changed, the user row becomes stale. The pointer also lives away from the actual client lifecycle logic.

### Option C: Infer the default from sort order or naming

Rejected.

This is fast to code and bad to live with. It is brittle, hard to explain, and guaranteed to create future bugs when client ordering changes.

## Chosen Data Model

Add to `clients`:

- `is_default_for_owner TINYINT(1) NOT NULL DEFAULT 0`

Recommended supporting index:

- `(owner_user_id, is_default_for_owner, status)`

## Invariants

For tenant-owned clients:

- a tenant may have at most one default client
- the default client must have `owner_user_id = tenant.id`
- admin-shared clients with `owner_user_id = 0` can never be default for a tenant
- the system should never intentionally leave a tenant with zero usable default clients

The app layer enforces uniqueness and repair logic. This design does not depend on a complicated SQL unique constraint for partial rows.

## Default Client Lifecycle

### Registration

When a tenant is created through self-registration:

1. create or update the tenant `users` row
2. immediately ensure the tenant has one default client
3. if none exists, create it
4. if one already exists, reuse it

This step must be idempotent. Repeated registration attempts for the same tenant must not create duplicate default clients.

### Admin-created tenants

When an admin creates a tenant manually:

1. save the tenant user
2. immediately ensure the tenant has one default client

This keeps the rule universal. Every tenant gets a default client regardless of creation path.

### Existing tenants

Backfill must normalize older data so every tenant ends up with exactly one default client.

## Default Client Creation Rules

When a new default client is created, use a normal real client row:

- `owner_user_id = tenant.id`
- `is_default_for_owner = 1`
- `status = active`
- name: `Default Client`
- `display_host`: deterministic generated value that satisfies current client schema

The generated `display_host` should be deterministic and stable, for example derived from tenant id or a sanitized tenant/email label. The exact string is less important than being valid, unique enough for the existing UI, and not misleadingly tied to routing behavior.

## Backfill Rules

Backfill must be conservative.

### If a tenant has no owned clients

- create one default client

### If a tenant has owned clients but no default marker

- choose one existing owned client as default

Deterministic selection rule:

1. prefer active owned clients
2. choose the oldest owned client by `id ASC`

This is safer than picking the newest client and avoids update-timestamp drift.

### If a tenant has multiple default markers

- normalize back to one default client
- keep the preferred one by the same deterministic rule
- clear the marker from the others

### Renames

Do not aggressively rename existing tenant clients during backfill. If an existing client becomes the default, only mark it. Preserve tenant-chosen names where possible.

## Forward Binding Behavior Going Forward

When tenant flow creates a forward and no explicit client or client-group binding is supplied:

- resolve the tenant default client
- write `line_client_id = default_client.id`

This replaces the current fallback that implicitly picks the first available client returned by `availableClientsForService()`.

Explicit bindings continue to win:

- if `line_client_id` is provided, keep it
- if `line_client_group_id` is provided, keep it
- if `line_client_group_ids_json` is provided, keep it

Only the fully unbound case falls back to the tenant default client.

## Existing Forward Reassociation Rules

Per the chosen safety rule A, only patch forwards that are effectively unassigned.

### Reassociate these forwards

For a tenant-owned service, set `line_client_id = default_client.id` only when all of these are true:

- `line_client_id IS NULL`
- `line_client_group_id IS NULL`
- `line_client_group_ids_json` is null, empty, or `[]`

### Do not touch these forwards

Leave existing forwards unchanged if any of these are true:

- `line_client_id` is already set
- `line_client_group_id` is already set
- `line_client_group_ids_json` contains any group ids

This preserves explicit earlier routing choices and avoids stealing forwards from existing client-group layouts.

## Why This Matches Runtime Truth

`assignedForwardConfigsForClient()` currently matches forwards by:

1. exact `line_client_id`
2. `line_client_group_id`
3. membership in `line_client_group_ids_json`

That means once unbound forwards are reassigned to the default client:

- the client-agent for that default client will pull them naturally
- Windows and Android app sign-in with that client id and token remain consistent
- Linux client-agent install on that default client becomes the obvious operational landing point

## Delete Behavior

The tenant should never be left without a valid default client by accident.

### Deleting a non-default client

Normal delete behavior.

### Deleting the default client

If another owned client exists:

- promote one replacement client to default
- use the same deterministic rule:
  - prefer active
  - oldest by `id ASC`

If no other owned client exists:

- block deletion

This prevents the tenant from silently falling back into the broken "no client exists" state.

## Core Backend Helpers

Add focused helpers in `master/src/Support/ProvisioningService.php`:

- `defaultClientForUser(int $userId): ?array`
- `ensureDefaultClientForUser(int $userId): array`
- `createDefaultClientForUser(int $userId, array $user): array`
- `markClientAsDefaultForUser(int $userId, int $clientId): void`
- `backfillDefaultClientsForUsers(?int $userId = null, bool $apply = false): array`
- `assignUnboundForwardsToDefaultClient(?int $userId = null, bool $apply = false): array`

These helpers let registration, admin tenant creation, forward fallback, and repair tooling all share one source of truth.

## Files Expected To Change

### Schema and migrations

- `master/migrations/schema.sql`
- new migration for `clients.is_default_for_owner`

### Registration / tenant creation

- `master/src/Support/TenantRegistrationService.php`
- `master/src/Controllers/AdminController.php`

### Core binding logic

- `master/src/Support/ProvisioningService.php`

### Optional CLI repair entrypoint

- new script such as `master/scripts/backfill-default-clients.php`

### Regression tests

- new tests under `master/tests/`

## Backfill CLI Shape

Add a dedicated repair script:

- `master/scripts/backfill-default-clients.php`

Recommended flags:

- `--user-id=<id>` optional narrow repair
- `--dry-run`
- `--apply`

### Dry-run output should report

- tenants missing a default client
- tenants with multiple defaults
- tenants that will get a new client created
- tenants whose existing client will be marked default
- unbound forwards that will be attached to the default client

### Apply mode should do two phases

1. normalize default client state for tenants
2. associate only unbound forwards to the tenant default client

This gives ops a safe repair path for older installs.

## Test Plan

Add regression coverage for at least:

- self-registration creates a default client
- admin-created tenant creates a default client
- repeated registration does not create duplicate defaults
- backfill creates a default client for tenants with none
- backfill marks one existing owned client as default when none marked
- backfill normalizes multiple defaults down to one
- tenant forward creation with no explicit binding uses the default client
- forwards with explicit `line_client_id` remain untouched
- forwards with explicit `line_client_group_id` remain untouched
- forwards with explicit `line_client_group_ids_json` remain untouched
- deleting a default client promotes another client when possible
- deleting the only default client is blocked

## Rollout Order

1. add schema field and migration
2. add provisioning helpers
3. hook registration path
4. hook admin tenant-create path
5. replace tenant forward fallback with default-client resolution
6. add backfill CLI
7. run dry-run in production
8. run apply after review

## Non-Goals

This design does not include:

- reintroducing a tenant-facing specific client picker in the first step
- changing existing explicit client or client-group forward bindings
- inventing a virtual client slot or hidden placeholder
- redesigning Windows or Android app sign-in behavior

## Main Risk

The biggest risk is over-eager backfill touching forwards that were intentionally group-bound.

This is why the reassociation rule is strict:

- only patch fully unbound forwards
- leave every explicit binding alone

## Final Product Statement

Every tenant gets one real shared default client immediately. New tenant forwards bind to that client when no more explicit binding exists, old tenants are backfilled safely, and only truly unbound old forwards are reassociated. This removes the current clientless gap without rewriting existing deliberate client-routing choices.
