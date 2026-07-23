# LeiGod-Only Retirement and Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add durable LeiGod-only legacy Forward retirement and authenticated Forward-scoped cleanup for client, slave, and sidecar agents without deleting historical data or touching active LeiGod runtime.

**Architecture:** Add a permanent master-side retirement ledger with immutable client eligibility scopes and concrete role/device targets, then deliver leased cleanup attempts through existing authenticated client, node, and sidecar control channels. A focused `ForwardRetirementService` owns activation, target leasing, result fencing, aggregate recomputation, late-device materialization, reappearance, endpoint-destroy convergence, operations projections, and compaction. Each agent adds one `retire_forward_runtime(forward_id, ownership_scope, active_keep_set)` entry point that deletes only exact Forward-owned resources after subtracting the active keep-set; missing retirement data is always a no-op.

**Tech Stack:** PHP 8 strict types, existing `Config`/`Database`/`ApiException`/controller patterns, MySQL-compatible SQL migrations with SQLite-safe syntax, standalone PHP regression scripts, Python 3 agent code and pytest/unittest cleanup fixtures.

---

## Scope and hard guardrails

This plan implements storage, server delivery, agent teardown contracts, and tests for legacy runtime retirement only. It does **not** implement bond scheduler changes, Linux supervisor/port-lease hardening for active Speed Plus, telemetry gates, rollout activation, artifact publication, remote host operations, production deployment, commits, or pushes. Those remain in the later Bond Data Plane, Linux Supervisor, Telemetry, and Activation/Rollout plans.

Protected unrelated file:

```text
docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md
```

Do not modify, stage, commit, delete, execute, or use that file as authorization for this changeset.

Runtime safety invariants for every task:

```text
cleanup must never become a fleet-wide WireGuard or systemd purge
missing desired-state or retirement sections never authorize deletion
cleanup may delete only resources with provable exact retired Forward ownership
active LeiGod keep-set references always win over retired ownership evidence
ambiguous ownership remains on disk and returns partial
remote cleanup never runs inside a master database transaction
LeiGod credentials are never exposed, persisted in snapshots, rotated, or redacted by logging them
```

The active product contract from the control-plane plan remains:

```text
backend_mode = leigod_mode
client_mode = bond
runtime_preference = single
bond_ingress_mode = plain_tcp_mux
```

---

## File structure

### New master files

- `master/migrations/20260718_add_forward_runtime_retirements.sql` — creates the retirement ledger tables and extends `client_device_sessions` with a server-issued session credential digest.
- `master/src/Support/ClientDeviceSessionContext.php` — immutable DTO returned by the client-device session service.
- `master/src/Support/ForwardRetirementService.php` — single server-side service for tombstones, targets, leases, results, reappearance, operations projections, and compaction.
- `master/cli/activate-forward-retirements.php` — idempotent CLI that creates legacy Forward tombstones and disables legacy Forward publication; not a deployment command.
- `master/cli/compact-forward-retirements.php` — idempotent CLI that compacts old completed result payloads without deleting tombstones or receipts.
- `master/tests/retirement_migration_shape_test.php` — migration/schema contract test.
- `master/tests/client_device_session_credential_test.php` — client installation identity and credential test.
- `master/tests/forward_retirement_service_test.php` — service transaction, lease, result, reappearance, aggregate, and compaction test.
- `master/tests/forward_retirement_controller_test.php` — role-authenticated delivery/result route test.
- `master/tests/forward_retirement_cli_shape_test.php` — activation/compaction CLI wiring and protected-file staging test.

### Modified master files

- `master/migrations/schema.sql` — mirror the new table definitions and client session columns.
- `master/src/Support/Auth.php` — authenticate the logical client without mutating device-session state; controller code obtains one authoritative session context.
- `master/src/Support/ClientOnlineSessionService.php` — issue/verify/rotate per-installation session credentials and return `ClientDeviceSessionContext`.
- `master/src/Bootstrap.php` — instantiate `ForwardRetirementService`, inject it into controllers, and register role-specific retirement result routes plus operations read routes.
- `master/src/Controllers/AdminController.php` — add read-only retirement operations page/API; no edit/publish/reactivate controls.
- `master/src/Controllers/ClientAgentController.php` — use one session context per request, materialize late client targets, return leased `runtime_retirements`, and accept client result/renew/reappearance calls.
- `master/src/Controllers/NodeController.php` — include slave retirement leases in authenticated node management and accept slave result/renew/reappearance calls.
- `master/src/Controllers/SidecarAgentController.php` — include sidecar retirement leases in authenticated sidecar management and accept sidecar result/renew/reappearance calls.

### Modified agent files

- `client-agent/client_agent.py` — persist device-session credential, parse explicit `runtime_retirements`, build active keep-set, run exact client cleanup, post bounded results, keep completion watermarks.
- `slave-agent/slave_agent.py` — parse management `runtime_retirements`, build active keep-set, run exact Forward-level slave cleanup using metadata, post bounded results.
- `sidecar-agent/sidecar_agent.py` — parse management `runtime_retirements`, build active keep-set, run exact sidecar cleanup, preserve point-shared bond server and active static uplinks.

### New agent tests

- `client-agent/tests/test_forward_retirement_cleanup.py`
- `slave-agent/tests/test_forward_retirement_cleanup.py`
- `sidecar-agent/tests/test_forward_retirement_cleanup.py`

Artifact mirrors under `artifacts/` and `master/public/artifacts/` are **not** modified by this plan. Artifact synchronization belongs to the activation/rollout plan and still requires separate deployment authorization.

---

### Task 1: Add retirement ledger migration and schema mirror

**Files:**
- Create: `master/migrations/20260718_add_forward_runtime_retirements.sql`
- Modify: `master/migrations/schema.sql`
- Create: `master/tests/retirement_migration_shape_test.php`

- [ ] **Step 1: Write the failing migration shape test**

Create `master/tests/retirement_migration_shape_test.php` with the standalone PHP test pattern used by existing migration shape tests. The test reads `master/migrations/20260718_add_forward_runtime_retirements.sql` and `master/migrations/schema.sql`, then asserts every required table, column, index, and safety guard string exists.

Use these required snippets for the migration file:

```php
$requiredMigrationSnippets = [
    'CREATE TABLE IF NOT EXISTS forward_runtime_retirements',
    'forward_id BIGINT UNSIGNED NOT NULL',
    'service_id BIGINT UNSIGNED NOT NULL',
    'backend_mode VARCHAR(32) NOT NULL',
    'combination_backend_mode VARCHAR(32) NULL',
    'ownership_snapshot_json JSON NOT NULL',
    'ownership_snapshot_bytes INT NOT NULL DEFAULT 0',
    'UNIQUE INDEX uniq_forward_runtime_retirements_forward',
    'CREATE TABLE IF NOT EXISTS forward_runtime_retirement_client_scopes',
    'match_source VARCHAR(32) NOT NULL',
    'UNIQUE INDEX uniq_forward_retirement_scope_client',
    'CREATE TABLE IF NOT EXISTS forward_runtime_retirement_targets',
    'role VARCHAR(16) NOT NULL',
    'target_key VARCHAR(191) NOT NULL',
    'device_session_id BIGINT UNSIGNED NULL',
    'reconciliation_generation INT NOT NULL DEFAULT 1',
    'last_attempt_seq BIGINT UNSIGNED NOT NULL DEFAULT 0',
    'last_attempt_id CHAR(64) NULL',
    'compact_receipt_json JSON NULL',
    'UNIQUE INDEX uniq_forward_retirement_target',
    'ALTER TABLE client_device_sessions ADD COLUMN IF NOT EXISTS session_secret_digest CHAR(64) NULL',
    'ALTER TABLE client_device_sessions ADD COLUMN IF NOT EXISTS session_secret_revoked_at DATETIME NULL',
];
```

Use these required snippets for `schema.sql`:

```php
$requiredSchemaSnippets = [
    'CREATE TABLE IF NOT EXISTS forward_runtime_retirements',
    'CREATE TABLE IF NOT EXISTS forward_runtime_retirement_client_scopes',
    'CREATE TABLE IF NOT EXISTS forward_runtime_retirement_targets',
    'session_secret_digest CHAR(64) NULL',
    'session_secret_issued_at DATETIME NULL',
    'session_secret_last_seen_at DATETIME NULL',
];
```

Assert these destructive snippets are absent from the migration:

```php
$forbiddenSnippets = [
    'DROP TABLE',
    'TRUNCATE',
    'DELETE FROM forwards',
    'DELETE FROM forward_combinations',
    'UPDATE forwards SET backend_mode',
    'UPDATE forward_combinations SET backend_mode',
    'docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md',
];
```

Also assert that the migration contains no `AFTER ` clause, because SQLite normalization in `MigrationRunner` does not strip MySQL column-order syntax.

End the test with `echo "ok\n";`.

- [ ] **Step 2: Run the migration shape test and verify it fails**

Run:

```bash
php master/tests/retirement_migration_shape_test.php
```

Expected: FAIL because `20260718_add_forward_runtime_retirements.sql` does not exist.

- [ ] **Step 3: Create the migration**

Create `master/migrations/20260718_add_forward_runtime_retirements.sql` with this SQL. It must not update historical backend values, delete historical rows, or queue remote work.

```sql
CREATE TABLE IF NOT EXISTS forward_runtime_retirements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_id BIGINT UNSIGNED NOT NULL,
    service_id BIGINT UNSIGNED NOT NULL,
    forward_combination_id BIGINT UNSIGNED NULL,
    active_combination_id BIGINT UNSIGNED NULL,
    forward_route_id BIGINT UNSIGNED NULL,
    backend_mode VARCHAR(32) NOT NULL,
    combination_backend_mode VARCHAR(32) NULL,
    reason VARCHAR(64) NOT NULL DEFAULT 'backend_mode_deprecated',
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    ownership_snapshot_json JSON NOT NULL,
    ownership_snapshot_sha256 CHAR(64) NOT NULL,
    ownership_snapshot_bytes INT NOT NULL DEFAULT 0,
    target_counts_json JSON NULL,
    last_discovery_at DATETIME NULL,
    retired_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    compacted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forward_runtime_retirements_forward FOREIGN KEY (forward_id) REFERENCES forwards(id),
    CONSTRAINT fk_forward_runtime_retirements_service FOREIGN KEY (service_id) REFERENCES services(id)
);

CREATE UNIQUE INDEX uniq_forward_runtime_retirements_forward
    ON forward_runtime_retirements (forward_id);
CREATE INDEX idx_forward_runtime_retirements_status
    ON forward_runtime_retirements (status, updated_at);
CREATE INDEX idx_forward_runtime_retirements_service
    ON forward_runtime_retirements (service_id, status);

CREATE TABLE IF NOT EXISTS forward_runtime_retirement_client_scopes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    retirement_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NOT NULL,
    match_source VARCHAR(32) NOT NULL,
    match_evidence_json JSON NULL,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forward_retirement_scopes_retirement FOREIGN KEY (retirement_id) REFERENCES forward_runtime_retirements(id) ON DELETE CASCADE,
    CONSTRAINT fk_forward_retirement_scopes_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX uniq_forward_retirement_scope_client
    ON forward_runtime_retirement_client_scopes (retirement_id, client_id);
CREATE INDEX idx_forward_retirement_scopes_client
    ON forward_runtime_retirement_client_scopes (client_id, revoked_at);

CREATE TABLE IF NOT EXISTS forward_runtime_retirement_targets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    retirement_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(16) NOT NULL,
    target_id BIGINT UNSIGNED NOT NULL,
    target_key VARCHAR(191) NOT NULL,
    device_session_id BIGINT UNSIGNED NULL,
    reconciliation_generation INT NOT NULL DEFAULT 1,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    last_attempt_outcome VARCHAR(32) NULL,
    last_attempt_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_attempt_id CHAR(64) NULL,
    attempt_count INT NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NULL,
    lease_until DATETIME NULL,
    lease_started_at DATETIME NULL,
    last_error TEXT NULL,
    result_json JSON NULL,
    compact_receipt_json JSON NULL,
    first_seen_at DATETIME NOT NULL,
    last_attempt_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_forward_retirement_targets_retirement FOREIGN KEY (retirement_id) REFERENCES forward_runtime_retirements(id) ON DELETE CASCADE,
    CONSTRAINT fk_forward_retirement_targets_device_session FOREIGN KEY (device_session_id) REFERENCES client_device_sessions(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX uniq_forward_retirement_target
    ON forward_runtime_retirement_targets (retirement_id, role, target_key);
CREATE INDEX idx_forward_retirement_targets_due
    ON forward_runtime_retirement_targets (role, status, next_attempt_at, lease_until);
CREATE INDEX idx_forward_retirement_targets_identity
    ON forward_runtime_retirement_targets (role, target_id, device_session_id);
CREATE INDEX idx_forward_retirement_targets_attempt
    ON forward_runtime_retirement_targets (last_attempt_id);
CREATE INDEX idx_forward_retirement_targets_completed
    ON forward_runtime_retirement_targets (status, completed_at, compact_receipt_json(32));

ALTER TABLE client_device_sessions
    ADD COLUMN IF NOT EXISTS session_secret_digest CHAR(64) NULL;
ALTER TABLE client_device_sessions
    ADD COLUMN IF NOT EXISTS session_secret_issued_at DATETIME NULL;
ALTER TABLE client_device_sessions
    ADD COLUMN IF NOT EXISTS session_secret_rotated_at DATETIME NULL;
ALTER TABLE client_device_sessions
    ADD COLUMN IF NOT EXISTS session_secret_revoked_at DATETIME NULL;
ALTER TABLE client_device_sessions
    ADD COLUMN IF NOT EXISTS session_secret_last_seen_at DATETIME NULL;
ALTER TABLE client_device_sessions
    ADD COLUMN IF NOT EXISTS retirement_capability_version VARCHAR(32) NULL;
ALTER TABLE client_device_sessions
    ADD COLUMN IF NOT EXISTS session_capabilities_json JSON NULL;

CREATE INDEX idx_client_device_sessions_secret_seen
    ON client_device_sessions (client_id, session_secret_revoked_at, session_secret_last_seen_at);
```

If the target MySQL version rejects prefix indexing on `compact_receipt_json(32)`, replace that single index with:

```sql
CREATE INDEX idx_forward_retirement_targets_completed
    ON forward_runtime_retirement_targets (status, completed_at);
```

The migration shape test must be updated to require the final selected index form exactly once.

- [ ] **Step 4: Mirror the schema in `schema.sql`**

Modify `master/migrations/schema.sql` so a fresh install contains the same three tables, indexes, and `client_device_sessions` columns. Keep the existing `client_device_sessions` `UNIQUE KEY uniq_client_device (client_id, device_id)` unchanged for diagnostic compatibility; do not make `device_id` authoritative for retirement acknowledgement.

The `forward_runtime_retirement_targets` schema must use a non-null `target_key` rather than nullable uniqueness. Use these target keys in server code and tests:

```text
client-device:<client_device_sessions.id>
slave-node:<nodes.id>
sidecar-point:<sidecar_points.id>
```

- [ ] **Step 5: Run the migration shape test**

Run:

```bash
php master/tests/retirement_migration_shape_test.php
```

Expected: PASS and print `ok`.

- [ ] **Step 6: Commit the migration and schema plan task**

Do not stage the protected Bridge Lite plan. Commit only these files for this task:

```bash
git add master/migrations/20260718_add_forward_runtime_retirements.sql \
  master/migrations/schema.sql \
  master/tests/retirement_migration_shape_test.php
git commit -m $'feat(master): add forward retirement ledger schema\n\nCo-Authored-By: Claude Fable 5 <noreply@anthropic.com>\nCo-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>'
```

---

### Task 2: Harden client installation identity and session credential flow

**Files:**
- Create: `master/src/Support/ClientDeviceSessionContext.php`
- Modify: `master/src/Support/Auth.php`
- Modify: `master/src/Support/ClientOnlineSessionService.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Create: `master/tests/client_device_session_credential_test.php`

- [ ] **Step 1: Write the failing credential test**

Create `master/tests/client_device_session_credential_test.php`. Build an in-memory SQLite database through the existing test bootstrap/migration pattern, insert one active client, and exercise the public API that this task will introduce.

The test must assert these exact behaviors:

1. `Auth::requireClientAgent()` authenticates the logical client but does not insert or update `client_device_sessions`.
2. `ClientOnlineSessionService::contextFromRequest($client, $request, 'pull')` creates or touches exactly one session row and returns `ClientDeviceSessionContext` with a positive `deviceSessionId()`.
3. A first pull without credential headers returns a non-empty `issuedSecret()` and stores only `session_secret_digest`; plaintext does not appear in the database.
4. A second pull with headers `X-Leikwan-Device-Session-Id` and `X-Leikwan-Device-Session-Secret` returns the same session ID, `credentialVerified() === true`, and no new secret.
5. A result-style credential check with only the shared client token throws `client_device_session_auth_required`.
6. A result-style credential check with a forged secret throws `client_device_session_auth_failed` and does not rotate the digest.
7. A result-style credential check with a valid secret returns the same context and updates `session_secret_last_seen_at`.
8. A revoked session secret throws `client_device_session_revoked`.
9. A caller-chosen `device_id` collision never authorizes acknowledgement for another installation because result-style checks key by positive `client_device_sessions.id` plus secret digest, not by `device_id` text.
10. `ClientAgentController::pullConfigs()` calls session-touch code once; the count of rows remains one and the `last_pull_at` timestamp changes once.

End with `echo "ok\n";`.

- [ ] **Step 2: Run the credential test and verify the missing-class failure**

Run:

```bash
php master/tests/client_device_session_credential_test.php
```

Expected: FAIL because `ClientDeviceSessionContext` and the new session methods do not exist.

- [ ] **Step 3: Add `ClientDeviceSessionContext`**

Create `master/src/Support/ClientDeviceSessionContext.php`:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class ClientDeviceSessionContext
{
    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed> $session
     * @param array<string, mixed> $capabilities
     */
    public function __construct(
        private array $client,
        private array $session,
        private string $diagnosticDeviceId,
        private bool $credentialVerified,
        private ?string $issuedSecret,
        private array $capabilities,
    ) {}

    /** @return array<string, mixed> */
    public function client(): array
    {
        return $this->client;
    }

    /** @return array<string, mixed> */
    public function session(): array
    {
        return $this->session;
    }

    public function clientId(): int
    {
        return (int) ($this->client['id'] ?? 0);
    }

    public function deviceSessionId(): int
    {
        return (int) ($this->session['id'] ?? 0);
    }

    public function diagnosticDeviceId(): string
    {
        return $this->diagnosticDeviceId;
    }

    public function credentialVerified(): bool
    {
        return $this->credentialVerified;
    }

    public function issuedSecret(): ?string
    {
        return $this->issuedSecret;
    }

    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    /** @return array<string, mixed> */
    public function responsePayload(): array
    {
        $payload = [
            'id' => $this->deviceSessionId(),
            'diagnostic_device_id' => $this->diagnosticDeviceId,
            'credential_verified' => $this->credentialVerified,
            'credential_status' => $this->issuedSecret !== null
                ? 'issued'
                : ($this->credentialVerified ? 'verified' : 'not_present'),
        ];

        if ($this->issuedSecret !== null) {
            $payload['retirement_secret'] = $this->issuedSecret;
        }

        return $payload;
    }
}
```

- [ ] **Step 4: Stop `Auth::requireClientAgent()` from touching device sessions**

Modify `master/src/Support/Auth.php` so `requireClientAgent()` only validates the bearer/body token and returns the client row. Remove the call to `ClientOnlineSessionService::enforceFromRequest()` from this method. Keep the stable error:

```php
throw new ApiException('client_agent_auth_failed', 401);
```

Every controller method that needs an installation identity must call `ClientOnlineSessionService` explicitly once.

- [ ] **Step 5: Add credential-aware session context methods**

Modify `master/src/Support/ClientOnlineSessionService.php` with these new public methods while preserving existing `deviceIdFromRequest()`, `deviceMetadataFromRequest()`, `recordAndEnforce()`, `activeCountForClient()`, and `activeSessionForClientAndDevice()` for compatibility:

```php
public function contextFromRequest(
    array $client,
    Request $request,
    string $touchType = 'pull'
): ClientDeviceSessionContext
```

```php
public function requireCredentialContext(
    array $client,
    Request $request,
    string $touchType = 'heartbeat'
): ClientDeviceSessionContext
```

`contextFromRequest()` behavior:

```text
1. Derive diagnostic device_id using existing deviceIdFromRequest().
2. If X-Leikwan-Device-Session-Id names a positive session owned by the client, prefer that row.
3. Else use the existing (client_id, device_id) row or create it.
4. Touch last_seen_at and the requested pull/heartbeat column exactly once.
5. Merge bounded capability fields from request body into session_capabilities_json:
   retirement_schema, agent_version, artifact_sha256, platform, bond_snapshot_contract.
6. If the request includes a matching X-Leikwan-Device-Session-Secret and the row is not revoked, set credentialVerified=true.
7. If the row has no digest, or the caller omitted the secret, issue a new 32-byte base64url secret, store hash('sha256', secret), set issued/rotated timestamps, and return plaintext only in ClientDeviceSessionContext::issuedSecret().
8. Enforce the existing online-device limit by positive session rows; do not count caller-supplied device_id text as authority.
```

`requireCredentialContext()` behavior:

```text
1. Require both X-Leikwan-Device-Session-Id and X-Leikwan-Device-Session-Secret.
2. Select the session by positive id and authenticated client_id.
3. Reject missing row with client_device_session_auth_required, HTTP 401.
4. Reject revoked rows with client_device_session_revoked, HTTP 403.
5. Compare hash('sha256', suppliedSecret) with session_secret_digest using hash_equals().
6. Reject mismatch with client_device_session_auth_failed, HTTP 401.
7. Touch last_seen_at, session_secret_last_seen_at, and the requested heartbeat/pull timestamp.
8. Return a context with credentialVerified=true and issuedSecret=null.
```

Use this secret generator:

```php
private function newSessionSecret(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}
```

- [ ] **Step 6: Change client controller pull and heartbeat to use one context**

In `master/src/Controllers/ClientAgentController.php`, change `pullConfigs()` from duplicate session touching to this flow:

```php
$client = Auth::requireClientAgent($request, $this->db);
$deviceSessionService = new ClientOnlineSessionService($this->db);
$sessionContext = $deviceSessionService->contextFromRequest($client, $request, 'pull');
$deviceSession = $sessionContext->session();
$forwards = $this->provisioning->assignedForwardConfigsForClient(
    (int) $client['id'],
    $deviceSession
);
```

Replace the existing client payload fields:

```php
'current_device_id' => $deviceId,
```

with:

```php
'current_device_id' => $sessionContext->diagnosticDeviceId(),
'device_session' => $sessionContext->responsePayload(),
```

In `heartbeat()`, use:

```php
$client = Auth::requireClientAgent($request, $this->db);
$deviceSessionService = new ClientOnlineSessionService($this->db);
$sessionContext = $deviceSessionService->contextFromRequest($client, $request, 'heartbeat');
```

Do not call `deviceIdFromRequest()` plus `recordAndEnforce()` separately in the controller.

- [ ] **Step 7: Run credential tests**

Run:

```bash
php master/tests/client_device_session_credential_test.php
```

Expected: PASS and print `ok`.

- [ ] **Step 8: Commit client credential hardening**

```bash
git add master/src/Support/ClientDeviceSessionContext.php \
  master/src/Support/Auth.php \
  master/src/Support/ClientOnlineSessionService.php \
  master/src/Controllers/ClientAgentController.php \
  master/tests/client_device_session_credential_test.php
git commit -m $'feat(master): bind client retirement to device sessions\n\nCo-Authored-By: Claude Fable 5 <noreply@anthropic.com>\nCo-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>'
```

---

### Task 3: Implement `ForwardRetirementService` ledger, activation, leases, results, and compaction

**Files:**
- Create: `master/src/Support/ForwardRetirementService.php`
- Create: `master/tests/forward_retirement_service_test.php`

- [ ] **Step 1: Write the failing service test**

Create `master/tests/forward_retirement_service_test.php`. Use an in-memory SQLite database with minimal rows for `services`, `clients`, `client_groups`, `client_device_sessions`, `nodes`, `sidecar_points`, `forward_combinations`, `forwards`, `service_endpoints`, and `provision_tasks`.

The test must cover these exact scenarios:

1. A `current_mode` Forward with a `cf_mode` combination creates one tombstone, disables the Forward, preserves `forwards.backend_mode`, preserves `forward_combinations.backend_mode`, stores both raw modes in the tombstone/audit details, queues endpoint destroy work insert-if-absent, creates slave/sidecar concrete targets, and creates client eligibility scopes plus known device targets.
2. An already-disabled legacy Forward still creates a tombstone and targets because disabled rows may still have runtime residue.
3. A locked `leigod_mode` Forward creates no tombstone and receives no target.
4. Rerunning activation returns `already_retired=1`, creates no duplicate tombstone/scope/target, and does not reset `attempt_count`, `status`, or `reconciliation_generation`.
5. A snapshot above 262144 bytes rejects that Forward's activation with `ownership_snapshot_too_large` and leaves `enabled`, tombstone, targets, endpoint tasks, and audit state unchanged.
6. Client scope creation mirrors historical assignment rules: direct `line_client_id`, `line_client_group_id`, and JSON `line_client_group_ids_json`; it does not broadcast to unrelated clients.
7. Target keys are non-null and unique: `client-device:<sessionId>`, `slave-node:<nodeId>`, and `sidecar-point:<pointId>`.
8. `materializeClientTargets()` inserts a target for a newly seen eligible session, updates `last_discovery_at`, and reopens a completed aggregate to `in_progress`.
9. Leasing returns at most 16 targets, sets `lease_until` five minutes ahead, increments `last_attempt_seq`, issues a 64-char hex `attempt_id`, increments `attempt_count` once, and does not lease a live leased target again.
10. Renewing a current lease extends `lease_until`; renewing a stale/foreign/expired token returns a stale no-op and does not mutate the target.
11. A stale result with old generation, old sequence, expired attempt, wrong role, or wrong authenticated identity is an idempotent no-op.
12. A valid `completed` result maps to `last_attempt_outcome='success'`, `status='completed'`, clears the lease, writes bounded `result_json`, sets `completed_at`, and recomputes the parent aggregate.
13. A valid `partial` or `failed` result keeps target `pending`, writes `last_attempt_outcome`, clears the lease, sets `next_attempt_at` using `min(30s × 2^(attempt_count-1), 1h)` plus deterministic 0–20% jitter, and makes the parent aggregate `partial`.
14. Parent status precedence exactly matches: all targets completed => `completed`; any unresolved partial/failed => `partial`; any accepted attempt while unresolved targets remain => `in_progress`; otherwise `pending`, including zero concrete targets.
15. Reappearance after completion increments `reconciliation_generation` once, preserves the compact receipt, sets target `pending`, and returns a new lease only after the next leasing call; duplicate concurrent reappearance reports coalesce.
16. Endpoint destroy convergence marks encompassed endpoint-destroy work complete/absent only after Forward retirement target completion proves the role's exclusive resources are absent; it does not delete unrelated provision tasks.
17. Compaction after 30 days clears verbose `result_json`/`last_error` for completed targets and stores permanent receipt fields: retirement, role, target_key, device_session_id, completed_generation, completed_at.

End with `echo "ok\n";`.

- [ ] **Step 2: Run the service test and verify the missing-class failure**

Run:

```bash
php master/tests/forward_retirement_service_test.php
```

Expected: FAIL because `ForwardRetirementService` does not exist.

- [ ] **Step 3: Create the service with exact public interface**

Create `master/src/Support/ForwardRetirementService.php` with this public interface:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class ForwardRetirementService
{
    public const ROLE_CLIENT = 'client';
    public const ROLE_SLAVE = 'slave';
    public const ROLE_SIDECAR = 'sidecar';
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_COMPLETED = 'completed';
    public const MAX_SNAPSHOT_BYTES = 262144;
    public const MAX_RESULT_BYTES = 32768;
    public const LEASE_SECONDS = 300;
    public const MAX_LEASES_PER_CYCLE = 16;

    public function __construct(
        private Config $config,
        private Database $db,
    ) {}

    /** @return array<string, int> */
    public function activateLegacyForwards(int $limit = 0): array;

    /** @return array<string, mixed> */
    public function activateLegacyForward(int $forwardId): array;

    /** @return array<int, array<string, mixed>> */
    public function leaseTargetsForClient(
        array $client,
        ClientDeviceSessionContext $sessionContext,
        int $limit = self::MAX_LEASES_PER_CYCLE
    ): array;

    /** @return array<int, array<string, mixed>> */
    public function leaseTargetsForNode(
        array $node,
        int $limit = self::MAX_LEASES_PER_CYCLE
    ): array;

    /** @return array<int, array<string, mixed>> */
    public function leaseTargetsForSidecarPoint(
        array $point,
        int $limit = self::MAX_LEASES_PER_CYCLE
    ): array;

    /** @return array<string, mixed> */
    public function renewLease(
        string $role,
        array $authenticatedIdentity,
        int $targetId,
        int $generation,
        int $attemptSeq,
        string $attemptId
    ): array;

    /** @return array<string, mixed> */
    public function acceptResult(
        string $role,
        array $authenticatedIdentity,
        int $targetId,
        array $payload
    ): array;

    /** @return array<string, mixed> */
    public function reportReappearance(
        string $role,
        array $authenticatedIdentity,
        int $targetId,
        array $payload
    ): array;

    /** @return array<int, int> */
    public function materializeClientTargets(
        int $clientId,
        int $deviceSessionId
    ): array;

    /** @return array<string, mixed> */
    public function operationsSummary(array $filters = []): array;

    /** @return array<string, mixed> */
    public function operationsDetail(int $retirementId): array;

    /** @return array<string, int> */
    public function compactCompletedTargets(int $olderThanDays = 30, int $limit = 500): array;
}
```

- [ ] **Step 4: Implement legacy predicate and atomic activation**

Inside `activateLegacyForward()` use one `Database::transaction()` and no remote operations. The transaction order must be:

```text
1. SELECT the Forward by id with a lock suffix when DB_DRIVER is not sqlite.
2. SELECT the referenced combination by forward_combination_id with the same lock suffix.
3. Determine legacy when forward.backend_mode or combination.backend_mode is current_mode/cf_mode.
4. Return skipped active_leigod when neither side is legacy.
5. Build a redacted bounded ownership snapshot.
6. Insert or fetch forward_runtime_retirements by unique forward_id.
7. Update forwards.enabled = 0 only; preserve backend_mode, client_mode, route IDs, target, service, and combination IDs.
8. Mark active service_endpoints health/lifecycle fields deleting through existing columns only where those columns exist; insert endpoint destroy provision_tasks with insert-if-absent semantics.
9. Insert client scopes, existing client-device targets, slave targets, and sidecar targets with insert-if-absent semantics.
10. Recompute parent aggregate status.
11. Write an audit event through the existing audit mechanism when available; when no audit table exists in a test fixture, return the audit payload in the method result.
```

Use this lock helper:

```php
private function lockSuffix(): string
{
    return $this->config->get('DB_DRIVER', 'mysql') === 'sqlite'
        ? ''
        : ' FOR UPDATE';
}
```

The legacy predicate must read raw persisted values and never substitute defaults:

```php
private function isLegacyBackend(?string $value): bool
{
    return in_array($value, ['current_mode', 'cf_mode'], true);
}
```

The activation result keys must be stable:

```php
[
    'forward_id' => 65,
    'status' => 'retired|already_retired|skipped',
    'reason' => 'backend_mode_deprecated|active_leigod|forward_missing',
    'retirement_id' => 123,
    'targets_created' => 3,
    'client_scopes_created' => 1,
]
```

- [ ] **Step 5: Implement redacted ownership snapshot construction**

The snapshot must contain no credentials or token material. Use this shape:

```php
[
    'schema' => 1,
    'forward' => [
        'id' => (int) $forward['id'],
        'service_id' => (int) $forward['service_id'],
        'forward_combination_id' => $forward['forward_combination_id'] !== null ? (int) $forward['forward_combination_id'] : null,
        'active_combination_id' => $forward['active_combination_id'] !== null ? (int) $forward['active_combination_id'] : null,
        'forward_route_id' => $forward['forward_route_id'] !== null ? (int) $forward['forward_route_id'] : null,
        'backend_mode' => (string) ($forward['backend_mode'] ?? ''),
        'combination_backend_mode' => $combination !== null ? (string) ($combination['backend_mode'] ?? '') : null,
    ],
    'endpoint_ids' => [901, 902],
    'node_ids' => [11, 12],
    'sidecar_point_ids' => [4],
    'client_ids' => [7],
    'known_interfaces' => [],
    'known_units' => [],
    'managed_paths' => [],
    'provision_task_ids' => [3001],
    'endpoint_task_payloads' => [],
]
```

Snapshot builders may parse historical `provision_tasks.payload_json` and endpoint metadata for names, interfaces, and paths, but must recursively redact keys whose lower-case name contains any of:

```text
token
secret
password
credential
session
private_key
api_key
```

Bound each array to 256 items, sort numeric arrays ascending, sort string arrays lexicographically, and encode with:

```php
json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
```

If the encoded snapshot exceeds `MAX_SNAPSHOT_BYTES`, throw:

```php
throw new ApiException('ownership_snapshot_too_large', 422, [
    'forward_id' => $forwardId,
    'bytes' => $bytes,
    'max_bytes' => self::MAX_SNAPSHOT_BYTES,
]);
```

Throwing here rolls back the transaction and preserves the Forward's previous lifecycle fields.

- [ ] **Step 6: Implement scope and target materialization**

Client scopes use the same historical assignment vocabulary as `ProvisioningService::assignedForwardConfigsForClient()`:

```text
line_client_id match                  -> match_source = direct_client
line_client_group_id match            -> match_source = client_group
line_client_group_ids_json membership -> match_source = client_group_json
```

Do not create scopes for unrelated clients. A scope is immutable except for `revoked_at` and `updated_at`.

Concrete targets:

```php
private function clientTargetKey(int $deviceSessionId): string
{
    return 'client-device:' . $deviceSessionId;
}

private function slaveTargetKey(int $nodeId): string
{
    return 'slave-node:' . $nodeId;
}

private function sidecarTargetKey(int $pointId): string
{
    return 'sidecar-point:' . $pointId;
}
```

For `role=client`, set:

```text
target_id = client_id
device_session_id = client_device_sessions.id
target_key = client-device:<client_device_sessions.id>
```

For `role=slave`, set:

```text
target_id = nodes.id
device_session_id = NULL
target_key = slave-node:<nodes.id>
```

For `role=sidecar`, set:

```text
target_id = sidecar_points.id
device_session_id = NULL
target_key = sidecar-point:<sidecar_points.id>
```

`materializeClientTargets($clientId, $deviceSessionId)` must lock matching non-revoked scopes and insert missing client targets. A newly inserted client target reopens parent status from `completed` to `in_progress` and updates `last_discovery_at`.

- [ ] **Step 7: Implement leasing payload construction**

A lease is deliverable only when:

```text
target.status != completed
AND (target.lease_until IS NULL OR target.lease_until < database_now)
AND (target.next_attempt_at IS NULL OR target.next_attempt_at <= database_now)
AND role and authenticated identity match the target
```

On lease, set:

```text
last_attempt_seq = last_attempt_seq + 1
last_attempt_id = bin2hex(random_bytes(32))
attempt_count = attempt_count + 1
lease_started_at = now
lease_until = now + 5 minutes
last_attempt_at = now
status remains pending
```

The returned object must include only redacted ownership data:

```php
[
    'target_id' => (int) $target['id'],
    'target_key' => (string) $target['target_key'],
    'retirement_id' => (int) $retirement['id'],
    'forward_id' => (int) $retirement['forward_id'],
    'reason' => (string) $retirement['reason'],
    'role' => (string) $target['role'],
    'reconciliation_generation' => (int) $target['reconciliation_generation'],
    'attempt_seq' => (int) $newSeq,
    'attempt_id' => $attemptId,
    'lease_expires_at' => $leaseUntil,
    'renew_path' => '/api/client/retirements/' . $targetId . '/renew',
    'result_path' => '/api/client/retirements/' . $targetId . '/result',
    'reappearance_path' => '/api/client/retirements/' . $targetId . '/reappearance',
    'ownership_scope' => [
        'endpoint_ids' => [901, 902],
        'sidecar_point_ids' => [4],
        'known_interfaces' => [],
        'known_units' => [],
        'managed_paths' => [],
    ],
]
```

Use role-specific paths for node and sidecar:

```text
/api/node/retirements/{id}/renew
/api/node/retirements/{id}/result
/api/node/retirements/{id}/reappearance
/api/sidecar/retirements/{id}/renew
/api/sidecar/retirements/{id}/result
/api/sidecar/retirements/{id}/reappearance
```

- [ ] **Step 8: Implement renew, result, retry, and aggregate recomputation**

`renewLease()` validates current role identity, target ID, generation, attempt sequence, attempt ID, and live lease. Valid renewal extends `lease_until` to `now + 5 minutes`. Stale renewal returns:

```php
['ok' => true, 'accepted' => false, 'stale' => true]
```

`acceptResult()` accepts payloads with this exact shape:

```json
{
  "retirement_id": 123,
  "forward_id": 65,
  "reconciliation_generation": 1,
  "attempt_seq": 2,
  "attempt_id": "64_hex_chars",
  "outcome": "completed",
  "counts": {
    "stopped_units": 1,
    "disabled_units": 1,
    "deleted_interfaces": 2,
    "deleted_files": 3,
    "deleted_leases": 1,
    "removed_references": 1,
    "already_absent": 4,
    "keep_set_preserved": 2,
    "errors": 0
  },
  "resources": ["leikwan-client-65.service", "lw-mptcp-65-0"],
  "message": "exclusive resources absent"
}
```

Accepted outcomes:

```text
completed -> last_attempt_outcome = success, target.status = completed
partial   -> last_attempt_outcome = partial, target.status remains pending
failed    -> last_attempt_outcome = failed, target.status remains pending
```

Bound `result_json` to `MAX_RESULT_BYTES` by truncating `resources` to the first 50 short names and `message` to 512 bytes before rejecting. If the bounded payload is still too large, accept a synthetic `failed` result with:

```text
last_error = result_payload_too_large
result_json = {"outcome":"failed","counts":{"errors":1},"message":"result_payload_too_large"}
```

Retry delay for partial/failed:

```php
$base = min(30 * (2 ** max(0, $attemptCount - 1)), 3600);
$jitterPercent = hexdec(substr(hash('sha256', $target['target_key']), 0, 8)) / 0xffffffff * 0.20;
$delaySeconds = (int) ceil($base * (1.0 + $jitterPercent));
```

Aggregate recomputation locks the parent and uses this order:

```text
completed   if count(targets) > 0 and every target.status = completed
partial     if any target.status != completed and target.last_attempt_outcome in (partial, failed)
in_progress if any target.attempt_count > 0 and unresolved targets remain
pending     otherwise, including zero targets
```

- [ ] **Step 9: Implement reappearance and compaction**

`reportReappearance()` accepts only authenticated identity for the target, a completed target, and a permanent tombstone. It does not authorize deletion under an old attempt token. It records bounded proof under `result_json`, increments `reconciliation_generation` by one, sets `status='pending'`, clears `last_attempt_id`, clears `lease_until`, sets `last_attempt_outcome='partial'`, and recomputes the parent. If another request already reopened the target to pending, return the current generation without incrementing again.

`compactCompletedTargets()` selects completed targets older than 30 days with non-null verbose `result_json` or `last_error`. It writes:

```php
$receipt = [
    'retirement_id' => (int) $target['retirement_id'],
    'role' => (string) $target['role'],
    'target_key' => (string) $target['target_key'],
    'device_session_id' => $target['device_session_id'] !== null ? (int) $target['device_session_id'] : null,
    'completed_generation' => (int) $target['reconciliation_generation'],
    'completed_at' => (string) $target['completed_at'],
];
```

Then clear `result_json` and `last_error`. Never delete tombstones, scopes, targets, or receipts.

- [ ] **Step 10: Run the service test**

Run:

```bash
php master/tests/forward_retirement_service_test.php
```

Expected: PASS and print `ok`.

- [ ] **Step 11: Commit service implementation**

```bash
git add master/src/Support/ForwardRetirementService.php \
  master/tests/forward_retirement_service_test.php
git commit -m $'feat(master): manage forward runtime retirements\n\nCo-Authored-By: Claude Fable 5 <noreply@anthropic.com>\nCo-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>'
```

---

### Task 4: Add activation/compaction CLIs and read-only operations projection

**Files:**
- Create: `master/cli/activate-forward-retirements.php`
- Create: `master/cli/compact-forward-retirements.php`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/AdminController.php`
- Create: `master/tests/forward_retirement_cli_shape_test.php`

- [ ] **Step 1: Write the failing CLI and operations shape test**

Create `master/tests/forward_retirement_cli_shape_test.php`. The test must assert:

1. `master/cli/activate-forward-retirements.php` exists, requires the normal bootstrap, constructs `ForwardRetirementService`, calls `activateLegacyForwards()`, and prints JSON counts.
2. The activation CLI accepts optional `--limit=N` and `--dry-run` flags; dry-run performs the read-only scan and prints matching Forward IDs but does not call `activateLegacyForward()`.
3. `master/cli/compact-forward-retirements.php` exists and calls `compactCompletedTargets(30, 500)` by default.
4. `Bootstrap.php` registers read-only operations routes: `GET /admin/retirements`, `GET /api/admin/retirements`, and `GET /api/admin/retirements/{id}`.
5. No `POST /admin/retirements`, publish, clone, edit, reactivation, or cleanup-trigger route exists.
6. `AdminController` contains `retirementsPage`, `retirementsApi`, and `retirementDetailApi`, and those methods call only `operationsSummary()` / `operationsDetail()`.
7. The protected Bridge Lite plan path is not referenced by either CLI.

End with `echo "ok\n";`.

- [ ] **Step 2: Run the shape test and verify it fails**

Run:

```bash
php master/tests/forward_retirement_cli_shape_test.php
```

Expected: FAIL because the CLI files and routes do not exist.

- [ ] **Step 3: Add activation CLI**

Create `master/cli/activate-forward-retirements.php`:

```php
<?php
declare(strict_types=1);

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Support\ForwardRetirementService;

require __DIR__ . '/../vendor/autoload.php';

$envPath = getenv('LEIKWAN_ENV') ?: (__DIR__ . '/../.env');
$limit = 0;
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, strlen('--limit=')));
        continue;
    }
    fwrite(STDERR, "unknown argument: {$arg}\n");
    exit(2);
}

$bootstrap = new Bootstrap($envPath);
$service = new ForwardRetirementService($bootstrap->config(), $bootstrap->db());

if ($dryRun) {
    $rows = $bootstrap->db()->fetchAll(
        "SELECT f.id, f.backend_mode, fc.backend_mode AS combination_backend_mode
         FROM forwards f
         LEFT JOIN forward_combinations fc ON fc.id = f.forward_combination_id
         WHERE f.backend_mode IN ('current_mode', 'cf_mode')
            OR fc.backend_mode IN ('current_mode', 'cf_mode')
         ORDER BY f.id ASC" . ($limit > 0 ? ' LIMIT ' . $limit : '')
    );
    echo json_encode([
        'ok' => true,
        'dry_run' => true,
        'matches' => array_map(static fn(array $row): array => [
            'forward_id' => (int) $row['id'],
            'backend_mode' => (string) ($row['backend_mode'] ?? ''),
            'combination_backend_mode' => $row['combination_backend_mode'] !== null ? (string) $row['combination_backend_mode'] : null,
        ], $rows),
    ], JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$result = $service->activateLegacyForwards($limit);
echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES) . "\n";
```

This command is not a deployment step. It must not contact agents or production hosts directly.

- [ ] **Step 4: Add compaction CLI**

Create `master/cli/compact-forward-retirements.php`:

```php
<?php
declare(strict_types=1);

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Support\ForwardRetirementService;

require __DIR__ . '/../vendor/autoload.php';

$envPath = getenv('LEIKWAN_ENV') ?: (__DIR__ . '/../.env');
$olderThanDays = 30;
$limit = 500;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--older-than-days=')) {
        $olderThanDays = max(1, (int) substr($arg, strlen('--older-than-days=')));
        continue;
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, strlen('--limit=')));
        continue;
    }
    fwrite(STDERR, "unknown argument: {$arg}\n");
    exit(2);
}

$bootstrap = new Bootstrap($envPath);
$service = new ForwardRetirementService($bootstrap->config(), $bootstrap->db());
$result = $service->compactCompletedTargets($olderThanDays, $limit);
echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_SLASHES) . "\n";
```

- [ ] **Step 5: Register read-only operations routes and inject service**

Modify `master/src/Bootstrap.php`:

1. Add `use Leikwan\Master\Support\ForwardRetirementService;`.
2. Add property `private ForwardRetirementService $retirements;`.
3. Construct it after `ProvisioningService`:

```php
$this->retirements = new ForwardRetirementService($this->config, $this->db);
```

4. Pass `$this->retirements` to `AdminController`, `ClientAgentController`, `NodeController`, and `SidecarAgentController` constructors.
5. Add routes:

```php
$this->router->add('GET', '/admin/retirements', [$admin, 'retirementsPage']);
$this->router->add('GET', '/api/admin/retirements', [$admin, 'retirementsApi']);
$this->router->add('GET', '/api/admin/retirements/{id}', [$admin, 'retirementDetailApi']);
```

Do not add any POST retirement admin route in this task.

- [ ] **Step 6: Add AdminController read-only projection methods**

Modify the `AdminController` constructor to accept `ForwardRetirementService $retirements` and store it. Add:

```php
public function retirementsPage(Request $request, array $params): Response
{
    $this->requireAdmin();
    $summary = $this->retirements->operationsSummary([
        'status' => (string) $request->query('status', ''),
        'forward_id' => (int) $request->query('forward_id', 0),
    ]);

    return $this->adminPage('Forward Runtime Retirements', $this->renderRetirementsTable($summary));
}

public function retirementsApi(Request $request, array $params): Response
{
    $this->requireAdmin();
    return $this->json($this->retirements->operationsSummary([
        'status' => (string) $request->query('status', ''),
        'forward_id' => (int) $request->query('forward_id', 0),
    ]));
}

public function retirementDetailApi(Request $request, array $params): Response
{
    $this->requireAdmin();
    return $this->json($this->retirements->operationsDetail((int) $params['id']));
}
```

`renderRetirementsTable()` must render only status, Forward ID, original modes, counts, last discovery time, unresolved targets, and compact receipt indicators. It must not render forms, buttons, links, or JS actions for edit, publish, clone, select, reactivation, retry-now, or cleanup-now.

- [ ] **Step 7: Run shape tests**

Run:

```bash
php master/tests/forward_retirement_cli_shape_test.php
```

Expected: PASS and print `ok`.

- [ ] **Step 8: Commit CLI and operations projection**

```bash
git add master/cli/activate-forward-retirements.php \
  master/cli/compact-forward-retirements.php \
  master/src/Bootstrap.php \
  master/src/Controllers/AdminController.php \
  master/tests/forward_retirement_cli_shape_test.php
git commit -m $'feat(master): expose read-only forward retirements\n\nCo-Authored-By: Claude Fable 5 <noreply@anthropic.com>\nCo-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>'
```

---

### Task 5: Wire authenticated role delivery, renewal, results, and reappearance routes

**Files:**
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/src/Controllers/NodeController.php`
- Modify: `master/src/Controllers/SidecarAgentController.php`
- Create: `master/tests/forward_retirement_controller_test.php`

- [ ] **Step 1: Write the failing controller test**

Create `master/tests/forward_retirement_controller_test.php`. Use a test router/bootstrap fixture with one client session, one node, one sidecar point, one retirement, and three targets.

Assert these exact behaviors:

1. `GET /api/client/configs/pull` with a logical client token returns `device_session`, materializes the client's target, and returns an explicit top-level `runtime_retirements` array when leases are due.
2. A client with no due retirements still receives `runtime_retirements: []` when the agent reports retirement capability.
3. A client response without the `runtime_retirements` key is never generated by the server for retirement-capable clients; older clients that ignore the key remain safe because absence is not a delete signal.
4. `POST /api/client/retirements/{id}/result` requires valid logical client token plus valid `X-Leikwan-Device-Session-Id` and `X-Leikwan-Device-Session-Secret`.
5. A client result cannot acknowledge a target for another client or another device session.
6. `GET /api/node/tasks/pull` returns existing `tasks` unchanged and adds `management.runtime_retirements` for the authenticated node.
7. A node result cannot claim another node's slave target even if the body supplies that node ID.
8. `POST /api/sidecar/heartbeat` returns existing management fields unchanged and adds `management.runtime_retirements` for the authenticated sidecar point.
9. A sidecar result cannot claim another sidecar point's target.
10. Renew routes extend only current leases and stale renew returns `{ok:true, accepted:false, stale:true}`.
11. Reappearance routes do not delete anything; they reopen the target generation and return `{ok:true, reopened:true}` or `{ok:true, reopened:false}` for duplicates.
12. Malformed result bodies return bounded stable errors and do not clear leases.

End with `echo "ok\n";`.

- [ ] **Step 2: Run the controller test and verify missing routes**

Run:

```bash
php master/tests/forward_retirement_controller_test.php
```

Expected: FAIL because the role-specific routes and controller methods do not exist.

- [ ] **Step 3: Register role-specific routes**

In `master/src/Bootstrap.php`, add these routes next to existing client/node/sidecar API routes:

```php
$this->router->add('POST', '/api/client/retirements/{id}/renew', [$clientAgent, 'renewRetirementLease']);
$this->router->add('POST', '/api/client/retirements/{id}/result', [$clientAgent, 'retirementResult']);
$this->router->add('POST', '/api/client/retirements/{id}/reappearance', [$clientAgent, 'retirementReappearance']);

$this->router->add('POST', '/api/node/retirements/{id}/renew', [$nodes, 'renewRetirementLease']);
$this->router->add('POST', '/api/node/retirements/{id}/result', [$nodes, 'retirementResult']);
$this->router->add('POST', '/api/node/retirements/{id}/reappearance', [$nodes, 'retirementReappearance']);

$this->router->add('POST', '/api/sidecar/retirements/{id}/renew', [$sidecarAgent, 'renewRetirementLease']);
$this->router->add('POST', '/api/sidecar/retirements/{id}/result', [$sidecarAgent, 'retirementResult']);
$this->router->add('POST', '/api/sidecar/retirements/{id}/reappearance', [$sidecarAgent, 'retirementReappearance']);
```

- [ ] **Step 4: Add client retirement delivery and result methods**

Modify `ClientAgentController` constructor to accept `ForwardRetirementService $retirements`.

In `pullConfigs()`, after `$sessionContext` is created and before returning JSON:

```php
$this->retirements->materializeClientTargets(
    (int) $client['id'],
    $sessionContext->deviceSessionId()
);
$runtimeRetirements = $this->retirements->leaseTargetsForClient(
    $client,
    $sessionContext
);
```

Add top-level response fields:

```php
'device_session' => $sessionContext->responsePayload(),
'runtime_retirements' => $runtimeRetirements,
```

Keep the existing nested `client.device_session` during a transition if desktop code expects client-scoped metadata, but the authoritative field for agents is the top-level `device_session`.

Add methods:

```php
public function renewRetirementLease(Request $request, array $params): Response
{
    $client = Auth::requireClientAgent($request, $this->db);
    $sessionContext = (new ClientOnlineSessionService($this->db))
        ->requireCredentialContext($client, $request, 'heartbeat');

    return $this->json($this->retirements->renewLease(
        ForwardRetirementService::ROLE_CLIENT,
        ['client_id' => (int) $client['id'], 'device_session_id' => $sessionContext->deviceSessionId()],
        (int) $params['id'],
        (int) $request->input('reconciliation_generation', 0),
        (int) $request->input('attempt_seq', 0),
        (string) $request->input('attempt_id', '')
    ));
}

public function retirementResult(Request $request, array $params): Response
{
    $client = Auth::requireClientAgent($request, $this->db);
    $sessionContext = (new ClientOnlineSessionService($this->db))
        ->requireCredentialContext($client, $request, 'heartbeat');

    return $this->json($this->retirements->acceptResult(
        ForwardRetirementService::ROLE_CLIENT,
        ['client_id' => (int) $client['id'], 'device_session_id' => $sessionContext->deviceSessionId()],
        (int) $params['id'],
        is_array($request->body()) ? $request->body() : []
    ));
}

public function retirementReappearance(Request $request, array $params): Response
{
    $client = Auth::requireClientAgent($request, $this->db);
    $sessionContext = (new ClientOnlineSessionService($this->db))
        ->requireCredentialContext($client, $request, 'heartbeat');

    return $this->json($this->retirements->reportReappearance(
        ForwardRetirementService::ROLE_CLIENT,
        ['client_id' => (int) $client['id'], 'device_session_id' => $sessionContext->deviceSessionId()],
        (int) $params['id'],
        is_array($request->body()) ? $request->body() : []
    ));
}
```

- [ ] **Step 5: Add node retirement management and result methods**

Modify `NodeController` constructor to accept `ForwardRetirementService $retirements`.

In `pullTasks()`, keep existing `tasks` and `management` fields. Add:

```php
$management['runtime_retirements'] = $this->retirements->leaseTargetsForNode($node);
```

If `management` is built by another helper, add the field immediately before the JSON response so it cannot be overwritten by existing task logic.

Add methods equivalent to the client methods, with identity:

```php
[
    'node_id' => (int) $node['id'],
]
```

and role:

```php
ForwardRetirementService::ROLE_SLAVE
```

Do not allow request body fields named `node_id`, `role`, or `target_id` to change the authenticated identity.

- [ ] **Step 6: Add sidecar retirement management and result methods**

Modify `SidecarAgentController` constructor to accept `ForwardRetirementService $retirements`.

In `heartbeat()`, after `$commonManagement + [...]` is assembled but before returning, add:

```php
$management['runtime_retirements'] = $this->retirements->leaseTargetsForSidecarPoint($point);
```

Add renew/result/reappearance methods using identity:

```php
[
    'sidecar_point_id' => (int) $point['id'],
]
```

and role:

```php
ForwardRetirementService::ROLE_SIDECAR
```

Do not allow request body fields named `sidecar_point_id`, `role`, or `target_id` to change the authenticated identity.

- [ ] **Step 7: Run controller tests**

Run:

```bash
php master/tests/forward_retirement_controller_test.php
```

Expected: PASS and print `ok`.

- [ ] **Step 8: Commit delivery routes and controllers**

```bash
git add master/src/Bootstrap.php \
  master/src/Controllers/ClientAgentController.php \
  master/src/Controllers/NodeController.php \
  master/src/Controllers/SidecarAgentController.php \
  master/tests/forward_retirement_controller_test.php
git commit -m $'feat(master): deliver authenticated retirement work\n\nCo-Authored-By: Claude Fable 5 <noreply@anthropic.com>\nCo-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>'
```

---

### Task 6: Add client-agent Forward-scoped retirement cleanup

**Files:**
- Modify: `client-agent/client_agent.py`
- Create: `client-agent/tests/test_forward_retirement_cleanup.py`

- [ ] **Step 1: Write failing client cleanup tests**

Create `client-agent/tests/test_forward_retirement_cleanup.py` using the existing unittest/pytest style from `client-agent/tests/test_mptcp_setup_and_cleanup_regressions.py`.

The tests must patch command execution and filesystem roots so no host service is touched. Cover these cases:

1. An absent `runtime_retirements` key in a pull response performs no cleanup and does not rewrite retirement state.
2. A present empty `runtime_retirements: []` performs no cleanup and records only that the section was parsed.
3. A retirement for Forward 65 deletes exact resources from `applied.json['65']`: listed units, managed paths, nft rules for that Forward, and exact legacy interfaces `lw-mptcp-65-*`, `lw-cfwg-65`, `lw-cfsr-65`, `lw-self-65`.
4. Look-alike Forward 650 resources remain untouched.
5. A systemd unit is deleted only when its name or unit content proves exact Forward 65 ownership; generic `leikwan` and unrelated `wg-quick@` units remain untouched.
6. An active keep-set containing a unit/path/interface preserves that resource and records it under `keep_set_preserved` rather than `errors`.
7. Active `leigod_mode` bond workers and port leases are preserved even when binary names contain `bond`.
8. A malformed retirement object returns a bounded `failed` result and performs no deletion.
9. A desired-state conflict where Forward 65 is both active and retired returns `partial` with `desired_state_conflict` and performs no deletion.
10. A second leased generation after resources are already absent returns `completed` with `already_absent` counts.
11. A stale or forged result token is never posted as success; the local executor keeps the target pending for the next pull.
12. After completion, local state is compacted to a minimal watermark containing `forward_id`, `retirement_id`, `target_id`, and `reconciliation_generation`; if exact legacy evidence reappears, the agent posts reappearance proof and does not delete under the old generation.

- [ ] **Step 2: Run client cleanup tests and verify missing methods**

Run:

```bash
python -m pytest client-agent/tests/test_forward_retirement_cleanup.py -q
```

Expected: FAIL because the client agent has no retirement parser/executor.

- [ ] **Step 3: Persist device-session credential from pull responses**

In `client-agent/client_agent.py`, add a state path under the existing state root:

```python
self.device_session_credential_path = self.state_dir / "device-session-credential.json"
self.retirement_state_path = self.state_dir / "retirement_state.json"
```

Add helpers:

```python
def load_device_session_credential(self) -> dict:
    if not self.device_session_credential_path.exists():
        return {}
    try:
        payload = json.loads(self.device_session_credential_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}
    return payload if isinstance(payload, dict) else {}


def save_device_session_credential(self, payload: dict) -> None:
    tmp = self.device_session_credential_path.with_suffix(".tmp")
    tmp.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    tmp.replace(self.device_session_credential_path)


def update_device_session_from_response(self, response: dict) -> None:
    session = response.get("device_session")
    if not isinstance(session, dict):
        client = response.get("client")
        session = client.get("device_session") if isinstance(client, dict) else None
    if not isinstance(session, dict):
        return
    session_id = int(session.get("id", 0) or 0)
    if session_id <= 0:
        return
    current = self.load_device_session_credential()
    current["device_session_id"] = session_id
    current["diagnostic_device_id"] = str(session.get("diagnostic_device_id", "") or "")
    secret = str(session.get("retirement_secret", "") or "")
    if secret != "":
        current["retirement_secret"] = secret
    current["updated_at"] = int(time.time())
    self.save_device_session_credential(current)
```

HTTP calls to retirement `renew_path`, `result_path`, and `reappearance_path` must include:

```python
headers["X-Leikwan-Device-Session-Id"] = str(credential["device_session_id"])
headers["X-Leikwan-Device-Session-Secret"] = str(credential["retirement_secret"])
```

If either is missing, do not execute cleanup; request a new config pull and leave the target unmodified locally.

- [ ] **Step 4: Parse retirement work only when the key is present**

Add:

```python
def runtime_retirements_from_response(self, response: dict) -> tuple[bool, list[dict]]:
    if "runtime_retirements" not in response:
        return False, []
    raw = response.get("runtime_retirements")
    if not isinstance(raw, list):
        return True, []
    return True, [item for item in raw if isinstance(item, dict)]
```

In the main pull/apply loop, call the retirement executor after successful parsing of active desired state. Absent key means no-op. Present empty list means no-op. A malformed item produces a failed result for that item only.

- [ ] **Step 5: Build the client active keep-set**

Add:

```python
def build_active_keep_set(self, active_forwards: list[dict]) -> dict[str, set[str]]:
    keep = {
        "units": set(),
        "interfaces": set(),
        "paths": set(),
        "leases": set(),
        "forward_ids": set(),
    }
    applied = self.load_applied()
    for forward in active_forwards:
        try:
            fid = int(forward.get("id", 0) or forward.get("forward_id", 0) or 0)
        except (TypeError, ValueError):
            fid = 0
        if fid <= 0:
            continue
        keep["forward_ids"].add(str(fid))
        previous = applied.get(str(fid), {}) if isinstance(applied, dict) else {}
        if isinstance(previous, dict):
            for unit in previous.get("units", []):
                if isinstance(unit, dict):
                    name = str(unit.get("service_name", "") or "")
                    if name:
                        keep["units"].add(name)
                    for path in unit.get("managed_paths", []):
                        keep["paths"].add(str(path))
            for path in previous.get("managed_paths", []):
                keep["paths"].add(str(path))
    return keep
```

Extend the keep-set with active bond port leases and health snapshots when those registries exist. A lease key matching `(forward_id, tcp, node_id, endpoint_id, ingress_leg)` for an active Forward is preserved.

- [ ] **Step 6: Implement client `retire_forward_runtime()`**

Add an internal method with this signature:

```python
def retire_forward_runtime(self, forward_id: int, ownership_scope: dict, active_keep_set: dict[str, set[str]]) -> dict:
```

It must:

```text
1. Reject forward_id <= 0 with failed.
2. If str(forward_id) is in active_keep_set['forward_ids'], perform no deletion and return partial desired_state_conflict.
3. Build candidates only from applied.json[str(forward_id)], ownership_scope known_units/known_interfaces/managed_paths, and canonical exact Forward-ID names.
4. Inspect systemd unit content before deleting a unit whose name alone is not canonical.
5. Subtract active keep-set units/interfaces/paths/leases.
6. Stop/disable/delete only remaining exact units.
7. Delete exact WireGuard interfaces/configs and legacy interfaces matching the retired Forward ID.
8. Delete exact generated scripts, manifests, nft rules, namespaces, veths, bond health files, and local port leases owned by the retired Forward.
9. Remove retired references from applied.json only after exclusive resource deletion succeeds or a keep-set-preserved shared object is recorded.
10. Return completed when all exclusive resources are absent and ambiguous candidates list is empty; partial when ambiguity or command failure remains; failed only for malformed input or executor exceptions before safe candidate construction.
```

Canonical client names include:

```text
leikwan-client-<forward_id>
leikwan-client-supervisor-<forward_id>
leikwan-bond-<forward_id>
leikwan-bond-client-<forward_id>
lw-mptcp-<forward_id>-<n>
lw-cfwg-<forward_id>
lw-cfsr-<forward_id>
lw-self-<forward_id>
```

Do not enumerate and delete every `wg-quick@*`, every WireGuard interface, or every unit starting with `leikwan`.

- [ ] **Step 7: Execute leased retirements serially and post bounded results**

Add:

```python
def process_runtime_retirements(self, retirements: list[dict], active_forwards: list[dict]) -> None:
    keep = self.build_active_keep_set(active_forwards)
    started = 0
    deadline = time.monotonic() + 30.0
    for item in retirements:
        if started >= 16 or time.monotonic() >= deadline:
            break
        self.process_one_runtime_retirement(item, keep)
        started += 1
```

`process_one_runtime_retirement()` validates `target_id`, `retirement_id`, `forward_id`, `reconciliation_generation`, `attempt_seq`, `attempt_id`, and paths. It posts to `result_path` with:

```python
{
    "retirement_id": retirement_id,
    "forward_id": forward_id,
    "reconciliation_generation": generation,
    "attempt_seq": attempt_seq,
    "attempt_id": attempt_id,
    "outcome": "completed" if result["ok"] else result["outcome"],
    "counts": result["counts"],
    "resources": result["resources"][:50],
    "message": result["message"][:512],
}
```

Renew a lease only while the cleanup loop is still running and the current monotonic time has passed half of the lease duration. Do not renew after the local outcome is known.

- [ ] **Step 8: Add completion watermark and reappearance check**

`retirement_state.json` must keep pending evidence until accepted completion. On accepted completion, compact to:

```json
{
  "completed": {
    "65": {
      "retirement_id": 123,
      "target_id": 456,
      "reconciliation_generation": 1,
      "completed_at": 1760000000
    }
  }
}
```

On each pull with a permanent tombstone for an already completed Forward, run a cheap exact inventory. If exact retired resources reappear, post to `reappearance_path` with bounded proof and do not delete until a new leased generation arrives.

- [ ] **Step 9: Run client cleanup tests**

Run:

```bash
python -m pytest client-agent/tests/test_forward_retirement_cleanup.py -q
```

Expected: PASS.

- [ ] **Step 10: Commit client agent retirement cleanup**

```bash
git add client-agent/client_agent.py \
  client-agent/tests/test_forward_retirement_cleanup.py
git commit -m $'feat(client-agent): retire forward-owned runtime safely\n\nCo-Authored-By: Claude Fable 5 <noreply@anthropic.com>\nCo-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>'
```

---

### Task 7: Add slave-agent Forward-scoped retirement cleanup

**Files:**
- Modify: `slave-agent/slave_agent.py`
- Create: `slave-agent/tests/test_forward_retirement_cleanup.py`

- [ ] **Step 1: Write failing slave cleanup tests**

Create `slave-agent/tests/test_forward_retirement_cleanup.py` using fake service metadata, fake systemd files, and command capture.

Cover:

1. Missing `management.runtime_retirements` performs no cleanup.
2. Present empty `management.runtime_retirements` performs no cleanup.
3. A retirement for Forward 65 deletes only service metadata whose JSON contains `forward_id: 65` or whose `endpoint_id` appears in `ownership_scope.endpoint_ids` and the metadata also belongs to Forward 65.
4. Unit name `wstunnel-svc-1-901` alone is insufficient when metadata is missing or disagrees with Forward 65; it remains and returns partial.
5. Look-alike Forward 650 metadata and units remain untouched.
6. Existing endpoint `destroy_service()` helpers may be called only for endpoint IDs in scope and exact metadata; missing endpoint resources count as success.
7. Active static sidecar uplinks in the keep-set are preserved even if retired metadata references the same interface.
8. Broad helper methods do not delete all MPTCP underlays, all sidecar uplinks, all `wg-quick@*`, or all `wstunnel-svc-*` units.
9. Partial command failure preserves evidence and schedules retry through the master result path.
10. A second generation with already absent resources returns completed.
11. Desired-state conflict returns partial and performs no deletion.

- [ ] **Step 2: Run slave cleanup tests and verify missing methods**

Run:

```bash
python -m pytest slave-agent/tests/test_forward_retirement_cleanup.py -q
```

Expected: FAIL because the slave agent has no Forward retirement executor.

- [ ] **Step 3: Parse retirement work in management payload**

In `slave-agent/slave_agent.py`, wherever node management/task-pull response is parsed, add:

```python
def runtime_retirements_from_management(self, management: dict) -> tuple[bool, list[dict]]:
    if "runtime_retirements" not in management:
        return False, []
    raw = management.get("runtime_retirements")
    if not isinstance(raw, list):
        return True, []
    return True, [item for item in raw if isinstance(item, dict)]
```

Call `process_runtime_retirements()` only after active management/task parsing succeeds. Missing key is no-op.

- [ ] **Step 4: Build the slave active keep-set**

Build keep-set from active provision tasks, active service metadata, active sidecar-uplink state, active MPTCP underlay state, active filter/listener state, and active management sidecar uplinks:

```python
{
    "units": set(),
    "interfaces": set(),
    "paths": set(),
    "endpoint_ids": set(),
    "forward_ids": set(),
    "sidecar_uplinks": set(),
}
```

A sidecar uplink is kept if any active LeiGod endpoint or management uplink references the same interface, config path, relay service, selector service, or static target.

- [ ] **Step 5: Implement slave `retire_forward_runtime()`**

Add:

```python
def retire_forward_runtime(self, forward_id: int, ownership_scope: dict, active_keep_set: dict[str, set[str]]) -> dict:
```

The executor must construct candidates from:

```text
services/*.json where metadata.forward_id == forward_id
services/*.json where metadata.endpoint_id is in ownership_scope.endpoint_ids and metadata.forward_id == forward_id
ownership_scope.known_units / known_interfaces / managed_paths after exact proof
provision task payload service names only when payload.forward_id == forward_id or endpoint_id is in scope with matching metadata
```

Delete only after proof and keep-set subtraction:

```text
endpoint ingress/backend/relay/selector units
classic/CF wstunnel units
shadow backend units
UoT and MPTCP underlay units
Forward-owned WireGuard interfaces/configs
Forward-owned route/filter/listener files
retired references in local state JSON
stale sidecar-uplink references attributable only to the retired Forward
```

If a unit's name is generic or lacks the exact Forward ID, require metadata or unit content/environment proof before deletion. If proof is absent, preserve it and return partial.

- [ ] **Step 6: Wire result posting and lease bounds**

Use the paths supplied by the server object. A slave uses its existing bearer token; it must not include or accept request-body identity overrides. Process at most 16 leased targets and at most 30 seconds per cycle. Bound resources and messages to the same limits as the client.

- [ ] **Step 7: Run slave cleanup tests**

Run:

```bash
python -m pytest slave-agent/tests/test_forward_retirement_cleanup.py -q
```

Expected: PASS.

- [ ] **Step 8: Commit slave agent retirement cleanup**

```bash
git add slave-agent/slave_agent.py \
  slave-agent/tests/test_forward_retirement_cleanup.py
git commit -m $'feat(slave-agent): retire forward-owned services safely\n\nCo-Authored-By: Claude Fable 5 <noreply@anthropic.com>\nCo-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>'
```

---

### Task 8: Add sidecar-agent Forward-scoped retirement cleanup

**Files:**
- Modify: `sidecar-agent/sidecar_agent.py`
- Create: `sidecar-agent/tests/test_forward_retirement_cleanup.py`

- [ ] **Step 1: Write failing sidecar cleanup tests**

Create `sidecar-agent/tests/test_forward_retirement_cleanup.py` with temp state directories and command capture.

Cover:

1. Missing `management.runtime_retirements` performs no cleanup and preserves existing sidecar uplinks.
2. Present empty `management.runtime_retirements` performs no cleanup.
3. Forward 65 retirement removes exact legacy MPTCP transit interfaces, slave-transit CF backends, relay/selector units, old per-Forward `gost`/`lguot` residue, WireGuard configs, generated routes, and retired static-uplink references only when local state or ownership scope proves Forward 65.
4. Look-alike Forward 650 resources remain untouched.
5. The point-shared `leikwan-bond` server service, config, start script, runtime config, and stats file are never disabled or deleted for a single Forward retirement.
6. Active LeiGod static sidecar uplinks and routes in the keep-set are preserved and reported under `keep_set_preserved`.
7. Broad `disable_runtime()` is not called for the point-shared bond server during Forward retirement.
8. Desired-state conflict returns partial and performs no deletion.
9. A second generation with already absent resources returns completed.
10. Ambiguous unit names without exact content/environment proof remain and produce partial.

- [ ] **Step 2: Run sidecar cleanup tests and verify missing methods**

Run:

```bash
python -m pytest sidecar-agent/tests/test_forward_retirement_cleanup.py -q
```

Expected: FAIL because the sidecar agent has no runtime retirement executor.

- [ ] **Step 3: Parse retirement work in sidecar management**

In `sidecar-agent/sidecar_agent.py`, add parsing with the same present/absent semantics used for `sidecar_uplinks`:

```python
runtime_retirements_present = "runtime_retirements" in management
raw_runtime_retirements = management.get("runtime_retirements", [])
runtime_retirements = raw_runtime_retirements if isinstance(raw_runtime_retirements, list) else []
if runtime_retirements_present:
    self.process_runtime_retirements(runtime_retirements, management)
```

Do not treat an absent key as an empty authoritative wipe.

- [ ] **Step 4: Build the sidecar active keep-set**

Build keep-set from the same successfully parsed management payload and persisted active state:

```python
{
    "units": set(),
    "interfaces": set(),
    "paths": set(),
    "routes": set(),
    "sidecar_uplinks": set(),
    "bond_services": set(),
    "forward_ids": set(),
}
```

Always include the point-shared sidecar bond runtime service/config/start script/stats path in `bond_services` when any active LeiGod Forward references that point's bond runtime. Retiring one Forward must not stop that service.

- [ ] **Step 5: Implement sidecar `retire_forward_runtime()`**

Add:

```python
def retire_forward_runtime(self, forward_id: int, ownership_scope: dict, active_keep_set: dict[str, set[str]]) -> dict:
```

Construct candidates only from exact evidence:

```text
state rows containing forward_id == retired forward_id
ownership_scope known interfaces/units/paths after exact proof
canonical names embedding the exact Forward ID
unit content/environment containing LEIKWAN_FORWARD_ID=<forward_id> or equivalent exact JSON field
```

Candidate families:

```text
legacy MPTCP transit interfaces and configs
slave-transit CF backend units and configs
relay and selector units
old per-Forward gost/lguot residue
WireGuard units/interfaces/configs
generated route files
static-uplink references solely attributable to the retired Forward
```

Never delete:

```text
point-shared sidecar leikwan-bond server
active static sidecar uplink referenced by any active LeiGod Forward
active bond runtime config or stats file
unknown wg-quick@* units
all leikwan* units by prefix
```

- [ ] **Step 6: Wire result posting and reappearance**

Use server-provided paths. Sidecar result calls use the sidecar agent bearer token only; body identity is diagnostic. Process at most 16 leased targets and 30 seconds per cycle. After accepted completion, compact local state to a generation watermark and run cheap exact inventory on future tombstone replay to detect reappearance.

- [ ] **Step 7: Run sidecar cleanup tests**

Run:

```bash
python -m pytest sidecar-agent/tests/test_forward_retirement_cleanup.py -q
```

Expected: PASS.

- [ ] **Step 8: Commit sidecar agent retirement cleanup**

```bash
git add sidecar-agent/sidecar_agent.py \
  sidecar-agent/tests/test_forward_retirement_cleanup.py
git commit -m $'feat(sidecar-agent): retire forward-owned sidecar residue\n\nCo-Authored-By: Claude Fable 5 <noreply@anthropic.com>\nCo-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>'
```

---

### Task 9: Run full retirement verification and protect unrelated files

**Files:**
- No new product files.
- Verify all files modified by Tasks 1–8.

- [ ] **Step 1: Run all retirement PHP tests**

Run:

```bash
php master/tests/retirement_migration_shape_test.php
php master/tests/client_device_session_credential_test.php
php master/tests/forward_retirement_service_test.php
php master/tests/forward_retirement_cli_shape_test.php
php master/tests/forward_retirement_controller_test.php
```

Expected: every command prints `ok`.

- [ ] **Step 2: Run all retirement Python tests**

Run:

```bash
python -m pytest client-agent/tests/test_forward_retirement_cleanup.py -q
python -m pytest slave-agent/tests/test_forward_retirement_cleanup.py -q
python -m pytest sidecar-agent/tests/test_forward_retirement_cleanup.py -q
```

Expected: every suite passes.

- [ ] **Step 3: Run focused existing cleanup regressions**

Run:

```bash
python -m pytest client-agent/tests/test_mptcp_setup_and_cleanup_regressions.py -q
python -m pytest slave-agent/tests/test_slave_mptcp_cleanup.py -q
python -m pytest sidecar-agent/tests/test_sidecar_stale_transit_cleanup.py -q
```

Expected: every suite passes and existing scoped-cleanup protections remain intact.

- [ ] **Step 4: Run PHP syntax and selected full PHP tests**

Run:

```bash
php -l master/src/Support/ForwardRetirementService.php
php -l master/src/Support/ClientDeviceSessionContext.php
php -l master/src/Support/ClientOnlineSessionService.php
php -l master/src/Support/Auth.php
php -l master/src/Controllers/ClientAgentController.php
php -l master/src/Controllers/NodeController.php
php -l master/src/Controllers/SidecarAgentController.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Bootstrap.php
```

Expected: each prints `No syntax errors detected`.

- [ ] **Step 5: Audit git status before final commit**

Run:

```bash
git status --short
```

Expected tracked/untracked files are limited to the retirement implementation files in this plan plus pre-existing untracked plan files. The protected file must remain unstaged and unmodified:

```text
docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md
```

If the protected file appears as modified, stop and report the blocker. Do not stage it.

- [ ] **Step 6: Make the final retirement verification commit if Tasks 1–8 were not already committed separately**

If the earlier task commits were skipped, stage only retirement files with this explicit list:

```bash
git add master/migrations/20260718_add_forward_runtime_retirements.sql \
  master/migrations/schema.sql \
  master/src/Support/ClientDeviceSessionContext.php \
  master/src/Support/ForwardRetirementService.php \
  master/src/Support/Auth.php \
  master/src/Support/ClientOnlineSessionService.php \
  master/src/Bootstrap.php \
  master/src/Controllers/AdminController.php \
  master/src/Controllers/ClientAgentController.php \
  master/src/Controllers/NodeController.php \
  master/src/Controllers/SidecarAgentController.php \
  master/cli/activate-forward-retirements.php \
  master/cli/compact-forward-retirements.php \
  master/tests/retirement_migration_shape_test.php \
  master/tests/client_device_session_credential_test.php \
  master/tests/forward_retirement_service_test.php \
  master/tests/forward_retirement_cli_shape_test.php \
  master/tests/forward_retirement_controller_test.php \
  client-agent/client_agent.py \
  client-agent/tests/test_forward_retirement_cleanup.py \
  slave-agent/slave_agent.py \
  slave-agent/tests/test_forward_retirement_cleanup.py \
  sidecar-agent/sidecar_agent.py \
  sidecar-agent/tests/test_forward_retirement_cleanup.py
git commit -m $'feat(master): retire legacy forward runtime safely\n\nCo-Authored-By: Claude Fable 5 <noreply@anthropic.com>\nCo-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>'
```

Do not stage `docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md`.

---

## Retirement self-review checklist for the implementer

Before asking for review or moving to the next implementation plan, verify all items below:

- [ ] No production host, remote service, artifact endpoint, or deployment command was contacted.
- [ ] No product source outside the listed retirement files was changed.
- [ ] No historical Forward, combination, route, endpoint, donation, CF, sidecar, runtime evidence, audit, or relationship row was deleted or silently converted.
- [ ] Legacy activation changes only allowed lifecycle/retirement metadata: `forwards.enabled`, endpoint lifecycle/destroy convergence, tombstones, scopes, targets, and audit.
- [ ] The migration contains no `UPDATE ... backend_mode`, no destructive table operation, and no MySQL `AFTER` clause.
- [ ] `client_device_sessions.id` plus server-issued secret digest authorizes device targets; caller-supplied `device_id` is diagnostic only.
- [ ] Client result/renew/reappearance requires logical client auth and valid session credential; shared client token alone cannot acknowledge cleanup.
- [ ] Node and sidecar result paths derive role identity from bearer authentication and ignore body role/target identity claims.
- [ ] Target uniqueness uses non-null `target_key`; no nullable unique-key behavior is required for correctness.
- [ ] Leasing is at-most-one live attempt per target, five minutes, max 16 starts per cycle, max 30 seconds agent work per cycle.
- [ ] Duplicate, stale, expired, forged, lower-sequence, old-generation, and wrong-role results are idempotent no-ops.
- [ ] Partial/failed attempts retain evidence and schedule master-owned exponential backoff with stable target jitter.
- [ ] A second generation after reappearance never deletes under an old token.
- [ ] Agent cleanup deletes only exact Forward-owned resources and subtracts active keep-set resources first.
- [ ] Active LeiGod static uplinks, active bond workers, local port leases, sidecar bond server, and active traffic are preserved.
- [ ] Missing `runtime_retirements` sections are no-op on all agents.
- [ ] Present empty `runtime_retirements` lists are no-op on all agents.
- [ ] Ambiguous ownership returns `partial` and preserves resources.
- [ ] Completed targets compact only verbose result/error payloads; tombstones, scopes, targets, and permanent receipts remain.
- [ ] The protected Bridge Lite plan remains untouched and unstaged.

---

## Not included in this plan

The following work is intentionally outside this retirement/cleanup plan and must be covered by the remaining plans before implementation execution is offered:

- Standalone `leikwan-bond` scheduler/framing/data-plane hardening, same-session best-slave degradation, and Rust tests.
- Linux per-Forward supervisor, host-wide local port leases, generation fencing, and recovery budgets.
- Authenticated bond runtime telemetry, capability/version/hash gates, reducer, reporter, and operations display.
- Artifact mirror synchronization, version/hash/manifest alignment, rollout canaries, normalization execution, production activation, rollback, and deployment authorization.
