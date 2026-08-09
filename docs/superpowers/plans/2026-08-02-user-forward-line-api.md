# User Forward Line API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a tenant-owned forward-rule CRUD API with strict authorization, idempotent creation, optimistic concurrency, safe response DTOs, shared injection-resistant validation, and hardened legacy/Web compatibility paths.

**Architecture:** Add a pure `ForwardLineApiContract` for request validation and safe DTO construction, and a focused `ForwardLineApiController` for the v1 Bearer transport. Keep provisioning in `ProvisioningService`, but make its route authorization fail closed and its mutations transactional. Persist revision, hashed idempotency metadata, an explicit user-deletion tombstone, and a distinct balance-suspension marker on `forwards`; legacy API and tenant Web handlers reuse the same allowlists and policy checks.

**Tech Stack:** PHP 8.3+, MySQL/MariaDB migrations, existing custom Router/Request/Response stack, standalone PHP regression tests, Python `unittest`/`pytest` for slave-agent defense-in-depth tests.

---

### Task 1: Forward API persistence contract

**Files:**
- Create: `master/tests/forward_line_api_schema_test.php`
- Create: `master/migrations/20260802_add_forward_line_api_guards.sql`
- Modify: `master/migrations/schema.sql`
- Modify: `master/.env.example`

- [ ] **Step 1: Write the failing schema test**

Create a standalone test that reads both schema files and requires these exact concepts in each canonical location:

```php
$requiredColumns = [
    'api_revision BIGINT UNSIGNED NOT NULL DEFAULT 1',
    'api_idempotency_key_hash CHAR(64) NULL',
    'api_request_hash CHAR(64) NULL',
    'user_deleted_at DATETIME NULL',
    'balance_suspended_at DATETIME NULL',
    'UNIQUE KEY uniq_forward_service_idempotency (service_id, api_idempotency_key_hash)',
];
```

Also require `USER_FORWARD_LIMIT_PER_SERVICE=64` and `USER_FORWARD_STORED_LIMIT_PER_SERVICE=512` in `.env.example`, and reject plaintext idempotency-key storage names.

- [ ] **Step 2: Run the test and verify RED**

Run: `php master/tests/forward_line_api_schema_test.php`

Expected: exit 1 naming the first missing `api_revision` schema fragment.

- [ ] **Step 3: Add the migration, canonical schema, and configuration default**

The migration adds five columns, backfills only explicit historical deletes with forward-level audit evidence, leaves ambiguous disabled rows unclassified and non-reactivatable, and adds the unique composite index. The canonical `forwards` table includes the same fields. Document the bounded defaults in `.env.example`:

```dotenv
USER_FORWARD_LIMIT_PER_SERVICE=64
USER_FORWARD_STORED_LIMIT_PER_SERVICE=512
```

- [ ] **Step 4: Run focused verification**

Run: `php master/tests/forward_line_api_schema_test.php`

Expected: `ok`.

- [ ] **Step 5: Commit**

```bash
git add master/migrations/20260802_add_forward_line_api_guards.sql master/migrations/schema.sql master/.env.example master/tests/forward_line_api_schema_test.php
git commit -m "feat: add forward API persistence guards"
```

### Task 2: Strict request and response contract

**Files:**
- Create: `master/src/Support/ForwardLineApiContract.php`
- Create: `master/tests/forward_line_api_contract_test.php`

- [ ] **Step 1: Write failing tests for valid create and patch normalization**

The test autoloads `ForwardLineApiContract` and asserts this public API:

```php
$create = ForwardLineApiContract::normalizeCreate([
    'route_id' => 14,
    'listen_port' => null,
    'transport' => 'tcp',
    'target' => ['source' => 'manual', 'host' => 'backend.example.com', 'port' => 443],
]);
assertSame(14, $create['forward_route_id']);
assertSame(0, $create['listen_port']);
assertSame('manual', $create['target_source']);

$patch = ForwardLineApiContract::normalizePatch(['target' => ['port' => 8443]]);
assertSame(['target_port' => 8443], $patch['changes']);
```

- [ ] **Step 2: Run and verify RED**

Run: `php master/tests/forward_line_api_contract_test.php`

Expected: class-not-found failure for `ForwardLineApiContract`.

- [ ] **Step 3: Implement strict normalization**

Implement public static methods:

```php
public static function normalizeCreate(array $payload): array;
public static function normalizePatch(array $payload): array;
public static function normalizeLegacyCreate(array $payload): array;
public static function normalizeLegacyUpdate(array $payload): array;
public static function validateTargetHost(string $host): string;
public static function requestHash(array $normalized): string;
public static function etag(array $forward): string;
public static function matchesEtag(?string $ifMatch, array $forward): bool;
public static function presentForward(array $forward): array;
public static function presentRoute(array $route): array;
public static function presentService(array $summary): array;
```

Reject unknown fields, non-integer IDs/ports, ports outside `1..65535`, non-enum transports, URLs, whitespace/control bytes, systemd/shell separators, invalid IPv4/IPv6/DNS names, and empty patches. Build DTOs from explicit allowed keys only.

- [ ] **Step 4: Add adversarial and privacy cases**

Test CR/LF, NUL, `http://`, `/path`, `user@host`, `%zone`, `$()`, backticks, semicolon, ports `0/65536`, numeric strings, unknown internal fields, idempotency hashes, stale/current ETags, and a source row containing every token/node/Sidecar/combination field. The encoded DTO must not contain `token`, `secret`, `pass`, `node`, `sidecar`, `combination`, `leigod`, `config_json`, `socks`, or an injected internal value.

- [ ] **Step 5: Run and verify GREEN**

Run: `php master/tests/forward_line_api_contract_test.php`

Expected: `ok`.

- [ ] **Step 6: Commit**

```bash
git add master/src/Support/ForwardLineApiContract.php master/tests/forward_line_api_contract_test.php
git commit -m "feat: define strict forward API contract"
```

### Task 3: Fail-closed and atomic provisioning boundary

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/forward_plan_route_allowlist_regression_test.php`
- Create: `master/tests/forward_target_validation_regression_test.php`
- Create: `master/tests/forward_mutation_atomicity_regression_test.php`

- [ ] **Step 1: Write a failing plan-route allowlist test**

Use the existing fake-database style to prove an active plan with zero `service_plan_forward_routes` rows receives `[]`, while an explicitly mapped active route remains visible. Assert that no query fallback loads all active routes.

Run: `php master/tests/forward_plan_route_allowlist_regression_test.php`

Expected: the unmapped plan incorrectly receives active routes.

- [ ] **Step 2: Make route selection fail closed**

Change `availableForwardRoutesForService()` to return only active routes joined through the service plan. Remove the all-active fallback. Keep route availability calculation, then redact it through the contract presenter at response boundaries.

- [ ] **Step 3: Write failing central-validation and rollback tests**

Prove `createForward()` and `updateForwardNonStructural()` reject unsafe hosts and out-of-range ports even when called directly, without a controller. In the fake database, force `no_available_nodes` after the insert and assert the transaction rolls back forwards, endpoints, tasks, audits, and revision changes.

- [ ] **Step 4: Make all forward mutations transactional and revision-aware**

Wrap create, structural update, non-structural update, and delete in `Database::transaction()`. Validate the resolved target with `ForwardLineApiContract::validateTargetHost()` and strict port checks before persistence. Initialize `api_revision=1`, persist the two API hashes on v1 create, set `user_deleted_at` on delete, set `balance_suspended_at` only in the billing suspension job, and increment `api_revision` on update/delete/suspension/reactivation. Add optional expected-revision arguments so the v1 controller can verify again inside the transaction before endpoint retirement.

- [ ] **Step 5: Run focused provisioning tests**

```bash
php master/tests/forward_plan_route_allowlist_regression_test.php
php master/tests/forward_target_validation_regression_test.php
php master/tests/forward_mutation_atomicity_regression_test.php
php master/tests/provisioning_forward_non_structural_update_test.php
php master/tests/provisioning_specific_node_persistence_regression_test.php
```

Expected: every command exits 0 and prints `ok` or its existing success output.

- [ ] **Step 6: Commit**

```bash
git add master/src/Support/ProvisioningService.php master/tests/forward_plan_route_allowlist_regression_test.php master/tests/forward_target_validation_regression_test.php master/tests/forward_mutation_atomicity_regression_test.php
git commit -m "fix: secure forward provisioning boundary"
```

### Task 4: Versioned tenant Bearer API

**Files:**
- Create: `master/src/Controllers/ForwardLineApiController.php`
- Modify: `master/src/Bootstrap.php`
- Create: `master/tests/forward_line_api_controller_test.php`
- Create: `master/tests/forward_line_api_routes_test.php`

- [ ] **Step 1: Write failing route and controller tests**

Require the six routes from the design. With a fake PDO, test authentication, tenant role, service/forward ownership, safe list/detail/options DTOs, `Content-Type`, `Cache-Control`, `X-Content-Type-Options`, and ETag headers. Cross-tenant IDs must return the same `404` as nonexistent IDs.

- [ ] **Step 2: Run and verify RED**

```bash
php master/tests/forward_line_api_routes_test.php
php master/tests/forward_line_api_controller_test.php
```

Expected: missing controller/routes failures.

- [ ] **Step 3: Implement read endpoints and safe errors**

Add `ForwardLineApiController` methods `options`, `index`, and `show`. Authenticate only active tenant users, constrain ownership in SQL, and use the contract DTOs. Catch expected `ApiException` values into a stable public envelope; log unexpected exceptions and return generic JSON without exception messages.

- [ ] **Step 4: Add failing mutation protocol tests**

Test `415` for non-JSON, `413` for bodies over 16 KiB, `428` for missing `Idempotency-Key`/`If-Match`, `409` for mismatched idempotent replay, `412` for stale ETag, `403` when `allow_manage_forwards` blocks create/update or `allow_delete_forwards` blocks delete, `409` for inactive service/quota, and success/replay semantics with a fake provisioning gateway or PDO fixture.

- [ ] **Step 5: Implement create/patch/delete**

Create hashes the idempotency header, checks an existing scoped row, and enforces both active and retained-history limits before provisioning with `actor=user_api:{user_id}`. Patch merges allowed fields with current values and uses structural update for route, target-source, transport, and automatic listen-port changes. Delete performs the existing soft retirement and returns `204`. Each successful single-resource response includes the current ETag and `Location` on create.

- [ ] **Step 6: Run controller tests and PHP lint**

```bash
php master/tests/forward_line_api_routes_test.php
php master/tests/forward_line_api_controller_test.php
php -l master/src/Controllers/ForwardLineApiController.php
php -l master/src/Support/ForwardLineApiContract.php
```

Expected: tests print `ok`; lint reports no syntax errors.

- [ ] **Step 7: Commit**

```bash
git add master/src/Controllers/ForwardLineApiController.php master/src/Bootstrap.php master/tests/forward_line_api_controller_test.php master/tests/forward_line_api_routes_test.php
git commit -m "feat: add versioned user forward API"
```

### Task 5: Harden legacy API and tenant Web flow

**Files:**
- Modify: `master/src/Controllers/ServiceController.php`
- Modify: `master/src/Controllers/TenantController.php`
- Create: `master/tests/legacy_forward_api_security_regression_test.php`
- Modify: `master/tests/tenant_forward_edit_regression_test.php`
- Create: `master/tests/provider_metadata_privacy_regression_test.php`

- [ ] **Step 1: Write failing compatibility security tests**

Prove legacy create cannot pass `forward_combination_id`, `public_port`, `path_prefix`, node/Sidecar/client placement, or unknown transport; create/update enforce `allow_manage_forwards`; delete enforces `allow_delete_forwards`; every write enforces active service; `showService()` contains no token/config/provider/topology keys; tenant edit checks `allow_manage_forwards`; and dashboard Edit href exactly matches `/tenant/services/{id}/forward/{fid}/edit`.

- [ ] **Step 2: Run and verify RED**

```bash
php master/tests/legacy_forward_api_security_regression_test.php
php master/tests/provider_metadata_privacy_regression_test.php
php master/tests/tenant_forward_edit_regression_test.php
```

Expected: current broad payload/summary and broken href assertions fail.

- [ ] **Step 3: Apply shared gates and DTOs**

Use `ForwardLineApiContract::normalizeLegacyCreate/Update()` and `presentService()`. Add active-service and feature checks to legacy create/update/delete. Keep only safe historical coercions (`endpoint_count` ignored, decimal strings and transport aliases canonicalized), while internal topology fields remain forbidden. Stop returning client runtime config from management mutations; return the safe forward resource plus its client-config URL, and serve that URL only for active forwards on active services. Normalize every legacy forward error before it reaches the global handler. Add deprecation headers to old write responses.

- [ ] **Step 4: Fix tenant edit authorization and link**

Correct the dashboard href, filter deleted forwards from the dashboard query, and enforce `allow_manage_forwards` before rendering or saving an edit. Keep server-rendered forms and CSRF behavior intact.

- [ ] **Step 5: Run focused compatibility tests**

```bash
php master/tests/legacy_forward_api_security_regression_test.php
php master/tests/provider_metadata_privacy_regression_test.php
php master/tests/tenant_forward_edit_regression_test.php
php master/tests/tenant_controller_regressions_test.php
php master/tests/tenant_forward_route_selector_test.php
```

Expected: all exit 0.

- [ ] **Step 6: Commit**

```bash
git add master/src/Controllers/ServiceController.php master/src/Controllers/TenantController.php master/tests/legacy_forward_api_security_regression_test.php master/tests/provider_metadata_privacy_regression_test.php master/tests/tenant_forward_edit_regression_test.php
git commit -m "fix: harden tenant forward compatibility paths"
```

### Task 6: Slave renderer defense in depth

**Files:**
- Modify: `slave-agent/slave_agent.py`
- Modify: `artifacts/slave-agent.py`
- Create: `slave-agent/tests/test_slave_unit_argument_safety.py`
- Modify: `artifacts/versions.json`

- [ ] **Step 1: Write a failing renderer test**

Import the source agent and prove `shell_arg()` rejects `\r`, `\n`, NUL, and other ASCII control bytes before unit rendering. Prove ordinary IPv4/IPv6/hostname targets and paths still render.

Run: `python -m unittest slave-agent.tests.test_slave_unit_argument_safety -v`

Expected: malicious values are currently accepted.

- [ ] **Step 2: Reject control bytes at the renderer boundary**

Make `shell_arg()` raise `RuntimeError('unsafe unit argument')` for ASCII control characters. Apply the identical change to the published artifact, update its SHA-256/version manifest metadata using the repo's established artifact procedure, and do not change any ingress topology or port allocation.

- [ ] **Step 3: Run renderer and artifact tests**

```bash
python -m unittest slave-agent.tests.test_slave_unit_argument_safety -v
python -m unittest slave-agent.tests.test_slave_artifact_consistency -v
php master/tests/artifact_versions_manifest_consistency_test.php
```

Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add slave-agent/slave_agent.py artifacts/slave-agent.py artifacts/versions.json slave-agent/tests/test_slave_unit_argument_safety.py
git commit -m "fix: reject unsafe slave unit arguments"
```

### Task 7: API documentation and final verification

**Files:**
- Create: `docs/api/user-forward-lines-v1.md`
- Modify: `master/README.md`

- [ ] **Step 1: Document exact requests and responses**

Include Bearer authentication, all six routes, create/patch/delete examples, `Idempotency-Key`, `If-Match`, ETag refresh, retry rules, error codes, quota, and a warning that `leigod_lines`/nodes/Sidecars are not tenant resources. Do not include real hosts, tokens, credentials, or production IDs.

- [ ] **Step 2: Run documentation and secret scans**

```bash
rg -n "leikwan-v3|cantons\.txt|shanghais\.txt|agent_token|token_secret|leigod_socks_pass" docs/api/user-forward-lines-v1.md
rg -n "TBD|TODO|FIXME" docs/api/user-forward-lines-v1.md docs/superpowers/specs/2026-08-02-user-forward-line-api-design.md
```

Expected: no matches.

- [ ] **Step 3: Run the focused suite serially**

Run every new PHP test, the existing forward route/provisioning/tenant tests listed above, every changed PHP file through `php -l`, and the two Python slave tests. Stop on the first failure and fix it before continuing.

- [ ] **Step 4: Run the broad master PHP regression set**

From `master/`, run all `tests/*_test.php` serially with a PowerShell loop that records exact failures. Compare any failure against an unchanged `master` worktree before calling it a regression.

- [ ] **Step 5: Perform independent security and code-quality reviews**

Review for IDOR, mass assignment, secret/topology disclosure, SQL/command/unit injection, stale write races, idempotency races, transaction rollback, error leakage, and compatibility. Fix every confirmed issue and rerun affected tests.

- [ ] **Step 6: Commit documentation and any reviewed fixes**

```bash
git add docs/api/user-forward-lines-v1.md master/README.md
git commit -m "docs: publish user forward API contract"
```

- [ ] **Step 7: Record final evidence**

Capture `git status --short`, `git log --oneline master..HEAD`, `git diff --check master...HEAD`, focused test counts, broad-suite results, and the exact execution model actually available. Do not deploy.
