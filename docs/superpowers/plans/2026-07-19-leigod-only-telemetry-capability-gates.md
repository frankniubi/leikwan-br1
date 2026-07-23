# LeiGod-Only Telemetry and Capability Gates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add authenticated standalone-bond runtime telemetry, role capability reports, fail-closed read-only Speed Plus eligibility computation, and operations visibility for LeiGod-only Forward runtime without enabling legacy modes, native MPTCP, cleanup, deployment, artifact publication, or runtime enforcement changes.

**Architecture:** Treat the already-approved active Forward contract as the only authoritative contract: `backend_mode=leigod_mode`, `client_mode=bond`, `runtime_preference=single`, and `bond_ingress_mode=plain_tcp_mux`. Client-device sessions authenticate telemetry with a per-session secret on top of the existing client agent bearer token; only client-device standalone-bond snapshots plus fresh accepted client capability reports can prove read-only Speed Plus eligibility. Slave and sidecar reports remain bounded capability/availability diagnostics and never feed useful-byte aggregation proof.

**Tech Stack:** PHP 8 master controllers/support services/migrations/tests, MySQL-compatible SQL schema, Python 3 client agent reporter/tests, the Task 37 standalone `leikwan-bond` snapshot contract, existing admin HTML helpers.

---

## Hard Scope and Drift Guard

This plan is for implementation planning only. It does not authorize product implementation in this session, production deployment, artifact publication, remote cleanup activation, remote service operations, commits, pushes, credential rotation, or touching unrelated files. The task steps below include test, `git add`, and `git commit` commands only as future execution instructions for a separately user-authorized implementation session; they are not authorization to implement or commit during this planning-only session.

Task 39 produces telemetry ingestion, capability reports, read-only eligibility computation, and read-only operations display only. It must not wire the eligibility result into runtime publication, config selection, artifact rollout, cleanup, or Speed Plus enforcement; that enforcement/cutover wiring belongs to Task 40 or a later separately approved implementation plan.

The implementation produced from this plan must preserve these invariants:

```text
active backend_mode = leigod_mode
active client_mode = bond
active runtime_preference = single
active bond_ingress_mode = plain_tcp_mux
internal aggregation family = standalone_bond
```

For this changeset, `runtime_preference = single` means the Linux client directly runs standalone `leikwan-bond`. It does not mean one Slave, and it does not authorize native MPTCP. Capability reports may record passive operating-system observations, but they must not select, prefer, publish, classify, or fall back through native MPTCP, Cloudflare, WSS, classic sidecar, `current_mode`, `cf_mode`, or `bond_fallback`.

Do not modify, stage, commit, delete, execute, or use as authorization:

```text
docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md
```

No cleanup service in this plan may delete systemd units, WireGuard links, WireGuard peers, ports, files, applied runtime state, endpoints, Forwards, or agent resources. Telemetry row expiration may remove only rows in telemetry tables and only through explicit database maintenance added in this plan.

## Data Flow and Trust Boundaries

```text
client-agent bearer token
    |
    v
Auth::requireClientAgentBearerOnly()
    |
    v
ClientDeviceSessionContext::requireTelemetry()
    | requires X-Device-Session-Id + X-Device-Session-Credential
    v
BondAggregationRuntimeSnapshotService
    | validates assignment generation, set hash, active_family=standalone_bond,
    | monotonic runtime_epoch/sequence, freshness <15s, size bounds, useful-byte window freshness
    v
forward_aggregation_runtime_snapshots latest lifecycle row
forward_aggregation_runtime_window_proofs latest upload/download proof rows
    |
    v
BondRuntimeEligibilityService read-only derived state + capability-gated eligibility
    |
    v
AdminController::renderBondTelemetryGateSection()

node/slave heartbeat ----> AgentCapabilityReportService ----> role capability rows
sidecar heartbeat -------> AgentCapabilityReportService ----> role capability rows
client heartbeat/pull ---> ClientDeviceSessionContext issues credential when missing

Hard wall: node/sidecar capability rows and legacy BondLegReputationService rows are diagnostics.
They never count useful bytes for Speed Plus proof in Task 39. Fresh accepted client capability rows are required for read-only eligibility, but they still cannot prove aggregation without client-device standalone-bond useful-byte proof rows.
```

## File Map

**Create:**

- `master/migrations/20260719_add_forward_aggregation_telemetry.sql` — create telemetry credential, capability, snapshot, and row-retention tables.
- `master/src/Support/ClientDeviceSessionContext.php` — issue and verify client-device session credentials without persisting plaintext.
- `master/src/Support/TelemetryPayloadSanitizer.php` — strip high-churn telemetry before durable heartbeat JSON/config merges.
- `master/src/Support/AgentCapabilityReportService.php` — persist bounded client/slave/sidecar capability reports and compare reported hashes against expected metadata supplied by controllers.
- `master/src/Support/BondRuntimeAssignmentContext.php` — immutable DTO describing one delivered standalone-bond assignment.
- `master/src/Support/BondRuntimeAssignmentRepository.php` — derive assignment context from the same Forward/client assignment sources used by config delivery.
- `master/src/Support/BondAggregationRuntimeSnapshotService.php` — authenticate, validate, reduce, and store latest client-device standalone-bond lifecycle snapshots plus direction-specific useful-byte proof rows.
- `master/src/Support/TelemetryRetentionService.php` — prune only telemetry database rows, never runtime resources.
- `master/src/Support/BondRuntimeEligibilityService.php` — read-only derived state and capability-gated result service.
- `master/tests/aggregation_runtime_telemetry_migration_shape_test.php` — schema and migration shape test.
- `master/tests/client_device_session_context_test.php` — credential issue/verify/fail-closed tests.
- `master/tests/agent_capability_report_service_test.php` — bounded capability report and expected-hash tests.
- `master/tests/bond_runtime_assignment_repository_test.php` — assignment source-of-truth, schema, hash, and combination-pointer guard tests.
- `master/tests/bond_aggregation_runtime_snapshot_service_test.php` — reducer, freshness, monotonic, direction-specific proof, and useful-byte tests.
- `master/tests/telemetry_retention_service_test.php` — telemetry-table-only retention tests.
- `master/tests/client_aggregation_runtime_endpoint_test.php` — endpoint auth, route, bounds, and response tests.
- `master/tests/telemetry_payload_sanitizer_test.php` — high-churn heartbeat stripping tests.
- `master/tests/bond_runtime_eligibility_display_test.php` — read-only admin display and gate state tests.
- `master/tests/leigod_telemetry_contract_drift_guard_test.php` — forbidden scope/source audit.
- `client-agent/aggregation_runtime_reporter.py` — latest-only in-memory reporter for the Task 37 standalone-bond snapshot contract; no plaintext telemetry credential is persisted to disk.
- `client-agent/tests/test_aggregation_runtime_reporter.py` — reporter credential, bounds, effective freshness, configured snapshot directory, independent cadence, and no-stale-replay tests.

**Modify:**

- `master/migrations/schema.sql` — mirror the new tables.
- `master/src/Bootstrap.php` — register `POST /api/client/aggregation-runtime-snapshots`.
- `master/src/Http/Request.php` — enforce bounded pre-parse reads for aggregation runtime telemetry requests.
- `master/src/Support/Auth.php` — add bearer-header-only telemetry auth and token-only auth that does not bootstrap a device session.
- `master/src/Controllers/ClientAgentController.php` — issue device-session telemetry credentials only when missing, record client capabilities, add snapshot endpoint.
- `master/src/Controllers/NodeController.php` — record bounded slave capability reports and sanitize heartbeat payload storage.
- `master/src/Controllers/SidecarAgentController.php` — record bounded sidecar capability reports and sanitize runtime payload storage.
- `master/src/Controllers/AdminController.php` — render read-only telemetry/capability gate section.
- `master/cli/cleanup-tasks.php` — invoke telemetry-table-only row retention from the existing database cleanup entrypoint.
- `client-agent/client_agent.py` — retain telemetry credential in memory only, emit client capability reports, use configured snapshot dir, and flush latest-only aggregation runtime snapshots independently of config pull cadence.
- `slave-agent/slave_agent.py` — emit bounded slave capability reports in heartbeat payloads.
- `sidecar-agent/sidecar_agent.py` — emit bounded sidecar capability reports in heartbeat payloads.

**Do not modify:**

- `wstunnel-main/leikwan-bond/**` — data-plane scheduler and snapshot writer are covered by the bond data-plane plan.
- `client-agent/leigod_bond_supervisor.py` and port lease internals — Linux supervisor ownership is covered by the supervisor plan.
- `artifacts/**` and `master/public/artifacts/**` — artifact publication and mirror consistency are covered by rollout.
- `docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md`.

---

### Task 1: Record Scope Sentinels Without Reading Protected Files

**Files:**
- Create through Git's private path resolver: `$(git rev-parse --git-path leigod-telemetry-capability-gates-baseline.json)`

- [ ] **Step 1: Record pathspec-only protected-file status and base commit**

Run from the repository root:

```bash
python - <<'PY'
import json
import subprocess
from pathlib import Path

protected_path = 'docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md'
status = subprocess.check_output(['git', 'status', '--short', '--', protected_path], text=True).strip()
baseline_path = Path(subprocess.check_output(['git', 'rev-parse', '--git-path', 'leigod-telemetry-capability-gates-baseline.json'], text=True).strip())
baseline_path.parent.mkdir(parents=True, exist_ok=True)
base_commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
baseline = {
    'protected_path': protected_path,
    'protected_status': status,
    'base_commit': base_commit,
}
baseline_path.write_text(json.dumps(baseline, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(json.dumps({'baseline_path': str(baseline_path), **baseline}, ensure_ascii=False))
PY
```

Expected: JSON prints the resolved Git-private `baseline_path`, pathspec-only status, and `base_commit`. The command must not open, hash, read, execute, or otherwise inspect the protected Bridge Lite plan contents; it may only ask Git for path metadata with `git status --short -- <path>`. It is acceptable for the status to be `?? docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md` because the file is intentionally unrelated and untracked at baseline. Keep `base_commit` for the final committed-range forbidden-path check. Do not hard-code `.git/...`; linked worktrees store Git metadata through a `.git` file and require `git rev-parse --git-path ...`.

- [ ] **Step 2: Verify no product file is changed before starting this implementation**

Run:

```bash
python - <<'PY'
import json
import subprocess
from pathlib import Path

baseline_path = Path(subprocess.check_output(['git', 'rev-parse', '--git-path', 'leigod-telemetry-capability-gates-baseline.json'], text=True).strip())
baseline = json.loads(baseline_path.read_text(encoding='utf-8'))
protected = baseline['protected_path']
status_lines = subprocess.check_output(['git', 'status', '--short', '--', '.', f':(exclude){protected}'], text=True).splitlines()
if status_lines:
    print('\n'.join(status_lines))
print('ok')
PY
```

Expected: `ok`, with no runtime source files listed before implementation starts. This broad working-tree check must explicitly exclude the protected Bridge Lite plan path; protected-file handling remains only the pathspec baseline check in Step 1 and the final pathspec comparison.

- [ ] **Step 3: Commit nothing in this task**

Do not commit the `.git/` baseline file. It is an implementation-time guard only.

---

### Task 2: Add Telemetry Persistence Schema

**Files:**
- Create: `master/tests/aggregation_runtime_telemetry_migration_shape_test.php`
- Create: `master/migrations/20260719_add_forward_aggregation_telemetry.sql`
- Modify: `master/migrations/schema.sql`

- [ ] **Step 1: Write the failing migration shape test**

Create `master/tests/aggregation_runtime_telemetry_migration_shape_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$migration = $root . '/migrations/20260719_add_forward_aggregation_telemetry.sql';
$schema = $root . '/migrations/schema.sql';

$migrationSql = is_file($migration) ? file_get_contents($migration) : '';
$schemaSql = is_file($schema) ? file_get_contents($schema) : '';
if ($migrationSql === false || $schemaSql === false || $migrationSql === '') {
    fail('migration file missing or unreadable');
}

$requiredSnippets = [
    'CREATE TABLE IF NOT EXISTS client_device_session_credentials',
    'client_device_session_id BIGINT UNSIGNED NOT NULL',
    'credential_hash VARCHAR(255) NOT NULL',
    'UNIQUE KEY uq_client_device_session_credentials_session (client_device_session_id)',
    'CREATE TABLE IF NOT EXISTS agent_capability_reports',
    "role_type ENUM('client','slave','sidecar') NOT NULL",
    'reported_artifact_sha256 CHAR(64) DEFAULT NULL',
    'expected_artifact_sha256 CHAR(64) DEFAULT NULL',
    'capability_json JSON DEFAULT NULL',
    'CREATE TABLE IF NOT EXISTS forward_aggregation_runtime_snapshots',
    "active_family VARCHAR(32) NOT NULL DEFAULT 'standalone_bond'",
    "derived_state ENUM('unknown','unavailable','single_leg_only','degraded_single_leg','ready_multi_leg','aggregating') NOT NULL DEFAULT 'unknown'",
    'runtime_epoch BIGINT UNSIGNED NOT NULL',
    'sequence BIGINT UNSIGNED NOT NULL',
    'desired_generation BIGINT UNSIGNED NOT NULL',
    'desired_leg_set_hash CHAR(64) NOT NULL',
    'snapshot_json JSON NOT NULL',
    'client_observed_at DATETIME(3) DEFAULT NULL',
    'received_at DATETIME(3) NOT NULL',
    'received_at_epoch_ms BIGINT UNSIGNED NOT NULL',
    'row_expires_at DATETIME(3) NOT NULL',
    'row_expires_at_epoch_ms BIGINT UNSIGNED NOT NULL',
    'UNIQUE KEY uq_forward_aggregation_snapshot_latest (client_id, client_device_session_id, forward_id, active_family)',
    'CREATE TABLE IF NOT EXISTS forward_aggregation_runtime_window_proofs',
    "useful_window_direction ENUM('upload','download') NOT NULL",
    'traffic_window_sequence BIGINT UNSIGNED NOT NULL',
    'traffic_window_started_monotonic_ms BIGINT UNSIGNED NOT NULL',
    'traffic_window_ended_monotonic_ms BIGINT UNSIGNED NOT NULL',
    'traffic_window_end_age_ms INT UNSIGNED NOT NULL',
    'traffic_window_state_hash CHAR(64) NOT NULL',
    'duration_ms INT UNSIGNED NOT NULL',
    'useful_window_total_bytes BIGINT UNSIGNED NOT NULL',
    'traffic_window_json JSON NOT NULL',
    'UNIQUE KEY uq_forward_aggregation_window_proof_latest (client_id, client_device_session_id, forward_id, active_family, useful_window_direction)',
    'CREATE TABLE IF NOT EXISTS forward_aggregation_runtime_snapshot_rejections',
    'rejection_code VARCHAR(64) NOT NULL',
    'runtime_epoch BIGINT UNSIGNED DEFAULT NULL',
    'sequence BIGINT UNSIGNED DEFAULT NULL',
    'terminal TINYINT(1) NOT NULL DEFAULT 0',
];

foreach ($requiredSnippets as $snippet) {
    if (!str_contains($migrationSql, $snippet)) {
        fail('migration missing snippet: ' . $snippet);
    }
    if (!str_contains($schemaSql, $snippet)) {
        fail('schema.sql missing snippet: ' . $snippet);
    }
}

$forbidden = [
    'DELETE FROM systemd',
    'wg-quick down',
    'ip link del',
    'DROP TABLE forwards',
    'current_mode',
    'cf_mode',
    'bond_fallback',
    'mptcp_preferred_bond_fallback',
];
foreach ($forbidden as $snippet) {
    if (str_contains($migrationSql, $snippet)) {
        fail('migration contains forbidden runtime/legacy snippet: ' . $snippet);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the migration shape test and verify it fails**

Run:

```bash
php master/tests/aggregation_runtime_telemetry_migration_shape_test.php
```

Expected: FAIL with `migration file missing or unreadable`.

- [ ] **Step 3: Create the migration SQL**

Create `master/migrations/20260719_add_forward_aggregation_telemetry.sql`:

```sql
CREATE TABLE IF NOT EXISTS client_device_session_credentials (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_device_session_id BIGINT UNSIGNED NOT NULL,
    credential_hash VARCHAR(255) NOT NULL,
    issued_at DATETIME NOT NULL,
    last_verified_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_client_device_session_credentials_session (client_device_session_id),
    INDEX idx_client_device_session_credentials_verified (last_verified_at),
    CONSTRAINT fk_client_device_session_credentials_session
        FOREIGN KEY (client_device_session_id) REFERENCES client_device_sessions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_capability_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_type ENUM('client','slave','sidecar') NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    client_device_session_id BIGINT UNSIGNED DEFAULT NULL,
    forward_id BIGINT UNSIGNED DEFAULT NULL,
    platform VARCHAR(64) DEFAULT NULL,
    agent_version VARCHAR(64) DEFAULT NULL,
    agent_sha256 CHAR(64) DEFAULT NULL,
    artifact_name VARCHAR(64) DEFAULT NULL,
    artifact_version VARCHAR(64) DEFAULT NULL,
    reported_artifact_sha256 CHAR(64) DEFAULT NULL,
    expected_artifact_sha256 CHAR(64) DEFAULT NULL,
    standalone_bond_version VARCHAR(64) DEFAULT NULL,
    standalone_bond_sha256 CHAR(64) DEFAULT NULL,
    supervisor_schema_version INT UNSIGNED DEFAULT NULL,
    reporter_schema_version INT UNSIGNED DEFAULT NULL,
    bond_snapshot_contract_version INT UNSIGNED DEFAULT NULL,
    capability_json JSON DEFAULT NULL,
    report_status ENUM('accepted','rejected') NOT NULL DEFAULT 'accepted',
    rejection_code VARCHAR(64) DEFAULT NULL,
    received_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_capability_latest (role_type, role_id, client_device_session_id, artifact_name, forward_id),
    INDEX idx_agent_capability_role_received (role_type, role_id, received_at),
    INDEX idx_agent_capability_forward (forward_id, received_at),
    INDEX idx_agent_capability_status (report_status, rejection_code),
    CONSTRAINT fk_agent_capability_client_session
        FOREIGN KEY (client_device_session_id) REFERENCES client_device_sessions(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forward_aggregation_runtime_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id BIGINT UNSIGNED NOT NULL,
    client_device_session_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    active_family VARCHAR(32) NOT NULL DEFAULT 'standalone_bond',
    desired_generation BIGINT UNSIGNED NOT NULL,
    desired_leg_set_hash CHAR(64) NOT NULL,
    runtime_epoch BIGINT UNSIGNED NOT NULL,
    transport_session_epoch BIGINT UNSIGNED DEFAULT NULL,
    sequence BIGINT UNSIGNED NOT NULL,
    lifecycle_state VARCHAR(32) NOT NULL,
    derived_state ENUM('unknown','unavailable','single_leg_only','degraded_single_leg','ready_multi_leg','aggregating') NOT NULL DEFAULT 'unknown',
    selected_logical_leg_key VARCHAR(128) DEFAULT NULL,
    selected_reason VARCHAR(64) DEFAULT NULL,
    snapshot_json JSON NOT NULL,
    state_hash CHAR(64) NOT NULL,
    sample_age_ms INT UNSIGNED NOT NULL,
    client_observed_at DATETIME(3) DEFAULT NULL,
    client_observed_at_epoch_ms BIGINT UNSIGNED DEFAULT NULL,
    received_at DATETIME(3) NOT NULL,
    received_at_epoch_ms BIGINT UNSIGNED NOT NULL,
    row_expires_at DATETIME(3) NOT NULL,
    row_expires_at_epoch_ms BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_forward_aggregation_snapshot_latest (client_id, client_device_session_id, forward_id, active_family),
    INDEX idx_forward_aggregation_snapshot_forward_state (forward_id, derived_state, row_expires_at_epoch_ms),
    INDEX idx_forward_aggregation_snapshot_session (client_device_session_id, runtime_epoch, sequence),
    INDEX idx_forward_aggregation_snapshot_expires (row_expires_at_epoch_ms),
    CONSTRAINT fk_forward_aggregation_snapshot_client_session
        FOREIGN KEY (client_device_session_id) REFERENCES client_device_sessions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forward_aggregation_runtime_window_proofs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id BIGINT UNSIGNED NOT NULL,
    client_device_session_id BIGINT UNSIGNED NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    active_family VARCHAR(32) NOT NULL DEFAULT 'standalone_bond',
    desired_generation BIGINT UNSIGNED NOT NULL,
    desired_leg_set_hash CHAR(64) NOT NULL,
    runtime_epoch BIGINT UNSIGNED NOT NULL,
    transport_session_epoch BIGINT UNSIGNED DEFAULT NULL,
    sequence BIGINT UNSIGNED NOT NULL,
    useful_window_direction ENUM('upload','download') NOT NULL,
    traffic_window_sequence BIGINT UNSIGNED NOT NULL,
    traffic_window_started_monotonic_ms BIGINT UNSIGNED NOT NULL,
    traffic_window_ended_monotonic_ms BIGINT UNSIGNED NOT NULL,
    traffic_window_end_age_ms INT UNSIGNED NOT NULL,
    traffic_window_state_hash CHAR(64) NOT NULL,
    duration_ms INT UNSIGNED NOT NULL,
    useful_window_total_bytes BIGINT UNSIGNED NOT NULL,
    traffic_window_json JSON NOT NULL,
    received_at DATETIME(3) NOT NULL,
    received_at_epoch_ms BIGINT UNSIGNED NOT NULL,
    row_expires_at DATETIME(3) NOT NULL,
    row_expires_at_epoch_ms BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL,
    updated_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_forward_aggregation_window_proof_latest (client_id, client_device_session_id, forward_id, active_family, useful_window_direction),
    INDEX idx_forward_aggregation_window_forward_direction (forward_id, useful_window_direction, row_expires_at_epoch_ms),
    INDEX idx_forward_aggregation_window_session (client_device_session_id, runtime_epoch, sequence),
    CONSTRAINT fk_forward_aggregation_window_client_session
        FOREIGN KEY (client_device_session_id) REFERENCES client_device_sessions(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forward_aggregation_runtime_snapshot_rejections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id BIGINT UNSIGNED NOT NULL,
    client_device_session_id BIGINT UNSIGNED DEFAULT NULL,
    forward_id BIGINT UNSIGNED DEFAULT NULL,
    active_family VARCHAR(32) DEFAULT NULL,
    rejection_code VARCHAR(64) NOT NULL,
    rejection_detail VARCHAR(255) DEFAULT NULL,
    runtime_epoch BIGINT UNSIGNED DEFAULT NULL,
    sequence BIGINT UNSIGNED DEFAULT NULL,
    terminal TINYINT(1) NOT NULL DEFAULT 0,
    received_at DATETIME(3) NOT NULL,
    created_at DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    INDEX idx_forward_aggregation_rejections_client (client_id, received_at),
    INDEX idx_forward_aggregation_rejections_forward (forward_id, received_at),
    INDEX idx_forward_aggregation_rejections_code (rejection_code, received_at),
    CONSTRAINT fk_forward_aggregation_rejection_client_session
        FOREIGN KEY (client_device_session_id) REFERENCES client_device_sessions(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Mirror the table definitions in `master/migrations/schema.sql`**

Append the same five `CREATE TABLE IF NOT EXISTS` statements to `master/migrations/schema.sql` after the existing telemetry/reputation tables. Keep the statements byte-for-byte aligned with the migration except for surrounding schema file ordering.

- [ ] **Step 5: Run the migration shape test and verify it passes**

Run:

```bash
php master/tests/aggregation_runtime_telemetry_migration_shape_test.php
```

Expected: `ok`.

- [ ] **Step 6: Commit the schema task**

Run:

```bash
git add master/tests/aggregation_runtime_telemetry_migration_shape_test.php master/migrations/20260719_add_forward_aggregation_telemetry.sql master/migrations/schema.sql
git commit -m "feat(master): add aggregation telemetry schema

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Add Client Device-Session Credential Context

**Files:**
- Create: `master/tests/client_device_session_context_test.php`
- Create: `master/src/Support/ClientDeviceSessionContext.php`
- Modify: `master/src/Support/Auth.php`
- Modify: `master/src/Controllers/ClientAgentController.php`

- [ ] **Step 1: Write the failing credential context test**

Create `master/tests/client_device_session_context_test.php`:

```php
<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\ClientDeviceSessionContext;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

final class CredentialFakeDb
{
    public array $sessions = [];
    public array $credentials = [];
    public array $updates = [];
    private int $nextId = 1;

    public function fetchOne(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, 'FROM client_device_session_credentials') && isset($params['client_device_session_id'])) {
            return $this->credentials[(int) $params['client_device_session_id']] ?? null;
        }
        if (preg_match('/FROM\s+client_device_sessions\b/i', $sql) === 1 && isset($params['client_id'], $params['device_id'])) {
            foreach ($this->sessions as $row) {
                if ((int) $row['client_id'] === (int) $params['client_id'] && (string) $row['device_id'] === (string) $params['device_id']) {
                    return $row;
                }
            }
            return null;
        }
        if (preg_match('/FROM\s+client_device_sessions\b/i', $sql) === 1 && isset($params['id'], $params['client_id'])) {
            foreach ($this->sessions as $row) {
                if ((int) $row['id'] === (int) $params['id'] && (int) $row['client_id'] === (int) $params['client_id']) {
                    return $row;
                }
            }
            return null;
        }
        return null;
    }

    public function insert(string $table, array $data): int
    {
        if ($table === 'client_device_sessions') {
            $data['id'] = $this->nextId++;
            $this->sessions[] = $data;
            return (int) $data['id'];
        }
        if ($table === 'client_device_session_credentials') {
            $data['id'] = $this->nextId++;
            $this->credentials[(int) $data['client_device_session_id']] = $data;
            return (int) $data['id'];
        }
        fail('unexpected insert table ' . $table);
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->updates[] = ['sql' => $sql, 'params' => $params];
        if (str_contains($sql, 'UPDATE client_device_session_credentials')) {
            $id = (int) $params['id'];
            foreach ($this->credentials as $sessionId => $row) {
                if ((int) $row['id'] === $id) {
                    foreach (['credential_hash', 'issued_at', 'last_verified_at', 'updated_at'] as $key) {
                        if (array_key_exists($key, $params)) {
                            $this->credentials[$sessionId][$key] = $params[$key];
                        }
                    }
                    return 1;
                }
            }
        }
        return 1;
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fail($message);
    }
}

$db = new CredentialFakeDb();
$db->sessions[] = [
    'id' => 10,
    'client_id' => 7,
    'device_id' => 'linux-device-a',
    'last_seen_at' => gmdate('Y-m-d H:i:s.v'),
];
$client = ['id' => 7, 'agent_token' => 'agent-secret'];

$issued = ClientDeviceSessionContext::issueForPullOrHeartbeat($db, $client, 'linux-device-a');
assert_true($issued['session_id'] === 10, 'expected existing session id');
assert_true(is_string($issued['credential']) && strlen($issued['credential']) >= 32, 'expected plaintext credential on first issue');
assert_true(isset($db->credentials[10]['credential_hash']), 'expected credential hash persisted');
assert_true(!str_contains((string) $db->credentials[10]['credential_hash'], $issued['credential']), 'plaintext credential must not be persisted');

$second = ClientDeviceSessionContext::issueForPullOrHeartbeat($db, $client, 'linux-device-a');
assert_true($second['session_id'] === 10, 'expected same session id');
assert_true($second['credential'] === null, 'existing credential must not rotate on every pull/heartbeat');
assert_true($second['credential_status'] === 'unchanged', 'expected unchanged credential status');
assert_true(password_verify($issued['credential'], (string) $db->credentials[10]['credential_hash']), 'original credential must remain valid after heartbeat');

$verified = ClientDeviceSessionContext::requireTelemetry($db, $client, 10, $issued['credential']);
assert_true($verified['session_id'] === 10, 'expected telemetry context session id');
assert_true($verified['client_id'] === 7, 'expected telemetry context client id');


try {
    ClientDeviceSessionContext::requireTelemetry($db, $client, 10, 'wrong-secret');
    fail('wrong credential unexpectedly accepted');
} catch (ApiException $exception) {
    assert_true($exception->getMessage() === 'client_device_session_credential_invalid', 'expected invalid credential error');
}

try {
    ClientDeviceSessionContext::requireTelemetry($db, $client, 99, $issued['credential']);
    fail('unknown session unexpectedly accepted');
} catch (ApiException $exception) {
    assert_true($exception->getMessage() === 'client_device_session_not_found', 'expected missing session error');
}

echo "ok\n";
```

- [ ] **Step 2: Run the credential context test and verify it fails**

Run:

```bash
php master/tests/client_device_session_context_test.php
```

Expected: FAIL because `ClientDeviceSessionContext` does not exist.

- [ ] **Step 3: Add token-only client agent authentication**

Modify `master/src/Support/Auth.php` by adding this method immediately before the existing `requireClientAgent()` method:

```php
    /**
     * Authenticate a client agent bearer token without touching or creating a
     * client_device_sessions row. Telemetry endpoints use this so a failed or
     * unauthenticated device-session credential cannot bootstrap a session.
     *
     * @return array<string, mixed>
     */
    public static function requireClientAgentTokenOnly(Request $request, Database $db, bool $allowBodyToken = true): array
    {
        $token = $request->bearerToken();
        if ($token === null && $allowBodyToken) {
            $token = (string) $request->input('token', '');
        }
        $token = trim((string) ($token ?? ''));
        if ($token === '') {
            throw new ApiException('client_agent_auth_failed', 401);
        }

        $client = $db->fetchOne('SELECT * FROM clients WHERE agent_token = :token LIMIT 1', [
            'token' => $token,
        ]);

        if ($client === null) {
            throw new ApiException('client_agent_auth_failed', 401);
        }

        return $client;
    }

    /**
     * Telemetry must be authenticated by the Authorization bearer header only.
     * A body/query `token` is accepted on legacy pull/heartbeat paths but is
     * deliberately rejected for high-rate telemetry uploads.
     *
     * @return array<string, mixed>
     */
    public static function requireClientAgentBearerOnly(Request $request, Database $db): array
    {
        return self::requireClientAgentTokenOnly($request, $db, allowBodyToken: false);
    }
```

Then replace the body of `requireClientAgent()` with this exact body so existing pull/heartbeat behavior stays compatible:

```php
    public static function requireClientAgent(Request $request, Database $db): array
    {
        $client = self::requireClientAgentTokenOnly($request, $db, allowBodyToken: true);
        (new ClientOnlineSessionService($db))->enforceFromRequest($client, $request);
        return $client;
    }
```

- [ ] **Step 4: Create `ClientDeviceSessionContext`**

Create `master/src/Support/ClientDeviceSessionContext.php`:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class ClientDeviceSessionContext
{
    /**
     * @param object $db Database-like object supporting fetchOne/insert/execute.
     * @param array<string, mixed> $client
     * @return array{session_id:int,client_id:int,device_id:string,credential:?string,credential_status:string}
     */
    public static function issueForPullOrHeartbeat(object $db, array $client, string $deviceId): array
    {
        $clientId = (int) ($client['id'] ?? 0);
        if ($clientId <= 0) {
            throw new ApiException('client_not_found', 404);
        }
        $deviceId = trim($deviceId) !== '' ? trim($deviceId) : 'legacy-unknown-device';
        $session = $db->fetchOne(
            'SELECT * FROM client_device_sessions WHERE client_id = :client_id AND device_id = :device_id LIMIT 1',
            ['client_id' => $clientId, 'device_id' => $deviceId]
        );
        if ($session === null) {
            throw new ApiException('client_device_session_not_found_after_touch', 500);
        }
        $sessionId = (int) ($session['id'] ?? 0);
        if ($sessionId <= 0) {
            throw new ApiException('client_device_session_not_found_after_touch', 500);
        }

        $credential = $db->fetchOne(
            'SELECT * FROM client_device_session_credentials WHERE client_device_session_id = :client_device_session_id LIMIT 1',
            ['client_device_session_id' => $sessionId]
        );
        $now = gmdate('Y-m-d H:i:s.v');
        if ($credential !== null) {
            return [
                'session_id' => $sessionId,
                'client_id' => $clientId,
                'device_id' => $deviceId,
                'credential' => null,
                'credential_status' => 'unchanged',
            ];
        }

        $plain = self::newCredential();
        $db->insert('client_device_session_credentials', [
            'client_device_session_id' => $sessionId,
            'credential_hash' => password_hash($plain, PASSWORD_DEFAULT),
            'issued_at' => $now,
            'last_verified_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'session_id' => $sessionId,
            'client_id' => $clientId,
            'device_id' => $deviceId,
            'credential' => $plain,
            'credential_status' => 'issued',
        ];
    }

    /**
     * @param object $db Database-like object supporting fetchOne/execute.
     * @param array<string, mixed> $client
     * @return array{session_id:int,client_id:int,device_id:string}
     */
    public static function requireTelemetry(object $db, array $client, int $deviceSessionId, string $credentialPlaintext): array
    {
        $clientId = (int) ($client['id'] ?? 0);
        if ($clientId <= 0) {
            throw new ApiException('client_not_found', 404);
        }
        if ($deviceSessionId <= 0) {
            throw new ApiException('client_device_session_required', 401);
        }
        $credentialPlaintext = trim($credentialPlaintext);
        if ($credentialPlaintext === '') {
            throw new ApiException('client_device_session_credential_required', 401);
        }

        $session = $db->fetchOne(
            'SELECT * FROM client_device_sessions WHERE id = :id AND client_id = :client_id LIMIT 1',
            ['id' => $deviceSessionId, 'client_id' => $clientId]
        );
        if ($session === null) {
            throw new ApiException('client_device_session_not_found', 401);
        }

        $credential = $db->fetchOne(
            'SELECT * FROM client_device_session_credentials WHERE client_device_session_id = :client_device_session_id LIMIT 1',
            ['client_device_session_id' => $deviceSessionId]
        );
        if ($credential === null || !password_verify($credentialPlaintext, (string) ($credential['credential_hash'] ?? ''))) {
            throw new ApiException('client_device_session_credential_invalid', 401);
        }

        $now = gmdate('Y-m-d H:i:s.v');
        $db->execute(
            'UPDATE client_device_session_credentials SET last_verified_at = :last_verified_at, updated_at = :updated_at WHERE id = :id',
            ['id' => $credential['id'], 'last_verified_at' => $now, 'updated_at' => $now]
        );

        return [
            'session_id' => $deviceSessionId,
            'client_id' => $clientId,
            'device_id' => (string) ($session['device_id'] ?? ''),
        ];
    }

    private static function newCredential(): string
    {
        $raw = random_bytes(32);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 5: Add device-session credential fields to client pull/heartbeat responses**

Credential issuance is stable: pull/heartbeat may return a plaintext credential only when the session has no telemetry credential row yet. Existing credentials must return `credential: null` with `credential_status: unchanged`; do not rotate on every heartbeat because the reporter posts on an independent cadence and a heartbeat-time rotation can invalidate the in-memory reporter secret between flushes. If a local client-agent process restarts and loses its memory-only telemetry credential while the server already has a credential row, telemetry must pause and fail closed until a separately authorized session reset or credential-rotation design exists; Task 39 must not add automatic reissue, credential rotation, old-credential invalidation, or admin/user rotation controls.

Modify `ClientAgentController::pullConfigs()` after `$deviceSession = ...` is assigned:

```php
        $deviceSessionCredential = ClientDeviceSessionContext::issueForPullOrHeartbeat($this->db, $client, $deviceId);
```

Add this key under the returned `client` object:

```php
                'device_session' => [
                    'id' => (int) $deviceSessionCredential['session_id'],
                    'credential' => $deviceSessionCredential['credential'],
                    'credential_status' => (string) $deviceSessionCredential['credential_status'],
                ],
```

Modify `ClientAgentController::heartbeat()` after `$deviceSessionService->recordAndEnforce(...)`:

```php
        $deviceSessionCredential = ClientDeviceSessionContext::issueForPullOrHeartbeat($this->db, $client, $deviceId);
```

Do not read or honor an `aggregation_runtime_credential_reissue_requested` flag in Task 39. A client-agent restart that loses its memory-only telemetry credential must fail closed for telemetry until a separately authorized reset/rotation policy exists.

Change the final heartbeat response from `return $this->json(['ok' => true]);` to:

```php
        return $this->json([
            'ok' => true,
            'device_session' => [
                'id' => (int) $deviceSessionCredential['session_id'],
                'credential' => $deviceSessionCredential['credential'],
                'credential_status' => (string) $deviceSessionCredential['credential_status'],
            ],
        ]);
```

If `ClientDeviceSessionContext` is not already imported, add:

```php
use Leikwan\Master\Support\ClientDeviceSessionContext;
```

- [ ] **Step 6: Run the credential context test and verify it passes**

Run:

```bash
php master/tests/client_device_session_context_test.php
```

Expected: `ok`.

- [ ] **Step 7: Commit the credential context task**

Run:

```bash
git add master/tests/client_device_session_context_test.php master/src/Support/ClientDeviceSessionContext.php master/src/Support/Auth.php master/src/Controllers/ClientAgentController.php
git commit -m "feat(master): authenticate aggregation telemetry sessions

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Persist Bounded Role Capability Reports

**Files:**
- Create: `master/tests/agent_capability_report_service_test.php`
- Create: `master/src/Support/AgentCapabilityReportService.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/src/Controllers/NodeController.php`
- Modify: `master/src/Controllers/SidecarAgentController.php`
- Modify: `client-agent/client_agent.py`
- Modify: `slave-agent/slave_agent.py`
- Modify: `sidecar-agent/sidecar_agent.py`

- [ ] **Step 1: Write the failing capability service test**

Create `master/tests/agent_capability_report_service_test.php`:

```php
<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\AgentCapabilityReportService;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

final class CapabilityFakeDb
{
    public array $rows = [];
    public array $deleted = [];

    public function execute(string $sql, array $params = []): int
    {
        if (str_contains($sql, 'DELETE FROM agent_capability_reports')) {
            $this->deleted[] = $params;
            $this->rows = array_values(array_filter($this->rows, static function (array $row) use ($params): bool {
                return !(
                    $row['role_type'] === $params['role_type']
                    && (int) $row['role_id'] === (int) $params['role_id']
                    && (int) ($row['client_device_session_id'] ?? 0) === (int) ($params['client_device_session_id'] ?? 0)
                    && (string) ($row['artifact_name'] ?? '') === (string) ($params['artifact_name'] ?? '')
                    && (int) ($row['forward_id'] ?? 0) === (int) ($params['forward_id'] ?? 0)
                );
            }));
        }
        return 1;
    }

    public function insert(string $table, array $data): int
    {
        if ($table !== 'agent_capability_reports') {
            fail('unexpected table ' . $table);
        }
        $data['id'] = count($this->rows) + 1;
        $this->rows[] = $data;
        return (int) $data['id'];
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        foreach (array_reverse($this->rows) as $row) {
            if ($row['role_type'] === $params['role_type'] && (int) $row['role_id'] === (int) $params['role_id']) {
                return $row;
            }
        }
        return null;
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

$db = new CapabilityFakeDb();
$service = new AgentCapabilityReportService($db);
$accepted = $service->recordReport(
    roleType: 'client',
    roleId: 7,
    clientDeviceSessionId: 10,
    forwardId: 65,
    payload: [
        'platform' => 'linux',
        'agent_version' => '2026.07.19.1',
        'agent_sha256' => str_repeat('a', 64),
        'artifact_name' => 'leikwan_bond_linux_amd64',
        'artifact_version' => '0.1.15',
        'artifact_sha256' => str_repeat('b', 64),
        'standalone_bond_version' => '0.1.15',
        'standalone_bond_sha256' => str_repeat('b', 64),
        'reporter_schema_version' => 1,
        'bond_snapshot_contract_version' => 1,
        'native_' . 'mptcp_observed' => true,
        'mptcp_preferred_bond_' . 'fallback' => true,
        'recent_errors' => array_fill(0, 20, str_repeat('x', 200)),
    ],
    expected: [
        'artifact_sha256' => str_repeat('b', 64),
    ]
);
assert_same('accepted', $accepted['status'], 'matching hash should be accepted');
assert_same('client', $db->rows[0]['role_type'], 'role type persisted');
assert_same(str_repeat('b', 64), $db->rows[0]['expected_artifact_sha256'], 'expected hash persisted from server input');
assert_same(null, $db->rows[0]['rejection_code'], 'accepted report has no rejection');
$capabilityJson = json_decode((string) $db->rows[0]['capability_json'], true);
assert_same(false, array_key_exists('native_' . 'mptcp_observed', $capabilityJson), 'passive OS aggregation observation must not be persisted as first-class gate input');
assert_same(false, array_key_exists('mptcp_preferred_bond_' . 'fallback', $capabilityJson), 'unsupported aggregation fallback flag must be stripped');

$rejected = $service->recordReport(
    roleType: 'slave',
    roleId: 22,
    clientDeviceSessionId: null,
    forwardId: 65,
    payload: [
        'platform' => 'linux',
        'agent_version' => '2026.07.19.1',
        'artifact_name' => 'slave_agent',
        'artifact_sha256' => str_repeat('c', 64),
    ],
    expected: [
        'artifact_sha256' => str_repeat('d', 64),
    ]
);
assert_same('rejected', $rejected['status'], 'mismatched expected hash must be rejected');
assert_same('artifact_sha256_mismatch', $rejected['rejection_code'], 'expected mismatch code');

$unknownExpected = $service->recordReport(
    roleType: 'sidecar',
    roleId: 5,
    clientDeviceSessionId: null,
    forwardId: null,
    payload: [
        'artifact_name' => 'sidecar_agent',
        'artifact_sha256' => str_repeat('e', 64),
    ],
    expected: []
);
assert_same('accepted', $unknownExpected['status'], 'unknown expected hash is diagnostic-only accepted');

foreach (['client', 'slave', 'sidecar'] as $role) {
    $db = new CapabilityFakeDb();
    $service = new AgentCapabilityReportService($db);
    $service->recordReport(
        roleType: $role,
        roleId: 99,
        clientDeviceSessionId: $role === 'client' ? 10 : null,
        forwardId: 65,
        payload: [
            'platform' => 'linux',
            'artifact_name' => $role . '_agent',
            'credential' => 'cred-secret',
            'device_session_credential' => 'session-secret',
            'token' => 'token-secret',
            'agent_token' => 'agent-token-secret',
            'authorization' => 'Bearer secret',
            'password' => 'password-secret',
            'secret' => 'generic-secret',
            'nested' => ['api_secret' => 'nested-secret', 'safe' => 'value'],
        ],
        expected: []
    );
    $encoded = (string) $db->rows[0]['capability_json'];
    foreach (['cred-secret', 'session-secret', 'token-secret', 'agent-token-secret', 'Bearer secret', 'password-secret', 'generic-secret', 'nested-secret'] as $secret) {
        if (str_contains($encoded, $secret)) {
            fail('secret persisted for role ' . $role . ': ' . $secret);
        }
    }
}

$controllerSource = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if ($controllerSource === false) {
    fail('failed to read ClientAgentController');
}
$heartbeatStart = strpos($controllerSource, 'function heartbeat');
$heartbeatSection = $heartbeatStart === false ? '' : substr($controllerSource, $heartbeatStart, 7000);
foreach (["input('capability_reports'", 'AgentCapabilityReportService', 'BondRuntimeAssignmentRepository', 'findForClientDeviceForward(', 'if ($assignment === null)', 'forwardId: $forwardId,'] as $snippet) {
    if (!str_contains($heartbeatSection, $snippet)) {
        fail('ClientAgentController heartbeat missing plural gate-capable capability snippet: ' . $snippet);
    }
}
foreach (["input('capability_report'", 'input("capability_report"', '$body[\'capability_report\']', '$body["capability_report"]', '$legacyCapabilityReport'] as $forbidden) {
    if (str_contains($heartbeatSection, $forbidden)) {
        fail('ClientAgentController heartbeat must not promote singular client capability reports into Speed Plus gate rows: ' . $forbidden);
    }
}
$assignmentCall = strpos($heartbeatSection, 'findForClientDeviceForward(');
$assignmentSkip = strpos($heartbeatSection, 'if ($assignment === null)');
$recordReportCall = strpos($heartbeatSection, 'recordReport(');
if ($assignmentCall === false || $assignmentSkip === false || $recordReportCall === false || $assignmentCall > $recordReportCall || $assignmentSkip > $recordReportCall) {
    fail('positive capability_reports[] forward_id values must be server-assignment-checked and skipped when unassigned before recordReport() can create a gate-capable row');
}

$unassignedForwardCapabilityReport = ['forward_id' => 999, 'artifact_name' => 'leikwan_bond_linux_amd64'];
if ((int) $unassignedForwardCapabilityReport['forward_id'] <= 0) {
    fail('unassigned-forward regression fixture must use a positive forward_id');
}


echo "ok\n";
```

- [ ] **Step 2: Run the capability service test and verify it fails**

Run:

```bash
php master/tests/agent_capability_report_service_test.php
```

Expected: FAIL because `AgentCapabilityReportService` does not exist.

- [ ] **Step 3: Create `AgentCapabilityReportService`**

Create `master/src/Support/AgentCapabilityReportService.php`:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class AgentCapabilityReportService
{
    private const ROLE_TYPES = ['client', 'slave', 'sidecar'];
    private const FIRST_CLASS_KEYS = [
        'platform',
        'agent_version',
        'agent_sha256',
        'artifact_name',
        'artifact_version',
        'artifact_sha256',
        'standalone_bond_version',
        'standalone_bond_sha256',
        'supervisor_schema_version',
        'reporter_schema_version',
        'bond_snapshot_contract_version',
    ];
    private const CAPABILITY_JSON_ALLOWLIST = [
        'platform',
        'agent_version',
        'agent_sha256',
        'artifact_name',
        'artifact_version',
        'artifact_sha256',
        'standalone_bond_version',
        'standalone_bond_sha256',
        'supervisor_schema_version',
        'reporter_schema_version',
        'bond_snapshot_contract_version',
        'kernel_release',
        'installer_version',
        'installer_sha256',
        'install_forward_runtime_sha256',
        'mptcp_client_sha256',
        'cfwg_watchdog_sha256',
        'wireguard_listen_port',
        'wireguard_fallback_tcp_port',
        'clock_sync',
    ];
    private const SECRET_KEY_FRAGMENTS = [
        'credential',
        'token',
        'authorization',
        'password',
        'secret',
    ];
    private const STRIPPED_KEYS = [
        'native_' . 'mptcp_observed',
        'supports_' . 'mptcp',
        'mptcp_preferred_bond_' . 'fallback',
        'bond_' . 'fallback',
        'current_' . 'mode',
        'cf_' . 'mode',
        'traffic_window_json',
        'aggregation_runtime_snapshots',
        'bond_runtime_snapshots',
        'recent_errors',
        'transition_history',
    ];

    public function __construct(private object $db)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $expected
     * @return array{status:string,rejection_code:?string}
     */
    public function recordReport(
        string $roleType,
        int $roleId,
        ?int $clientDeviceSessionId,
        ?int $forwardId,
        array $payload,
        array $expected = []
    ): array {
        $roleType = strtolower(trim($roleType));
        if (!in_array($roleType, self::ROLE_TYPES, true)) {
            throw new ApiException('invalid_capability_role_type', 422);
        }
        if ($roleId <= 0) {
            throw new ApiException('invalid_capability_role_id', 422);
        }

        $normalized = $this->normalizePayload($payload);
        $reportedHash = $normalized['artifact_sha256'];
        $expectedHash = $this->sha256OrNull($expected['artifact_sha256'] ?? null);
        $status = 'accepted';
        $rejectionCode = null;
        if ($expectedHash !== null && $reportedHash !== null && !hash_equals($expectedHash, $reportedHash)) {
            $status = 'rejected';
            $rejectionCode = 'artifact_sha256_mismatch';
        }
        if ($expectedHash !== null && $reportedHash === null) {
            $status = 'rejected';
            $rejectionCode = 'artifact_sha256_missing';
        }

        $now = gmdate('Y-m-d H:i:s');
        $deleteParams = [
            'role_type' => $roleType,
            'role_id' => $roleId,
            'client_device_session_id' => $clientDeviceSessionId ?? 0,
            'artifact_name' => $normalized['artifact_name'] ?? '',
            'forward_id' => $forwardId ?? 0,
        ];
        $this->db->execute(
            'DELETE FROM agent_capability_reports
             WHERE role_type = :role_type
               AND role_id = :role_id
               AND COALESCE(client_device_session_id, 0) = :client_device_session_id
               AND COALESCE(artifact_name, \'\') = :artifact_name
               AND COALESCE(forward_id, 0) = :forward_id',
            $deleteParams
        );
        $this->db->insert('agent_capability_reports', [
            'role_type' => $roleType,
            'role_id' => $roleId,
            'client_device_session_id' => $clientDeviceSessionId,
            'forward_id' => $forwardId,
            'platform' => $normalized['platform'],
            'agent_version' => $normalized['agent_version'],
            'agent_sha256' => $normalized['agent_sha256'],
            'artifact_name' => $normalized['artifact_name'],
            'artifact_version' => $normalized['artifact_version'],
            'reported_artifact_sha256' => $reportedHash,
            'expected_artifact_sha256' => $expectedHash,
            'standalone_bond_version' => $normalized['standalone_bond_version'],
            'standalone_bond_sha256' => $normalized['standalone_bond_sha256'],
            'supervisor_schema_version' => $normalized['supervisor_schema_version'],
            'reporter_schema_version' => $normalized['reporter_schema_version'],
            'bond_snapshot_contract_version' => $normalized['bond_snapshot_contract_version'],
            'capability_json' => json_encode($normalized['capability_json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'report_status' => $status,
            'rejection_code' => $rejectionCode,
            'received_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['status' => $status, 'rejection_code' => $rejectionCode];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload): array
    {
        foreach (self::STRIPPED_KEYS as $key) {
            unset($payload[$key]);
        }
        $bounded = [];
        foreach ($payload as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            if (!in_array($key, self::CAPABILITY_JSON_ALLOWLIST, true)) {
                continue;
            }
            if ($this->isSecretKey($key)) {
                continue;
            }
            $safe = $this->normalizeSafeValue($value);
            if ($safe !== null || array_key_exists($key, $payload)) {
                $bounded[$key] = $safe;
            }
        }

        $result = ['capability_json' => $bounded];
        foreach (self::FIRST_CLASS_KEYS as $key) {
            $result[$key] = $bounded[$key] ?? null;
        }
        $result['agent_sha256'] = $this->sha256OrNull($result['agent_sha256']);
        $result['artifact_sha256'] = $this->sha256OrNull($result['artifact_sha256']);
        $result['standalone_bond_sha256'] = $this->sha256OrNull($result['standalone_bond_sha256']);
        foreach (['supervisor_schema_version', 'reporter_schema_version', 'bond_snapshot_contract_version'] as $intKey) {
            $result[$intKey] = $result[$intKey] !== null ? max(0, (int) $result[$intKey]) : null;
        }
        foreach (['platform', 'agent_version', 'artifact_name', 'artifact_version', 'standalone_bond_version'] as $stringKey) {
            $result[$stringKey] = $result[$stringKey] !== null ? substr(trim((string) $result[$stringKey]), 0, 64) : null;
        }
        return $result;
    }

    private function normalizeSafeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return substr($value, 0, 256);
        }
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        $bounded = [];
        foreach (array_slice($value, 0, 16, true) as $key => $child) {
            $key = trim((string) $key);
            if ($key === '' || $this->isSecretKey($key)) {
                continue;
            }
            if (is_string($child)) {
                $bounded[$key] = substr($child, 0, 256);
            } elseif (is_bool($child) || is_int($child) || is_float($child) || $child === null) {
                $bounded[$key] = $child;
            }
        }
        return $bounded;
    }

    private function isSecretKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SECRET_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function sha256OrNull(mixed $value): ?string
    {
        $hash = strtolower(trim((string) ($value ?? '')));
        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
    }
}
```

- [ ] **Step 4: Emit client capability reports from the client agent without enabling MPTCP**

In `client-agent/client_agent.py`, add this helper to the `ClientAgent` class near the existing `bond_binary_path()` / `mptcp_client_path()` helpers:

```python
    def aggregation_capability_report(self, forward_id: int | None = None) -> dict:
        cfwg_watchdog_path = pathlib.Path(__file__).with_name("cfwg_latency_watchdog.py")
        bond_path = self.bond_binary_path()
        report = {
            "platform": "linux",
            "agent_version": AGENT_VERSION,
            "agent_sha256": file_sha256(pathlib.Path(__file__).resolve()),
            "artifact_name": "leikwan_bond_linux_amd64",
            "artifact_sha256": file_sha256(bond_path),
            "standalone_bond_sha256": file_sha256(bond_path),
            "reporter_schema_version": 1,
            "bond_snapshot_contract_version": 1,
            "kernel_release": platform.release(),
            "installer_version": str(self.config.get("installer_version", "") or ""),
            "installer_sha256": str(self.config.get("installer_sha256", "") or ""),
            "install_forward_runtime_sha256": file_sha256(self.install_forward_runtime_path()),
            "mptcp_client_sha256": file_sha256(self.mptcp_client_path()),
            "cfwg_watchdog_sha256": file_sha256(cfwg_watchdog_path) if cfwg_watchdog_path.is_file() else "",
            "wireguard_listen_port": self.wireguard_listen_port(),
            "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
        }
        if forward_id is not None and int(forward_id) > 0:
            report["forward_id"] = int(forward_id)
        return report

    def aggregation_capability_reports(self) -> list[dict]:
        reports: list[dict] = []
        seen: set[int] = set()
        for forward in self.latest_forwards:
            if not isinstance(forward, dict):
                continue
            forward_id = int(forward.get("id") or forward.get("forward_id") or 0)
            if forward_id <= 0 or forward_id in seen:
                continue
            seen.add(forward_id)
            reports.append(self.aggregation_capability_report(forward_id))
        return reports
```

Then add this key to the existing heartbeat dictionary in `sync_once()`:

```python
                "capability_reports": self.aggregation_capability_reports(),
```

Heartbeat capability reports are Forward-scoped by real `self.latest_forwards` IDs so rows can satisfy the read-only gate when heartbeat happens to be fresh. They are not the only freshness source: Task 7 wires the independent 5-second aggregation reporter to include the same Forward-scoped capability reports in `/api/client/aggregation-runtime-snapshots`, so eligibility is not tied to the 20-30 second heartbeat/config cadence. Do not include `agent_token`, device-session credential, Authorization header value, LeiGod credential material, bond session token, or `bond_leg_reputation` content inside any capability report. `supports_mptcp` and `mptcp_capability` may stay in the legacy heartbeat payload for diagnostics, but `AgentCapabilityReportService` must strip them from gate inputs.

- [ ] **Step 5: Record client capabilities from heartbeat without enabling MPTCP**

In `ClientAgentController::heartbeat()`, after `$runtimeState` is computed and before the `UPDATE clients` statement, add:

```php
        $capabilityReports = $request->input('capability_reports', []);
        if (!is_array($capabilityReports)) {
            $capabilityReports = [];
        }
        $capabilityService = new AgentCapabilityReportService($this->db);
        $assignmentRepository = new BondRuntimeAssignmentRepository($this->db);
        foreach ($capabilityReports as $capabilityReport) {
            if (!is_array($capabilityReport)) {
                continue;
            }
            $forwardId = isset($capabilityReport['forward_id']) ? (int) $capabilityReport['forward_id'] : 0;
            if ($forwardId <= 0) {
                continue;
            }
            try {
                $assignment = $assignmentRepository->findForClientDeviceForward(
                    (int) $client['id'],
                    (int) ($deviceSessionCredential['session_id'] ?? 0),
                    $forwardId
                );
            } catch (ApiException $exception) {
                $assignment = null;
            }
            if ($assignment === null) {
                continue;
            }
            $capabilityService->recordReport(
                roleType: 'client',
                roleId: (int) $client['id'],
                clientDeviceSessionId: (int) ($deviceSessionCredential['session_id'] ?? 0),
                forwardId: $forwardId,
                payload: $capabilityReport,
                expected: ['artifact_sha256' => $desiredBondSha256 ?? null]
            );
        }
```

Do not read, wrap, or persist legacy singular `capability_report` from `ClientAgentController::heartbeat()` for client Speed Plus gating. Singular client capability payloads are treated as diagnostic legacy noise and must not create a Forward-scoped `agent_capability_reports` row that `BondRuntimeEligibilityService::freshClientCapability()` can consume. Only plural `capability_reports[]` entries with a positive real `forward_id` and a matching server-side `BondRuntimeAssignmentRepository::findForClientDeviceForward()` assignment may become gate-eligible client capability rows. Unknown, unassigned, disabled, legacy-mode, wrong-client, or otherwise unsupported Forward IDs must be skipped instead of persisted as gate-capable client rows.

If `$desiredBondSha256` is not in scope in `heartbeat()`, compute it before this block:

```php
        $bondBinary = $this->artifactMetadata('leikwan_bond_linux_amd64');
        $desiredBondSha256 = (string) ($bondBinary['binary_sha256'] ?? $bondBinary['sha256'] ?? '');
```

Add these imports if they are not already present:

```php
use Leikwan\Master\Support\AgentCapabilityReportService;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\BondRuntimeAssignmentRepository;
```

- [ ] **Step 6: Emit slave capability reports from `slave-agent/slave_agent.py`**

In `slave-agent/slave_agent.py`, add this helper to the `SlaveAgent` class near `build_heartbeat_payload()`:

```python
    def capability_report(self) -> dict[str, Any]:
        return {
            "platform": "linux",
            "agent_version": AGENT_VERSION,
            "agent_sha256": file_sha256(Path(__file__).resolve()),
            "artifact_name": "slave_agent",
            "artifact_sha256": file_sha256(Path(__file__).resolve()),
            "clock_sync": self.time_sync_state(),
            "wireguard_listen_port": self.wireguard_listen_port(),
            "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
        }
```

Then add this key to the payload returned by `build_heartbeat_payload()`:

```python
            "capability_report": self.capability_report(),
```

The report must remain diagnostic-only. Do not add node token, enrollment token, LeiGod credential material, sidecar token, task token, or any WireGuard private key material.

- [ ] **Step 7: Emit sidecar capability reports from `sidecar-agent/sidecar_agent.py`**

In `sidecar-agent/sidecar_agent.py`, add this helper to the `SidecarAgent` class near `build_heartbeat_payload()`:

```python
    def capability_report(self) -> dict:
        return {
            "platform": "linux",
            "agent_version": AGENT_VERSION,
            "agent_sha256": file_sha256(pathlib.Path(__file__).resolve()),
            "artifact_name": "sidecar_agent",
            "artifact_sha256": file_sha256(pathlib.Path(__file__).resolve()),
            "standalone_bond_sha256": file_sha256(self.bond_binary_path()),
            "supervisor_schema_version": 1,
            "wireguard_listen_port": self.wireguard_listen_port(),
            "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
            "clock_sync": self.time_sync_state(),
        }
```

Then add this key to the payload returned by `build_heartbeat_payload()`:

```python
            "capability_report": self.capability_report(),
```

The report must remain diagnostic-only. Do not add `agent_token`, `token_secret`, LeiGod credential material, bond auth token, TLS private key paths containing secrets, or `bond_leg_reputation` details.

- [ ] **Step 8: Record slave and sidecar capabilities as diagnostics only**

In `NodeController::heartbeat()`, after `$mergedCapabilities` is computed, add:

```php
        $capabilityReport = is_array($body['capability_report'] ?? null) ? $body['capability_report'] : [];
        if ($capabilityReport !== []) {
            (new AgentCapabilityReportService($this->db))->recordReport(
                roleType: 'slave',
                roleId: (int) $node['id'],
                clientDeviceSessionId: null,
                forwardId: isset($capabilityReport['forward_id']) ? (int) $capabilityReport['forward_id'] : null,
                payload: $capabilityReport,
                expected: []
            );
        }
```

In `SidecarAgentController::heartbeat()`, after the sidecar point is authenticated and the request body is available, add:

```php
        $capabilityReport = is_array($body['capability_report'] ?? null) ? $body['capability_report'] : [];
        if ($capabilityReport !== []) {
            (new AgentCapabilityReportService($this->db))->recordReport(
                roleType: 'sidecar',
                roleId: (int) $point['id'],
                clientDeviceSessionId: null,
                forwardId: isset($capabilityReport['forward_id']) ? (int) $capabilityReport['forward_id'] : null,
                payload: $capabilityReport,
                expected: []
            );
        }
```

Add the same `use Leikwan\Master\Support\AgentCapabilityReportService;` import to both controllers.

- [ ] **Step 9: Run the capability service test and verify it passes**

Run:

```bash
php master/tests/agent_capability_report_service_test.php
```

Expected: `ok`.

- [ ] **Step 10: Run agent syntax smoke checks**

Run:

```bash
python -m py_compile client-agent/client_agent.py slave-agent/slave_agent.py sidecar-agent/sidecar_agent.py
```

Expected: no output and exit 0.

- [ ] **Step 11: Commit capability reports**

Run:

```bash
git add master/tests/agent_capability_report_service_test.php master/src/Support/AgentCapabilityReportService.php master/src/Controllers/ClientAgentController.php master/src/Controllers/NodeController.php master/src/Controllers/SidecarAgentController.php client-agent/client_agent.py slave-agent/slave_agent.py sidecar-agent/sidecar_agent.py
git commit -m "feat(master): persist standalone bond capability reports

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Add Standalone-Bond Assignment Context and Snapshot Reducer

**Files:**
- Create: `master/tests/bond_runtime_assignment_repository_test.php`
- Create: `master/tests/bond_aggregation_runtime_snapshot_service_test.php`
- Create: `master/src/Support/BondRuntimeAssignmentContext.php`
- Create: `master/src/Support/BondRuntimeAssignmentRepository.php`
- Create: `master/src/Support/BondAggregationRuntimeSnapshotService.php`

- [ ] **Step 1: Write the failing reducer test**

Create `master/tests/bond_aggregation_runtime_snapshot_service_test.php`:

```php
<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\BondAggregationRuntimeSnapshotService;
use Leikwan\Master\Support\BondRuntimeAssignmentContext;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

final class SnapshotFakeDb
{
    public array $snapshots = [];
    public array $proofs = [];
    public array $rejections = [];

    public function fetchOne(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, 'FROM forward_aggregation_runtime_snapshots')) {
            foreach ($this->snapshots as $row) {
                if ((int) $row['client_id'] === (int) $params['client_id']
                    && (int) $row['client_device_session_id'] === (int) $params['client_device_session_id']
                    && (int) $row['forward_id'] === (int) $params['forward_id']
                    && (string) $row['active_family'] === (string) $params['active_family']) {
                    return $row;
                }
            }
            return null;
        }
        if (str_contains($sql, 'FROM forward_aggregation_runtime_window_proofs')) {
            foreach ($this->proofs as $row) {
                if ((int) $row['client_id'] === (int) $params['client_id']
                    && (int) $row['client_device_session_id'] === (int) $params['client_device_session_id']
                    && (int) $row['forward_id'] === (int) $params['forward_id']
                    && (string) $row['active_family'] === (string) $params['active_family']
                    && (string) $row['useful_window_direction'] === (string) $params['direction']) {
                    return $row;
                }
            }
            return null;
        }
        return null;
    }

    public function execute(string $sql, array $params = []): int
    {
        if (str_contains($sql, 'DELETE FROM forward_aggregation_runtime_snapshots')
            || str_contains($sql, 'DELETE FROM forward_aggregation_runtime_window_proofs')) {
            fail('snapshot reducer must not delete-then-insert latest telemetry rows');
        }
        if (str_contains($sql, 'UPDATE forward_aggregation_runtime_snapshots')) {
            foreach ($this->snapshots as $index => $row) {
                if ((int) $row['client_id'] === (int) $params['client_id_key']
                    && (int) $row['client_device_session_id'] === (int) $params['client_device_session_id_key']
                    && (int) $row['forward_id'] === (int) $params['forward_id_key']
                    && (string) $row['active_family'] === (string) $params['active_family_key']) {
                    $this->snapshots[$index] = array_merge($row, array_diff_key($params, array_flip(['client_id_key', 'client_device_session_id_key', 'forward_id_key', 'active_family_key'])));
                    return 1;
                }
            }
            fail('update missed latest lifecycle row');
        }
        if (str_contains($sql, 'UPDATE forward_aggregation_runtime_window_proofs')) {
            foreach ($this->proofs as $index => $row) {
                if ((int) $row['client_id'] === (int) $params['client_id_key']
                    && (int) $row['client_device_session_id'] === (int) $params['client_device_session_id_key']
                    && (int) $row['forward_id'] === (int) $params['forward_id_key']
                    && (string) $row['active_family'] === (string) $params['active_family_key']
                    && (string) $row['useful_window_direction'] === (string) $params['direction_key']) {
                    $this->proofs[$index] = array_merge($row, array_diff_key($params, array_flip(['client_id_key', 'client_device_session_id_key', 'forward_id_key', 'active_family_key', 'direction_key'])));
                    return 1;
                }
            }
            fail('update missed latest proof row');
        }
        return 1;
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }

    public function insert(string $table, array $data): int
    {
        if ($table === 'forward_aggregation_runtime_snapshots') {
            if (array_key_exists('useful_window_direction', $data)) {
                fail('lifecycle rows must not carry upload/download proof direction');
            }
            $snapshotJson = json_decode((string) ($data['snapshot_json'] ?? '{}'), true);
            if (!is_array($snapshotJson)) {
                fail('lifecycle snapshot_json must decode');
            }
            foreach (['traffic_window', 'traffic_window_json', 'traffic_window_state_hash', 'traffic_window_sequence', 'traffic_window_started_monotonic_ms', 'traffic_window_ended_monotonic_ms', 'traffic_window_end_age_ms', 'duration_ms', 'useful_window_total_bytes', 'per_leg_useful_bytes'] as $proofOnlyKey) {
                if (array_key_exists($proofOnlyKey, $snapshotJson)) {
                    fail('lifecycle snapshot_json must not persist proof-only key: ' . $proofOnlyKey);
                }
            }
            $data['id'] = count($this->snapshots) + 1;
            $this->snapshots[] = $data;
            return (int) $data['id'];
        }
        if ($table === 'forward_aggregation_runtime_window_proofs') {
            $data['id'] = count($this->proofs) + 1;
            $this->proofs[] = $data;
            return (int) $data['id'];
        }
        if ($table === 'forward_aggregation_runtime_snapshot_rejections') {
            $data['id'] = count($this->rejections) + 1;
            $this->rejections[] = $data;
            return (int) $data['id'];
        }
        fail('unexpected table ' . $table);
    }
}

function proof_window(string $direction, int $sequence, array $perLeg, int $endAgeMs = 200): array
{
    return [
        'direction' => $direction,
        'sequence' => $sequence,
        'started_monotonic_ms' => 100000 + ($sequence * 10000),
        'ended_monotonic_ms' => 110000 + ($sequence * 10000),
        'end_age_ms' => $endAgeMs,
        'duration_ms' => 10000,
        'total_useful_bytes' => array_sum($perLeg),
        'per_leg_useful_bytes' => $perLeg,
    ];
}

function base_payload(array $overrides = []): array
{
    return array_replace_recursive([
        'schema_version' => 1,
        'runtime_contract_version' => 1,
        'forward_id' => 65,
        'active_family' => 'standalone_bond',
        'desired_generation' => 44,
        'desired_leg_set_hash' => str_repeat('1', 64),
        'runtime_epoch' => 3,
        'transport_session_epoch' => 9,
        'sequence' => 1,
        'sample_age_ms' => 100,
        'lifecycle_state' => 'connected',
        'legs' => [
            ['logical_key' => 'bond:65:tcp:19:316', 'state' => 'active'],
            ['logical_key' => 'bond:65:tcp:20:317', 'state' => 'active'],
        ],
    ], $overrides);
}

$assignment = new BondRuntimeAssignmentContext(
    clientId: 7,
    clientDeviceSessionId: 10,
    forwardId: 65,
    desiredGeneration: 44,
    desiredLegSetHash: str_repeat('1', 64),
    activeFamily: 'standalone_bond',
    logicalLegKeys: [
        'bond:65:tcp:19:316',
        'bond:65:tcp:19:999',
        'bond:65:tcp:20:317',
    ]
);
$db = new SnapshotFakeDb();
$service = new BondAggregationRuntimeSnapshotService($db, static fn(int $clientId, int $deviceSessionId, int $forwardId): ?BondRuntimeAssignmentContext => $assignment);

$missingAge = $service->ingestClientSnapshots(7, 10, [[
    'schema_version' => 1,
    'runtime_contract_version' => 1,
    'forward_id' => 65,
    'active_family' => 'standalone_bond',
    'desired_generation' => 44,
    'desired_leg_set_hash' => str_repeat('1', 64),
    'runtime_epoch' => 1,
    'sequence' => 1,
    'lifecycle_state' => 'connected',
    'legs' => [],
]]);
assert_same('invalid_sample_age', $missingAge['rejected'][0]['error'], 'missing sample age must be rejected');
assert_same(true, $missingAge['rejected'][0]['terminal'], 'immutable malformed sample age must be terminal');

$missingSequence = $service->ingestClientSnapshots(7, 10, [[
    'schema_version' => 1,
    'runtime_contract_version' => 1,
    'forward_id' => 65,
    'active_family' => 'standalone_bond',
    'desired_generation' => 44,
    'desired_leg_set_hash' => str_repeat('1', 64),
    'runtime_epoch' => 1,
    'sample_age_ms' => 10,
    'lifecycle_state' => 'connected',
    'legs' => [],
]]);
assert_same('invalid_runtime_sequence', $missingSequence['rejected'][0]['error'], 'missing sequence must be rejected');

$invalidDesired = $service->ingestClientSnapshots(7, 10, [base_payload(['desired_generation' => 0, 'sequence' => 2])]);
assert_same('invalid_desired_state', $invalidDesired['rejected'][0]['error'], 'missing/non-positive desired generation must be rejected before comparison');

$downloadPayload = base_payload([
    'sample_age_ms' => 14999,
    'traffic_window' => proof_window('download', 101, [
        'bond:65:tcp:19:316' => 1048576,
        'bond:65:tcp:20:317' => 1048576,
    ]),
]);
$accepted = $service->ingestClientSnapshots(7, 10, [$downloadPayload]);
assert_same(1, $accepted['accepted'], 'expected accepted count');
assert_same([['forward_id' => 65, 'runtime_epoch' => 3, 'sequence' => 1]], $accepted['accepted_snapshots'], 'accepted response must include snapshot identity');
assert_same('aggregating', $db->snapshots[0]['derived_state'], 'expected aggregating lifecycle state');
assert_same(1, count($db->proofs), 'download aggregation proof should be stored separately');
assert_same('download', $db->proofs[0]['useful_window_direction'], 'download proof direction must be stored in proof table');
if ((int) $db->snapshots[0]['row_expires_at_epoch_ms'] <= (int) $db->snapshots[0]['received_at_epoch_ms']) {
    fail('sample_age_ms=14999 must retain a positive millisecond freshness interval');
}
if ((int) $db->proofs[0]['row_expires_at_epoch_ms'] <= (int) $db->proofs[0]['received_at_epoch_ms']) {
    fail('proof rows must keep sub-second freshness instead of truncating to seconds');
}

$sameNodePayload = base_payload([
    'sequence' => 2,
    'legs' => [
        ['logical_key' => 'bond:65:tcp:19:316', 'state' => 'active'],
        ['logical_key' => 'bond:65:tcp:19:999', 'state' => 'active'],
    ],
    'traffic_window' => proof_window('download', 102, [
        'bond:65:tcp:19:316' => 1048576,
        'bond:65:tcp:19:999' => 1048576,
    ]),
]);
$sameNode = $service->ingestClientSnapshots(7, 10, [$sameNodePayload]);
assert_same(1, $sameNode['accepted'], 'same-node telemetry row should be accepted as non-aggregating evidence');
assert_same('ready_multi_leg', $db->snapshots[0]['derived_state'], 'two endpoint keys on one Slave must not prove aggregation');
assert_same(1, count($db->proofs), 'same-node useful-byte window must not create an aggregation proof row');

$singleLifecyclePayload = base_payload(['sequence' => 3, 'lifecycle_state' => 'single_leg_only']);
unset($singleLifecyclePayload['traffic_window']);
$singleLifecycle = $service->ingestClientSnapshots(7, 10, [$singleLifecyclePayload]);
assert_same(1, $singleLifecycle['accepted'], 'single-leg lifecycle row should be accepted');
assert_same('single_leg_only', $db->snapshots[0]['derived_state'], 'single_leg_only lifecycle must not be elevated by a stale/prior multi-leg window');

$uploadPayload = base_payload([
    'sequence' => 4,
    'traffic_window' => proof_window('upload', 103, [
        'bond:65:tcp:19:316' => 1048576,
        'bond:65:tcp:20:317' => 1048576,
    ]),
]);
$upload = $service->ingestClientSnapshots(7, 10, [$uploadPayload]);
assert_same(1, $upload['accepted'], 'upload proof should be accepted separately');
assert_same(2, count($db->proofs), 'upload proof must not overwrite download proof');
assert_same(['download', 'upload'], array_column($db->proofs, 'useful_window_direction'), 'proof rows must be keyed by direction');

$badWindow = base_payload(['sequence' => 5, 'traffic_window' => ['direction' => 'download']]);
$badWindowResult = $service->ingestClientSnapshots(7, 10, [$badWindow]);
assert_same('invalid_traffic_window_metadata', $badWindowResult['rejected'][0]['error'], 'malformed proof metadata must be rejected');

$staleWindow = base_payload([
    'sequence' => 6,
    'traffic_window' => proof_window('download', 104, [
        'bond:65:tcp:19:316' => 1048576,
        'bond:65:tcp:20:317' => 1048576,
    ], 15000),
]);
$staleWindowResult = $service->ingestClientSnapshots(7, 10, [$staleWindow]);
assert_same('stale_traffic_window', $staleWindowResult['rejected'][0]['error'], 'traffic_window.end_age_ms=15000 must not refresh proof freshness');

$beforeUploadExpiry = $db->proofs[1]['row_expires_at_epoch_ms'];
$nonAdvancing = base_payload([
    'sequence' => 7,
    'traffic_window' => proof_window('upload', 103, [
        'bond:65:tcp:19:316' => 1048576,
        'bond:65:tcp:20:317' => 1048576,
    ]),
]);
$nonAdvancingResult = $service->ingestClientSnapshots(7, 10, [$nonAdvancing]);
assert_same('non_advancing_traffic_window', $nonAdvancingResult['rejected'][0]['error'], 'same-direction non-advancing traffic window must be rejected');
assert_same($beforeUploadExpiry, $db->proofs[1]['row_expires_at_epoch_ms'], 'non-advancing proof must not refresh expiry');

$stale = $service->ingestClientSnapshots(7, 10, [base_payload(['sequence' => 8, 'sample_age_ms' => 15000])]);
assert_same(0, $stale['accepted'], 'exactly 15 seconds must be stale');
assert_same('stale_snapshot', $stale['rejected'][0]['error'], 'expected stale error');

$badFamily = $service->ingestClientSnapshots(7, 10, [base_payload(['active_family' => 'bond_' . 'fallback', 'runtime_epoch' => 4, 'sequence' => 1])]);
assert_same('unsupported_active_family', $badFamily['rejected'][0]['error'], 'unsupported active family must be rejected');
assert_same(true, $badFamily['rejected'][0]['terminal'], 'unsupported active family is terminal for this snapshot');

$outOfOrder = $service->ingestClientSnapshots(7, 10, [base_payload(['runtime_epoch' => 2, 'sequence' => 99])]);
assert_same('out_of_order_snapshot', $outOfOrder['rejected'][0]['error'], 'lower runtime epoch must be rejected');

$retryableAssignment = new BondAggregationRuntimeSnapshotService(new SnapshotFakeDb(), static fn(int $clientId, int $deviceSessionId, int $forwardId): ?BondRuntimeAssignmentContext => null);
$assignmentResult = $retryableAssignment->ingestClientSnapshots(7, 10, [base_payload(['runtime_epoch' => 9, 'sequence' => 1])]);
assert_same('assignment_not_found', $assignmentResult['rejected'][0]['error'], 'missing assignment should be reported');
assert_same(false, $assignmentResult['rejected'][0]['terminal'], 'assignment/environment failures remain retryable');

echo "ok\n";

```

- [ ] **Step 2: Run the reducer test and verify it fails**

Run:

```bash
php master/tests/bond_aggregation_runtime_snapshot_service_test.php
```

Expected: FAIL because the reducer classes do not exist.

- [ ] **Step 3: Create the assignment context DTO**

Create `master/src/Support/BondRuntimeAssignmentContext.php`:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class BondRuntimeAssignmentContext
{
    /**
     * @param array<int, string> $logicalLegKeys
     */
    public function __construct(
        public readonly int $clientId,
        public readonly int $clientDeviceSessionId,
        public readonly int $forwardId,
        public readonly int $desiredGeneration,
        public readonly string $desiredLegSetHash,
        public readonly string $activeFamily,
        public readonly array $logicalLegKeys
    ) {
    }

    public function hasLogicalLeg(string $logicalKey): bool
    {
        return in_array($logicalKey, $this->logicalLegKeys, true);
    }

    public function nodeIdForLogicalLeg(string $logicalKey): ?int
    {
        $parts = explode(':', $logicalKey);
        if (count($parts) !== 5 || $parts[0] !== 'bond' || $parts[2] !== 'tcp' || !ctype_digit($parts[3])) {
            return null;
        }
        $nodeId = (int) $parts[3];
        return $nodeId > 0 ? $nodeId : null;
    }

    /**
     * @param array<int, string> $logicalKeys
     */
    public function distinctNodeCountForKeys(array $logicalKeys): int
    {
        $nodes = [];
        foreach ($logicalKeys as $key) {
            $nodeId = $this->nodeIdForLogicalLeg($key);
            if ($nodeId !== null) {
                $nodes[$nodeId] = true;
            }
        }
        return count($nodes);
    }

    public function distinctDesiredNodeCount(): int
    {
        return $this->distinctNodeCountForKeys($this->logicalLegKeys);
    }
}
```

- [ ] **Step 4: Write the failing assignment repository test**

Create `master/tests/bond_runtime_assignment_repository_test.php`:

```php
<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\BondRuntimeAssignmentRepository;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

final class AssignmentFakeDb
{
    /** @var array<int, array<string, mixed>> */
    public array $clients = [];
    /** @var array<int, array<string, mixed>> */
    public array $forwards = [];
    /** @var array<int, array<string, mixed>> */
    public array $combinations = [];
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $endpointsByForward = [];

    public function fetchOne(string $sql, array $params = []): ?array
    {
        if (str_contains($sql, 'FROM clients')) {
            return $this->clients[(int) $params['id']] ?? null;
        }
        if (str_contains($sql, 'FROM forwards')) {
            if (str_contains($sql, 'f.runtime_preference')) {
                fail('repository must not query nonexistent forwards.runtime_preference');
            }
            if (!str_contains($sql, 'JOIN forward_combinations combo ON combo.id = f.forward_combination_id')) {
                fail('repository must strict-join the delivered forward_combination_id contract');
            }
            if (str_contains($sql, 'LEFT JOIN forward_combinations') || str_contains($sql, 'COALESCE(')) {
                fail('repository must not fallback from combo fields to forwards legacy mode fields');
            }
            $forward = $this->forwards[(int) $params['forward_id']] ?? null;
            if ($forward === null || (int) ($forward['enabled'] ?? 0) !== 1 || empty($forward['forward_combination_id'])) {
                return null;
            }
            $combo = $this->combinations[(int) $forward['forward_combination_id']] ?? null;
            if (!is_array($combo)) {
                return null;
            }
            return $forward + [
                'effective_backend_mode' => $combo['backend_mode'] ?? '',
                'effective_client_mode' => $combo['client_mode'] ?? '',
                'effective_runtime_preference' => $combo['runtime_preference'] ?? '',
                'effective_bond_ingress_mode' => $combo['bond_ingress_mode'] ?? '',
                'combination_updated_at' => $combo['updated_at'] ?? null,
            ];
        }
        return null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'FROM service_endpoints')) {
            if (str_contains($sql, '.status')) {
                fail('repository must use service_endpoints.health_status');
            }
            return array_values(array_filter(
                $this->endpointsByForward[(int) $params['forward_id']] ?? [],
                static fn(array $row): bool => !in_array((string) ($row['health_status'] ?? ''), ['deleted', 'deleting'], true)
            ));
        }
        return [];
    }
}

function seeded_db(): AssignmentFakeDb
{
    $db = new AssignmentFakeDb();
    $db->clients[7] = ['id' => 7, 'group_id' => 70];
    $db->combinations[100] = [
        'id' => 100,
        'backend_mode' => 'leigod_mode',
        'client_mode' => 'bond',
        'runtime_preference' => 'single',
        'bond_ingress_mode' => 'plain_tcp_mux',
        'updated_at' => '2026-07-19 00:00:00',
    ];
    $db->combinations[101] = [
        'id' => 101,
        'backend_mode' => 'cf_mode',
        'client_mode' => 'bond',
        'runtime_preference' => 'single',
        'bond_ingress_mode' => 'plain_tcp_mux',
        'updated_at' => '2026-07-19 00:00:01',
    ];
    $db->forwards[65] = [
        'id' => 65,
        'enabled' => 1,
        'line_client_id' => 7,
        'line_client_group_id' => null,
        'line_client_group_ids_json' => null,
        'forward_combination_id' => 100,
        'active_combination_id' => 101,
        'backend_mode' => 'leigod_mode',
        'client_mode' => 'bond',
        'bond_ingress_mode' => 'plain_tcp_mux',
        'forward_updated_at' => '2026-07-19 00:00:02',
    ];
    $db->forwards[66] = $db->forwards[65] + ['id' => 66, 'line_client_id' => null, 'line_client_group_id' => 70];
    $db->forwards[67] = $db->forwards[65] + ['id' => 67, 'line_client_id' => null, 'line_client_group_id' => null, 'line_client_group_ids_json' => '[70,71]'];
    foreach ([65, 66, 67] as $forwardId) {
        $db->endpointsByForward[$forwardId] = [
            ['id' => 316, 'node_id' => 19, 'health_status' => 'active', 'updated_at' => '2026-07-19 00:00:03'],
            ['id' => 317, 'node_id' => 20, 'health_status' => 'pending', 'updated_at' => '2026-07-19 00:00:04'],
            ['id' => 318, 'node_id' => 21, 'health_status' => 'deleted', 'updated_at' => '2026-07-19 00:00:05'],
            ['id' => 319, 'node_id' => 22, 'health_status' => 'deleting', 'updated_at' => '2026-07-19 00:00:06'],
        ];
    }
    return $db;
}

$db = seeded_db();
$repo = new BondRuntimeAssignmentRepository($db);
$direct = $repo->findForClientDeviceForward(7, 10, 65);
assert_same(['bond:65:tcp:19:316', 'bond:65:tcp:20:317'], $direct?->logicalLegKeys, 'direct assignment should include only non-deleted endpoints');
assert_same('standalone_bond', $direct?->activeFamily, 'active family');
assert_same(2, $direct?->distinctDesiredNodeCount(), 'distinct nodes');

$primaryGroup = $repo->findForClientDeviceForward(7, 10, 66);
assert_same(['bond:66:tcp:19:316', 'bond:66:tcp:20:317'], $primaryGroup?->logicalLegKeys, 'primary group assignment');
$jsonGroup = $repo->findForClientDeviceForward(7, 10, 67);
assert_same(['bond:67:tcp:19:316', 'bond:67:tcp:20:317'], $jsonGroup?->logicalLegKeys, 'JSON group assignment');

$hashA = BondRuntimeAssignmentRepository::desiredLegSetHash(['bond:65:tcp:20:317', 'bond:65:tcp:19:316']);
$hashB = BondRuntimeAssignmentRepository::desiredLegSetHash(['bond:65:tcp:19:316', 'bond:65:tcp:20:317']);
assert_same($hashA, $hashB, 'leg-set hash must be deterministic regardless of order');
if ((int) $direct?->desiredGeneration <= 0) {
    fail('desired generation should be positive');
}

$db = seeded_db();
$db->combinations[100]['backend_mode'] = 'current_mode';
try {
    (new BondRuntimeAssignmentRepository($db))->findForClientDeviceForward(7, 10, 65);
    fail('unsupported contract unexpectedly accepted');
} catch (ApiException $exception) {
    assert_same('unsupported_leigod_runtime_contract', $exception->getMessage(), 'unsupported contract error');
}

$db = seeded_db();
$db->forwards[65]['forward_combination_id'] = null;
$missingPointer = (new BondRuntimeAssignmentRepository($db))->findForClientDeviceForward(7, 10, 65);
if ($missingPointer !== null) {
    fail('missing forward_combination_id must fail closed');
}

$db = seeded_db();
unset($db->combinations[100]);
$missingCombo = (new BondRuntimeAssignmentRepository($db))->findForClientDeviceForward(7, 10, 65);
if ($missingCombo !== null) {
    fail('missing joined forward_combinations row must fail closed instead of using forwards legacy fields');
}

$db = seeded_db();
$db->combinations[100]['runtime_preference'] = '';
try {
    (new BondRuntimeAssignmentRepository($db))->findForClientDeviceForward(7, 10, 65);
    fail('empty combo runtime_preference unexpectedly accepted');
} catch (ApiException $exception) {
    assert_same('unsupported_leigod_runtime_contract', $exception->getMessage(), 'empty runtime preference error');
}

$db = seeded_db();
$db->endpointsByForward[65] = [];
for ($i = 0; $i < 9; $i++) {
    $db->endpointsByForward[65][] = ['id' => 400 + $i, 'node_id' => 800 + $i, 'health_status' => 'active', 'updated_at' => '2026-07-19 00:00:07'];
}
try {
    (new BondRuntimeAssignmentRepository($db))->findForClientDeviceForward(7, 10, 65);
    fail('too many legs unexpectedly accepted');
} catch (ApiException $exception) {
    assert_same('too_many_desired_aggregation_legs', $exception->getMessage(), 'leg cap error');
}

$db = seeded_db();
$ctx = (new BondRuntimeAssignmentRepository($db))->findForClientDeviceForward(7, 10, 65);
if ($ctx === null) {
    fail('forward_combination_id source-of-truth guard unexpectedly failed');
}

$repoSource = file_get_contents(__DIR__ . '/../src/Support/BondRuntimeAssignmentRepository.php') ?: '';
foreach (['f.runtime_preference', 'forwards.client_id', 'service_endpoints.status'] as $forbidden) {
    if (str_contains($repoSource, $forbidden)) {
        fail('forbidden nonexistent schema reference: ' . $forbidden);
    }
}
if (!str_contains($repoSource, 'combo.runtime_preference AS effective_runtime_preference')) {
    fail('runtime preference must come from forward_combinations only');
}
if (!str_contains($repoSource, 'JOIN forward_combinations combo ON combo.id = f.forward_combination_id')) {
    fail('assignment source must strict-join the delivered forward_combination_id contract');
}
if (str_contains($repoSource, 'LEFT JOIN forward_combinations') || str_contains($repoSource, 'COALESCE(')) {
    fail('assignment repository must not fallback to legacy forwards mode fields');
}
if (str_contains($repoSource, '$runtimePreference = \'single\';')) {
    fail('assignment repository must not default an empty combo runtime_preference');
}
if (!str_contains($repoSource, 'active_combination_id')) {
    fail('repository must document active_combination_id vs forward_combination_id guard');
}

echo "ok\n";
```

- [ ] **Step 5: Run the assignment repository test and verify it fails**

Run:

```bash
php master/tests/bond_runtime_assignment_repository_test.php
```

Expected: FAIL because `BondRuntimeAssignmentRepository` does not exist yet.

- [ ] **Step 6: Create the assignment repository with a deterministic hash helper**

Create `master/src/Support/BondRuntimeAssignmentRepository.php`:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class BondRuntimeAssignmentRepository
{
    public function __construct(private object $db)
    {
    }

    public function findForClientDeviceForward(int $clientId, int $clientDeviceSessionId, int $forwardId): ?BondRuntimeAssignmentContext
    {
        $client = $this->db->fetchOne(
            'SELECT id, group_id FROM clients WHERE id = :id LIMIT 1',
            ['id' => $clientId]
        );
        if ($client === null) {
            return null;
        }

        $forward = $this->db->fetchOne(
            'SELECT f.id,
                    f.enabled,
                    f.line_client_id,
                    f.line_client_group_id,
                    f.line_client_group_ids_json,
                    f.forward_combination_id,
                    f.active_combination_id,
                    f.updated_at AS forward_updated_at,
                    combo.backend_mode AS effective_backend_mode,
                    combo.client_mode AS effective_client_mode,
                    combo.runtime_preference AS effective_runtime_preference,
                    combo.bond_ingress_mode AS effective_bond_ingress_mode,
                    combo.updated_at AS combination_updated_at
             FROM forwards f
             /*
              * IMPORTANT: current ProvisioningService config delivery joins by
              * f.forward_combination_id. f.active_combination_id exists, but is
              * not the delivered source of truth today. Telemetry must mirror the
              * assignment the client actually received; if delivery moves to
              * active_combination_id, update this repository and its test in the
              * same commit.
              */
             JOIN forward_combinations combo ON combo.id = f.forward_combination_id
             WHERE f.id = :forward_id
               AND f.enabled = 1
             LIMIT 1',
            ['forward_id' => $forwardId]
        );
        if ($forward === null || !self::clientMatchesForward($client, $forward)) {
            return null;
        }
        $backendMode = trim((string) ($forward['effective_backend_mode'] ?? ''));
        $clientMode = trim((string) ($forward['effective_client_mode'] ?? ''));
        $runtimePreference = trim((string) ($forward['effective_runtime_preference'] ?? ''));
        $bondIngressMode = trim((string) ($forward['effective_bond_ingress_mode'] ?? ''));
        if ($backendMode !== 'leigod_mode'
            || $clientMode !== 'bond'
            || $runtimePreference !== 'single'
            || $bondIngressMode !== 'plain_tcp_mux') {
            throw new ApiException('unsupported_leigod_runtime_contract', 422);
        }

        $rows = $this->db->fetchAll(
            'SELECT id, node_id, health_status, updated_at
             FROM service_endpoints
             WHERE forward_id = :forward_id
               AND health_status <> :deleted
               AND health_status <> :deleting
             ORDER BY node_id ASC, id ASC',
            ['forward_id' => $forwardId, 'deleted' => 'deleted', 'deleting' => 'deleting']
        );
        $logicalKeys = [];
        $distinctNodes = [];
        foreach ($rows as $row) {
            $nodeId = (int) ($row['node_id'] ?? 0);
            $endpointId = (int) ($row['id'] ?? 0);
            if ($nodeId <= 0 || $endpointId <= 0) {
                continue;
            }
            $distinctNodes[$nodeId] = true;
            $logicalKeys[] = self::logicalLegKey($forwardId, 'tcp', $nodeId, $endpointId);
        }
        if (count($distinctNodes) > 8) {
            throw new ApiException('too_many_desired_aggregation_legs', 422);
        }

        return new BondRuntimeAssignmentContext(
            clientId: $clientId,
            clientDeviceSessionId: $clientDeviceSessionId,
            forwardId: $forwardId,
            desiredGeneration: self::desiredGeneration($forward, $rows),
            desiredLegSetHash: self::desiredLegSetHash($logicalKeys),
            activeFamily: 'standalone_bond',
            logicalLegKeys: $logicalKeys
        );
    }

    /**
     * Mirror ProvisioningService::assignedForwardConfigsForClient(): direct client,
     * primary client group, or JSON group list assignment. Do not invent f.client_id.
     *
     * @param array<string, mixed> $client
     * @param array<string, mixed> $forward
     */
    private static function clientMatchesForward(array $client, array $forward): bool
    {
        $clientId = (int) ($client['id'] ?? 0);
        if ($clientId > 0 && (int) ($forward['line_client_id'] ?? 0) === $clientId) {
            return true;
        }
        $clientGroupId = (int) ($client['group_id'] ?? 0);
        if ($clientGroupId <= 0) {
            return false;
        }
        if ((int) ($forward['line_client_group_id'] ?? 0) === $clientGroupId) {
            return true;
        }
        $rawGroups = (string) ($forward['line_client_group_ids_json'] ?? '');
        if ($rawGroups === '') {
            return false;
        }
        $decoded = json_decode($rawGroups, true);
        if (!is_array($decoded)) {
            return false;
        }
        foreach ($decoded as $groupId) {
            if ((int) $groupId === $clientGroupId) {
                return true;
            }
        }
        return false;
    }

    public static function logicalLegKey(int $forwardId, string $protocol, int $nodeId, int $endpointId): string
    {
        $protocol = strtolower(trim($protocol));
        if ($protocol !== 'tcp') {
            throw new ApiException('unsupported_aggregation_protocol', 422);
        }
        return 'bond:' . $forwardId . ':tcp:' . $nodeId . ':' . $endpointId;
    }

    /**
     * @param array<string, mixed> $forward
     * @param array<int, array<string, mixed>> $endpointRows
     */
    public static function desiredGeneration(array $forward, array $endpointRows): int
    {
        $source = [
            'forward_id' => (int) ($forward['id'] ?? 0),
            'forward_combination_id' => (int) ($forward['forward_combination_id'] ?? 0),
            'forward_updated_at' => (string) ($forward['forward_updated_at'] ?? ''),
            'combination_updated_at' => (string) ($forward['combination_updated_at'] ?? ''),
            'endpoints' => array_map(static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'node_id' => (int) ($row['node_id'] ?? 0),
                'health_status' => (string) ($row['health_status'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ], $endpointRows),
        ];
        $digest = hash('sha256', json_encode($source, JSON_UNESCAPED_SLASHES));
        return 1 + (int) (hexdec(substr($digest, 0, 7)) % 2147483646);
    }

    /**
     * @param array<int, string> $logicalKeys
     */
    public static function desiredLegSetHash(array $logicalKeys): string
    {
        $unique = array_values(array_unique($logicalKeys));
        sort($unique, SORT_STRING);
        return hash('sha256', json_encode($unique, JSON_UNESCAPED_SLASHES));
    }
}
```

This repository deliberately mirrors the current config-delivery assignment boundary (`line_client_id`, `line_client_group_id`, `line_client_group_ids_json`, and `forward_combination_id`). `active_combination_id` is selected only as a documented guardrail because current config delivery does not use it; if delivery changes, the assignment repository and `master/tests/bond_runtime_assignment_repository_test.php` must change in the same commit. Do not use non-existent `forwards.client_id`, `forwards.generation`, `forwards.updated_generation`, `forwards.runtime_preference`, or `service_endpoints.status` columns.

- [ ] **Step 7: Create the standalone-bond snapshot reducer**

Implementation requirements for this service are part of the contract and are tested by the reducer test above:

- `forward_aggregation_runtime_snapshots` stores only the latest lifecycle row keyed by `(client_id, client_device_session_id, forward_id, active_family)`.
- `forward_aggregation_runtime_window_proofs` stores upload/download useful-byte proof rows keyed by `(client_id, client_device_session_id, forward_id, active_family, useful_window_direction)`. Upload and download proofs must not overwrite each other or the lifecycle row.
- `ingestClientSnapshots()` returns `accepted`, `accepted_snapshots`, and `rejected`; every accepted identity includes `forward_id`, `runtime_epoch`, and `sequence`; every rejected identity includes those fields when available plus `error` and `terminal`.
- Validate desired state before comparing it: missing/non-numeric/non-positive `desired_generation` or a non-`/^[a-f0-9]{64}$/` `desired_leg_set_hash` rejects as `invalid_desired_state`.
- A proof traffic window must include `direction`, `sequence`, `started_monotonic_ms`, `ended_monotonic_ms`, `end_age_ms`, `duration_ms`, `total_useful_bytes`, and `per_leg_useful_bytes`; malformed metadata rejects as `invalid_traffic_window_metadata`.
- `sample_age_ms >= 15000` is stale. `traffic_window.end_age_ms >= 15000` cannot create or refresh a proof row.
- Same-direction non-advancing proof windows (`traffic_window_sequence` not greater than the latest stored proof, or identical `traffic_window_state_hash`) reject as `non_advancing_traffic_window` and must not refresh proof expiry.
- Lifecycle freshness and proof freshness use epoch milliseconds and UTC `DATETIME(3)`; no second-truncation expiry is allowed.

Create `master/src/Support/BondAggregationRuntimeSnapshotService.php`:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class BondAggregationRuntimeSnapshotService
{
    private const MAX_SNAPSHOTS_PER_REQUEST = 64;
    private const MAX_REQUEST_BYTES = 524288;
    private const MAX_SNAPSHOT_BYTES = 16384;
    private const FRESH_AGE_MS = 15000;
    private const MIN_USEFUL_BYTES = 1048576;
    private const MIN_LEG_SHARE = 0.05;

    /** @var callable */
    private $assignmentResolver;

    public function __construct(private object $db, ?callable $assignmentResolver = null)
    {
        $this->assignmentResolver = $assignmentResolver ?? function (int $clientId, int $deviceSessionId, int $forwardId): ?BondRuntimeAssignmentContext {
            return (new BondRuntimeAssignmentRepository($this->db))->findForClientDeviceForward($clientId, $deviceSessionId, $forwardId);
        };
    }

    /**
     * @param array<int, mixed> $snapshots
     * @return array{accepted:int,accepted_snapshots:array<int,array<string,int>>,rejected:array<int,array<string,mixed>>}
     */
    public function ingestClientSnapshots(int $clientId, int $clientDeviceSessionId, array $snapshots): array
    {
        if (count($snapshots) > self::MAX_SNAPSHOTS_PER_REQUEST) {
            throw new ApiException('aggregation_snapshot_request_too_large', 413);
        }
        $encoded = json_encode($snapshots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || strlen($encoded) > self::MAX_REQUEST_BYTES) {
            throw new ApiException('aggregation_snapshot_request_too_large', 413);
        }

        $accepted = 0;
        $acceptedSnapshots = [];
        $rejected = [];
        foreach ($snapshots as $snapshot) {
            if (!is_array($snapshot)) {
                $rejected[] = ['forward_id' => null, 'runtime_epoch' => null, 'sequence' => null, 'error' => 'malformed_snapshot', 'terminal' => true];
                continue;
            }
            $result = $this->ingestOne($clientId, $clientDeviceSessionId, $snapshot);
            if ($result['ok']) {
                $accepted++;
                $acceptedSnapshots[] = ['forward_id' => $result['forward_id'], 'runtime_epoch' => $result['runtime_epoch'], 'sequence' => $result['sequence']];
            } else {
                $rejected[] = ['forward_id' => $result['forward_id'], 'runtime_epoch' => $result['runtime_epoch'], 'sequence' => $result['sequence'], 'error' => $result['error'], 'terminal' => $result['terminal']];
            }
        }
        return ['accepted' => $accepted, 'accepted_snapshots' => $acceptedSnapshots, 'rejected' => $rejected];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array{ok:bool,forward_id:?int,runtime_epoch:?int,sequence:?int,error:?string,terminal:bool}
     */
    private function ingestOne(int $clientId, int $clientDeviceSessionId, array $snapshot): array
    {
        $forwardId = max(0, (int) ($snapshot['forward_id'] ?? 0));
        if ($forwardId <= 0) {
            return $this->reject($clientId, $clientDeviceSessionId, null, $snapshot, 'invalid_forward_id');
        }
        $rawSnapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($rawSnapshotJson === false || strlen($rawSnapshotJson) > self::MAX_SNAPSHOT_BYTES) {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'snapshot_too_large');
        }
        if ((string) ($snapshot['active_family'] ?? '') !== 'standalone_bond') {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'unsupported_active_family');
        }
        if (!array_key_exists('sample_age_ms', $snapshot) || !is_numeric($snapshot['sample_age_ms'])) {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'invalid_sample_age');
        }
        $sampleAgeMs = max(0, (int) $snapshot['sample_age_ms']);
        if ($sampleAgeMs >= self::FRESH_AGE_MS) {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'stale_snapshot');
        }
        if ((int) ($snapshot['schema_version'] ?? 0) !== 1 || (int) ($snapshot['runtime_contract_version'] ?? 0) !== 1) {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'unsupported_snapshot_contract_version');
        }

        $assignment = ($this->assignmentResolver)($clientId, $clientDeviceSessionId, $forwardId);
        if (!$assignment instanceof BondRuntimeAssignmentContext) {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'assignment_not_found');
        }
        if ($assignment->activeFamily !== 'standalone_bond') {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'unsupported_assignment_family');
        }
        if (!array_key_exists('desired_generation', $snapshot) || !is_numeric($snapshot['desired_generation']) || (int) $snapshot['desired_generation'] <= 0
            || !array_key_exists('desired_leg_set_hash', $snapshot) || !preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string) $snapshot['desired_leg_set_hash'])))) {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'invalid_desired_state');
        }
        if ((int) $snapshot['desired_generation'] !== $assignment->desiredGeneration) {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'desired_generation_mismatch');
        }
        if (!hash_equals($assignment->desiredLegSetHash, strtolower(trim((string) $snapshot['desired_leg_set_hash'])))) {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'desired_leg_set_hash_mismatch');
        }

        if (!array_key_exists('runtime_epoch', $snapshot) || !is_numeric($snapshot['runtime_epoch'])
            || !array_key_exists('sequence', $snapshot) || !is_numeric($snapshot['sequence'])) {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'invalid_runtime_sequence');
        }
        $runtimeEpoch = max(0, (int) $snapshot['runtime_epoch']);
        $sequence = max(0, (int) $snapshot['sequence']);
        $trafficWindow = is_array($snapshot['traffic_window'] ?? null) ? $snapshot['traffic_window'] : null;
        $receivedAtMs = (int) floor(microtime(true) * 1000);
        $receivedAt = self::formatEpochMs($receivedAtMs);
        $clientObservedAtMs = max(0, $receivedAtMs - $sampleAgeMs);
        $clientObservedAt = self::formatEpochMs($clientObservedAtMs);
        $rowExpiresAtMs = $receivedAtMs + max(1, self::FRESH_AGE_MS - $sampleAgeMs);
        $rowExpiresAt = self::formatEpochMs($rowExpiresAtMs);

        $preparedProof = null;
        if ($trafficWindow !== null) {
            $proofResult = $this->prepareProofRow($clientId, $clientDeviceSessionId, $forwardId, $snapshot, $assignment, $runtimeEpoch, $sequence, $trafficWindow, $receivedAtMs, $rowExpiresAtMs);
            if (!$proofResult['ok']) {
                return $proofResult;
            }
            $preparedProof = $proofResult['proof'];
        }

        $state = $this->deriveState($snapshot, $assignment);
        $lifecycleSnapshot = self::lifecycleSnapshot($snapshot);
        $snapshotJson = json_encode($lifecycleSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $stateHash = hash('sha256', $snapshotJson);
        $row = [
            'client_id' => $clientId,
            'client_device_session_id' => $clientDeviceSessionId,
            'forward_id' => $forwardId,
            'active_family' => 'standalone_bond',
            'desired_generation' => $assignment->desiredGeneration,
            'desired_leg_set_hash' => $assignment->desiredLegSetHash,
            'runtime_epoch' => $runtimeEpoch,
            'transport_session_epoch' => isset($snapshot['transport_session_epoch']) ? max(0, (int) $snapshot['transport_session_epoch']) : null,
            'sequence' => $sequence,
            'lifecycle_state' => substr(trim((string) ($snapshot['lifecycle_state'] ?? 'unknown')), 0, 32),
            'derived_state' => $state,
            'selected_logical_leg_key' => isset($snapshot['selected_logical_leg_key']) ? substr(trim((string) $snapshot['selected_logical_leg_key']), 0, 128) : null,
            'selected_reason' => isset($snapshot['selected_reason']) ? substr(trim((string) $snapshot['selected_reason']), 0, 64) : null,
            'snapshot_json' => $snapshotJson,
            'state_hash' => $stateHash,
            'sample_age_ms' => $sampleAgeMs,
            'client_observed_at' => $clientObservedAt,
            'client_observed_at_epoch_ms' => $clientObservedAtMs,
            'received_at' => $receivedAt,
            'received_at_epoch_ms' => $receivedAtMs,
            'row_expires_at' => $rowExpiresAt,
            'row_expires_at_epoch_ms' => $rowExpiresAtMs,
            'created_at' => $receivedAt,
            'updated_at' => $receivedAt,
        ];

        $write = function () use ($clientId, $clientDeviceSessionId, $forwardId, $runtimeEpoch, $sequence, $row, $preparedProof): string {
            $existing = $this->db->fetchOne(
                'SELECT * FROM forward_aggregation_runtime_snapshots
                 WHERE client_id = :client_id
                   AND client_device_session_id = :client_device_session_id
                   AND forward_id = :forward_id
                   AND active_family = :active_family
                 LIMIT 1
                 FOR UPDATE',
                [
                    'client_id' => $clientId,
                    'client_device_session_id' => $clientDeviceSessionId,
                    'forward_id' => $forwardId,
                    'active_family' => 'standalone_bond',
                ]
            );
            if ($existing !== null) {
                $oldEpoch = (int) ($existing['runtime_epoch'] ?? 0);
                $oldSequence = (int) ($existing['sequence'] ?? 0);
                if ($runtimeEpoch < $oldEpoch || ($runtimeEpoch === $oldEpoch && $sequence <= $oldSequence)) {
                    return 'out_of_order_snapshot';
                }
            }

            if (is_array($preparedProof)) {
                $proofOutcome = $this->upsertPreparedProofRow($clientId, $clientDeviceSessionId, $forwardId, $preparedProof);
                if ($proofOutcome !== 'ok') {
                    return $proofOutcome;
                }
            }

            if ($existing !== null) {
                $assignments = [];
                foreach ($row as $key => $value) {
                    if ($key === 'created_at') {
                        continue;
                    }
                    $assignments[] = $key . ' = :' . $key;
                }
                $this->db->execute(
                    'UPDATE forward_aggregation_runtime_snapshots SET ' . implode(', ', $assignments) . '
                     WHERE client_id = :client_id_key
                       AND client_device_session_id = :client_device_session_id_key
                       AND forward_id = :forward_id_key
                       AND active_family = :active_family_key',
                    $row + [
                        'client_id_key' => $clientId,
                        'client_device_session_id_key' => $clientDeviceSessionId,
                        'forward_id_key' => $forwardId,
                        'active_family_key' => 'standalone_bond',
                    ]
                );
            } else {
                $this->db->insert('forward_aggregation_runtime_snapshots', $row);
            }
            return 'ok';
        };

        $outcome = method_exists($this->db, 'transaction') ? $this->db->transaction($write) : $write();
        if ($outcome !== 'ok') {
            return $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, (string) $outcome);
        }
        return ['ok' => true, 'forward_id' => $forwardId, 'runtime_epoch' => $runtimeEpoch, 'sequence' => $sequence, 'error' => null, 'terminal' => false];
    }

    private function deriveState(array $snapshot, BondRuntimeAssignmentContext $assignment): string
    {
        $lifecycle = strtolower(trim((string) ($snapshot['lifecycle_state'] ?? '')));
        $legs = is_array($snapshot['legs'] ?? null) ? $snapshot['legs'] : [];
        if (!in_array($lifecycle, ['connected', 'degraded', 'single_leg_only'], true)) {
            return 'unavailable';
        }

        if ($lifecycle === 'single_leg_only') {
            return 'single_leg_only';
        }

        $eligibleKeys = [];
        foreach ($legs as $leg) {
            if (!is_array($leg)) {
                continue;
            }
            $key = trim((string) ($leg['logical_key'] ?? ''));
            $state = strtolower(trim((string) ($leg['state'] ?? '')));
            if ($key !== '' && $assignment->hasLogicalLeg($key) && in_array($state, ['active', 'healthy', 'probation'], true)) {
                $eligibleKeys[$key] = true;
            }
        }
        $eligibleNodeCount = $assignment->distinctNodeCountForKeys(array_keys($eligibleKeys));
        $desiredCount = $assignment->distinctDesiredNodeCount();
        if ($eligibleNodeCount <= 0) {
            return 'unavailable';
        }
        if ($desiredCount <= 1 || $eligibleNodeCount === 1) {
            return $desiredCount <= 1 ? 'single_leg_only' : 'degraded_single_leg';
        }

        $window = is_array($snapshot['traffic_window'] ?? null) ? $snapshot['traffic_window'] : null;
        if ($window === null) {
            return 'ready_multi_leg';
        }
        $total = max(0, (int) ($window['total_useful_bytes'] ?? 0));
        if ($total < self::MIN_USEFUL_BYTES) {
            return 'ready_multi_leg';
        }
        $direction = $this->directionOrNull($window['direction'] ?? null);
        if ($direction === null) {
            return 'ready_multi_leg';
        }
        $perLeg = is_array($window['per_leg_useful_bytes'] ?? null) ? $window['per_leg_useful_bytes'] : [];
        $contributorKeys = [];
        foreach ($perLeg as $key => $bytes) {
            $key = (string) $key;
            $bytes = max(0, (int) $bytes);
            if (isset($eligibleKeys[$key]) && $total > 0 && ($bytes / $total) >= self::MIN_LEG_SHARE) {
                $contributorKeys[] = $key;
            }
        }
        if ($assignment->distinctNodeCountForKeys($contributorKeys) >= 2) {
            return 'aggregating';
        }
        return $lifecycle === 'degraded' ? 'degraded_single_leg' : 'ready_multi_leg';
    }

    /**
     * Validate one upload/download proof before the lifecycle row is inserted or updated. Non-qualifying
     * but well-formed traffic windows remain lifecycle evidence only and return proof=null. Malformed,
     * stale, or non-advancing proof windows reject the whole snapshot before lifecycle sequence/expiry can
     * advance.
     *
     * @return array{ok:bool,proof:?array<string,mixed>,forward_id:int,runtime_epoch:int,sequence:int,error:?string,terminal:bool}
     */
    private function prepareProofRow(int $clientId, int $clientDeviceSessionId, int $forwardId, array $snapshot, BondRuntimeAssignmentContext $assignment, int $runtimeEpoch, int $sequence, array $trafficWindow, int $receivedAtMs, int $snapshotExpiresAtMs): array
    {
        foreach (['direction', 'sequence', 'started_monotonic_ms', 'ended_monotonic_ms', 'end_age_ms', 'duration_ms', 'total_useful_bytes', 'per_leg_useful_bytes'] as $required) {
            if (!array_key_exists($required, $trafficWindow)) {
                return $this->rejectWithProofShape($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'invalid_traffic_window_metadata');
            }
        }
        $direction = $this->directionOrNull($trafficWindow['direction'] ?? null);
        if ($direction === null || !is_array($trafficWindow['per_leg_useful_bytes'])) {
            return $this->rejectWithProofShape($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'invalid_traffic_window_metadata');
        }
        foreach (['sequence', 'started_monotonic_ms', 'ended_monotonic_ms', 'end_age_ms', 'duration_ms', 'total_useful_bytes'] as $numericKey) {
            if (!is_numeric($trafficWindow[$numericKey])) {
                return $this->rejectWithProofShape($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'invalid_traffic_window_metadata');
            }
        }
        $windowSequence = max(0, (int) $trafficWindow['sequence']);
        $startedMs = max(0, (int) $trafficWindow['started_monotonic_ms']);
        $endedMs = max(0, (int) $trafficWindow['ended_monotonic_ms']);
        $endAgeMs = max(0, (int) $trafficWindow['end_age_ms']);
        $durationMs = max(0, (int) $trafficWindow['duration_ms']);
        $totalBytes = max(0, (int) $trafficWindow['total_useful_bytes']);
        if ($windowSequence <= 0 || $endedMs <= $startedMs || $durationMs <= 0) {
            return $this->rejectWithProofShape($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'invalid_traffic_window_metadata');
        }
        if ($endAgeMs >= self::FRESH_AGE_MS) {
            return $this->rejectWithProofShape($clientId, $clientDeviceSessionId, $forwardId, $snapshot, 'stale_traffic_window');
        }

        $perLeg = [];
        foreach ($trafficWindow['per_leg_useful_bytes'] as $key => $bytes) {
            $key = trim((string) $key);
            if ($key === '' || !$assignment->hasLogicalLeg($key)) {
                continue;
            }
            $perLeg[$key] = max(0, (int) $bytes);
        }
        ksort($perLeg, SORT_STRING);
        $contributorKeys = [];
        foreach ($perLeg as $key => $bytes) {
            if ($totalBytes > 0 && ($bytes / $totalBytes) >= self::MIN_LEG_SHARE) {
                $contributorKeys[] = $key;
            }
        }
        if ($totalBytes < self::MIN_USEFUL_BYTES || $assignment->distinctNodeCountForKeys($contributorKeys) < 2) {
            return ['ok' => true, 'proof' => null, 'forward_id' => $forwardId, 'runtime_epoch' => $runtimeEpoch, 'sequence' => $sequence, 'error' => null, 'terminal' => false];
        }

        $proofJson = json_encode($trafficWindow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $proofHash = hash('sha256', $direction . '|' . $startedMs . '|' . $endedMs . '|' . $totalBytes . '|' . json_encode($perLeg, JSON_UNESCAPED_SLASHES));
        $proofExpiresAtMs = $receivedAtMs + max(1, self::FRESH_AGE_MS - $endAgeMs);
        $rowExpiresAtMs = min($snapshotExpiresAtMs, $proofExpiresAtMs);
        $receivedAt = self::formatEpochMs($receivedAtMs);
        $rowExpiresAt = self::formatEpochMs($rowExpiresAtMs);
        return [
            'ok' => true,
            'forward_id' => $forwardId,
            'runtime_epoch' => $runtimeEpoch,
            'sequence' => $sequence,
            'error' => null,
            'terminal' => false,
            'proof' => [
                'direction_key' => $direction,
                'window_sequence_key' => $windowSequence,
                'traffic_window_state_hash_key' => $proofHash,
                'row' => [
                    'client_id' => $clientId,
                    'client_device_session_id' => $clientDeviceSessionId,
                    'forward_id' => $forwardId,
                    'active_family' => 'standalone_bond',
                    'desired_generation' => $assignment->desiredGeneration,
                    'desired_leg_set_hash' => $assignment->desiredLegSetHash,
                    'runtime_epoch' => $runtimeEpoch,
                    'transport_session_epoch' => isset($snapshot['transport_session_epoch']) ? max(0, (int) $snapshot['transport_session_epoch']) : null,
                    'sequence' => $sequence,
                    'useful_window_direction' => $direction,
                    'traffic_window_sequence' => $windowSequence,
                    'traffic_window_started_monotonic_ms' => $startedMs,
                    'traffic_window_ended_monotonic_ms' => $endedMs,
                    'traffic_window_end_age_ms' => $endAgeMs,
                    'traffic_window_state_hash' => $proofHash,
                    'duration_ms' => $durationMs,
                    'useful_window_total_bytes' => $totalBytes,
                    'traffic_window_json' => $proofJson,
                    'received_at' => $receivedAt,
                    'received_at_epoch_ms' => $receivedAtMs,
                    'row_expires_at' => $rowExpiresAt,
                    'row_expires_at_epoch_ms' => $rowExpiresAtMs,
                    'created_at' => $receivedAt,
                    'updated_at' => $receivedAt,
                ],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $preparedProof
     */
    private function upsertPreparedProofRow(int $clientId, int $clientDeviceSessionId, int $forwardId, array $preparedProof): string
    {
        $direction = (string) $preparedProof['direction_key'];
        $windowSequence = (int) $preparedProof['window_sequence_key'];
        $proofHash = (string) $preparedProof['traffic_window_state_hash_key'];
        $row = $preparedProof['row'];
        $existing = $this->db->fetchOne(
            'SELECT * FROM forward_aggregation_runtime_window_proofs
             WHERE client_id = :client_id
               AND client_device_session_id = :client_device_session_id
               AND forward_id = :forward_id
               AND active_family = :active_family
               AND useful_window_direction = :direction
             LIMIT 1
             FOR UPDATE',
            [
                'client_id' => $clientId,
                'client_device_session_id' => $clientDeviceSessionId,
                'forward_id' => $forwardId,
                'active_family' => 'standalone_bond',
                'direction' => $direction,
            ]
        );
        if ($existing !== null) {
            if ($windowSequence <= (int) ($existing['traffic_window_sequence'] ?? 0)
                || hash_equals((string) ($existing['traffic_window_state_hash'] ?? ''), $proofHash)) {
                return 'non_advancing_traffic_window';
            }
            $assignments = [];
            foreach ($row as $key => $value) {
                if ($key === 'created_at') {
                    continue;
                }
                $assignments[] = $key . ' = :' . $key;
            }
            $this->db->execute(
                'UPDATE forward_aggregation_runtime_window_proofs SET ' . implode(', ', $assignments) . '
                 WHERE client_id = :client_id_key
                   AND client_device_session_id = :client_device_session_id_key
                   AND forward_id = :forward_id_key
                   AND active_family = :active_family_key
                   AND useful_window_direction = :direction_key',
                $row + [
                    'client_id_key' => $clientId,
                    'client_device_session_id_key' => $clientDeviceSessionId,
                    'forward_id_key' => $forwardId,
                    'active_family_key' => 'standalone_bond',
                    'direction_key' => $direction,
                ]
            );
            return 'ok';
        }
        $this->db->insert('forward_aggregation_runtime_window_proofs', $row);
        return 'ok';
    }

    /**
     * @return array{ok:bool,proof:null,forward_id:int,runtime_epoch:?int,sequence:?int,error:string,terminal:bool}
     */
    private function rejectWithProofShape(int $clientId, int $clientDeviceSessionId, int $forwardId, array $snapshot, string $code): array
    {
        $rejection = $this->reject($clientId, $clientDeviceSessionId, $forwardId, $snapshot, $code);
        return $rejection + ['proof' => null];
    }

    /**
     * @return array{ok:bool,forward_id:?int,runtime_epoch:?int,sequence:?int,error:string,terminal:bool}
     */
    private function reject(int $clientId, int $clientDeviceSessionId, ?int $forwardId, array $snapshot, string $code): array
    {
        $now = self::formatEpochMs((int) floor(microtime(true) * 1000));
        $this->db->insert('forward_aggregation_runtime_snapshot_rejections', [
            'client_id' => $clientId,
            'client_device_session_id' => $clientDeviceSessionId,
            'forward_id' => $forwardId,
            'active_family' => isset($snapshot['active_family']) ? substr(trim((string) $snapshot['active_family']), 0, 32) : null,
            'rejection_code' => $code,
            'rejection_detail' => null,
            'runtime_epoch' => isset($snapshot['runtime_epoch']) && is_numeric($snapshot['runtime_epoch']) ? (int) $snapshot['runtime_epoch'] : null,
            'sequence' => isset($snapshot['sequence']) && is_numeric($snapshot['sequence']) ? (int) $snapshot['sequence'] : null,
            'terminal' => $this->isTerminalRejection($code) ? 1 : 0,
            'received_at' => $now,
            'created_at' => $now,
        ]);
        return ['ok' => false, 'forward_id' => $forwardId, 'runtime_epoch' => isset($snapshot['runtime_epoch']) && is_numeric($snapshot['runtime_epoch']) ? (int) $snapshot['runtime_epoch'] : null, 'sequence' => isset($snapshot['sequence']) && is_numeric($snapshot['sequence']) ? (int) $snapshot['sequence'] : null, 'error' => $code, 'terminal' => $this->isTerminalRejection($code)];
    }

    private function isTerminalRejection(string $code): bool
    {
        return in_array($code, ['malformed_snapshot', 'invalid_forward_id', 'snapshot_too_large', 'unsupported_active_family', 'invalid_sample_age', 'stale_snapshot', 'unsupported_snapshot_contract_version', 'invalid_desired_state', 'desired_generation_mismatch', 'desired_leg_set_hash_mismatch', 'invalid_runtime_sequence', 'invalid_traffic_window_metadata', 'stale_traffic_window', 'out_of_order_snapshot'], true);
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    private static function lifecycleSnapshot(array $snapshot): array
    {
        foreach (['traffic_window', 'traffic_window_json', 'traffic_window_state_hash', 'traffic_window_sequence', 'traffic_window_started_monotonic_ms', 'traffic_window_ended_monotonic_ms', 'traffic_window_end_age_ms', 'duration_ms', 'useful_window_total_bytes', 'per_leg_useful_bytes'] as $proofOnlyKey) {
            unset($snapshot[$proofOnlyKey]);
        }
        return $snapshot;
    }

    private static function formatEpochMs(int $epochMs): string
    {
        $seconds = intdiv($epochMs, 1000);
        $millis = $epochMs % 1000;
        return gmdate('Y-m-d H:i:s', $seconds) . '.' . str_pad((string) $millis, 3, '0', STR_PAD_LEFT);
    }

    private function directionOrNull(mixed $value): ?string
    {
        $direction = strtolower(trim((string) ($value ?? '')));
        return in_array($direction, ['upload', 'download'], true) ? $direction : null;
    }
}
```

This reducer must not use delete-then-insert for the latest row. In production it must run the latest-row read/write inside `Database::transaction()` and lock the existing row with `SELECT ... FOR UPDATE` before accepting a newer `(runtime_epoch, sequence)`. If the repository later swaps to `INSERT ... ON DUPLICATE KEY UPDATE`, the update clause must be conditional on the incoming epoch/sequence being strictly newer; either way, a lower sequence can never overwrite a higher one.

- [ ] **Step 8: Run the assignment and reducer tests and verify they pass**

Run:

```bash
php master/tests/bond_runtime_assignment_repository_test.php
php master/tests/bond_aggregation_runtime_snapshot_service_test.php
```

Expected: both print `ok`.

- [ ] **Step 9: Commit reducer and assignment context**

Run:

```bash
git add master/tests/bond_runtime_assignment_repository_test.php master/tests/bond_aggregation_runtime_snapshot_service_test.php master/src/Support/BondRuntimeAssignmentContext.php master/src/Support/BondRuntimeAssignmentRepository.php master/src/Support/BondAggregationRuntimeSnapshotService.php
git commit -m "feat(master): reduce standalone bond runtime snapshots

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Wire the Authenticated Snapshot Endpoint

**Files:**
- Create: `master/tests/client_aggregation_runtime_endpoint_test.php`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Http/Request.php`
- Modify: `master/src/Controllers/ClientAgentController.php`

- [ ] **Step 1: Write the route and controller source test**

Create `master/tests/client_aggregation_runtime_endpoint_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$controller = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$auth = file_get_contents(__DIR__ . '/../src/Support/Auth.php');
$request = file_get_contents(__DIR__ . '/../src/Http/Request.php');
if ($bootstrap === false || $controller === false || $auth === false || $request === false) {
    fail('failed to read source files');
}

$required = [
    'POST /api/client/aggregation-runtime-snapshots route' => "\$this->router->add('POST', '/api/client/aggregation-runtime-snapshots', [\$clientAgent, 'aggregationRuntimeSnapshots']);",
    'controller method' => 'function aggregationRuntimeSnapshots(Request $request, array $params): Response',
    'bearer-only auth' => 'Auth::requireClientAgentBearerOnly($request, $this->db)',
    'body-token-compatible legacy helper still isolated' => 'requireClientAgentTokenOnly(Request $request, Database $db, bool $allowBodyToken = true)',
    'device session id header' => "x-device-session-id",
    'device credential header' => "x-device-session-credential",
    'telemetry credential verification' => 'ClientDeviceSessionContext::requireTelemetry',
    'snapshot service call' => 'BondAggregationRuntimeSnapshotService($this->db)',
    'capability assignment repository' => 'BondRuntimeAssignmentRepository($this->db)',
    'capability assignment validation' => 'findForClientDeviceForward(',
    'capability unassigned skip' => 'if ($assignment === null)',
    'accepted response key' => "'accepted' =>",
    'accepted identities response key' => "'accepted_snapshots' =>",
    'rejected response key' => "'rejected' =>",
    'request pre-parse path guard' => "isAggregationRuntimeSnapshotRequest($method, $path)",
    'request content-length cap' => "aggregation_snapshot_request_too_large",
    'request bounded raw body read' => 'stream_get_contents($input, self::MAX_AGGREGATION_RUNTIME_SNAPSHOT_BODY_BYTES + 1)',
];
foreach ($required as $label => $snippet) {
    if (!str_contains($bootstrap . $controller . $auth . $request, $snippet)) {
        fail('missing ' . $label . ': ' . $snippet);
    }
}

$methodStart = strpos($controller, 'function aggregationRuntimeSnapshots(Request $request, array $params): Response');
if ($methodStart === false) {
    fail('telemetry endpoint method missing');
}
$nextMethod = strpos($controller, "\n    public function ", $methodStart + 1);
$methodBody = substr($controller, $methodStart, $nextMethod === false ? null : $nextMethod - $methodStart);
foreach (['issueForPullOrHeartbeat', 'Auth::requireClientAgent($request, $this->db)', 'Auth::requireClientAgentTokenOnly($request, $this->db)', "input('token'", 'input("token"', "input('device_session_credential'", 'input("device_session_credential"'] as $forbiddenInEndpoint) {
    if (str_contains($methodBody, $forbiddenInEndpoint)) {
        fail('telemetry endpoint contains forbidden bootstrap/body-token call: ' . $forbiddenInEndpoint);
    }
}
$assignmentCall = strpos($methodBody, 'findForClientDeviceForward(');
$assignmentSkip = strpos($methodBody, 'if ($assignment === null)');
$recordReportCall = strpos($methodBody, 'recordReport(');
if ($assignmentCall === false || $assignmentSkip === false || $recordReportCall === false || $assignmentCall > $recordReportCall || $assignmentSkip > $recordReportCall) {
    fail('telemetry endpoint must assignment-check positive capability_reports[] forward_id values and skip unassigned rows before recordReport() can make them gate-capable');
}
$unassignedForwardCapabilityReport = ['forward_id' => 999, 'artifact_name' => 'leikwan_bond_linux_amd64'];
if ((int) $unassignedForwardCapabilityReport['forward_id'] <= 0) {
    fail('unassigned-forward telemetry regression fixture must use a positive forward_id');
}
if (!str_contains($auth, 'allowBodyToken: false')) {
    fail('telemetry auth must force bearer-header-only mode');
}
$telemetryIfStart = strpos($request, 'if (self::isAggregationRuntimeSnapshotRequest($method, $path))');
if ($telemetryIfStart === false) {
    fail('telemetry request branch missing');
}
$telemetryElseStart = strpos($request, '} else {', $telemetryIfStart);
if ($telemetryElseStart === false) {
    fail('telemetry request branch else boundary missing');
}
$telemetryBranch = substr($request, $telemetryIfStart, $telemetryElseStart - $telemetryIfStart);
if (str_contains($telemetryBranch, "file_get_contents('php://input')")) {
    fail('telemetry request branch must use bounded stream_get_contents, not unbounded file_get_contents');
}

echo "ok\n";
```

- [ ] **Step 2: Run the endpoint test and verify it fails**

Run:

```bash
php master/tests/client_aggregation_runtime_endpoint_test.php
```

Expected: FAIL because route/method do not exist.

- [ ] **Step 3: Register the endpoint route**

Modify `master/src/Bootstrap.php` by adding this route next to the existing client agent heartbeat/config routes:

```php
        $this->router->add('POST', '/api/client/aggregation-runtime-snapshots', [$clientAgent, 'aggregationRuntimeSnapshots']);
```

Use the exact `$clientAgent` variable already used for the neighboring `/api/client/heartbeat` and `/api/client/configs/pull` routes; `Router` exposes `add()`, not HTTP verb helpers.

- [ ] **Step 4: Add the pre-parse request body cap**

Modify `master/src/Http/Request.php` so the telemetry endpoint rejects oversized bodies before JSON decoding. Add this import after the namespace:

```php
use Leikwan\Master\Support\ApiException;
```

Add this constant at the top of the `Request` class:

```php
    private const MAX_AGGREGATION_RUNTIME_SNAPSHOT_BODY_BYTES = 524288;
```

Change `fromGlobals()` so it checks `Content-Length` before reading/parsing and checks `rawBody()` length before JSON decode:

```php
    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $query = $_GET ?? [];
        $headers = function_exists('getallheaders') ? getallheaders() : self::serverHeaders();
        if (self::isAggregationRuntimeSnapshotRequest($method, $path)) {
            $contentLength = max(0, (int) ($_SERVER['CONTENT_LENGTH'] ?? $_SERVER['HTTP_CONTENT_LENGTH'] ?? 0));
            if ($contentLength > self::MAX_AGGREGATION_RUNTIME_SNAPSHOT_BODY_BYTES) {
                throw new ApiException('aggregation_snapshot_request_too_large', 413);
            }
            $input = fopen('php://input', 'rb');
            $rawBody = $input === false ? '' : (stream_get_contents($input, self::MAX_AGGREGATION_RUNTIME_SNAPSHOT_BODY_BYTES + 1) ?: '');
            if (strlen($rawBody) > self::MAX_AGGREGATION_RUNTIME_SNAPSHOT_BODY_BYTES) {
                throw new ApiException('aggregation_snapshot_request_too_large', 413);
            }
        } else {
            $rawBody = file_get_contents('php://input') ?: '';
        }
        $body = $_POST ?? [];

        $contentType = strtolower((string) ($headers['Content-Type'] ?? $headers['content-type'] ?? ''));
        if (str_contains($contentType, 'application/json') && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self($method, $path, $query, $body, $headers, $rawBody);
    }
```

Add this helper before `serverHeaders()`:

```php
    private static function isAggregationRuntimeSnapshotRequest(string $method, string $path): bool
    {
        return strtoupper($method) === 'POST' && $path === '/api/client/aggregation-runtime-snapshots';
    }
```

Do not rely only on `BondAggregationRuntimeSnapshotService::MAX_REQUEST_BYTES`: by the time the controller calls that service, `Request::fromGlobals()` has already read and JSON-decoded the body.

- [ ] **Step 5: Add the controller endpoint**

Ensure `ClientAgentController.php` imports `BondAggregationRuntimeSnapshotService` and `BondRuntimeAssignmentRepository`; add `AgentCapabilityReportService` or `ClientDeviceSessionContext` only if a previous task did not already import them:

```php
use Leikwan\Master\Support\BondAggregationRuntimeSnapshotService;
use Leikwan\Master\Support\BondRuntimeAssignmentRepository;
```

Add this method after `heartbeat()`:

```php
    public function aggregationRuntimeSnapshots(Request $request, array $params): Response
    {
        $client = Auth::requireClientAgentBearerOnly($request, $this->db);
        $deviceSessionId = (int) ($request->header('x-device-session-id') ?? 0);
        $deviceCredential = trim((string) ($request->header('x-device-session-credential') ?? ''));
        $context = ClientDeviceSessionContext::requireTelemetry($this->db, $client, $deviceSessionId, $deviceCredential);

        $capabilityReports = $request->input('capability_reports', []);
        if (is_array($capabilityReports) && $capabilityReports !== []) {
            $bondBinary = $this->artifactMetadata('leikwan_bond_linux_amd64');
            $desiredBondSha256 = (string) ($bondBinary['binary_sha256'] ?? $bondBinary['sha256'] ?? '');
            $capabilityService = new AgentCapabilityReportService($this->db);
            $assignmentRepository = new BondRuntimeAssignmentRepository($this->db);
            foreach ($capabilityReports as $capabilityReport) {
                if (!is_array($capabilityReport)) {
                    continue;
                }
                $forwardId = isset($capabilityReport['forward_id']) ? (int) $capabilityReport['forward_id'] : 0;
                if ($forwardId <= 0) {
                    continue;
                }
                try {
                    $assignment = $assignmentRepository->findForClientDeviceForward(
                        (int) $context['client_id'],
                        (int) $context['session_id'],
                        $forwardId
                    );
                } catch (ApiException $exception) {
                    $assignment = null;
                }
                if ($assignment === null) {
                    continue;
                }
                $capabilityService->recordReport(
                    roleType: 'client',
                    roleId: (int) $context['client_id'],
                    clientDeviceSessionId: (int) $context['session_id'],
                    forwardId: $forwardId,
                    payload: $capabilityReport,
                    expected: ['artifact_sha256' => $desiredBondSha256]
                );
            }
        }

        $snapshots = $request->input('snapshots', []);
        if (!is_array($snapshots)) {
            throw new ApiException('aggregation_snapshots_required', 422);
        }

        $result = (new BondAggregationRuntimeSnapshotService($this->db))->ingestClientSnapshots(
            (int) $context['client_id'],
            (int) $context['session_id'],
            $snapshots
        );

        return $this->json([
            'ok' => true,
            'accepted' => (int) $result['accepted'],
            'accepted_snapshots' => $result['accepted_snapshots'],
            'rejected' => $result['rejected'],
        ]);
    }
```

If `ApiException` is not imported, add:

```php
use Leikwan\Master\Support\ApiException;
```

- [ ] **Step 6: Run endpoint and reducer tests**

Run:

```bash
php master/tests/client_aggregation_runtime_endpoint_test.php
php master/tests/bond_aggregation_runtime_snapshot_service_test.php
php master/tests/client_device_session_context_test.php
```

Expected: all print `ok`.

- [ ] **Step 7: Commit endpoint wiring**

Run:

```bash
git add master/tests/client_aggregation_runtime_endpoint_test.php master/src/Bootstrap.php master/src/Http/Request.php master/src/Controllers/ClientAgentController.php
git commit -m "feat(master): add aggregation runtime snapshot endpoint

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Add Latest-Only Client Agent Reporter

**Files:**
- Create: `client-agent/tests/test_aggregation_runtime_reporter.py`
- Create: `client-agent/aggregation_runtime_reporter.py`
- Modify: `client-agent/client_agent.py`
- Modify: `master/src/Controllers/ClientAgentController.php`

- [ ] **Step 1: Write the failing Python reporter test**

Create `client-agent/tests/test_aggregation_runtime_reporter.py`:

```python
import importlib.util
import json
import os
import pathlib
import tempfile
import time
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
MODULE_PATH = ROOT / "aggregation_runtime_reporter.py"

spec = importlib.util.spec_from_file_location("aggregation_runtime_reporter", MODULE_PATH)
module = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(module)
AggregationRuntimeReporter = module.AggregationRuntimeReporter


class AggregationRuntimeReporterTest(unittest.TestCase):
    def accepted_response_for_body(self, body):
        snapshots = body.get("snapshots", [])
        return {
            "ok": True,
            "accepted": len(snapshots),
            "accepted_snapshots": [
                {
                    "forward_id": int(snapshot.get("forward_id") or 0),
                    "runtime_epoch": int(snapshot.get("runtime_epoch") or 0),
                    "sequence": int(snapshot.get("sequence") or 0),
                }
                for snapshot in snapshots
            ],
            "rejected": [],
        }

    def write_snapshot(self, run_dir: pathlib.Path, payload: dict, forward_id: int = 65) -> pathlib.Path:
        path = run_dir / f"bond-leg-health-forward-{forward_id}.json"
        path.write_text(json.dumps(payload), encoding="utf-8")
        return path

    def base_payload(self, forward_id: int = 65, runtime_epoch: int = 2, sequence: int = 7) -> dict:
        return {
            "forward_id": forward_id,
            "schema_version": 1,
            "runtime_contract_version": 1,
            "active_family": "standalone_bond",
            "desired_generation": 4,
            "desired_leg_set_hash": "1" * 64,
            "runtime_epoch": runtime_epoch,
            "sequence": sequence,
            "sample_age_ms": 100,
            "lifecycle_state": "connected",
            "legs": [
                {"logical_key": "bond:65:tcp:19:316", "state": "active"},
                {"logical_key": "bond:65:tcp:20:317", "state": "active"},
            ],
        }

    def test_loads_latest_snapshot_and_posts_once(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            run_dir = root / "run"
            state_dir = root / "state"
            run_dir.mkdir()
            payload = self.base_payload()
            snapshot_path = self.write_snapshot(run_dir, payload)
            calls = []

            def post_json(path, body, headers):
                calls.append((path, body, headers))
                return self.accepted_response_for_body(body)

            reporter = AggregationRuntimeReporter(
                state_dir=state_dir,
                snapshot_glob=str(run_dir / "bond-leg-health-forward-*.json"),
                post_json=post_json,
                now_monotonic=time.monotonic,
            )
            reporter.update_device_session(10, "secret-value")
            self.assertTrue(reporter.flush_once())
            self.assertEqual(1, len(calls))
            self.assertEqual("/api/client/aggregation-runtime-snapshots", calls[0][0])
            self.assertEqual(10, calls[0][2]["x-device-session-id"])
            self.assertEqual("secret-value", calls[0][2]["x-device-session-credential"])
            self.assertNotIn("device_session_credential", json.dumps(calls[0][1]))
            self.assertEqual("standalone_bond", calls[0][1]["snapshots"][0]["active_family"])
            self.assertFalse(reporter.flush_once())
            self.assertFalse((state_dir / "device-session-credential.json").exists())
            self.assertFalse((state_dir / "device-session-credential.txt").exists())
            state_files = [path for path in state_dir.glob("**/*") if path.is_file()]
            self.assertNotIn("secret-value", "\n".join(path.read_text(encoding="utf-8", errors="ignore") for path in state_files))
            snapshot_path.write_text(json.dumps(dict(payload, sequence=8)), encoding="utf-8")
            reloaded = AggregationRuntimeReporter(
                state_dir=state_dir,
                snapshot_glob=str(run_dir / "bond-leg-health-forward-*.json"),
                post_json=post_json,
                now_monotonic=time.monotonic,
            )
            self.assertFalse(reloaded.flush_once(), "credential must not survive reporter restart as plaintext state")

    def test_restart_with_known_session_but_no_credential_fails_closed_without_reissue(self):
        with tempfile.TemporaryDirectory() as tmp:
            run_dir = pathlib.Path(tmp) / "run"
            run_dir.mkdir()
            self.write_snapshot(run_dir, self.base_payload(runtime_epoch=1, sequence=1))
            calls = []

            reporter = AggregationRuntimeReporter(
                state_dir=pathlib.Path(tmp) / "state",
                snapshot_glob=str(run_dir / "bond-leg-health-forward-*.json"),
                post_json=lambda path, body, headers: calls.append((path, body, headers)),
                now_monotonic=time.monotonic,
            )
            reporter.update_device_session(10, None)
            self.assertTrue(reporter.needs_credential())
            self.assertFalse(reporter.flush_once())
            self.assertEqual([], calls, "telemetry must stay paused instead of posting or requesting reissue")

    def test_rejects_stale_or_wrong_family_before_posting(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            run_dir = root / "run"
            run_dir.mkdir()
            self.write_snapshot(run_dir, dict(self.base_payload(forward_id=1, runtime_epoch=1, sequence=1), active_family="bond_" + "fallback"), 1)
            calls = []
            reporter = AggregationRuntimeReporter(
                state_dir=root / "state",
                snapshot_glob=str(run_dir / "bond-leg-health-forward-*.json"),
                post_json=lambda path, body, headers: calls.append((path, body, headers)),
                now_monotonic=time.monotonic,
            )
            reporter.update_device_session(10, "secret-value")
            self.assertFalse(reporter.flush_once())
            self.assertEqual([], calls)

    def test_effective_age_uses_file_mtime_plus_payload_age(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            run_dir = root / "run"
            run_dir.mkdir()
            path = self.write_snapshot(run_dir, self.base_payload(runtime_epoch=1, sequence=1))
            stale_wall_time = time.time() - 20
            os.utime(path, (stale_wall_time, stale_wall_time))
            calls = []
            reporter = AggregationRuntimeReporter(
                state_dir=root / "state",
                snapshot_glob=str(run_dir / "bond-leg-health-forward-*.json"),
                post_json=lambda path, body, headers: calls.append((path, body, headers)) or self.accepted_response_for_body(body),
                now_monotonic=time.monotonic,
                now_wall=time.time,
            )
            reporter.update_device_session(10, "secret-value")
            self.assertFalse(reporter.flush_once())
            self.assertEqual([], calls, "old files must not stay fresh by self-reporting a low sample age")

    def test_posts_effective_age_instead_of_self_reported_age(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            run_dir = root / "run"
            run_dir.mkdir()
            wall = float(int(time.time()))
            path = self.write_snapshot(run_dir, self.base_payload(runtime_epoch=1, sequence=1))
            os.utime(path, (wall - 2, wall - 2))
            calls = []

            def post_json(path, body, headers):
                calls.append((path, body, headers))
                return self.accepted_response_for_body(body)

            reporter = AggregationRuntimeReporter(
                state_dir=root / "state",
                snapshot_glob=str(run_dir / "bond-leg-health-forward-*.json"),
                post_json=post_json,
                now_monotonic=time.monotonic,
                now_wall=lambda: wall,
            )
            reporter.update_device_session(10, "secret-value")
            self.assertTrue(reporter.flush_once())
            self.assertEqual(2100, calls[0][1]["snapshots"][0]["sample_age_ms"])

    def test_rejects_missing_or_wrong_snapshot_contract_versions(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            run_dir = root / "run"
            run_dir.mkdir()
            path = run_dir / "bond-leg-health-forward-65.json"
            base = {
                "forward_id": 65,
                "active_family": "standalone_bond",
                "runtime_epoch": 1,
                "sequence": 1,
                "sample_age_ms": 1,
            }
            calls = []

            def post_json(path, body, headers):
                calls.append((path, body, headers))
                return self.accepted_response_for_body(body)

            reporter = AggregationRuntimeReporter(
                state_dir=root / "state",
                snapshot_glob=str(run_dir / "bond-leg-health-forward-*.json"),
                post_json=post_json,
                now_monotonic=time.monotonic,
            )
            reporter.update_device_session(10, "secret-value")
            path.write_text(json.dumps(base), encoding="utf-8")
            self.assertFalse(reporter.flush_once())
            path.write_text(json.dumps(dict(base, schema_version="bad", runtime_contract_version=1, sequence=2)), encoding="utf-8")
            self.assertFalse(reporter.flush_once())
            path.write_text(json.dumps(dict(base, schema_version=1, runtime_contract_version=2, sequence=3)), encoding="utf-8")
            self.assertFalse(reporter.flush_once())
            path.write_text(json.dumps(dict(base, schema_version=1, runtime_contract_version=1, sequence=4)), encoding="utf-8")
            self.assertTrue(reporter.flush_once())
            self.assertEqual(1, len(calls))

    def test_client_agent_and_master_config_wire_reporter_knobs(self):
        agent_source = (ROOT / "client_agent.py").read_text(encoding="utf-8")
        master_source = (ROOT.parent / "master" / "src" / "Controllers" / "ClientAgentController.php").read_text(encoding="utf-8")
        for snippet in [
            '"bond_leg_snapshot_dir": "/run/leikwan"',
            '"aggregation_runtime_report_interval_seconds": 5',
            '"bond_leg_snapshot_dir",',
            '"aggregation_runtime_report_interval_seconds",',
            "def rebuild_aggregation_runtime_reporter(self) -> None:",
            "previous_credential = previous_reporter.device_session_credential if previous_reporter is not None else \"\"",
            "def needs_credential(self) -> bool:",
            'logging.warning("aggregation runtime reporter has no credential; telemetry remains paused until a separately authorized credential reset")',
            "if state_dir_changed or snapshot_dir_changed:",
            "self.rebuild_aggregation_runtime_reporter()",
        ]:
            self.assertIn(snippet, agent_source)
        for forbidden in [
            "aggregation_runtime_credential_reissue_requested",
            "aggregation_runtime_credential_reissue_forbidden",
        ]:
            self.assertNotIn(forbidden, agent_source + master_source)
        for snippet in [
            "'bond_leg_snapshot_dir'",
            "'aggregation_runtime_report_interval_seconds'",
        ]:
            self.assertIn(snippet, master_source)

    def test_configured_snapshot_dir_factory(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            reporter = AggregationRuntimeReporter.for_snapshot_dir(
                state_dir=root / "state",
                snapshot_dir=root / "configured-run",
                post_json=lambda path, body, headers: self.accepted_response_for_body(body),
            )
            self.assertEqual(str(root / "configured-run" / "bond-leg-health-forward-*.json"), reporter.snapshot_glob)

    def test_retries_failed_snapshot_but_drops_stale_snapshot_without_replay(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            run_dir = root / "run"
            run_dir.mkdir()
            path = self.write_snapshot(run_dir, self.base_payload())
            calls = []

            def post_json(path, body, headers):
                calls.append((path, body, headers))
                return {"ok": False}

            reporter = AggregationRuntimeReporter(
                state_dir=root / "state",
                snapshot_glob=str(run_dir / "bond-leg-health-forward-*.json"),
                post_json=post_json,
                now_monotonic=time.monotonic,
            )
            reporter.update_device_session(10, "secret-value")
            self.assertFalse(reporter.flush_once())
            self.assertEqual(1, len(calls))
            self.assertFalse(reporter.flush_once())
            self.assertEqual(2, len(calls), "failed posts must not mark the snapshot sent")

            stale = self.base_payload()
            stale["sample_age_ms"] = 15000
            path.write_text(json.dumps(stale), encoding="utf-8")
            self.assertFalse(reporter.flush_once())
            self.assertEqual(2, len(calls), "stale snapshots must not be replayed")

    def test_server_retryable_rejection_is_not_marked_sent(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            run_dir = root / "run"
            run_dir.mkdir()
            self.write_snapshot(run_dir, self.base_payload())
            calls = []

            def post_json(path, body, headers):
                calls.append((path, body, headers))
                return {
                    "ok": True,
                    "accepted": 0,
                    "accepted_snapshots": [],
                    "rejected": [{"forward_id": 65, "runtime_epoch": 2, "sequence": 7, "error": "assignment_not_found", "terminal": False}],
                }

            reporter = AggregationRuntimeReporter(root / "state", str(run_dir / "bond-leg-health-forward-*.json"), post_json=post_json)
            reporter.update_device_session(10, "secret-value")
            self.assertFalse(reporter.flush_once())
            self.assertFalse(reporter.flush_once())
            self.assertEqual(2, len(calls), "retryable rejection must remain unsent and be retried")

    def test_terminal_rejection_is_marked_sent(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            run_dir = root / "run"
            run_dir.mkdir()
            self.write_snapshot(run_dir, self.base_payload())
            calls = []

            def post_json(path, body, headers):
                calls.append((path, body, headers))
                return {
                    "ok": True,
                    "accepted": 0,
                    "accepted_snapshots": [],
                    "rejected": [{"forward_id": 65, "runtime_epoch": 2, "sequence": 7, "error": "desired_generation_mismatch", "terminal": True}],
                }

            reporter = AggregationRuntimeReporter(root / "state", str(run_dir / "bond-leg-health-forward-*.json"), post_json=post_json)
            reporter.update_device_session(10, "secret-value")
            self.assertTrue(reporter.flush_once())
            self.assertFalse(reporter.flush_once())
            self.assertEqual(1, len(calls), "terminal rejection should mark identity sent and stop replay")

    def test_partial_dispositions_retry_only_retryable_snapshot(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            run_dir = root / "run"
            run_dir.mkdir()
            self.write_snapshot(run_dir, self.base_payload(forward_id=65, runtime_epoch=2, sequence=7), 65)
            self.write_snapshot(run_dir, self.base_payload(forward_id=66, runtime_epoch=2, sequence=8), 66)
            calls = []

            def post_json(path, body, headers):
                calls.append((path, body, headers))
                if len(calls) == 1:
                    return {
                        "ok": True,
                        "accepted": 1,
                        "accepted_snapshots": [{"forward_id": 65, "runtime_epoch": 2, "sequence": 7}],
                        "rejected": [{"forward_id": 66, "runtime_epoch": 2, "sequence": 8, "error": "assignment_not_found", "terminal": False}],
                    }
                return {
                    "ok": True,
                    "accepted": 1,
                    "accepted_snapshots": [{"forward_id": 66, "runtime_epoch": 2, "sequence": 8}],
                    "rejected": [],
                }

            reporter = AggregationRuntimeReporter(root / "state", str(run_dir / "bond-leg-health-forward-*.json"), post_json=post_json)
            reporter.update_device_session(10, "secret-value")
            self.assertTrue(reporter.flush_once())
            self.assertEqual([65, 66], sorted(snapshot["forward_id"] for snapshot in calls[0][1]["snapshots"]))
            self.assertTrue(reporter.flush_once())
            self.assertEqual([66], [snapshot["forward_id"] for snapshot in calls[1][1]["snapshots"]])
            self.assertFalse(reporter.flush_once())


if __name__ == "__main__":
    unittest.main()

```

- [ ] **Step 2: Run the reporter test and verify it fails**

Run:

```bash
python client-agent/tests/test_aggregation_runtime_reporter.py
```

Expected: FAIL because `client-agent/aggregation_runtime_reporter.py` does not exist.

- [ ] **Step 3: Create the latest-only reporter**

Create `client-agent/aggregation_runtime_reporter.py`:

```python
from __future__ import annotations

import glob
import json
import os
import pathlib
import time
from typing import Callable, Dict, List, Optional

CapabilityReportFactory = Callable[[int], dict]


class AggregationRuntimeReporter:
    MAX_SNAPSHOTS = 64
    MAX_REQUEST_BYTES = 512 * 1024
    MAX_SNAPSHOT_BYTES = 16 * 1024
    MAX_SAMPLE_AGE_MS = 15000

    def __init__(
        self,
        state_dir: pathlib.Path,
        snapshot_glob: str = "/run/leikwan/bond-leg-health-forward-*.json",
        post_json: Optional[Callable[[str, dict, dict], dict]] = None,
        now_monotonic: Callable[[], float] = time.monotonic,
        now_wall: Callable[[], float] = time.time,
        capability_report_factory: Optional[CapabilityReportFactory] = None,
    ) -> None:
        self.state_dir = pathlib.Path(state_dir)
        self.snapshot_glob = snapshot_glob
        self.post_json = post_json
        self.now_monotonic = now_monotonic
        self.now_wall = now_wall
        self.sent_keys: set[str] = set()
        self.device_session_id: Optional[int] = None
        self.device_session_credential: str = ""
        self.capability_report_factory = capability_report_factory
        self._ensure_state_dir_mode()

    @classmethod
    def for_snapshot_dir(
        cls,
        state_dir: pathlib.Path,
        snapshot_dir: pathlib.Path,
        post_json: Optional[Callable[[str, dict, dict], dict]] = None,
        now_monotonic: Callable[[], float] = time.monotonic,
        now_wall: Callable[[], float] = time.time,
        capability_report_factory: Optional[CapabilityReportFactory] = None,
    ) -> "AggregationRuntimeReporter":
        return cls(
            state_dir=state_dir,
            snapshot_glob=str(pathlib.Path(snapshot_dir) / "bond-leg-health-forward-*.json"),
            post_json=post_json,
            now_monotonic=now_monotonic,
            now_wall=now_wall,
            capability_report_factory=capability_report_factory,
        )

    def update_device_session(self, session_id: int, credential: Optional[str]) -> None:
        if session_id > 0:
            self.device_session_id = int(session_id)
        if credential:
            self.device_session_credential = str(credential)

    def needs_credential(self) -> bool:
        return bool(self.device_session_id) and self.device_session_credential == ""

    def flush_once(self) -> bool:
        if not self.device_session_id or not self.device_session_credential:
            return False
        snapshots = self._load_latest_snapshots()
        if not snapshots:
            return False
        body = {"device_session_id": self.device_session_id, "snapshots": snapshots}
        capability_reports = self._capability_reports_for_snapshots(snapshots)
        if capability_reports:
            body["capability_reports"] = capability_reports
        encoded = self._encode_body(body)
        if len(encoded) > self.MAX_REQUEST_BYTES:
            trimmed: List[dict] = []
            for snapshot in snapshots:
                candidate = trimmed + [snapshot]
                candidate_body = {"device_session_id": self.device_session_id, "snapshots": candidate}
                candidate_reports = self._capability_reports_for_snapshots(candidate)
                if candidate_reports:
                    candidate_body["capability_reports"] = candidate_reports
                candidate_size = len(self._encode_body(candidate_body))
                if candidate_size > self.MAX_REQUEST_BYTES:
                    break
                trimmed = candidate
            snapshots = trimmed
            if not snapshots:
                return False
            body = {"device_session_id": self.device_session_id, "snapshots": snapshots}
            capability_reports = self._capability_reports_for_snapshots(snapshots)
            if capability_reports:
                body["capability_reports"] = capability_reports
        headers = {
            "x-device-session-id": self.device_session_id,
            "x-device-session-credential": self.device_session_credential,
        }
        if self.post_json is None:
            return False
        try:
            response = self.post_json("/api/client/aggregation-runtime-snapshots", body, headers)
        except Exception:
            return False
        if not isinstance(response, dict) or response.get("ok") is not True:
            return False
        accepted = {self._identity_key(item) for item in response.get("accepted_snapshots", []) if isinstance(item, dict)}
        terminal = {
            self._identity_key(item)
            for item in response.get("rejected", [])
            if isinstance(item, dict) and item.get("terminal") is True
        }
        progressed = False
        for snapshot in snapshots:
            key = self._snapshot_key(snapshot)
            if key in accepted or key in terminal:
                self.sent_keys.add(key)
                progressed = True
        return progressed

    def _encode_body(self, body: dict) -> bytes:
        return json.dumps(body, separators=(",", ":")).encode("utf-8")

    def _capability_reports_for_snapshots(self, snapshots: List[dict]) -> List[dict]:
        if self.capability_report_factory is None:
            return []
        reports: List[dict] = []
        seen: set[int] = set()
        for snapshot in snapshots:
            forward_id = int(snapshot.get("forward_id") or 0)
            if forward_id <= 0 or forward_id in seen:
                continue
            seen.add(forward_id)
            report = self.capability_report_factory(forward_id)
            if isinstance(report, dict):
                normalized = dict(report)
                normalized["forward_id"] = forward_id
                reports.append(normalized)
        return reports

    def _ensure_state_dir_mode(self) -> None:
        self.state_dir.mkdir(parents=True, exist_ok=True)
        try:
            os.chmod(self.state_dir, 0o700)
        except OSError:
            pass

    def _load_latest_snapshots(self) -> List[dict]:
        by_forward: Dict[int, dict] = {}
        for raw_path in glob.glob(self.snapshot_glob):
            path = pathlib.Path(raw_path)
            try:
                stat = path.stat()
                if stat.st_size > self.MAX_SNAPSHOT_BYTES:
                    continue
                snapshot = json.loads(path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                continue
            if not isinstance(snapshot, dict):
                continue
            try:
                schema_version = int(snapshot.get("schema_version") or 0)
                runtime_contract_version = int(snapshot.get("runtime_contract_version") or 0)
            except (TypeError, ValueError):
                continue
            if schema_version != 1:
                continue
            if runtime_contract_version != 1:
                continue
            if snapshot.get("active_family") != "standalone_bond":
                continue
            try:
                payload_age_ms = max(0, int(snapshot.get("sample_age_ms")))
            except (TypeError, ValueError):
                continue
            file_age_ms = max(0, int((self.now_wall() - stat.st_mtime) * 1000))
            effective_age_ms = payload_age_ms + file_age_ms
            if effective_age_ms >= self.MAX_SAMPLE_AGE_MS:
                continue
            forward_id = int(snapshot.get("forward_id") or 0)
            if forward_id <= 0:
                continue
            sanitized = dict(snapshot)
            sanitized["sample_age_ms"] = effective_age_ms
            key = self._snapshot_key(sanitized)
            if key in self.sent_keys:
                continue
            current = by_forward.get(forward_id)
            if current is None or self._snapshot_order(sanitized) > self._snapshot_order(current):
                by_forward[forward_id] = sanitized
        return list(by_forward.values())[: self.MAX_SNAPSHOTS]

    def _snapshot_order(self, snapshot: dict) -> tuple[int, int]:
        return (int(snapshot.get("runtime_epoch") or 0), int(snapshot.get("sequence") or 0))

    def _snapshot_key(self, snapshot: dict) -> str:
        return self._identity_key(snapshot)

    def _identity_key(self, item: dict) -> str:
        return "%s:%s:%s" % (
            int(item.get("forward_id") or 0),
            int(item.get("runtime_epoch") or 0),
            int(item.get("sequence") or 0),
        )
```

- [ ] **Step 4: Wire reporter credential updates into `client_agent.py`**

In `client-agent/client_agent.py`, extend `DEFAULT_MANAGED_CONFIG` with the reporter knobs that master manages for Linux clients:

```python
DEFAULT_MANAGED_CONFIG = {
    "state_dir": "/var/lib/leikwan-client-agent",
    "poll_interval_seconds": 30,
    "health_check_interval_seconds": 20,
    "log_level": "INFO",
    "wireguard_listen_port": 0,
    "wireguard_fallback_tcp_port": 0,
    "bond_leg_snapshot_dir": "/run/leikwan",
    "aggregation_runtime_report_interval_seconds": 5,
}
```

Add both keys to the `allowed_keys` set in `ClientAgent.apply_config_patch()`:

```python
            "bond_leg_snapshot_dir",
            "aggregation_runtime_report_interval_seconds",
```

In `master/src/Controllers/ClientAgentController.php`, extend `authoritativeAgentConfig()` so the master delivers both knobs through `client.agent_config_json` to Linux client agents. Add these entries to `$managedKeys`:

```php
            'bond_leg_snapshot_dir',
            'aggregation_runtime_report_interval_seconds',
```

Add imports near the existing imports in `client-agent/client_agent.py`:

```python
try:
    from aggregation_runtime_reporter import AggregationRuntimeReporter
except Exception:
    AggregationRuntimeReporter = None
```

In the client agent constructor after `self._cfwg_latency_watchdog = None`, add:

```python
        self.aggregation_runtime_reporter = None
        self.rebuild_aggregation_runtime_reporter()
```

Add this helper method to the `ClientAgent` class:

```python
    def rebuild_aggregation_runtime_reporter(self) -> None:
        previous_reporter = self.aggregation_runtime_reporter
        previous_session_id = previous_reporter.device_session_id if previous_reporter is not None else None
        previous_credential = previous_reporter.device_session_credential if previous_reporter is not None else ""
        self.aggregation_runtime_reporter = None
        if AggregationRuntimeReporter is not None:
            self.aggregation_runtime_reporter = AggregationRuntimeReporter.for_snapshot_dir(
                state_dir=self.state_dir,
                snapshot_dir=self.bond_leg_snapshot_dir(),
                post_json=self.post_master_json,
                capability_report_factory=self.aggregation_capability_report,
            )
            if previous_session_id is not None and previous_credential != "":
                self.aggregation_runtime_reporter.update_device_session(previous_session_id, previous_credential)
```

This must use `self.bond_leg_snapshot_dir()` so the reporter watches the configured Task 37 standalone-bond snapshot directory instead of silently falling back to `/run/leikwan` when the client config uses another runtime directory. The helper intentionally preserves the in-memory credential across local reporter object rebuilds, but it still never persists the credential to disk.

`client-agent/client_agent.py` does not have `self.api_post`; it uses the module-level `http_json` / `http_json_with_headers` pattern. Add this method to the `ClientAgent` class and keep the credential in telemetry headers only:

```python
    def post_master_json(self, path: str, body: dict, headers: dict | None = None) -> dict:
        master = self.config["master_url"].rstrip("/")
        token = self.config["agent_token"]
        request_headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Authorization": f"Bearer {token}",
            "User-Agent": f"Leikwan-Client-Agent/{AGENT_VERSION}",
        }
        for key, value in dict(headers or {}).items():
            request_headers[str(key)] = str(value)
        data = json.dumps(body, separators=(",", ":")).encode("utf-8")
        req = urllib.request.Request(f"{master}{path}", data=data, headers=request_headers, method="POST")
        try:
            with urllib.request.urlopen(req, timeout=30) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as exc:
            response_body = exc.read().decode("utf-8", errors="ignore")
            raise RuntimeError(f"http {exc.code} {master}{path}: {response_body}") from exc
```

Add this helper method to the `ClientAgent` class:

```python
    def update_aggregation_runtime_device_session(self, response: dict) -> None:
        if self.aggregation_runtime_reporter is None or not isinstance(response, dict):
            return
        device_session = None
        client_payload = response.get("client")
        if isinstance(client_payload, dict):
            device_session = client_payload.get("device_session")
        if not isinstance(device_session, dict):
            device_session = response.get("device_session")
        if not isinstance(device_session, dict):
            return
        self.aggregation_runtime_reporter.update_device_session(
            int(device_session.get("id") or 0),
            device_session.get("credential"),
        )
```

In `ClientAgent.apply_config_patch()`, capture reporter-directory changes immediately after the `if not isinstance(patch, dict): return` guard and before the `allowed_keys` set:

```python
        old_state_dir = self.state_dir
        old_snapshot_dir = self.bond_leg_snapshot_dir()
```

After the existing block that updates `self.state_dir`, `self.applied_path`, `self.pull_state_path`, and `self.usage_state_path`, add:

```python
        state_dir_changed = new_state_dir != old_state_dir
        snapshot_dir_changed = self.bond_leg_snapshot_dir() != old_snapshot_dir
        if state_dir_changed or snapshot_dir_changed:
            self.rebuild_aggregation_runtime_reporter()
```

This makes config changes to either `state_dir` or `bond_leg_snapshot_dir` take effect without waiting for process restart and without losing the memory-only telemetry credential. Passing `capability_report_factory=self.aggregation_capability_report` makes the independent 5-second telemetry POST carry fresh Forward-scoped client capability rows alongside snapshots, so the `<15s` eligibility gate does not depend on the slower heartbeat/config cadence.

After the existing config pull line:

```python
        payload, response_headers = http_json_with_headers("GET", f"{master}/api/client/configs/pull", token)
```

add:

```python
        self.update_aggregation_runtime_device_session(payload)
```

Change the heartbeat POST from a discarded return value to an assigned return value and preserve the current heartbeat dictionary exactly, with only the new plural Forward-scoped `capability_reports` key added:

```python
        heartbeat_body = {
            "agent_version": AGENT_VERSION,
                "usage_bytes_in": usage_totals["bytes_in"],
                "usage_bytes_out": usage_totals["bytes_out"],
                "supports_mptcp": self.running_kernel_supports_mptcp(),
                "mptcp_capability": self.mptcp_capability(),
                "kernel_release": platform.release(),
                "installer_version": str(self.config.get("installer_version", "") or ""),
                "installer_sha256": str(self.config.get("installer_sha256", "") or ""),
                "install_forward_runtime_sha256": file_sha256(self.install_forward_runtime_path()),
                "mptcp_client_sha256": file_sha256(self.mptcp_client_path()),
                "cfwg_watchdog_sha256": file_sha256(cfwg_watchdog_path) if cfwg_watchdog_path.is_file() else "",
                "wireguard_public_key": wireguard_public_key,
                "wireguard_listen_port": self.wireguard_listen_port(),
                "wireguard_fallback_tcp_port": self.wireguard_fallback_tcp_port(),
                "clock_sync": local_clock,
                "bond_leg_reputation": {
                    "forwards": self.load_bond_leg_reputation_snapshots(),
                },
                "capability_reports": self.aggregation_capability_reports(),
            }
        if self.aggregation_runtime_reporter is not None and self.aggregation_runtime_reporter.needs_credential():
            logging.warning("aggregation runtime reporter has no credential; telemetry remains paused until a separately authorized credential reset")
        heartbeat_response = http_json(
            "POST",
            f"{master}/api/client/heartbeat",
            token,
            heartbeat_body,
        )
        self.update_aggregation_runtime_device_session(heartbeat_response)
```

Do not add the device-session credential to heartbeat, capability reports, logs, `bond_leg_reputation`, or any payload other than the `/api/client/aggregation-runtime-snapshots` request headers. Keep existing `bond_leg_reputation` heartbeat reporting unchanged; that remains diagnostic and separate from Speed Plus proof. Do not add a singular unscoped `capability_report` key for Speed Plus gating; gate-capable client reports must be plural `capability_reports` entries carrying real Forward IDs.

In `ClientAgent.run()`, add an independent reporter cadence that is not tied to the 30-second config pull/apply cycle. First add a local schedule variable with the existing loop variables:

```python
        next_aggregation_runtime_report = 0.0
```

Then add this block after the health-check block and before the `run_cfwg_latency_watchdog()` call:

```python
            if now >= next_aggregation_runtime_report:
                if self.aggregation_runtime_reporter is not None:
                    try:
                        self.aggregation_runtime_reporter.flush_once()
                    except Exception as exc:  # noqa: BLE001
                        logging.exception("aggregation runtime reporter failed: %s", exc)
                next_aggregation_runtime_report = now + max(1, int(self.config.get("aggregation_runtime_report_interval_seconds", 5)))
```

This cadence must remain independent even when pull/apply fails or the pull interval is 30 seconds. The reporter itself keeps only the newest unsent sample per Forward, marks samples sent only after a matching `accepted_snapshots` identity or a matching rejected disposition with `terminal: true`, leaves retryable rejected identities unsent, and drops snapshots once their effective age reaches 15 seconds. Because the credential is intentionally memory-only, a restarted agent that has a known `device_session_id` but no credential logs and keeps telemetry paused. It must not request or trigger credential reissue/rotation in Task 39.

- [ ] **Step 5: Run reporter tests**

Run:

```bash
python client-agent/tests/test_aggregation_runtime_reporter.py
```

Expected: `OK`.

- [ ] **Step 6: Commit reporter integration**

Run:

```bash
git add client-agent/aggregation_runtime_reporter.py client-agent/tests/test_aggregation_runtime_reporter.py client-agent/client_agent.py master/src/Controllers/ClientAgentController.php
git commit -m "feat(client-agent): report standalone bond runtime snapshots

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Sanitize High-Churn Telemetry from Heartbeat Hot Paths

**Files:**
- Create: `master/tests/telemetry_payload_sanitizer_test.php`
- Create: `master/src/Support/TelemetryPayloadSanitizer.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/src/Controllers/NodeController.php`
- Modify: `master/src/Controllers/SidecarAgentController.php`

- [ ] **Step 1: Write the failing sanitizer test**

Create `master/tests/telemetry_payload_sanitizer_test.php`:

```php
<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\TelemetryPayloadSanitizer;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$input = [
    'agent_version' => '2026.07.19.1',
    'supports_udp' => true,
    'capability_report' => ['artifact_sha256' => str_repeat('a', 64)],
    'capability_reports' => [['forward_id' => 65, 'artifact_sha256' => str_repeat('a', 64)]],
    'bond_runtime_snapshot' => ['large' => str_repeat('x', 10000)],
    'bond_runtime_snapshots' => [['large' => str_repeat('x', 10000)]],
    'aggregation_runtime_snapshots' => [['large' => str_repeat('x', 10000)]],
    'traffic_window_json' => ['bytes' => 123],
    'recent_errors' => array_fill(0, 50, 'err'),
    'transition_history' => array_fill(0, 50, 'state'),
    'wireguard_peer_state' => [['interface_name' => 'wg0']],
];

$sanitized = TelemetryPayloadSanitizer::stripHighChurn($input);
foreach (['capability_report', 'capability_reports', 'bond_runtime_snapshot', 'bond_runtime_snapshots', 'aggregation_runtime_snapshots', 'traffic_window_json', 'recent_errors', 'transition_history'] as $key) {
    if (array_key_exists($key, $sanitized)) {
        fail('high-churn key survived: ' . $key);
    }
}
if (($sanitized['agent_version'] ?? null) !== '2026.07.19.1') {
    fail('stable field removed');
}
if (($sanitized['supports_udp'] ?? null) !== true) {
    fail('stable capability removed');
}
if (!isset($sanitized['wireguard_peer_state'])) {
    fail('existing bounded peer state removed');
}

echo "ok\n";
```

- [ ] **Step 2: Run the sanitizer test and verify it fails**

Run:

```bash
php master/tests/telemetry_payload_sanitizer_test.php
```

Expected: FAIL because `TelemetryPayloadSanitizer` does not exist.

- [ ] **Step 3: Create the sanitizer**

Create `master/src/Support/TelemetryPayloadSanitizer.php`:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class TelemetryPayloadSanitizer
{
    private const HIGH_CHURN_KEYS = [
        'capability_report',
        'capability_reports',
        'bond_runtime_snapshot',
        'bond_runtime_snapshots',
        'aggregation_runtime_snapshots',
        'telemetry_counters',
        'traffic_window_json',
        'recent_errors',
        'transition_history',
        'runtime_events',
    ];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function stripHighChurn(array $payload): array
    {
        foreach (self::HIGH_CHURN_KEYS as $key) {
            unset($payload[$key]);
        }
        return $payload;
    }
}
```

- [ ] **Step 4: Use the sanitizer before heartbeat config merges and payload storage**

Add this import to `ClientAgentController.php`, `NodeController.php`, and `SidecarAgentController.php`:

```php
use Leikwan\Master\Support\TelemetryPayloadSanitizer;
```

In `NodeController::heartbeat()`, change the top of the method from:

```php
        $node = Auth::requireNode($request, $this->db);
        $body = is_array($request->body()) ? $request->body() : [];
        $currentLoad = (int) $request->input('current_load', 0);
```

to:

```php
        $node = Auth::requireNode($request, $this->db);
        $body = is_array($request->body()) ? $request->body() : [];
        $stableBody = TelemetryPayloadSanitizer::stripHighChurn($body);
        $currentLoad = (int) $request->input('current_load', 0);
```

Then change the durable capability merge from:

```php
        $mergedCapabilities = $this->mergeCapabilityConfig($existingAgentConfig, $body);
```

to:

```php
        $mergedCapabilities = $this->mergeCapabilityConfig($existingAgentConfig, $stableBody);
```

Change the heartbeat payload storage from:

```php
                'payload_json' => json_encode($request->body(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
```

to:

```php
                'payload_json' => json_encode($stableBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
```

Keep explicit node/slave `capability_report` extraction on `$body`, because Task 4 intentionally records that node-role diagnostic report through `AgentCapabilityReportService` before the stable heartbeat body is stored. This sentence applies to `NodeController::heartbeat()` only; it does not authorize singular client `capability_report` ingestion in `ClientAgentController::heartbeat()`. For provisioning calls that take the whole heartbeat body, pass `$stableBody` so high-churn telemetry is not durably copied into downstream state. Change the donation self-check block from:

```php
        if (array_key_exists('donation_self_check', $body) && is_array($body['donation_self_check'])) {
            $this->guardProvisioning($nodeId, 'recordDonationSelfCheck', function () use ($nodeId, $body): void {
                $this->provisioning->recordDonationSelfCheck($nodeId, $body);
            });
        }
```

to:

```php
        if (array_key_exists('donation_self_check', $body) && is_array($body['donation_self_check'])) {
            $this->guardProvisioning($nodeId, 'recordDonationSelfCheck', function () use ($nodeId, $stableBody): void {
                $this->provisioning->recordDonationSelfCheck($nodeId, $stableBody);
            });
        }
```

In the same method, change the remediation skip condition from:

```php
        if ($shouldRepublishNodeEndpoints && (!array_key_exists('forward_chain_remediation', $body) || !is_array($body['forward_chain_remediation']))) {
```

to:

```php
        if ($shouldRepublishNodeEndpoints && (!array_key_exists('forward_chain_remediation', $stableBody) || !is_array($stableBody['forward_chain_remediation']))) {
```

In `ClientAgentController::heartbeat()`, add a stable body immediately after authentication:

```php
        $client = Auth::requireClientAgent($request, $this->db);
        $body = is_array($request->body()) ? $request->body() : [];
        $stableBody = TelemetryPayloadSanitizer::stripHighChurn($body);
        $deviceSessionService = new ClientOnlineSessionService($this->db);
```

Then change the `mergeRuntimeState()` input from direct `$request->input(...)` calls to stable scalar body values with request-input fallback for compatibility:

```php
        $runtimeState = $this->mergeRuntimeState($existingAgentConfig, [
            'supports_mptcp' => $stableBody['supports_mptcp'] ?? $request->input('supports_mptcp'),
            'wireguard_public_key' => $stableBody['wireguard_public_key'] ?? $request->input('wireguard_public_key'),
            'cfwg_watchdog_sha256' => $stableBody['cfwg_watchdog_sha256'] ?? $request->input('cfwg_watchdog_sha256'),
            'wireguard_listen_port' => $stableBody['wireguard_listen_port'] ?? $request->input('wireguard_listen_port'),
            'wireguard_fallback_tcp_port' => $stableBody['wireguard_fallback_tcp_port'] ?? $request->input('wireguard_fallback_tcp_port'),
        ]);
```

Keep explicit plural `capability_reports[]` handling from Task 4 on `$body` before durable sanitization, and keep `bond_leg_reputation` ingestion on `$request->input(...)`; they are separately reduced by `AgentCapabilityReportService` and `BondLegReputationService` and must not be copied into `agent_config_json`. Do not keep, re-add, or reduce singular client `capability_report` in `ClientAgentController::heartbeat()`; singular client capability payloads are legacy diagnostic noise and must not feed `AgentCapabilityReportService` or Speed Plus gates.

In `SidecarAgentController::heartbeat()`, add a stable body immediately after `$body` is assigned:

```php
        $point = Auth::requireSidecarPointAgent($request, $this->db);
        $body = is_array($request->body()) ? $request->body() : [];
        $stableBody = TelemetryPayloadSanitizer::stripHighChurn($body);
        $bondBinary = $this->artifactMetadata('leikwan_bond_linux_amd64');
```

Then change the `mergeRuntimeState()` input from direct `$request->input(...)` calls to stable body values with request-input fallback:

```php
        $runtimeState = $this->mergeRuntimeState($existingAgentConfig, [
            'supports_mptcp' => $stableBody['supports_mptcp'] ?? $request->input('supports_mptcp'),
            'wireguard_public_key' => $stableBody['wireguard_public_key'] ?? $request->input('wireguard_public_key'),
            'wireguard_listen_port' => $stableBody['wireguard_listen_port'] ?? $request->input('wireguard_listen_port'),
            'wireguard_fallback_tcp_port' => $stableBody['wireguard_fallback_tcp_port'] ?? $request->input('wireguard_fallback_tcp_port'),
            'wireguard_peer_state' => $stableBody['wireguard_peer_state'] ?? $request->input('wireguard_peer_state'),
        ]);
```

Keep explicit `bond_leg_reputation` ingestion unchanged because it is already reduced by `BondLegReputationService`. For donation reverse probe storage, pass the sanitized whole body while still checking the original explicit key. Change:

```php
        if (array_key_exists('donation_reverse_probe', $body) && is_array($body['donation_reverse_probe'])) {
            $this->provisioning->recordDonationReverseProbe((int) $point['id'], $body);
        }
```

to:

```php
        if (array_key_exists('donation_reverse_probe', $body) && is_array($body['donation_reverse_probe'])) {
            $this->provisioning->recordDonationReverseProbe((int) $point['id'], $stableBody);
        }
```

- [ ] **Step 5: Run sanitizer and heartbeat regression tests**

Run:

```bash
php master/tests/telemetry_payload_sanitizer_test.php
php master/tests/node_heartbeat_republish_gate_regression_test.php
php master/tests/client_heartbeat_republish_gate_regression_test.php
php master/tests/sidecar_heartbeat_republish_gate_regression_test.php
```

Expected: all print `ok`.

- [ ] **Step 6: Commit heartbeat sanitization**

Run:

```bash
git add master/tests/telemetry_payload_sanitizer_test.php master/src/Support/TelemetryPayloadSanitizer.php master/src/Controllers/ClientAgentController.php master/src/Controllers/NodeController.php master/src/Controllers/SidecarAgentController.php
git commit -m "fix(master): keep telemetry out of heartbeat churn paths

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---


### Task 9: Add Telemetry-Only Retention Pruning

**Files:**
- Create: `master/tests/telemetry_retention_service_test.php`
- Create: `master/src/Support/TelemetryRetentionService.php`
- Modify: `master/cli/cleanup-tasks.php`

- [ ] **Step 1: Write the failing telemetry retention test**

Create `master/tests/telemetry_retention_service_test.php`:

```php
<?php
declare(strict_types=1);

use Leikwan\Master\Support\TelemetryRetentionService;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

final class RetentionFakeDb
{
    public array $sql = [];

    public function execute(string $sql, array $params = []): int
    {
        $this->sql[] = $sql;
        foreach (['systemd', 'wg-quick', 'ip link', 'service_endpoints', 'forwards ', 'wireguard'] as $forbidden) {
            if (stripos($sql, $forbidden) !== false) {
                fail('retention service must not touch runtime or Forward resources: ' . $forbidden);
            }
        }
        return 1;
    }
}

$db = new RetentionFakeDb();
$result = (new TelemetryRetentionService($db))->pruneExpiredRows(1784410000000);
foreach (['forward_aggregation_runtime_window_proofs', 'forward_aggregation_runtime_snapshots', 'forward_aggregation_runtime_snapshot_rejections', 'agent_capability_reports'] as $table) {
    if (!array_key_exists($table, $result)) {
        fail('missing retention count for ' . $table);
    }
}
$combined = implode("\n", $db->sql);
foreach (['DELETE FROM forward_aggregation_runtime_window_proofs', 'DELETE FROM forward_aggregation_runtime_snapshots', 'DELETE FROM forward_aggregation_runtime_snapshot_rejections', 'DELETE FROM agent_capability_reports'] as $snippet) {
    if (!str_contains($combined, $snippet)) {
        fail('missing telemetry-only delete: ' . $snippet);
    }
}

$cleanupCli = file_get_contents(__DIR__ . '/../cli/cleanup-tasks.php') ?: '';
foreach (['TelemetryRetentionService', 'pruneExpiredRows((int) floor(microtime(true) * 1000))'] as $snippet) {
    if (!str_contains($cleanupCli, $snippet)) {
        fail('cleanup CLI must wire telemetry pruning through existing database cleanup schedule: ' . $snippet);
    }
}
foreach (['wg-quick down', 'systemctl stop', 'systemctl disable', 'ip link del'] as $forbidden) {
    if (str_contains($cleanupCli, $forbidden)) {
        fail('Task 39 cleanup CLI wiring must not add host runtime cleanup: ' . $forbidden);
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the retention test and verify it fails**

Run:

```bash
php master/tests/telemetry_retention_service_test.php
```

Expected: FAIL because `TelemetryRetentionService` does not exist.

- [ ] **Step 3: Create telemetry-only retention service**

Create `master/src/Support/TelemetryRetentionService.php`:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class TelemetryRetentionService
{
    public function __construct(private object $db)
    {
    }

    /**
     * @return array<string, int>
     */
    public function pruneExpiredRows(int $nowEpochMs): array
    {
        $counts = [];
        $counts['forward_aggregation_runtime_window_proofs'] = $this->db->execute(
            'DELETE FROM forward_aggregation_runtime_window_proofs WHERE row_expires_at_epoch_ms < :now_epoch_ms',
            ['now_epoch_ms' => $nowEpochMs]
        );
        $counts['forward_aggregation_runtime_snapshots'] = $this->db->execute(
            'DELETE FROM forward_aggregation_runtime_snapshots WHERE row_expires_at_epoch_ms < :now_epoch_ms',
            ['now_epoch_ms' => $nowEpochMs]
        );
        $counts['forward_aggregation_runtime_snapshot_rejections'] = $this->db->execute(
            'DELETE FROM forward_aggregation_runtime_snapshot_rejections WHERE received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)',
            []
        );
        $counts['agent_capability_reports'] = $this->db->execute(
            'DELETE FROM agent_capability_reports WHERE received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)',
            []
        );
        return $counts;
    }
}
```

This service is intentionally database-telemetry-only. It must never delete WireGuard interfaces, systemd units, Forward rows, service endpoint rows, artifacts, or any host runtime resource; remote cleanup remains exclusively in the separately approved retirement/cleanup plan and is not activated by Task 39.

- [ ] **Step 4: Wire telemetry pruning into the existing database cleanup CLI**

In `master/cli/cleanup-tasks.php`, add this import next to the existing `use` statement:

```php
use Leikwan\Master\Support\TelemetryRetentionService;
```

After `$db = $app->db();`, add:

```php
$telemetryRetention = (new TelemetryRetentionService($db))->pruneExpiredRows((int) floor(microtime(true) * 1000));
echo "[cleanup-tasks] pruned telemetry rows: " . json_encode($telemetryRetention, JSON_UNESCAPED_SLASHES) . "
";
```

This is the only Task 39 retention wiring. It reuses the existing database cleanup entrypoint so expired telemetry rows are pruned whenever normal DB cleanup runs, but it does not create, stop, disable, delete, or inspect any host service, WireGuard interface, Forward runtime, artifact, or remote resource.

- [ ] **Step 5: Run retention test**

Run:

```bash
php master/tests/telemetry_retention_service_test.php
```

Expected: `ok`.

- [ ] **Step 6: Commit telemetry retention service**

Run:

```bash
git add master/tests/telemetry_retention_service_test.php master/src/Support/TelemetryRetentionService.php master/cli/cleanup-tasks.php
git commit -m "feat(master): prune aggregation telemetry rows

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Add Read-Only Eligibility Gates and Operations Display

**Files:**
- Create: `master/tests/bond_runtime_eligibility_display_test.php`
- Create: `master/src/Support/BondRuntimeEligibilityService.php`
- Modify: `master/src/Controllers/AdminController.php`

This task is read-only. It computes and displays eligibility evidence but must not feed the result into runtime publication, config selection, artifact rollout, cleanup, or Speed Plus enforcement. Upload and download are independent gates, and a gate is scoped to `(client_id, client_device_session_id, forward_id, direction, desired_generation, desired_leg_set_hash)`. Lifecycle rows in `forward_aggregation_runtime_snapshots` are display evidence only; upload/download proof must come from `forward_aggregation_runtime_window_proofs` plus a fresh accepted client capability report. The operations display must show upload and download gate labels/reasons from `BondRuntimeEligibilityService::gateForForwardDirection()`, not infer Speed Plus readiness from lifecycle-only `derived_state=aggregating`.

- [ ] **Step 1: Write the failing eligibility/display test**

Create `master/tests/bond_runtime_eligibility_display_test.php`:

```php
<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\BondRuntimeEligibilityService;

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function now_ms_string(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d H:i:s', time() + $offsetSeconds) . '.000';
}

final class EligibilityFakeDb
{
    public array $snapshots = [];
    public array $proofs = [];
    public array $capabilities = [];

    private static function isFresh(array $row, int $freshAgeMs): bool
    {
        $received = strtotime(substr((string) ($row['received_at'] ?? ''), 0, 19)) ?: 0;
        $expires = strtotime(substr((string) ($row['row_expires_at'] ?? ''), 0, 19)) ?: (time() + 1);
        $effectiveAgeMs = (int) ($row['sample_age_ms'] ?? 0) + max(0, (time() - $received) * 1000);
        return $expires > time() && $effectiveAgeMs < $freshAgeMs;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if (str_contains($sql, 'FROM forward_aggregation_runtime_snapshots')) {
            return array_values(array_filter($this->snapshots, static function (array $row) use ($params): bool {
                if (($row['active_family'] ?? '') !== ($params['active_family'] ?? 'standalone_bond')) {
                    return false;
                }
                if (isset($params['forward_id']) && (int) ($row['forward_id'] ?? 0) !== (int) $params['forward_id']) {
                    return false;
                }
                return EligibilityFakeDb::isFresh($row, (int) ($params['fresh_age_ms'] ?? 15000));
            }));
        }
        if (str_contains($sql, 'FROM forward_aggregation_runtime_window_proofs')) {
            return array_values(array_filter($this->proofs, static function (array $row) use ($params): bool {
                foreach (['client_id', 'client_device_session_id', 'forward_id'] as $key) {
                    if (isset($params[$key]) && (int) ($row[$key] ?? 0) !== (int) $params[$key]) {
                        return false;
                    }
                }
                if (($row['active_family'] ?? '') !== ($params['active_family'] ?? 'standalone_bond')) {
                    return false;
                }
                if (isset($params['direction']) && (string) ($row['useful_window_direction'] ?? '') !== (string) $params['direction']) {
                    return false;
                }
                return EligibilityFakeDb::isFresh($row, (int) ($params['fresh_age_ms'] ?? 15000));
            }));
        }
        if (str_contains($sql, 'FROM agent_capability_reports')) {
            return array_values(array_filter($this->capabilities, static function (array $row) use ($params): bool {
                if (($row['role_type'] ?? '') !== 'client' || (int) ($row['role_id'] ?? 0) !== (int) $params['client_id']) {
                    return false;
                }
                if ((int) ($row['client_device_session_id'] ?? 0) !== (int) $params['client_device_session_id']) {
                    return false;
                }
                if ((int) ($row['forward_id'] ?? 0) !== (int) $params['forward_id']) {
                    return false;
                }
                if (($row['report_status'] ?? '') !== 'accepted') {
                    return false;
                }
                if (($row['expected_artifact_sha256'] ?? '') !== ($row['reported_artifact_sha256'] ?? '')) {
                    return false;
                }
                foreach (['expected_artifact_sha256', 'reported_artifact_sha256', 'standalone_bond_sha256'] as $hashKey) {
                    if (!preg_match('/^[a-f0-9]{64}$/', (string) ($row[$hashKey] ?? ''))) {
                        return false;
                    }
                }
                if (($row['standalone_bond_sha256'] ?? '') !== ($row['reported_artifact_sha256'] ?? '')) {
                    return false;
                }
                return EligibilityFakeDb::isFresh($row + ['row_expires_at' => now_ms_string(60), 'sample_age_ms' => 0], (int) ($params['fresh_age_ms'] ?? 15000));
            }));
        }
        return [];
    }
}

$db = new EligibilityFakeDb();
$service = new BondRuntimeEligibilityService($db);
$rows = $service->latestRows(10);
if ($rows !== []) {
    fail('empty DB should have no rows');
}

$hash = str_repeat('1', 64);
$artifactHash = str_repeat('a', 64);
$db->snapshots[] = [
    'client_id' => 7,
    'client_device_session_id' => 10,
    'forward_id' => 65,
    'active_family' => 'standalone_bond',
    'derived_state' => 'aggregating',
    'desired_generation' => 4,
    'desired_leg_set_hash' => $hash,
    'received_at' => now_ms_string(),
    'row_expires_at' => now_ms_string(10),
    'sample_age_ms' => 100,
];
$db->proofs[] = [
    'client_id' => 7,
    'client_device_session_id' => 10,
    'forward_id' => 65,
    'active_family' => 'standalone_bond',
    'desired_generation' => 4,
    'desired_leg_set_hash' => $hash,
    'useful_window_direction' => 'download',
    'useful_window_total_bytes' => 2097152,
    'received_at' => now_ms_string(),
    'row_expires_at' => now_ms_string(10),
    'sample_age_ms' => 0,
];
$db->capabilities[] = [
    'role_type' => 'client',
    'role_id' => 7,
    'client_device_session_id' => 10,
    'forward_id' => 65,
    'report_status' => 'accepted',
    'expected_artifact_sha256' => $artifactHash,
    'reported_artifact_sha256' => $artifactHash,
    'standalone_bond_sha256' => $artifactHash,
    'reporter_schema_version' => 1,
    'bond_snapshot_contract_version' => 1,
    'received_at' => now_ms_string(),
];
$rows = $service->latestRows(10);
if (($rows[0]['derived_state'] ?? '') !== 'aggregating') {
    fail('expected aggregating lifecycle row');
}
if (($rows[0]['display_family'] ?? '') !== 'LeiGod standalone bond') {
    fail('expected standalone bond display family');
}
if (($rows[0]['display_direction'] ?? '') !== 'None') {
    fail('lifecycle rows are display evidence only and must not impersonate upload/download proof direction');
}
$downloadGate = $service->gateForForwardDirection(7, 10, 65, 'download', 4, $hash);
if (($downloadGate['eligible'] ?? true) !== true || ($downloadGate['reason'] ?? '') !== 'speed_plus_download_proven') {
    fail('download gate should be eligible when fresh client capability and download proof both exist');
}
$mismatchGate = $service->gateForForwardDirection(7, 10, 65, 'download', 5, str_repeat('2', 64));
if (($mismatchGate['eligible'] ?? true) !== false || ($mismatchGate['reason'] ?? '') !== 'desired_state_mismatch') {
    fail('runtime gate must fail closed when fresh proof belongs to an old desired generation/hash');
}
$uploadGate = $service->gateForForwardDirection(7, 10, 65, 'upload', 4, $hash);
if (($uploadGate['eligible'] ?? true) !== false || ($uploadGate['reason'] ?? '') !== 'missing_fresh_upload_aggregation_proof') {
    fail('upload gate must fail closed when only download proof exists');
}

$db->capabilities = [];
$missingCapability = $service->gateForForwardDirection(7, 10, 65, 'download', 4, $hash);
if (($missingCapability['eligible'] ?? true) !== false || ($missingCapability['reason'] ?? '') !== 'missing_fresh_client_capability') {
    fail('proof rows without a fresh accepted client capability must not pass the gate');
}

$uppercaseArtifactHash = strtoupper($artifactHash);
$db->capabilities = [[
    'role_type' => 'client',
    'role_id' => 7,
    'client_device_session_id' => 10,
    'forward_id' => 65,
    'report_status' => 'accepted',
    'expected_artifact_sha256' => $uppercaseArtifactHash,
    'reported_artifact_sha256' => $uppercaseArtifactHash,
    'standalone_bond_sha256' => $uppercaseArtifactHash,
    'reporter_schema_version' => 1,
    'bond_snapshot_contract_version' => 1,
    'received_at' => now_ms_string(),
]];
$uppercaseCapability = $service->gateForForwardDirection(7, 10, 65, 'download', 4, $hash);
if (($uppercaseCapability['eligible'] ?? true) !== false || ($uppercaseCapability['reason'] ?? '') !== 'missing_fresh_client_capability') {
    fail('uppercase 64-hex capability hashes must not satisfy the lower-hex Speed Plus gate');
}
$db->capabilities = [[
    'role_type' => 'client',
    'role_id' => 7,
    'client_device_session_id' => 10,
    'forward_id' => 999,
    'report_status' => 'accepted',
    'expected_artifact_sha256' => $artifactHash,
    'reported_artifact_sha256' => $artifactHash,
    'standalone_bond_sha256' => $artifactHash,
    'reporter_schema_version' => 1,
    'bond_snapshot_contract_version' => 1,
    'received_at' => now_ms_string(),
]];
$unassignedCapability = $service->gateForForwardDirection(7, 10, 65, 'download', 4, $hash);
if (($unassignedCapability['eligible'] ?? true) !== false || ($unassignedCapability['reason'] ?? '') !== 'missing_fresh_client_capability') {
    fail('positive but unassigned capability_reports[] forward_id must not create a capability row that satisfies another Forward gate');
}
$db->capabilities = [];

$db->snapshots = [[
    'client_id' => 7,
    'client_device_session_id' => 10,
    'forward_id' => 65,
    'active_family' => 'standalone_bond',
    'derived_state' => 'aggregating',
    'received_at' => now_ms_string(),
    'row_expires_at' => now_ms_string(10),
    'sample_age_ms' => 15000,
]];
$db->capabilities[] = [
    'role_type' => 'client',
    'role_id' => 7,
    'client_device_session_id' => 10,
    'forward_id' => 65,
    'report_status' => 'accepted',
    'expected_artifact_sha256' => $artifactHash,
    'reported_artifact_sha256' => $artifactHash,
    'standalone_bond_sha256' => $artifactHash,
    'reporter_schema_version' => 1,
    'bond_snapshot_contract_version' => 1,
    'received_at' => now_ms_string(),
];
$db->proofs = [[
    'client_id' => 7,
    'client_device_session_id' => 10,
    'forward_id' => 65,
    'active_family' => 'standalone_bond',
    'desired_generation' => 4,
    'desired_leg_set_hash' => $hash,
    'useful_window_direction' => 'download',
    'useful_window_total_bytes' => 2097152,
    'received_at' => now_ms_string(),
    'row_expires_at' => now_ms_string(-1),
    'sample_age_ms' => 0,
]];
if ($service->latestRows(10) !== []) {
    fail('exactly 15 seconds must be hidden as stale');
}
if (($service->gateForForwardDirection(7, 10, 65, 'download', 4, $hash)['eligible'] ?? true) !== false) {
    fail('expired proof row must fail the runtime gate');
}

$db->snapshots = [[
    'client_id' => 7,
    'client_device_session_id' => 10,
    'forward_id' => 65,
    'active_family' => 'standalone_bond',
    'derived_state' => 'aggregating',
    'received_at' => now_ms_string(-20),
    'row_expires_at' => now_ms_string(10),
    'sample_age_ms' => 100,
]];
if ($service->latestRows(10) !== []) {
    fail('old received_at plus low sample_age_ms must be hidden as stale');
}

$serviceSource = file_get_contents(__DIR__ . '/../src/Support/BondRuntimeEligibilityService.php');
if ($serviceSource === false) {
    fail('failed to read BondRuntimeEligibilityService');
}
foreach (['forward_aggregation_runtime_window_proofs', 'row_expires_at > UTC_TIMESTAMP(3)', 'TIMESTAMPDIFF(MICROSECOND, received_at, UTC_TIMESTAMP(3))', 'gateForForwardDirection(int $clientId, int $clientDeviceSessionId, int $forwardId', 'freshClientCapability', 'BINARY expected_artifact_sha256 REGEXP', 'BINARY reported_artifact_sha256 REGEXP', 'BINARY standalone_bond_sha256 REGEXP', 'standalone_bond_sha256 = reported_artifact_sha256', 'useful_window_direction', 'desired_leg_set_hash', 'desired_state_mismatch'] as $snippet) {
    if (!str_contains($serviceSource, $snippet)) {
        fail('eligibility service missing freshness/gate snippet: ' . $snippet);
    }
}

$adminSource = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
if ($adminSource === false) {
    fail('failed to read AdminController');
}
foreach (['renderBondTelemetryGateSection', 'data-section="bond-telemetry-gates"', 'BondRuntimeEligibilityService', 'upload_gate_label', 'download_gate_label', 'speed_plus_download_proven', 'missing_fresh_upload_aggregation_proof'] as $snippet) {
    if (!str_contains($adminSource, $snippet)) {
        fail('AdminController missing snippet: ' . $snippet);
    }
}
$sectionStart = strpos($adminSource, 'function renderBondTelemetryGateSection');
$section = $sectionStart === false ? '' : substr($adminSource, $sectionStart, 5000);
foreach (['<form', 'method="post"', 'republish', 'cleanup', 'retire', 'reactivate', 'rotate', 'delete'] as $forbidden) {
    if (stripos($section, $forbidden) !== false) {
        fail('read-only telemetry section contains forbidden action: ' . $forbidden);
    }
}

echo "ok\n";

```

- [ ] **Step 2: Run the eligibility/display test and verify it fails**

Run:

```bash
php master/tests/bond_runtime_eligibility_display_test.php
```

Expected: FAIL because the service/display helper do not exist.

- [ ] **Step 3: Create the read-only eligibility service**

Create `master/src/Support/BondRuntimeEligibilityService.php`:

```php
<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class BondRuntimeEligibilityService
{
    private const FRESH_AGE_MS = 15000;
    private const MIN_USEFUL_BYTES = 1048576;

    public function __construct(private object $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function latestRows(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->freshRows(
            'ORDER BY received_at DESC, id DESC LIMIT ' . $limit,
            []
        );
        return array_map(function (array $row): array {
            $decorated = $this->decorateRow($row);
            $clientId = (int) ($row['client_id'] ?? 0);
            $deviceSessionId = (int) ($row['client_device_session_id'] ?? 0);
            $forwardId = (int) ($row['forward_id'] ?? 0);
            $desiredGeneration = (int) ($row['desired_generation'] ?? 0);
            $desiredHash = (string) ($row['desired_leg_set_hash'] ?? '');
            $uploadGate = $this->gateForForwardDirection($clientId, $deviceSessionId, $forwardId, 'upload', $desiredGeneration, $desiredHash);
            $downloadGate = $this->gateForForwardDirection($clientId, $deviceSessionId, $forwardId, 'download', $desiredGeneration, $desiredHash);
            $decorated['upload_gate_label'] = self::gateLabel($uploadGate);
            $decorated['upload_gate_reason'] = (string) ($uploadGate['reason'] ?? 'unknown');
            $decorated['upload_useful_bytes'] = (int) ($uploadGate['row']['useful_window_total_bytes'] ?? 0);
            $decorated['download_gate_label'] = self::gateLabel($downloadGate);
            $decorated['download_gate_reason'] = (string) ($downloadGate['reason'] ?? 'unknown');
            $decorated['download_useful_bytes'] = (int) ($downloadGate['row']['useful_window_total_bytes'] ?? 0);
            return $decorated;
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function gateForForwardDirection(int $clientId, int $clientDeviceSessionId, int $forwardId, string $direction, int $desiredGeneration, string $desiredLegSetHash): array
    {
        $direction = strtolower(trim($direction));
        if (!in_array($direction, ['upload', 'download'], true)) {
            return ['eligible' => false, 'reason' => 'invalid_speed_plus_direction'];
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $desiredLegSetHash) || $desiredGeneration <= 0) {
            return ['eligible' => false, 'reason' => 'desired_state_required'];
        }
        if (!$this->freshClientCapability($clientId, $clientDeviceSessionId, $forwardId)) {
            return ['eligible' => false, 'reason' => 'missing_fresh_client_capability'];
        }
        $rows = $this->freshProofRows(
            'AND client_id = :client_id AND client_device_session_id = :client_device_session_id AND forward_id = :forward_id AND useful_window_direction = :direction ORDER BY received_at DESC, id DESC LIMIT 1',
            ['client_id' => $clientId, 'client_device_session_id' => $clientDeviceSessionId, 'forward_id' => $forwardId, 'direction' => $direction]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row) || (int) ($row['useful_window_total_bytes'] ?? 0) < self::MIN_USEFUL_BYTES) {
            return ['eligible' => false, 'reason' => 'missing_fresh_' . $direction . '_aggregation_proof'];
        }
        if ((int) ($row['desired_generation'] ?? 0) !== $desiredGeneration) {
            return ['eligible' => false, 'reason' => 'desired_state_mismatch'];
        }
        if ((string) ($row['desired_leg_set_hash'] ?? '') !== $desiredLegSetHash) {
            return ['eligible' => false, 'reason' => 'desired_state_mismatch'];
        }
        return ['eligible' => true, 'reason' => 'speed_plus_' . $direction . '_proven', 'row' => $this->decorateRow($row)];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function freshRows(string $suffixSql, array $params): array
    {
        return $this->db->fetchAll(
            'SELECT *
             FROM forward_aggregation_runtime_snapshots
             WHERE active_family = :active_family
               AND row_expires_at > UTC_TIMESTAMP(3)
               AND sample_age_ms + TIMESTAMPDIFF(MICROSECOND, received_at, UTC_TIMESTAMP(3)) / 1000 < :fresh_age_ms
               ' . $suffixSql,
            $params + ['active_family' => 'standalone_bond', 'fresh_age_ms' => self::FRESH_AGE_MS]
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function freshProofRows(string $suffixSql, array $params): array
    {
        return $this->db->fetchAll(
            'SELECT *
             FROM forward_aggregation_runtime_window_proofs
             WHERE active_family = :active_family
               AND useful_window_direction IN (\'upload\',\'download\')
               AND row_expires_at > UTC_TIMESTAMP(3)
               AND TIMESTAMPDIFF(MICROSECOND, received_at, UTC_TIMESTAMP(3)) / 1000 < :fresh_age_ms
               ' . $suffixSql,
            $params + ['active_family' => 'standalone_bond', 'fresh_age_ms' => self::FRESH_AGE_MS]
        );
    }

    private function freshClientCapability(int $clientId, int $clientDeviceSessionId, int $forwardId): bool
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM agent_capability_reports
             WHERE role_type = \'client\'
               AND role_id = :client_id
               AND client_device_session_id = :client_device_session_id
               AND forward_id = :forward_id
               AND report_status = \'accepted\'
               AND BINARY expected_artifact_sha256 REGEXP \'^[a-f0-9]{64}$\'
               AND BINARY reported_artifact_sha256 REGEXP \'^[a-f0-9]{64}$\'
               AND BINARY standalone_bond_sha256 REGEXP \'^[a-f0-9]{64}$\'
               AND expected_artifact_sha256 = reported_artifact_sha256
               AND standalone_bond_sha256 = reported_artifact_sha256
               AND reporter_schema_version >= 1
               AND bond_snapshot_contract_version >= 1
               AND TIMESTAMPDIFF(MICROSECOND, received_at, UTC_TIMESTAMP(3)) / 1000 < :fresh_age_ms
             ORDER BY received_at DESC, id DESC LIMIT 1',
            ['client_id' => $clientId, 'client_device_session_id' => $clientDeviceSessionId, 'forward_id' => $forwardId, 'fresh_age_ms' => self::FRESH_AGE_MS]
        );
        return $rows !== [];
    }

    /**
     * @param array<string, mixed> $gate
     */
    private static function gateLabel(array $gate): string
    {
        if (($gate['eligible'] ?? false) === true) {
            return 'Eligible: ' . (string) ($gate['reason'] ?? 'speed_plus_proven');
        }
        return 'Blocked: ' . (string) ($gate['reason'] ?? 'unknown');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decorateRow(array $row): array
    {
        $row['display_family'] = 'LeiGod standalone bond';
        $row['display_direction'] = match ((string) ($row['useful_window_direction'] ?? '')) {
            'upload' => 'Upload',
            'download' => 'Download',
            default => 'None',
        };
        $row['gate_label'] = match ((string) ($row['derived_state'] ?? 'unknown')) {
            'aggregating' => 'Aggregating',
            'ready_multi_leg' => 'Ready, waiting for traffic proof',
            'degraded_single_leg' => 'Degraded to best Slave',
            'single_leg_only' => 'Single Slave',
            'unavailable' => 'Unavailable',
            default => 'Unknown',
        };
        return $row;
    }
}

```

- [ ] **Step 4: Render the read-only admin section**

Add this import to `AdminController.php`:

```php
use Leikwan\Master\Support\BondRuntimeEligibilityService;
```

In `diagnosticsPage()`, after `$reputationEvents` is assigned, add:

```php
        $bondTelemetryRows = (new BondRuntimeEligibilityService($this->db))->latestRows(50);
```

Append this renderer call after the existing bond reputation diagnostics section:

```php
              ' . $this->renderBondTelemetryGateSection($bondTelemetryRows) . '
```

Add this private method to `AdminController.php`:

```php
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderBondTelemetryGateSection(array $rows): string
    {
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>'
                . '<td>' . (int) ($row['forward_id'] ?? 0) . '</td>'
                . '<td>' . (int) ($row['client_id'] ?? 0) . '</td>'
                . '<td>' . (int) ($row['client_device_session_id'] ?? 0) . '</td>'
                . '<td>' . $this->escape((string) ($row['display_family'] ?? 'LeiGod standalone bond')) . '</td>'
                . '<td>' . $this->escape((string) ($row['derived_state'] ?? 'unknown')) . '</td>'
                . '<td>' . $this->escape((string) ($row['display_direction'] ?? 'None')) . '</td>'
                . '<td>' . $this->escape((string) ($row['upload_gate_label'] ?? 'Blocked: unknown')) . '<br><span class="muted">' . $this->escape((string) ($row['upload_gate_reason'] ?? 'unknown')) . '</span></td>'
                . '<td>' . (int) ($row['upload_useful_bytes'] ?? 0) . '</td>'
                . '<td>' . $this->escape((string) ($row['download_gate_label'] ?? 'Blocked: unknown')) . '<br><span class="muted">' . $this->escape((string) ($row['download_gate_reason'] ?? 'unknown')) . '</span></td>'
                . '<td>' . (int) ($row['download_useful_bytes'] ?? 0) . '</td>'
                . '<td>' . (int) ($row['sample_age_ms'] ?? 0) . '</td>'
                . '<td>' . $this->escape((string) ($row['received_at'] ?? '')) . '</td>'
                . '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td colspan="12" class="muted">No standalone bond telemetry has been accepted yet.</td></tr>';
        }

        return '<section class="card" data-section="bond-telemetry-gates">'
            . '<h3>LeiGod Standalone Bond Telemetry Gates</h3>'
            . '<p class="muted">Read-only latest client-device evidence. This section contains no runtime controls.</p>'
            . '<div class="table-wrap"><table>'
            . '<thead><tr><th>Forward</th><th>Client</th><th>Device Session</th><th>Family</th><th>State</th><th>Direction</th><th>Upload Gate</th><th>Upload Bytes</th><th>Download Gate</th><th>Download Bytes</th><th>Age ms</th><th>Received</th></tr></thead>'
            . '<tbody>' . $body . '</tbody>'
            . '</table></div>'
            . '</section>';
    }
```

- [ ] **Step 5: Run eligibility/display tests**

Run:

```bash
php master/tests/bond_runtime_eligibility_display_test.php
```

Expected: `ok`.

- [ ] **Step 6: Commit read-only display**

Run:

```bash
git add master/tests/bond_runtime_eligibility_display_test.php master/src/Support/BondRuntimeEligibilityService.php master/src/Controllers/AdminController.php
git commit -m "feat(master): show standalone bond telemetry gates

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: Add Contract Drift and Forbidden-Scope Audits

**Files:**
- Create: `master/tests/leigod_telemetry_contract_drift_guard_test.php`

- [ ] **Step 1: Write the contract drift guard test**

Create `master/tests/leigod_telemetry_contract_drift_guard_test.php`:

```php
<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__);
$repo = dirname($root);

function read_source(string $path): string
{
    if (!is_file($path)) {
        fail('missing expected implementation file: ' . $path);
    }
    $source = file_get_contents($path);
    if ($source === false) {
        fail('failed to read source file: ' . $path);
    }
    return $source;
}

function extract_method(string $source, string $signature): string
{
    $start = strpos($source, $signature);
    if ($start === false) {
        fail('missing method signature: ' . $signature);
    }
    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        fail('method has no body: ' . $signature);
    }
    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        $char = $source[$i];
        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    fail('method body did not close: ' . $signature);
}

$sources = [
    'ClientDeviceSessionContext' => read_source($root . '/src/Support/ClientDeviceSessionContext.php'),
    'AgentCapabilityReportService' => read_source($root . '/src/Support/AgentCapabilityReportService.php'),
    'BondRuntimeAssignmentContext' => read_source($root . '/src/Support/BondRuntimeAssignmentContext.php'),
    'BondRuntimeAssignmentRepository' => read_source($root . '/src/Support/BondRuntimeAssignmentRepository.php'),
    'BondAggregationRuntimeSnapshotService' => read_source($root . '/src/Support/BondAggregationRuntimeSnapshotService.php'),
    'BondRuntimeEligibilityService' => read_source($root . '/src/Support/BondRuntimeEligibilityService.php'),
    'TelemetryPayloadSanitizer' => read_source($root . '/src/Support/TelemetryPayloadSanitizer.php'),
    'ClientAgentController' => read_source($root . '/src/Controllers/ClientAgentController.php'),
    'NodeController' => read_source($root . '/src/Controllers/NodeController.php'),
    'SidecarAgentController' => read_source($root . '/src/Controllers/SidecarAgentController.php'),
    'AdminController' => read_source($root . '/src/Controllers/AdminController.php'),
    'aggregation_runtime_reporter.py' => read_source($repo . '/client-agent/aggregation_runtime_reporter.py'),
];

$ownedScopes = [
    'ClientDeviceSessionContext' => $sources['ClientDeviceSessionContext'],
    'AgentCapabilityReportService' => $sources['AgentCapabilityReportService'],
    'BondRuntimeAssignmentContext' => $sources['BondRuntimeAssignmentContext'],
    'BondRuntimeAssignmentRepository' => $sources['BondRuntimeAssignmentRepository'],
    'BondAggregationRuntimeSnapshotService' => $sources['BondAggregationRuntimeSnapshotService'],
    'BondRuntimeEligibilityService' => $sources['BondRuntimeEligibilityService'],
    'TelemetryPayloadSanitizer' => $sources['TelemetryPayloadSanitizer'],
    'ClientAgentController::aggregationRuntimeSnapshots' => extract_method($sources['ClientAgentController'], 'function aggregationRuntimeSnapshots(Request $request, array $params): Response'),
    'AdminController::renderBondTelemetryGateSection' => extract_method($sources['AdminController'], 'function renderBondTelemetryGateSection('),
    'aggregation_runtime_reporter.py' => $sources['aggregation_runtime_reporter.py'],
];
$ownedCombined = implode("\n", $ownedScopes);

$required = [
    'standalone_bond',
    'leigod_mode',
    'plain_tcp_mux',
];
foreach ($required as $snippet) {
    if (!str_contains($ownedCombined, $snippet)) {
        fail('missing locked contract snippet in Task 39-owned code: ' . $snippet);
    }
}

$requiredControllerHooks = [
    'ClientAgentController telemetry endpoint' => 'aggregationRuntimeSnapshots',
    'NodeController capability service' => 'AgentCapabilityReportService',
    'SidecarAgentController capability service' => 'AgentCapabilityReportService',
    'ClientAgentController sanitizer' => 'TelemetryPayloadSanitizer::stripHighChurn',
    'NodeController sanitizer' => 'TelemetryPayloadSanitizer::stripHighChurn',
    'SidecarAgentController sanitizer' => 'TelemetryPayloadSanitizer::stripHighChurn',
];
foreach ($requiredControllerHooks as $label => $snippet) {
    $source = str_starts_with($label, 'NodeController') ? $sources['NodeController'] : (str_starts_with($label, 'SidecarAgentController') ? $sources['SidecarAgentController'] : $sources['ClientAgentController']);
    if (!str_contains($source, $snippet)) {
        fail('missing controller hook: ' . $label);
    }
}

$forbiddenInOwnedScopes = [
    'mptcp_preferred_bond_' . 'fallback',
    'platform_default',
    'bond_' . 'fallback',
    'cf_' . 'mode',
    'current_' . 'mode',
    'wss_path_mux',
    'classic sidecar',
    'native MPTCP first',
    'wg-quick down',
    'systemctl disable',
    'systemctl stop',
    'ip link del',
    'DELETE FROM forwards',
];
foreach ($forbiddenInOwnedScopes as $snippet) {
    foreach ($ownedScopes as $label => $scope) {
        if (stripos($scope, $snippet) !== false) {
            fail('forbidden scope drift snippet found in ' . $label . ': ' . $snippet);
        }
    }
}

$assignmentRepo = $ownedScopes['BondRuntimeAssignmentRepository'];
foreach (['JOIN forward_combinations combo ON combo.id = f.forward_combination_id', 'combo.runtime_preference AS effective_runtime_preference'] as $snippet) {
    if (!str_contains($assignmentRepo, $snippet)) {
        fail('assignment repository missing strict combination snippet: ' . $snippet);
    }
}
foreach (['LEFT JOIN forward_combinations', 'COALESCE(', '$runtimePreference = \'single\';'] as $snippet) {
    if (str_contains($assignmentRepo, $snippet)) {
        fail('assignment repository contains fallback snippet: ' . $snippet);
    }
}
$reporter = $ownedScopes['aggregation_runtime_reporter.py'];
foreach (['accepted_snapshots', 'terminal', '_identity_key'] as $snippet) {
    if (!str_contains($reporter, $snippet)) {
        fail('reporter missing accepted/terminal disposition protocol: ' . $snippet);
    }
}
$reducer = $ownedScopes['BondAggregationRuntimeSnapshotService'];
foreach (['accepted_snapshots', 'terminal', 'forward_aggregation_runtime_window_proofs', 'non_advancing_traffic_window'] as $snippet) {
    if (!str_contains($reducer, $snippet)) {
        fail('reducer missing direction-specific disposition/proof protocol: ' . $snippet);
    }
}

$readOnlyAdmin = $ownedScopes['AdminController::renderBondTelemetryGateSection'];
foreach (['<form', 'method="post"', 'republish', 'cleanup', 'retire', 'reactivate', 'rotate', 'delete', 'runtime action'] as $snippet) {
    if (stripos($readOnlyAdmin, $snippet) !== false) {
        fail('admin telemetry gate section is not read-only: ' . $snippet);
    }
}

$baselinePathOutput = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse --git-path ' . escapeshellarg('leigod-telemetry-capability-gates-baseline.json')));
if ($baselinePathOutput === '') {
    fail('protected file baseline git path lookup failed');
}
$protectedBaseline = $baselinePathOutput;
if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $protectedBaseline) !== 1) {
    $protectedBaseline = $repo . DIRECTORY_SEPARATOR . $protectedBaseline;
}
if (!is_file($protectedBaseline)) {
    fail('protected file baseline missing');
}
$baseline = json_decode(file_get_contents($protectedBaseline) ?: '', true);
if (!is_array($baseline)) {
    fail('protected file baseline invalid');
}
$protectedPath = (string) ($baseline['protected_path'] ?? '');
if ($protectedPath !== 'docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md') {
    fail('protected path baseline mismatch');
}
$status = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' status --short -- ' . escapeshellarg($protectedPath)));
if ($status !== (string) ($baseline['protected_status'] ?? '')) {
    fail('protected Bridge Lite plan pathspec status changed');
}
foreach (['hash_file(', 'read_bytes()', 'hashlib.sha256'] as $forbiddenProtectedRead) {
    if (str_contains($ownedCombined, $forbiddenProtectedRead)) {
        fail('Task 39-owned code must not read or hash protected Bridge Lite plan contents');
    }
}

echo "ok\n";
```

- [ ] **Step 2: Run the drift guard test**

Run:

```bash
php master/tests/leigod_telemetry_contract_drift_guard_test.php
```

Expected: `ok`. If it fails on a legitimate reference inside an error message, rewrite that error message to avoid embedding the forbidden legacy mode text in Task 39 files. Stable API error codes should use `unsupported_active_family` or `unsupported_leigod_runtime_contract` rather than naming deprecated modes in new telemetry code.

- [ ] **Step 3: Commit drift guards**

Run:

```bash
git add master/tests/leigod_telemetry_contract_drift_guard_test.php
git commit -m "test(master): guard telemetry contract boundaries

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: Run Acceptance Gates and Document Non-Deployment

**Files:**
- Verify only; no product files should be modified in this task unless a prior test failure required a focused fix.

- [ ] **Step 1: Run all Task 39 PHP tests**

Run:

```bash
php master/tests/aggregation_runtime_telemetry_migration_shape_test.php
php master/tests/client_device_session_context_test.php
php master/tests/agent_capability_report_service_test.php
php master/tests/bond_runtime_assignment_repository_test.php
php master/tests/bond_aggregation_runtime_snapshot_service_test.php
php master/tests/client_aggregation_runtime_endpoint_test.php
php master/tests/telemetry_payload_sanitizer_test.php
php master/tests/telemetry_retention_service_test.php
php master/tests/bond_runtime_eligibility_display_test.php
php master/tests/leigod_telemetry_contract_drift_guard_test.php
php master/tests/node_heartbeat_republish_gate_regression_test.php
php master/tests/client_heartbeat_republish_gate_regression_test.php
php master/tests/sidecar_heartbeat_republish_gate_regression_test.php
```

Expected: every command prints `ok`.

- [ ] **Step 2: Run the Python reporter test**

Run:

```bash
python client-agent/tests/test_aggregation_runtime_reporter.py
```

Expected: `OK`.

- [ ] **Step 3: Verify protected Bridge Lite plan pathspec baseline**

Run:

```bash
python - <<'PY'
import json
import subprocess
from pathlib import Path

baseline_path = Path(subprocess.check_output(['git', 'rev-parse', '--git-path', 'leigod-telemetry-capability-gates-baseline.json'], text=True).strip())
baseline = json.loads(baseline_path.read_text(encoding='utf-8'))
protected = baseline['protected_path']
if protected != 'docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md':
    raise SystemExit(f'protected path mismatch: {protected!r}')
status = subprocess.check_output(['git', 'status', '--short', '--', protected], text=True).strip()
if status != baseline['protected_status']:
    raise SystemExit(f'protected file pathspec status changed: status={status!r}')
print('ok')
PY
```

Expected: `ok`. This command may inspect only Git path metadata for the protected path. It must not open, hash, read, execute, or otherwise inspect the protected Bridge Lite plan contents.

- [ ] **Step 4: Verify no deployment, artifacts, or production paths changed**

Run:

```bash
python - <<'PY'
import json
import subprocess
from pathlib import Path

baseline_path = Path(subprocess.check_output(['git', 'rev-parse', '--git-path', 'leigod-telemetry-capability-gates-baseline.json'], text=True).strip())
baseline = json.loads(baseline_path.read_text(encoding='utf-8'))
base_commit = baseline['base_commit']
protected = baseline['protected_path']
forbidden = [
    'artifacts/',
    'master/public/artifacts/',
    'wstunnel-main/leikwan-bond/',
]
changed = subprocess.check_output(['git', 'diff', '--name-only', f'{base_commit}..HEAD', '--', '.', f':(exclude){protected}'], text=True).splitlines()
status_lines = subprocess.check_output(['git', 'status', '--short', '--', '.', f':(exclude){protected}'], text=True).splitlines()
status_paths = []
for line in status_lines:
    path = line[3:] if len(line) >= 4 else line
    if ' -> ' in path:
        path = path.split(' -> ', 1)[1]
    status_paths.append(path)
violations = []
for source, paths in [('committed', changed), ('working-tree', status_paths)]:
    for path in paths:
        normalized = path.replace('\\', '/')
        for prefix in forbidden:
            if normalized == prefix.rstrip('/') or normalized.startswith(prefix):
                violations.append(f'{source}: {normalized}')
if violations:
    raise SystemExit('forbidden deployment/artifact/protected path changed:\n' + '\n'.join(violations))
print('ok')
PY
```

Expected: `ok`. This is a hard-failing guard over committed changes since the Task 1 baseline and the current working tree, excluding the protected Bridge Lite plan path that is checked separately by the pathspec-only Step 3 baseline. It must fail if any of these paths appear, even if the ordinary test suite passes:

```text
artifacts/
master/public/artifacts/
wstunnel-main/leikwan-bond/
```

- [ ] **Step 5: Verify no production operation was run**

Run:

```bash
python - <<'PY'
import json
import subprocess
from pathlib import Path

baseline_path = Path(subprocess.check_output(['git', 'rev-parse', '--git-path', 'leigod-telemetry-capability-gates-baseline.json'], text=True).strip())
baseline = json.loads(baseline_path.read_text(encoding='utf-8'))
protected = baseline['protected_path']
status_lines = subprocess.check_output(['git', 'status', '--short', '--', '.', f':(exclude){protected}'], text=True).splitlines()
print('\n'.join(status_lines))
print('ok')
PY
```

Expected: only intended Task 39 files are modified or committed, excluding the protected Bridge Lite path that Step 3 checks separately with a pathspec. There is no evidence of artifact publication, remote host contact, cleanup activation, systemd operation, WireGuard deletion, or production deploy.

- [ ] **Step 6: Final commit if acceptance fixes were needed**

If Task 11 required a small fix, commit it:

```bash
git add \
  master/migrations/schema.sql \
  master/migrations/20260719_add_forward_aggregation_telemetry.sql \
  master/cli/cleanup-tasks.php \
  master/src/Bootstrap.php \
  master/src/Http/Request.php \
  master/src/Support/Auth.php \
  master/src/Support/ClientDeviceSessionContext.php \
  master/src/Support/TelemetryPayloadSanitizer.php \
  master/src/Support/AgentCapabilityReportService.php \
  master/src/Support/BondRuntimeAssignmentContext.php \
  master/src/Support/BondRuntimeAssignmentRepository.php \
  master/src/Support/BondAggregationRuntimeSnapshotService.php \
  master/src/Support/BondRuntimeEligibilityService.php \
  master/src/Support/TelemetryRetentionService.php \
  master/src/Controllers/ClientAgentController.php \
  master/src/Controllers/NodeController.php \
  master/src/Controllers/SidecarAgentController.php \
  master/src/Controllers/AdminController.php \
  master/tests/aggregation_runtime_telemetry_migration_shape_test.php \
  master/tests/client_device_session_context_test.php \
  master/tests/agent_capability_report_service_test.php \
  master/tests/bond_runtime_assignment_repository_test.php \
  master/tests/bond_aggregation_runtime_snapshot_service_test.php \
  master/tests/client_aggregation_runtime_endpoint_test.php \
  master/tests/telemetry_payload_sanitizer_test.php \
  master/tests/telemetry_retention_service_test.php \
  master/tests/bond_runtime_eligibility_display_test.php \
  master/tests/leigod_telemetry_contract_drift_guard_test.php \
  client-agent/aggregation_runtime_reporter.py \
  client-agent/tests/test_aggregation_runtime_reporter.py \
  client-agent/client_agent.py \
  slave-agent/slave_agent.py \
  sidecar-agent/sidecar_agent.py
git commit -m "test(master): verify aggregation telemetry gates

Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

If no files changed in Task 11, do not create an empty commit. Never use a broad `git add master client-agent` here; the acceptance fix commit may stage only the explicit Task 39 paths listed above.

## Self-Review Checklist for the Implementer

Before handing off Task 39 implementation, confirm each statement is true:

- The only active aggregation family accepted by new telemetry code is `standalone_bond`.
- Exactly 15 seconds of effective observation age is stale; accepted snapshots require age strictly less than 15 seconds.
- A complete useful-byte window needs at least 1,048,576 unique application-payload bytes and at least two eligible distinct logical Slave legs each contributing at least 5%.
- Keepalives and process liveness never keep old useful-byte windows fresh.
- Task 39 useful-byte proof consumes the Task 37 standalone-bond snapshot contract; current legacy bond-leg reputation files alone are diagnostic and cannot prove Speed Plus aggregation.
- Client-device session credentials are issued only by existing pull/heartbeat paths and verified by the separate telemetry endpoint; the telemetry endpoint never issues a credential.
- Ordinary pull/heartbeat calls never rotate an existing telemetry credential; after local restart loses the memory-only telemetry credential, telemetry remains paused and fail-closed until a separately authorized reset or credential-rotation design exists.
- The client reporter keeps the telemetry credential only in memory, never writes it to disk, and after restart with a known device-session id but no credential it logs a warning and does not request reissue through heartbeat, pull, telemetry, or capability reports.
- The client reporter watches `self.bond_leg_snapshot_dir()` and posts independently of the 30-second pull/apply cadence.
- Slave and sidecar reports are capability/availability diagnostics only and never prove Speed Plus useful-byte aggregation.
- Missing, mismatched, stale, or malformed capability reports fail closed to `unknown`, `unavailable`, `update_required`, `unsupported_leigod_runtime_contract`, or `unsupported_active_family`; they never fallback to legacy or native MPTCP paths.
- The read-only admin section contains no form, POST method, publish, republish, cleanup, retire, reactivate, rotate, edit, or delete action.
- Telemetry row expiration can delete only telemetry rows, never host runtime resources.
- The protected Bridge Lite plan pathspec-only status matches the pre-flight baseline, and the committed/working-tree forbidden-path guard reports no protected-path change; no plan or guard opens, hashes, reads, executes, or otherwise inspects that protected file's contents.
- No production deployment, artifact publication, cleanup activation, or remote host operation occurred.

## Execution Notes

This plan intentionally produces implementation tasks and tests only. Do not execute any production activation after finishing it. Task 40 must separately plan source-of-truth artifacts, version/hash consistency, canary rollout, normalization/retirement activation, upload/download acceptance campaigns, and rollback. Task 40 also remains non-deploying unless a later genuine user-role message explicitly authorizes deployment.
