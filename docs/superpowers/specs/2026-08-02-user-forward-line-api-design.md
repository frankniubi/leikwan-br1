# User Forward Line API Design

## Goal

Give an authenticated tenant a stable API for listing, creating, reading, changing, and deleting that tenant's forward rules without exposing the panel's internal node, Sidecar, LeiGod line, or forward-combination topology.

This design treats a "forward line" as the existing `forwards` resource owned through `forwards.service_id -> services.user_id`. It does not let tenants manage the administrator-owned `leigod_lines` catalog.

## Context

The panel already has unversioned user endpoints for create, non-structural update, delete, and client-config retrieval. They are not a complete resource API:

- there is no dedicated safe list or detail response;
- write endpoints do not consistently enforce the tenant Web UI's feature flags;
- create accepts a broad payload before `ProvisioningService` resolves it;
- create and update return runtime client configuration instead of a management resource;
- retries can create duplicate rules;
- concurrent edits can silently overwrite one another;
- `GET /api/user/services/{id}` returns the broad internal `serviceSummary()` shape;
- a plan with no route mapping currently falls back to every active route;
- the broad legacy create payload can select internal placement fields;
- host strings reach generated root-owned systemd units without a shared strict host validator;
- create can insert a forward before discovering that no endpoint is available, leaving partial state.

The existing `forward_routes` catalog remains the tenant-facing product boundary. Tenants select a route by public route ID and never submit a node, Sidecar, LeiGod line, forward combination, CDN host, path prefix, public port, or runtime mode.

Route visibility becomes fail-closed: an active route is selectable only when the service plan has an explicit `service_plan_forward_routes` row for it. A missing mapping returns no routes rather than all active routes.

## Considered Approaches

### 1. Extend the unversioned API in place

This has the smallest route diff, but adding required idempotency and conditional-write headers would break unknown clients. Keeping the old permissive behavior would retain the most important weaknesses.

### 2. Add a strict v1 resource API and harden the legacy compatibility layer

This is the selected approach. New clients get an explicit contract with safe representations, idempotent create, and optimistic concurrency. Existing routes remain available, but their ownership, service-state, feature-policy, and input allowlist checks are tightened so they cannot bypass the v1 rules.

### 3. Add a bulk declarative sync endpoint

A client could submit the complete desired set and let the server calculate creates, updates, and deletes. This is convenient for automation, but one malformed or stale document could remove many live forwards. It also needs a larger transaction, dry-run protocol, and more complex authorization. It is out of scope for the first secure API.

## Resource API

All endpoints require `Authorization: Bearer <user-api-token>`. Query-string user tokens are not accepted.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/user/services/{service_id}/forward-options` | List the service's public route choices and mutation capabilities. |
| `GET` | `/api/v1/user/services/{service_id}/forwards` | List active forward rules owned by the authenticated tenant. |
| `POST` | `/api/v1/user/services/{service_id}/forwards` | Create one forward rule. Requires `Idempotency-Key`. |
| `GET` | `/api/v1/user/forwards/{forward_id}` | Read one owned active forward rule. |
| `PATCH` | `/api/v1/user/forwards/{forward_id}` | Partially update one rule. Requires `If-Match`. |
| `DELETE` | `/api/v1/user/forwards/{forward_id}` | Soft-delete one rule. Requires `If-Match`. |

The API uses `application/json`. Mutation request bodies are limited to 16 KiB. Successful resource responses include `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, and an `ETag` on single-resource responses.

### Forward options

The options response contains only:

- supported transports: `tcp`, `udp`, and `tcp+udp`;
- the configured active-forward limit and current active count;
- capabilities derived from `allow_manage_forwards` and `allow_delete_forwards`;
- route `id`, tenant label, tenant description, tenant badge, and per-transport `available`, `healthy_node_count`, and stable `unavailable_reason` values.

It must not include combination IDs, provider line IDs, node IDs or addresses, candidate records, configuration hashes, SOCKS ports, health errors, or raw route database fields.

### Create request

```json
{
  "route_id": 14,
  "listen_port": null,
  "transport": "tcp",
  "target": {
    "source": "manual",
    "host": "10.0.0.5",
    "port": 443
  }
}
```

`listen_port: null` asks the server to allocate a port. `target.source` is either `manual` or `service_ip`. A `manual` target requires `host` and `port`; `service_ip` requires an owned `service_id` and `port`. Unknown fields are rejected instead of ignored.

`Idempotency-Key` is required and must contain 8-64 ASCII letters, digits, `.`, `_`, `:`, or `-`. The server stores only its SHA-256 hash plus a hash of the normalized request. Repeating the same key and normalized request returns the original resource. Reusing the key with a different request returns `409 idempotency_conflict`.

### Patch request

```json
{
  "route_id": 18,
  "transport": "tcp+udp",
  "target": {
    "source": "manual",
    "host": "backend.example.com",
    "port": 8443
  }
}
```

Every field is optional, but at least one mutable field must be present. A route, target-source, transport, or automatic listen-port change uses the existing structural update path and recreates endpoints transactionally after route availability validation. A target-only or explicit nonzero listen-port change uses the narrower update path.

The client sends the exact `ETag` returned by the last detail, create, or patch response in `If-Match`. List items expose a revision but not an item-specific response header, so clients fetch detail before mutating. Missing preconditions return `428 precondition_required`; stale values return `412 forward_version_conflict` before any endpoint is retired.

Target patches preserve the current source unless `target.source` is present. Switching from `manual` to `service_ip` requires an owned `service_id` in the same patch and discards the stale manual host. Switching back requires an explicit manual host and clears the service ID. A caller can never patch the resolved host of a `service_ip` target. `listen_port: null` requests a new automatic allocation and therefore uses the structural update path.

### Delete

Delete is a soft delete through the existing retirement protocol. A successful delete returns `204`. Repeating a delete after the row becomes inactive returns `404 forward_not_found`; another tenant receives the same response.

## Safe Forward Representation

```json
{
  "id": 123,
  "service_id": 42,
  "route": {
    "id": 14,
    "label": "Smart Route"
  },
  "listen_port": 11024,
  "transport": "tcp",
  "target": {
    "source": "manual",
    "host": "10.0.0.5",
    "port": 443,
    "service_id": null
  },
  "ingress": {
    "host": "edge.example.com",
    "port": 443
  },
  "state": "active",
  "endpoint_count": 1,
  "active_endpoint_count": 1,
  "revision": 3,
  "created_at": "2026-08-02 03:00:00",
  "updated_at": "2026-08-02 03:05:00"
}
```

The representation never contains `forward_combination_id`, `active_combination_id`, internal backend/client mode, node group, node identity, node address, Sidecar fields, path prefix, SNI, configuration material, health error text, client token, or API token. Runtime client configuration remains a separate endpoint with its existing purpose and authorization.

Safe service, route, and forward representations are constructed from explicit allowlists. They are not produced by copying `serviceSummary()` and removing a known set of fields, because new internal columns would otherwise become tenant-visible by default.

## Persistence and Concurrency

Add these nullable/defaulted fields to `forwards`:

- `api_revision BIGINT UNSIGNED NOT NULL DEFAULT 1`;
- `api_idempotency_key_hash CHAR(64) NULL`;
- `api_request_hash CHAR(64) NULL`;
- `user_deleted_at DATETIME NULL`;
- `balance_suspended_at DATETIME NULL`.

Add a unique index on `(service_id, api_idempotency_key_hash)`. Existing rows remain valid because MySQL permits multiple `NULL` values in a unique index.

All create, update, and delete paths increment or initialize `api_revision`. The ETag is `"forward-{id}-r{revision}"`; it contains no secret. Idempotency lookup is scoped by the already-authorized service ID.

Create, structural update, non-structural update, and delete each run in one database transaction, including endpoint retirement/creation and provisioning-task enqueue. Validation or placement failure rolls back the forward row, endpoints, tasks, audit event, and revision change together. The API checks an ETag before entering the transaction, then verifies the revision again inside the transaction before mutation. Delete sets `user_deleted_at` and clears any balance marker. Billing suspension sets `balance_suspended_at`; account reactivation requires that marker, excludes tombstones, reapplies tenant status, balance, policy, and quota under lock, clears the marker, and increments the revision. Historical disabled rows without forward-level evidence remain unclassified and cannot be restored automatically. Nested transactions use savepoints. InnoDB deadlocks are never retried one statement at a time inside an aborted transaction; the owning transaction retries its complete callback.

## Authorization and Policy

Every operation follows this order:

1. Authenticate an active tenant API user.
2. Load the service or forward through a query constrained by that user's ID.
3. Return `404` for missing and cross-tenant resources to avoid an object-existence oracle.
4. For writes, require service status `active`.
5. Enforce `allow_manage_forwards` for create/update and `allow_delete_forwards` for delete.
6. Lock the service row and recheck owner, active user, active service, feature policy, idempotency, and the configured active-forward limit inside the create transaction.
7. Resolve `route_id` only through the fail-closed `availableForwardRoutesForService()` allowlist.
8. Let `ProvisioningService` perform the final route, target-service ownership, health, port, and endpoint realization checks.

The legacy `/api/user/...` create, update, and delete handlers receive the same ownership, active-service, feature-policy, forward-count, and payload-allowlist checks. They do not gain access to v1-only internal fields.

## Input Validation

- JSON values use strict types; numeric strings are rejected in v1.
- Ports are integers from 1 through 65535; only `listen_port` may be `null` for automatic allocation.
- Manual hosts are ASCII IPv4, IPv6, or DNS host names up to 253 characters.
- Hosts containing a URL scheme, userinfo, path, whitespace, control bytes, shell metacharacters, or an IPv6 zone identifier are rejected.
- Route and service IDs are positive integers.
- V1 transport values and numeric fields use exact JSON types and are never silently coerced. Deprecated legacy routes canonicalize historical decimal-string IDs/ports plus case-insensitive and `udp+tcp`/`both` transport aliases.
- Unknown top-level and nested fields return `422 unknown_fields`.
- V1 never accepts client-supplied `endpoint_count`; deprecated legacy routes accept and ignore that historical field. Neither surface accepts `public_port`, `cdn_host`, `node_group`, `forward_combination_id`, `backend_mode`, `client_mode`, Sidecar fields, or configuration material.

Private and loopback target addresses remain valid because forwarding to a tenant-owned LAN/backend is the product's purpose. The panel does not make an HTTP request to the target during API validation.

The host/port validator lives in the shared provisioning path and is also used by legacy API and tenant Web form mutations. This prevents a caller from bypassing v1 validation and ensures CR/LF or systemd directive text never reaches the slave unit renderer.

## Errors

Errors use a stable envelope and do not echo submitted values or internal exception details:

```json
{
  "ok": false,
  "error": {
    "code": "validation_failed",
    "message": "The forward request is invalid.",
    "fields": {
      "target.port": "out_of_range"
    }
  }
}
```

Expected statuses are `401` authentication failure, `404` missing/not-owned resource, `409` quota/idempotency/route availability conflict, `412` stale ETag, `415` wrong content type, `422` validation failure, `428` missing required mutation header, and `500` generic operation failure. Unexpected exceptions are logged server-side and never returned verbatim by these endpoints.

## Audit and Abuse Controls

Existing `forward.created`, `forward.updated`, and `forward.deleted` audit events remain authoritative. New user-API calls record the authenticated user ID, service ID, forward ID, action, and revision, without logging bearer tokens, idempotency keys, full targets, request bodies, or client configuration.

The application enforces a configurable `USER_FORWARD_LIMIT_PER_SERVICE` with a conservative default of 64. Service-row serialization prevents concurrent requests with different idempotency keys from exceeding that quota. It also caps all retained forward rows per service with `USER_FORWARD_STORED_LIMIT_PER_SERVICE` (default 512), including soft-deleted rows, so create/delete churn cannot grow forward-owned persistent state without bound. HTTP request-rate limiting remains a reverse-proxy deployment control.

## Compatibility

- Existing `/api/user/...` routes remain registered.
- Existing client-config installers are unchanged.
- Existing Web tenant forms remain available with CSRF protection; service-IP target hosts are rendered as server-managed rather than editable fields.
- Suspended users lose existing Web sessions, and legacy Bearer routes require the active `tenant` role.
- The existing tenant forward Edit link is corrected to the registered service-scoped route.
- Tenant Web edit receives the same `allow_manage_forwards` gate as create.
- Admin plan editing persists an explicit Forward Route allowlist and derives the legacy combination mapping transactionally.
- Route options exclude packages with no enabled active combination, and client-config reads require both an active forward and active service.
- Automatic MySQL migration execution is serialized with an advisory lock; statements use the standalone deadlock retry wrapper and public migration errors contain no SQL or driver message.
- The legacy write routes are marked deprecated in response headers and documentation, but are security-hardened rather than removed.
- The v1 API is the documented integration surface for new clients.

## Verification

Automated tests must prove:

- valid create and partial-patch payload normalization;
- rejection of unknown fields, invalid types, unsafe host syntax, bad ports, and invalid transports;
- safe presentation omits every internal topology and credential field;
- cross-tenant service and forward IDs return `404`;
- inactive services and disabled feature flags block mutations;
- create requires and correctly replays an idempotency key;
- a changed request under the same key returns `409`;
- patch/delete require a current ETag and stale values cause no mutation;
- the active-forward limit blocks the next create;
- route options expose only the approved tenant fields;
- a plan without an explicit route mapping sees zero routes;
- legacy writes apply the same ownership/policy/allowlist gates;
- tenant Web edits enforce the manage-forwards feature and use the registered route;
- CR/LF, unit-directive text, shell metacharacters, URL syntax, and invalid host labels are rejected by the shared provisioning validator;
- placement failure leaves no forward, endpoint, task, audit, or revision change behind;
- migration and canonical schema contain matching columns and indexes;
- all changed PHP files pass `php -l` and focused regression tests pass.

## Non-Goals

- Tenant management of `leigod_lines`, forward combinations, nodes, Sidecars, or credentials.
- Bulk replace/sync of an entire forward set.
- Hard delete or immediate bypass of the retirement protocol.
- Changing client-to-slave-to-Sidecar topology.
- Adding direct client-to-Sidecar connectivity.
- Replacing the current user API-token system with OAuth or scoped personal access tokens in this change.
- Deploying to production without a separate explicit deployment request.
