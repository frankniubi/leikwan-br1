# Native Outbound Forwards

Native outbound is a **forward type**. The carrier runs on the client device
instead of the panel-managed ingress, so the client never binds the forward's
`listen_port`. It starts an app-private SOCKS5 relay on an ephemeral loopback
port and points its own proxy/TUN stack at that relay.

The panel still owns the forward row: route, transport, direction identity, and
lifecycle. This document is the interface and behaviour reference for that type,
for integration developers and operators. It complements
[user-forward-lines-v1.md](user-forward-lines-v1.md), which describes the shared
v1 surface, and it is intentionally technical: the customer-facing guide set does
not cover native outbound, because a customer experience that depends on which
carrier process runs on the device is not something the guides can promise.

## 1. Availability

A forward can be created as native outbound only when every condition below
holds. Each condition has its own stable failure code so a caller can tell
"not entitled" from "not applicable to this route".

| Condition | Failure when it does not hold |
| --- | --- |
| The selected route resolves to an ultra combination | `422 native_outbound_requires_ultra_tcp_udp` |
| `transport` is `tcp+udp` | `422 native_outbound_requires_ultra_tcp_udp` |
| The service plan sets `allow_native_outbound` | `403 native_outbound_not_allowed` |
| The resolved combination carries a line direction | `422 native_outbound_direction_missing` |
| No other active native forward on the service already uses that direction | `409 native_direction_conflict` |

The service plan feature flags are:

| Flag | Effect |
| --- | --- |
| `allow_native_outbound` | Permits native outbound forwards on the plan |
| `allow_native_outbound_unrestricted` | Additionally permits the unrestricted route policy |

The tenant panel exposes a **Native outbound** checkbox on the forward form, and
that control appears only when the plan flag allows it. On the edit form the
checkbox stays visible for a forward that is already native, so an operator can
always turn the type back off. The v1 API applies exactly the same rule, so a
caller that is not entitled to native outbound gets
`403 native_outbound_not_allowed` rather than a forward that silently behaves
differently from the request.

## 2. Target ownership

The caller does not supply a target for a native forward; the server assigns one:

- `target.source` is always `manual`.
- `target.host` is always `127.0.0.1`.
- `target.port` is a server-chosen port. Callers must not send one and must not
  depend on its value.
- `target.service_id` is `null`.

On create, a `target` object sent together with `native_outbound: true` is not
used: the server writes the fields above. On patch it is rejected instead, so an
existing forward can never keep a target that looks meaningful while the carrier
runs somewhere else.

## 3. Direction identity

The panel derives `native_direction` from the direction recorded on the resolved
route combination: it is lowercased, non-alphanumeric runs collapse to a single
underscore, and the result is truncated to 64 characters.

The identity is unique per service across active native forwards. Creating or
reactivating a second active native forward with the same direction fails with
`409 native_direction_conflict`, and the error details carry the conflicting
`forward_id` plus the redacted `direction` label. The constraint is enforced by
a database unique key as well as by the pre-flight check, so two concurrent
requests cannot both win.

`native_direction` is read-only in the API. It is not accepted on create or
patch: a caller that needs a different direction selects a different route.

## 4. Create

```http
POST /api/v1/user/services/{service_id}/forwards
Authorization: Bearer <user-api-token>
Content-Type: application/json
Idempotency-Key: create-native-20260914-01
```

```json
{
  "route_id": 14,
  "listen_port": null,
  "transport": "tcp+udp",
  "native_outbound": true
}
```

No `target` object is sent. The response is `201` with `Location`, an ETag such
as `"forward-181-r1"`, and the safe resource under `forward`, including
`"native_outbound": true` and the derived `native_direction`.

All other create rules from the shared v1 surface still apply: the required
`Idempotency-Key`, the active-service check, the feature policy check, and the
forward quota. A replay with the same key and the same normalized body returns
the original resource rather than a second native forward.

## 5. Changing the type of an existing forward

Turning native outbound **off** is a patch operation, and it requires a complete
manual target in the same patch:

```json
{
  "native_outbound": false,
  "target": {
    "source": "manual",
    "host": "backend.example.test",
    "port": 8443
  }
}
```

Because the request carries target fields, it takes the structural provisioning
path: existing endpoints are retired only after structural validation succeeds,
and a failure leaves the previous state intact.

Turning native outbound **on** for a forward that already exists is deliberately
not a v1 patch operation. A patch may not carry target fields together with
`native_outbound: true` (that combination fails with `422 validation_failed` and
`fields: {"target": "forbidden_for_native_outbound"}`), and a patch that carries
only the flag cannot perform the direction check, the server-assigned target, or
the plan entitlement check that a native forward requires. Use the panel's
forward form, which runs the full native path, or create a new native forward.

Patch remains a conditional write. `If-Match` is required, and a stale ETag
returns `412 forward_version_conflict` without touching the forward.

## 6. Client runtime contract

A native forward is not an ordinary ultra forward with a different target. The
client keeps the two lifecycles separate, and an integration should assume the
following behaviour:

- The client does **not** bind the forward's `listen_port` for a native forward,
  does not run a listener pre-flight or readiness probe on it, and does not
  request any inbound port, NAT mapping, or firewall opening for it.
- The client excludes native forwards from the ordinary ultra runtime path:
  lease resolution, runtime apply, runtime refresh, restart, listener readiness,
  and listener ownership checks all skip them. A native forward that is sent to
  the ordinary path is refused and logged rather than started.
- The client starts an app-private relay process as a native SOCKS5 facade on an
  ephemeral loopback port. Provider material reaches that process only through
  its protected control channel; the local proxy/TUN stack is pointed at the
  returned loopback endpoint.
- A replacement lease is delivered to the already-running relay. A rejected
  lease alone does not justify tearing down the attached data plane; the relay is
  only restarted when the relay process or its control channel is no longer
  usable.
- The client accepts a carrier only when the route policy and scope digest
  presented by the panel match what the client computed for that forward id,
  direction, transport, and scope. A mismatch is reported as
  `native_route_policy_not_allowed` in the client-agent config flow.

## 7. Failure codes

Native outbound adds these codes to the shared v1 set:

| HTTP | Code | Meaning |
| --- | --- | --- |
| `403` | `native_outbound_not_allowed` | The plan does not set `allow_native_outbound` |
| `409` | `native_direction_conflict` | Another active native forward on the service uses the same direction |
| `422` | `native_outbound_requires_ultra_tcp_udp` | The route is not ultra, or the transport is not `tcp+udp` |
| `422` | `native_outbound_direction_missing` | The resolved route carries no line direction |
| `422` | `validation_failed` (`target: forbidden_for_native_outbound`) | A target was sent while native outbound was requested |

The client-agent config flow additionally reports
`native_route_policy_not_allowed` when a native carrier's policy or scope digest
does not match.

## 8. Operational checks

- The panel-side unique key is `uniq_forwards_native_direction` on
  `(service_id, native_direction_key)`, together with the
  `native_outbound_enabled` and `native_direction_key` columns on `forwards`.
- Clearing native outbound clears `native_direction_key`, so a later native
  forward on the same service can reuse the direction.
- Account reactivation recomputes the direction identity from the combination
  and fails closed with `native_outbound_direction_missing` rather than
  restoring a native forward whose direction can no longer be resolved.
- A native forward and an ordinary forward on the same service are independent:
  retiring one does not retire or renumber the other.
