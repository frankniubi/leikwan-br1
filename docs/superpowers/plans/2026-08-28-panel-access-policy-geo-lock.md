# Panel Access Policy and Geographic Lock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement the approved Panel-only access-policy, blacklist, geographic-lock, temporary device-ban, tenant reset, admin/tenant UI, three-language i18n, and distribution handoff without modifying any client source or binary.

**Architecture:** Keep all decisions in a new `AccessPolicyService` backed by additive MySQL tables and small pure helpers for IP/CIDR normalization and Mainland region policy. Existing client-agent authentication and device-control paths call this service; protocol-v1 location challenge/report endpoints remain distribution-neutral and accept `android`, `windows`, `macos`, and `ios` as data values only. Admin and tenant controllers expose the approved management flows while preserving current session/CSRF patterns.

**Tech Stack:** PHP 8+, existing `Leikwan\Master\Database\Database`, `Request`/`Response`, `SessionAuth`, MySQL/SQLite migration runner, server-rendered HTML, existing `master/public/i18n/{zh-Hans,zh-TW,en}.json`, shell PHP contract tests, and `/browse` browser QA.

**Spec:** `docs/superpowers/specs/2026-08-28-panel-access-policy-geo-lock-design.md`

## Global Constraints

- This delivery changes `master/` Panel code, migrations, tests, i18n catalogs, and handoff documentation only; do not modify `leikwan-android/`, `LeikwanBridge.Desktop/`, `wstunnel-main/`, client-agent sources, installers, manifests, or binaries.
- `master` is the implementation baseline. Preserve unrelated dirty changes and never stage them.
- `service_plans.geo_lock_enabled` defaults to `0`; account geographic lock is enabled when any active service for the tenant uses an active plan with that switch enabled.
- Global device UUID, exact IP, CIDR, Mainland province/city, and non-Mainland country/region deny rules apply independently of plan geographic lock.
- A confirmed geographic violation blocks immediately, counts at most once per `attempt_id`, and the third independent violation in a rolling 24-hour window permanently bans the account until admin release.
- Tenant self-change of `main_province` is limited to once per rolling 24 hours and requires a valid observation from the preceding 10 minutes.
- Tenant Kick-all snapshots UUIDs, revokes application sessions, enters `reset_pending`, and bans snapshot UUIDs for one hour only when the next verified Mainland province differs.
- Tenant-origin temporary device blocks can be released immediately by the owning tenant; admin/policy/province-reset blocks require admin release. Permanent bans require admin release.
- Location challenge TTL is five minutes; successful observations may be reused for 24 hours only for the same user, UUID, and unchanged trusted source IP. Normal checks are due after 30 minutes plus 0-5 minutes of jitter.
- Bilibili `/zone` can report only the machine that makes the request. Panel never claims to bypass a client proxy/VPN; the handoff specifies the later physical `physical_no_proxy` client probe.
- All new visible UI strings must be present and translated in `zh-Hans`, `zh-TW`, and `en`; API error codes remain language-neutral.
- No production deployment, client build, artifact publication, or credential output is part of this plan.

---

### Task 1: Add the additive access-policy schema

**Files:**
- Create: `master/migrations/20260828_add_panel_access_policy.sql`
- Modify: `master/migrations/schema.sql:1-80,274-299,492-590` (users, service_plans, clients/device tables)
- Test: `master/tests/access_policy_schema_test.php`

**Interfaces:**
- Produces tables and columns consumed by `AccessPolicyService`, `DeviceControlService`, the admin/tenant controllers, and migration tests.
- `schema.sql` must contain the same canonical definitions as the dated migration for fresh installs.

- [ ] **Step 1: Write the failing schema contract test**

Create `access_policy_schema_test.php` that reads both schema and migration and asserts the following exact identifiers and constraints are present:

```php
foreach ([$schema, $migration] as $sql) {
    foreach ([
        'geo_lock_enabled TINYINT(1) NOT NULL DEFAULT 0',
        'access_blacklist_rules',
        'user_geo_lock_profiles',
        'access_location_challenges',
        'access_location_observations',
        'access_policy_events',
        'account_access_bans',
        'access_reset_generations',
        'access_reset_devices',
        'blocked_until',
        'block_origin',
        'release_policy',
        'UNIQUE KEY uniq_access_blacklist_entity',
        'UNIQUE KEY uniq_access_location_challenge_token',
        'UNIQUE KEY uniq_access_policy_attempt',
        'rolling 24',
    ] as $needle) {
        if (!str_contains($sql, $needle)) {
            fail('missing access-policy schema contract: ' . $needle);
        }
    }
}
```

The test must also reject credential columns (`agent_token`, `password`, provider material) in the new access-policy tables and assert `users.status` remains available for the account BAN transition.

- [ ] **Step 2: Run the schema test and confirm it fails**

Run from `master`:

```powershell
php tests/access_policy_schema_test.php
```

Expected: FAIL because the new migration, `geo_lock_enabled`, and access-policy tables do not exist yet.

- [ ] **Step 3: Implement the dated migration**

Create `20260828_add_panel_access_policy.sql` with additive, rerunnable statements:

```sql
ALTER TABLE service_plans ADD COLUMN geo_lock_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_tenant_visible;
ALTER TABLE client_device_controls
    ADD COLUMN blocked_until DATETIME NULL AFTER state,
    ADD COLUMN block_origin VARCHAR(32) NULL AFTER blocked_until,
    ADD COLUMN release_policy VARCHAR(32) NULL AFTER block_origin;

CREATE TABLE IF NOT EXISTS access_blacklist_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(32) NOT NULL,
    canonical_value VARCHAR(255) NOT NULL,
    display_value VARCHAR(255) NOT NULL,
    ip_family TINYINT NULL,
    ip_start VARBINARY(16) NULL,
    ip_end VARBINARY(16) NULL,
    province_code VARCHAR(32) NULL,
    city_code VARCHAR(64) NULL,
    country_code VARCHAR(32) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    reason_code VARCHAR(64) NOT NULL,
    admin_note VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_access_blacklist_entity (entity_type, canonical_value),
    INDEX idx_access_blacklist_active_type (entity_type, is_active),
    INDEX idx_access_blacklist_ip (ip_family, ip_start, ip_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Add `user_geo_lock_profiles`, single-use `access_location_challenges`, canonical `access_location_observations`, `access_policy_events`, `account_access_bans`, and reset generation/device snapshot tables with foreign keys to users/clients where available. Store token hashes and payload digests only; include `attempt_id` uniqueness and evidence indexes. The migration runner already ignores duplicate additive-column/table errors, so each `ALTER TABLE` must be a single-column statement when compatibility requires it.

- [ ] **Step 4: Mirror canonical definitions into `schema.sql`**

Insert the new `geo_lock_enabled` column, device-control columns, and full table definitions in the corresponding fresh-schema sections. Keep names, defaults, indexes, and foreign-key actions byte-for-byte equivalent to the dated migration definitions.

- [ ] **Step 5: Run the schema test and migration syntax checks**

Run:

```powershell
php tests/access_policy_schema_test.php
php -l src/Support/MigrationRunner.php
```

Expected: both commands exit `0` and the contract reports `ok`.

- [ ] **Step 6: Commit the schema task**

```powershell
git add -- migrations/20260828_add_panel_access_policy.sql migrations/schema.sql tests/access_policy_schema_test.php
git commit -m "feat: add panel access policy schema"
```

---

### Task 2: Implement pure IP and Mainland-region policy helpers

**Files:**
- Create: `master/src/Support/IpAddressMatcher.php`
- Create: `master/src/Support/MainlandRegionPolicy.php`
- Create: `master/resources/access-policy/mainland-region-adjacency-v1.json`
- Test: `master/tests/access_policy_ip_test.php`
- Test: `master/tests/access_policy_region_test.php`

**Interfaces:**
- `IpAddressMatcher::canonicalize(string $value): ?array{canonical:string,family:int,packed:string}`
- `IpAddressMatcher::canonicalCidr(string $value): ?array{canonical:string,family:int,start:string,end:string,prefix:int}`
- `IpAddressMatcher::contains(string $network, string $candidate): bool`
- `MainlandRegionPolicy::normalizeProvince(string $value): ?string`
- `MainlandRegionPolicy::normalizeCity(string $province, string $city): ?string`
- `MainlandRegionPolicy::allowedProvinces(string $mainProvince): array<int,string>`
- `MainlandRegionPolicy::isMainlandProvince(string $province): bool`

- [ ] **Step 1: Write failing IP tests**

Cover canonical IPv4/IPv6, mapped IPv4 rejection/normalization policy, CIDR boundaries, invalid prefixes, and no string-prefix false positives:

```php
assertSame('203.0.113.10', IpAddressMatcher::canonicalize('203.0.113.10')['canonical'] ?? null);
assertSame('2001:db8::1', IpAddressMatcher::canonicalize('2001:0DB8:0:0:0:0:0:1')['canonical'] ?? null);
assertTrue(IpAddressMatcher::contains('203.0.113.0/24', '203.0.113.255'));
assertFalse(IpAddressMatcher::contains('203.0.113.0/24', '203.0.114.1'));
assertFalse(IpAddressMatcher::contains('203.0.113.0/24', '203.0.113.001'));
```

- [ ] **Step 2: Run IP tests and confirm failure**

```powershell
php tests/access_policy_ip_test.php
```

Expected: FAIL because `IpAddressMatcher` is undefined.

- [ ] **Step 3: Implement `IpAddressMatcher`**

Use `inet_pton`/`inet_ntop`, validate family-specific prefix lengths, compare packed bytes with a mask, and return canonical start/end binary values. Never use textual prefix matching. Reject malformed or mixed-family CIDRs with `null`/`false`.

- [ ] **Step 4: Write failing region tests**

Assert all 31 Mainland province codes are covered, adjacency is symmetric, Shanghai/Jiangsu/Zhejiang and Beijing/Tianjin/Hebei are exact three-member sets, Xinjiang/Tibet/Inner Mongolia/Shandong are self-only, and Hainan has no accidental neighbor. Assert parent-province city keys normalize deterministically.

- [ ] **Step 5: Run region tests and confirm failure**

```powershell
php tests/access_policy_region_test.php
```

Expected: FAIL because the static policy artifact and helper are missing.

- [ ] **Step 6: Add the versioned adjacency artifact and helper**

Create JSON containing `version`, `source_description`, `sha256`, all 31 region codes, and symmetric direct-neighbor arrays. `MainlandRegionPolicy` loads the checked-in file once, validates symmetry and region coverage, applies the two special circles and four self-only regions, and exposes only normalized codes. Do not download a map at request time.

- [ ] **Step 7: Run both pure-helper suites**

```powershell
php tests/access_policy_ip_test.php
php tests/access_policy_region_test.php
```

Expected: both report `ok`.

- [ ] **Step 8: Commit the helper task**

```powershell
git add -- src/Support/IpAddressMatcher.php src/Support/MainlandRegionPolicy.php resources/access-policy/mainland-region-adjacency-v1.json tests/access_policy_ip_test.php tests/access_policy_region_test.php
git commit -m "feat: add access policy network and region rules"
```

---

### Task 3: Build the Panel access-policy service and decision engine

**Files:**
- Create: `master/src/Support/AccessPolicyService.php`
- Create: `master/src/Support/AccessPolicyStore.php`
- Test: `master/tests/access_policy_service_test.php`
- Test: `master/tests/access_policy_concurrency_test.php`

**Interfaces:**
- `AccessPolicyService::__construct(Database $db, ?MainlandRegionPolicy $regions = null, ?DateTimeImmutable $clock = null)`
- `effectiveGeoLockForUser(int $userId): bool`
- `issueChallenge(array $client, string $deviceUuid, string $attemptId, string $platform): array`
- `recordLocationReport(array $client, Request $request): array`
- `authorizeClientRequest(array $client, Request $request, string $touchType): array`
- `changeMainProvince(int $userId, string $province, int $actorUserId, bool $admin): array`
- `beginAllDeviceReset(int $userId, int $actorUserId): array`
- `registerBlacklistRule(array $payload, int $actorUserId): array`
- `listBlacklistRules(array $filters = []): array`
- `setBlacklistRuleActive(int $ruleId, bool $active, int $actorUserId): void`
- `recordManualDeviceBlock(...)`, `releaseDeviceBlock(...)`, `releaseAccountBan(...)`

- [ ] **Step 1: Write failing service tests**

Use a fake `Database` matching the repository's shell-test style. Cover:

- account plan aggregation (one active geo-locked service enables the account; inactive/legacy plans do not);
- exact IP/CIDR/device/province/city/country blacklist matches block without counting a geo violation;
- challenge hash persistence, five-minute expiry, one-time use, user/client/device/attempt binding;
- effective-path foreign result requiring `physical_no_proxy`, then foreign direct result counting a violation;
- provider failure returning `location_check_unavailable` without counting;
- unchanged source-IP cache and `next_location_check_at` jitter range;
- ordinary allowed province, special circles, self-only regions, and adjacent province decisions;
- `attempt_id` deduplication and third violation setting `users.status='banned'` in one transaction.

Example assertion:

```php
$result = $service->authorizeClientRequest($client, $request, 'pull');
assertSame('geo_lock_violation', $result['error'] ?? null);
assertSame(1, $db->temporaryPolicyBlocks);
assertSame(3, $db->countedViolationsForUser(17));
assertSame('banned', $db->users[17]['status']);
```

- [ ] **Step 2: Run service tests and confirm failure**

```powershell
php tests/access_policy_service_test.php
php tests/access_policy_concurrency_test.php
```

Expected: FAIL because the service/store classes do not exist.

- [ ] **Step 3: Implement `AccessPolicyStore`**

Keep SQL persistence isolated: normalized rule lookup, profile lock/read/update, challenge hash consumption, observation insert, event insert with `attempt_id` uniqueness, account-ban insert, reset generation/snapshot reads, and bounded admin list queries. Store no raw challenge token or unrestricted provider payload.

- [ ] **Step 4: Implement `AccessPolicyService` decision order**

Resolve trusted source IP through `TrustedClientIpResolver`, then apply account status, global deny rules, permanent/temporary UUID controls, due-check/cache, challenge/report validation, geographic deny rules, main-province bind/reset, allowed-region calculation, immediate block, deduplication, and atomic third-violation account BAN in the exact order from the spec. Throw `ApiException` with the stable codes and redacted details.

- [ ] **Step 5: Implement challenge/report normalization**

Accept `protocol_version=1`, `platform` values `android|windows|macos|ios`, a stable UUID, `attempt_id`, and an observations array. Validate `effective_path` and conditional `physical_no_proxy`, Bilibili provider field shapes, IP syntax, timestamps, report size, and challenge binding. Return `direct_location_check_required` for a foreign effective path and no violation until a direct report is supplied.

- [ ] **Step 6: Implement cache, jitter, and violation transaction**

Use a 30-minute base due time plus cryptographically safe 0-300 second jitter, 24-hour same-UUID/source cache, unique event key per attempt/location event, and a `SELECT ... FOR UPDATE` user/profile boundary before inserting the third event and account BAN. Repeated calls during a temporary block must not create new violation events.

- [ ] **Step 7: Run service and concurrency tests**

```powershell
php tests/access_policy_service_test.php
php tests/access_policy_concurrency_test.php
```

Expected: both report `ok`.

- [ ] **Step 8: Commit the service task**

```powershell
git add -- src/Support/AccessPolicyService.php src/Support/AccessPolicyStore.php tests/access_policy_service_test.php tests/access_policy_concurrency_test.php
git commit -m "feat: add panel access policy decision engine"
```

---

### Task 4: Make device controls temporary-ban aware and add reset semantics

**Files:**
- Modify: `master/src/Support/DeviceControlService.php:1-450`
- Modify: `master/src/Support/ClientOnlineSessionService.php:123-166`
- Modify: `master/src/Support/AccessPolicyService.php`
- Test: `master/tests/device_temporary_block_contract_test.php`
- Test: `master/tests/access_policy_reset_test.php`

**Interfaces:**
- `DeviceControlService::setTemporaryBlock(int $clientId, string $deviceId, string $origin, int $seconds, int $actorUserId): array`
- `DeviceControlService::releaseTemporaryBlock(int $clientId, string $deviceId, int $actorUserId, bool $isAdmin): array`
- `DeviceControlService::temporaryBlockFor(int $clientId, string $deviceId): ?array`
- `ClientOnlineSessionService::revokeAllForUser(int $userId): int`
- `AccessPolicyService::beginAllDeviceReset(int $userId, int $actorUserId): array` returns `reset_generation`, `snapshot_count`, and `revoked_count`.

- [ ] **Step 1: Write failing temporary-block tests**

Extend the current device-control fixture to assert a 30-minute policy block returns `device_temporarily_banned`, carries `blocked_until`/`retry_after_seconds`, rejects challenge/enrollment/active requests, does not allow tenant early release, and lazily becomes active after expiry. Assert `manual_tenant` can be released immediately and increments the command version.

- [ ] **Step 2: Run the new device tests and confirm failure**

```powershell
php tests/device_temporary_block_contract_test.php
php tests/access_policy_reset_test.php
```

Expected: FAIL because temporary block columns and methods do not exist.

- [ ] **Step 3: Extend `DeviceControlService` without removing permanent behavior**

Treat a non-expired `blocked_until` as a hard block before enrollment/active checks. Keep permanent `banned` separate. Map protocol-v1 to `device_temporarily_banned` and legacy callers to `device_kicked`, include only safe timing fields, and enforce `release_policy` (`tenant_or_admin`, `admin_only`).

- [ ] **Step 4: Add user-wide session revocation**

Implement `revokeAllForUser` through owned clients, preserve the existing idempotent per-client revoke methods, and revoke related Ultra/device sessions without deleting audit rows.

- [ ] **Step 5: Implement Kick-all reset generation**

Under one transaction, snapshot all known UUIDs for owned clients, create `reset_pending`, revoke application sessions, preserve the web session, and on the next valid Mainland bind compare provinces. If changed, create one-hour `province_reset` blocks for snapshot UUIDs; if unchanged, create no blocks. Make repeated requests idempotent by reset generation.

- [ ] **Step 6: Run focused device/reset tests and existing Android contract tests**

```powershell
php tests/device_temporary_block_contract_test.php
php tests/access_policy_reset_test.php
php tests/android_device_control_contract_test.php
```

Expected: all three report `ok`; the existing Android contract must retain active/kicked/permanent-banned compatibility.

- [ ] **Step 7: Commit the device-control task**

```powershell
git add -- src/Support/DeviceControlService.php src/Support/ClientOnlineSessionService.php src/Support/AccessPolicyService.php tests/device_temporary_block_contract_test.php tests/access_policy_reset_test.php
git commit -m "feat: add temporary device blocks and tenant reset"
```

---

### Task 5: Add distribution-neutral client API endpoints and Panel enforcement hooks

**Files:**
- Modify: `master/src/Controllers/ClientAgentController.php:35-145,267-330,591-695,727-825`
- Modify: `master/src/Bootstrap.php:154-162,300-330`
- Modify: `master/public/index.php:42-94`
- Test: `master/tests/access_policy_api_contract_test.php`
- Test: `master/tests/client_agent_access_policy_integration_test.php`

**Interfaces:**
- `ClientAgentController::locationChallenge(Request $request, array $params): Response`
- `ClientAgentController::locationReport(Request $request, array $params): Response`
- `ClientAgentController::accessPolicyStatus(Request $request, array $params): Response`
- Existing `desktopLogin`, `enrollDevice`, `pullConfigs`, `heartbeat`, and `selectDesktopClient` delegate policy checks to `AccessPolicyService` where the account/rule state requires it.

- [ ] **Step 1: Write failing API contract tests**

Construct `Request` objects for all four platform headers and assert identical response shapes: challenge response, valid report response, `direct_location_check_required`, `access_blacklisted`, `geo_lock_violation`, `device_temporarily_banned`, and `account_banned`. Assert no response includes raw admin notes, rule IDs, provider payload, other device UUIDs, or credentials.

- [ ] **Step 2: Run API tests and confirm failure**

```powershell
php tests/access_policy_api_contract_test.php
php tests/client_agent_access_policy_integration_test.php
```

Expected: FAIL because routes, controller methods, and shared service delegation are missing.

- [ ] **Step 3: Wire `AccessPolicyService` into `ClientAgentController`**

Inject the service optionally for existing constructor compatibility. Add the two location endpoints and status endpoint without an Android-only guard. `locationChallenge` authenticates the client token and validates UUID/attempt/platform; `locationReport` consumes the challenge and returns a safe decision payload. `accessPolicyStatus` returns due time, lock state, main province, and protocol version only.

- [ ] **Step 4: Enforce policy on existing flows**

Call the service after credential/token context is known and before issuing or returning usable enrollment/session/config results. Keep the existing Android attestation check intact, but do not require an Android header for the shared policy endpoints. Existing default-off accounts remain behavior-compatible.

- [ ] **Step 5: Register routes and redact public errors**

Register:

```php
$this->router->add('POST', '/api/client/access/location/challenge', [$clientAgent, 'locationChallenge']);
$this->router->add('POST', '/api/client/access/location/report', [$clientAgent, 'locationReport']);
$this->router->add('GET', '/api/client/access/policy', [$clientAgent, 'accessPolicyStatus']);
```

Extend `public/index.php` to pass through the stable policy error payload and safe timing fields for `/api/client/*`, while retaining generic public errors for HTML and all other API paths.

- [ ] **Step 6: Run contract and existing client-agent tests**

```powershell
php tests/access_policy_api_contract_test.php
php tests/client_agent_access_policy_integration_test.php
php tests/android_device_control_contract_test.php
php tests/client_configs_revision_test.php
```

Expected: all report `ok`.

- [ ] **Step 7: Commit the API task**

```powershell
git add -- src/Controllers/ClientAgentController.php src/Bootstrap.php public/index.php tests/access_policy_api_contract_test.php tests/client_agent_access_policy_integration_test.php
git commit -m "feat: expose shared access policy API"
```

---

### Task 6: Add admin access-control management and plan switch UI

**Files:**
- Modify: `master/src/Controllers/AdminController.php:28-45,1522-1658,4949-4956`
- Modify: `master/src/Controllers/BaseController.php:1088-1108`
- Modify: `master/src/Bootstrap.php:186-220`
- Modify: `master/src/Support/ProvisioningService.php:2997-3195`
- Test: `master/tests/admin_access_policy_surface_test.php`
- Test: `master/tests/admin_plan_geo_lock_test.php`

**Interfaces:**
- `AdminController::accessControlPage(Request $request, array $params): Response`
- `AdminController::saveAccessBlacklistRule(Request $request, array $params): Response`
- `AdminController::toggleAccessBlacklistRule(Request $request, array $params): Response`
- `AdminController::releaseDeviceBlock(Request $request, array $params): Response`
- `AdminController::releaseAccountBan(Request $request, array $params): Response`
- `ProvisioningService::savePlan` accepts `geo_lock_enabled` and persists it as an integer boolean.

- [ ] **Step 1: Write failing admin/plan tests**

Assert the navigation contains `/admin/access-control`, the page renders Blacklist/Geo Profiles/Violations/Device Blocks/Account Bans sections, every mutation requires `SessionAuth::validateCsrf`, tenant-only releases are impossible, admin release actions call the service, and the plan form contains `name="geo_lock_enabled"` with the current value.

- [ ] **Step 2: Run admin tests and confirm failure**

```powershell
php tests/admin_access_policy_surface_test.php
php tests/admin_plan_geo_lock_test.php
```

Expected: FAIL because the route, page, form field, and persistence path are absent.

- [ ] **Step 3: Persist the plan switch**

Pass `geo_lock_enabled` from `AdminController::savePlan` to `ProvisioningService::savePlan`; update both UPDATE and INSERT SQL and return/list the value. Treat missing input as `0` so old forms cannot accidentally enable the lock.

- [ ] **Step 4: Add admin navigation and route registration**

Add the translated-source navigation item to `BaseController::adminNavigationItems`, register the GET/POST routes, and keep all controller methods behind `adminViewer()` plus CSRF on mutations.

- [ ] **Step 5: Implement the admin page**

Use bounded filters and escaped server-rendered tables. Provide normalized rule create/edit/toggle forms, profile/reset status, violation evidence without raw provider payload, temporary/permanent block status with allowed release buttons, and account-ban release. Use stable source strings that the i18n catalogs can patch; do not echo exception details into flash messages.

- [ ] **Step 6: Run admin tests and related navigation/plan suites**

```powershell
php tests/admin_access_policy_surface_test.php
php tests/admin_plan_geo_lock_test.php
php tests/admin_nav_consistency_regression_test.php
php tests/admin_plan_storefront_controls_regression_test.php
```

Expected: all report `ok`.

- [ ] **Step 7: Commit the admin task**

```powershell
git add -- src/Controllers/AdminController.php src/Controllers/BaseController.php src/Bootstrap.php src/Support/ProvisioningService.php tests/admin_access_policy_surface_test.php tests/admin_plan_geo_lock_test.php
git commit -m "feat: add admin access policy controls"
```

---

### Task 7: Add tenant province, device, and reset controls

**Files:**
- Modify: `master/src/Controllers/TenantController.php:29-190,1004-1290,3240-3250`
- Modify: `master/src/Bootstrap.php:258-276`
- Test: `master/tests/tenant_access_policy_surface_test.php`
- Test: `master/tests/tenant_access_policy_permissions_test.php`

**Interfaces:**
- `TenantController::accessSecurityPage(Request $request, array $params): Response`
- `TenantController::changeMainProvince(Request $request, array $params): Response`
- `TenantController::kickAllDevices(Request $request, array $params): Response`
- `TenantController::restoreDevice(Request $request, array $params): Response`
- Existing single-device kick/ban/unban handlers delegate to generic UUID controls and no longer require an `android-client-` prefix.

- [ ] **Step 1: Write failing tenant tests**

Assert `/tenant/security` renders current province, last verification, next eligible time, device rows, Kick-all, daily change, and restore actions. Assert tenant changes require CSRF, reject a second change inside 24 hours, require a recent observation, allow immediate restore only for `manual_tenant`, and cannot release policy/admin/permanent/account bans. Assert Kick-all preserves the web session and creates a reset generation.

- [ ] **Step 2: Run tenant tests and confirm failure**

```powershell
php tests/tenant_access_policy_surface_test.php
php tests/tenant_access_policy_permissions_test.php
```

Expected: FAIL because the routes and controller methods do not exist.

- [ ] **Step 3: Add tenant routes and navigation**

Register GET/POST `/tenant/security`, POST `/tenant/security/main-province`, POST `/tenant/security/kick-all`, and POST `/tenant/security/devices/restore`. Add a `Security` tenant navigation item using existing `tenantNav` patterns.

- [ ] **Step 4: Implement tenant security page**

Load only the current user's profile, owned clients/devices, safe block state, and recent verification metadata. Render all labels/buttons as translatable source strings, escape UUID/IP/city values, keep confirmation text fixed, and avoid exposing admin evidence or internal rule IDs.

- [ ] **Step 5: Implement daily province change and Kick-all**

Use `AccessPolicyService::changeMainProvince` and `beginAllDeviceReset`; return generic success/error flashes. Kick-all must revoke application sessions but not call `SessionAuth::logout()`.

- [ ] **Step 6: Generalize existing device actions**

Replace the Android-prefix gate in tenant kick/ban/unban with ownership validation through the default client's UUID rows. Map tenant kick to `manual_tenant` temporary block and expose immediate restore; preserve permanent ban as admin-only release state.

- [ ] **Step 7: Run tenant and existing client-page tests**

```powershell
php tests/tenant_access_policy_surface_test.php
php tests/tenant_access_policy_permissions_test.php
php tests/tenant_client_reset_route_regression_test.php
php tests/client_online_session_revoke_test.php
```

Expected: all report `ok`.

- [ ] **Step 8: Commit the tenant task**

```powershell
git add -- src/Controllers/TenantController.php src/Bootstrap.php tests/tenant_access_policy_surface_test.php tests/tenant_access_policy_permissions_test.php
git commit -m "feat: add tenant geographic security controls"
```

---

### Task 8: Add complete three-language i18n coverage

**Files:**
- Modify: `master/public/i18n/zh-Hans.json`
- Modify: `master/public/i18n/zh-TW.json`
- Modify: `master/public/i18n/en.json`
- Modify: `master/src/Controllers/BaseController.php` (only if a new source string needs shared navigation wiring)
- Test: `master/tests/access_policy_i18n_test.php`

**Interfaces:**
- New visible source strings are exact keys consumed by the existing `patch.js` text/attribute maps. English remains the source/fallback catalog; Simplified and Traditional catalogs provide non-empty translated values.

- [ ] **Step 1: Write the failing i18n test**

Define the complete new key list in one test and assert all three catalogs contain matching text/attribute keys, non-empty values, no ASCII `?` corruption, and translated values for the two Chinese catalogs. Include navigation, tabs, entity types, province/region states, challenge/ban messages, release permissions, confirmations, and plan switch labels.

- [ ] **Step 2: Run the i18n test and confirm failure**

```powershell
php tests/access_policy_i18n_test.php
```

Expected: FAIL with missing access-policy keys.

- [ ] **Step 3: Add the catalog entries**

Add identical English source keys to `en.json` (text/attributes), Simplified Chinese translations to `zh-Hans.json`, and Traditional Chinese translations to `zh-TW.json`. Keep JSON valid UTF-8 and preserve existing keys/order style; do not put dynamic IP/UUID/timestamp data in catalog keys.

- [ ] **Step 4: Run focused i18n and existing locale coverage tests**

```powershell
php tests/access_policy_i18n_test.php
php tests/i18n_locale_regression_test.php
```

Expected: both report `ok`.

- [ ] **Step 5: Commit the i18n task**

```powershell
git add -- public/i18n/zh-Hans.json public/i18n/zh-TW.json public/i18n/en.json tests/access_policy_i18n_test.php
git commit -m "feat: localize access policy controls"
```

---

### Task 9: Write the cross-distribution handoff and protocol fixtures

**Files:**
- Create: `docs/handoffs/2026-08-28-panel-access-policy-distribution-handoff.md`
- Create: `master/tests/fixtures/access-policy-v1.json`
- Test: `master/tests/access_policy_handoff_contract_test.php`

**Interfaces:**
- Handoff is the implementation contract for Android, Windows, macOS, and future iOS; it must not claim those clients are implemented.
- Fixture contains challenge, effective-path foreign, direct physical, allowed, violation, temporary-ban, account-ban, and reset responses.

- [ ] **Step 1: Write the failing handoff contract test**

Assert the handoff and fixture contain protocol version 1, all stable error codes, all four platform values, `effective_path`, `physical_no_proxy`, `attempt_id`, five-minute challenge, 30-minute plus jitter schedule, 24-hour cache, three-violation account BAN, daily province change, Kick-all reset, tenant/manual/admin/policy release rules, and the explicit statement that Panel cannot bypass a remote client proxy.

- [ ] **Step 2: Run the handoff test and confirm failure**

```powershell
php tests/access_policy_handoff_contract_test.php
```

Expected: FAIL because the handoff and fixture do not exist.

- [ ] **Step 3: Write the distribution-neutral handoff**

Document endpoint URLs, auth context, JSON schemas, challenge/report lifecycle, retry/idempotency, cache/jitter, stable UUID requirements, physical no-proxy semantics, privacy fields, error mapping, staged rollout, evidence format, and separate checklists for Android/Windows/macOS/iOS. Mark native implementation and binary proof as pending for every distribution.

- [ ] **Step 4: Add JSON fixtures**

Keep fixtures credential-free and deterministic. Include no real user/IP data; use RFC 5737/RFC 3849 documentation addresses and synthetic province/city values.

- [ ] **Step 5: Run handoff and fixture validation**

```powershell
php tests/access_policy_handoff_contract_test.php
php -r "json_decode(file_get_contents('tests/fixtures/access-policy-v1.json'), true, 512, JSON_THROW_ON_ERROR); echo 'ok'.PHP_EOL;"
```

Expected: both report `ok`.

- [ ] **Step 6: Commit the handoff task**

```powershell
git add -- docs/handoffs/2026-08-28-panel-access-policy-distribution-handoff.md tests/fixtures/access-policy-v1.json tests/access_policy_handoff_contract_test.php
git commit -m "docs: hand off shared access policy protocol"
```

---

### Task 10: Run integrated verification and browser QA

**Files:**
- Modify only if verification finds a defect in the files from Tasks 1-9.
- Test: existing focused Panel suites plus all new `access_policy_*` and related admin/tenant/device tests.

- [ ] **Step 1: Audit the client-file boundary**

```powershell
git diff master --name-only -- leikwan-android LeikwanBridge.Desktop wstunnel-main client-agent
```

Expected: no output for the access-policy implementation commits. Any pre-existing dirty client files remain unstaged and untouched.

- [ ] **Step 2: Run PHP syntax checks for changed Panel PHP files**

```powershell
Get-ChildItem src -Recurse -Filter '*.php' | ForEach-Object { php -l $_.FullName } 
```

Expected: every changed PHP file reports `No syntax errors detected`.

- [ ] **Step 3: Run all focused new tests**

```powershell
Get-ChildItem tests -Filter '*access_policy*test.php' | Sort-Object Name | ForEach-Object { php $_.FullName }
php tests/device_temporary_block_contract_test.php
php tests/access_policy_reset_test.php
php tests/admin_nav_consistency_regression_test.php
php tests/i18n_locale_regression_test.php
```

Expected: every command exits `0` and prints `ok`.

- [ ] **Step 4: Run related existing regression tests**

```powershell
php tests/android_device_control_contract_test.php
php tests/android_device_control_schema_test.php
php tests/client_online_session_revoke_test.php
php tests/admin_plan_storefront_controls_regression_test.php
php tests/tenant_client_reset_route_regression_test.php
php tests/tenant_controller_regressions_test.php
```

Expected: every command exits `0`; any regression is fixed with a new failing test before code changes.

- [ ] **Step 5: Start the local Panel with a non-production port**

Use the repository's existing local PHP server instructions, choose a free port, and do not point it at production credentials or data. Record the URL in the verification notes only.

- [ ] **Step 6: Run `/browse` QA**

Verify `/admin/access-control`, `/admin/plans`, `/tenant/security`, and `/tenant/clients` at desktop and mobile widths. Check all three locale query/cookie paths, long translated labels, empty/loading/error states, CSRF failures, confirmation dialogs, no horizontal overflow, no overlapping text, and redaction of rule/evidence details.

- [ ] **Step 7: Run final diff and status audit**

```powershell
git diff --check master
git status --short --branch
git diff master --stat -- master/src master/migrations master/public/i18n master/tests docs/handoffs
```

Expected: only approved Panel/docs paths are in the access-policy commits; unrelated dirty files are still uncommitted and unchanged.

- [ ] **Step 8: Commit only verified fixes, if any**

For each defect found by verification, repeat the relevant red-green test cycle and use a focused commit. Do not squash or stage unrelated work.

---

## Plan Self-Review

- Spec coverage: schema, plan aggregation, global rules, protocol/challenge, direct no-proxy handoff, 30-minute jitter/cache, Mainland adjacency/special sets, three-violation BAN, temporary UUID blocks, tenant restore, Kick-all reset, admin/tenant UI, three catalogs, handoff, and verification each have a task.
- Boundary coverage: no client source/binary is touched; Panel cannot discover a remote direct public IP with Bilibili `/zone` and the handoff states this explicitly.
- Idempotency coverage: challenge one-time use, `attempt_id` uniqueness, reset generation, temporary block expiry/release, and migration reruns are tested.
- Security coverage: trusted source IP, CIDR binary matching, CSRF/session roles, redaction, bounded lists, no raw tokens/provider payloads, and no credential fields in new tables are tested.
- Placeholder scan: no `TBD`, `TODO`, or unspecified implementation step is used; every task names files, interfaces, tests, commands, and expected outcomes.
- Type consistency: helper/service/controller method names in later tasks match the interfaces produced by earlier tasks.
