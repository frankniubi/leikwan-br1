# User Forward Lines API v1

This is the versioned integration surface for an authenticated tenant to manage
that tenant's active forward rules. All IDs and hostnames in this document are
illustrative values, not production identifiers.

A forward may also be created as a **native outbound** forward, whose carrier runs
on the client device instead of the panel ingress. The shared fields are described
here; the full interface and behaviour reference for that type is in
[native-outbound.md](native-outbound.md).

## Authentication

Every route requires an active tenant user authenticated with:

```http
Authorization: Bearer <user-api-token>
```

Query-string tokens are not accepted. Do not log the bearer value. Requests and
responses use JSON. Mutation requests must send `Content-Type: application/json`
and are limited to 16 KiB.

## Routes

| Method | Path | Required headers | Result |
| --- | --- | --- | --- |
| `GET` | `/api/v1/user/services` | Bearer | The tenant's services and their `service_id` (`services.id`, not the Client ID or the WHMCS service id) |
| `GET` | `/api/v1/user/services/{service_id}/forward-options` | Bearer | Public route choices, transports, capabilities, and quota |
| `GET` | `/api/v1/user/services/{service_id}/forwards` | Bearer | Active forwards owned by the tenant |
| `POST` | `/api/v1/user/services/{service_id}/forwards` | Bearer, `Content-Type`, `Idempotency-Key` | Creates or replays one forward; `201` on create |
| `GET` | `/api/v1/user/forwards/{forward_id}` | Bearer | One active owned forward |
| `PATCH` | `/api/v1/user/forwards/{forward_id}` | Bearer, `Content-Type`, `If-Match` | Updates one forward |
| `DELETE` | `/api/v1/user/forwards/{forward_id}` | Bearer, `If-Match` | Soft-deletes one forward; `204` on success |

Successful responses include `Cache-Control: no-store` and
`X-Content-Type-Options: nosniff`. Single-forward responses include an `ETag`.
Create responses also include `Location` for the new resource. A list response
contains each forward's `revision`; fetch the detail route to obtain its exact
ETag before a conditional mutation.

## Options

```http
GET /api/v1/user/services/{service_id}/forward-options
Authorization: Bearer <user-api-token>
```

Example response:

```json
{
  "ok": true,
  "transports": ["tcp", "udp", "tcp+udp"],
  "forward_limit": 64,
  "active_forward_count": 2,
  "capabilities": {
    "allow_manage_forwards": true,
    "allow_delete_forwards": true
  },
  "routes": [
    {
      "id": 14,
      "label": "Example Route",
      "description": "Tenant-visible route description",
      "badge": "stable",
      "transports": {
        "tcp": {"available": true, "healthy_node_count": 2, "unavailable_reason": null},
        "udp": {"available": false, "healthy_node_count": 0, "unavailable_reason": "no_healthy_nodes"},
        "tcp+udp": {"available": true, "healthy_node_count": 2, "unavailable_reason": null}
      }
    }
  ]
}
```

Only active routes explicitly mapped to the service plan and containing at
least one enabled active combination are returned. Missing or stale plan
mapping is fail-closed and returns an empty route list. Stable availability
reasons are limited to `route_unavailable`, `no_healthy_nodes`,
`transport_unavailable`, and `not_configured`.

## Forward Resource

The list, detail, create, and patch responses use this representation:

```json
{
  "id": 123,
  "service_id": 42,
  "route": {
    "id": 14,
    "label": "Example Route"
  },
  "listen_port": 11024,
  "transport": "tcp",
  "native_outbound": false,
  "native_direction": null,
  "target": {
    "source": "manual",
    "host": "192.0.2.10",
    "port": 443,
    "service_id": null
  },
  "ingress": {
    "host": "edge.example.test",
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

`native_outbound` is `true` when the carrier runs on the client device, and
`native_direction` is the direction identity the panel derived for that forward.
Both are read-only: the direction comes from the assigned route, never from the
request. Every other forward reports `"native_outbound": false` and
`"native_direction": null`.

The resource never contains provider line IDs, combination IDs, active
combination IDs, node IDs or addresses, transport endpoint fields, client
placement, backend mode, path prefixes, SNI, configuration material, client
tokens, or health error text. Runtime client configuration remains behind its
separate existing endpoint and authorization flow.

## Create

`Idempotency-Key` is required. It must be 8-64 ASCII characters matching
`[A-Za-z0-9._:-]+`.

```powershell
$body = @'
{
  "route_id": 14,
  "listen_port": null,
  "transport": "tcp",
  "target": {
    "source": "manual",
    "host": "192.0.2.10",
    "port": 443
  }
}
'@
Invoke-RestMethod `
  -Method Post `
  -Uri "https://panel.example.test/api/v1/user/services/$SERVICE_ID/forwards" `
  -Headers @{
    Authorization = "Bearer $USER_API_TOKEN"
    'Idempotency-Key' = 'create-forward-20260802-01'
  } `
  -ContentType 'application/json' `
  -Body $body
```

`listen_port: null` requests automatic allocation. A manual target requires a
host and port. A service-IP target uses an owned service ID and does not accept
a manual host:

```json
{
  "route_id": 14,
  "listen_port": null,
  "transport": "tcp+udp",
  "target": {
    "source": "service_ip",
    "service_id": 43,
    "port": 8443
  }
}
```

Set `"native_outbound": true` to create a forward whose carrier runs on the
client device. A native forward ignores any `target` object, requires the ultra
runtime with `tcp+udp`, and derives its `native_direction` from the route; see
[native-outbound.md](native-outbound.md) for the availability rules, the
server-assigned target, and the direction conflict contract.

On success the response is `201`, includes `Location` and an ETag such as
`"forward-123-r1"`, and contains the safe forward resource under `forward`.

## List and Detail

```http
GET /api/v1/user/services/{service_id}/forwards
Authorization: Bearer <user-api-token>
```

The list contains only active forwards whose service belongs to the bearer
user:

```json
{
  "ok": true,
  "forwards": [
    { "id": 123, "service_id": 42, "state": "active", "revision": 3 }
  ]
}
```

Fetch a detail resource when a current ETag is needed:

```http
GET /api/v1/user/forwards/123
Authorization: Bearer <user-api-token>
```

The detail response has the same `forward` representation and an `ETag` header.
Cross-tenant, missing, and already inactive forward IDs return the same
`404 forward_not_found` result.

## Patch

Patch accepts only mutable fields. At least one field is required; unknown
top-level or nested fields are rejected.

```http
PATCH /api/v1/user/forwards/123
Authorization: Bearer <user-api-token>
Content-Type: application/json
If-Match: "forward-123-r3"
```

```json
{
  "transport": "tcp+udp",
  "target": {
    "source": "manual",
    "host": "backend.example.test",
    "port": 8443
  }
}
```

The response returns the updated safe resource and a new ETag, for example
`"forward-123-r4"`. Changing route ID, target source, target service ID, or
transport uses the structural provisioning path; changing only the target or
an explicit nonzero listen port uses the narrower update path. Setting
`listen_port` to `null` requests a new automatic allocation and also uses the
structural path. Structural validation completes before endpoint retirement,
so an unavailable transport fails atomically with `409 route_unavailable`.

A partial target patch keeps the current source. A manual target cannot accept
`service_id`, and a service-IP target cannot accept a caller-controlled `host`.
Changing from `manual` to `service_ip` requires `source` and `service_id` in the
same patch. Changing back requires `source` and `host` in the same patch; stale
fields from the previous target type are cleared server-side.

`native_outbound` can be switched off through a patch. Turning it off requires a
complete manual target in the same patch, and the request then takes the
structural provisioning path. Turning native outbound on for an existing forward
is not a v1 patch operation: a patch that carries target fields together with
`native_outbound: true` fails with `422 validation_failed` and
`fields: {"target": "forbidden_for_native_outbound"}`. Use the panel's forward
form or create a new native forward; see [native-outbound.md](native-outbound.md).

## Delete

```http
DELETE /api/v1/user/forwards/123
Authorization: Bearer <user-api-token>
If-Match: "forward-123-r4"
```

Successful deletion is a soft delete through the existing retirement protocol
and returns `204` with an empty body. A stale or missing ETag never retires an
endpoint. User deletion writes a permanent tombstone marker; the tenant account
reactivation action never revives that row. The billing job writes a separate
balance-suspension marker and increments the revision. Only rows carrying that
explicit marker are eligible for account reactivation; they are rechecked under
the current tenant status, wallet balance, feature policy, and active quota,
then the marker is cleared and the revision is incremented. Historical disabled
rows whose cause cannot be attributed to an individual forward remain
unclassified and are never restored automatically.

## Retry and Concurrency

- Retry reads normally; they do not mutate state.
- If a create request times out after submission, retry with the same
  `Idempotency-Key` and the same normalized JSON. The server replays the
  original resource instead of creating a duplicate.
- Reusing a key with a different normalized request returns
  `409 idempotency_conflict`. Do not generate a new key merely because the
  first response was lost.
- If the original resource was deleted, replay returns
  `409 idempotency_resource_inactive`; an idempotency key never resurrects or
  successfully replays a tombstone.
- `PATCH` and `DELETE` are conditional writes. On `412 forward_version_conflict`,
  fetch the current detail resource, review the new ETag, and decide whether to
  apply the operation again. Never retry a stale mutation unchanged.
- A successful patch increments `revision`. A successful delete increments the
  server-side revision before the resource becomes inactive.
- Create locks the service and rechecks owner, account/service status, feature
  policy, idempotency, and quota. Concurrent creates cannot jointly exceed the
  configured limit.
- Soft-deleted rows count toward the configurable retained-history limit. Once
  reached, create returns `409 forward_storage_quota_exceeded`; operators must
  review and deliberately archive old records rather than allowing unbounded
  create/delete churn.

## Validation and Errors

JSON values use strict types. Ports are integers from 1 through 65535; only
`listen_port` may be `null`. Manual hosts are ASCII IPv4, IPv6, or DNS names up
to 253 characters. URL schemes, userinfo, paths, whitespace, control bytes,
shell metacharacters, and IPv6 zone identifiers are rejected. Private and
loopback targets remain valid for tenant-owned backends.

The stable error envelope is:

```json
{
  "ok": false,
  "error": {
    "code": "validation_failed",
    "message": "The forward request is invalid.",
    "fields": {"target.port": "out_of_range"}
  }
}
```

Expected codes include:

| HTTP | Codes |
| --- | --- |
| `401` | `user_auth_failed` |
| `404` | `service_not_found`, `forward_not_found` |
| `403` | `native_outbound_not_allowed` |
| `409` | `service_inactive`, `forward_quota_exceeded`, `forward_storage_quota_exceeded`, `idempotency_conflict`, `idempotency_resource_inactive`, `route_unavailable`, `native_direction_conflict` |
| `412` | `forward_version_conflict` |
| `413` | `request_too_large` |
| `415` | `unsupported_media_type` |
| `422` | `validation_failed`, `unknown_fields`, `idempotency_key_invalid`, `forward_route_not_allowed`, `forward_route_has_no_enabled_combination`, `native_outbound_requires_ultra_tcp_udp`, `native_outbound_direction_missing` |
| `428` | `idempotency_key_required`, `precondition_required` |
| `500` | `operation_failed` |

Feature-policy failures use `403 feature_denied_manage_forwards` or
`403 feature_denied_delete_forwards`. Unexpected exception details are logged
server-side and are never returned in the response. Provider-specific failures,
node identities, group names, and topology details are mapped to stable public
codes rather than exposed.

## Resource Boundary

The tenant resource is the existing `forwards` row reached through
`forwards.service_id -> services.user_id`. The tenant never manages the
administrator-owned `leigod_lines` catalog. Nodes, transport endpoints, forward
combinations, provider placement, CDN hosts, public ports, path prefixes, and
runtime configuration are not tenant resources or writable API fields.

The internal ingress topology is operator-owned and unchanged, including its
7000-9000 port window. A tenant selects only a public route ID; the server
resolves the internal combination and endpoint placement after the service-plan
allowlist, target ownership, health, and port checks succeed. A native outbound
forward is the one case where the carrier does not run on that ingress, and the
client is told so explicitly rather than being asked to infer it from a target or
a port range.

## Legacy Compatibility

The unversioned `/api/user/...` create, update, delete, and client-config routes
remain registered for existing clients. New integrations should use v1. Legacy
write responses carry `Deprecation: true`, use the same tenant ownership,
active-service, feature-policy, quota, host, port, and payload allowlists, and
return a safe forward resource plus a client-config URL rather than embedding
runtime client configuration in a management mutation response. They require an
active tenant account, pass the current revision into the transaction, and
redact internal provisioning errors across preflight, provisioning, and
client-config reads. Client config is available only while both the forward and
service are active. For compatibility, legacy writes ignore `endpoint_count`,
canonicalize decimal-string IDs/ports and case-insensitive `tcp`/`udp`,
`udp+tcp`, and `both`; internal
combination, node, Sidecar, and placement fields remain rejected.

Administrators assign active Forward Routes directly on the plan editor. A plan
save validates that every selected route contains an enabled active combination,
then writes both the explicit route allowlist and its compatibility combination
mapping in one transaction. A missing explicit route mapping remains fail-closed.
Automatic MySQL migrations use a database advisory lock, retry standalone
deadlocked statements through the database wrapper, and return only stable
migration errors while logging implementation details server-side.
