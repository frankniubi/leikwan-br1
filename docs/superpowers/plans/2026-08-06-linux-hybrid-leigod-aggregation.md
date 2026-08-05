# Linux Hybrid LeiGod Aggregation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Linux-only, single-daemon hybrid LeiGod bond that combines client-local and slave paths into one transparent Reverse-P1-capable stream, backed by exclusive fenced line leases and an independent egress aggregator.

**Architecture:** The PHP panel owns catalog, lease, revision, and egress policy. Existing `client-agent` remains the only control authority and atomically reconciles one `leikwan-pathd` system daemon. `leikwan-pathd` reuses the existing Rust bond session and scheduler, while slave relays expose only node-leased route IDs. An independent LeiGod egress `leikwan-bond server` reassembles legs and opens one target connection.

**Tech Stack:** PHP 8/MySQL migrations and script tests, Python 3 `unittest`, Rust/Tokio/Serde, systemd, manifest-pinned static Linux artifacts, iperf3 JSON, WSL for local Linux commands.

---

## Execution Rules

- Work only in `.worktrees/sidecar-shared-wg-peers` on `codex/sidecar-shared-wg-peers`.
- Follow TDD for every task: focused RED, minimal implementation, focused GREEN, regression GREEN.
- Run heavy commands serially. Never overlap Cargo builds, broad Python suites, or broad PHP lint/tests.
- Keep real credentials in the existing local credential files and server secret boundary. Never add them to tests, commits, logs, docs, or prompts.
- Do not modify Windows or Android runtime behavior.
- Do not use `hk-bgp-b`, its sidecar DNS, or Point 10 flags for the hybrid path.
- Each implementation task is committed only after spec review and code-quality review pass.

## File Structure

New focused units:

- `master/src/Support/LeigodExecutionLeaseService.php`: transactional exclusive owner leases, fencing, readiness, renewal, and projections.
- `master/src/Support/ClientConfigRevisionService.php`: monotonic per-client revision read/bump and bounded watch state.
- `master/src/Support/LeigodHybridRuntimeService.php`: capability-gated egress/local/slave contract construction; no general provisioning responsibilities.
- `wstunnel-main/leikwan-bond/src/lib.rs`: exports existing frame/session/strategy/token/transport modules for both binaries.
- `wstunnel-main/leikwan-bond/src/pathd.rs`: desired-generation validation and multi-forward reconcile lifecycle.
- `wstunnel-main/leikwan-bond/src/pathd_line.rs`: root-only local lease files and provider child lifecycle.
- `wstunnel-main/leikwan-bond/src/bin/leikwan-pathd.rs`: daemon CLI, signals, status snapshots, and shutdown.

Existing large files receive only integration adapters:

- `master/src/Support/ProvisioningService.php`: call the new services while preserving old contracts.
- `master/src/Controllers/ClientAgentController.php`: pull metadata, watch endpoint, capability/health input.
- `client-agent/client_agent.py`: watch trigger, artifact verification, atomic pathd generation, service/status lifecycle.
- `slave-agent/slave_agent.py`: lease-fenced desired lines and flat relay placement.

---

### Task 1: Add The Inert Lease, Revision, And Egress Schema

**Files:**
- Create: `master/migrations/20260806_add_linux_hybrid_leigod_aggregation.sql`
- Modify: `master/migrations/schema.sql`
- Create: `master/tests/linux_hybrid_leigod_schema_test.php`

- [ ] **Step 1: Write the schema-shape test**

Create a text-based migration/schema test following `flexible_leigod_line_schema_test.php`. Require
these exact columns and constraints in both migration and canonical schema:

```php
$lease = tableBlock($sql, 'leigod_execution_leases', $source, $failures);
requireSnippets([
    'leigod_line_id BIGINT UNSIGNED NOT NULL PRIMARY KEY',
    'lease_id CHAR(36) NOT NULL',
    "owner_type ENUM('client_device','node') NOT NULL",
    'owner_id BIGINT UNSIGNED NOT NULL',
    'owner_device_id VARCHAR(191) NULL',
    'generation BIGINT UNSIGNED NOT NULL',
    'max_parallel_lanes TINYINT UNSIGNED NOT NULL DEFAULT 2',
    "state ENUM('pending','active','revoking') NOT NULL",
    'ready_deadline_at DATETIME NOT NULL',
    'expires_at DATETIME NOT NULL',
    'UNIQUE KEY uniq_leigod_lease_id (lease_id)',
    'CONSTRAINT fk_lel_line FOREIGN KEY (leigod_line_id) REFERENCES leigod_lines(id) ON DELETE CASCADE',
], $lease, $source . ' lease table', $failures);
```

Also require:

```text
leigod_lines.allow_node_execution default 1
leigod_lines.allow_client_execution default 0
leigod_lines.max_parallel_lanes default 2
node_leigod_line_health.lease_id and lease_generation
client_config_revisions(client_id primary key, revision, updated_at)
leigod_egress_profiles(profile_key unique, region, host, tcp_port, secret_ref, is_enabled)
forward_routes.leigod_egress_profile_id foreign key
```

Assert the migration contains no `UPDATE` enabling either rollout gate and no hard-coded physical
line IDs.

- [ ] **Step 2: Run the schema test and verify RED**

Run:

```powershell
php master/tests/linux_hybrid_leigod_schema_test.php
```

Expected: `FAIL` because the migration and tables do not exist.

- [ ] **Step 3: Add the migration and canonical schema**

Use additive, backward-compatible DDL. The migration must begin with:

```sql
ALTER TABLE leigod_lines
  ADD COLUMN allow_node_execution TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active,
  ADD COLUMN allow_client_execution TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_node_execution,
  ADD COLUMN max_parallel_lanes TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER allow_client_execution;

ALTER TABLE node_leigod_line_health
  ADD COLUMN lease_id CHAR(36) NULL AFTER leigod_line_id,
  ADD COLUMN lease_generation BIGINT UNSIGNED NULL AFTER lease_id;
```

Create the three new tables with `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`. Add the nullable route
foreign key only after creating `leigod_egress_profiles`. Do not insert leases and do not alter
current desired-line behavior.

- [ ] **Step 4: Run schema and existing migration tests**

Run serially:

```powershell
php master/tests/linux_hybrid_leigod_schema_test.php
php master/tests/flexible_leigod_line_schema_test.php
php master/tests/leigod_mode_migration_shape_test.php
```

Expected: all print `ok`.

- [ ] **Step 5: Commit**

```powershell
git add master/migrations/20260806_add_linux_hybrid_leigod_aggregation.sql master/migrations/schema.sql master/tests/linux_hybrid_leigod_schema_test.php
git commit -m "feat(leigod): add hybrid execution lease schema"
```

---

### Task 2: Implement Transactional Exclusive Execution Leases

**Files:**
- Create: `master/src/Support/LeigodExecutionLeaseService.php`
- Create: `master/tests/leigod_execution_lease_service_test.php`
- Create: `master/cli/seed-leigod-execution-leases.php`
- Create: `master/tests/seed_leigod_execution_leases_test.php`

- [ ] **Step 1: Write failing lease-domain tests**

Use a stateful fake `Database` with transaction recording. Cover these public calls and exact
outcomes:

```php
$service = new LeigodExecutionLeaseService($config, $db, static fn(): int => 1_786_000_000);

$lease = $service->acquireForClientDevice(10, 20, 'linux-device-a');
assertSame('client_device', $lease['owner_type']);
assertSame('linux-device-a', $lease['owner_device_id']);
assertSame(1, $lease['generation']);
assertSame('pending', $lease['state']);

$same = $service->acquireForClientDevice(10, 20, 'linux-device-a');
assertSame($lease['lease_id'], $same['lease_id']);
assertSame(1, $same['generation']);

expectApiException(
    fn() => $service->acquireForNode(10, 15),
    'leigod_line_lease_conflict'
);

$active = $service->acceptHealth(10, $lease['lease_id'], 1, [
    'owner_type' => 'client_device',
    'owner_id' => 20,
    'owner_device_id' => 'linux-device-a',
    'config_sha256' => str_repeat('a', 64),
    'healthy' => true,
]);
assertSame('active', $active['state']);
```

Also test stale generation rejection, owner mismatch, pending timeout, active sticky renewal,
non-preemption, explicit revocation, expiry takeover with generation increment, owner-type catalog
policy, and lane clamp `1..4`.

- [ ] **Step 2: Run the lease tests and verify RED**

```powershell
php master/tests/leigod_execution_lease_service_test.php
```

Expected: class-not-found failure.

- [ ] **Step 3: Implement the service**

The class must expose typed boundaries:

```php
final class LeigodExecutionLeaseService
{
    public function acquireForNode(int $catalogLineId, int $nodeId): array;
    public function acquireForClientDevice(int $catalogLineId, int $clientId, string $deviceId): array;
    public function allocateForClientDevice(int $clientId, string $deviceId, array $candidateCatalogIds, int $desiredCount): array;
    public function renew(string $leaseId, int $generation, string $ownerType, int $ownerId, ?string $deviceId): array;
    public function acceptHealth(int $catalogLineId, string $leaseId, int $generation, array $report): array;
    public function revoke(string $leaseId, int $generation, string $reason): array;
    public function activeForNode(int $nodeId): array;
    public function activeForClientDevice(int $clientId, string $deviceId): array;
    public function reapExpired(): int;
}
```

Every mutating method locks its `leigod_execution_leases` row with `SELECT * FROM
leigod_execution_leases WHERE leigod_line_id = :line_id FOR UPDATE` and performs the write inside
`Database::transaction()`.
Generate lease IDs with `Uuid::v4` equivalent bytes formatted as UUID, not sequential IDs. Use
configurable bounded defaults: pending ready `45s`, active TTL `90s`, revoking grace `15s`.
`allocateForClientDevice()` keeps existing matching leases, then locks eligible catalog rows in
stable sort order and acquires only unleased/expired rows until `desiredCount` is reached. It never
preempts a live node/client lease and returns fewer rows when spare capacity is unavailable.

- [ ] **Step 4: Add the guarded seed/audit CLI**

The CLI accepts `--dry-run` by default and requires `--apply --confirm=SEED-LEIGOD-LEASES`. It maps
currently desired node lines to explicit node leases, refuses one line desired on multiple nodes,
and prints counts only:

```text
catalog_lines=N desired_pairs=N seeded=N conflicts=N unchanged=N
```

It must not enable `LEIGOD_EXECUTION_LEASES_ENABLED` or modify `.env`.

- [ ] **Step 5: Run focused tests**

```powershell
php master/tests/leigod_execution_lease_service_test.php
php master/tests/seed_leigod_execution_leases_test.php
```

Expected: both print `ok`.

- [ ] **Step 6: Commit**

```powershell
git add master/src/Support/LeigodExecutionLeaseService.php master/cli/seed-leigod-execution-leases.php master/tests/leigod_execution_lease_service_test.php master/tests/seed_leigod_execution_leases_test.php
git commit -m "feat(leigod): add fenced execution leases"
```

---

### Task 3: Make Existing Line Management Lease-Aware

**Files:**
- Modify: `master/src/Support/LeigodLineCatalogService.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/src/Controllers/NodeController.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `slave-agent/slave_agent.py`
- Test: `master/tests/node_leigod_line_management_test.php`
- Test: `master/tests/node_leigod_line_health_ingest_test.php`
- Create: `master/tests/client_leigod_line_lease_projection_test.php`
- Modify: `slave-agent/tests/test_slave_leigod_line_management.py`

- [ ] **Step 1: Add failing node projection and fencing tests**

With `LEIGOD_EXECUTION_LEASES_ENABLED=1`, assert `desiredLinesForNode(12)` includes only active or
pending leases owned by node 12 and each row contains:

```php
[
    'lease_id' => '00000000-0000-4000-8000-000000000012',
    'lease_generation' => 3,
    'lease_state' => 'active',
    'max_parallel_lanes' => 2,
]
```

Assert a node health report missing or mismatching those fields is rejected and cannot update the
stored row. With the gate off, preserve the current full eligible catalog behavior byte-for-byte.

- [ ] **Step 2: Add failing client-device projection tests**

Add a wrapper returning root-only local lease material for one authenticated device:

```php
$projection = $provisioning->desiredLeigodLineManagementForClientDevice(20, 'linux-device-a');
assertSame([451], array_column($projection['lines'], 'line_id'));
assertSame(7, $projection['lines'][0]['lease_generation']);
assertSame(hash('sha256', $toml), $projection['lines'][0]['config_sha256']);
assertFalse(isset($projection['lines'][0]['label']));
```

The projection may contain TOML and the existing session payload only in the authenticated agent
response; tenant pages and ordinary forward presenters must not include it.

The authenticated pull field is named `linux_path_leases`. It includes pending and active leases so
the local runtime can start a pending line and report readiness before a hybrid forward begins.

- [ ] **Step 3: Run focused tests and verify RED**

```powershell
php master/tests/node_leigod_line_management_test.php
php master/tests/node_leigod_line_health_ingest_test.php
php master/tests/client_leigod_line_lease_projection_test.php
python -m unittest slave-agent.tests.test_slave_leigod_line_management -v
```

Expected: new assertions fail while existing cases remain green.

- [ ] **Step 4: Implement gated projections and fenced health**

Inject/use `LeigodExecutionLeaseService` from `LeigodLineCatalogService`. Extend health persistence
with `lease_id` and `lease_generation`. The matching predicate becomes:

```php
$leaseMatches = !$leasesEnabled || (
    hash_equals((string) $desired['lease_id'], $reportedLeaseId)
    && (int) $desired['lease_generation'] === $reportedGeneration
);
```

Do not accept an unfenced report when lease enforcement is on.

Update `candidatesForForwardOnNode()` so `single`, `race`, and `bond` all filter through matching
active node leases when the gate is on. Add assertions for all three modes; gate-off candidate
order and payloads remain unchanged.

- [ ] **Step 5: Update slave reconciliation**

Require `lease_id`, positive `lease_generation`, and `lease_state` for lease-managed rows. Persist
these fields in desired state and health. On revocation, stop and remove the line only after proving
no active relay config references it; report `revoking` until cleanup completes. Gate-off payloads
retain current behavior.

- [ ] **Step 6: Run focused and adjacent regressions**

Run the four commands from Step 3, then:

```powershell
python -m unittest slave-agent.tests.test_slave_leigod_forward_publish -v
php master/tests/leigod_route_node_availability_test.php
php master/tests/publish_dedicated_leigod_candidates_test.php
```

Expected: all pass.

- [ ] **Step 7: Commit**

```powershell
git add master/src/Support/LeigodLineCatalogService.php master/src/Support/ProvisioningService.php master/src/Controllers/NodeController.php master/src/Controllers/ClientAgentController.php slave-agent/slave_agent.py master/tests/node_leigod_line_management_test.php master/tests/node_leigod_line_health_ingest_test.php master/tests/client_leigod_line_lease_projection_test.php slave-agent/tests/test_slave_leigod_line_management.py
git commit -m "feat(leigod): fence managed line execution"
```

---

### Task 4: Add Monotonic Config Revisions And Bounded Watch

**Files:**
- Create: `master/src/Support/ClientConfigRevisionService.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/src/Bootstrap.php`
- Create: `master/tests/client_config_revision_service_test.php`
- Create: `master/tests/client_config_watch_endpoint_test.php`

- [ ] **Step 1: Write failing revision tests**

Exercise `current()` and `bump()` under a fake transaction-capable database:

```php
$revisions = new ClientConfigRevisionService($db);
assertSame(0, $revisions->current(20));
assertSame(1, $revisions->bump(20));
assertSame(2, $revisions->bump(20));
assertSame(2, $revisions->current(20));
```

Assert `bumpMany([20, 20, 21])` increments each unique client once.

- [ ] **Step 2: Write failing endpoint tests**

Register and call:

```text
GET /api/client/configs/watch?revision=8&wait=1
```

Assert authentication and
`ClientOnlineSessionService::enforceFromRequest($client, $request, 'watch')` are used,
`wait` clamps to `0..25`, a changed revision returns `200 {changed:true,revision:9}`, and timeout
returns `200 {changed:false,revision:8}` without forward or secret data. Assert a non-Linux device
gets `409 unsupported_runtime_platform` while ordinary pull remains unchanged.

- [ ] **Step 3: Run and verify RED**

```powershell
php master/tests/client_config_revision_service_test.php
php master/tests/client_config_watch_endpoint_test.php
```

Expected: class/route failures.

- [ ] **Step 4: Implement revision storage and watch**

Use `INSERT INTO client_config_revisions (client_id, revision, updated_at) VALUES
(:client_id, 1, :updated_at) ON DUPLICATE KEY UPDATE revision = LAST_INSERT_ID(revision + 1),
updated_at = VALUES(updated_at)` or a locked transaction so returned revisions are monotonic.
`watch()` polls only the revision row with bounded
`usleep(250000)`, stops at deadline, and never holds a database transaction while sleeping.

Add `config_revision` to full pull and an ETag derived exactly as:

```php
$etag = '"client-' . (int) $client['id'] . '-r' . $revision . '"';
```

Use the existing route rate limiter and a feature gate `CLIENT_CONFIG_LONG_POLL_ENABLED`, default
off. Do not bump revisions for artifact-only changes; periodic pull covers them.

- [ ] **Step 5: Wire revision bumps**

Call `bump()` after the same transaction that changes a client's forward assignment or lease
projection. Add a focused helper in `ProvisioningService` rather than scattering raw SQL. Verify a
no-op renewal does not bump while new/revoked/reassigned leases do.

- [ ] **Step 6: Run tests**

```powershell
php master/tests/client_config_revision_service_test.php
php master/tests/client_config_watch_endpoint_test.php
php master/tests/client_online_device_limit_test.php
php master/tests/linux_profile_runtime_payload_regression_test.php
```

Expected: all pass.

- [ ] **Step 7: Commit**

```powershell
git add master/src/Support/ClientConfigRevisionService.php master/src/Controllers/ClientAgentController.php master/src/Bootstrap.php master/src/Support/ProvisioningService.php master/tests/client_config_revision_service_test.php master/tests/client_config_watch_endpoint_test.php
git commit -m "feat(client): add bounded config revision watch"
```

---

### Task 5: Publish A Sidecar-Independent Linux Hybrid Contract

**Files:**
- Create: `master/src/Support/LeigodHybridRuntimeService.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Create: `master/tests/leigod_linux_hybrid_contract_test.php`
- Create: `master/tests/leigod_hybrid_sidecar_independence_test.php`

- [ ] **Step 1: Write failing contract tests**

Build a LeiGod bond assigned to a Linux device with one active local lease, two active node leases,
one enabled egress profile, and a pathd capability report. Assert:

```php
$runtime = $hybrid->forForward($forward, $client, $deviceSession, $endpoints);
assertSame('linux_pathd', $runtime['engine']);
assertSame('linux_hybrid', $runtime['placement']);
assertSame('tcp', $runtime['protocol']);
assertCount(1, $runtime['local_leases']);
assertCount(2, $runtime['slave_legs']);
assertSame(2, $runtime['local_leases'][0]['max_lanes']);
assertSame(10.0, $runtime['policy']['throughput_min_mbps']);
assertFalse(array_key_exists('bond_sidecar_point_id', $runtime));
```

Assert every logical route ID is positive and unique, local and slave physical lines are distinct,
and the session token claims the original target and `tcp` protocol.

- [ ] **Step 2: Write fallback and independence tests**

Assert all of these return no hybrid object and preserve current `slave_aggregate` publication:

```text
hybrid gate off
lease gate off
Windows/Android/unknown platform
missing or hash-mismatched pathd capability
missing active local lease
fewer than two total execution groups
missing/disabled egress profile or secret reference
non-TCP transport
```

Seed a sidecar point containing both old flags and assert changing/deleting it does not change the
hybrid contract hash.

- [ ] **Step 3: Run and verify RED**

```powershell
php master/tests/leigod_linux_hybrid_contract_test.php
php master/tests/leigod_hybrid_sidecar_independence_test.php
```

Expected: class-not-found failures.

- [ ] **Step 4: Implement the focused builder**

Expose one public method:

```php
public function forForward(
    array $forward,
    array $client,
    array $deviceSession,
    array $endpoints
): ?array;
```

Keep secret resolution in `Config`, validate host/port/profile state, call the existing bond token
builder through a narrow callable supplied by `ProvisioningService`, and sort legs deterministically
by execution group, catalog sort order, physical line, and lane.

- [ ] **Step 5: Integrate with assigned configs**

Add `path_runtime` only for the capable Linux shape. Preserve user-facing `mode=bond`, `target`,
`listen_port`, and endpoint rows. Do not pass it through dedicated-LeiGod canonicalization that
forces `single`. Add `desired_pathd_version` and `desired_pathd_sha256` to client metadata only when
the manifest entry exists.

- [ ] **Step 6: Run focused and contract regressions**

```powershell
php master/tests/leigod_linux_hybrid_contract_test.php
php master/tests/leigod_hybrid_sidecar_independence_test.php
php master/tests/leigod_fixed_endpoint_bond_contract_test.php
php master/tests/provisioning_leigod_dedicated_contract_regression_test.php
php master/tests/profile_linux_schema_regression_test.php
```

Expected: all pass.

- [ ] **Step 7: Commit**

```powershell
git add master/src/Support/LeigodHybridRuntimeService.php master/src/Support/ProvisioningService.php master/src/Controllers/ClientAgentController.php master/tests/leigod_linux_hybrid_contract_test.php master/tests/leigod_hybrid_sidecar_independence_test.php
git commit -m "feat(leigod): publish linux hybrid runtime contract"
```

---

### Task 6: Export The Bond Runtime And Add Multi-Forward `leikwan-pathd`

**Files:**
- Create: `wstunnel-main/leikwan-bond/src/lib.rs`
- Create: `wstunnel-main/leikwan-bond/src/pathd.rs`
- Create: `wstunnel-main/leikwan-bond/src/bin/leikwan-pathd.rs`
- Modify: `wstunnel-main/leikwan-bond/src/main.rs`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`
- Modify: `wstunnel-main/leikwan-bond/Cargo.toml`

- [ ] **Step 1: Add failing desired-generation tests**

Define the exact config boundary in `pathd.rs`:

```rust
#[derive(Clone, Debug, Deserialize, PartialEq)]
pub struct DesiredState {
    pub generation: u64,
    pub provider: ProviderSpec,
    pub forwards: Vec<ForwardSpec>,
    pub local_leases: Vec<LocalLeaseSpec>,
}

#[derive(Clone, Debug, Deserialize, PartialEq)]
pub struct ForwardSpec {
    pub forward_id: u64,
    pub listen: SocketAddr,
    pub egress: SocketAddr,
    pub session_token: String,
    pub legs: Vec<DesiredLegSpec>,
    pub policy: SessionConfigWire,
}

#[derive(Clone, Debug, Deserialize, PartialEq)]
pub struct LocalLeaseSpec {
    pub line_id: u32,
    pub lease_id: String,
    pub lease_generation: u64,
    pub max_lanes: u8,
    pub config_sha256: String,
    pub config_toml: SecretString,
    pub expires_at_unix: i64,
    pub socks_addr: SocketAddr,
}

#[derive(Clone, Debug, Deserialize, PartialEq)]
pub struct DesiredLegSpec {
    pub uri: String,
    pub execution_group: String,
    pub line_id: u32,
    pub lease_id: String,
    pub lease_generation: u64,
    pub leg_route_id: u32,
}

#[derive(Clone, Debug, Deserialize, PartialEq)]
pub struct ProviderSpec {
    pub binary: PathBuf,
    pub session_json: SecretString,
    pub session_sha256: String,
}
```

`SecretString` is a small redacted wrapper whose `Debug` and `Display` implementations always emit
`[redacted]`. `SessionConfigWire` contains the existing bounded numeric/admission fields and has one
checked conversion into `SessionConfig`; it does not create new scheduler defaults.

Tests must reject generation zero, duplicate forward IDs/listeners/route IDs, non-loopback local
SOCKS endpoints, public provider binds, fewer than two execution groups, invalid lease fences,
empty/expired tokens, and out-of-range policy values.

- [ ] **Step 2: Run focused Rust tests and verify RED**

```powershell
wsl -e bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main/leikwan-bond && cargo test pathd::tests -- --nocapture'
```

Expected: module/type compilation failures.

- [ ] **Step 3: Export existing modules without duplicating code**

Create `lib.rs`:

```rust
pub mod frame;
pub mod pathd;
pub mod session;
pub mod strategy;
pub mod token;
pub mod transport;
```

Change `main.rs` to import those modules from `leikwan_bond`. Move CLI-to-`SessionConfig` conversion
into a shared constructor so the original binary and pathd use identical bounds/defaults.

- [ ] **Step 4: Add cancellable listener ownership**

Add `tokio-util = { version = "0.7", features = ["rt"] }`. Extend the listener API with a
`CancellationToken` and track spawned session tasks. Cancellation stops accepting, cancels or
boundedly drains owned sessions, and closes the listener. Existing CLI passes a never-cancelled
token so behavior stays compatible.

- [ ] **Step 5: Implement reconcile lifecycle**

`PathDaemon::apply(desired)` validates the complete generation before mutation, starts changed/new
listeners, swaps the current map only after readiness, then cancels removed/old listeners. A failed
apply retains the previous generation. Write status atomically as:

```json
{"generation":42,"status":"ready","forwards":{"17":{"listener":"ready","sessions":0}}}
```

The binary accepts only:

```text
leikwan-pathd --config /run/leikwan-pathd/desired.json --status /run/leikwan-pathd/status.json
```

It handles `SIGHUP`, `SIGTERM`, and `SIGINT` and never logs session tokens or local lease payloads.

- [ ] **Step 6: Run focused and full Rust tests**

```powershell
wsl -e bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main/leikwan-bond && cargo test pathd::tests -- --nocapture'
wsl -e bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main/leikwan-bond && RUSTFLAGS=-Awarnings cargo test'
```

Expected: all tests pass; commands run sequentially.

- [ ] **Step 7: Commit**

```powershell
git add wstunnel-main/leikwan-bond/Cargo.toml wstunnel-main/leikwan-bond/src/lib.rs wstunnel-main/leikwan-bond/src/main.rs wstunnel-main/leikwan-bond/src/session.rs wstunnel-main/leikwan-bond/src/pathd.rs wstunnel-main/leikwan-bond/src/bin/leikwan-pathd.rs
git commit -m "feat(bond): add multi-forward path daemon"
```

---

### Task 7: Manage Client-Local Leased Line Children In `pathd`

**Files:**
- Create: `wstunnel-main/leikwan-bond/src/pathd_line.rs`
- Modify: `wstunnel-main/leikwan-bond/src/lib.rs`
- Modify: `wstunnel-main/leikwan-bond/src/pathd.rs`
- Modify: `wstunnel-main/leikwan-bond/src/bin/leikwan-pathd.rs`

- [ ] **Step 1: Write failing lease material and child lifecycle tests**

Use temporary directories and a fake executable. Assert:

```rust
let runtime = LineRuntime::start(&lease, &paths, &fake_clock).await?;
assert_eq!(0o600, mode(paths.lease_toml(&lease.lease_id)));
assert!(runtime.socks_addr().ip().is_loopback());
assert_eq!(lease.config_sha256, sha256_file(paths.lease_toml(&lease.lease_id))?);

runtime.stop(Duration::from_secs(5)).await?;
assert!(!paths.lease_toml(&lease.lease_id).exists());
assert!(!process_is_alive(runtime.pid()));
```

Test missing session/config, SHA mismatch, expired lease, stale generation, duplicate physical line,
child early exit, capped restart backoff, and config redaction in `Debug`/errors.

- [ ] **Step 2: Verify RED**

```powershell
wsl -e bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main/leikwan-bond && cargo test pathd_line::tests -- --nocapture'
```

Expected: module/type failures.

- [ ] **Step 3: Implement root-only runtime files and children**

Write lease files under the configured `/run/leikwan-pathd/leases` root with create-new temporary
files, `0600`, fsync, rename, then hash verification. Spawn the existing provider executable
without placing secrets in argv:

```rust
Command::new(&spec.provider_bin)
    .arg("up")
    .arg("--session")
    .arg(paths.session_path())
    .arg("--config")
    .arg(paths.lease_toml(&lease.lease_id))
    .spawn()?;
```

Keep each child in pathd's systemd cgroup. Allocate collision-checked loopback SOCKS ports from a
bounded configured range; do not use a public bind and do not expose credentials in argv.

- [ ] **Step 4: Reconcile leases before listeners**

Start/verify every required local line before making a new forward generation ready. On reload,
reuse an unchanged `(physical_line, lease_id, generation, config_sha256)` child. Drain forward
sessions before stopping a no-longer-referenced child. Expiry removes the line from new sessions
immediately and starts bounded cleanup.

- [ ] **Step 5: Emit fenced health status**

Status rows contain only:

```rust
LocalLineStatus {
    line_id,
    lease_id,
    lease_generation,
    service_state,
    tcp_status,
    tcp_connect_ms,
    config_sha256,
    last_error_code,
}
```

No TOML, session JSON, token, or command line appears.

- [ ] **Step 6: Run Rust tests**

```powershell
wsl -e bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main/leikwan-bond && cargo test pathd_line::tests -- --nocapture'
wsl -e bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main/leikwan-bond && RUSTFLAGS=-Awarnings cargo test'
```

Expected: all pass serially.

- [ ] **Step 7: Commit**

```powershell
git add wstunnel-main/leikwan-bond/src/pathd_line.rs wstunnel-main/leikwan-bond/src/lib.rs wstunnel-main/leikwan-bond/src/pathd.rs wstunnel-main/leikwan-bond/src/bin/leikwan-pathd.rs
git commit -m "feat(pathd): manage local leased line runtimes"
```

---

### Task 8: Join Local And Slave Legs In One Flat Bond Session

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/transport.rs`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs`
- Modify: `wstunnel-main/leikwan-bond/src/pathd.rs`
- Modify: `wstunnel-main/leikwan-ingress/src/lib.rs`
- Modify: `wstunnel-main/leikwan-ingress/src/client.rs` or the current client transport module
- Test: inline Rust tests and `wstunnel-main/leikwan-bond/tests/pathd_flat_hybrid.rs`

- [ ] **Step 1: Write failing mixed-leg parser tests**

Support two explicit internal leg schemes:

```text
socks5://127.0.0.1:20451?leg_route_id=1004511&line_id=451&lease_generation=7
ingress+tcp://slave.example:7003?leg_route_id=1004531&endpoint_id=316&route_key=fixed&auth_ref=slave-316
```

Reject inline auth tokens in URIs, duplicate route IDs, missing metadata, public SOCKS hosts, unknown
schemes, and target overrides. Secrets are resolved from root-only desired-state maps by opaque ref.

- [ ] **Step 2: Write a failing real-process flat hybrid test**

Start:

1. a real `leikwan-bond server` with a signed target token;
2. one fake local SOCKS relay to that server;
3. one real authenticated `leikwan-ingress` backend representing a slave relay;
4. `leikwan-pathd` with one local and one slave execution group;
5. one ordinary TCP client socket and one echo/stream target.

Assert one target connection, both route IDs acknowledged, upload bytes delivered once in order,
Reverse bytes delivered once in order, and pathd owns one public listener.

- [ ] **Step 3: Verify RED**

```powershell
wsl -e bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main && cargo test -p leikwan-bond --test pathd_flat_hybrid -- --nocapture'
```

Expected: unsupported schemes/integration failure.

- [ ] **Step 4: Reuse authenticated ingress as a library**

Expose an async `connect_authenticated(remote, route_key, auth_token)` from `leikwan-ingress`
without changing the binary CLI. Add it as a path dependency of `leikwan-bond`. Do not spawn one
ingress process per leg.

- [ ] **Step 5: Add SOCKS and ingress transports**

Implement SOCKS5 CONNECT in `transport.rs` with bounded handshake reads and connect timeout. Both
schemes return the same `AsyncRead + AsyncWrite` leg abstraction already consumed by the bond
session. Preserve route identity in the existing LBND hello. The only remote target remains the
egress aggregator from the signed session token.

- [ ] **Step 6: Prove existing scheduler semantics on mixed legs**

Add deterministic tests that a local low-RTT 5 Mbps lane remains interactive-only, a 150 Mbps
slave lane receives bulk, a sub-10 Mbps lane is quarantined from bulk after configured windows,
and Reverse decisions use server-side delivery observations. Reuse existing admission helpers;
do not add a pathd-specific weighting algorithm.

- [ ] **Step 7: Run focused and full Rust suites**

```powershell
wsl -e bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main && cargo test -p leikwan-bond --test pathd_flat_hybrid -- --nocapture'
wsl -e bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main && RUSTFLAGS=-Awarnings cargo test -p leikwan-bond'
wsl -e bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main && RUSTFLAGS=-Awarnings cargo test -p leikwan-ingress'
```

Expected: all pass serially.

- [ ] **Step 8: Commit**

```powershell
git add wstunnel-main/leikwan-bond wstunnel-main/leikwan-ingress
git commit -m "feat(pathd): bond local and slave leigod legs"
```

---

### Task 9: Add The Flat Hybrid Slave Placement

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `slave-agent/slave_agent.py`
- Create: `slave-agent/tests/test_leigod_linux_hybrid_runtime.py`
- Modify: `slave-agent/tests/test_leigod_server_side_bond_runtime.py`
- Create: `master/tests/leigod_linux_hybrid_slave_publish_test.php`

- [ ] **Step 1: Write failing panel payload tests**

For a hybrid-capable client, assert each selected slave receives:

```json
{
  "leigod_forward": {
    "mode": "bond",
    "target": "198.51.100.25:5210",
    "candidates": [{"line_id": 451, "leg_route_id": 1004511, "lease_id": "00000000-0000-4000-8000-000000000451", "lease_generation": 7}]
  },
  "hybrid_relay": {"placement": "linux_hybrid"},
  "server_side_bond": null
}
```

Assert the public slave port stays in `7000..9000`, the relay remains loopback-only, and no sidecar
point config is read.

- [ ] **Step 2: Write failing slave validation/lifecycle tests**

Accept only candidates whose physical line is currently desired with matching node lease ID and
generation. Reject stale lease, client-owned lease, duplicate route, target mismatch, non-TCP
carrier, or any `server_side_bond` object combined with `linux_hybrid`.

Assert publish starts relay+ingress but zero slave-local `leikwan-bond client` units. Mode change,
suspend, destroy, and lease revocation remove only the hybrid relay state and leave unrelated line
leases untouched.

- [ ] **Step 3: Run and verify RED**

```powershell
php master/tests/leigod_linux_hybrid_slave_publish_test.php
python -m unittest slave-agent.tests.test_leigod_linux_hybrid_runtime -v
```

Expected: payload/validation failures.

- [ ] **Step 4: Implement panel and slave placement**

Add `aggregation_placement=linux_hybrid` as a strict branch separate from `slave_sidecar`. Keep
legacy server-side bond generation unchanged for fallback. Extend relay candidate metadata with
lease fence and validate it against desired state before activation.

- [ ] **Step 5: Run focused and adjacent tests**

```powershell
php master/tests/leigod_linux_hybrid_slave_publish_test.php
python -m unittest slave-agent.tests.test_leigod_linux_hybrid_runtime -v
python -m unittest slave-agent.tests.test_leigod_server_side_bond_runtime -v
python -m unittest slave-agent.tests.test_slave_leigod_forward_publish -v
php master/tests/bond_leigod_leg_cooldown_publish_test.php
```

Expected: all pass.

- [ ] **Step 6: Commit**

```powershell
git add master/src/Support/ProvisioningService.php master/tests/leigod_linux_hybrid_slave_publish_test.php slave-agent/slave_agent.py slave-agent/tests/test_leigod_linux_hybrid_runtime.py slave-agent/tests/test_leigod_server_side_bond_runtime.py
git commit -m "feat(slave): publish flat hybrid leigod relays"
```

---

### Task 10: Reconcile One System `leikwan-pathd` From `client-agent`

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/install_forward_runtime.py`
- Create: `client-agent/tests/test_client_agent_config_watch.py`
- Create: `client-agent/tests/test_client_agent_pathd_runtime.py`
- Modify: `client-agent/tests/test_leigod_only_runtime_contract.py`

- [ ] **Step 1: Write failing watch-trigger tests**

Inject a fake HTTP client and clock. Assert one daemon watch thread sends revision/device headers,
sets a `threading.Event` on change, backs off `1,2,4,8,16,30s` on failure, and never calls
`sync_once()` itself. The main loop consumes the event and performs the only reconcile. Assert a
full pull still occurs every configured 30 seconds with bounded jitter when watch is silent/down.

- [ ] **Step 2: Write failing pathd generation tests**

Given two hybrid forwards, assert `client-agent` writes one root-only desired JSON containing both,
one `leikwan-pathd.service`, and no per-forward hybrid service. Verify write-temp, fsync, `0600`,
rename, then `systemctl kill -s HUP leikwan-pathd.service` ordering.

Assert a pull containing only pending `linux_path_leases` still writes/starts pathd with zero
forwards so provider readiness can be reported. Active leases join `path_runtime` forwards only
after the panel accepts their fenced health.

Assert a malformed contract, missing/hash-mismatched binary, duplicate listener, stale lease, or
pathd status rejection leaves the previous generation and existing listeners untouched.

- [ ] **Step 3: Verify RED**

```powershell
python -m unittest client-agent.tests.test_client_agent_config_watch -v
python -m unittest client-agent.tests.test_client_agent_pathd_runtime -v
```

Expected: missing watch/pathd behavior.

- [ ] **Step 4: Implement the watch trigger**

Add one daemon thread created once in `ClientAgent.run()`. It calls the watch endpoint with a socket
timeout slightly above 25 seconds and updates only `config_changed_event` plus observed revision.
Shutdown/tests use a stop event. The existing main loop checks the event at least every second,
while health/watchdog cadence remains independent.

- [ ] **Step 5: Implement pathd desired-state reconciliation**

Collect all `path_runtime.engine=linux_pathd` forwards before the per-forward renderer. Build one
deterministically sorted desired file under `/run/leikwan-pathd`. Install a unit with:

```ini
[Service]
Type=simple
ExecStart=/usr/local/bin/leikwan-pathd --config /run/leikwan-pathd/desired.json --status /run/leikwan-pathd/status.json
Restart=on-failure
RestartSec=3
KillMode=control-group
NoNewPrivileges=true
ProtectHome=true
```

The unit may write only its `/run/leikwan-pathd` directory. Keep provider children compatible with
required networking and executable reads; do not apply a sandbox flag that blocks them.

- [ ] **Step 6: Add capability and health reporting**

Heartbeat reports pathd artifact SHA/version, applied generation, bounded forward status, and
fenced local line health. It never reports desired JSON, provider TOML, session payload, or tokens.

- [ ] **Step 7: Run focused and broad client tests**

```powershell
python -m unittest client-agent.tests.test_client_agent_config_watch -v
python -m unittest client-agent.tests.test_client_agent_pathd_runtime -v
python -m unittest client-agent.tests.test_leigod_only_runtime_contract -v
python -m unittest discover -s client-agent/tests -p 'test_*runtime*.py' -v
```

Expected: all pass serially.

- [ ] **Step 8: Commit**

```powershell
git add client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/tests/test_client_agent_config_watch.py client-agent/tests/test_client_agent_pathd_runtime.py client-agent/tests/test_leigod_only_runtime_contract.py
git commit -m "feat(client): reconcile the linux path daemon"
```

---

### Task 11: Distribute And Pin The New Artifact

**Files:**
- Modify: `wstunnel-main/leikwan-bond/Cargo.toml`
- Create: `artifacts/wstunnel/leikwan-pathd_linux_amd64` (release output)
- Modify: `artifacts/versions.json`
- Modify: `master/src/Controllers/ArtifactController.php`
- Modify: `master/src/Support/LeigodOnlyArtifactSourceBuildMap.php`
- Modify: `master/src/Support/LeigodOnlyArtifactConsistencyService.php`
- Modify: `install/install-client-agent.sh`
- Modify: `client-agent/tests/test_client_agent_installer_helper.py`
- Modify: `client-agent/tests/test_artifact_versions_consistency.py`
- Create: `master/tests/leigod_pathd_artifact_distribution_test.php`
- Modify mirrors: `artifacts/client-agent.py`, `artifacts/install-forward-runtime.py`, `artifacts/slave-agent.py`

- [ ] **Step 1: Write failing distribution and pin tests**

Assert manifest key `leikwan_pathd_linux_amd64` has version, source path, route, SHA256, and size;
ArtifactController serves the route; source/build provenance includes the new Rust sources; the
client installer downloads atomically, verifies the manifest hash, installs mode `0755`, and writes
`pathd_bin=/usr/local/bin/leikwan-pathd`.

Assert client metadata advertises desired version/hash and hybrid activation fails closed if the
reported installed hash differs.

- [ ] **Step 2: Verify RED**

```powershell
php master/tests/leigod_pathd_artifact_distribution_test.php
python -m unittest client-agent.tests.test_client_agent_installer_helper -v
python -m unittest client-agent.tests.test_artifact_versions_consistency -v
```

Expected: missing artifact failures.

- [ ] **Step 3: Bump versions before building**

Bump `leikwan-bond` and `leikwan-pathd` together to the next patch version after `0.1.34`. Bump
client and slave agent versions because their wire/management contracts change. Update tests that
assert exact release versions.

- [ ] **Step 4: Build one static release serially**

Use the repo's established musl build environment. Run only one Cargo process:

```powershell
wsl -e bash -lc 'cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main && cargo build --release --target x86_64-unknown-linux-musl -p leikwan-bond --bins'
```

Copy `leikwan-bond` and `leikwan-pathd` outputs atomically into `artifacts/wstunnel/`, then update
manifest SHA256 and byte sizes from the actual files. Do not reuse an old binary with a new label.

- [ ] **Step 5: Synchronize source mirrors mechanically**

Copy canonical Python sources to their artifact mirrors, then verify byte equality:

```powershell
Copy-Item client-agent/client_agent.py artifacts/client-agent.py -Force
Copy-Item client-agent/install_forward_runtime.py artifacts/install-forward-runtime.py -Force
Copy-Item slave-agent/slave_agent.py artifacts/slave-agent.py -Force
```

Update every affected manifest hash/size from bytes after copying.

- [ ] **Step 6: Run artifact and installer gates**

```powershell
php master/tests/leigod_pathd_artifact_distribution_test.php
php master/tests/artifact_versions_manifest_consistency_test.php
php master/tests/artifact_source_build_provenance_guard_test.php
python -m unittest client-agent.tests.test_client_agent_installer_helper -v
python -m unittest client-agent.tests.test_artifact_versions_consistency -v
```

Expected: all pass.

- [ ] **Step 7: Commit**

```powershell
git add wstunnel-main/leikwan-bond artifacts master/src/Controllers/ArtifactController.php master/src/Support/LeigodOnlyArtifactSourceBuildMap.php master/src/Support/LeigodOnlyArtifactConsistencyService.php install/install-client-agent.sh client-agent/tests/test_client_agent_installer_helper.py client-agent/tests/test_artifact_versions_consistency.py master/tests/leigod_pathd_artifact_distribution_test.php
git commit -m "build(pathd): publish pinned linux hybrid runtime"
```

---

### Task 12: Prove End-To-End Correctness And Compatibility Locally

**Files:**
- Create: `slave-agent/tests/test_leigod_linux_hybrid_integration.py`
- Create: `scripts/check-linux-hybrid-leigod-release.ps1`
- Modify only if failures reveal implementation defects: files from Tasks 1-11

- [ ] **Step 1: Add a real-process integration test**

The test starts real release binaries where possible and fake provider SOCKS endpoints only for
provider ownership. It must prove:

```text
one ordinary user TCP connection
one pathd process
at least one local execution group
at least one slave execution group
one egress target connection
ordered upload and Reverse delivery
sub-10 Mbps bulk exclusion and bounded probes
generation reload without dropping an unchanged forward
lease revocation removes only its lanes and child
complete cleanup leaves zero listeners/processes/temp files
```

- [ ] **Step 2: Run the integration RED/GREEN loop**

```powershell
python -m unittest slave-agent.tests.test_leigod_linux_hybrid_integration -v
```

Expected after implementation: pass. Fix only evidence-backed failures and keep fixes in focused
commits with their tests.

- [ ] **Step 3: Add a serial release gate script**

The PowerShell script stops on first failure and runs, never in parallel:

```text
git diff --check
new PHP focused tests
all tracked PHP files through php -l
focused Python suites
broad client/slave Python suites
Rust leikwan-bond tests
Rust leikwan-ingress tests
real-process hybrid integration
artifact/hash/source-mirror tests
Windows/Android contract-only regressions without rebuilding those apps
```

It writes compact timing/status output and no secrets.

- [ ] **Step 4: Run the complete local gate**

```powershell
powershell -ExecutionPolicy Bypass -File scripts/check-linux-hybrid-leigod-release.ps1
```

Expected: every stage passes; no more than one heavy child process runs at once.

- [ ] **Step 5: Run a bounded local RSS soak**

Use the integration harness for 30 minutes with two local lease children and mixed traffic. Record
initial/peak/final RSS for `client-agent`, `leikwan-pathd`, and provider children. Expected:
incremental RSS at most `128 MiB` and no monotonic growth. Store only compact JSON summary under
`artifacts/qa/` if that directory is already ignored; do not commit raw captures.

- [ ] **Step 6: Commit integration/gate files**

```powershell
git add slave-agent/tests/test_leigod_linux_hybrid_integration.py scripts/check-linux-hybrid-leigod-release.ps1
git commit -m "test(leigod): gate linux hybrid aggregation"
```

---

### Task 13: Isolated Live Capacity And Reverse-P1 Canary

**Files:**
- Update after results: `docs/superpowers/handoffs/2026-08-05-sha-tencent-hk-sidecar-forward-throughput.md`
- Create temporary remote scripts/results under a directory initialized by
  `RUN_ID="linux-hybrid-leigod-$(date -u +%Y%m%dT%H%M%SZ)"` and
  `RUN_DIR="/root/codex-benchmarks/$RUN_ID"`; remove runtime residue after summarizing.

- [ ] **Step 1: Freeze a committed release candidate**

Require clean `git status`, record committed `HEAD`, artifact versions/hashes/sizes, migration count,
and local release-gate output. Package tracked committed files only; do not deploy dirty changes.

- [ ] **Step 2: Inspect and back up live state read-only first**

Through WSL SSH hops from the authoritative local credential files, record panel migrations/gates,
selected client/slave/egress/target units, listeners, command lines, versions, restart counts, and
current forward/task counts. Back up application, database, runtime configs, units, and artifacts
before mutation. Do not access `hk-bgp-b` because it is outside this topology.

- [ ] **Step 3: Deploy isolated canary artifacts only**

Install versioned canary binaries/units on one Linux test client, selected slave(s), and the
LeiGod-specific egress. Keep both production gates off. Use loopback/canary ports and confirm slave
public ingress remains in `7000..9000`.

- [ ] **Step 4: Measure segment controls**

Run serial JSON controls for:

```text
client -> each selected slave raw TCP
client-local line -> egress, Reverse P1 and P2
each slave line -> egress, Reverse P1 and P2
egress -> v4-hkt-b.mcbar.cc:5201
one slave with internal P2 -> unchanged user endpoint, Reverse P1
```

Five 20-second normal runs per formal control; archive command, start/end time, sender/receiver
Mbps, retransmits, zero intervals, process restarts, and route snapshots.

- [ ] **Step 5: Run the flat hybrid formal gate**

Use the unchanged forwarded endpoint and target:

```bash
iperf3 -4 -c 101.133.142.65 -p 7003 -R -P 1 -t 20 --json
```

Run five normal 20-second samples after bounded warmup. Pass requires receiver median `>=400 Mbps`,
every run `>=350 Mbps`, zero zero-throughput intervals, at least one local lease, at least two
execution groups, no duplicate physical lease, no restart, and no uncontrolled session rebuild.

- [ ] **Step 6: Run exclusion, latency, and resource gates**

Cap or select one lane below `10 Mbps`; prove its bulk share converges to probe-only while the user
stream continues. Measure small-request p95 idle and during bulk; delta must be `<=15 ms` unless
the raw path independently degrades. Run the 30-minute RSS/socket/restart soak.

- [ ] **Step 7: Clean the canary completely**

Disable/delete canary assignments, converge tasks, revoke test leases, remove temporary units,
listeners, processes, uploads, and target helpers. Preserve only compact JSON summaries and hashes.
Verify production gates and `hk-bgp-b` state were never changed.

- [ ] **Step 8: Decide rollout from evidence**

If the 400 Mbps median gate fails, keep `LEIGOD_LINUX_HYBRID_ENABLED=0`, report per-segment ceilings,
and do not market 400 Mbps. Correctness code may remain deployed inert for further canaries. If all
gates pass, proceed to Task 14.

---

### Task 14: Deploy Committed HEAD, Verify Production, And Update Handoff

**Files:**
- Modify: `docs/superpowers/handoffs/2026-08-05-sha-tencent-hk-sidecar-forward-throughput.md`

- [ ] **Step 1: Prepare rollback and deploy the panel**

Create fresh application/database backups with SHA256. Deploy the committed tracked archive,
verify staged-vs-live equality, run migration status before/after, and confirm runtime `.env` hash
is unchanged unless the separately approved gate values are intentionally changed.

- [ ] **Step 2: Deploy pinned runtime artifacts atomically**

Update client/slave/egress binaries and agents with temporary files, SHA256 verification, chmod,
and rename. Restart only required units. Verify reported versions/hashes equal the manifest.

- [ ] **Step 3: Activate one production canary only after all gates pass**

Enable lease enforcement only after the seed audit is conflict-free. Enable hybrid only for the
one approved Linux assignment. Verify same user endpoint, active fenced leases, one pathd, expected
slave relays, egress target connection, health/status freshness, and zero restarts.

- [ ] **Step 4: Verify panel and runtime health**

Report migration applied/pending counts, enabled forwards, outstanding tasks, public/origin login
HTTP status, versions manifest and artifact range response, client/slave/egress unit status,
listeners, process command lines, and cleanup state. Keep all secrets redacted.

- [ ] **Step 5: Update and commit the handoff**

Append a new authoritative section containing:

```text
release commits and artifact hashes
schema/seed/gate state
exact benchmark topology and Reverse-P1 command
per-line P1/P2 controls
single-slave control
five hybrid formal results and median/min/max
automatic exclusion evidence
latency and RSS soak results
backups and rollback commands
production verification and cleanup
explicit statement that hk-bgp-b was untouched
```

Run `git diff --check`, commit the handoff, sync that committed handoff to the server, and compare
local/server SHA256.

- [ ] **Step 6: Final independent review**

Dispatch a fresh `gpt-5.6-sol` `xhigh` reviewer over the full commit range and live evidence. Resolve
all Important/Critical findings, rerun affected gates, and only then declare completion.
