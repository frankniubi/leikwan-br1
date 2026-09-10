<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . '/master/src/Support/LeigodOnlyActivationPlan.php';
require_once $repoRoot . '/master/src/Support/LeigodOnlyRollbackService.php';

use Leikwan\Master\Support\LeigodOnlyActivationPlan;
use Leikwan\Master\Support\LeigodOnlyRollbackService;

if (!class_exists('LeigodOnlyRollbackService', false)) {
    class_alias(LeigodOnlyRollbackService::class, 'LeigodOnlyRollbackService');
}

function h(string $c): string { return str_repeat($c, 64); }
function cj(mixed $v): string { return LeigodOnlyActivationPlan::canonicalJson($v); }
function snap_hash(array $snapshot): string { unset($snapshot['snapshot_hash']); return hash('sha256', cj($snapshot)); }
function rollback_assignment_slot_key(int $fid, ?int $clientId, ?int $deviceId): string { return $fid . ':' . ($clientId ?? 0) . ':' . ($deviceId ?? 0) . ':current'; }
function rollback_desired_slot_key(int $fid, ?int $clientId, ?int $deviceId, ?int $desktopClientSessionId): string { return $fid . ':' . ($clientId ?? 0) . ':' . ($deviceId ?? 0) . ':' . ($desktopClientSessionId ?? 0) . ':desired-current'; }
function rollback_pointer_slot_key(int $fid, ?int $clientId, ?int $deviceId, ?int $desktopClientSessionId): string { return $fid . ':' . ($clientId ?? 0) . ':' . ($deviceId ?? 0) . ':' . ($desktopClientSessionId ?? 0) . ':artifact-pointer-current'; }

final class RollbackMemoryDb
{
    /** @var array<string,list<array<string,mixed>>> */
    public array $tables = [];
    /** @var array<string,int> */
    private array $nextIds = [];
    private ?array $txSnapshot = null;

    public function __construct()
    {
        foreach (['leigod_only_activation_batches','leigod_only_rollout_locks','leigod_only_activation_assignments','leigod_only_activation_assignment_current_slots','leigod_only_forward_desired_state_fences','leigod_only_artifact_pointer_snapshots','leigod_only_artifact_pointer_states','leigod_only_fenced_publish_jobs','leigod_only_activation_audit_events'] as $table) {
            $this->tables[$table] = [];
            $this->nextIds[$table] = 1;
        }
    }

    /** @param array<string,mixed> $row */
    public function insert(string $table, array $row): int
    {
        if ($table !== 'leigod_only_activation_assignment_current_slots' && !isset($row['id'])) {
            $row['id'] = $this->nextIds[$table]++;
        } elseif (isset($row['id'])) {
            $this->nextIds[$table] = max($this->nextIds[$table], (int)$row['id'] + 1);
        }
        $this->tables[$table][] = $row;
        return (int)($row['id'] ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public function rows(string $table, ?callable $predicate = null): array
    {
        $rows = $this->tables[$table] ?? [];
        if ($predicate === null) { return array_values($rows); }
        return array_values(array_filter($rows, $predicate));
    }

    public function countRows(string $table, ?callable $predicate = null): int
    {
        return count($this->rows($table, $predicate));
    }

    public function updateRows(string $table, callable $predicate, callable $mutator): int
    {
        $count = 0;
        foreach ($this->tables[$table] as &$row) {
            if ($predicate($row)) {
                $row = $mutator($row);
                $count++;
            }
        }
        unset($row);
        return $count;
    }

    public function deleteRows(string $table, callable $predicate): int
    {
        $old = count($this->tables[$table]);
        $this->tables[$table] = array_values(array_filter($this->tables[$table], static fn (array $row): bool => !$predicate($row)));
        return $old - count($this->tables[$table]);
    }

    public function beginTransaction(): void { $this->txSnapshot = [$this->tables, $this->nextIds]; }
    public function commit(): void { $this->txSnapshot = null; }
    public function rollBack(): void { if ($this->txSnapshot !== null) { [$this->tables, $this->nextIds] = $this->txSnapshot; $this->txSnapshot = null; } }
    public function inTransaction(): bool { return $this->txSnapshot !== null; }
}

function rollback_db(): RollbackMemoryDb { return new RollbackMemoryDb(); }
function rollback_count_rows(RollbackMemoryDb $db, string $table): int { return $db->countRows($table); }

function rollback_dump_state(RollbackMemoryDb $db): string
{
    $tables = ['leigod_only_activation_assignments','leigod_only_activation_assignment_current_slots','leigod_only_forward_desired_state_fences','leigod_only_artifact_pointer_states','leigod_only_fenced_publish_jobs','leigod_only_activation_audit_events'];
    $out = [];
    foreach ($tables as $table) { $out[$table] = $db->rows($table); }
    return cj($out);
}

function rollback_identity(int $fid): array
{
    return $fid === 48 ? ['client_id' => 12, 'client_device_session_id' => 99, 'desktop_client_session_id' => 77] : ['client_id' => null, 'client_device_session_id' => null, 'desktop_client_session_id' => null];
}

function seed_rollback(RollbackMemoryDb $db, array $overrides = []): array
{
    $scope = 'global'; $owner = h('c'); $fence = h('d'); $batchHash = h('b'); $postDesired = h('a'); $postArtifact = h('e'); $publishFence = h('f'); $prevDesired = h('1'); $prevArtifact = h('2'); $affected = [47, 48];
    $prevDesiredRows = []; $currentRows = []; $slotKeys = []; $desiredSlotKeys = []; $pointerSlotKeys = []; $identities = [];
    foreach ($affected as $fid) {
        $identity = rollback_identity($fid);
        $clientId = $identity['client_id'];
        $deviceId = $identity['client_device_session_id'];
        $desktopId = $identity['desktop_client_session_id'];
        $slotKey = rollback_assignment_slot_key($fid, $clientId, $deviceId);
        $desiredSlotKey = rollback_desired_slot_key($fid, $clientId, $deviceId, $desktopId);
        $pointerSlotKey = rollback_pointer_slot_key($fid, $clientId, $deviceId, $desktopId);
        $slotKeys[$fid] = $slotKey;
        $desiredSlotKeys[$fid] = $desiredSlotKey;
        $pointerSlotKeys[$fid] = $pointerSlotKey;
        $identities[$fid] = $identity;
        $assignmentPayload = [
            'forward_id' => $fid,
            'client_id' => $clientId,
            'client_device_session_id' => $deviceId,
            'endpoint_id' => $fid + 100,
            'node_id' => $fid + 200,
            'desired_generation' => 8,
            'logical_leg_key' => 'prior:' . $fid,
            'protocol' => 'tcp',
            'active_family' => 'standalone_bond',
            'row_expires_at' => '2099-01-01 00:00:00',
            'desired_leg_set_hash' => hash('sha256', 'prior-leg-set-' . $fid),
            'runtime_contract_json' => ['backend_mode' => 'leigod_mode', 'client_mode' => 'bond', 'runtime_preference' => 'single', 'bond_ingress_mode' => 'plain_tcp_mux', 'active_family' => 'standalone_bond'],
        ];
        $assignmentHash = hash('sha256', cj($assignmentPayload));
        $currentRows[] = [
            'slot_key' => $slotKey,
            'scope' => $scope,
            'forward_id' => $fid,
            'client_id' => $clientId,
            'client_device_session_id' => $deviceId,
            'endpoint_id' => $fid + 100,
            'node_id' => $fid + 200,
            'desired_generation' => 8,
            'logical_leg_key' => 'prior:' . $fid,
            'protocol' => 'tcp',
            'assignment_hash' => $assignmentHash,
            'desired_leg_set_hash' => $assignmentPayload['desired_leg_set_hash'],
            'active_family' => 'standalone_bond',
            'artifact_snapshot_hash' => $prevArtifact,
            'runtime_contract_json' => $assignmentPayload['runtime_contract_json'],
            'row_expires_at' => $assignmentPayload['row_expires_at'],
            'assignment_json' => $assignmentPayload,
            'write_hash' => hash('sha256', 'prior-slot-' . $fid),
        ];
        $prevDesiredRows[] = ['forward_id' => $fid, 'client_id' => $clientId, 'desktop_client_session_id' => $desktopId, 'client_device_session_id' => $deviceId, 'desired_generation' => 8, 'desired_state_hash' => $prevDesired, 'artifact_snapshot_hash' => $prevArtifact, 'desired_state_json' => ['forward_id' => $fid, 'mode' => 'prior'], 'write_hash' => hash('sha256', 'prior-desired-' . $fid), 'current_slot_key' => $desiredSlotKey];
        $aid = $db->insert('leigod_only_activation_assignments', ['scope' => $scope, 'batch_id' => 7, 'forward_id' => $fid, 'client_id' => $clientId, 'client_device_session_id' => $deviceId, 'endpoint_id' => $fid + 10, 'node_id' => $fid + 20, 'desired_generation' => 9, 'logical_leg_key' => 'post:' . $fid, 'protocol' => 'tcp', 'current_slot_key' => $slotKey, 'assignment_state' => 'current', 'assignment_hash' => hash('sha256', 'post-assignment-' . $fid), 'desired_leg_set_hash' => hash('sha256', 'post-leg-set-' . $fid), 'active_family' => 'standalone_bond', 'runtime_contract_json' => cj(['backend_mode' => 'leigod_mode', 'client_mode' => 'bond', 'runtime_preference' => 'single', 'bond_ingress_mode' => 'plain_tcp_mux', 'active_family' => 'standalone_bond']), 'row_expires_at' => '2099-01-01 00:00:00', 'artifact_snapshot_hash' => $postArtifact, 'owner_token' => $owner, 'fencing_token' => $fence, 'write_hash' => hash('sha256', 'post-assignment-' . $fid), 'assignment_json' => cj(['post' => true, 'forward_id' => $fid]), 'superseded_at' => null]);
        $db->insert('leigod_only_activation_assignment_current_slots', ['slot_key' => $slotKey, 'scope' => $scope, 'assignment_id' => $aid, 'forward_id' => $fid, 'client_id' => $clientId, 'client_device_session_id' => $deviceId, 'endpoint_id' => $fid + 10, 'node_id' => $fid + 20, 'desired_generation' => 9, 'logical_leg_key' => 'post:' . $fid, 'protocol' => 'tcp', 'slot_state' => 'current', 'assignment_hash' => hash('sha256', 'post-assignment-' . $fid), 'desired_leg_set_hash' => hash('sha256', 'post-leg-set-' . $fid), 'active_family' => 'standalone_bond', 'artifact_snapshot_hash' => $postArtifact, 'owner_token' => $owner, 'fencing_token' => $fence, 'write_hash' => hash('sha256', 'post-slot-' . $fid)]);
        $db->insert('leigod_only_forward_desired_state_fences', ['scope' => $scope, 'forward_id' => $fid, 'client_id' => $clientId, 'desktop_client_session_id' => $desktopId, 'client_device_session_id' => $deviceId, 'desired_generation' => 9, 'desired_state_hash' => $postDesired, 'artifact_snapshot_hash' => $postArtifact, 'state' => 'fenced', 'owner_token' => $owner, 'fencing_token' => $fence, 'write_hash' => hash('sha256', 'post-desired-' . $fid), 'desired_state_json' => cj(['post' => true, 'forward_id' => $fid]), 'current_slot_key' => $desiredSlotKey, 'superseded_at' => null]);
        $db->insert('leigod_only_artifact_pointer_states', ['scope' => $scope, 'forward_id' => $fid, 'client_id' => $clientId, 'desktop_client_session_id' => $desktopId, 'client_device_session_id' => $deviceId, 'desired_generation' => 9, 'desired_state_hash' => $postDesired, 'artifact_snapshot_hash' => $postArtifact, 'status' => 'current', 'owner_token' => $owner, 'fencing_token' => $fence, 'write_hash' => hash('sha256', 'post-pointer-' . $fid), 'pointer_state_json' => cj(['post' => true, 'forward_id' => $fid]), 'current_slot_key' => $pointerSlotKey, 'superseded_at' => null]);
    }
    $desiredSnap = ['snapshot_immutable' => 1, 'desired_generation' => 8, 'desired_state_hash' => $prevDesired, 'publish_fence_token' => $publishFence, 'rows' => $prevDesiredRows]; $desiredSnap['snapshot_hash'] = snap_hash($desiredSnap);
    $artifactStates = array_map(static function (int $fid) use ($prevDesired, $prevArtifact, $identities, $pointerSlotKeys): array { $identity = $identities[$fid]; return ['forward_id' => $fid, 'client_id' => $identity['client_id'], 'desktop_client_session_id' => $identity['desktop_client_session_id'], 'client_device_session_id' => $identity['client_device_session_id'], 'desired_generation' => 8, 'desired_state_hash' => $prevDesired, 'artifact_snapshot_hash' => $prevArtifact, 'status' => 'current', 'pointer_state_json' => ['forward_id' => $fid, 'artifact' => 'prior'], 'write_hash' => hash('sha256', 'prior-pointer-' . $fid), 'current_slot_key' => $pointerSlotKeys[$fid]]; }, $affected);
    $artifactSnap = ['snapshot_immutable' => 1, 'artifact_snapshot_hash' => $prevArtifact, 'platform_family' => 'linux', 'pointer_manifest' => ['manifest' => 'prior'], 'artifact_hashes' => ['slave-agent' => h('3')], 'states' => $artifactStates]; $artifactSnap['snapshot_hash'] = snap_hash($artifactSnap);
    $currentSnap = ['snapshot_immutable' => 1, 'rows' => $currentRows]; $currentSnap['snapshot_hash'] = snap_hash($currentSnap);
    $batchJson = ['publish_fence_token' => $publishFence, 'post_activation' => ['desired_generation' => 9, 'desired_state_hash' => $postDesired, 'artifact_snapshot_hash' => $postArtifact, 'affected_forward_ids' => $affected], 'previous_desired_state_snapshot' => $desiredSnap, 'previous_artifact_pointer_snapshot' => $artifactSnap, 'previous_current_slot_snapshot' => $currentSnap];
    $batchJson = array_replace_recursive($batchJson, $overrides['batch_json'] ?? []);
    $db->insert('leigod_only_activation_batches', ['id' => 7, 'scope' => $scope, 'batch_hash' => $overrides['batch_hash'] ?? $batchHash, 'batch_state' => 'activated', 'affected_forward_ids_json' => $overrides['affected_forward_ids_json'] ?? cj($affected), 'desired_generation' => 9, 'desired_state_hash' => $postDesired, 'artifact_snapshot_hash' => $postArtifact, 'owner_token' => $owner, 'fencing_token' => $fence, 'write_hash' => h('9'), 'batch_json' => cj($batchJson)]);
    $db->insert('leigod_only_rollout_locks', ['scope' => $scope, 'lock_key' => 'activation-batch:7', 'forward_id' => null, 'lock_state' => 'held', 'artifact_snapshot_hash' => $postArtifact, 'owner_token' => $owner, 'fencing_token' => $fence, 'write_hash' => h('8'), 'lease_expires_at' => '2099-01-01 00:00:00']);
    $db->insert('leigod_only_artifact_pointer_snapshots', ['scope' => $scope, 'artifact_snapshot_hash' => $prevArtifact, 'snapshot_immutable' => $overrides['snapshot_immutable'] ?? 1, 'platform_family' => 'linux', 'pointer_manifest_json' => cj($artifactSnap['pointer_manifest']), 'artifact_hashes_json' => cj($artifactSnap['artifact_hashes']), 'owner_token' => $owner, 'fencing_token' => $fence, 'write_hash' => snap_hash(['pointer_manifest' => $artifactSnap['pointer_manifest'], 'artifact_hashes' => $artifactSnap['artifact_hashes']])]);
    return ['batch_id' => 7, 'batch_hash' => $batchHash, 'owner' => $owner, 'fence' => $fence, 'publish_fence' => $publishFence, 'post_desired' => $postDesired, 'post_artifact' => $postArtifact, 'prev_desired' => $prevDesired, 'prev_artifact' => $prevArtifact, 'affected' => $affected, 'batch_json' => $batchJson, 'slot_keys' => $slotKeys, 'desired_slot_keys' => $desiredSlotKeys, 'pointer_slot_keys' => $pointerSlotKeys, 'identities' => $identities];
}
