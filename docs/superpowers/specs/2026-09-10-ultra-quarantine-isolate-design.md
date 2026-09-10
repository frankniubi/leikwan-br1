# Ultra Candidate Quarantine and Runtime Isolation Design

**Status:** Approved; core implementation landed behind the dynamic filter flag

**Date:** 2026-09-10

## 1. Summary

Extend the `current_mode` quarantine/isolate idea to Ultra, but keep the two
responsibilities separate:

- **Server-side quarantine** temporarily removes a repeatedly failing Ultra
  candidate from newly issued leases.
- **Client-side isolation** immediately stops assigning new traffic to the
  currently selected failing candidate while preserving healthy candidates.

The server must make the final candidate decision before issuing a lease. A
client-only implementation would still allow the same bad line to be issued to
new devices and after every lease refresh.

This design is intentionally limited to dynamic failure handling. Permanent
operator removal remains the existing `ultra_lines.is_enabled` and
`forward_combination_ultra_lines.enabled` workflow.

## 2. Motivation

The recent CAN -> HKG incident exposed a failure class that static catalog
state does not catch: a line can pass a provider or inventory health check but
fail the real TCP/UDP data path. Such a line remains eligible and is repeatedly
sealed into Ultra candidate sets unless the operator disables it manually.

The existing Ultra flow has these relevant properties:

- `source-agent` periodically uploads bounded inventory from local line files,
  `index.json`, and optional catalog/audit caches.
- `UltraInventoryService::acceptInventory()` upserts safe line metadata and
  marks omitted rows source-absent on a complete snapshot.
- `UltraMaterialService::dueLineIds()` renews material only for lines already
  required by active Ultra combinations.
- `ProvisioningService` and `UltraMaterialService` resolve only active,
  source-present, enabled, verified, fresh candidates.
- `selection_ref` is scoped and ephemeral; it is not a stable line identity.

Therefore, dynamic reputation must be attached to a stable line identity and
consulted during candidate resolution.

## 3. Goals

1. Stop repeatedly issuing candidates with corroborated data-plane failures.
2. Preserve availability when one candidate fails.
3. Avoid globally quarantining a line because of one noisy client, one target,
   or one transient timeout.
4. Keep static administrator controls authoritative and reversible.
5. Make every state transition observable and explainable without exposing
   credentials, tokens, or relay ports to normal client telemetry.
6. Keep the first rollout compatible with existing Ultra leases and clients.

## 4. Non-goals

- No automatic provider account creation or upstream line purchase.
- No automatic modification of `forward_combination_ultra_lines` memberships.
- No migration of existing connections when a faster candidate appears.
- No use of `process_stderr` as proof of a line or process failure.
- No global quarantine based only on target-side `connection refused`, DNS
  failure, or a client-local listener/process error.
- No reuse of the `current_mode` endpoint key or the ephemeral `selection_ref`
  as the Ultra reputation key.

## 5. Terminology and Identity

### 5.1 Stable candidate key

The primary reputation key is:

```text
source_id + line_id + ingress_zone + destination_zone + protocol
```

`forward_id` or a target digest may be added when the evidence is known to be
target-specific. The minimum route scope must prevent a CAN -> HKG failure from
silently quarantining an unrelated SHA -> TYO use of the same provider line.

The key must not contain raw user credentials, lease tokens, or relay ports.

### 5.2 Static versus dynamic state

| State | Owner | Meaning | Lifetime |
| --- | --- | --- | --- |
| `is_enabled=0` | Operator | Explicit catalog removal | Until operator restores it |
| Membership `enabled=0` | Operator | Removed from one combination | Until operator restores it |
| `healthy` | Reputation service | Eligible for normal issuance | Ongoing |
| `probation` | Reputation service | Canary issuance allowed | Short, bounded window |
| `quarantined` | Reputation service | Excluded from new leases | TTL with backoff |
| `isolated` | Client runtime | Current process avoids candidate | Local runtime scope |

Dynamic state must never overwrite the static administrator fields.

## 6. Server-Side Reputation State

Prefer a dedicated table instead of reusing the current bond-leg table without
changing its identity model. A first implementation can use:

```text
ultra_candidate_reputation_current
  id BIGINT UNSIGNED PRIMARY KEY
  source_id VARCHAR(191) NOT NULL
  line_id INT UNSIGNED NOT NULL
  ingress_zone VARCHAR(32) NOT NULL
  destination_zone VARCHAR(32) NOT NULL
  protocol VARCHAR(16) NOT NULL
  state VARCHAR(16) NOT NULL DEFAULT 'healthy'
  consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0
  consecutive_successes INT UNSIGNED NOT NULL DEFAULT 0
  independent_observers INT UNSIGNED NOT NULL DEFAULT 0
  backoff_level INT UNSIGNED NOT NULL DEFAULT 0
  quarantine_until DATETIME NULL
  last_reason VARCHAR(64) NULL
  last_observed_at DATETIME NULL
  last_good_at DATETIME NULL
  sample_count INT UNSIGNED NOT NULL DEFAULT 0
  created_at DATETIME NOT NULL
  updated_at DATETIME NOT NULL
  UNIQUE KEY (source_id, line_id, ingress_zone, destination_zone, protocol)
```

Append-only events should retain the transition evidence:

```text
ultra_candidate_reputation_events
  reputation_id BIGINT UNSIGNED NOT NULL
  event_type VARCHAR(32) NOT NULL
  reason VARCHAR(64) NOT NULL
  observer_hash CHAR(64) NULL
  lease_generation_hash CHAR(64) NULL
  target_scope_hash CHAR(64) NULL
  observed_at DATETIME NOT NULL
```

Only hashes and bounded reason codes belong in the event row. Do not persist
client credentials, sealed material, or relay ports.

## 7. Failure Evidence

### 7.1 Evidence that may affect reputation

- Repeated provider handshake or authentication failure for the same candidate.
- Repeated UDP association failure or no reply after bounded probes.
- Repeated TCP connect/handshake timeout at the relay path.
- Repeated stream reset or timeout after the relay path is known to be open.

### 7.2 Evidence that must be classified separately

- One transient timeout.
- Target application refusal or target-side policy rejection.
- Client-local bind, process, DNS, or firewall failure.
- Generic `process_stderr` events without a classified data-plane result.
- Provider catalog or control-plane metadata alone.

The client report must carry a bounded reason code, protocol, route scope,
timestamp, and opaque lease/selection reference hash. Reports are authenticated
by the existing client session and rate-limited per device and candidate.

## 8. State Machine and Default Policy

```text
healthy
  -- 2 consecutive local data-plane failures --> probation
probation
  -- corroborated failure threshold ----------> quarantined
probation
  -- 3 successful canary probes --------------> healthy
quarantined
  -- TTL expiry ------------------------------> probation
quarantined
  -- failure during probation ----------------> quarantined with backoff
```

Recommended initial values:

- Client local isolation: 2 consecutive classified failures.
- Global quarantine: 3 failures in a short window from at least 2 independent
  leases/devices, or an equivalent server-side corroboration rule.
- Initial quarantine TTL: 30 minutes.
- Recovery: issue one or two canary leases after expiry; 3 successes restore
  `healthy`.
- Backoff: bounded exponential extension, capped at 2 hours.
- Event/report rate limit: one counted failure per candidate and observer per
  short sampling window; duplicates are idempotent.

These values are configuration, not client constants, and must be surfaced in
the admin diagnostics page.

## 9. Candidate Resolution

`UltraMaterialService` must consult dynamic reputation after the static
inventory/material predicates and before creating a candidate response.

The resolver must:

1. Exclude `quarantined` candidates whose `quarantine_until` is still in the
   future.
2. Convert expired quarantine rows to `probation` atomically before selection.
3. Apply `max_candidates=4` after dynamic filtering, not before it.
4. Preserve deterministic ordering for remaining candidates.
5. Recompute policy/material generation when the effective candidate set
   changes.
6. Refuse a sticky client selection whose stable candidate is now quarantined;
   issue a fresh candidate set on the next renewal.

If all candidates are unhealthy, retain an availability floor: do not exclude
the last one or two candidates solely because of dynamic reputation. Mark the
lease/runtime as degraded and select the least-bad eligible candidate. Static
disable and source absence remain hard exclusions.

## 10. Client Runtime Isolation

The Go Ultra runtime and the desktop/Linux wrappers should maintain local state
keyed by the stable candidate metadata carried in the authenticated lease, not
by a raw credential or a transient process identifier.

When the selected candidate crosses the local failure threshold:

1. Stop assigning new TCP streams to that candidate.
2. Re-arm or reconnect UDP through the next ranked candidate when possible.
3. Keep existing healthy connections alive; do not migrate them forcibly.
4. Emit one bounded status/report event after de-duplication.
5. Request a lease refresh when no healthy local candidate remains.

Local isolation is allowed immediately. It does not wait for the server to
decide whether the line deserves global quarantine.

## 11. Generation and Sticky Selection

The current policy generation already includes material fingerprints. The new
implementation must also include a deterministic reputation snapshot or
candidate-set fingerprint. A quarantine, recovery, static membership change,
or material rotation must invalidate stale sticky selection.

An unexpired existing connection remains valid unless its own transport fails
or its lease expires. New connections use the latest filtered set.

## 12. Admin and Operations Surface

Add a credential-free Ultra view containing:

- stable candidate label (`source_id` and line ID may be admin-only);
- route/protocol scope;
- current state and `quarantine_until`;
- last reason and last good time;
- failure/success counters and independent observer count;
- active memberships and current candidate availability;
- manual `restore` and `force quarantine` actions with audit records.

Client-facing payloads must not expose the reputation event stream, observer
identity, credentials, or relay ports.

## 13. Rollout Plan

### Phase 1: Local isolation

Implement Ultra client-local isolation and bounded status reporting. Do not
change server candidate issuance.

### Phase 2: Server shadow mode

Persist authenticated failure reports and state transitions, but only display
the would-be filtered candidates. Measure false positives and recovery time.

### Phase 3: Enforced server quarantine

Filter quarantined candidates in `UltraMaterialService`, preserve the
availability floor, and rotate policy generation when the candidate set changes.

### Phase 4: Operations and tuning

Add admin overrides, dashboards, route-specific thresholds, and alerting for
candidate pools that repeatedly enter degraded mode.

Each phase must be reversible by disabling the dynamic filter flag. Static
operator disable remains independent.

## 14. Test Plan

### Unit and contract tests

- Stable key is deterministic and never contains credentials or ports.
- One transient failure does not quarantine a candidate.
- Repeated failures enter probation and then quarantine at the configured
  corroboration threshold.
- TTL expiry permits only probation/canary behavior.
- Successful canaries recover a candidate; failed canaries increase bounded
  backoff.
- Candidate filtering happens before the `max_candidates` limit.
- Sticky selection is invalidated after a reputation snapshot change.
- The availability floor prevents total dynamic exclusion.
- Target refusal and `process_stderr` do not create a global quarantine event.
- Static disable still hard-excludes a candidate in every state.

### Integration tests

- Authenticated reports are idempotent and rate-limited.
- Two independent clients can corroborate the same scoped candidate failure.
- A CAN -> HKG quarantine does not remove an unrelated SHA -> TYO candidate.
- Material refresh and reputation changes rotate policy generation correctly.
- Existing unexpired connections survive inventory/reputation refresh.

### Live acceptance

- A deliberately isolated test candidate stops appearing in new Ultra leases.
- A healthy sibling remains selectable without restarting the listener.
- A quarantined candidate returns only through the bounded probation path.
- Admin evidence shows state, reason, TTL, and recovery without secrets.

## 15. Automatic New-Line Replenishment Boundary

This design does not make the system automatically purchase or authorize new
provider lines. The current implementation has three narrower behaviors:

1. `source-agent` can discover local line files/catalog records and upload a
   signed inventory on startup, fingerprint change, or its refresh interval.
2. The Panel can create/update `ultra_lines` rows from that inventory and mark
   missing rows source-absent.
3. The source agent can acquire/renew material only for line IDs returned by
   `/api/ultra/source/jobs`, which currently derives from active Ultra
   memberships that are already required by live forwards.

The authoritative Ultra combination membership remains an administrator-owned
`forward_combination_ultra_lines` list. A newly discovered line is therefore
not automatically added to every combination. A separate replenishment design
must define eligibility, approval, capacity, and rollback before enabling that
behavior.

There is also an implementation/documentation mismatch to resolve: the current
`normalizeInventoryLine()` code derives `is_enabled=1` for a line classified as
`native_udp` and `verified`, while the earlier Ultra design documents describe
new inventory rows as disabled by default. Dynamic quarantine must not hide this
ambiguity; the replenishment work must first choose and test one authoritative
default.

## 16. Rollback

1. Disable the dynamic server filter flag.
2. Keep client local isolation disabled or retain it as a local availability
   safeguard, depending on the incident.
3. Preserve reputation rows and events for diagnosis; do not delete evidence.
4. Restore static line/membership state only through the existing
   administrator workflow or a root-only backup.
5. Verify candidate count, policy generation, lease issuance, and existing
   connection continuity after rollback.
