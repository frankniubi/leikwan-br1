# Ultra Admin Operations and Automatic Line Selection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a discoverable Ultra admin workspace, source-driven line inventory, automatic Bilibili-assisted ingress detection, binary-owned TCP/UDP line selection, bounded client telemetry, and a six-direction Linux DNS report.

**Architecture:** The existing Panel HTTPS API remains the only control plane. Source 171 uploads signed, credential-free inventory and rotating material; the Panel stores safe line metadata and seals up to eight approved candidates; the Go runtime detects the client region, ranks candidates, forwards TCP/UDP directly, and writes bounded status consumed by the existing Python heartbeat. No independent gateway or user-facing line selector is added.

**Tech Stack:** PHP 8.3, MySQL migrations, Python 3 standard library, Go standard library, systemd, server-rendered admin HTML, PowerShell/Plink deployment, `dig` Linux canaries.

---

## Worktree Safety

This checkout contains pre-existing uncommitted work that is required by the
current Ultra baseline and unrelated work that must not be reverted or swept
into a commit. Before every commit step:

1. inspect `git diff -- <listed paths>` and `git diff --cached`;
2. stage only files whose complete diff belongs to the task;
3. when a listed file contains unrelated user changes, leave that file
   uncommitted and record it in the final change summary;
4. never reset, restore, stash, or rewrite the unrelated changes.

The commit commands below are therefore conditional on that path-level
ownership check. Tests and deployment do not depend on creating those commits.

## File Structure

New focused units:

- `master/src/Support/UltraInventoryService.php`: source inventory validation, categorization, zone/line persistence, safe admin projections, source status.
- `master/src/Support/UltraClientStatusService.php`: heartbeat status validation, opaque entry reference resolution, 24-hour session query.
- `master/migrations/20260824_add_ultra_operations.sql`: additive Ultra inventory, zone, membership, policy, and client-session status schema.
- `master/tests/ultra_inventory_contract_test.php`: inventory/category/override/default-disabled contract.
- `master/tests/ultra_client_status_contract_test.php`: bounded status and assignment validation contract.
- `master/tests/admin_ultra_operations_page_test.php`: navigation, routes, tabs, redaction, and form contract.
- `tools/ultra-client/location.go`: Bilibili parser, shared cache, file lock, zone mapping.
- `tools/ultra-client/selector.go`: bounded candidate ranking and fallback policy.
- `tools/ultra-client/status.go`: atomic credential-free runtime status.
- `tools/ultra-client/location_test.go`, `selector_test.go`, `status_test.go`: Go behavior tests.
- `docs/reports/2026-08-24-ultra-six-direction-dns-report.md`: credential-free Linux canary evidence.

Existing integration points:

- `master/src/Bootstrap.php`: routes and service construction.
- `master/src/Controllers/AdminController.php`: Ultra page rendering and safe admin actions.
- `master/src/Controllers/ClientAgentController.php`: material resolution and heartbeat status ingestion.
- `master/src/Controllers/UltraSourceController.php`: signed inventory endpoint.
- `master/src/Controllers/BaseController.php`: shared navigation.
- `master/src/Support/UltraMaterialService.php`: safe material projection and sealed candidate creation.
- `master/src/Support/LeigodTokenBroker.php`: compatibility provider reading Ultra memberships/policy.
- `master/src/Support/ProvisioningService.php`: combination persistence and assigned-forward metadata.
- `source-agent/ultra_source_agent.py`: normalized inventory upload.
- `client-agent/client_agent.py`: bounded status-file collection in existing heartbeat.
- `client-agent/install_forward_runtime.py`: runtime policy/status/cache paths and systemd writable paths.
- generated artifact mirrors and `artifacts/versions.json`.

## Task 1: Add the Additive Ultra Operations Schema

**Files:**
- Create: `master/migrations/20260824_add_ultra_operations.sql`
- Modify: `master/migrations/schema.sql`
- Test: `master/tests/ultra_operations_schema_test.php`

- [ ] **Step 1: Write the failing schema contract test**

Create a source-level regression test requiring these exact schema objects:

```php
<?php
declare(strict_types=1);

$migration = file_get_contents(__DIR__ . '/../migrations/20260824_add_ultra_operations.sql');
$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
foreach (['ultra_sources', 'ultra_zones', 'ultra_lines', 'forward_combination_ultra_lines',
          'ultra_destination_zone', 'ultra_allowed_ingress_zones_json', 'ultra_fallback_policy',
          'ultra_status_json', 'ultra_status_updated_at'] as $needle) {
    if (!str_contains((string) $migration, $needle) || !str_contains((string) $schema, $needle)) {
        fwrite(STDERR, "missing Ultra schema: {$needle}\n");
        exit(1);
    }
}
echo "ok\n";
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php master/tests/ultra_operations_schema_test.php`

Expected: failure naming the absent migration or first missing table.

- [ ] **Step 3: Implement the additive migration and canonical schema**

Create the four tables and three combination columns exactly as specified in
`docs/superpowers/specs/2026-08-24-ultra-admin-operations-and-auto-line-selection-design.md`.
Add two nullable columns to `client_device_sessions`:

```sql
ALTER TABLE client_device_sessions
    ADD COLUMN ultra_status_json JSON NULL AFTER last_heartbeat_at,
    ADD COLUMN ultra_status_updated_at DATETIME NULL AFTER ultra_status_json;
```

Seed active ingress zones `beijing`, `shanghai`, `guangzhou`, `unknown` and
destination zones `singapore`, `hong-kong`, `tokyo`, `frankfurt`,
`los-angeles`, `moscow`, `unknown` with JSON aliases and idempotent inserts.

- [ ] **Step 4: Verify GREEN and migration syntax**

Run:

```powershell
php master/tests/ultra_operations_schema_test.php
php -l master/tests/ultra_operations_schema_test.php
```

Expected: `ok` and no syntax errors.

- [ ] **Step 5: Commit only schema files**

```powershell
git add master/migrations/20260824_add_ultra_operations.sql master/migrations/schema.sql master/tests/ultra_operations_schema_test.php
git commit -m "feat: add ultra operations schema"
```

## Task 2: Implement Source Inventory and Line Categorization

**Files:**
- Create: `master/src/Support/UltraInventoryService.php`
- Create: `master/tests/ultra_inventory_contract_test.php`
- Modify: `master/src/Controllers/UltraSourceController.php`
- Modify: `master/src/Bootstrap.php`
- Modify: `source-agent/ultra_source_agent.py`
- Modify: `source-agent/tests/test_ultra_source_agent.py`

- [ ] **Step 1: Write failing PHP inventory tests**

The test must exercise a fake database/settings boundary and assert:

```php
$accepted = $service->acceptInventory('source-a', [
    'agent_version' => '2026.08.24.1',
    'inventory_fingerprint' => str_repeat('a', 64),
    'complete' => true,
    'lines' => [[
        'line_id' => 453,
        'title' => '上海-日本-BGP-层峰【主机】',
        'family_key' => '上海-日本-BGP-层峰',
        'game_id' => 6262,
        'area_id' => 11796,
        'line_type' => 8,
        'discovery_ip' => '203.0.113.20',
        'config_sha256' => str_repeat('b', 64),
    ]],
]);
assert($accepted === 1);
assert($db->line['auto_ingress_zone'] === 'shanghai');
assert($db->line['auto_destination_zone'] === 'tokyo');
assert($db->line['is_enabled'] === 0);
```

Also assert admin overrides survive recategorization, unknown titles remain
`unknown`, more than 256 entries are rejected, and absent entries in a complete
snapshot become `is_source_present=0` rather than being deleted.

- [ ] **Step 2: Run PHP tests and verify RED**

Run: `php master/tests/ultra_inventory_contract_test.php`

Expected: class/file missing failure.

- [ ] **Step 3: Implement `UltraInventoryService`**

Public API:

```php
public function acceptInventory(string $sourceId, array $payload): int;
public function markSourceContact(string $sourceId, string $kind, array $status = []): void;
public function listLinesForAdmin(array $filters, int $limit, int $offset): array;
public function listSourcesForAdmin(): array;
public function activeZones(string $role): array;
public function safeMaterialUpdate(int $lineId, array $relayIps, string $acquiredAt, string $expiresAt): void;
```

Normalize text length, literal IPs, line IDs, numeric metadata, SHA-256, and
payload count. Categorize through active `ultra_zones.aliases_json`, choosing
the longest matching alias and leaving ambiguous matches `unknown`. Preserve
admin overrides and default new rows disabled.

- [ ] **Step 4: Register the signed inventory route**

Add:

```php
$this->router->add('POST', '/api/ultra/source/inventory', [$ultraSource, 'inventory']);
```

`UltraSourceController::inventory()` must call
`UltraMaterialService::verifySourceRequest()`, validate body size before
processing, invoke `UltraInventoryService::acceptInventory()`, and return only
`ok`, `accepted`, and the normalized fingerprint.

- [ ] **Step 5: Write failing Python source-agent inventory tests**

Require `build_inventory()` to merge `index.json` and TOML numeric fields,
omit TOML/session/credential content, cap at 256, and trigger upload on startup,
fingerprint change, or 30-minute refresh.

Run: `python -m unittest source-agent/tests/test_ultra_source_agent.py`

Expected: failures for missing inventory functions.

- [ ] **Step 6: Implement bounded inventory upload**

Add source configuration defaults:

```json
{
  "inventory_path": "/opt/ultra-source/lines/index.json",
  "inventory_refresh_seconds": 1800,
  "max_inventory_lines": 256
}
```

Use the existing HMAC request path. Persist only the last inventory fingerprint
and upload timestamp in the agent state directory. Upload is one request per
cycle, not retried with the same nonce.

- [ ] **Step 7: Run inventory tests and lint**

```powershell
php master/tests/ultra_inventory_contract_test.php
php -l master/src/Support/UltraInventoryService.php
php -l master/src/Controllers/UltraSourceController.php
python -m unittest source-agent/tests/test_ultra_source_agent.py
python -m py_compile source-agent/ultra_source_agent.py
```

Expected: all pass.

- [ ] **Step 8: Commit inventory implementation**

```powershell
git add master/src/Support/UltraInventoryService.php master/tests/ultra_inventory_contract_test.php master/src/Controllers/UltraSourceController.php master/src/Bootstrap.php source-agent/ultra_source_agent.py source-agent/tests/test_ultra_source_agent.py source-agent/ultra-source-agent.example.json
git commit -m "feat: ingest ultra source inventory"
```

## Task 3: Add Ultra Combination Policy and Separate Membership

**Files:**
- Modify: `master/src/Support/ProvisioningService.php`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/tests/saveforwardcombination_leigod_mode_test.php`
- Create: `master/tests/saveforwardcombination_ultra_inventory_test.php`

- [ ] **Step 1: Write failing combination persistence tests**

Test an Ultra payload with:

```php
[
    'backend_mode' => 'ultra',
    'ultra_destination_zone' => 'tokyo',
    'ultra_allowed_ingress_zones' => ['shanghai', 'beijing'],
    'ultra_fallback_policy' => 'detected_then_any',
    'ultra_lines' => [
        ['ultra_line_id' => 10, 'enabled' => 1, 'sort_order' => 10],
        ['ultra_line_id' => 11, 'enabled' => 1, 'sort_order' => 20],
    ],
]
```

Assert only source-present, admin-enabled, destination-matching lines persist,
legacy memberships are untouched, and non-Ultra modes cannot save Ultra
policy. Invalid fallback policy returns `422`.

- [ ] **Step 2: Run and verify RED**

Run: `php master/tests/saveforwardcombination_ultra_inventory_test.php`

Expected: missing Ultra inventory membership behavior.

- [ ] **Step 3: Implement policy normalization and memberships**

Add focused methods to `ProvisioningService`:

```php
private function normalizeUltraCombinationPolicy(array $payload, string $backendMode): array;
private function saveUltraLineMemberships(int $combinationId, array $memberships): void;
public function ultraLineMetadataForAssignedForwards(array $forwards): array;
public function listUltraCombinationsForAdmin(): array;
```

For Ultra, read `forward_combination_ultra_lines`; stop reading legacy
`forward_combination_leigod_lines`. Keep compatibility fields only where the
existing database enum requires them, never as the Ultra authority.

- [ ] **Step 4: Update the Forward Combination form**

When Ultra is selected, render:

- destination zone menu;
- allowed ingress-zone checkboxes;
- fallback-policy menu;
- only discovered, source-present, admin-enabled Ultra lines;
- columns for line ID, label, ingress zone, destination zone, material state,
  and priority.

The `backend_mode=ultra` query must preselect Ultra for a new combination.

- [ ] **Step 5: Run focused combination tests**

```powershell
php master/tests/saveforwardcombination_ultra_inventory_test.php
php master/tests/saveforwardcombination_leigod_mode_test.php
php master/tests/admin_forward_combination_leigod_field_test.php
php -l master/src/Support/ProvisioningService.php
php -l master/src/Controllers/AdminController.php
```

Expected: all pass.

- [ ] **Step 6: Commit combination policy**

```powershell
git add master/src/Support/ProvisioningService.php master/src/Controllers/AdminController.php master/tests/saveforwardcombination_ultra_inventory_test.php master/tests/saveforwardcombination_leigod_mode_test.php master/tests/admin_forward_combination_leigod_field_test.php
git commit -m "feat: configure ultra inventory combinations"
```

## Task 4: Build the Dedicated Ultra Admin Workspace

**Files:**
- Modify: `master/src/Controllers/BaseController.php`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Bootstrap.php`
- Create: `master/tests/admin_ultra_operations_page_test.php`
- Modify: `master/tests/admin_nav_consistency_regression_test.php`

- [ ] **Step 1: Write failing navigation/page tests**

Require shared navigation labels/paths, the `/admin/ultra` route, views
`sessions`, `lines`, `combinations`, `source`, 24-hour state labels, pagination
caps, and absence of `credential`, `sealed_material`, relay ports, and secrets.
Update the old regression test so Forward Combinations is required rather than
listed as retired.

- [ ] **Step 2: Run and verify RED**

```powershell
php master/tests/admin_ultra_operations_page_test.php
php master/tests/admin_nav_consistency_regression_test.php
```

Expected: missing navigation and page failures.

- [ ] **Step 3: Add routes and shared navigation**

Register:

```php
$this->router->add('GET', '/admin/ultra', [$admin, 'ultraOperationsPage']);
$this->router->add('POST', '/admin/ultra/lines/{id}', [$admin, 'saveUltraLinePolicy']);
```

Add `Ultra Operations` under Operations and `Forward Combinations` under
Infrastructure in `BaseController::adminNavigationItems()`.

- [ ] **Step 4: Render the approved workspace**

Use the approved compact layout with four tabs. Query only safe projections
from `UltraInventoryService`, `ProvisioningService`, and
`UltraClientStatusService`. Default page size is 50 and maximum is 200.
Escape all labels, source titles, users, devices, addresses, and errors.

- [ ] **Step 5: Run page tests and PHP lint**

```powershell
php master/tests/admin_ultra_operations_page_test.php
php master/tests/admin_nav_consistency_regression_test.php
php master/tests/admin_forward_combination_page_test.php
php -l master/src/Controllers/BaseController.php
php -l master/src/Controllers/AdminController.php
php -l master/src/Bootstrap.php
```

Expected: all pass.

- [ ] **Step 6: Commit the admin workspace**

```powershell
git add master/src/Controllers/BaseController.php master/src/Controllers/AdminController.php master/src/Bootstrap.php master/tests/admin_ultra_operations_page_test.php master/tests/admin_nav_consistency_regression_test.php
git commit -m "feat: add ultra operations workspace"
```

## Task 5: Seal Admin-Approved Candidates with Opaque Entry References

**Files:**
- Modify: `master/src/Support/UltraMaterialService.php`
- Modify: `master/src/Support/LeigodTokenBroker.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/tests/leigod_token_broker_contract_test.php`

- [ ] **Step 1: Extend the contract test and verify RED**

Assert decrypted test material has at most eight entries containing
`entry_ref`, `ingress_zone`, and `destination_zone`; normal Panel JSON still
contains no line ID, relay port, user, or credential. Assert client-provided
`ordered_ips` does not alter server candidate order.

Run: `php master/tests/leigod_token_broker_contract_test.php`

Expected: missing entry reference/zone assertions fail.

- [ ] **Step 2: Implement candidate sealing**

Generate an entry reference as URL-safe HMAC-SHA256 over canonical scope:

```text
client_id\ndevice_hash\nforward_id\nline_id\ngeneration\nrelay_ip\ntransport
```

Use a key derived from `ULTRA_TOKEN_KEY` and the scoped grant token. Keep the
line ID only in server-side sealed scope and never in the client response.
Read candidate policy from `forward_combination_ultra_lines` and `ultra_lines`.

- [ ] **Step 3: Add safe material status updates**

After accepting source material, update only deduplicated relay IPs,
`acquired_at`, and `expires_at` in `ultra_lines` through
`UltraInventoryService::safeMaterialUpdate()`.

- [ ] **Step 4: Run contract tests and lint**

```powershell
php master/tests/leigod_token_broker_contract_test.php
php master/tests/ultra_no_slave_contract_test.php
php -l master/src/Support/UltraMaterialService.php
php -l master/src/Support/LeigodTokenBroker.php
php -l master/src/Controllers/ClientAgentController.php
```

Expected: all pass.

- [ ] **Step 5: Commit candidate contract**

```powershell
git add master/src/Support/UltraMaterialService.php master/src/Support/LeigodTokenBroker.php master/src/Controllers/ClientAgentController.php master/tests/leigod_token_broker_contract_test.php
git commit -m "feat: seal ultra line candidates"
```

## Task 6: Move Location Detection and Line Selection into the Go Runtime

**Files:**
- Create: `tools/ultra-client/location.go`
- Create: `tools/ultra-client/location_test.go`
- Create: `tools/ultra-client/selector.go`
- Create: `tools/ultra-client/selector_test.go`
- Modify: `tools/ultra-client/main.go`
- Modify: `tools/ultra-client/main_test.go`

- [ ] **Step 1: Write failing Bilibili parser/cache tests**

Use `httptest.Server` and assert:

```go
func TestParseZoneResponse(t *testing.T) {
    zone, err := parseZoneResponse(strings.NewReader(`{"code":0,"data":{"country":"中国","province":"上海","city":"上海市","isp":"电信"}}`))
    if err != nil || zone.Code != "shanghai" { t.Fatalf("zone=%+v err=%v", zone, err) }
}
```

Also test 16-KiB body cap, 2.5-second timeout configuration, 24-hour fresh
cache, seven-day stale fallback, unknown fallback, atomic mode-0600 cache, and
one shared lock winner.

- [ ] **Step 2: Write failing selector tests**

Cover `detected_only`, `detected_then_any`, `any`, maximum eight candidates,
maximum four concurrent probes, deterministic RTT tie-breaking, TCP failover,
and independent UDP choice.

Run: `Push-Location tools/ultra-client; go test ./...; Pop-Location`

Expected: undefined parser/selector failures.

- [ ] **Step 3: Implement `location.go`**

Define:

```go
type LocationResult struct {
    Country, Province, City, ISP, Code, Source string
    ObservedAt time.Time
}

func DetectLocation(ctx context.Context, cachePath, lockPath string, aliases map[string][]string) LocationResult
```

Use only `net/http`, `encoding/json`, `syscall.Flock`, bounded reads, atomic
rename, and mode `0600`. Send no client/Panel identifiers.

- [ ] **Step 4: Implement `selector.go`**

Extend sealed `Entry` with `EntryRef`, `IngressZone`, `DestinationZone`, and
stable order. Implement a four-worker bounded probe pool over at most eight
entries. Return a ranked slice and retain TCP/UDP selections independently.

- [ ] **Step 5: Integrate selection into `main.go`**

Remove dependence on Python-provided RTT order. On startup and material reload,
detect zone, rank entries, arm UDP in rank order, and use ranked TCP fallback.
Coalesce SIGHUP reload through a one-element channel. Existing sessions remain
untouched until normal close.

- [ ] **Step 6: Run Go tests and race-sensitive checks**

```powershell
Push-Location tools/ultra-client
go test ./...
go test -race ./...
Pop-Location
```

Expected: all pass. If Windows cannot execute race instrumentation, record the
toolchain limitation and run normal tests plus Linux canary race behavior.

- [ ] **Step 7: Commit binary selection**

```powershell
git add tools/ultra-client/location.go tools/ultra-client/location_test.go tools/ultra-client/selector.go tools/ultra-client/selector_test.go tools/ultra-client/main.go tools/ultra-client/main_test.go
git commit -m "feat: auto-select ultra lines in runtime"
```

## Task 7: Add Bounded Runtime Status and Heartbeat Ingestion

**Files:**
- Create: `tools/ultra-client/status.go`
- Create: `tools/ultra-client/status_test.go`
- Create: `master/src/Support/UltraClientStatusService.php`
- Create: `master/tests/ultra_client_status_contract_test.php`
- Modify: `client-agent/client_agent.py`
- Create or modify: `client-agent/tests/test_ultra_status.py`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/src/Bootstrap.php`

- [ ] **Step 1: Write failing Go status tests**

Assert atomic writes, mode `0600`, credential-free JSON, five-second throttle,
latest TCP observation, current UDP association, and no token/material fields.

- [ ] **Step 2: Implement `status.go` and integrate runtime events**

Write `/run/ultra/forward-<id>.status.json` with the exact safe shape from the
design. Update after successful TCP selection, UDP association change, reload,
and terminal failure, but no more than once per five seconds.

- [ ] **Step 3: Write failing Python collection tests**

Assert the agent reads at most 32 matching status files, rejects symlinks and
oversized files, strips unknown keys, and adds one `ultra_status` array to the
existing heartbeat.

Run:

```powershell
python -m unittest client-agent/tests/test_ultra_status.py
```

Expected: missing collector failure.

- [ ] **Step 4: Implement bounded Python collection**

Add `collect_ultra_status()` to `client_agent.py`. Read only regular root-owned
files below `/run/ultra`, maximum 32 files and 64 KiB each. Do not add a thread
or separate HTTP request.

- [ ] **Step 5: Write failing Panel ingestion tests**

Assert at most 32 statuses, assigned active Ultra forwards only, valid entry
references/IPs only, numeric/text caps, one replacement update to the device
session, and 24-hour admin query thresholds.

- [ ] **Step 6: Implement `UltraClientStatusService`**

Public API:

```php
public function ingest(int $clientId, string $deviceId, array $statuses): int;
public function sessionsForAdmin(array $filters, int $limit, int $offset): array;
public function statusCounts(): array;
```

Wire it into `ClientAgentController::heartbeat()` after assignment/session
validation and before returning `ok`.

- [ ] **Step 7: Run all status tests**

```powershell
Push-Location tools/ultra-client; go test ./...; Pop-Location
python -m unittest client-agent/tests/test_ultra_status.py
php master/tests/ultra_client_status_contract_test.php
php -l master/src/Support/UltraClientStatusService.php
php -l master/src/Controllers/ClientAgentController.php
```

Expected: all pass.

- [ ] **Step 8: Commit telemetry**

```powershell
git add tools/ultra-client/status.go tools/ultra-client/status_test.go tools/ultra-client/main.go client-agent/client_agent.py client-agent/tests/test_ultra_status.py master/src/Support/UltraClientStatusService.php master/tests/ultra_client_status_contract_test.php master/src/Controllers/ClientAgentController.php master/src/Bootstrap.php
git commit -m "feat: report ultra runtime selections"
```

## Task 8: Update Runtime Rendering and Published Artifacts

**Files:**
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/tests/test_ultra_runtime.py`
- Modify: `install/install-client-agent.sh`
- Modify: `artifacts/client-agent.py`
- Modify: `artifacts/install-forward-runtime.py`
- Modify: `artifacts/versions.json`
- Rebuild: `artifacts/ultra/ultra-client_linux_amd64`

- [ ] **Step 1: Write failing runtime-render tests**

Require config keys for status path, shared location cache/lock, fallback
policy, and aliases. Require systemd `ReadWritePaths=/run/ultra /var/lib/ultra`,
generic labels, no upstream credentials, no legacy helper, no `18443`, and no
independent listener.

- [ ] **Step 2: Run and verify RED**

```powershell
python -m unittest client-agent/tests/test_ultra_runtime.py
```

Expected: missing config/path assertions fail.

- [ ] **Step 3: Update runtime rendering and installer**

Render root-only directories/files, pass only safe policy to static config,
keep dynamic material in `/run/ultra`, and preserve token-only SIGHUP reload.
Install the new ELF with mode `0755` and create `/var/lib/ultra` mode `0700`.

- [ ] **Step 4: Build Linux ELF explicitly**

```powershell
$env:GOOS='linux'
$env:GOARCH='amd64'
$env:CGO_ENABLED='0'
Push-Location tools/ultra-client
go build -trimpath -buildvcs=false -ldflags='-s -w -buildid=' -o ../../artifacts/ultra/ultra-client_linux_amd64 .
Pop-Location
Remove-Item Env:GOOS,Env:GOARCH,Env:CGO_ENABLED
```

Verify first four bytes are `7f454c46` before updating the manifest.

- [ ] **Step 5: Regenerate mirrors and manifest metadata**

Copy the canonical Python files to artifact mirrors through the repository's
existing generation path. Update versions, SHA-256, and byte sizes for the
client agent, runtime installer, client installer, and Ultra ELF.

- [ ] **Step 6: Run artifact and runtime tests**

```powershell
python -m unittest client-agent/tests/test_ultra_runtime.py client-agent/tests/test_ultra_client.py client-agent/tests/test_ultra_renewal_schedule.py client-agent/tests/test_ultra_status.py
python -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py
php master/tests/ultra_client_artifact_distribution_test.php
php master/tests/ultra_no_slave_contract_test.php
Push-Location tools/ultra-client; go test ./...; Pop-Location
```

Expected: all pass and manifest equals local artifact hash/size.

- [ ] **Step 7: Commit artifacts**

```powershell
git add client-agent/install_forward_runtime.py client-agent/tests/test_ultra_runtime.py install/install-client-agent.sh artifacts/client-agent.py artifacts/install-forward-runtime.py artifacts/versions.json artifacts/ultra/ultra-client_linux_amd64
git commit -m "build: publish automatic ultra runtime"
```

## Task 9: Run the Full Local Verification Matrix

**Files:**
- Modify only when a failure identifies an Ultra regression.

- [ ] **Step 1: Run PHP syntax and focused contracts**

```powershell
$files = @(
  'master/src/Support/UltraInventoryService.php',
  'master/src/Support/UltraClientStatusService.php',
  'master/src/Support/UltraMaterialService.php',
  'master/src/Support/LeigodTokenBroker.php',
  'master/src/Controllers/UltraSourceController.php',
  'master/src/Controllers/ClientAgentController.php',
  'master/src/Controllers/AdminController.php',
  'master/src/Controllers/BaseController.php',
  'master/src/Bootstrap.php'
)
foreach ($file in $files) { php -l $file }
php master/tests/ultra_operations_schema_test.php
php master/tests/ultra_inventory_contract_test.php
php master/tests/saveforwardcombination_ultra_inventory_test.php
php master/tests/admin_ultra_operations_page_test.php
php master/tests/admin_nav_consistency_regression_test.php
php master/tests/leigod_token_broker_contract_test.php
php master/tests/ultra_client_status_contract_test.php
php master/tests/ultra_client_artifact_distribution_test.php
php master/tests/ultra_no_slave_contract_test.php
```

Expected: no syntax errors and every contract prints `ok`.

- [ ] **Step 2: Run Python and Go verification**

```powershell
python -m unittest client-agent/tests/test_ultra_client.py client-agent/tests/test_ultra_runtime.py client-agent/tests/test_ultra_renewal_schedule.py client-agent/tests/test_ultra_status.py source-agent/tests/test_ultra_source_agent.py
python -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py source-agent/ultra_source_agent.py
Push-Location tools/ultra-client; go test ./...; Pop-Location
```

Expected: all pass.

- [ ] **Step 3: Verify security and artifact boundaries**

```powershell
rg -n -S "ULTRA_BROKER_URL|gateway_ips|18443" master/src client-agent source-agent tools/ultra-client
git diff --check
```

Expected: only explicit negative tests/docs mention rejected legacy markers;
no whitespace errors. Verify the ELF magic/hash/size against
`artifacts/versions.json`.

## Task 10: Guarded Panel and Source Deployment

**Files:**
- Deploy exact changed files only; preserve production `.env` and server-only state.

- [ ] **Step 1: Read production state and back up exact targets**

Use host-key-pinned SSH from local authoritative environment files. Record
production hashes, pending migrations, PHP-FPM owner, active Ultra counts, and
source unit/listener state. Create timestamped backups on the Panel and 171.

- [ ] **Step 2: Stage and hash-compare Panel files**

Upload to a staging directory, compare local/staged SHA-256, then promote exact
PHP, migration, artifact, installer, and agent files. Do not replace
`master/.env`.

- [ ] **Step 3: Apply migration idempotently**

Use the existing `MigrationRunner`, confirm the new migration is applied once,
and verify all new tables/columns/indexes. Reload the verified PHP-FPM service.

- [ ] **Step 4: Deploy and verify source inventory agent**

Back up the source agent/config/unit, deploy the new script, preserve root-only
session and line files, restart `ultra-source-agent.service`, and verify a
signed inventory cycle accepts the expected count. Confirm no new listener.

- [ ] **Step 5: Run Panel HTTP canaries**

Verify:

```text
/api/health -> 200
/artifacts/versions.json -> 200
unauthenticated POST /api/ultra/source/inventory -> 401
unauthenticated POST /api/client/ultra/materials -> 401
/admin/ultra unauthenticated -> login redirect
published ELF hash/size -> manifest match
```

## Task 11: Six-Direction Linux DNS Canary and Report

**Files:**
- Create: `docs/reports/2026-08-24-ultra-six-direction-dns-report.md`

- [ ] **Step 1: Establish the canary host boundary**

Connect to `root@172.21.255.189` using the user-provided local credential.
Record OS/architecture, free ports, existing Ultra units/listeners, `dig`
availability, and baseline CPU/RSS. Back up any exact file that will be
replaced. Do not write the password to scripts, logs, docs, or repository files.

- [ ] **Step 2: Install the new Linux artifact in an isolated canary path**

Upload the verified ELF to a temporary root-owned path, compare SHA-256, and
use temporary configs/status/cache directories. Do not replace an active
production client binary until canaries pass.

- [ ] **Step 3: Prepare six temporary Panel assignments**

Create or reuse admin-only test combinations for Singapore, Hong Kong, Tokyo,
Frankfurt, Los Angeles, and Moscow. Each targets `8.8.8.8:53`, includes all
enabled matching ingress variants, and uses `detected_then_any`. Record IDs for
deterministic cleanup.

- [ ] **Step 4: Run three UDP and three TCP queries per direction**

For each isolated local port run:

```bash
dig @127.0.0.1 -p PORT google.com A +tries=1 +time=5
dig @127.0.0.1 -p PORT o-o.myaddr.l.google.com TXT +tries=1 +time=5
dig +tcp @127.0.0.1 -p PORT google.com A +tries=1 +time=5
dig +tcp @127.0.0.1 -p PORT o-o.myaddr.l.google.com TXT +tries=1 +time=5
```

Repeat until three UDP and three TCP observations per family are recorded.
Capture RCODE, answer values, query times, selected opaque status mapped by the
Panel to line ID/label, relay IP, detected zone, failover, peak CPU, and peak
RSS. Never capture or print credentials/material.

- [ ] **Step 5: Write the credential-free report**

Populate one row per direction with UDP/TCP success out of three, median query
time, answer values, selected line/relay, detected zone, CPU/RSS, timestamp,
and verdict. Preserve failures as failures.

- [ ] **Step 6: Clean up and prove cleanup**

Stop/remove temporary processes, units, listeners, configs, local binaries,
and Panel assignments created for the canary. Verify no temporary port/process
remains and production services are unchanged.

## Task 12: Final Verification and Documentation Sync

**Files:**
- Modify: `docs/superpowers/specs/2026-08-24-ultra-admin-operations-and-auto-line-selection-design.md`
- Modify: `docs/2026-08-21-leigod-token-broker-contract.md`
- Modify: `docs/reports/2026-08-24-ultra-six-direction-dns-report.md`

- [ ] **Step 1: Update implementation status and exact versions**

Change approved/pending language only after verified deployment. Record schema,
artifact version/hash, source agent version, routes, and acceptance evidence.

- [ ] **Step 2: Run fresh final verification**

Rerun the complete focused PHP/Python/Go matrix, ELF/manifest check, production
HTTP canaries, source no-listener check, `/admin/ultra` authenticated browser
QA, and canary-host cleanup check.

- [ ] **Step 3: Review requirement coverage**

Confirm every Production Acceptance item in the design has direct evidence.
List any failed direction or residual risk rather than converting it into a
completion claim.

- [ ] **Step 4: Commit final docs and report**

```powershell
git add docs/superpowers/specs/2026-08-24-ultra-admin-operations-and-auto-line-selection-design.md docs/2026-08-21-leigod-token-broker-contract.md docs/reports/2026-08-24-ultra-six-direction-dns-report.md
git commit -m "docs: record ultra operations rollout"
```
