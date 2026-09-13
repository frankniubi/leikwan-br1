# API documentation refresh and the Native Outbound reference

Date: 2026-09-14
Scope: `docs/api`, `master/README.md`
Status: documentation only. No application code, database row, or published
artifact was changed by this work.

## What was wrong

`docs/api/user-forward-lines-v1.md` was last written on 2026-08-02, before the
native outbound work landed. The v1 contract accepts a `native_outbound` field on
create and patch (`ForwardLineApiContract::normalizeCreate` /
`normalizePatch`) and presents `native_outbound` plus `native_direction` on every
forward resource (`presentForward`), but the reference did not mention either
field. An integrator reading the document could not tell that a forward type
exists at all.

The document also still framed the tenant boundary with vocabulary the product no
longer exposes to customers — Sidecar, MPTCP, bond.

## What was written

`docs/api/native-outbound.md` (new). The interface and behaviour reference for
the forward type: availability preconditions and their individual failure codes,
plan feature flags, server-assigned target ownership, the direction identity and
its per-service uniqueness, create and patch shapes, the client runtime contract,
the added failure codes, and the operational checks.

`docs/api/user-forward-lines-v1.md` (updated). Native outbound added to the
resource example and its explanation, to create, to patch, and to the error-code
table; the tenant boundary paragraph reworded to operator-owned ingress and
transport endpoints.

`master/README.md`. Index entry for the new document, and one unrelated broken
link fixed: the WHMCS sync link pointed at a local Windows path
(`C:\Users\...\leikwan-v3\master\scripts\whmcs_sync.php`) instead of the
repository file.

## Verified contract facts

Every statement in the new document was read out of the code before it was
written:

- `ForwardLineApiContract::normalizeCreate` ignores a supplied `target` when
  `native_outbound` is true, and requires `route_id`, `listen_port`, and
  `transport` regardless.
- `ForwardLineApiContract::mergePatch` rejects any patch that mixes native
  outbound with target fields: `422 validation_failed`, `target:
  forbidden_for_native_outbound`.
- `ProvisioningService::saveForward` enforces ultra plus `tcp+udp`
  (`native_outbound_requires_ultra_tcp_udp`), the plan flag
  (`native_outbound_not_allowed`), a resolvable direction
  (`native_outbound_direction_missing`), and per-service direction uniqueness
  (`native_direction_conflict`), then assigns `target_host = 127.0.0.1` and a
  server-chosen target port.
- `nativeDirectionKey()` slugifies the combination's line direction and truncates
  it to 64 characters; `uniq_forwards_native_direction` is the database side of
  the same rule.
- `ClientAgentController` binds the native capability to a scope digest over
  forward id, direction, transport, scope, and policy, and reports
  `native_route_policy_not_allowed` on a mismatch.
- `NativeOutboundRelayHost` runs the app-private relay as a native SOCKS5 facade
  and points sing-box at the returned loopback endpoint.
- The tenant forward form shows the Native outbound checkbox only when the plan
  allows it, and keeps it visible on edit for a forward that is already native.

## Open defect found while documenting

The v1 patch path cannot turn native outbound **on** for an existing forward, and
it fails in a way that looks like success.

`ForwardLineApiController::patch` decides between the structural and
non-structural provisioning paths at lines 169-174. The structural condition
covers `forward_route_id`, `target_source`, `target_service_id`, a changed
`transport_mode`, and `listen_port = 0`. It does **not** cover
`native_outbound`.

A patch body of `{"native_outbound": true}` therefore produces
`changes = ['native_outbound' => true]` and takes the non-structural path.
`ForwardLineApiContract::mergePatch` returns the native shape with
`target_host = '127.0.0.1'` and the previous `target_port`, and
`ProvisioningService::updateForwardNonStructural` writes exactly four columns —
`listen_port`, `target_host`, `target_port`, `transport_mode`. It never writes
`native_outbound_enabled` or `native_direction_key`.

The caller gets `200` with `native_outbound: false` in the response, and the
forward has been rewritten to point at `127.0.0.1:<old target port>` — the
self-referencing shape that the 2026-09-11 forward 180 incident was about. The
panel's own form does not have this problem: `TenantController` includes
`native_outbound` in its structural decision.

Reproduced against the real contract classes with an ordinary forward
(`target_host = 203.0.113.9`, `target_port = 443`, `native_outbound_enabled = 0`)
and the patch body `{"native_outbound": true}`:

```text
changes={"native_outbound":true}
structural=false
merged.target_host=127.0.0.1
merged.target_port=443
merged.native_outbound=true
merged has native_outbound_enabled key: no
```

The reference documents the current behaviour honestly — the new document states
that enabling native outbound on an existing forward is not a v1 patch operation
and directs the caller to the panel form or to a fresh create — but the endpoint
should refuse the request rather than accept it and misconfigure the forward.

Proposed fix, not applied here because this round was scoped to documentation:

1. Add `array_key_exists('native_outbound', $changes)` to the structural
   condition in `ForwardLineApiController::patch`, which routes the change through
   `saveForward` and its native validation.
2. Add a regression test asserting that `PATCH {"native_outbound": true}` either
   records the native type and direction or fails with a stable code, and that it
   never leaves an ordinary forward pointing at `127.0.0.1`.

## Customer-facing guides

Native outbound is intentionally absent from the knowledge base. A customer does
not need to know which carrier process runs on the device, and the plan flag — not
the guide set — is what makes the option appear.
