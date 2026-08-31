# Native Outbound via Ultra Forward Design

**Status:** Approved for implementation on 2026-09-01

## Goal

Allow a subscription to expose an invisible-by-default **原生出口** option. A
client without an imported node can obtain a short-lived standard SOCKS5
endpoint backed by the provider material selected by an assigned Ultra forward,
then use it for full TCP+UDP traffic and TUN mode on Windows, macOS, and
Android. The user may choose whether mainland-China IP ranges bypass the
outbound. Provider identity and provider credentials must not be exposed as
product metadata or persisted client configuration.

## Scope and Non-Goals

### In scope

- An administrator-controlled `allow_native_outbound` package feature flag.
- Reusing an existing assigned active Ultra forward as the native carrier.
- Direction selection from that forward's
  `forward_combination_direction`, including the case where the normal
  terminal direction is currently unavailable.
- A standard SOCKS5 lease with TCP+UDP transport, short expiry, renewal, quota,
  replay protection, and device binding equivalent to the current Ultra lease.
- Native mode visibility and selection in Windows, macOS, and Android clients.
- Existing TUN runtimes and China-IP bypass routing.
- An explicit inventory state for candidates verified to accept standard SOCKS5
  `CONNECT` and UDP `ASSOCIATE`.

### Out of scope

- A new slave or sidecar hop. The topology remains client-to-provider through
  the existing Ultra lease path.
- Persisting provider nodes in the user's node list or exposing provider names.
- Replacing the existing Ultra custom runtime protocol.
- A Linux client implementation in this delivery. Linux compatibility is
  described as a follow-up because the lease contract is platform-neutral.

## Terminology

- **Carrier forward:** an assigned, active forward whose `backend_mode` is
  `ultra`. It supplies the policy generation, direction, quota, and candidate
  pool for a native lease.
- **Native lease:** one ephemeral credential-bound SOCKS5 access grant. It is
  not an Ultra custom-protocol lease and is consumed only by the local
  sing-box runtime.
- **Native scope:** a canonical hash scope used instead of the ordinary
  forward target. Native traffic is arbitrary user traffic, so the forward's
  target is never used as the native destination restriction.

## Entitlement and Visibility

1. Add `allow_native_outbound` to `ProvisioningService::defaultFeatureFlags()`
   with value `false`.
2. The administrator plan form renders it as `原生出口` (and the existing
   English label for the English UI). Saving a plan stores it in the existing
   `service_plans.feature_flags_json` field.
3. Native authorization is **plan-only**. A user-level feature flag cannot
   elevate a plan that does not allow native outbound. Existing generic feature
   flag layering remains unchanged for all other flags.
4. `GET /api/client/configs/pull` adds a top-level `native_outbound` object only
   when the client has at least one assigned active Ultra carrier and the
   service plan allows the feature. The object is omitted entirely otherwise,
   so old clients and disallowed users do not see a disabled menu item.

The object has this shape (all fields are non-secret):

```json
{
  "enabled": true,
  "protocol": "socks5",
  "lease_protocol": "leikwan-ultra-native-socks5-v1",
  "transport": "tcp+udp",
  "forward_ids": [158],
  "default_forward_id": 158,
  "forwards": [
    {
      "forward_id": 158,
      "direction": "intl_to_cn",
      "scope_digest": "<sha256>",
      "transport": "tcp+udp"
    }
  ]
}
```

`forward_ids` is derived from assigned policy, not from client-submitted IDs.
The default is the currently selected eligible forward; if there is no
selection, the lowest stable `forward_id` is used. No endpoint, line ID,
source ID, provider label, username, or password is included.

## Panel Lease Contract

The existing `POST /api/client/ultra/lease` endpoint remains the single entry
point. Each request item gains an explicit `purpose`:

- omitted or `ultra`: preserve the current Ultra behavior and response protocol;
- `native_outbound`: issue a standard SOCKS5 lease.

A native request item is:

```json
{
  "purpose": "native_outbound",
  "forward_id": 158,
  "session_ticket": "<policy ticket>",
  "policy_generation": "<64 hex chars>",
  "nonce": "<fresh hex nonce>",
  "target_digest": "<native scope digest>",
  "transport": "tcp+udp",
  "selection_mode": "single",
  "max_candidates": 1
}
```

`target_digest` is retained for wire compatibility but means the native scope
digest for this purpose. It is computed as:

```text
sha256("native-outbound:v1\n" + forward_id + "\n" + direction + "\n" + transport)
```

The server must validate, in this order:

1. Client agent and active device session.
2. Assigned active carrier forward and active service.
3. Plan-only `allow_native_outbound` entitlement.
4. Policy ticket, generation, expiry, native scope digest, and transport.
5. A candidate marked `native_socks5_state=verified` and compatible with the
   carrier direction.
6. Fresh nonce and quota transaction, using the same persisted replay fence as
   Ultra.

The response lease is:

```json
{
  "protocol": "leikwan-ultra-native-socks5-v1",
  "purpose": "native_outbound",
  "forward_id": 158,
  "lease_id": "<opaque id>",
  "policy_generation": "<64 hex chars>",
  "expires_at": "<UTC timestamp>",
  "transport": "tcp+udp",
  "rate_limit_mbps": 0,
  "quota_remaining_bytes": 0,
  "selection_ref": "<opaque ref>",
  "direction": "intl_to_cn",
  "scope_digest": "<native scope digest>",
  "endpoint": {"ip": "<address>", "port": 0},
  "credential": {"user": "<temporary user>", "password": "<temporary password>"}
}
```

The panel strips all provider-specific fields before serialization. The
protocol constant and `purpose` are the only native-specific identifiers. Lease
TTL remains within the existing Ultra 60-300 second bounds; renewal uses a new
nonce and the current policy ticket. Candidate auto-selection remains bounded
to four candidates when explicitly requested.

## Candidate Capability

Ultra's existing `native_udp_state` is not sufficient evidence of a standard
SOCKS5 server. Add `native_socks5_state` to `ultra_lines`, with the values
`unknown`, `verified`, and `failed`, defaulting to `unknown`. Inventory ingest,
admin normalization, and candidate resolution preserve only those values.

Only `verified` candidates may be returned for `native_outbound`. Verification
must perform both a non-destructive TCP SOCKS5 `CONNECT` probe and a UDP
`ASSOCIATE` probe against the provider server, recording the state and timestamp
without returning probe credentials to clients. A failed or stale verification
causes `no_approved_candidate`; it does not reveal the provider name.

## Direction and Carrier Semantics

- The native lease direction is copied from the carrier forward's normalized
  `forward_combination_direction` and its Ultra line policy.
- Normal endpoint availability, terminal direction bindings, and imported user
  nodes do not gate native visibility or lease authorization.
- The selected carrier forward is persisted only as a client preference. If it
  disappears or loses entitlement, the client clears the preference and returns
  to the normal Services mode.
- A user can create an ordinary Ultra forward and use it as the default carrier;
  no new native-only forward type is introduced.

## Client Runtime Behavior

### Shared rules

- Add a `native` connection mode distinct from user-node `direct` mode and
  service/Ultra mode.
- Native mode requires no imported node. Existing node validation is bypassed
  only for this mode.
- The lease is held in memory and delivered to an ephemeral sing-box config.
  Temporary files are removed on stop, expiry, or startup failure; credentials
  are never written to profile stores, pull snapshots, diagnostics, or normal
  logs.
- `tcp+udp` maps to a SOCKS5 outbound with native UDP support. TUN uses the
  existing platform runtime and full-device route setup.
- The user's existing bypass-China setting controls a direct route for
  `geoip-cn`/`geosite-cn`. Default remains `false`; the setting is available
  while native mode is selected.

### Android

- Extend `ProxyConnectionMode` with `NATIVE` and expose `原生出口` only when
  `PullConfigsResponse.native_outbound.enabled` is true.
- Let the user choose the carrier forward; default to the panel-provided
  `default_forward_id`.
- Resolve a native lease through the existing Retrofit lease call, parse and
  validate the native protocol, then build a temporary SOCKS5 `ProxyNode` or
  equivalent outbound for `SingboxConfigBuilder`.
- Reuse `TunnelOrchestrator` TUN startup, protected connectivity, stop cleanup,
  and bypass-China route construction. Native mode must not start the Ultra
  custom `ultra-client` process.

### Windows

- Add a managed native mode to the shared desktop controller rather than a
  persisted `WindowsProxyProfile`.
- `MasterApiClient` requests and validates native leases; the runtime builder
  writes only an ephemeral SOCKS5 outbound and invokes the existing Windows
  proxy/TUN process host.
- Keep the existing imported-profile UI and storage unchanged. The native mode
  entry is hidden when the capability object is absent.

### macOS

- Add the same managed native mode to `MacConnectionService` and its view model.
- Reuse the existing proxy and VPN process host with an ephemeral SOCKS5
  profile. Do not add native credentials to `MacConnectionProfileStore`.
- Preserve the current macOS permission/error path for VPN/TUN startup.

## Error Handling

The panel uses existing lease error categories where possible:

- `native_outbound_not_allowed` for a plan gate;
- `policy_unavailable`, `policy_generation_invalid`, `policy_scope_invalid`,
  `policy_expired`, and `lease_replayed` for the existing policy checks;
- `no_approved_candidate` when no verified SOCKS5 candidate matches direction;
- `quota_exhausted` when service quota is depleted.

Clients show a generic native-outbound-unavailable message and keep imported
node settings intact. They do not display raw API bodies, endpoint credentials,
source IDs, or provider names.

## Backward Compatibility and Rollout

- The feature flag is false for every existing plan unless an administrator
  explicitly enables it.
- Existing clients ignore the unknown pull field and continue their current
  Ultra and user-node paths.
- Existing Ultra lease requests and protocol validation remain unchanged when
  `purpose` is omitted.
- Revoking the carrier forward generation immediately invalidates both Ultra and
  native leases through the shared lease table.

## Test and Acceptance Criteria

Panel tests must cover default-off plans, plan-only authorization, hidden pull
payloads, native scope digest validation, direction inheritance, candidate
state filtering, nonce replay, expiry, quota, and provider-field scrubbing.

Android and desktop tests must cover capability-gated visibility, no-node native
startup, SOCKS5 TCP+UDP config generation, TUN startup, bypass-China on/off,
lease expiry/renewal, cleanup, and fallback without changing imported profiles.

Manual acceptance requires one approved `intl_to_cn` carrier forward and one
plan with the flag enabled:

1. Pull config shows `原生出口` only for the entitled client.
2. Native mode starts with zero imported nodes and carries TCP traffic.
3. UDP traffic and TUN capture work with bypass-China disabled.
4. Enabling bypass-China sends mainland-China destinations direct while other
   destinations remain on the native SOCKS5 outbound.
5. A client cannot infer `leigod` from pull/lease JSON, UI text, logs, or stored
   profile files.
6. Disabling the plan flag hides the option after the next pull and prevents a
   new lease even if an old carrier forward is still selected.

## Linux Follow-up

The panel contract and native lease format are platform-neutral. A later Linux
agent can add `NATIVE` to its mode enum, feed the same ephemeral SOCKS5
credentials into sing-box TUN, and reuse the existing route/bypass logic. No
Linux-specific schema or API is required.
