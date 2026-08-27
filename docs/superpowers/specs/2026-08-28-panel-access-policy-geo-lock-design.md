# Panel Access Policy and Geographic Lock Design

Date: 2026-08-28
Status: Approved for implementation planning
Scope: Panel control plane, admin and tenant web UI, shared API contract, and distribution handoff

## 1. Decision

Build one Panel-owned access-policy subsystem for all current and future client
distributions. This delivery changes Panel code and documentation only. It does
not modify or rebuild Android, Windows, macOS, or iOS client code or artifacts.

The subsystem provides:

1. Global deny rules for device UUID, exact IP, IP subnet, Mainland China
   province, Mainland China prefecture-level city, and non-Mainland country or
   region.
2. A service-plan geographic-lock switch whose effective scope is the tenant
   account.
3. A fixed per-account main province, province adjacency rules, immediate access
   denial, and an account ban after three independent geographic violations in
   a rolling 24-hour window.
4. Distribution-neutral challenge, location-report, decision, retry, and device
   control contracts.
5. Tenant self-service for a daily main-province change, single-device temporary
   kick and restore, and an all-device reset flow.
6. Admin management for deny rules, profiles, violations, temporary/permanent
   device blocks, and account bans.
7. Complete `zh-Hans`, `zh-TW`, and `en` UI coverage through the existing i18n
   layer.

Panel is authoritative for every policy decision. A client distribution is a
location collector and policy consumer; it does not decide whether access is
allowed.

## 2. Current-System Constraints

- `services` owns `plan_id`; `clients` owns `owner_user_id`. There is no reliable
  service-to-client assignment. Therefore geographic-lock entitlement is
  aggregated at the account boundary.
- Device control already records a device ID and supports active, kicked, and
  permanently banned states. A kick currently does not prevent immediate
  reenrollment, so reconnecting clients make it ineffective.
- `ClientOnlineSessionService` already records the trusted-proxy-resolved source
  IP and device metadata.
- Android currently has the strongest enrollment and device-session contract,
  but the new access-policy API must not contain Android-specific authorization,
  field names, or decisions.
- `https://api.bilibili.com/x/web-interface/zone` reports the public exit of the
  machine making that request. A Panel server cannot use it to discover another
  machine's direct physical-network exit or bypass that machine's proxy/VPN.

The last point is a hard technical boundary. This Panel-only delivery defines,
validates, stores, and evaluates reports. Each distribution must later implement
the actual effective-path and physical-network probes before location enforcement
can be complete for that distribution.

## 3. Goals and Non-goals

### Goals

- Keep one policy implementation and one stable protocol for every distribution.
- Allow an admin to create active deny rules before the entity first appears.
- Enforce exact IP and CIDR rules using Panel-observed source addresses.
- Support IPv4 and IPv6 without string-prefix matching.
- Normalize geographic values so provider spelling differences do not create
  accidental allow paths.
- Make all blocks and state changes auditable and idempotent.
- Preserve compatibility while client distributions adopt protocol version 1.
- Avoid turning provider outages or transport retries into violations.

### Non-goals

- No Android, Windows, macOS, or iOS implementation in this delivery.
- No client binary, installer, manifest, or release publication.
- No production deployment without a separate explicit request.
- No latitude, longitude, precise device location, or location-history product.
- No client-side authority to alter rules, violation counts, or account bans.
- No per-service or per-client geographic-lock entitlement until the repository
  has an explicit service-to-client ownership model.

## 4. Effective Plan Policy

Add `service_plans.geo_lock_enabled TINYINT(1) NOT NULL DEFAULT 0` and expose it as
the translated "Enable geographic lock" control on the admin plan form.

For a tenant user, geographic lock is enabled when at least one active service is
attached to an active plan whose switch is enabled. When enabled, it applies to
all application clients owned by that user. An unlocked active plan does not
exempt part of the same account because the current data model cannot prove which
client belongs to which service.

The switch defaults off in schema, migration, admin creation, and legacy rows.

## 5. Global Deny Rules

All deny rules are global and independent of service-plan geographic lock.

Supported rule types:

| Type | Canonical value | Match input |
| --- | --- | --- |
| `device_uuid` | normalized opaque UUID/device key | presented device UUID |
| `ip` | canonical IPv4 or IPv6 address | Panel source and reported exits |
| `cidr` | canonical network plus prefix | Panel source and reported exits |
| `cn_province` | Mainland province code | effective Mainland observation |
| `cn_city` | province code plus prefecture code/name | effective Mainland observation |
| `country` | ISO-like country/region code | every reported non-Mainland exit |

Mainland city rules always include their parent province in the canonical key.
Hong Kong, Macao, and Taiwan are outside the 31-region Mainland set and use stable
non-Mainland location codes in the country/region matching path.

Rules have an active flag, reason code, admin note, creator/updater identity, and
timestamps. Deactivation preserves history. Re-adding the same canonical key
reactivates or updates the existing row instead of creating ambiguous duplicates.

An exact IP or CIDR match is evaluated against the trusted Panel source IP and
every valid reported public IP. A geographic rule is evaluated only when a valid
location observation exists. Any deny-rule hit blocks immediately and is audited,
but does not increment the geographic-lock violation counter.

## 6. Location Protocol

Protocol version 1 is distribution-neutral. Existing login, enrollment, client
selection, heartbeat, and configuration-pull controllers call a shared
`AccessPolicyService`; they do not implement policy inline.

When a fresh location decision is required, the authenticating endpoint returns:

```json
{
  "ok": false,
  "error": "location_check_required",
  "protocol_version": 1,
  "challenge_token": "opaque-single-use-token",
  "challenge_expires_at": "2026-08-28T10:05:00Z",
  "attempt_id": "client-generated-id"
}
```

The client later submits a shared report shape:

```json
{
  "protocol_version": 1,
  "challenge_token": "opaque-single-use-token",
  "attempt_id": "same-client-generated-id",
  "device_uuid": "distribution-stable-device-id",
  "platform": "windows",
  "observations": [
    {
      "collection_mode": "effective_path",
      "provider": "bilibili_zone",
      "public_ip": "203.0.113.10",
      "country": "example",
      "country_code": "XX",
      "province": "",
      "city": "",
      "observed_at": "2026-08-28T10:01:00Z"
    }
  ]
}
```

Allowed platform values include `android`, `windows`, `macos`, and `ios`, but
platform does not alter policy semantics. A challenge is bound to the verified
auth context, user, client when known, device UUID, attempt ID, and expiry. The
database stores only a token hash. A challenge expires after five minutes and is
consumed once.

If the effective-path observation is outside Mainland China, the decision is
`direct_location_check_required`. The same challenge must then include a
`physical_no_proxy` observation made over a physical Wi-Fi or cellular network
without the configured proxy or application VPN. If that result is also outside
Mainland China, the geographic lock records a violation.

The Panel validates schema, timestamps, challenge binding, IP syntax, geographic
normalization, duplicate modes, and report size. It stores canonical fields and a
payload digest, not latitude, longitude, or unrestricted raw provider payloads.

Challenge binding prevents replay; it does not make an unsigned third-party API
response cryptographically trustworthy. The handoff therefore requires each
distribution to protect the collector and documents platform attestation as a
future hardening layer. The Panel must not claim a stronger trust guarantee than
the client integration provides.

## 7. Check Frequency and Cache

- Check immediately during login or enrollment.
- After allow, return `next_location_check_at` for 30 minutes plus a uniformly
  selected 0-5 minute jitter.
- A network-interface or source-IP change invalidates the schedule and cache.
- Ordinary heartbeat calls do not themselves invoke the external location API;
  they enforce whether a report is due.
- A provider timeout, no network, or malformed provider response does not count
  as a violation.
- A verified observation may be reused for up to 24 hours only for the same user,
  device UUID, and unchanged trusted source IP.
- First login with no usable cache fails closed while the provider is unavailable.

Global geographic deny rules require valid location data. Activating such a rule
before a distribution implements protocol version 1 may cause that distribution
to receive `location_check_required`. Admin UI and rollout documentation must make
this operational dependency explicit. Device/IP/CIDR rules do not have that
dependency.

## 8. Main Province and Allowed Regions

Each user has one fixed `main_province`. The first valid Mainland observation
binds it. Normal logins never move it automatically.

The checked-in policy data covers the 31 Mainland provincial-level regions. A
versioned adjacency artifact is derived from a documented China provincial
boundary map; its source, version, and SHA-256 are recorded with the artifact.
Runtime requests never download a map.

Allowed sets are:

- Shanghai, Jiangsu, or Zhejiang: exactly Shanghai + Jiangsu + Zhejiang.
- Beijing, Tianjin, or Hebei: exactly Beijing + Tianjin + Hebei.
- Xinjiang, Tibet, Inner Mongolia, or Shandong: exactly the main province.
- Every other Mainland region: the main province plus regions sharing a direct
  land boundary in the checked-in map.

The two three-region circles do not inherit other map neighbors. Hainan has no
land neighbor and therefore allows only itself under the ordinary map rule.

The adjacency artifact must be symmetric, contain no unknown/self-only accidental
edges, and cover every non-special region. Special sets are tested separately.

## 9. Tenant Main-Province Operations

### Daily self-change

The tenant may change `main_province` once per rolling 24 hours. The requested
province must equal a valid observation for that user from the preceding ten
minutes. The change takes effect immediately, is audited, and does not clear
geographic violations. Admin changes and resets are not subject to this cooldown.

### Kick out all devices and reset

The tenant can start an all-device reset from the web Panel without losing the
current web session:

1. Snapshot every known application device UUID for the user under a new reset
   generation.
2. Revoke Android, Windows, macOS, iOS, device-control, and desktop-client
   application sessions. Do not revoke the tenant's current web Panel session.
3. Preserve the old province and mark the profile `reset_pending`.
4. Bind the next valid Mainland observation as the new main province.
5. If the new province differs, temporarily ban every UUID in the snapshot for
   one hour. A genuinely new UUID is not in the snapshot and is not blocked.
6. If the province is unchanged, do not add the one-hour block.
7. Never clear violation events or the rolling count as part of reset.

The transition is transactional and idempotent by reset generation.

## 10. Geographic Violations and Account Ban

A geographic violation means only a geo-locked account whose effective location:

- is outside the allowed province set; or
- remains outside Mainland after the required physical-no-proxy check.

It does not mean invalid credentials, provider failure, malformed input, a retry,
or a global deny-rule match.

Every confirmed violation immediately denies the current login or request and
places a 30-minute `policy` temporary ban on the offending user/device UUID. The
tenant cannot release a policy ban; admin can release it early.

The client supplies one `attempt_id` for a user-initiated attempt and reuses it
for transport retries. Ongoing checks generate one ID per location event. A
database uniqueness constraint ensures an attempt is counted at most once.
Repeated calls while the same temporary ban is active are denied but are not new
violations.

At the third independent violation in a rolling 24-hour window, one transaction:

1. Locks the user/profile violation boundary.
2. Inserts the third deduplicated event.
3. Creates an account-ban record with evidence IDs.
4. Sets `users.status = 'banned'`.
5. Revokes all application sessions for the account.

An account ban has no automatic expiry and requires admin release. Release is
audited and does not delete historical evidence.

## 11. Device Control Semantics

Replace the useful effect of tenant `kick` with a temporary UUID ban while
retaining permanent ban as a separate operation.

Temporary-ban presets are 15 minutes, 30 minutes (default), one hour, 24 hours,
and seven days.

Origins and release policy:

| Origin | Early release |
| --- | --- |
| `manual_tenant` | owning tenant or admin |
| `manual_admin` | admin only |
| `policy` | admin only |
| `province_reset` | admin only |

A temporary ban immediately revokes matching device sessions and rejects
challenge, enrollment, heartbeat, pull, and protected client requests until
`blocked_until`. Expiry lazily restores active eligibility and creates an audit
event. Early release increments the command version and allows immediate fresh
enrollment; revoked sessions are never resurrected.

Legacy clients continue to receive `device_kicked` with added `blocked_until` and
`retry_after_seconds`. Protocol-v1 consumers receive
`device_temporarily_banned`. Permanent blocks remain `device_banned`.

## 12. Decision Order

For every protected authentication or client request:

1. Validate credentials/token/enrollment context without issuing a usable
   session, and resolve user, client, device UUID, and trusted source IP.
2. Reject an account whose status is banned.
3. Match global device UUID, exact IP, and CIDR deny rules.
4. Enforce permanent and temporary device controls.
5. Determine whether location is due because of login, schedule, source change,
   geographic deny policy, or effective plan geographic lock.
6. Validate or request challenge-bound effective-path and, when required,
   physical-no-proxy observations.
7. Match global province, city, and country/region deny rules against every valid
   relevant observation.
8. Bind or reset the main province when the profile state permits it.
9. Calculate the approved province set and evaluate geographic lock.
10. On violation, deny, deduplicate, temporarily ban the device, and atomically
    account-ban on the third rolling event.
11. On allow, issue the access grant/session result and next check time.

No later allow can override an earlier deny in this sequence.

## 13. Response Contract

Stable protocol-v1 errors:

| HTTP | Code | Meaning |
| --- | --- | --- |
| 428 | `location_check_required` | effective-path report required |
| 428 | `direct_location_check_required` | physical-no-proxy report required |
| 422 | `location_report_invalid` | report failed validation |
| 503 | `location_check_unavailable` | retryable provider/network condition |
| 403 | `access_blacklisted` | a global deny rule matched |
| 403 | `geo_lock_violation` | effective location is outside policy |
| 423 | `device_temporarily_banned` | UUID is blocked until a timestamp |
| 403 | `device_banned` | UUID is permanently blocked |
| 403 | `account_banned` | account requires admin release |

Responses may include `decision_id`, `challenge_token`,
`challenge_expires_at`, `retry_after_seconds`, `blocked_until`,
`remaining_geo_violations`, and `next_location_check_at` as applicable. Public
responses never expose the matching rule ID, admin note, other device IDs, raw
provider response, or detailed ban evidence.

## 14. Data Model

### `access_blacklist_rules`

- `entity_type`, `canonical_value`, `display_value`
- canonical binary IP/network bounds and prefix metadata where applicable
- province/city/country codes where applicable
- `is_active`, `reason_code`, `admin_note`
- creator/updater user IDs and timestamps
- unique canonical entity key and active lookup indexes

### `user_geo_lock_profiles`

- `user_id` unique foreign key
- state: `unbound`, `bound`, or `reset_pending`
- main/previous province code
- binding observation and timestamps
- `self_change_available_at`
- reset generation and reset timestamps

### `access_location_challenges`

- token hash, auth-context digest, user/client/device/attempt binding
- expiry, consumption time, and timestamps
- indexes for expiry cleanup and binding lookup

### `access_location_observations`

- user/client/device/attempt/challenge references
- Panel source IP, reported public IP, normalized geography
- collection mode, provider, observed/received times, payload digest
- no latitude, longitude, or raw unrestricted payload

### `access_policy_events` and `account_access_bans`

- decision ID, decision/result/reason, matched entity type
- user/client/device/IP and observation references
- deduplication key and whether it counts as a geographic violation
- ban evidence, actor, release actor/time, and audit timestamps

### Device-control extensions and reset snapshots

- Add `blocked_until`, `block_origin`, and `release_policy` to
  `client_device_controls`.
- Store all-device reset generations and their UUID snapshots in dedicated
  tables so province rebinding and one-hour bans are idempotent.

All migrations are additive and default-off. `migrations/schema.sql` and a dated
incremental migration must stay equivalent.

## 15. Admin and Tenant UI

Add `/admin/access-control` with tabs for Blacklist, Geo Profiles, Violations,
Device Blocks, and Account Bans. Provide bounded filters, normalized validation,
enable/disable actions, evidence drill-down, and permission-correct release
actions. Preserve audit history instead of hard deleting used rules.

Add the geographic-lock checkbox to the existing plan edit form.

Add a tenant security surface containing:

- current main province and last verification time;
- next self-change eligibility and the one-per-24-hour action;
- all-device kick/reset with explicit confirmation;
- per-device temporary kick and immediate restore for tenant-origin blocks;
- read-only admin/policy block state.

The surfaces use the existing compact operational design and responsive table
patterns. No nested cards or marketing layout is introduced.

## 16. Internationalization

Every new user-visible string must exist in all three existing catalogs:

- `master/public/i18n/zh-Hans.json`
- `master/public/i18n/zh-TW.json`
- `master/public/i18n/en.json`

Coverage includes navigation, tabs, field labels, entity and state names,
validation errors, confirmations, success/failure flash messages, relative and
absolute time labels, ban origins, release permissions, and the plan switch.

Controllers render stable source strings/keys compatible with the existing
`patch.js` mechanism. Tests must fail when any new key is missing from one catalog,
when a value is blank, or when Simplified/Traditional entries accidentally equal
an untranslated English source value where translation is required. API error
codes remain language-neutral; clients map them through their own future i18n.

## 17. Distribution Handoff

Create a credential-free handoff under `docs/handoffs/` containing:

- protocol versioning and full request/response fixtures;
- login/enrollment and periodic-check state diagrams;
- attempt ID, challenge, cache, jitter, and retry requirements;
- stable device UUID requirements and reset behavior;
- effective-path versus physical-no-proxy semantics;
- Android, Windows, macOS, and iOS implementation checklists;
- per-platform proof that the no-proxy probe used a physical interface and a
  different public exit when a controlled proxy/VPN fixture is active;
- compatibility and staged-enforcement instructions;
- acceptance tests and evidence format.

The handoff must label all client integrations as pending. Panel tests using a
platform string do not prove a native client implementation or binary behavior.

## 18. Verification

Focused tests cover:

- migration/schema parity and additive defaults;
- IPv4/IPv6 exact and CIDR normalization/matching;
- rule uniqueness, enable/disable, validation, and audit behavior;
- province, parent-province city, and non-Mainland country/region matching;
- all 31 Mainland regions, symmetric adjacency, both exact three-region circles,
  the four self-only regions, and Hainan;
- account-level plan aggregation across mixed active plans;
- challenge expiry, one-time use, auth/device/attempt binding, and replay denial;
- effective-path foreign -> physical-no-proxy transition;
- provider failure and unchanged-source 24-hour cache;
- attempt deduplication and concurrent third-violation account ban;
- all-device reset generations, province-change comparison, and old-UUID bans;
- rolling daily self-change and ten-minute observation requirement;
- tenant/admin/policy release permissions and lazy temporary-ban expiry;
- legacy `device_kicked` compatibility;
- identical controller contracts for Android, Windows, macOS, and iOS fixtures;
- three-catalog i18n completeness;
- CSRF, authorization, escaping, pagination/filter bounds, and public error
  redaction.

Run the repository's focused PHP tests, broader Panel regression suite, syntax
checks, and migration checks. Start the local Panel and use the repository
`/browse` workflow to verify admin and tenant pages at desktop and mobile widths,
including confirmation dialogs, long translated labels, empty/loading/error
states, and absence of overlap or horizontal page overflow.

## 19. Delivery and Rollout Boundary

Implementation occurs from the current `master` baseline while preserving all
unrelated dirty work. No client directory is modified. A final diff audit must
prove this boundary.

Local completion means source, migration, schema, fixtures, tests, browser QA,
design document, implementation plan, and distribution handoff pass. It does not
mean any client collects location, any production rule is active, or production
has been deployed.

Production rollout, when separately requested, must be staged:

1. Deploy additive/default-off Panel changes.
2. Integrate and prove each distribution against protocol fixtures.
3. Observe reports without active geographic enforcement.
4. Enable geographic rules/plan switches only for proven client cohorts.
5. Verify deny, recovery, audit, and rollback behavior before wider enablement.

This design intentionally separates a complete Panel mechanism from the later
native-distribution work needed for end-to-end enforcement.
