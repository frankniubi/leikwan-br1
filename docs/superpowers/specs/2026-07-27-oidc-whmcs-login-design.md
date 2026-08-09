# OIDC Login, Email Sync, and WHMCS Auto-Binding Design

## Goal

Add generic OpenID Connect login to the LeiKwan control panel, use the verified OIDC email as the tenant account email, and automatically bind the matching WHMCS account without weakening the existing password login path.

## Context

The panel currently authenticates users by local email and password. Verified local registrations already call `WhmcsIdentityService::tryDirectBindForUser()`, which resolves a WHMCS client by email and enforces one-to-one binding. The reference implementation in `debug/sub2api-feature-work` uses authorization code flow, state, nonce, optional PKCE, discovery metadata, JWKS-backed ID token validation, and a stable `(issuer, subject)` external identity.

The WHMCS provider supports RS256 and nonce validation. PKCE remains implemented and configurable, but the current WHMCS provider configuration can disable it for compatibility.

## Architecture

### OIDC protocol client

`OidcClient` owns provider configuration, discovery, authorization URL construction, code exchange, userinfo retrieval, and RS256 ID token verification. It rejects mismatched issuer, audience, authorized party, nonce, subject, expiration, not-before, signing algorithm, key ID, and userinfo subject. HTTP and clock callbacks are injectable for deterministic tests.

### One-time browser flow

`OidcSessionStore` creates cryptographically random state, nonce, and PKCE verifier values and stores a bounded, ten-minute flow in the PHP session. Callback consumption removes the state before token exchange so replays fail even if downstream processing errors.

### Local identity resolution

`OidcIdentityService` resolves accounts transactionally using `(issuer, subject)` as the stable identity:

1. If the identity exists, its active tenant is loaded and its email is synchronized when the verified upstream email is not owned by another user.
2. If the identity is new and the verified email belongs to an existing tenant, that tenant is linked. A pending email-verification tenant becomes active because the OIDC provider has verified the email.
3. If neither identity nor email exists, a passwordless active tenant is created with a random API token.
4. Admin accounts, disabled accounts, duplicate emails, identity reassignment, and one-user/multiple-subject conflicts fail closed.

The new `oidc_identities` table stores the provider identity, last verified email, and login timestamps. Unique hashes enforce one identity per provider subject and one subject per provider/user pair without oversized text indexes.

### Controller integration

`OidcController` exposes:

- `GET /auth/oidc/start`
- `GET /auth/oidc/callback`

The callback resolves the tenant, attempts existing WHMCS email binding, creates the normal panel session, and redirects to a local return path. WHMCS lookup failure does not discard a successful login; it produces a warning flash so billing can be bound later. Binding conflicts remain blocked by the existing WHMCS service.

`AuthController` displays the OIDC action only when configuration is complete. Existing password registration, password login, password reset, and email verification remain available.

## Configuration

Environment variables configure enablement, provider label, issuer/discovery URLs, client credentials, callback URI, scopes, token authentication, PKCE, allowed algorithms, clock skew, and the verified-email requirement. Secrets remain environment-only and are never committed.

## Security Rules

- OIDC email synchronization requires `email_verified=true` by default.
- Only HTTPS provider endpoints are accepted.
- Only `RS256` is accepted for the WHMCS provider.
- State is single-use and nonce is mandatory.
- Return redirects must be local absolute paths and cannot begin with `//`.
- Existing admins can never be claimed through matching email.
- Existing WHMCS bindings are never silently switched to a different client.
- Logs and UI errors do not expose tokens, authorization codes, client secrets, or raw provider responses.

## Verification

Focused tests cover authorization parameters, one-time state, JWT validation failures, verified-email enforcement, JIT account creation, existing-account linking, email synchronization, collision rejection, route wiring, login-page rendering, and WHMCS binding invocation. PHP lint, migration checks, existing auth regressions, and the broader master PHP regression suite run before completion.
