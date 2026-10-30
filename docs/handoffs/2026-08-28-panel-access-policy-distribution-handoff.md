# Panel Access Policy v1: Cross-distribution handoff

Date: 2026-08-28

## Purpose and boundary

This document is the implementation contract for the Panel access-policy layer.
It is intentionally distribution-neutral and is the handoff for Android,
Windows, macOS, and future iOS clients. The current change implements the
Panel, persistence, and server decision engine only. Native client collection,
UI, binary changes, and release proof are **pending** for every distribution.

The Panel cannot bypass a remote client proxy. When the effective public
location is foreign, the future client must perform a physical Wi-Fi/cellular
check with `collection_mode=physical_no_proxy` and submit that result to the
Panel. The Panel only validates the two observations and makes the decision.

## Protocol v1

Wire contract marker: `protocol_version: 1`.

All endpoints below are under the Panel origin and use HTTPS. Requests are
authenticated with the existing client agent token (or the existing desktop
session/enrollment context). Every request carries a stable `device_uuid`, a
client-supplied `attempt_id`, and a `platform` value from `android`, `windows`,
`macos`, or `ios`.

### Challenge

`POST /api/client/access/location/challenge`

```json
{
  "protocol_version": 1,
  "device_uuid": "stable-device-uuid",
  "attempt_id": "login-attempt-uuid",
  "platform": "windows"
}
```

The response contains an opaque `challenge_token`, an expiry five minutes in
the future, the same `attempt_id`, and the recommended schedule:

```json
{
  "ok": true,
  "protocol_version": 1,
  "challenge_token": "opaque-token",
  "challenge_expires_at": "2026-08-28T12:05:00Z",
  "attempt_id": "login-attempt-uuid",
  "poll_interval_seconds": 1800,
  "poll_jitter_seconds": 300
}
```

The token is single-use, stored only as a hash, and bound to account, client,
device, attempt, and platform. Do not log or persist the raw token.

### Report

`POST /api/client/access/location/report`

```json
{
  "protocol_version": 1,
  "challenge_token": "opaque-token",
  "attempt_id": "login-attempt-uuid",
  "device_uuid": "stable-device-uuid",
  "platform": "windows",
  "observations": [
    {
      "collection_mode": "effective_path",
      "provider": "bilibili_zone",
      "public_ip": "198.51.100.10",
      "country": "United States",
      "country_code": "1",
      "observed_at": "2026-08-28T12:01:00Z"
    }
  ]
}
```

`effective_path` is the public location observed by the normal client route.
`physical_no_proxy` is a direct local Wi-Fi/cellular public-IP lookup. The
Panel accepts at most one observation of each mode. A Bilibili zone response
may be normalized from its `data` object (`addr`, `country`, `province`,
`city`, `country_code`, and timestamps); raw provider payloads are never
stored.

If `effective_path` is outside Mainland China, the first response is
`direct_location_check_required` (HTTP 428) unless a valid
`physical_no_proxy` observation is included in the same attempt. A second
report may reuse the still-unconsumed challenge. Provider/network failure is
`location_check_unavailable` and does not count as a violation.

### Status and enforcement

`GET /api/client/access/policy`

Returns only safe state: `protocol_version`, `geo_lock_enabled`, whether a
location check is due, `next_location_check_at`, current/main province codes,
and temporary-block retry timing. It never returns blacklist rule IDs,
administrator notes, raw provider data, or another account's information.

The Panel invokes the same decision hook at login/enrollment, config pull,
heartbeat, and other client request boundaries. Existing Android endpoints are
adapters to this contract; future distributions should use the same hook.

## Location provider and timing

The reference provider is:

```text
curl 'https://api.bilibili.com/x/web-interface/zone'
```

The client should not poll on every request. After login, schedule a check every
30 minutes plus deterministic 0-5 minute jitter. A source/interface change
triggers an immediate check. A successful observation is cached for 24 hours
for the same account/device/source, while the server still enforces the next
30-minute due boundary. Observations older than 15 minutes at receipt are
stale. Clock values are UTC ISO-8601 on the wire.

## Global deny rules

Administrators may pre-create active rules for any of these entities:

- device UUID;
- one exact IPv4/IPv6 address;
- an IPv4/IPv6 CIDR subnet;
- Mainland province;
- Mainland prefecture city (province-qualified);
- country/region outside Mainland China.

Rules apply globally and are checked before geographic-lock evaluation. A match
returns `access_blacklisted` (HTTP 403), is recorded for audit, and does not
count as a geographic violation. Rules are canonicalized by the Panel so IPv6
and CIDR text have one matching form.

## Geographic lock

`service_plans.geo_lock_enabled` is the plan switch. The effective account
switch is enabled when any active service uses an active geo-locked plan.

On the first valid Mainland observation the account binds a `main_province`.
Tenant self-service changes are allowed once per rolling 24 hours and require a
valid observation from the preceding 10 minutes. Admin changes can bypass the
cooldown but still use a recognized province code.

Allowed province sets are:

- Shanghai/Jiangsu/Zhejiang: the exact three-province circle;
- Beijing/Tianjin/Hebei: the exact three-province circle;
- Xinjiang, Tibet, Inner Mongolia, and Shandong: that province only;
- all other provinces: the province itself plus direct land-border neighbors;
- Hainan: itself only because it has no land border.

A non-adjacent or otherwise disallowed province is an immediate
`geo_lock_violation` (HTTP 403). It is not a warning and does not permit a
retry through the same route.

## Blocks and account BAN

Each confirmed geographic violation creates a 30-minute temporary block for
the offending device UUID (`block_origin=policy`, `release_policy=admin_only`).
The same `attempt_id` is idempotent; retries and polling do not create extra
violations. Three independent geographic violations in a rolling 24 hours
create an account BAN (`account_banned`, HTTP 403), set the account status to
`banned`, and revoke application sessions. Blacklist matches, stale reports,
and provider failures do not count.

The v1 error mapping is:

| Error | HTTP | Client action |
| --- | ---: | --- |
| `access_blacklisted` | 403 | stop and show a generic access-denied state |
| `direct_location_check_required` | 428 | collect `physical_no_proxy`, then retry the same attempt |
| `location_check_unavailable` | 503 | back off; do not count a violation |
| `geo_lock_violation` | 403 | stop the login/request and await the block expiry or admin action |
| `device_temporarily_banned` | 423 | honor `retry_after_seconds`; do not tight-loop |
| `device_banned` | 403 | stop; only an administrator can release a permanent BAN |
| `account_banned` | 403 | stop all application retries; Panel admin review is required |

## Tenant and admin controls

The tenant `/tenant/security` page exposes the current province, verification
timestamps, due time, owned device UUIDs, daily self-change form, Kick-all, and
eligible restore actions. Tenant Kick-all snapshots known UUIDs, revokes
application sessions while preserving the web Panel session, and enters a
reset generation. The next valid Mainland bind completes the reset; if the
province changed, every snapshotted UUID receives a one-hour
`province_reset`/`admin_only` block.

An individual tenant kick is a 30-minute `manual_tenant` temporary block and
can be restored immediately by its owner. `manual_admin`, `policy`, and
`province_reset` blocks are administrator-only. Permanent bans and account BANs
are administrator-only. All POST Panel actions require CSRF and ownership
checks; flash messages are generic and do not expose exception details.

The admin `/admin/access-control` page manages rules, geo profiles, violation
events, device blocks, and account BAN release. The Plans page contains the
`开启地域锁`/geo-lock switch. Admin views are bounded and escape UUID/IP/city
values; raw provider data and challenge secrets are excluded.

## Privacy and evidence

Persist only canonical IP/location fields, timestamps, hashes, decision codes,
attempt IDs, and short digests. Do not persist raw Bilibili responses, bearer
tokens, passwords, or unrestricted client payloads. For an incident, capture
the Panel request ID, account/client/device identifiers, attempt ID, decision
code, UTC timestamps, and the relevant policy-event IDs. Redact IPs and UUIDs
before sharing outside the operator group.

## Distribution checklists

### Android

- [ ] Implement protocol v1 challenge/report/status calls in the existing login
  and enrollment flow.
- [ ] Add a physical Wi-Fi/cellular lookup path that bypasses the app proxy
  only for the `physical_no_proxy` measurement.
- [ ] Honor HTTP 428/423/403 and `retry_after_seconds` without reconnect loops.
- [ ] Schedule 30-minute plus 0-5-minute jitter checks and source-change checks.
- [ ] Test UUID persistence across reinstall/update and verify no token logging.
- [ ] Native implementation and binary proof remain pending.

### Windows

- [ ] Add the same calls before desktop login, pull, and heartbeat.
- [ ] Collect `physical_no_proxy` through the OS network path, never by asking
  the Panel to bypass a remote proxy.
- [ ] Persist the stable UUID in the platform credential store and map errors
  to the desktop UI.
- [ ] Native implementation and binary proof remain pending.

### macOS

- [ ] Add the same calls before login, pull, and heartbeat.
- [ ] Use a direct system interface for the physical observation and document
  user-consent/permission behavior.
- [ ] Test sleep/wake, interface changes, and clock skew against the due time.
- [ ] Native implementation and binary proof remain pending.

### iOS

- [ ] Implement the v1 adapter when the iOS client exists; do not invent a
  client-specific policy.
- [ ] Use the approved network path for `effective_path` and a permitted direct
  cellular/Wi-Fi path for `physical_no_proxy`.
- [ ] Cover background scheduling limits, reinstall UUID policy, and consent.
- [ ] Native implementation and binary proof remain pending.

## Rollout and rollback

1. Apply the additive Panel migration and verify schema hashes in staging.
2. Keep `geo_lock_enabled=0` by default while client adapters are staged.
3. Enable the switch for a pilot plan, inspect policy events, and confirm
   provider failures are not counted.
4. Expand by plan only after all target distributions understand v1 errors.
5. To roll back, disable the plan switch and stop client polling; retain audit
   rows and temporary blocks until their recorded expiry. Do not delete policy
   evidence during an incident.

No production deployment, native client release, or binary modification is
included in this handoff.
