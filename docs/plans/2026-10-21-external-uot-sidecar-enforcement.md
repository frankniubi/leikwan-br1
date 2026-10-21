# External UoT sidecar enforcement (2026-10-21)

## Context

A code audit on 2026-10-21 found three problems:

- **Speed limits are client-side only.** Every forward mode enforces its speed limit only in the client.
- **Ultra billing is self-reported.** Ultra billing (`recordUltraUsage`) trusts usage that the client reports about itself.
- **The external UoT static token outlives the service.** The token is one per combination and has no expiry. It also stays valid after a forward or service is disabled, suspended, deleted, or expired. `ExternalUotConfigController` never filters on forward or service state, and the standalone merge never removes entries. On 2026-10-21, production still published 128 non-live forwards with a valid token.

In production, 36 of 38 live forwards are Ultra with external UoT. Only their **UDP** passes through our sidecar. Their TCP goes straight to the third-party LeiGod relay, which we cannot meter or limit on the server. The owner chose this scope:

1. **Stop publishing forwards that are not live.** Revoke them on the sidecars and kill their live sessions.
2. **Use one HMAC token per forward, with no expiry.** Revocation happens through config removal. The sidecar meters UDP bytes per forward and the panel collects them. For UDP billing, the panel takes `max(client-reported, sidecar-measured)` per forward per day. No client release is needed.
3. **Enforce a per-forward speed limit on the sidecar,** in both directions.

TCP over LeiGod is out of scope. The client limiter and `ultra_usage` reporting stay as they are.

## Contract (all components must follow this exactly)

### C1. A forward is "live"

A forward is live when all of the following hold:

```
forwards.enabled = 1
AND forwards.user_deleted_at IS NULL
AND forwards.balance_suspended_at IS NULL
AND services.status = 'active'
AND (services.expire_at IS NULL OR services.expire_at > UTC_TIMESTAMP())
AND forward_combinations.external_uot_enabled = 1
```

### C2. Per-forward sidecar config entry

This lives under `forwards.<id>` in the sidecar JSON and in the pool or standalone document.

```json
{
  "secret": "<combination external_uot_hmac_secret, 64 hex chars>",
  "token": "<combination static external_uot_token>",
  "targets": ["host:port", "..."],
  "rate_limit_mbps": 100
}
```

- **`secret`** is the HMAC key: the UTF-8 bytes of the hex string, exactly as the Go `config.go` reads it today.
- **`token`** is present only while `EXTERNAL_UOT_ACCEPT_STATIC_TOKEN` is truthy. The default is true during rollout. It is turned off once clients have picked up HMAC tokens.
- **`rate_limit_mbps`** is optional. A value of `0` or a missing field means unlimited. 1 Mbps = 1,000,000 bit/s, which matches `tools/ultra-client/policy.go`.
- An entry is valid when it has a non-empty `secret` or a non-empty `token`, plus a non-empty `targets`.

### C3. Client token

The panel hands the client this token as an opaque string:

```
token = "hmac-sha256:" + lowercase_hex(HMAC_SHA256(key = secret_utf8, msg = "<forward_id>\n<normalizeTarget(target)>"))
```

- `normalizeTarget` must match `tools/uot-sidecar/protocol.go::normalizeTarget` byte for byte:
  1. Trim the value.
  2. Accept `[v6]:port` or `host:port`, splitting on the last colon.
  3. Lowercase and trim the host. Reject an empty host or a host that contains whitespace.
  4. The port must be an integer from 1 to 65535.
  5. If the host parses as an IP, use its canonical form (Go `netip.Addr.String()`).
  6. The result is `host + ":" + port`, with no brackets even for IPv6.
- Cross-check against the Go `--print-token` output in tests.
- A combination with no `external_uot_hmac_secret` keeps handing out the static token. That is the legacy behaviour.

### C4. Sidecar behaviour (Go `tools/uot-sidecar`)

- **Metering.** The sidecar keeps cumulative UDP payload byte and packet counters per forward id, shared across all sessions of that forward. There are two directions:
  - `to_target`: client to target, counted at the `sendToUDP` write.
  - `from_target`: target to client, counted when a datagram is accepted for delivery.
  - Datagrams dropped by the rate limiter are counted separately as `rate_limited_drops`.
- **Usage file.** The sidecar writes the counters every 10 s, and on clean shutdown, as an atomic replace with mode 0600. The path comes from `--usage-file`; the default is `/var/lib/leikwan-external-uot/usage.json`; an empty string disables it. Format:

  ```json
  {"version":1,"instance":"<32 hex, random per process start>","started_at":"RFC3339","written_at":"RFC3339",
   "forwards":{"<id>":{"to_target_bytes":0,"from_target_bytes":0,"to_target_packets":0,"from_target_packets":0,"rate_limited_drops":0}}}
  ```

  Counters are cumulative for the life of that `instance`. Forwards removed from config keep their final counters in the file until the process restarts.
- **Rate limit.** Each forward with `rate_limit_mbps > 0` gets one token bucket per direction, shared by all its sessions on this process.
  - The bucket's rate is `rate_limit_mbps * 1e6 / 8` bytes/s, with a 1 s burst.
  - **Client to target:** wait for tokens for at most 250 ms. This blocks the carrier reader and so pushes back on TCP. If the wait would exceed 250 ms, drop the datagram.
  - **Target to client:** the same, waiting before enqueue.
  - A reload that changes the limit updates the existing buckets in place.
- **Revocation.** On every successful reload, re-verify each live session's original HELLO credentials (token, forward_id, target) against the new snapshot. Close every session that no longer verifies, including its carriers.
  - This replaces today's behaviour, where a removed forward's sessions are kept and may reattach.
  - `authorizeHello` must no longer allow a reattach to a binding that is absent from the current snapshot.
  - Rotating a forward's credentials therefore disconnects sessions that hold the old token. That is intended.

### C5. Standalone hosts (SSH forced command `sidecar-agent/external_uot_forward_merge.py`)

- **Accepted entries.** `valid_entry` accepts `secret` and/or `token`, plus `targets`, and passes `rate_limit_mbps` through. Bounds: secret and token must be non-whitespace and at most 8192 chars; `rate_limit_mbps` must be a number from 0 to 100000.
- **Removal.** The document may contain `"remove": [<forward ids>]`. Those entries are deleted. Forwards neither listed nor removed are still kept, as today.
- **Usage pull.** When `SSH_ORIGINAL_COMMAND` is exactly `usage`, the script prints the usage file and exits 0. If the file is missing it prints `{}`. Any other non-empty command keeps today's merge behaviour, reading stdin.

### C6. Pool nodes (`sidecar-agent/uot_pool_sync.py`)

- The heartbeat body gains `"usage": <usage file document or null>`.
- The probe uses `binding.token` when present. Otherwise it computes the C3 HMAC token from `binding.secret` for the probe target.

### C7. Panel

- **Migration.** `master/migrations/20261021_external_uot_enforcement.sql` adds:
  - `forward_combinations.external_uot_hmac_secret VARCHAR(128) NULL`.
  - `external_uot_usage_counters (source VARCHAR(191), instance_id CHAR(32), forward_id BIGINT, to_target_bytes BIGINT UNSIGNED, from_target_bytes BIGINT UNSIGNED, updated_at DATETIME, PRIMARY KEY(source, instance_id, forward_id))`. This holds the last cumulative values seen.
  - `external_uot_usage_daily (forward_id BIGINT, usage_date DATE, service_id BIGINT, sidecar_bytes BIGINT UNSIGNED DEFAULT 0, client_bytes BIGINT UNSIGNED DEFAULT 0, billed_extra_bytes BIGINT UNSIGNED DEFAULT 0, reconciled_at DATETIME NULL, updated_at DATETIME, PRIMARY KEY(forward_id, usage_date))`.

  Mirror all of this in `schema.sql`. Each `ALTER` must be a single-ADD statement, because the runner ignores duplicate errors only in that case.
- **Secret.** Generated lazily as `bin2hex(random_bytes(32))` the first time a UoT-enabled combination is rendered or issued, and written once.
- **`ExternalUotConfigController`.**
  - Every render path (point, pool, standalone) filters forwards by C1.
  - It emits C2 entries, with `rate_limit_mbps` computed the same way as `buildClientConfig`: the smaller positive value of `services.bandwidth_limit_mbps` and `clients.speed_limit_mbps` via `forwards.line_client_id`.
  - The standalone render also returns `remove`: ids of non-live forwards in the combinations this host serves.
- **`cli/external-uot-standalone-sync.php`.**
  - Pushes `{"forwards":…, "remove":[…]}`.
  - Every run, also executes `ssh … usage` (the same forced command; `SSH_ORIGINAL_COMMAND=usage`) and ingests the result with source `standalone:<host>`.
  - A pull failure only logs.
- **Pool heartbeat.** Ingests `usage` with source `pool:<node id>`.
- **Ingest.**
  - For each forward: if the stored row has the same `(source, instance)` and the new value is at least the stored one, `delta = new - stored`; otherwise `delta = new`, because it is a new instance.
  - Upsert the counters, then add `delta(to + from)` to `external_uot_usage_daily.sidecar_bytes` for today's UTC date, with `service_id` taken from the forward.
  - Never touch `services` counters here.
- **Client token issuance.** The external UoT token the client receives in the grant or lease becomes the C3 HMAC token. Do this in `ProvisioningService::ultraLineMetadataForAssignedForwards`, where the token is set, so the broker and lease paths carry it unchanged.
- **`cli/external-uot-usage-reconcile.php`** (cron, hourly).
  - For each `external_uot_usage_daily` row whose date ended at least 2 h ago and that is not reconciled:
    1. `client_bytes` = SUM(`ultra_usage_events.bytes_in + bytes_out`) for that forward with `created_at` in that UTC date.
    2. `extra = max(0, sidecar_bytes - client_bytes - tolerance)`, where tolerance = `max(1 MiB, 2% of sidecar_bytes)`.
    3. If `extra > 0`, add it to the service's `traffic_used_bytes` and `billing_cycle_traffic_used_bytes`, using the same helpers and quota-suspension path as `recordUltraUsage`.
    4. Set `billed_extra_bytes` and `reconciled_at`.
  - The CLI is idempotent and supports `--dry-run` and `--date`.

## Rollout order

1. **Standalone hosts.** Install the new merge script, then the new sidecar binary. The restart briefly drops sessions, and clients reattach within grace.
2. **Panel.** Deploy the migration, controllers and CLIs with `EXTERNAL_UOT_ACCEPT_STATIC_TOKEN=1`. Clients receive HMAC tokens; Linux and desktop restart that forward once.
3. **Cron.** Add the reconcile job.
4. **Pool node 117.55.193.163.** Needs owner-provided SSH access. Until then it accepts HMAC tokens through the existing binary, but has no metering, rate limit, or revocation kill.
5. **Static tokens.** After 24-48 h, set `EXTERNAL_UOT_ACCEPT_STATIC_TOKEN=0` and rotate the static tokens.
