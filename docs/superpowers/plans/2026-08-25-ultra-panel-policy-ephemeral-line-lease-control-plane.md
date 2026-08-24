# Ultra Panel Policy and Ephemeral Line Lease Control Plane Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Make the Panel issue policy-only grants and one-time provider leases without exposing persistent line material to Clients.

**Architecture:** `UltraMode` defines the v2 contract. `LeigodTokenBroker` creates a short-lived policy grant with no line data; `UltraLeaseService` validates the grant and selects one approved provider material record server-side for a one-time lease response. Lease audit rows contain hashes and policy metadata only, never provider IPs or credentials.

**Tech Stack:** PHP 8.3, MySQL, existing `Database`, `AppSettings`, `Auth`, `Request`, `Response`, standalone PHP contract tests.

---

## File Map

Create:

- `master/migrations/20260825_add_ultra_policy_leases.sql` — non-secret lease audit and policy-generation state.
- `master/src/Support/UltraLeaseService.php` — lease validation, one-time nonce consumption, candidate selection, and response scrubbing.
- `master/tests/ultra_policy_lease_contract_test.php` — source and response contract assertions.
- `master/tests/ultra_lease_endpoint_test.php` — authenticated endpoint behavior with fake database/material fixtures.

Modify:

- `master/src/Support/UltraMode.php` — v2 protocol constants and bounded TTL/quota defaults.
- `master/src/Support/LeigodTokenBroker.php` — policy-only grant fields and generation calculation.
- `master/src/Support/UltraMaterialService.php` — expose a server-only candidate resolver; remove client-facing sealed-material v2 behavior.
- `master/src/Controllers/ClientAgentController.php` — add lease endpoint and remove line-bearing v2 material response.
- `master/src/Bootstrap.php` — register `POST /api/client/ultra/lease`.
- `master/src/Support/UltraClientStatusService.php` — reject line IPs/IDs and accept opaque selection references only.
- `master/tests/leigod_token_broker_contract_test.php` — update v2 assertions while retaining explicit v1 rejection coverage.
- `master/tests/ultra_no_slave_contract_test.php` — extend the no-slave/no-gateway contract scan.

## Data Contract

The v2 policy grant is exactly the following shape; unknown keys are omitted
before serialization:

```php
$grant = [
    'protocol' => UltraMode::POLICY_PROTOCOL,
    'forward_id' => $forwardId,
    'policy_generation' => $generation,
    'session_ticket' => $ticket,
    'issued_at' => $this->formatTimestamp($now),
    'renew_after' => $this->formatTimestamp($renewAfter),
    'expires_at' => $this->formatTimestamp($expiresAt),
    'service_state' => 'active',
    'transport' => $request['transport'],
    'rate_limit_mbps' => $request['speed_limit_mbps'],
    'quota_remaining_bytes' => $quotaBytes,
    'target_digest' => hash('sha256', $request['target']),
];
```

The v2 lease response is one candidate only:

```php
[
    'forward_id' => $forwardId,
    'lease_id' => $opaqueLeaseId,
    'policy_generation' => $generation,
    'expires_at' => $leaseExpiresAt,
    'endpoint' => ['ip' => $providerIp, 'port' => $providerPort],
    'credential' => ['user' => $user, 'password' => $credential],
]
```

The response is returned only over the authenticated HTTPS request and is never
written to an application setting, database lease row, Client config, or status
payload. The client endpoint is allowed to see the candidate because direct
Client-to-provider mode necessarily requires it at runtime; persistence is the
security requirement.

## Task 1: Add Non-Secret Lease State

**Files:**

- Create: `master/migrations/20260825_add_ultra_policy_leases.sql`
- Test: `master/tests/ultra_operations_schema_test.php`

- [ ] **Step 1: Write the migration contract test.** Add assertions for a table
  named `ultra_client_leases` with `client_id`, `device_hash`, `forward_id`,
  `policy_generation`, `nonce_hash`, `lease_hash`, `quota_bytes`, `issued_at`,
  `expires_at`, `consumed_at`, `revoked_at`, and no columns named `ip`, `port`,
  `user`, `password`, `credential`, `line_id`, or `material`.

```php
$source = file_get_contents(__DIR__ . '/../migrations/20260825_add_ultra_policy_leases.sql');
foreach (['ultra_client_leases', 'policy_generation', 'nonce_hash', 'quota_bytes'] as $needle) {
    assert(str_contains($source, $needle), "missing lease schema field: {$needle}");
}
foreach (['credential', 'password', 'line_id', 'material'] as $forbidden) {
    assert(!preg_match('/\\b' . preg_quote($forbidden, '/') . '\\b/i', $source), "secret field leaked: {$forbidden}");
}
```

- [ ] **Step 2: Run the focused test and verify it fails.**

```powershell
php master/tests/ultra_operations_schema_test.php
```

Expected: `missing lease schema field: ultra_client_leases`.

- [ ] **Step 3: Add the idempotent schema.** Use a `CREATE TABLE IF NOT EXISTS`
  migration with a unique key on `(client_id, device_hash, forward_id,
  nonce_hash)` and indexes on `(forward_id, expires_at)` and
  `(policy_generation, revoked_at)`.

```sql
CREATE TABLE IF NOT EXISTS ultra_client_leases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_hash CHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    policy_generation CHAR(64) NOT NULL,
    nonce_hash CHAR(64) NOT NULL,
    lease_hash CHAR(64) NOT NULL,
    quota_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    issued_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_ultra_lease_nonce (client_id, device_hash, forward_id, nonce_hash),
    INDEX idx_ultra_lease_expiry (forward_id, expires_at),
    INDEX idx_ultra_lease_generation (policy_generation, revoked_at)
);
```

- [ ] **Step 4: Re-run the schema tests.**

```powershell
php master/tests/ultra_operations_schema_test.php
```

Expected: PASS, including the no-secret-column assertions.

- [ ] **Step 5: Commit the isolated schema.**

```powershell
git add master/migrations/20260825_add_ultra_policy_leases.sql master/tests/ultra_operations_schema_test.php
git commit -m "feat: add non-secret Ultra lease audit state"
```

## Task 2: Define the v2 Policy Protocol

**Files:**

- Modify: `master/src/Support/UltraMode.php`
- Modify: `master/src/Support/LeigodTokenBroker.php`
- Test: `master/tests/leigod_token_broker_contract_test.php`

- [ ] **Step 1: Add failing v2 assertions.** Assert that a usable grant has
  `leikwan-ultra-policy-v2`, `session_ticket`, `policy_generation`,
  `quota_remaining_bytes`, and `target_digest`, and does not have `line_ips`,
  `gateway_ips`, `sealed_material`, `user`, `credential`, `line_id`, or
  `relay_port`.

```php
assert(($grant['protocol'] ?? '') === 'leikwan-ultra-policy-v2');
foreach (['line_ips', 'gateway_ips', 'sealed_material', 'user', 'credential', 'line_id', 'relay_port'] as $key) {
    assert(!array_key_exists($key, $grant), "v2 grant leaked {$key}");
}
```

- [ ] **Step 2: Run the contract test and verify it fails.**

```powershell
php master/tests/leigod_token_broker_contract_test.php
```

Expected: FAIL because the current grant protocol is v1 and includes line IPs.

- [ ] **Step 3: Add constants and a bounded generation helper.** Define in
  `UltraMode`:

```php
public const POLICY_PROTOCOL = 'leikwan-ultra-policy-v2';
public const LEASE_ENDPOINT = '/api/client/ultra/lease';
public const MAX_POLICY_TTL_SECONDS = 300;
public const MIN_POLICY_TTL_SECONDS = 60;
public const MAX_QUOTA_LEASE_BYTES = 107374182400;
```

Compute `policy_generation` from forward ID, service state, target digest,
transport, rate limit, quota epoch, and material fingerprint. Do not include
provider IP or credential in the grant hash.

- [ ] **Step 4: Change `LeigodTokenBroker::grantsForClientForwards()`.** Keep
  current eligibility and material freshness checks, but return only the v2
  policy fields. Clamp TTL to 60-300 seconds, clamp quota to
  `MAX_QUOTA_LEASE_BYTES`, and return no grant when service status is not
  `active`, the forward is disabled, or no approved line remains.

- [ ] **Step 5: Run the broker tests.**

```powershell
php master/tests/leigod_token_broker_contract_test.php
```

Expected: PASS for v2 and explicit PASS for legacy v1 fields being rejected.

- [ ] **Step 6: Commit the policy protocol.**

```powershell
git add master/src/Support/UltraMode.php master/src/Support/LeigodTokenBroker.php master/tests/leigod_token_broker_contract_test.php
git commit -m "feat: make Ultra grants policy-only"
```

## Task 3: Implement Server-Side One-Time Lease Resolution

**Files:**

- Create: `master/src/Support/UltraLeaseService.php`
- Modify: `master/src/Support/UltraMaterialService.php`
- Test: `master/tests/ultra_policy_lease_contract_test.php`

- [ ] **Step 1: Write unit-style contract tests for `UltraLeaseService`.** Cover
  these exact cases:

```php
assert($service->issueLease($validRequest)['ok'] === true);
assert($service->issueLease($sameNonce)['error'] === 'lease_replayed');
assert($service->issueLease($wrongDevice)['error'] === 'lease_scope_invalid');
assert($service->issueLease($oldGeneration)['error'] === 'policy_generation_invalid');
assert($service->issueLease($inactiveForward)['error'] === 'forward_inactive');
assert($service->issueLease($expiredPolicy)['error'] === 'policy_expired');
```

Assert the serialized lease response has no `line_id`, `line_ips`, `relay_ip`,
`material`, `sealed_material`, or `password` field outside the short-lived
credential object, and assert the database row contains only hashes and policy
metadata.

- [ ] **Step 2: Run the test and verify it fails.**

```powershell
php master/tests/ultra_policy_lease_contract_test.php
```

Expected: FAIL because `UltraLeaseService` does not exist.

- [ ] **Step 3: Add the service interface.** Use explicit array contracts so the
  controller cannot accidentally return the full material snapshot:

```php
final class UltraLeaseService
{
    /** @param array<string,mixed> $request @return array<string,mixed> */
    public function issueLease(array $request, int $clientId, string $deviceId): array;

    /** @return array<string,mixed> */
    public function revokeGeneration(int $forwardId, string $generation): array;
}
```

- [ ] **Step 4: Validate the request before loading material.** Require positive
  `forward_id`, a non-empty ticket, device ID, policy generation, nonce,
  target digest, and a transport in `tcp`, `udp`, or `tcp+udp`. Verify the
  authenticated client/device owns the active forward and that the current
  Panel-generated generation matches the request.

- [ ] **Step 5: Consume the nonce atomically.** Hash the nonce with SHA-256,
  insert the audit row using the unique key, and return `lease_replayed` on a
  duplicate. Set `consumed_at` only after selecting one approved candidate.

- [ ] **Step 6: Select one candidate server-side.** Reuse the existing
  `UltraMaterialService::loadMaterial()` and approved line membership, but
  return only one normalized `{ip, port, user, credential}` candidate. Do not
  return the full line list, line ID, relay metadata, or provider material
  generation to the Client.

- [ ] **Step 7: Add explicit response scrubbing.** Before returning from the
  service, recursively reject keys matching `line`, `relay`, `material`,
  `password`, `credential`, or `user` except the documented
  `credential.user/password` pair inside the one-time lease response.

- [ ] **Step 8: Re-run the lease tests.**

```powershell
php master/tests/ultra_policy_lease_contract_test.php
```

Expected: PASS for issuance, replay, scope, generation, expiry, and scrubbing.

- [ ] **Step 9: Commit the server-side lease service.**

```powershell
git add master/src/Support/UltraLeaseService.php master/src/Support/UltraMaterialService.php master/tests/ultra_policy_lease_contract_test.php
git commit -m "feat: issue one-time Ultra provider leases"
```

## Task 4: Add the Authenticated Lease Endpoint

**Files:**

- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/src/Bootstrap.php`
- Test: `master/tests/ultra_lease_endpoint_test.php`

- [ ] **Step 1: Add endpoint tests.** Exercise `POST /api/client/ultra/lease`
  with the existing `Auth::requireClientAgent()` and device-session fixture.
  Assert `401` for missing agent auth, `409` for inactive/expired policy,
  `409` for replay, and `200` with exactly one lease candidate for a valid
  request.

```php
$response = $bootstrap->handle(Request::json('POST', '/api/client/ultra/lease', $payload, $headers));
assert($response->statusCode() === 200);
$body = json_decode($response->body(), true);
assert(count($body['leases'] ?? []) === 1);
```

- [ ] **Step 2: Run the endpoint test and verify it fails.**

```powershell
php master/tests/ultra_lease_endpoint_test.php
```

Expected: FAIL with route-not-found.

- [ ] **Step 3: Inject `UltraLeaseService` into `ClientAgentController`.** Keep
  `resolveUltraMaterials()` available only for an explicitly unsupported v1
  compatibility response; it must not return v2 material.

- [ ] **Step 4: Implement `resolveUltraLease()`.** Authenticate the Client,
  enforce the device session, validate a bounded request list of at most four
  forwards, call `UltraLeaseService::issueLease()`, and return `Cache-Control:
  no-store, no-cache, must-revalidate` and `Pragma: no-cache`.

- [ ] **Step 5: Register the route.** Add exactly:

```php
$this->router->add('POST', '/api/client/ultra/lease', [$clientAgent, 'resolveUltraLease']);
```

- [ ] **Step 6: Reject v1 material for upgraded Clients.** If a request uses
  the old `/api/client/ultra/materials` route with a v2 policy, return `410`
  with `ultra_material_v1_retired`; never return the old sealed payload.

- [ ] **Step 7: Run endpoint and source contracts.**

```powershell
php master/tests/ultra_lease_endpoint_test.php
php master/tests/leigod_token_broker_contract_test.php
php master/tests/ultra_no_slave_contract_test.php
```

Expected: PASS, and the no-slave scan must still find no `gateway`, `18443`,
slave, or sidecar addition in the Ultra route/runtime contract.

- [ ] **Step 8: Commit the endpoint.**

```powershell
git add master/src/Controllers/ClientAgentController.php master/src/Bootstrap.php master/tests/ultra_lease_endpoint_test.php master/tests/ultra_no_slave_contract_test.php
git commit -m "feat: add authenticated Ultra lease endpoint"
```

## Task 5: Make Status and Revocation Credential-Free

**Files:**

- Modify: `master/src/Support/UltraClientStatusService.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Test: `master/tests/ultra_client_status_contract_test.php`

- [ ] **Step 1: Add failing status tests.** Send a status containing `relay_ip`,
  `line_id`, `line_ips`, `user`, and `credential`; assert the normalized stored
  JSON omits them. Send an opaque `selection_ref` and assert it is retained.

- [ ] **Step 2: Run the test and verify it fails.**

```powershell
php master/tests/ultra_client_status_contract_test.php
```

Expected: FAIL because the current status normalizer retains approved relay IPs
and maps them to line labels.

- [ ] **Step 3: Change status normalization.** Replace the line/IP mapping with
  `selection_ref`, `rtt_ms`, `selected_at`, and bounded transport state. Keep
  admin line labels derived from current Panel assignment only when the Client
  sends an opaque reference that Panel issued; never trust a Client-supplied
  IP or line ID.

- [ ] **Step 4: Add a source-level forbidden-key scan.** Assert the status
  serializer cannot emit `relay_ip`, `line_id`, `credential`, or `password`.

- [ ] **Step 5: Run all focused Panel tests.**

```powershell
php master/tests/ultra_client_status_contract_test.php
php master/tests/ultra_material_state_test.php
php master/tests/ultra_inventory_contract_test.php
```

Expected: PASS with no secret or line-bearing status output.

- [ ] **Step 6: Commit the credential-free status contract.**

```powershell
git add master/src/Support/UltraClientStatusService.php master/src/Controllers/ClientAgentController.php master/tests/ultra_client_status_contract_test.php
git commit -m "fix: make Ultra status credential-free"
```

## Control-Plane Self-Review

Before handing this plan to the client-runtime plans, verify:

- `grep`/`rg` of v2 grant serialization finds no `line_ips` or `sealed_material`.
- Lease table contains no provider fields.
- No endpoint returns a full material list to a v2 Client.
- Replay, stale generation, inactive service, quota-zero, and expired policy
  all fail closed.
- No Ultra path references slave, sidecar, independent gateway, or `18443`.
- Existing source-agent material upload remains outbound-only and unchanged in
  topology.
