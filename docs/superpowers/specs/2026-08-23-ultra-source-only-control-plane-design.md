# Ultra Source-Only Control Plane Design

Date: 2026-08-23

Status: Implemented and deployed. The Panel control plane and client runtime
are integrated; 171 runs the outbound-only source agent. No independent Ultra
data gateway or dedicated data/configuration port is part of this design.

## Objective

Add `ultra` as an additive backend mode, separate from `current_mode` and
`leigod_mode`, for forwarding a configured destination `IPADDR:PORT` through
the authenticated line material acquired by an approved source host.

The client owns the local `IPADDR:PORT` listener. After authentication, it
receives only safe line-IP candidates plus a scoped capability. Material
resolution seals the server-approved candidate set without accepting a client
ordering. The Go binary decrypts that set locally, detects the client's ingress
zone, performs bounded relay probes, and connects directly to the selected
upstream line.

## Invariants

- 171 is an outbound-only source. It never accepts client traffic and never
  appears in client configuration as a data endpoint.
- The Panel HTTPS API is the only Ultra control/configuration surface.
- There is no independent gateway process, `18443` listener, or standalone
  data-plane service.
- Existing `current_mode` and `leigod_mode` behavior remains separate.
- Client responses expose line IPs only. Business ports, line IDs, upstream
  credentials, and implementation-specific relay metadata stay server-side or
  inside a sealed client material blob.
- A missing or expired grant/material fails the Ultra runtime closed.
- CPU, connection admission, retry count, and UDP work queues are bounded.

## Topology

```text
source host 171
  outbound HTTPS control request + local upstream acquisition
        |
        v
central Panel HTTPS API
  signed source upload -> encrypted material in app_settings
  client/device/forward grant -> line IP candidates only
        |
        v
authenticated client
  local IPADDR:PORT listener
  material request and sealed approved candidate set
  bounded ingress-aware RTT ranking in the binary
  direct TCP or UDP connection to an upstream line
        |
        v
destination IPADDR:PORT
```

## Panel Control Plane

Routes added to the existing Panel router:

```text
GET  /api/ultra/source/jobs
POST /api/ultra/source/materials
POST /api/ultra/source/inventory
POST /api/client/ultra/materials
```

Source requests authenticate with an HMAC over method, path, source ID,
timestamp, nonce, and the SHA-256 body digest. Timestamps have a bounded
five-minute window and nonces are replay-protected in `app_settings`.

`GET /api/ultra/source/jobs` returns at most eight due line IDs and a short
upload ticket. `POST /api/ultra/source/materials` accepts only ticket-scoped,
short-lived material and stores it encrypted. The source upload payload is
never returned to clients.

The Panel issues a short-lived local grant for each active Ultra
client/device/forward scope. The grant contains:

```json
{
  "protocol": "leikwan-ultra-capability-v1",
  "token": "opaque-short-lived-token",
  "issued_at": "2026-08-23T12:00:00Z",
  "renew_after": "2026-08-23T12:20:00Z",
  "expires_at": "2026-08-23T12:30:00Z",
  "generation": "material-fingerprint-prefix",
  "scope": {"client_id": 123, "forward_id": 42},
  "line_ips": ["203.0.113.20", "203.0.113.21"],
  "destination_zone": "tokyo",
  "allowed_ingress_zones": ["beijing", "shanghai", "guangzhou"],
  "fallback_policy": "detected_then_any",
  "transport": "tcp+udp",
  "rate_limit_mbps": 20
}
```

The grant has no business port, line ID, username, password, relay port,
gateway address, or target destination. The existing provisioning speed-limit
field is carried into `rate_limit_mbps`; heartbeat usage accounting remains in
the existing client heartbeat path.

## Client Material Resolution

The client agent sends a bounded batch to `/api/client/ultra/materials` after
the normal authenticated config pull:

```json
{
  "device_id": "device-instance-id",
  "forwards": [
    {
      "forward_id": 42,
      "token": "opaque-short-lived-token"
    }
  ]
}
```

The Panel verifies client authentication, device session, forward assignment,
token scope, expiry, and active Ultra status. A supplied `ordered_ips` field is
ignored. The Panel returns an opaque `um1.<base64url>` sealed blob keyed from
the grant token. Its cleartext is limited to at most eight approved entries,
their ingress/destination zones, opaque `entry_ref` values, and the fallback
policy; it is never serialized into the normal grant response.

The Go runtime ranks at most eight decrypted entries with at most four
concurrent probes whenever it starts or reloads material. Material resolution
retries at most three times with short backoff. Control-plane pulls try the
primary HTTPS URL followed by the configured HTTPS mirrors. Near expiry, the
existing poll scheduler reduces its interval to 5-10 seconds without a busy
loop.

## Source Agent on 171

Installed paths are generic and root-only:

```text
/usr/local/bin/ultra-source-agent.py
/etc/ultra-source-agent.json
/etc/systemd/system/ultra-source-agent.service
/opt/ultra-source/runtime/line-client
/opt/ultra-source/lines/*.toml
/var/lib/ultra-source-agent/session.json
```

The service has no socket activation, listener directive, or traffic role. It
sequentially acquires at most eight requested lines, deletes temporary result
files, uploads only normalized short-lived material, and retries the read-only
job request at most three times. Its session and line catalog are isolated from
the legacy runtime paths.

## Client Runtime

The Panel distributes the static ELF artifact
`/artifacts/ultra/ultra-client_linux_amd64`. The client agent renders one
generic `ultra-forward-N` unit per active forward:

```text
/usr/local/bin/ultra-client --config /etc/ultra/forward-N.json
```

The static config contains the local listener, destination, transport,
connection cap, UDP queue bound, timeout, and rate limit. The dynamic access
file contains only the opaque token, expiry, and sealed material. Access-file
changes are written atomically and sent with `SIGHUP`; they do not restart the
unit.

TCP uses the direct upstream stream handshake and bounded connection semaphore.
UDP uses one bounded session per forward, a fixed-size envelope, and a bounded
local association path. Both directions use the existing byte limiter. The
binary is stripped and built with `CGO_ENABLED=0`; this reduces symbols and
labels but cannot make reverse engineering mathematically impossible.

## Rotation and Failure Behavior

The source material lifetime is 30 minutes by default. The Panel renews grants
before expiry and keeps a valid encrypted cache during a source outage. The
client retries the Panel request near expiry and preserves an existing runtime
until the sealed access file expires. If no valid material is available, new
connections are rejected and no fallback gateway is started.

## Production Evidence

- Panel PHP-FPM remained active after the additive schema and application
  deployment; public root, admin redirect, and artifact requests returned 200
  or the expected authentication redirect.
- Unauthenticated source and client-material routes returned 401.
- The published Linux ELF SHA-256 matched `artifacts/versions.json`.
- Source agent `2026.08.24.1` uploaded a complete 110-line inventory; all newly
  discovered lines defaulted disabled.
- The source one-shot cycle consumed about 0.388 CPU seconds with about 15 MiB
  RSS and created no listener.
- Legacy data-plane units and abandoned sessions found on 171 were stopped and
  disabled. A fresh listener/connection audit showed only normal system
  services and no client data-plane connection; the source agent remained a
  one-task outbound-only service.
- The isolated Linux canary on `172.21.255.189` passed three UDP and three TCP
  DNS queries through each of Singapore, Hong Kong, Tokyo, Frankfurt, Los
  Angeles, and Moscow. See
  `docs/reports/2026-08-24-ultra-six-direction-dns-report.md`.
- No standalone Ultra gateway or `18443` listener is present.

## Rollback

The Panel release backup is
`/root/codex-backups/ultra-20260824-051927`; the status-validation hotfix backup
is `/root/codex-backups/ultra-status-relay-validation-20260824-054845`. The 171
agent backup is `/root/codex-backups/ultra-source-20260824-052523`, and the
pre-enforcement unit/listener snapshot is
`/root/codex-backups/source-only-enforcement-20260824-055050`. Restore only the
required files, reload the affected PHP-FPM unit or stop/disable the source
agent, and verify public health, listeners, unit ownership, and artifact hashes
before re-enabling Ultra assignments. Re-enabling the legacy 171 data-plane
units would violate the source-only invariant and is not a normal rollback.
