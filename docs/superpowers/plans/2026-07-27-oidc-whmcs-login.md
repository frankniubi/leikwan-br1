# OIDC Login and WHMCS Auto-Binding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add secure WHMCS-backed OIDC login, verified-email synchronization, just-in-time tenant creation, and automatic WHMCS binding.

**Architecture:** A focused OIDC protocol client validates the authorization-code response and RS256 ID token. A transactional identity service maps `(issuer, subject)` to a tenant and synchronizes verified email. A thin controller owns the browser redirect/session flow and invokes the existing WHMCS binding service.

**Tech Stack:** PHP 8.3, cURL, OpenSSL, PDO/MySQL and SQLite tests, server-rendered HTML, script-style PHP tests.

---

## File Map and Shared Interfaces

- Create `master/src/Support/OidcClient.php`: discovery, authorization URL, token exchange, userinfo, RS256 verification.
- Create `master/src/Support/OidcSessionStore.php`: bounded single-use session flow storage.
- Create `master/src/Support/OidcIdentityService.php`: transactional user/identity resolution and email synchronization.
- Create `master/src/Controllers/OidcController.php`: start/callback HTTP orchestration.
- Create `master/migrations/20260727_add_oidc_identities.sql`: identity table.
- Modify `master/migrations/schema.sql`: fresh-install identity table.
- Modify `master/src/Bootstrap.php`: instantiate services and register two OIDC routes.
- Modify `master/src/Controllers/AuthController.php`: conditionally render OIDC login action.
- Modify `master/src/Support/SessionAuth.php`: regenerate the session ID after authentication.
- Modify `master/.env.example`: document non-secret OIDC configuration keys.
- Create focused tests in `master/tests/oidc_*_test.php`.

Cross-track contracts:

```php
final class OidcClient
{
    public function isConfigured(): bool;
    public function providerName(): string;
    public function usesPkce(): bool;
    public function authorizationUrl(array $flow): string;
    public function authenticateCallback(string $code, array $flow): array;
}

final class OidcSessionStore
{
    public function create(string $returnTo, bool $usePkce): array;
    public function consume(string $state): array;
}

final class OidcIdentityService
{
    public function login(array $claims): array;
}
```

`authenticateCallback()` returns normalized keys `issuer`, `subject`, `email`, `email_verified`, and `name`. `login()` returns the complete `users` row.

### Task 1: OIDC Protocol and One-Time Session Flow

**Files:**
- Create: `master/src/Support/OidcClient.php`
- Create: `master/src/Support/OidcSessionStore.php`
- Test: `master/tests/oidc_client_test.php`
- Test: `master/tests/oidc_session_store_test.php`

- [ ] **Step 1: Write failing protocol tests**

Cover state/nonce/PKCE parameters, discovery issuer validation, RS256 signature verification, `aud`/`azp`, expiry, nonce, subject, userinfo subject, and verified-email rejection using an injected HTTP callback and generated RSA key.

```php
$claims = $client->authenticateCallback('code-1', $flow);
assert($claims['issuer'] === 'https://buy.example.test');
assert($claims['subject'] === 'client-42');
assert($claims['email'] === 'user@example.test');
assert($claims['email_verified'] === true);
```

- [ ] **Step 2: Run RED tests**

Run: `php master/tests/oidc_client_test.php && php master/tests/oidc_session_store_test.php`

Expected: failure because OIDC support classes do not exist.

- [ ] **Step 3: Implement minimal strict client and store**

Use HTTPS-only metadata endpoints, `state`, mandatory nonce, optional S256 PKCE, configurable `client_secret_post`/`client_secret_basic`, exact `RS256`, JWK-to-PEM conversion, and ten-minute single-use session flows.

- [ ] **Step 4: Run GREEN tests and lint**

Run: `php master/tests/oidc_client_test.php && php master/tests/oidc_session_store_test.php && php -l master/src/Support/OidcClient.php && php -l master/src/Support/OidcSessionStore.php`

Expected: all commands succeed.

### Task 2: Transactional Identity and Email Synchronization

**Files:**
- Create: `master/migrations/20260727_add_oidc_identities.sql`
- Modify: `master/migrations/schema.sql`
- Create: `master/src/Support/OidcIdentityService.php`
- Test: `master/tests/oidc_identity_service_test.php`
- Test: `master/tests/oidc_schema_regression_test.php`

- [ ] **Step 1: Write failing SQLite behavior and schema tests**

Test JIT tenant creation, linking an existing active tenant, activating a pending-verification tenant, returning identity email updates, duplicate-email rejection, admin rejection, disabled-user rejection, and subject reassignment rejection.

```php
$user = $service->login([
    'issuer' => 'https://buy.example.test',
    'subject' => 'client-42',
    'email' => 'user@example.test',
    'email_verified' => true,
    'name' => 'Example User',
]);
assert($user['role'] === 'tenant');
assert($user['status'] === 'active');
```

- [ ] **Step 2: Run RED tests**

Run: `php master/tests/oidc_identity_service_test.php && php master/tests/oidc_schema_regression_test.php`

Expected: failure because the service and schema are absent.

- [ ] **Step 3: Implement migration and service**

Use SHA-256 issuer/subject keys for compact unique indexes. Resolve and mutate users inside `Database::transaction()`. Only accept verified, normalized email. Never claim admins or silently reactivate disabled users.

- [ ] **Step 4: Run GREEN tests and lint**

Run: `php master/tests/oidc_identity_service_test.php && php master/tests/oidc_schema_regression_test.php && php -l master/src/Support/OidcIdentityService.php`

Expected: all commands succeed.

### Task 3: Routes, Controller, Login UI, and Configuration

**Files:**
- Create: `master/src/Controllers/OidcController.php`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/AuthController.php`
- Modify: `master/src/Support/SessionAuth.php`
- Modify: `master/.env.example`
- Test: `master/tests/oidc_web_integration_test.php`

- [ ] **Step 1: Write failing integration assertions**

Assert route wiring, conditional login action, local return-path sanitization, callback service sequence, WHMCS warning behavior, and session-ID regeneration source guard.

```php
assert(str_contains($bootstrap, "'/auth/oidc/start'"));
assert(str_contains($bootstrap, "'/auth/oidc/callback'"));
assert(str_contains($loginHtml, '/auth/oidc/start'));
```

- [ ] **Step 2: Run RED test**

Run: `php master/tests/oidc_web_integration_test.php`

Expected: failure because the routes and controller are absent.

- [ ] **Step 3: Implement web integration**

The start route creates a flow and redirects to the provider. Callback consumes state, authenticates the provider response, resolves the user, calls `WhmcsIdentityService::tryDirectBindForUser()`, logs in through `SessionAuth`, sets success/warning flash, and redirects only to a sanitized local path.

- [ ] **Step 4: Run GREEN test and lint**

Run: `php master/tests/oidc_web_integration_test.php && php -l master/src/Controllers/OidcController.php && php -l master/src/Controllers/AuthController.php && php -l master/src/Bootstrap.php`

Expected: all commands succeed.

### Task 4: Integration Review and Regression Verification

**Files:**
- Modify only files required to resolve review findings.

- [ ] **Step 1: Run focused suite**

Run all `master/tests/oidc_*_test.php` plus `master/tests/auth_registration_error_message_regression_test.php`.

- [ ] **Step 2: Run CodeGraph impact review**

Check callers and impact for `SessionAuth::login`, `AuthController::loginPage`, and `Bootstrap::registerRoutes`.

- [ ] **Step 3: Run PHP lint and full master regression suite**

Lint every changed PHP file, then execute every `master/tests/*_test.php` and report any baseline/environment failures separately.

- [ ] **Step 4: Complete spec and code-quality reviews**

Review strict claim handling, collision policy, transactionality, error redaction, and absence of committed secrets. Fix every important finding and rerun affected tests.

### Task 5: Live Rollout and End-to-End Verification

**Files:**
- No committed secrets.

- [ ] **Step 1: Inspect current runtime and back up**

Confirm authoritative deployment path, PHP/OpenSSL/cURL support, database migration state, and current `.env`; back up application files and database before mutation.

- [ ] **Step 2: Register a dedicated WHMCS OAuth client**

Use the panel callback URI, `openid email profile`, and a new client identifier/secret. Keep values only in runtime configuration.

- [ ] **Step 3: Deploy release and apply migration**

Deploy the committed worktree state, update environment configuration atomically, run pending migrations, and verify web/PHP service health.

- [ ] **Step 4: Verify public OIDC behavior**

Prove start redirect parameters, state/nonce presence, successful provider login, email synchronization, identity persistence, automatic WHMCS binding, replay rejection, and unchanged password login.
