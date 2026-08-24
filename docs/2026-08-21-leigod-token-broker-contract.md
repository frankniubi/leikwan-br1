# Ultra Capability and Material Contract

Date: 2026-08-23

Status: Implemented and deployed as an additive `ultra` backend mode. This
document is kept at the historical token-contract path for continuity. The
runtime contract is Panel-integrated; the rejected standalone gateway and
dedicated data/configuration port are not part of the implementation.

## Boundary

There are three roles:

1. The source host obtains short-lived upstream line material and uploads it
   to the Panel. It is outbound-only and never accepts or relays client data.
2. The Panel authenticates the source, stores encrypted material, evaluates
   the active client/device/forward scope, and issues a short-lived local
   capability.
3. The client owns the configured local `IPADDR:PORT` listener. After it has
   authenticated, it requests sealed material for the Panel-approved candidate
   set. The Go binary decrypts the set, detects the local ingress zone, performs
   bounded relay probes, and connects directly to the selected upstream entry
   for the configured destination.

The existing `current_mode` and `leigod_mode` paths remain separate. Only a
forward with `backend_mode=ultra` and an active service enters this contract.
No source address, upstream account, line number, business port, or internal
relay metadata is published in the client capability.

There is no independent gateway process, no standalone listener, and no
`18443` port in this contract. The Panel HTTPS API is the only control and
configuration surface.

## Panel Configuration

The Panel reads these keys from its application environment:

```text
ULTRA_TOKEN_KEY=<32-byte key, hex or base64>
ULTRA_SOURCE_ID=<registered source identifier>
ULTRA_SOURCE_AUTH_TOKEN=<source HMAC secret>
ULTRA_MATERIAL_MAX_TTL_SECONDS=1800
ULTRA_MAX_TTL_SECONDS=1800
ULTRA_REFRESH_SKEW_SECONDS=600
```

`ULTRA_CACHE_KEY` remains an accepted fallback for local compatibility when
`ULTRA_TOKEN_KEY` is not set. Secret values are never placed in repository
files, client configuration, systemd units, or API responses.

The Panel persists encrypted values in `app_settings` under these logical
names:

```text
ultra.material.v1.line.<line-id>
ultra.access.v3.client.<client-id>.device.<device-hash>
ultra.client.scope.v2.<token-hash>
ultra.source.nonces.v1.<source-hash>
ultra.source.tickets.v1.<source-hash>
```

The material and grant caches are encrypted at rest. A cache write failure
does not invalidate a newly issued grant; an expired or malformed value is
discarded and cannot authorize a request.

## Source Authentication

The source signs every request to the Panel. The canonical bytes are the UTF-8
encoding of six newline-separated fields:

```text
METHOD_IN_UPPERCASE
REQUEST_PATH
SOURCE_ID
UNIX_TIMESTAMP
NONCE
SHA256_HEX_OF_RAW_BODY
```

The signature is lowercase hexadecimal HMAC-SHA256 using
`ULTRA_SOURCE_AUTH_TOKEN`. The request carries:

```text
X-Ultra-Source-Id: <source id>
X-Ultra-Timestamp: <unix timestamp>
X-Ultra-Nonce: <16-128 hexadecimal characters>
X-Ultra-Signature: <64 hexadecimal characters>
```

The Panel accepts a five-minute clock window and retains used nonces for ten
minutes. All source requests use HTTPS. The source agent limits control-plane
request retries to three attempts with bounded delays; upload tickets are
single-use so a material upload is not replayed.

## Source Inventory Upload

```http
POST /api/ultra/source/inventory
Content-Type: application/json
```

The source sends a signed, complete inventory snapshot at startup, when its
normalized fingerprint changes, and every 30 minutes. The request is capped at
256 lines and 256 KiB and contains only safe catalog metadata such as line ID,
title/family labels, numeric catalog fields, discovery IP, and configuration
fingerprint. It contains no line-file body, account session, credential, relay
port, or acquired material.

The Panel applies the same HMAC, clock-window, nonce-replay, and HTTPS rules as
the material routes. New rows default disabled. Missing rows are marked
source-absent rather than deleted, and admin category overrides are preserved.

## Source Job Discovery

```http
GET /api/ultra/source/jobs
```

Successful response:

```json
{
  "ok": true,
  "source_id": "source-home-171",
  "line_ids": [455],
  "upload_ticket": "ut1.<opaque-ticket>"
}
```

The Panel returns at most eight due line IDs. The ticket is bound to the
authenticated source and line set and expires after 180 seconds. An empty
line set returns an empty ticket and does not trigger upstream acquisition.

## Source Material Upload

```http
POST /api/ultra/source/materials
Content-Type: application/json
```

Request shape:

```json
{
  "upload_ticket": "ut1.<opaque-ticket>",
  "materials": [
    {
      "line_id": 455,
      "generation": "<material-generation>",
      "acquired_at": "2026-08-23T12:00:00Z",
      "expires_at": "2026-08-23T12:30:00Z",
      "user": "<source-acquired-user>",
      "credential": "<source-acquired-credential>",
      "relays": [
        {"ip": "203.0.113.10", "port": 443}
      ],
      "probe": {"ok": true}
    }
  ]
}
```

The source normalizes literal IP addresses and ports locally, caps each line
at eight relay entries, and removes its temporary acquisition file after
normalization. The Panel validates line range, timestamps, lifetime, relay
addresses, and ticket membership before encrypting the material.

Successful response:

```json
{
  "ok": true,
  "accepted_line_ids": [455]
}
```

The upload payload, upstream credentials, and source acquisition response are
never returned by the client configuration endpoints.

## Client Capability Grant

During the normal authenticated client configuration pull, the Panel finds
the authoritative active line associations for each Ultra forward. The
editable Forward Combination direction is retained as policy metadata, while
the line association itself remains Panel-controlled.

The grant is attached to the normal forward configuration under
`ultra_access`:

```json
{
  "protocol": "leikwan-ultra-capability-v1",
  "token": "<opaque-client-forward-token>",
  "issued_at": "2026-08-23T12:00:00Z",
  "renew_after": "2026-08-23T12:20:00Z",
  "expires_at": "2026-08-23T12:30:00Z",
  "generation": "<material-fingerprint-prefix>",
  "scope": {"client_id": 123, "forward_id": 42},
  "line_ips": ["203.0.113.10", "203.0.113.11"],
  "destination_zone": "tokyo",
  "allowed_ingress_zones": ["beijing", "shanghai", "guangzhou"],
  "fallback_policy": "detected_then_any",
  "zone_aliases": {"shanghai": ["Shanghai"]},
  "transport": "tcp+udp",
  "rate_limit_mbps": 20
}
```

`line_ips` is the only upstream entry information exposed before the client
has completed authentication. It contains normalized literal IP addresses,
not hostnames, line IDs, business ports, credentials, source addresses, or a
gateway address. The Panel returns no business port for line selection; the
Go runtime performs its own bounded probes only after material decryption.
The authenticated runtime configuration also carries the destination/ingress
policy and bounded admin-managed ingress aliases; these contain no credential
or line number.

The token is scoped to `client_id`, `device_id`, and `forward_id`, and expires
no later than the configured maximum TTL or the freshest available material.
The Panel may reuse an unexpired cached grant only while its scope, protocol,
line list, and expiry remain valid.

## Material Resolution

After receiving a grant, the client agent sends an authenticated request to the
existing Panel API:

```http
POST /api/client/ultra/materials
Content-Type: application/json
```

Request:

```json
{
  "forwards": [
    {
      "forward_id": 42,
      "token": "<opaque-client-forward-token>"
    }
  ]
}
```

Response:

```json
{
  "ok": true,
  "materials": [
    {
      "forward_id": 42,
      "token": "<same-opaque-token>",
      "expires_at": "2026-08-23T12:30:00Z",
      "sealed_material": "um1.<authenticated-encrypted-payload>"
    }
  ]
}
```

The Panel does not accept client RTT ordering as policy. If an older client
supplies `ordered_ips`, the field is ignored. The decrypted payload is consumed
only by the local runtime and contains up to eight server-approved entries with
IP, port, source-acquired credentials, effective ingress/destination zones,
opaque `entry_ref` values, and the applicable destination/ingress/fallback
policy. The payload is authenticated encryption keyed from the scoped client
token. A token, device, forward, or expiry mismatch returns no usable material.

## Client Runtime

The installer renders one generic runtime per Ultra forward:

```json
{
  "listen_host": "0.0.0.0",
  "listen_port": 12345,
  "target": "203.0.113.50:443",
  "transport": "tcp+udp",
  "access_file": "/run/ultra/forward-42.access.json",
  "connect_timeout_seconds": 5,
  "rate_limit_mbps": 20,
  "max_tcp_connections": 512,
  "udp_queue_packets": 64,
  "probe_timeout_ms": 800,
  "probe_workers": 4,
  "location_cache_path": "/var/lib/ultra/location.json",
  "status_path": "/run/ultra/forward-42.status.json"
}
```

The local listener is the user-requested `IPADDR:PORT`. TCP attempts the
ranked upstream entries in order. UDP arms a bounded association and uses the
same ranked entries with failover on session setup. Both paths use the binary
runtime and do not require a local SOCKS5 listener or a slave node.

The existing forward `speed_limit_mbps` value is carried into
`rate_limit_mbps` and enforced by the runtime in both directions. Existing
client heartbeat usage collection remains the accounting path, including
`usage_bytes_in` and `usage_bytes_out`; no per-packet database write is added.

## Renewal and Failure Behavior

- The client refresh schedule starts at `renew_after`, normally 600 seconds
  before expiry, with bounded jitter and no per-forward retry thread.
- A material/configuration pull retries up to three times and can use the
  Panel-provided HTTPS mirror list. Mirror endpoints cannot mint a grant or
  lower the accepted configuration generation.
- A failed refresh preserves the currently running service while its grant is
  still unexpired. Once the grant expires, the runtime fails closed.
- A new material grant is applied through the existing dynamic access-file
  path; token refresh does not restart unrelated forwards or legacy modes.
- Candidate probing is capped at eight entries, four concurrent operations,
  and a sub-second probe timeout. A process selects at startup and after a
  material reload; additional reload signals coalesce instead of forming a
  queue.
- TCP admission is capped at 4,096 connections. UDP work is synchronous and
  bounded by the runtime process; no unbounded per-datagram task or retry
  queue is created.

## Management and Accounting

The Panel UI exposes `ultra` as an additional Forward Combination mode and
allows the direction field to be edited. The migration
`20260823_add_forward_combination_line_direction.sql` adds the persisted
direction field without changing existing modes. Line membership is read
authoritatively from the enabled combination associations and is not accepted
from an untrusted client.

`/admin/ultra` exposes bounded Sessions, Lines, Combinations, and Source views.
Heartbeat status is latest-state only. The Panel discards a reported relay that
is not in the forward's currently approved membership, resolves an accepted
relay to the safe admin line label/ID server-side, and never renders an opaque
reference as a line number.

The existing service speed-limit field remains authoritative. The existing
client heartbeat and provisioning usage paths remain in place, so Ultra does
not require a new accounting port, a new listener, or a second statistics
pipeline.

## Deployment Boundary

The source-only deployment on host 171 consists of an outbound agent, a
root-only configuration, the source runtime binary, line files, and a
root-only session file. Its systemd unit has no `ListenStream`, UDP socket, or
data-plane address. The Panel deployment contains the routes, migration,
encrypted material service, and client artifacts.

The production acceptance evidence is:

1. signed source inventory accepted 110 lines and newly discovered rows
   remained disabled by default;
2. unauthenticated source/material endpoints returned `401`;
3. public Panel and artifact requests returned the expected successful or
   authentication-redirect status;
4. the published Linux client is an ELF binary whose SHA-256 matches the
   manifest;
5. source 171 owns no client data listener or connection after legacy
   data-plane units were stopped and disabled;
6. an isolated canary on `172.21.255.189` passed three UDP and three TCP DNS
   queries for each of all six configured destination families. The
   credential-free results are in
   `docs/reports/2026-08-24-ultra-six-direction-dns-report.md`.

## Security Limits

The client binary is stripped and uses generic runtime labels, which reduces
accidental disclosure. It cannot make the client an impossible reverse-
engineering target. The actual security boundary is short-lived scoped
capabilities, encrypted material, source authentication, replay protection,
server-side line association, expiry, and fail-closed behavior.

Never log tokens, source credentials, material payloads, or request bodies.
Never publish source addresses or line IDs in client grants. Never fall back
to a legacy account or route when Ultra material is missing. Never add an
unbounded retry, packet, connection, or statistics queue.
