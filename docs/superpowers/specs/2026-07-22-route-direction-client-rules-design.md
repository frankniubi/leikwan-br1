# Route Direction + Client Rules Design

Date: 2026-07-22
Status: design approved in conversation; awaiting written-spec review

## Summary

This edition removes the old admin-facing routing construction model from the product surface. Admins and users no longer work with Forward Combinations, CF Location Groups, Chains, or implementation mode names. Instead, the product has two first-class concepts:

- **Route Direction**: a stable, admin-managed user-facing direction such as `沪日1`, `沪日2`, or `沪港1`.
- **Client Rule**: an app-created, server-synced exact destination rule such as `93.184.216.34:443 -> 沪日1`.

The backend may still reuse existing provisioning/runtime machinery behind the facade. The implementation-term ban applies to new Route Direction, Client Rule, plan-facing, upgraded App, and user-facing API surfaces. Existing legacy compatibility fields may remain inside `forwards[]` for old clients, but upgraded route-rule UI/API surfaces must filter or hide those fields so users never see `LeiGod`, `current_mode`, `cf_mode`, `forward_combination`, `cf_location_group`, `chain`, slave group, sidecar, internal line, listener port, or runtime mode terms.

The V1 design maximizes reuse of existing compatible local-listener/runtime machinery:

- Route Directions are backed by one primary internal runtime realization in V1. That realization may reuse existing compatible `current_mode` or `leigod_mode` mechanics where already supported, but those mode names remain internal and never become route-rule product language.
- Client Rules bind stable Route Direction IDs, not runtime forwards, listener ports, or internal line IDs.
- `/api/client/configs/pull` remains backward-compatible and receives additive route-direction/rule fields for upgraded clients.
- Android reuses its existing TUN/sing-box foundation.
- Linux and Windows reuse existing local forward/listener runtimes after a thin exact-destination interception layer sends matching traffic to the right listener.

## Goals

1. Make Route Direction the only routable product object in this edition.
2. Make Forward Combinations, CF Location Groups, Chains, and raw legacy route-construction pages unroutable as admin product pages.
3. Hide `LeiGod` and all legacy/internal routing terminology from user-facing App, plan, and user API surfaces.
4. Let admins maintain friendly direction labels and internal line/slave group/sidecar mappings.
5. Let plans control the allowed direction set and max Client Rules per immutable `service_scope_id`.
6. Let users create exact `DST IP:PORT -> Route Direction` rules inside the App.
7. Sync Client Rules to the server and make the server authoritative for plan rights, quota, duplicates, and admin edits.
8. Support Linux, Windows, and Android with one product contract while preserving existing runtime contracts as much as possible.
9. Preserve backward compatibility for existing `/api/client/configs/pull` consumers.
10. Fail closed when a rule, direction, entitlement, or runtime binding cannot be applied safely.

## Non-goals

- V1 does not expose backup, auto-racing, or multi-realization failover inside a Route Direction.
- V1 does not let users choose protocol; rules are product-level TCP+UDP.
- V1 does not support domains, CIDR ranges, country rules, app/process-specific rules, or broad category rules for this feature.
- V1 does not make users manually connect to local listener ports.
- V1 does not remove all legacy database/provisioning tables immediately; they may remain as internal compatibility machinery.
- V1 does not replace `/api/client/configs/pull` with a brand-new client configuration protocol.

## Product objects and information architecture

### Route Direction

Route Direction is the product-facing facade for a usable acceleration direction. It is the only direction object users, plans, subscriptions, and Client Rules bind to.

Required semantics:

- **Stable ID**: immutable server ID used by plans, subscriptions, and user rules. It must not be recycled.
- **User label**: short admin-defined label shown in the App, for example `沪日1`, `沪日2`, `沪港1`.
- **Admin notes**: optional operator context for distinguishing similar directions.
- **Status**: available, unavailable, maintenance, retired. User UI only sees the direction name plus an unavailable/maintenance state when relevant. The exact V1 behavior for each status is defined in the Route Direction status matrix below.
- **Internal mapping**: admin-only mapping to line, slave group, sidecar, and realization details.
- **Primary realization**: V1 supports exactly one primary realization per direction.
- **Remap behavior**: changing the internal mapping for a direction automatically changes every rule bound to that direction, without users editing rules.

User-facing surfaces must never show `LeiGod`, `current_mode`, `cf_mode`, chain, combination, CF Location Group, internal line, slave group, sidecar, relay, tunnel, listener, or protocol terminology.

#### Route Direction status matrix

| Status | App selector visibility | Create/enable/rebind | Existing enabled rules | Effective state | Plan entitlement behavior |
| --- | --- | --- | --- | --- | --- |
| `available` | Visible when plan-allowed. | Allowed if other validation passes. | May be active if runtime binding is ready. | `active` or another non-status effective state from quota/runtime/platform validation. | May be added to plans and remains selectable. |
| `unavailable` | Visible only when plan-allowed and already relevant to the scope; shown as unavailable. | Rejected with `direction_unavailable`. | Retained, non-effective, and fail closed for matching traffic. | `unavailable`. | May remain in plans so existing rules are explainable, but new use is blocked while unavailable. |
| `maintenance` | Visible only when plan-allowed and already relevant to the scope; shown as maintenance/unavailable product copy. | Rejected with `direction_unavailable`. | Retained, non-effective, and fail closed for matching traffic. | `unavailable`. | May remain in plans during operator maintenance. |
| `retired` | Hidden from new selectors. Existing bound rules still show the historical label plus unavailable state. | Rejected with `direction_unavailable`; no new binding to retired directions. | Retained for audit and user/admin cleanup, non-effective, and fail closed for matching traffic. | `unavailable`. | Must be removed from new plan selection; historical entitlement/rule records remain readable for audit and migration. |

A direction is "already relevant to the scope" only when that `service_scope_id` has a non-tombstoned Client Rule currently bound to that direction. This preserves explainability for existing rules without making unavailable, maintenance, or retired directions selectable for new rules.

### Direction Realization

Direction Realization is the admin-only operational backing behind a Route Direction. It explains how a direction is currently realized, but it is not itself a user product object.

Required semantics:

- Belongs to exactly one Route Direction in V1.
- One primary realization per Route Direction in V1.
- Holds the internal line/slave group/sidecar mapping admins need to operate the service.
- May reuse existing compatible runtime machinery internally, including current-mode and leigod-mode mechanics where already supported, but that reuse must not leak into user naming, plan configuration, or App information architecture.
- Can be replaced or remapped while preserving the parent Route Direction ID and label.

### Client Rule

Client Rule is the user-created routing rule that binds an exact destination to a Route Direction.

Required semantics:

- Created in the App, synced to the server, and enforced by the server.
- Belongs to one immutable rule scope, named `service_scope_id` in this spec.
- Has exactly one destination: one destination IP and one port.
- Binds to exactly one Route Direction.
- Protocol is hidden from users and treated as TCP+UDP.
- Has saved/tombstoned lifecycle semantics defined below. The user/admin desired state is represented by `enabled` while the server-derived installability is represented by `effective_state`.
- Server rejects duplicate enabled rules for the same `service_scope_id` and same `IP:PORT + protocol`.
- Admin can view and fully edit user rules within the same immutable scope, including destination, enabled state, and bound Route Direction.
- Admin cannot move a Client Rule to another `service_scope_id` in V1; if scope is wrong, the rule must be tombstoned and recreated in the target scope subject to target-scope validation.
- If the bound Route Direction is remapped, the rule follows the remap automatically.

### Plan Direction Entitlement

Plan Direction Entitlement defines what a subscription is allowed to use.

Required semantics:

- **Allowed direction set**: plan controls which Route Direction IDs can be selected by users.
- **Rule quota**: plan controls max non-tombstoned saved rules per `service_scope_id`.
- **Server enforcement**: authoritative; App UI validation is only a convenience layer.
- **Plan changes**: if a direction is removed from a plan, existing affected rules are retained but become not allowed and non-effective until changed or the plan entitlement changes again.

### Service scope

`service_scope_id` is the quota and duplicate-check boundary for Client Rules.

V1 defines `service_scope_id` as the immutable service/subscription activation scope for one user's service instance. Implementation may derive it from existing service and subscription rows, but all rule validation uses this single canonical scope key.

Required semantics:

- A `service_scope_id` resolves to one current subscription and therefore one current plan.
- The current plan determines allowed Route Directions and max non-tombstoned saved rules for that `service_scope_id`.
- Rule count limits are evaluated per `service_scope_id`.
- Duplicate enabled `IP:PORT + protocol` checks are evaluated per `service_scope_id`.
- Admin views must display the `service_scope_id` plus human-readable user/service/subscription context so operators can understand why a rule is accepted or rejected.

### Legacy admin objects

Forward Combinations, CF Location Groups, Chains, and any raw legacy route-construction surface such as raw Forward Routes are legacy/internal objects for this edition.

Required semantics:

- They must not be routable admin product pages in this edition.
- They must not appear as selectable admin routing products.
- They must not appear in App UI, plan UI, user-facing APIs, or user-facing naming.
- They may exist as historical/internal records only if needed for compatibility, migration, or operations.
- Legacy product URLs and POST/API handlers for these objects must not remain usable as product CRUD/detail surfaces. V1 requires old Forward Combination, CF Location Group, Chain, and raw Forward Route CRUD/detail GET/POST/API routes to return 404 or 410. Redirects and unavailable pages are not sufficient because they keep the old route surface reachable.
- No new user-facing or admin-facing routing decision should require choosing a Forward Combination, CF Location Group, Chain, or raw Forward Route.

## Naming

### User-facing names

Use simple route-direction labels only:

- `沪日1`
- `沪日2`
- `沪港1`
- `京日1`

User-facing naming rules:

- Show direction label, availability, and rule destination.
- Do not show `LeiGod`, `current_mode`, `cf_mode`, chain, combination, CF Location Group, sidecar, slave, line, relay, tunnel, listener, or protocol.
- Protocol is implied as TCP+UDP and not selectable.
- Unavailable copy should be plain, such as `当前方向不可用` or `方向维护中`; do not expose internal cause strings.

### Admin-facing names

Admin sees both product label and internal mapping:

- Route Direction label, such as `沪日1`.
- Stable immutable direction ID.
- Internal mapping: line, slave group, sidecar, primary realization.
- Operator notes for line/vendor/account/region context.

Admin naming rules:

- Route Direction remains the top-level product term.
- Direction Realization is acceptable as an admin/internal term.
- Legacy terms may appear only in hidden diagnostics or compatibility context, not as routable product choices.

### API/product language

Preferred terms:

- Route Direction
- Client Rule
- Plan Direction Entitlement
- Direction unavailable
- Rule quota
- Exact destination

Avoid in user-facing and plan-facing surfaces:

- `LeiGod`
- `current_mode`
- `cf_mode`
- chain
- combination
- CF Location Group
- slave
- sidecar
- internal line
- listener port

## Information architecture

### Admin IA

Admin should be organized around Route Directions and Client Rules, not legacy routing machinery.

Primary admin pages:

1. **Route Directions**
   - List all Route Direction objects.
   - Show stable ID, user label, status, plan usage, rule count, and internal mapping summary.
   - Primary actions: create direction, edit label/status, edit internal mapping, retire direction.
   - Legacy Forward Combinations, CF Location Groups, Chains, and raw Forward Routes are not routable choices here.

2. **Route Direction Detail**
   - Product identity: stable ID, user label, admin notes, status.
   - Primary realization: V1 single active realization.
   - Internal mapping: line, slave group, sidecar, and operational metadata visible to admin only.
   - Impact preview: affected plans/subscriptions/rules when status or mapping changes.
   - Remap intent: admin edits mapping at the direction level; bound rules remain bound to the same Route Direction.

3. **Plan Direction Entitlements**
   - Configure each plan's allowed Route Direction set.
   - Configure max non-tombstoned saved rules per immutable `service_scope_id`.
   - Show affected subscriptions and current usage against limits.
   - Do not expose internal realization selection.

4. **Client Rules Admin**
   - Global searchable view of user rules.
   - Filters: user, `service_scope_id`, destination `IP:PORT`, direction, enabled state, availability/effective state.
   - Admin can fully edit rules inside their immutable scope: destination, direction, enabled state, and operational notes.
   - Admin cannot move a rule between service scopes in V1; moving requires tombstone plus recreate in the target scope.
   - Duplicate and entitlement validation messages should reference Route Direction and destination, not internal routing objects.

5. **User / Subscription Detail**
   - Show the `service_scope_id`'s allowed directions, rule quota, current rule count, and rule list.
   - Admin can view/edit/disable/tombstone existing App-created rules within that scope, but V1 does not let Admin create new Client Rules on behalf of users.
   - Make plan-derived limits visible so support can explain App behavior.

6. **Internal Diagnostics**
   - Optional operator-only diagnostics may expose legacy/internal mappings for troubleshooting.
   - Diagnostics must not expose old Forward Combination, CF Location Group, Chain, or raw Forward Route CRUD/detail pages as reachable routes, even by direct URL.
   - Diagnostics must be read-only or task-specific under the Route Direction facade, with no create/select/save action for legacy routing objects.
   - This area must not create routable product choices and must not be linked as a primary admin workflow in this edition.

### App user IA

The App user surface should be rule-oriented and direction-label-oriented.

Primary App surfaces across Linux, Windows, and Android:

1. **Rule List**
   - Shows rules for the current `service_scope_id` with user/service/subscription context.
   - Each row shows destination `IP:PORT`, Route Direction label, enabled state, sync state, and unavailable/effective state.
   - Does not show protocol, because V1 rules are product-level TCP+UDP.
   - Does not show `LeiGod`, `current_mode`, `cf_mode`, chain, combination, line, slave, sidecar, listener, or raw forward fields.

2. **Create / Edit Rule**
   - User enters one IP and one port.
   - User selects one allowed Route Direction.
   - UI shows remaining quota and duplicate validation results.
   - Protocol is not shown or selectable.
   - If a direction is unavailable, App shows that state using product copy. V1 rejects creating/enabling/rebinding a rule to an unavailable direction, while preserving existing rules if a direction becomes unavailable after they were created.

3. **Direction Selector**
   - Lists only plan-allowed Route Directions.
   - Shows label and availability only.
   - Does not show internal mapping, mode, chain, line, slave, sidecar, listener, or health metrics.

4. **Sync / Status**
   - App shows synced, pending sync, rejected, conflict, unavailable, not allowed, or over quota states.
   - Rejection reasons use product language: duplicate destination, quota exceeded, direction not allowed, direction unavailable, invalid destination, service inactive, version conflict.

Platform scope:

- Android is in scope and reuses the existing TUN foundation.
- Linux is in scope and uses exact-destination interception into the existing local listener path.
- Windows is in scope and uses an equivalent thin exact-destination interception/dispatch layer into the existing local listener path.
- All platforms preserve `/api/client/configs/pull` backward-compatibility boundaries.

## Data / API contract

### Contract goals

The API contract exposes the Route Direction facade and Client Rules while preserving existing runtime fields for compatibility.

The new Route Direction, Client Rule, plan-facing, upgraded App, and user-facing route-rule API contract must never expose or accept implementation terms such as `LeiGod`, `current_mode`, `cf_mode`, `chain`, `forward_combination`, or `cf_location_group`. Those remain server/admin internals, hidden diagnostics, or legacy compatibility fields only. Existing `forwards[]` runtime fields may keep their old shape for old clients, but upgraded route-rule surfaces must filter or hide them from user-facing route-rule UX.

### Route Direction payload

User/App-visible conceptual payload:

```json
{
  "id": "dir_sh_jp_1",
  "label": "沪日1",
  "status": "available",
  "is_available": true,
  "sort_order": 10
}
```

Rules:

- `id` is stable and non-recycled.
- `label` is user-facing and may be renamed without changing rule binding.
- `status` and `is_available` are product-level availability signals only.
- Internal line/slave group/sidecar/realization details are not returned to user-facing App payloads.

Admin-visible payloads may include:

- Internal line mapping.
- Slave group.
- Region / destination region.
- Sidecar / realization summary.
- Operational notes.
- Usage counts across plans, subscriptions, and rules.
- Audit/remap metadata.

### Plan Entitlement payload

Conceptual payload:

```json
{
  "plan_id": 12,
  "allowed_direction_ids": ["dir_sh_jp_1", "dir_sh_jp_2"],
  "max_client_rules_per_service_scope": 5
}
```

Validation rules:

- A Client Rule's `direction_id` must belong to the current plan's allowed direction set for that rule's `service_scope_id`.
- Rule quota is counted as non-tombstoned saved rules per `service_scope_id`. Disabled rules count toward quota; tombstoned rules do not count toward quota and are not restorable in V1.
- Admin edits use the same validation rules unless a later design explicitly introduces a separate override permission.

### Client Rule payload

Conceptual payload:

```json
{
  "id": "rule_1",
  "service_scope_id": "svc_scope_1001",
  "service_id": 1001,
  "subscription_id": 501,
  "destination_ip": "93.184.216.34",
  "destination_port": 443,
  "protocol": "tcp_udp",
  "direction_id": "dir_sh_jp_1",
  "enabled": true,
  "effective_state": "active",
  "sync_version": 17
}
```

Rules:

- `service_scope_id` is the authoritative validation key for quota, duplicate checks, entitlement resolution, and sync ownership.
- `service_id` and `subscription_id` are included for display, audit, and entitlement resolution, but implementations must not use them as competing duplicate/quota scopes.
- `destination_ip` must pass the V1 destination validation rules below.
- `destination_port` must be a single valid port.
- `protocol` is fixed as `tcp_udp` in V1. It is a machine field for server/client compatibility, duplicate keys, and future migration only; it is not product copy, not shown in UI, and not accepted as a user-selectable input.
- `direction_id` binds to a Route Direction, not a forward, line, sidecar, or listener.
- `enabled` is the user's/admin's desired enabled state.
- `effective_state` explains whether the enabled rule can actually apply, for example active, disabled, unavailable, not_allowed_by_plan, over_quota, invalid, conflict, unsupported, or runtime_missing.
- `sync_version` enables conflict detection between App edits and server/admin edits.

### Destination validation

V1 accepts only IPv4 literals as user-entered destinations.

Validation rules:

- `destination_ip` normalization trims surrounding ASCII whitespace only.
- After normalization, `destination_ip` must be exactly four decimal octets in canonical dotted-quad notation.
- Reject integer IPv4, octal/hex-like octets, leading-zero alternate notation, embedded whitespace, zone IDs, and other non-canonical spellings with `invalid_destination_ip`.
- IPv6 literals are out of scope for V1 and rejected with `unsupported_destination_address_family`.
- Domains, CIDR ranges, wildcards, hostnames, and URL strings are rejected with `invalid_destination_ip`.
- `destination_port` must be an integer from 1 to 65535.
- Loopback, link-local, multicast, broadcast, unspecified, documentation/test networks, RFC1918/private ranges, and any configured panel/control-plane/runtime/endpoint protection ranges are rejected with `destination_blocked_by_policy` even if syntactically valid.
- Control-plane protection wins over user configuration: users cannot create rules that capture panel login, config pull, artifact mirrors, DNS bootstrap, local listener ports, runtime reachability, slave/sidecar management endpoints, or other protected service infrastructure.

### Client Rule lifecycle

V1 uses a simple lifecycle model:

| Lifecycle | User/admin desired state | Counts toward quota | Duplicate participation | Restorable in V1 | Client delivery |
| --- | --- | --- | --- | --- | --- |
| Saved enabled | `enabled = true` | Yes | Yes, because enabled rules must be unique per `service_scope_id + IP:PORT + tcp_udp`. | Not applicable. | Delivered with effective state. |
| Saved disabled | `enabled = false` | Yes | No while disabled; enabling must re-run duplicate validation. | Not applicable. | Delivered as disabled. |
| Tombstoned | Deleted historical/audit record. | No | No. | No; recreate as a new rule if needed. | Not delivered as an active saved user rule. |

V1 treats user/App delete and Admin delete as tombstone operations: the record remains available for audit/history, but it is no longer a saved user rule, no longer counts toward quota, no longer participates in duplicate checks, and cannot be restored by flipping a flag. Recreating a tombstoned rule creates a new rule ID and must pass target-scope destination, duplicate, quota, entitlement, and audit validation.

### Duplicate validation

Duplicate scope is exactly one `service_scope_id`.

The server rejects any create/edit/enable operation that would produce two enabled rules in the same `service_scope_id` with the same:

- `destination_ip`
- `destination_port`
- `protocol`

Because protocol is fixed to `tcp_udp` in V1, the user-facing duplicate key is just `IP:PORT` inside the current `service_scope_id`.

Disabled duplicate records may exist, but re-enabling a disabled duplicate must pass the same validation.

### Rule quota validation

Rule quota counts non-tombstoned saved rules in one `service_scope_id`, including disabled rules. The reason is that the plan limit means how many saved destination rules the service scope may own, not how many are currently toggled on.

If a plan's max rules is reduced below existing usage:

- Existing records are retained.
- The server marks the whole `service_scope_id` as over quota.
- While the scope is over quota, all non-tombstoned saved rules in that scope receive `effective_state = over_quota` and are non-effective. This avoids ambiguous per-rule ranking and forces the user/Admin to tombstone rules until the scope is within quota or upgrade the plan limit.
- The server rejects create/enable/edit operations that would keep or increase the quota violation, except edits that tombstone rules or otherwise reduce the non-tombstoned saved count to resolve the violation.
- Once the non-tombstoned saved rule count is within quota, normal per-rule effective-state evaluation resumes.
- Admin views must show the over-quota state, current count, limit, and affected rules.

### Effective-state and write-error precedence

V1 uses deterministic precedence so overlapping conditions do not produce ambiguous App or Admin behavior.

Server-side write validation order for create/enable/rebind/edit is:

1. Scope exists and is active; otherwise `service_not_active`.
2. `sync_version` matches for operations on an existing rule; otherwise `version_conflict`.
3. Destination syntax and policy pass; otherwise `invalid_destination_ip`, `unsupported_destination_address_family`, `invalid_destination_port`, or `destination_blocked_by_policy`.
4. Direction exists, is plan-allowed for the `service_scope_id`, and is not retired; otherwise `direction_not_allowed` for missing/not-plan-allowed direction and `direction_unavailable` for retired direction.
5. Direction status is `available`; otherwise `direction_unavailable` for `unavailable`, `maintenance`, and `retired`.
6. Duplicate validation passes for enabled rules; otherwise `duplicate_destination`.
7. Quota validation passes for non-tombstoned saved rules; otherwise `rule_quota_exceeded`.
8. If validation passes, the server stores the rule and computes `effective_state` from runtime/platform state.

Read/pull `effective_state` precedence for non-tombstoned saved rules is:

1. `disabled` when `enabled = false`.
2. `over_quota` when the `service_scope_id` is over quota.
3. `not_allowed_by_plan` when the bound direction is no longer allowed by the current plan.
4. `unavailable` when the bound direction is unavailable, maintenance, retired, or its realization cannot be used.
5. `unsupported` when the client/platform lacks route-rule capability or cannot represent the TCP+UDP rule.
6. `invalid` when a previously saved destination becomes blocked by updated validation/control-plane policy.
7. `conflict` only for local App pending edits that lost a `sync_version` race; persisted server state returns the accepted rule version instead.
8. `runtime_missing` when entitlement and direction are valid but no ready runtime binding exists.
9. `active` only when all preceding checks pass and the binding is ready for both TCP and UDP.

`runtime_missing` and `unsupported` are product-level effective states; user copy still says only that the rule/direction is unavailable. Matching traffic fails closed on route-rule-capable clients for every non-active enabled state.

### Client sync contract

The App creates/edits/tombstones/enables/disables rules through server APIs. The server is authoritative.

Minimum compliant flow:

1. App pulls allowed directions, rule quota, existing rules, and availability.
2. App may perform local validation for responsiveness, but local validation is not authoritative.
3. App submits create/update/tombstone/enable/disable with the latest `sync_version`.
4. Server performs authoritative validation.
5. Server returns accepted state or a stable product-level error code.
6. App updates local state from the server response.

Stable error codes:

- `duplicate_destination`
- `rule_quota_exceeded`
- `direction_not_allowed`
- `direction_unavailable`
- `invalid_destination_ip`
- `invalid_destination_port`
- `unsupported_destination_address_family`
- `destination_blocked_by_policy`
- `service_not_active`
- `rule_not_found`
- `version_conflict`

Runtime/platform non-active states returned in pull payloads:

- `disabled`
- `over_quota`
- `not_allowed_by_plan`
- `unavailable`
- `unsupported`
- `invalid`
- `conflict`
- `runtime_missing`
- `active`

Error messages must not leak internal implementation names.

### `/api/client/configs/pull` extension

`/api/client/configs/pull` should be extended additively, not replaced.

Existing shape remains valid:

```json
{
  "ok": true,
  "client": {},
  "forwards": []
}
```

New upgraded-client fields may be added:

```json
{
  "route_directions": [
    {
      "id": "dir_sh_jp_1",
      "label": "沪日1",
      "is_available": true,
      "status": "available"
    }
  ],
  "route_rule_entitlements": [
    {
      "service_scope_id": "svc_scope_1001",
      "service_id": 1001,
      "subscription_id": 501,
      "max_rules": 5,
      "remaining_rules": 3,
      "allowed_direction_ids": ["dir_sh_jp_1"]
    }
  ],
  "client_rules": [
    {
      "id": "rule_1",
      "service_scope_id": "svc_scope_1001",
      "service_id": 1001,
      "subscription_id": 501,
      "destination_ip": "93.184.216.34",
      "destination_port": 443,
      "protocol": "tcp_udp",
      "direction_id": "dir_sh_jp_1",
      "enabled": true,
      "effective_state": "active",
      "sync_version": 17
    }
  ],
  "direction_runtime_bindings": [
    {
      "service_scope_id": "svc_scope_1001",
      "direction_id": "dir_sh_jp_1",
      "binding_state": "ready",
      "client_runtime_binding_id": "crb_svc_scope_1001_dir_sh_jp_1",
      "runtime_ref": {
        "type": "forward_ref",
        "forward_id": 10001,
        "selectable": false,
        "transport": "tcp_udp"
      },
      "local_endpoint": {
        "host": "127.0.0.1",
        "tcp_port": 11001,
        "udp_port": 11001
      },
      "tcp_ready": true,
      "udp_ready": true
    }
  ],
  "capabilities": {
    "route_direction_rules": true,
    "exact_destination_rules": true
  }
}
```

Compatibility requirements:

- Old clients continue reading `forwards[]`.
- New clients consume Route Directions and Client Rules when present.
- New fields missing means the client treats direction rules as unsupported for that account/client version.
- Exact-destination fail-closed guarantees apply only to clients that advertise and receive the route-rule capability. Unsupported legacy clients cannot be expected to install per-destination block rules.
- Existing forward/runtime fields are not removed or renamed as part of this design.
- V1 must not expose route-direction helper/runtime forwards as normal user-selectable services to legacy clients. The default V1 rule is: helper/runtime forwards used only for Route Direction bindings are omitted from legacy `forwards[]` unless they are also valid standalone legacy services for that client. If an implementation must include a helper forward in `forwards[]`, it must carry an explicit internal non-selectable marker understood by upgraded clients, and old clients must be prevented from receiving that helper forward through compatibility filtering.

### Direction to runtime binding

Client Rules bind `direction_id`, but runtime execution needs a local endpoint/outbound.

Conceptual mapping:

```text
Route Direction
  -> primary Direction Realization
    -> existing compatible runtime forward/listener/outbound
```

Rules:

- User rules never bind `forward_id`.
- User rules never bind `listen_port`.
- User rules never bind internal line/slave/sidecar.
- `direction_id` remains stable and is the user-rule binding key.
- `client_runtime_binding_id` is an opaque machine handle for upgraded client runtimes. It may resolve internally to an existing forward/listener/outbound, but it is never accepted in rule write APIs, never shown in App UI, never shown in plan UI, and never used as a user-selectable product object.
- Every `direction_runtime_bindings[]` entry with `binding_state = ready` must include or reference the existing runtime config material the upgraded client needs to prepare/select the local listener or outbound. That material remains internal, non-selectable, and separate from Client Rule write APIs and the exact-destination rule layer.
- V1 binding payloads use `runtime_ref` plus `local_endpoint`:
  - `runtime_ref.type = forward_ref` means the binding references an existing compatible forward/runtime descriptor already delivered to the upgraded client, but it is marked `selectable = false` for route-rule UI.
  - `runtime_ref.forward_id` is an internal compatibility pointer only; Client Rule write APIs never accept it and App UI never displays it.
  - `local_endpoint.host`, `local_endpoint.tcp_port`, and `local_endpoint.udp_port` describe where the exact-destination layer dispatches matching traffic after the runtime has been prepared.
  - Android may translate the same binding into a sing-box outbound instead of exposing listener details in UI; the payload still describes the machine target the runtime layer must prepare.
  - Linux and Windows use the endpoint as the dispatch target for their exact-destination interception layer.
  - `tcp_ready` and `udp_ready` must both be true for `binding_state = ready`; if either side is false, `binding_state` is not ready and rules using the direction are non-effective.
- The runtime object behind a `client_runtime_binding_id` may change when a direction is remapped.

## Cross-client runtime compatibility

### Shared runtime model

All clients consume one product contract:

```text
Client Rule: DST IP:PORT -> Route Direction ID
Route Direction ID -> runtime binding -> local endpoint/outbound
```

Current-mode reuse boundary:

- Existing current-mode/local-forward runtime can be reused after traffic reaches a local listener/outbound.
- The new exact-destination rule layer decides which listener/outbound receives matching traffic.
- The rule layer is new product behavior; it should be thin and platform-specific.

### Multi-direction sessions

V1 allows multiple rules to use different directions simultaneously in one client session:

```text
93.184.216.34:443    -> 沪日1
8.8.8.8:443   -> 沪港1
1.1.1.1:27015   -> 沪日2
```

Therefore clients cannot rely on a single selected forward/direction model for rule traffic. They need a mapping from each active rule's destination to the local endpoint for that rule's direction.

### Android

Android has the strongest foundation because it already owns packet capture/routing through TUN/sing-box.

Android V1 behavior:

- Continue using the TUN/sing-box foundation.
- Compile Client Rules into exact destination routing rules.
- Prepare or reference one local outbound/listener per Route Direction used by enabled rules.
- Send matching `DST IP:PORT` traffic to the direction's local endpoint.
- Leave non-matching traffic on existing/default behavior.
- Protect control-plane traffic from user rules, including panel login, config pull, artifact mirrors, DNS bootstrap, runtime reachability, local loopback, and endpoint reachability.
- App UI shows only direction label and availability.

### Linux

Linux client-agent already supports multiple local forward/listener runtimes. Its current nftables logic is primarily metering/accounting, not exact-destination transparent interception.

Linux V1 behavior:

- Reuse existing local listener runtime for each direction binding.
- Add a thin exact-destination interception layer that maps matching TCP+UDP `DST IP:PORT` traffic into the correct local listener.
- Do not change non-matching traffic.
- Prevent redirect loops when traffic is already headed to the local listener.
- Keep metering/accounting separate from interception semantics.
- Do not expose listener ports or routing internals to users.

### Windows

Windows Desktop currently manages local forward runtimes but does not provide the same transparent TUN routing foundation as Android.

Windows V1 behavior:

- Reuse existing local forward/listener runtime for each direction binding.
- Add an exact-destination interception/dispatch layer whose observable V1 behavior is: capture only configured matching TCP+UDP `DST IP:PORT` traffic, send it to the local listener for the selected direction when the binding is ready, block that exact destination when the binding cannot be safely installed, and leave non-matching traffic unchanged.
- Do not make users manually connect to local ports.
- Do not expose listener ports, mode names, line names, sidecar names, or internal realization details.
- Treat directions that cannot be represented safely through the compatible listener path as unavailable.

### TCP+UDP all-or-nothing runtime behavior

Because protocol is hidden and V1 rules are product-level TCP+UDP, an enabled rule is effective only when both TCP and UDP for the exact `IP:PORT` can be handled safely for that platform and direction.

- If both TCP and UDP can be intercepted and delivered to the direction binding, the rule may be `active`.
- If either TCP or UDP cannot be safely intercepted or delivered, the rule or direction is non-effective for that platform and matching traffic fails closed.
- V1 does not expose partial TCP-only or UDP-only effective states to users.

### Fail-closed runtime behavior

If a rule cannot be safely applied, it must fail closed for the matching destination.

Examples:

- Direction unavailable.
- Runtime binding missing.
- Local listener not ready.
- Exact-destination interception not installed.
- Plan no longer allows direction.
- Rule over quota or duplicate.
- Route-rule-capable client receives an enabled rule but cannot install the exact-destination layer safely.
- TCP or UDP side of the product-level TCP+UDP rule cannot be safely handled.

Unsupported-client policy:

- Exact-destination fail-closed behavior is required only after a client advertises route-rule capability and receives `client_rules[]` plus `direction_runtime_bindings[]`.
- For unsupported legacy clients, the server must not deliver rule-only helper/runtime forwards as user-selectable legacy `forwards[]` services. Withholding or compatibility-filtering those helpers is the server-side safety behavior for old clients.
- Unsupported legacy clients may continue using valid standalone legacy `forwards[]` services, but they do not participate in Client Rule enforcement.

Fail closed means:

- For traffic matching an enabled Client Rule whose effective state is not active, the client must block/reject/drop only that exact matching destination traffic rather than allowing it to use direct/default routing.
- Non-matching traffic remains unchanged.
- Do not silently route matching traffic through another direction.
- Do not silently route matching traffic through direct/default.
- Do not silently fall back to legacy combination/chain/CF path.
- If stale redirects/listeners from an older binding exist, clients must remove or replace them with a block behavior before reporting the new rule state as installed.
- App shows product-level unavailable/not allowed/error state.
- Admin can inspect internal cause.

## Operations, error handling, and verification

### Direction unavailable behavior

Route Direction may be unavailable because of internal line, slave group, sidecar, realization, plan, runtime, or platform causes.

User behavior:

- App shows the direction label plus a product-level unavailable state.
- App does not show internal cause strings.
- Existing rules remain visible.
- Existing rules do not silently remap or fall back.
- New create/enable/rebind to an unavailable direction is rejected in V1.

Admin behavior:

- Admin sees internal reason, affected plans/subscriptions/rules, last remap, and realization state.
- Admin can remap, disable, retire, or repair the direction.

### Plan changes

#### Direction removed from plan

V1 behavior:

- Existing rules are retained.
- Affected rules become `not_allowed_by_plan` and non-effective.
- App shows `当前套餐不支持该方向`.
- User can edit the rule to an allowed direction or tombstone it.
- Admin can edit, migrate, or disable rules.
- The system does not auto-rebind to another direction.

#### Max rules reduced

V1 behavior:

- Existing records are retained.
- The server marks the affected `service_scope_id` as over quota.
- All non-tombstoned saved rules in that scope are non-effective until resolved.
- The server rejects create/enable/edit operations that would keep or increase the quota violation, except edits that tombstone rules or otherwise reduce the non-tombstoned saved count.
- Admin sees the over-quota state, current count, limit, and affected rules.
- The system does not delete, auto-rebind, or silently reorder rules.

### Admin edits and auditability

Admin can fully edit Client Rules, but validation still applies.

Admin-editable fields:

- Destination IP.
- Destination port.
- Bound Route Direction.
- Enabled state.
- Operational notes or support reason.

Admin cannot edit a rule's `service_scope_id` in V1. Scope changes require tombstone plus recreate in the target scope, with target-scope duplicate, quota, entitlement, destination, and audit validation.

Audit is required for:

- Route Direction create/rename/status changes/remap/retire.
- Internal realization changes.
- Plan allowed direction changes.
- Plan max rule changes.
- User-created rule changes.
- Admin-edited rule changes.
- Server state changes from plan enforcement, over-quota, not-allowed, unavailable, or conflicts.

Audit records should answer:

- Who changed it.
- What object changed.
- Old value and new value.
- When it changed.
- Which user/service/subscription/rules were affected.
- Optional reason/notes.

### Remap blast radius

A Route Direction remap is powerful because existing rules follow the stable direction ID.

Admin remap surfaces must show impact:

- Affected plans.
- Affected users.
- Affected services/subscriptions.
- Enabled rule count.
- Destination examples.
- Current availability.
- Expected post-remap availability.

Remap behavior:

- Rules bound to the remapped direction follow automatically.
- Rules bound to other directions are not affected.
- If remap makes the direction unavailable, bound rules become unavailable/non-effective rather than being rebound.
- Remap is audited.

### Validation errors

Stable product-level errors:

- `duplicate_destination`: same `service_scope_id` already has an enabled rule for the same `IP:PORT` under TCP+UDP.
- `rule_quota_exceeded`: max non-tombstoned saved rules reached or plan reduction creates over-quota state.
- `direction_not_allowed`: direction not allowed by the current plan.
- `direction_unavailable`: direction exists and is allowed but cannot currently be used.
- `invalid_destination_ip`: destination is not a valid IPv4 literal.
- `invalid_destination_port`: destination port is invalid.
- `unsupported_destination_address_family`: destination is IPv6 or another unsupported address family in V1.
- `destination_blocked_by_policy`: destination is syntactically valid but blocked by safety/control-plane policy.
- `service_not_active`: `service_scope_id` cannot currently accept rules.
- `rule_not_found`: requested rule does not exist in scope.
- `version_conflict`: App submitted an old `sync_version` after server/admin changes.

User messages should be product-level, for example:

- `该目标已存在规则`
- `规则数量已达到当前套餐上限`
- `当前套餐不支持该方向`
- `当前方向暂不可用`
- `规则已更新，请刷新后重试`

### Conceptual verification requirements

Future implementation must prove these semantics:

1. Legacy admin product page GET/POST/API routes return 404 or 410 in this edition, including direct URLs, and expose no reachable create/select/save/detail surface.
2. User-facing App, plan, and API surfaces do not expose forbidden internal terms.
3. Admin can create and manage Route Directions with stable IDs, labels, status, and internal mappings.
4. Plan Entitlements enforce allowed directions and max non-tombstoned saved rules per immutable `service_scope_id`.
5. Client Rules sync to the server and server state is authoritative.
6. Admin can view and fully edit user rules, and App sync accepts server/admin changes.
7. Duplicate enabled `IP:PORT + tcp_udp` rules are rejected in the same immutable `service_scope_id`.
8. Direction remap changes runtime outcome for all bound rules without rewriting rule IDs or direction IDs.
9. Direction unavailable, not allowed, over quota, and missing runtime binding states fail closed on route-rule-capable clients; unsupported legacy clients receive server-side compatibility filtering for rule-only helpers.
10. Android, Linux, and Windows share product semantics even though the interception implementation differs.
11. `/api/client/configs/pull` remains backward-compatible for existing clients while upgraded clients receive Route Direction and Client Rule data.

## Open implementation risks for planning

These are design-accepted risks to handle during implementation planning:

- Linux and Windows need a real exact-destination interception/dispatch layer; current local listeners alone are not transparent routing.
- Backward-compatible config must avoid exposing helper runtime forwards as confusing normal services on old clients.
- Route quota overage after plan downgrade needs deterministic effective-state handling in code and UI.
- Admin remap requires clear preview/audit so stable direction IDs do not hide blast radius.
- Hidden diagnostics must not become a second routable control plane.

## Approval state

The design sections were reviewed and approved in conversation before this spec was written:

- Approach: Route Direction facade + Client Rules binding.
- Product objects and information architecture.
- Data/API contract.
- Cross-client runtime compatibility.
- Operations, error handling, and verification semantics.
