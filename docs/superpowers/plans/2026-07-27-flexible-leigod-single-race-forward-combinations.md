# Flexible LeiGod Single/Race Forward Combinations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver flexible, health-gated, sidecar-free LeiGod line pools for pinned single routes and multi-direction race routes, then seed and verify the five initial production directions.

**Architecture:** Add a panel-side line catalog and combination membership service, distribute desired line material through authenticated node management, persist per-node protocol health, and use that health for route visibility, placement, and publish candidates. A slave-local SOCKS relay owns the real target and selects a healthy line; clients continue to enter through the existing WSS path but request an endpoint-specific loopback `runtime_target`, so no client connects directly to a line or sidecar.

**Tech Stack:** PHP 8 server-rendered panel, MySQL migrations, Python 3 asyncio slave/client helpers, systemd, existing Wstunnel/LeiGod SOCKS runtime, standalone PHP regression tests, Python `unittest`.

---

## File Map

**New panel files**

- `master/migrations/20260727_add_flexible_leigod_line_pools.sql`: additive catalog, membership, and health tables plus legacy backfill.
- `master/src/Support/LeigodLineCatalogService.php`: catalog validation, env-backed configuration readiness, memberships, desired node lines, health ingest, candidate/availability queries.
- `master/cli/seed-flexible-leigod-routes.php`: dry-run-first, idempotent initial five-direction catalog/combination/route seeder.

**New runtime files**

- `slave-agent/leigod_forward_relay.py`: sidecar-free TCP/UDP SOCKS relay with single/race policies and bounded state.
- `client-agent/leikwan_tcp_race.py`: missing client multi-endpoint TCP race helper.
- `client-agent/leikwan_udp_fanout.py`: missing bounded UDP fanout helper.

**Existing files changed together**

- `master/migrations/schema.sql`: canonical schema mirror.
- `master/src/Support/ProvisioningService.php`: membership saves, route rules, health-aware placement, client/publish contracts, republish fingerprint.
- `master/src/Controllers/AdminController.php`: line catalog and health views, membership editor, route warnings.
- `master/src/Controllers/NodeController.php`: desired line management and bounded heartbeat ingestion.
- `master/src/Controllers/BaseController.php`: admin navigation entry.
- `master/src/Controllers/ArtifactController.php`, `master/src/Bootstrap.php`: new admin and artifact routes/manifests.
- `slave-agent/slave_agent.py`, `artifacts/slave-agent.py`: management reconciliation, health probes, relay lifecycle, selection telemetry.
- `client-agent/install_forward_runtime.py`, `artifacts/install-forward-runtime.py`: endpoint `runtime_target` and delivered race/fanout helpers.
- `install/install-slave.sh`, `install/install-client-agent.sh`, `install/leikwan-slave-update`: helper delivery and hash-aware updater behavior.
- `artifacts/versions.json`, `master/src/Support/LeigodOnlyArtifactSourceBuildMap.php`: version, hash, manifest, and activation gates.

## Task 1: Add the Catalog, Membership, and Health Schema

**Files:**

- Create: `master/migrations/20260727_add_flexible_leigod_line_pools.sql`
- Modify: `master/migrations/schema.sql`
- Create: `master/tests/flexible_leigod_line_schema_test.php`

- [ ] **Step 1: Write the failing schema test**

Create a text-level regression test that requires all three tables, foreign keys, unique keys, timestamps, and the legacy backfill without any five-line constants:

```php
$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql') ?: '';
$migration = file_get_contents(__DIR__ . '/../migrations/20260727_add_flexible_leigod_line_pools.sql') ?: '';
foreach (['leigod_lines', 'forward_combination_leigod_lines', 'node_leigod_line_health'] as $table) {
    assertContains('CREATE TABLE IF NOT EXISTS ' . $table, $schema);
    assertContains('CREATE TABLE ' . $table, $migration);
}
foreach (['uniq_leigod_external_line', 'uniq_combination_leigod_line', 'PRIMARY KEY (node_id, leigod_line_id)'] as $needle) {
    assertContains($needle, $schema . $migration);
}
foreach (['447', '451', '453', '455', '456'] as $forbidden) {
    assertNotContains($forbidden, $migration);
}
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php master/tests/flexible_leigod_line_schema_test.php`

Expected: FAIL because the migration and schema tables do not exist.

- [ ] **Step 3: Add the additive migration and canonical schema**

Use these exact table contracts:

```sql
CREATE TABLE leigod_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    line_id INT UNSIGNED NOT NULL,
    label VARCHAR(191) NOT NULL,
    notes TEXT NULL,
    tcp_probe_host VARCHAR(255) NOT NULL,
    tcp_probe_port INT UNSIGNED NOT NULL,
    udp_probe_host VARCHAR(255) NULL,
    udp_probe_port INT UNSIGNED NULL,
    udp_probe_method VARCHAR(32) NULL,
    config_sha256 CHAR(64) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_leigod_external_line (line_id)
);

CREATE TABLE forward_combination_leigod_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    forward_combination_id BIGINT UNSIGNED NOT NULL,
    leigod_line_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fcll_combination FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id),
    CONSTRAINT fk_fcll_line FOREIGN KEY (leigod_line_id) REFERENCES leigod_lines(id),
    UNIQUE KEY uniq_combination_leigod_line (forward_combination_id, leigod_line_id)
);

CREATE TABLE node_leigod_line_health (
    node_id BIGINT UNSIGNED NOT NULL,
    leigod_line_id BIGINT UNSIGNED NOT NULL,
    config_sha256 CHAR(64) NOT NULL,
    service_state VARCHAR(32) NOT NULL,
    tcp_status VARCHAR(32) NOT NULL,
    tcp_connect_ms INT UNSIGNED NULL,
    udp_status VARCHAR(32) NOT NULL,
    consecutive_successes INT UNSIGNED NOT NULL DEFAULT 0,
    consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    checked_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (node_id, leigod_line_id),
    CONSTRAINT fk_nllh_node FOREIGN KEY (node_id) REFERENCES nodes(id),
    CONSTRAINT fk_nllh_line FOREIGN KEY (leigod_line_id) REFERENCES leigod_lines(id)
);
```

Backfill distinct runtime-valid legacy `forward_combinations.leigod_line_id` values into inactive catalog rows, then create memberships by joining on external `line_id`. Build generic labels as `CONCAT('Legacy line ', line_id)`; do not seed the five production IDs.

- [ ] **Step 4: Run schema tests and verify GREEN**

Run:

```powershell
php master/tests/flexible_leigod_line_schema_test.php
php master/tests/forward_routes_schema_test.php
php master/tests/leigod_mode_migration_shape_test.php
```

Expected: all print `ok`/`OK` and exit 0.

- [ ] **Step 5: Commit**

```powershell
git add master/migrations/20260727_add_flexible_leigod_line_pools.sql master/migrations/schema.sql master/tests/flexible_leigod_line_schema_test.php
git commit -m "feat: add flexible leigod line pool schema"
```

## Task 2: Implement Catalog Readiness and Flexible Memberships

**Files:**

- Create: `master/src/Support/LeigodLineCatalogService.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/leigod_line_catalog_config_state_test.php`
- Create: `master/tests/saveforwardcombination_leigod_membership_test.php`

- [ ] **Step 1: Write failing catalog readiness tests**

Test arbitrary runtime-valid IDs and all readiness outcomes with a temporary env:

```php
$toml = "server = 'example'\n";
$env = tempnam(sys_get_temp_dir(), 'lw-line-env-');
file_put_contents($env, "LEIGOD_LINE_TOML_9001=" . base64_encode($toml) . "\n");
$service = new LeigodLineCatalogService(new Config($env), $db);
$ready = $service->configurationForLine(['line_id' => 9001, 'config_sha256' => hash('sha256', $toml)]);
assertSame('ready', $ready['state']);
assertSame($toml, $ready['toml']);
assertSame('missing', $service->configurationForLine(['line_id' => 9002, 'config_sha256' => str_repeat('0', 64)])['state']);
assertSame('hash_mismatch', $service->configurationForLine(['line_id' => 9001, 'config_sha256' => str_repeat('f', 64)])['state']);
```

Also assert that `saveLine()` forces `is_active=0` for missing/mismatched config and rejects malformed ID, hash, host, port, and UDP method.

- [ ] **Step 2: Run readiness test and verify RED**

Run: `php master/tests/leigod_line_catalog_config_state_test.php`

Expected: FAIL because `LeigodLineCatalogService` is missing.

- [ ] **Step 3: Implement the catalog service core**

Create the class with this public surface:

```php
final class LeigodLineCatalogService
{
    public function __construct(private Config $config, private Database $db) {}
    public function listLines(): array;
    public function lineById(int $id): ?array;
    public function saveLine(array $payload): array;
    public function deleteLine(int $id): void;
    public function configurationForLine(array $line): array;
    public function membershipsForCombination(int $combinationId): array;
    public function saveCombinationMemberships(int $combinationId, array $payload, bool $requireReady): array;
}
```

`configurationForLine()` must strict-base64-decode `LEIGOD_LINE_TOML_{line_id}`, compute lowercase SHA-256, and return `state`, `toml`, and `actual_sha256`. `saveLine()` validates `1 <= line_id <= 45535` before any write. `deleteLine()` throws `leigod_line_in_use` when membership exists.

- [ ] **Step 4: Run readiness test and verify GREEN**

Run: `php master/tests/leigod_line_catalog_config_state_test.php`

Expected: `ok`.

- [ ] **Step 5: Write the failing membership test**

Cover deduplication, order, reuse across combinations, legacy projection, non-LeiGod clearing, transaction rollback, and republish-after-commit:

```php
$saved = $service->saveForwardCombination($base + [
    'backend_mode' => 'leigod_mode',
    'client_mode' => 'single',
    'leigod_lines' => [
        ['leigod_line_id' => 12, 'enabled' => 1, 'sort_order' => 20],
        ['leigod_line_id' => 11, 'enabled' => 1, 'sort_order' => 10],
        ['leigod_line_id' => 11, 'enabled' => 1, 'sort_order' => 99],
    ],
]);
assertSame([11, 12], membershipCatalogIds($db));
assertSame(451, persistedCombination($db)['leigod_line_id']);
assertTrue($db->republishObservedAfterCommit);
```

- [ ] **Step 6: Run membership test and verify RED**

Run: `php master/tests/saveforwardcombination_leigod_membership_test.php`

Expected: FAIL because `saveForwardCombination()` still accepts only one legacy line.

- [ ] **Step 7: Integrate transactional membership saves**

Instantiate the catalog service lazily inside `ProvisioningService` to preserve its existing constructor. Normalize `leigod_lines` before the write. Wrap the combination row, helper groups, memberships, legacy projection, and denormalized forward ingress update in one `Database::transaction()`; queue republish only after the callback returns.

Compatibility rule:

```php
$legacyLineId = (int) ($firstEnabledMembership['line_id'] ?? 0);
$normalized['leigod_line_id'] = $legacyLineId > 0 ? $legacyLineId : null;
```

When an old caller supplies only `leigod_line_id`, resolve or create its inactive catalog row and normalize it to one membership. A non-LeiGod combination deletes all memberships.

- [ ] **Step 8: Run focused tests and verify GREEN**

Run:

```powershell
php master/tests/saveforwardcombination_leigod_membership_test.php
php master/tests/saveforwardcombination_leigod_mode_test.php
php master/tests/saveforwardcombination_leigod_guard_test.php
php master/tests/forward_combination_save_update_persistence_regression_test.php
```

Expected: all exit 0.

- [ ] **Step 9: Commit**

```powershell
git add master/src/Support/LeigodLineCatalogService.php master/src/Support/ProvisioningService.php master/tests/leigod_line_catalog_config_state_test.php master/tests/saveforwardcombination_leigod_membership_test.php
git commit -m "feat: manage flexible leigod line memberships"
```

## Task 3: Add Admin Catalog UI and Route Cardinality Guards

**Files:**

- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/BaseController.php`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Create: `master/tests/admin_leigod_line_catalog_page_test.php`
- Create: `master/tests/admin_forward_combination_line_memberships_test.php`
- Create: `master/tests/saveforwardroute_cardinality_test.php`

- [ ] **Step 1: Write failing route-cardinality tests**

Assert these exact cases:

```php
assertApiError('forward_route_single_requires_one_combination', fn() => saveRoute(false, []));
assertApiError('forward_route_single_requires_one_combination', fn() => saveRoute(false, [10, 11]));
assertApiError('forward_route_race_requires_two_combinations', fn() => saveRoute(true, [10]));
assertSame(7, saveRoute(false, [10])['id']);
assertSame(7, saveRoute(true, [10, 11])['id']);
```

- [ ] **Step 2: Run and verify RED**

Run: `php master/tests/saveforwardroute_cardinality_test.php`

Expected: FAIL because current save accepts zero/one/many regardless of `race_enabled`.

- [ ] **Step 3: Add cardinality validation without weakening ingress validation**

After normalizing enabled combinations and before writes:

```php
$enabledCount = count(array_filter($combos, static fn(array $row): bool => (int) $row['enabled'] === 1));
if ($raceEnabled === 0 && $enabledCount !== 1) {
    throw new ApiException('forward_route_single_requires_one_combination', 422, ['enabled_count' => $enabledCount]);
}
if ($raceEnabled === 1 && $enabledCount < 2) {
    throw new ApiException('forward_route_race_requires_two_combinations', 422, ['enabled_count' => $enabledCount]);
}
```

- [ ] **Step 4: Run route tests and verify GREEN**

Run:

```powershell
php master/tests/saveforwardroute_cardinality_test.php
php master/tests/saveforwardroute_ingress_guard_test.php
php master/tests/forward_routes_schema_test.php
```

Expected: all pass after updating old fixtures to set `race_enabled` truthfully.

- [ ] **Step 5: Write failing admin surface tests**

Require GET/POST `/admin/leigod-lines`, a CSRF-protected delete route, readiness states without TOML rendering, ordered membership rows, and route health warning areas. Explicitly forbid `LEIGOD_SESSION_JSON_B64` and TOML bytes in rendered HTML.

- [ ] **Step 6: Run admin tests and verify RED**

Run:

```powershell
php master/tests/admin_leigod_line_catalog_page_test.php
php master/tests/admin_forward_combination_line_memberships_test.php
```

Expected: FAIL because routes and controls are absent.

- [ ] **Step 7: Implement the admin pages and controls**

Add controller methods:

```php
public function leigodLinesPage(Request $request, array $params): Response;
public function saveLeigodLine(Request $request, array $params): Response;
public function deleteLeigodLine(Request $request, array $params): Response;
```

The list displays metadata, derived readiness, active flag, TCP/UDP healthy-node counts, freshness, and last error. The edit form never renders TOML/session values. Combination rows use numeric sort controls and enable checkboxes for every catalog entry; posting preserves arbitrary membership order.

- [ ] **Step 8: Run admin tests and verify GREEN**

Run the two new tests plus:

```powershell
php master/tests/admin_forward_combination_page_test.php
php master/tests/admin_forward_combination_leigod_field_test.php
php master/tests/readable_sheet_design_system_regression_test.php
```

Expected: all pass.

- [ ] **Step 9: Commit**

```powershell
git add master/src/Bootstrap.php master/src/Controllers/BaseController.php master/src/Controllers/AdminController.php master/src/Support/ProvisioningService.php master/tests/admin_leigod_line_catalog_page_test.php master/tests/admin_forward_combination_line_memberships_test.php master/tests/saveforwardroute_cardinality_test.php
git commit -m "feat: expose flexible leigod line administration"
```

## Task 4: Distribute Desired Lines and Ingest Bounded Health

**Files:**

- Modify: `master/src/Support/LeigodLineCatalogService.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/src/Controllers/NodeController.php`
- Create: `master/tests/node_leigod_line_management_test.php`
- Create: `master/tests/node_leigod_line_health_ingest_test.php`

- [ ] **Step 1: Write failing desired-management tests**

Seed active combinations and node groups, then assert:

```php
$desired = $catalog->desiredLinesForNode(12);
assertSame([451, 453], array_column($desired, 'line_id'));
assertSame($toml451, $desired[0]['toml']);
assertArrayNotHasKey('session_json', $desired[0]);
assertSame([], $catalog->desiredLinesForNode(99));
```

The NodeController pull result must add `management.leigod_lines`; `leigod_session_json` is present only when that list or a legacy leigod sidecar uplink is non-empty.

- [ ] **Step 2: Run management test and verify RED**

Run: `php master/tests/node_leigod_line_management_test.php`

Expected: FAIL because direct combinations do not produce management lines/session.

- [ ] **Step 3: Implement desired line management**

Add:

```php
public function desiredLinesForNode(int $nodeId): array;
public function desiredLeigodLineManagementForNode(int $nodeId): array;
```

Query only active, ready memberships whose combination is `leigod_mode + single` and whose node group contains the node. Return a deterministic list capped by `LEIGOD_LINE_MANAGEMENT_CAP` (default 64) containing line ID, TOML, expected hash, and probe policy.

- [ ] **Step 4: Run management test and verify GREEN**

Run: `php master/tests/node_leigod_line_management_test.php`

Expected: `ok`.

- [ ] **Step 5: Write failing heartbeat-ingest tests**

Use a desired set of 451/453 and post valid, unrelated, duplicate, oversized, future-dated, and malformed rows. Assert only bounded desired rows upsert and errors are truncated:

```php
$result = $catalog->ingestNodeHealth(12, $payload);
assertSame(2, $result['accepted']);
assertSame(3, $result['rejected']);
assertSame('healthy', health(12, 451)['tcp_status']);
assertLessThanOrEqual(500, strlen(health(12, 451)['last_error'] ?? ''));
```

- [ ] **Step 6: Run ingest test and verify RED**

Run: `php master/tests/node_leigod_line_health_ingest_test.php`

Expected: FAIL because ingestion is absent.

- [ ] **Step 7: Implement bounded heartbeat ingestion and selection telemetry merge**

Accept at most `LEIGOD_LINE_HEARTBEAT_CAP` rows (default 64), normalize states to `starting|active|failed` and `unknown|healthy|unhealthy`, clamp counters/latency, validate desired membership and current config hash, and upsert with MySQL `ON DUPLICATE KEY UPDATE`.

In `NodeController::heartbeat()` call ingestion before reconciliation. Merge at most 128 validated `leigod_forward_selections` into `nodes.agent_config_json`, but remove both dynamic arrays from the endpoint-republish capability fingerprint. Preserve these keys in `saveNodeManagement()`.

- [ ] **Step 8: Run heartbeat tests and verify GREEN**

Run:

```powershell
php master/tests/node_leigod_line_health_ingest_test.php
php master/tests/node_heartbeat_republish_gate_regression_test.php
php master/tests/node_heartbeat_reconcile_guard_regression_test.php
```

Expected: all pass.

- [ ] **Step 9: Commit**

```powershell
git add master/src/Support/LeigodLineCatalogService.php master/src/Support/ProvisioningService.php master/src/Controllers/NodeController.php master/tests/node_leigod_line_management_test.php master/tests/node_leigod_line_health_ingest_test.php
git commit -m "feat: reconcile and observe leigod lines per node"
```

## Task 5: Gate Routes, Placement, and Publish Candidates by Health

**Files:**

- Modify: `master/src/Support/LeigodLineCatalogService.php`
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/src/Controllers/AdminController.php`
- Create: `master/tests/leigod_route_node_availability_test.php`
- Create: `master/tests/publish_dedicated_leigod_candidates_test.php`
- Modify: `master/tests/provisioning_leigod_dedicated_contract_regression_test.php`

- [ ] **Step 1: Write failing node-availability tests**

Test fresh/stale/hash-mismatched health and protocol modes. The decisive race invariant is two distinct combinations on the same node:

```php
assertSame([12], nodeIds($catalog->eligibleNodesForRoute($raceRouteId, 'tcp')));
markStale(12, 453);
assertSame([], $catalog->eligibleNodesForRoute($raceRouteId, 'tcp'));
assertFalse($catalog->nodeSupportsRoute(13, $raceRouteId, 'tcp'));
assertFalse($catalog->nodeSupportsRoute(12, $singleRouteId, 'udp'));
assertTrue($catalog->nodeSupportsRoute(12, $singleRouteId, 'tcp'));
```

- [ ] **Step 2: Run availability test and verify RED**

Run: `php master/tests/leigod_route_node_availability_test.php`

Expected: FAIL because selection ignores line health.

- [ ] **Step 3: Implement the shared eligibility query**

Add:

```php
public function routeAvailability(int $routeId, string $transport): array;
public function nodeSupportsRoute(int $nodeId, int $routeId, string $transport): bool;
public function candidatesForForwardOnNode(array $forward, int $nodeId): array;
```

Use `LEIGOD_LINE_HEALTH_MAX_AGE_SECONDS` (default 180), exact config hash, active service state, required protocol health, distinct combination count, deterministic ordering, and `FORWARD_ROUTE_RACE_CANDIDATE_CAP` capped to 8.

- [ ] **Step 4: Apply the same gate everywhere placement occurs**

Filter nodes both during initial `saveForward()` and reconciliation. Run health preflight before inserting/updating an enabled forward so `leigod_route_no_healthy_candidate` cannot leave an active forward with zero endpoints. `availableForwardRoutesForService()` attaches `availability`, `healthy_node_count`, and `unavailable_reason`; tenant save revalidates the selected transport.

- [ ] **Step 5: Run availability test and verify GREEN**

Run: `php master/tests/leigod_route_node_availability_test.php`

Expected: `ok`.

- [ ] **Step 6: Write failing publish-contract tests**

Require `leigod_forward.mode` from `race_enabled`, ordered candidates, endpoint-specific loopback runtime target, and no secret material:

```php
$payload = callPrivate($service, 'publishTaskPayloadForForward', $forward, $node, 316);
assertSame('race', $payload['leigod_forward']['mode']);
assertSame([451, 453], array_column($payload['leigod_forward']['candidates'], 'line_id'));
assertSame('127.0.0.1:48316', $payload['target']);
assertArrayNotHasKey('toml', $payload['leigod_forward']['candidates'][0]);
assertStringNotContainsString('session', json_encode($payload));
```

Use one shared `stableLeigodRelayPort(forwardId, endpointId)` in both publish and client endpoint rendering:

```php
private function stableLeigodRelayPort(int $forwardId, int $endpointId): int
{
    if ($forwardId <= 0 || $endpointId <= 0) {
        throw new ApiException('invalid_leigod_relay_identity', 500);
    }
    return 48000 + ($endpointId % 10000);
}
```

The slave checks active metadata for a collision and returns `leigod_relay_port_collision` rather than silently choosing a target the client does not know.

- [ ] **Step 7: Run publish test and verify RED**

Run: `php master/tests/publish_dedicated_leigod_candidates_test.php`

Expected: FAIL because direct publish still targets the real destination.

- [ ] **Step 8: Implement additive client and publish contracts**

For dedicated `leigod_mode + single` only:

```php
$relayPort = $this->stableLeigodRelayPort((int) $forward['id'], $endpointId);
$payload['target'] = '127.0.0.1:' . $relayPort;
$payload['leigod_forward'] = [
    'mode' => $raceEnabled ? 'race' : 'single',
    'relay_port' => $relayPort,
    'target' => $forward['target_host'] . ':' . (int) $forward['target_port'],
    'candidates' => $catalog->candidatesForForwardOnNode($forward, (int) $node['id']),
    'health_max_age_seconds' => $maxAge,
    'switch_improvement_ms' => 15,
    'minimum_hold_seconds' => 30,
    'failure_threshold' => 2,
    'recovery_success_threshold' => 2,
];
```

Add `runtime_target` to each dedicated endpoint in `buildClientConfig()`, leaving top-level `target` unchanged for user display. Add candidate IDs/hashes/states to `materialPublishPayloadFingerprint()` so a material health-set change queues a new task.

- [ ] **Step 9: Run focused contract tests and verify GREEN**

Run:

```powershell
php master/tests/publish_dedicated_leigod_candidates_test.php
php master/tests/provisioning_leigod_dedicated_contract_regression_test.php
php master/tests/publish_route_race_candidates_test.php
php master/tests/provisioning_idempotent_publish_requeue_regression_test.php
```

Expected: all pass; the old sidecar race test remains unchanged in meaning.

- [ ] **Step 10: Commit**

```powershell
git add master/src/Support/LeigodLineCatalogService.php master/src/Support/ProvisioningService.php master/src/Controllers/AdminController.php master/tests/leigod_route_node_availability_test.php master/tests/publish_dedicated_leigod_candidates_test.php master/tests/provisioning_leigod_dedicated_contract_regression_test.php
git commit -m "feat: publish only healthy leigod route candidates"
```

## Task 6: Build the Slave-Local SOCKS Relay

**Files:**

- Create: `slave-agent/leigod_forward_relay.py`
- Create: `slave-agent/tests/test_leigod_forward_relay.py`

- [ ] **Step 1: Write failing SOCKS protocol and selector tests**

Build in-process fake SOCKS5 servers and target echo servers. Cover:

The first test must exercise the complete public relay behavior, not a selector mock:

```python
async def test_single_keeps_current_candidate_until_failure(self) -> None:
    async with FakeTargetEchoServer() as target, FakeSocksServer(delay_ms=5) as primary, FakeSocksServer(delay_ms=1) as backup:
        relay = await self.start_relay(mode="single", target=target.address, candidates=[primary.candidate(10), backup.candidate(20)])
        self.assertEqual(b"one", await relay.round_trip(b"one"))
        self.assertEqual(primary.line_id, relay.selected_line_id())
        await primary.stop_accepting()
        self.assertEqual(b"two", await relay.round_trip_after_failures(b"two", count=2))
        self.assertEqual(backup.line_id, relay.selected_line_id())
```

Add equally real socket tests named `test_race_selects_lowest_latency_after_hysteresis`, `test_race_does_not_flap_inside_minimum_hold`, `test_recovered_candidate_needs_success_threshold`, `test_existing_tcp_connection_drains_on_config_reload`, `test_udp_requires_a_reply_not_only_associate_success`, and `test_empty_or_invalid_config_fails_closed`. Each test must assert the selected line and transferred bytes or explicit failure.

Use real sockets, not mocks, for SOCKS greeting, CONNECT, UDP ASSOCIATE, request/reply forwarding, and timeout behavior.

- [ ] **Step 2: Run helper tests and verify RED**

Run: `py -3 -m unittest slave-agent.tests.test_leigod_forward_relay -v`

Expected: import/file failure.

- [ ] **Step 3: Implement strict config and SOCKS primitives**

The config parser accepts only:

```python
{
    "mode": "single|race",
    "listen_host": "127.0.0.1",
    "listen_port": 48000,
    "target_host": "example",
    "target_port": 443,
    "transport": "tcp|udp|tcp+udp",
    "candidates": [{"combination_id": 1, "line_id": 451, "socks_host": "127.0.0.1", "socks_port": 20451, "sort_order": 10}],
    "policy": {"probe_interval_seconds": 15, "connect_timeout_seconds": 2, "failure_threshold": 2, "recovery_success_threshold": 2, "switch_improvement_ms": 15, "minimum_hold_seconds": 30}
}
```

Reject non-loopback listen addresses, duplicate candidates, invalid ports, over-eight candidate sets, and missing protocol candidates.

- [ ] **Step 4: Implement TCP single/race behavior**

Implement SOCKS5 no-auth negotiation and `CONNECT`, a background concurrent target-specific probe loop, sticky state, failure quarantine, recovery probation, latency hysteresis, atomic JSON reload on `SIGHUP`, and bidirectional copying with bounded connect/handshake/idle timeouts. Existing connections retain their selected stream until close.

- [ ] **Step 5: Implement truth-gated UDP behavior**

Implement SOCKS5 `UDP ASSOCIATE`, RFC 1928 UDP framing, reply validation, per-flow pinning, bounded flow expiry, and no direct fallback. A candidate is UDP-healthy only after the configured probe receives the expected response.

- [ ] **Step 6: Run helper tests and verify GREEN**

Run:

```powershell
py -3 -m unittest slave-agent.tests.test_leigod_forward_relay -v
py -3 -m py_compile slave-agent/leigod_forward_relay.py
```

Expected: all tests pass; compile exits 0.

- [ ] **Step 7: Commit**

```powershell
git add slave-agent/leigod_forward_relay.py slave-agent/tests/test_leigod_forward_relay.py
git commit -m "feat: add sidecar-free leigod relay runtime"
```

## Task 7: Integrate Desired Lines, Probes, and Relay Lifecycle into the Slave Agent

**Files:**

- Modify: `slave-agent/slave_agent.py`
- Modify: `artifacts/slave-agent.py`
- Create: `slave-agent/tests/test_slave_leigod_line_management.py`
- Create: `slave-agent/tests/test_slave_leigod_forward_publish.py`
- Modify: `slave-agent/tests/test_slave_artifact_consistency.py`

- [ ] **Step 1: Write failing management/probe tests**

Test atomic TOML/hash verification, session-before-start ordering, desired line cleanup guards, complete TCP/UDP probes, and heartbeat rows:

```python
agent.apply_management_state({"leigod_session_json": session, "leigod_lines": [line451]})
self.assertEqual(toml, (root / "lines/451.toml").read_text())
self.assertEqual(0o600, stat.S_IMODE((root / "lines/451.toml").stat().st_mode))
payload = agent.build_heartbeat_payload()
self.assertEqual(451, payload["leigod_line_health"][0]["line_id"])
self.assertNotIn("toml", json.dumps(payload).lower())
```

- [ ] **Step 2: Run management tests and verify RED**

Run: `py -3 -m unittest slave-agent.tests.test_slave_leigod_line_management -v`

Expected: FAIL because `leigod_lines` is ignored.

- [ ] **Step 3: Implement desired line reconciliation and bounded health state**

Add `apply_leigod_lines()`, `probe_leigod_line()`, `load/save_leigod_line_health()`, and cleanup methods. Use the existing `ensure_leigod_line_service()` but require exact hash before start. Missing binary/session/TOML produces explicit failed state. An absent management key is a no-op; an explicitly present empty list is authoritative only after proving no active relay config references a line.

- [ ] **Step 4: Run management tests and verify GREEN**

Run: `py -3 -m unittest slave-agent.tests.test_slave_leigod_line_management -v`

Expected: all pass.

- [ ] **Step 5: Write failing publish lifecycle tests**

Assert `publish_task()` validates the additive contract, allocates/writes an atomic relay config, renders a hardened systemd unit, rewrites Wstunnel restrict target to loopback relay, stores metadata, reports selection state, and tears everything down on suspend/destroy.

The unit must contain:

```ini
ExecStart=/usr/bin/python3 /usr/local/bin/leikwan-leigod-forward-relay.py --config /var/lib/leikwan-slave/leigod-forwards/wstunnel-svc-2-316.config.json
Restart=on-failure
RestartSec=3
StartLimitIntervalSec=60
StartLimitBurst=5
NoNewPrivileges=true
PrivateTmp=true
```

- [ ] **Step 6: Run publish tests and verify RED**

Run: `py -3 -m unittest slave-agent.tests.test_slave_leigod_forward_publish -v`

Expected: FAIL because publish ignores `leigod_forward`.

- [ ] **Step 7: Implement relay lifecycle integration**

Before rendering the Wstunnel backend, validate that `payload.target` equals the declared loopback relay, that candidate SOCKS ports match `20000 + line_id`, and that each candidate hash exists in current healthy desired state. Write config under the agent state directory, ensure the relay unit, then render the existing backend restricted to that relay. Add relay ports to collision accounting. Suspend/destroy stop/remove relay unit/config and metadata. Include current bounded selections in heartbeat.

- [ ] **Step 8: Bump, mirror, and verify**

Set `AGENT_VERSION` to the next dated version, byte-copy canonical agent to `artifacts/slave-agent.py`, then run:

```powershell
Copy-Item slave-agent\slave_agent.py artifacts\slave-agent.py -Force
py -3 -m unittest slave-agent.tests.test_slave_leigod_forward_publish slave-agent.tests.test_slave_artifact_consistency -v
```

Expected: all pass and mirror equality holds.

- [ ] **Step 9: Commit**

```powershell
git add slave-agent/slave_agent.py artifacts/slave-agent.py slave-agent/tests/test_slave_leigod_line_management.py slave-agent/tests/test_slave_leigod_forward_publish.py slave-agent/tests/test_slave_artifact_consistency.py
git commit -m "feat: realize leigod line pools on slave nodes"
```

## Task 8: Supply the Missing Client Race and Fanout Helpers

**Files:**

- Create: `client-agent/leikwan_tcp_race.py`
- Create: `client-agent/leikwan_udp_fanout.py`
- Create: `client-agent/tests/test_tcp_race_helper.py`
- Create: `client-agent/tests/test_udp_fanout_helper.py`
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `artifacts/install-forward-runtime.py`

- [ ] **Step 1: Write failing behavioral tests for the generated CLI contract**

Use real loopback servers. TCP must race connection establishment, pick the first successful target, copy bidirectionally, quarantine failures, and never duplicate application bytes. UDP must fan out a bounded repeat count, deduplicate equivalent replies, pin response source, expire flows, and reject empty targets.

- [ ] **Step 2: Run helper tests and verify RED**

Run:

```powershell
py -3 -m unittest client-agent.tests.test_tcp_race_helper -v
py -3 -m unittest client-agent.tests.test_udp_fanout_helper -v
```

Expected: module/file failure.

- [ ] **Step 3: Implement the exact existing command-line contracts**

Support:

```text
leikwan-tcp-race.py --listen HOST:PORT --targets TARGET1,TARGET2
leikwan-udp-fanout.py --listen HOST:PORT --targets TARGET1,TARGET2 --repeat N
```

Use no third-party dependencies. Clamp candidates to eight, repeat to one through three, connect/probe timeouts to bounded values, and fail closed on malformed input.

- [ ] **Step 4: Use endpoint-specific runtime targets**

In all single/race render branches use:

```python
endpoint_target = str(endpoint.get("runtime_target", target) or target)
```

The top-level target remains the user destination. Each Wstunnel command for an endpoint uses that endpoint's `runtime_target` when provided. Keep source and artifact installer bytes identical.

- [ ] **Step 5: Run tests and verify GREEN**

Run:

```powershell
py -3 -m unittest client-agent.tests.test_tcp_race_helper client-agent.tests.test_udp_fanout_helper -v
py -3 -m py_compile client-agent/leikwan_tcp_race.py client-agent/leikwan_udp_fanout.py client-agent/install_forward_runtime.py
git diff --no-index -- client-agent/install_forward_runtime.py artifacts/install-forward-runtime.py
```

Expected: tests/compile pass; final diff command reports no differences.

- [ ] **Step 6: Commit**

```powershell
git add client-agent/leikwan_tcp_race.py client-agent/leikwan_udp_fanout.py client-agent/install_forward_runtime.py artifacts/install-forward-runtime.py client-agent/tests/test_tcp_race_helper.py client-agent/tests/test_udp_fanout_helper.py
git commit -m "feat: ship client tcp race and udp fanout helpers"
```

## Task 9: Complete Artifact, Installer, Version, and Activation Delivery

**Files:**

- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/ArtifactController.php`
- Modify: `master/src/Support/LeigodOnlyArtifactSourceBuildMap.php`
- Modify: `install/install-slave.sh`
- Modify: `install/install-client-agent.sh`
- Modify: `install/leikwan-slave-update`
- Modify: `artifacts/versions.json`
- Create: `master/tests/leigod_forward_helper_artifact_distribution_test.php`
- Create: `slave-agent/tests/test_slave_leigod_helper_distribution.py`
- Create: `client-agent/tests/test_client_race_helper_distribution.py`

- [ ] **Step 1: Write failing distribution tests**

Require controller methods/routes, slave/client manifest entries, installer downloads, manifest hashes, activation source/build mapping, installed paths, and SHA-aware installer update detection for:

```text
leigod_forward_relay
tcp_race_runtime
udp_fanout_runtime
```

Also assert the checked-in `slave_installer` SHA/size match the file and that updater comparison uses desired installer SHA as well as version.

- [ ] **Step 2: Run distribution tests and verify RED**

Run:

```powershell
php master/tests/leigod_forward_helper_artifact_distribution_test.php
py -3 -m unittest slave-agent.tests.test_slave_leigod_helper_distribution client-agent.tests.test_client_race_helper_distribution -v
```

Expected: FAIL because routes, entries, and downloads do not exist.

- [ ] **Step 3: Serve canonical sources directly**

Follow the preconnect helper pattern. Add routes:

```text
/artifacts/leikwan-leigod-forward-relay.py -> slave-agent/leigod_forward_relay.py
/artifacts/leikwan-tcp-race.py             -> client-agent/leikwan_tcp_race.py
/artifacts/leikwan-udp-fanout.py           -> client-agent/leikwan_udp_fanout.py
```

Include the relay in `slaveManifest()`, both generic helpers in `clientManifest()`, and the relay in activation-critical provenance so an old helper blocks activation.

- [ ] **Step 4: Install helpers atomically and hash-aware**

Download each helper to `/usr/local/bin` through `download_atomic`, set mode 0755, and manifest-gate unchanged bytes. Bump both `INSTALLER_VERSION` and the corresponding manifest version. Update self-update logic so equal installer version plus different SHA still triggers the installer.

- [ ] **Step 5: Refresh exact manifest hashes and sizes**

Use PowerShell hashing and file lengths, then update only touched entries:

```powershell
Get-FileHash -Algorithm SHA256 slave-agent\slave_agent.py,slave-agent\leigod_forward_relay.py,client-agent\leikwan_tcp_race.py,client-agent\leikwan_udp_fanout.py,install\install-slave.sh
Get-Item slave-agent\slave_agent.py,slave-agent\leigod_forward_relay.py,client-agent\leikwan_tcp_race.py,client-agent\leikwan_udp_fanout.py,install\install-slave.sh | Select-Object Name,Length
```

- [ ] **Step 6: Run distribution and existing artifact tests**

Run:

```powershell
php master/tests/leigod_forward_helper_artifact_distribution_test.php
php master/tests/artifact_versions_manifest_route_test.php
php master/tests/installer_artifact_hash_verification_test.php
php master/tests/artifact_source_build_provenance_guard_test.php
py -3 -m unittest slave-agent.tests.test_slave_leigod_helper_distribution client-agent.tests.test_client_race_helper_distribution slave-agent.tests.test_slave_installer_helper -v
```

Expected: all pass.

- [ ] **Step 7: Commit**

```powershell
git add master/src/Bootstrap.php master/src/Controllers/ArtifactController.php master/src/Support/LeigodOnlyArtifactSourceBuildMap.php install/install-slave.sh install/install-client-agent.sh install/leikwan-slave-update artifacts/versions.json master/tests/leigod_forward_helper_artifact_distribution_test.php slave-agent/tests/test_slave_leigod_helper_distribution.py client-agent/tests/test_client_race_helper_distribution.py
git commit -m "feat: deliver leigod race runtimes atomically"
```

## Task 10: Add the Idempotent Initial Catalog and Route Seeder

**Files:**

- Create: `master/cli/seed-flexible-leigod-routes.php`
- Create: `master/tests/seed_flexible_leigod_routes_test.php`
- Create: `docs/2026-07-27-flexible-leigod-routes-operator.md`

- [ ] **Step 1: Write the failing dry-run/idempotence test**

The script defaults to dry-run and accepts `--apply`, `--node-group=shanghai-alive`, and probe target overrides. Test that it:

- validates all five env configs and exact expected hashes;
- proposes five catalog rows, five combinations, five single routes, and one race route;
- writes nothing in dry-run;
- creates/updates by stable names in apply mode;
- produces zero duplicates on a second apply;
- creates no service plan, service, purchase, or tenant binding.

- [ ] **Step 2: Run seeder test and verify RED**

Run: `php master/tests/seed_flexible_leigod_routes_test.php`

Expected: FAIL because the CLI is absent.

- [ ] **Step 3: Implement the guarded seeder**

Keep the initial data in this operator CLI, never in migration/application constants:

```php
$initial = [
    ['line_id' => 451, 'label' => 'Shanghai to Korea'],
    ['line_id' => 453, 'label' => 'Shanghai to Japan'],
    ['line_id' => 456, 'label' => 'Beijing to Russia'],
    ['line_id' => 455, 'label' => 'Beijing to Germany'],
    ['line_id' => 447, 'label' => 'Guangzhou to Hong Kong'],
];
```

Require `--apply` for writes. Use catalog/provisioning public methods and stable slugs. Leave all routes inactive unless `--activate` is explicitly supplied after health proof. Emit counts and readiness only; never emit TOML/session bytes.

- [ ] **Step 4: Run seeder test and verify GREEN**

Run: `php master/tests/seed_flexible_leigod_routes_test.php`

Expected: `ok`.

- [ ] **Step 5: Commit**

```powershell
git add master/cli/seed-flexible-leigod-routes.php master/tests/seed_flexible_leigod_routes_test.php docs/2026-07-27-flexible-leigod-routes-operator.md
git commit -m "feat: seed initial flexible leigod directions safely"
```

## Task 11: Integration Verification and Release Readiness

**Files:**

- Modify only files required by failures proven during this task.

- [ ] **Step 1: Run the complete focused PHP feature set**

```powershell
$tests = @(
  'flexible_leigod_line_schema_test.php',
  'leigod_line_catalog_config_state_test.php',
  'saveforwardcombination_leigod_membership_test.php',
  'saveforwardroute_cardinality_test.php',
  'admin_leigod_line_catalog_page_test.php',
  'admin_forward_combination_line_memberships_test.php',
  'node_leigod_line_management_test.php',
  'node_leigod_line_health_ingest_test.php',
  'leigod_route_node_availability_test.php',
  'publish_dedicated_leigod_candidates_test.php',
  'leigod_forward_helper_artifact_distribution_test.php',
  'seed_flexible_leigod_routes_test.php'
)
foreach ($test in $tests) { php (Join-Path 'master/tests' $test); if ($LASTEXITCODE) { exit $LASTEXITCODE } }
```

Expected: every test exits 0.

- [ ] **Step 2: Run complete Python feature and agent suites**

```powershell
py -3 -m unittest discover -s slave-agent/tests -p 'test_*.py' -v
py -3 -m unittest discover -s client-agent/tests -p 'test_*.py' -v
```

Expected: zero failures/errors.

- [ ] **Step 3: Run broad panel regressions and compare baseline failures**

Run every `master/tests/*.php` in a loop, record exact pass/fail counts, and reproduce any failure on pristine `master@39d1887`. Fix only new failures; report unchanged baseline failures separately.

- [ ] **Step 4: Verify syntax, mirrors, secrets, and diff**

```powershell
php -l master/src/Support/LeigodLineCatalogService.php
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/NodeController.php
php -l master/src/Controllers/AdminController.php
py -3 -m py_compile slave-agent/slave_agent.py slave-agent/leigod_forward_relay.py client-agent/leikwan_tcp_race.py client-agent/leikwan_udp_fanout.py client-agent/install_forward_runtime.py
git diff --no-index -- slave-agent/slave_agent.py artifacts/slave-agent.py
git diff --no-index -- client-agent/install_forward_runtime.py artifacts/install-forward-runtime.py
git diff --check
git status --short
git diff refs/heads/master HEAD | rg -n "LEIGOD_SESSION_JSON_B64=|password\s*=|BEGIN (RSA|OPENSSH|PRIVATE) KEY"
```

Expected: syntax/mirrors/diff checks pass; secret scan finds no newly committed secret material.

- [ ] **Step 5: Run final spec and code-quality reviews**

Review the full branch against `docs/superpowers/specs/2026-07-27-flexible-leigod-single-race-forward-combinations-design.md`. Resolve every Important/Critical finding and rerun affected verification.

- [ ] **Step 6: Commit any verified integration-only corrections**

```powershell
git add -u
git commit -m "fix: close flexible leigod integration gaps"
```

Skip this commit when no integration correction was needed.

## Task 12: Guarded Live Canary and Production Activation

**Files:**

- No repository changes unless live evidence proves a reproducible defect first.

- [ ] **Step 1: Capture backups and exact preimages**

Through WSL and the configured jump host, back up the panel database, deployed files, `.env`, and current slave agent/helpers. Record paths, SHA-256 values, service versions, and the eight-node heartbeat baseline. Never print credentials or secret blobs.

- [ ] **Step 2: Deploy panel code and migration with routes inactive**

Verify every remote preimage before replacing a file. Apply the migration once, verify all three tables, and confirm `/api/health` plus existing node heartbeat/task-pull remain healthy.

- [ ] **Step 3: Install and hash the five line configs**

Import the task-linked TOMLs into `LEIGOD_LINE_TOML_447/451/453/455/456`, preserve the shared session, and verify exact decoded hashes against the known local artifacts. Do not log values.

- [ ] **Step 4: Update one Shanghai slave canary**

Install the new agent and relay, verify systemd units, desired line files/hashes, local SOCKS listeners, heartbeat health rows, and zero new 401/task-pull errors.

- [ ] **Step 5: Prove each line independently through the canary**

Probe `162.245.221.78:80` through each line and require HTTP 200, 1,876 bytes, and the previously recorded content hash. A failed line stays inactive.

- [ ] **Step 6: Seed inactive catalog/routes and activate one single canary route**

Run the seeder dry-run, inspect counts, apply without activation, then explicitly activate one healthy pinned route. Create a temporary canary forward only when a suitable internal service exists; otherwise validate directly through the slave relay without fabricating billing/customer state.

- [ ] **Step 7: Prove single failover and race switching**

Add a second line to the canary single pool, stop the selected line, and verify bounded failover for new TCP connections. Then activate the all-direction race only when one node has two healthy combinations; stop the winner and verify a new connection exits through another direction. Restore the stopped line and verify probation/hold before readmission.

- [ ] **Step 8: Roll out remaining slaves and activate only proven routes**

Update the remaining eligible slaves in bounded batches. Recheck heartbeat, line health freshness, active candidate counts, process/socket state, and target response after each batch. Leave UDP unavailable unless a complete reply probe succeeds.

- [ ] **Step 9: Verify public/control/runtime agreement and preserve rollback evidence**

Confirm panel route state, task payload, client endpoint `runtime_target`, slave relay config, selected line telemetry, service socket, and target response describe the same route. Record backups and rollback commands in a dated handoff without secrets.
