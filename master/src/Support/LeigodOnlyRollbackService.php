<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/LeigodOnlyActivationPlan.php';
require_once __DIR__ . '/LeigodOnlyAuthorizationGate.php';
require_once __DIR__ . '/LeigodOnlyRuntimeContract.php';

final class LeigodOnlyRollbackService
{
    /** @var callable|null */
    private $failureInjector;

    /** @param array<string,mixed> $options */
    public function __construct(private object $db, array $options = [])
    {
        $this->failureInjector = is_callable($options['failure_injector'] ?? null) ? $options['failure_injector'] : null;
    }

    /** @return array<string,mixed> */
    public function preview(int $batchId): array
    {
        $batch = $this->loadBatch($batchId);
        if ($batch === null) { throw new RuntimeException('batch_not_found'); }
        $context = $this->buildContext($batch, false);
        return [
            'batch_id' => $batchId,
            'expected_batch_hash' => (string)$batch['batch_hash'],
            'affected_forward_ids' => $context['affected_forward_ids'],
            'desired_generation' => (int)$context['previous_desired']['desired_generation'],
            'desired_state_hash' => (string)$context['previous_desired']['desired_state_hash'],
            'publish_fence_token' => $context['publish_fence_token'],
            'artifact_pointer_hash' => (string)$context['previous_artifact']['artifact_snapshot_hash'],
            'post_activation_desired_generation' => (int)$batch['desired_generation'],
            'post_activation_desired_state_hash' => (string)$batch['desired_state_hash'],
            'post_activation_artifact_snapshot_hash' => (string)$batch['artifact_snapshot_hash'],
            'previous_desired_state_snapshot' => $context['previous_desired'],
            'previous_artifact_pointer_snapshot' => $context['previous_artifact'],
            'previous_current_slot_snapshot' => $context['previous_current'],
            'restore_summary' => $this->restoreSummary($context),
        ];
    }

    /** @return array<string,mixed> */
    public function apply(int $batchId, string $confirmBatchHash, string $ownerToken, string $fencingToken): array
    {
        $batch = $this->loadBatch($batchId);
        if ($batch === null) { throw new RuntimeException('batch_not_found'); }
        if ($confirmBatchHash !== (string)$batch['batch_hash']) { throw new RuntimeException('confirm_batch_hash_mismatch'); }
        $this->requireHash($ownerToken, 'owner_token');
        $this->requireHash($fencingToken, 'fencing_token');
        if ($ownerToken !== (string)$batch['owner_token'] || $fencingToken !== (string)$batch['fencing_token']) { throw new RuntimeException('rollout_lock_or_fence_mismatch'); }
        $context = $this->buildContext($batch, true);
        $this->assertHeldFence($batchId, $context, $ownerToken, $fencingToken);
        $this->assertRestoreTargetsSupersedable($context, $ownerToken, $fencingToken);
        $this->assertAffectedAllowlist($context);
        $this->assertLiveHashes($context, $ownerToken, $fencingToken);

        try {
            $this->begin();
            $this->supersedeCurrentSlots($context, $ownerToken, $fencingToken);
            $this->inject('after_supersede_current_slots');
            $this->restoreCurrentSlots($context, $ownerToken, $fencingToken);
            $this->inject('after_restore_current_slots');
            $desiredWriteHash = $this->restoreDesiredState($context, $ownerToken, $fencingToken);
            $this->inject('after_restore_desired_state');
            $artifactRestoreHash = $this->restoreArtifactPointers($context, $ownerToken, $fencingToken);
            $this->inject('after_restore_artifact_pointer');
            $jobHashes = $this->enqueuePublishJobs($context, $ownerToken, $fencingToken);
            $this->inject('after_enqueue_publish');
            $auditHash = $this->persistAuditEvent($batchId, $context, $ownerToken, $fencingToken, $desiredWriteHash, $artifactRestoreHash, $jobHashes);
            $this->inject('after_audit_event');
            $this->commit();
        } catch (Throwable $e) {
            $this->rollback();
            throw $e;
        }

        return ['ok' => true, 'blockers' => [], 'status' => 'rolled_back', 'batch_id' => $batchId, 'restored_forward_ids' => $context['affected_forward_ids'], 'desired_generation' => (int)$context['previous_desired']['desired_generation'], 'desired_state_hash' => (string)$context['previous_desired']['desired_state_hash'], 'artifact_pointer_restore_hash' => $artifactRestoreHash, 'audit_event_hash' => $auditHash, 'publish_job_hashes' => $jobHashes];
    }

    /** @return array<string,mixed>|null */
    private function loadBatch(int $batchId): ?array { return $this->one('leigod_only_activation_batches', static fn(array $r): bool => (int)$r['id'] === $batchId); }

    /** @param array<string,mixed> $batch @return array<string,mixed> */
    private function buildContext(array $batch, bool $strict): array
    {
        LeigodOnlyAuthorizationGate::assertNoOutwardAction(['operation' => 'rollback_local_db_only']);
        $batchJson = $this->decodeJson((string)$batch['batch_json'], 'batch_json_malformed');
        $affected = $this->intList($this->decodeJson((string)$batch['affected_forward_ids_json'], 'affected_forward_ids_malformed'));
        $post = is_array($batchJson['post_activation'] ?? null) ? $batchJson['post_activation'] : [];
        $previousDesired = $this->snapshot($batchJson['previous_desired_state_snapshot'] ?? null, 'previous_desired_state_snapshot', $strict);
        $previousArtifact = $this->snapshot($batchJson['previous_artifact_pointer_snapshot'] ?? null, 'artifact_pointer_snapshot', $strict);
        $previousCurrent = $this->snapshot($batchJson['previous_current_slot_snapshot'] ?? null, 'previous_current_slot_snapshot', $strict);
        $publishFence = (string)($previousDesired['publish_fence_token'] ?? $batchJson['publish_fence_token'] ?? '');
        $this->requireHash($publishFence, 'publish_fence_token');
        return ['batch' => $batch, 'batch_json' => $batchJson, 'scope' => (string)$batch['scope'], 'affected_forward_ids' => $affected, 'post_affected_forward_ids' => $this->intList($post['affected_forward_ids'] ?? []), 'expected_post_desired_generation' => (int)($post['desired_generation'] ?? $batch['desired_generation']), 'expected_post_desired_state_hash' => (string)($post['desired_state_hash'] ?? $batch['desired_state_hash']), 'expected_post_artifact_snapshot_hash' => (string)($post['artifact_snapshot_hash'] ?? $batch['artifact_snapshot_hash']), 'previous_desired' => $previousDesired, 'previous_artifact' => $previousArtifact, 'previous_current' => $previousCurrent, 'publish_fence_token' => $publishFence];
    }

    /** @return array<string,mixed> */
    private function snapshot(mixed $value, string $name, bool $strict): array
    {
        if (!is_array($value)) { throw new RuntimeException($name . '_missing'); }
        if ($strict) {
            if (($value['current_only'] ?? false) === true) { throw new RuntimeException($name . '_current_only'); }
            if (!array_key_exists('snapshot_immutable', $value)) { throw new RuntimeException($name . '_snapshot_immutable_missing'); }
            if ((int)$value['snapshot_immutable'] !== 1) { throw new RuntimeException($name . '_not_immutable'); }
            $stored = (string)($value['snapshot_hash'] ?? ''); $this->requireHash($stored, $name . '_hash');
            $copy = $value; unset($copy['snapshot_hash']);
            if (hash('sha256', LeigodOnlyActivationPlan::canonicalJson($copy)) !== $stored) { throw new RuntimeException($name . '_hash_mismatch'); }
        }
        return $value;
    }

    /** @param array<string,mixed> $context @return list<array<string,mixed>> */
    private function restoreSummary(array $context): array
    {
        $desiredByForward = [];
        foreach (($context['previous_desired']['rows'] ?? []) as $row) { if (is_array($row)) { $desiredByForward[(int)$row['forward_id']] = $row; } }
        $summary = [];
        foreach ($context['affected_forward_ids'] as $fid) { $summary[] = ['forward_id' => $fid, 'restore_desired_generation' => (int)($desiredByForward[$fid]['desired_generation'] ?? $context['previous_desired']['desired_generation'] ?? 0), 'restore_desired_state_hash' => (string)($desiredByForward[$fid]['desired_state_hash'] ?? $context['previous_desired']['desired_state_hash'] ?? ''), 'restore_artifact_snapshot_hash' => (string)($context['previous_artifact']['artifact_snapshot_hash'] ?? '')]; }
        return $summary;
    }

    private function assertHeldFence(int $batchId, array $context, string $ownerToken, string $fencingToken): void
    {
        $count = $this->count('leigod_only_rollout_locks', fn(array $r): bool => (string)$r['scope'] === $context['scope'] && (string)$r['lock_key'] === 'activation-batch:' . $batchId && (string)$r['lock_state'] === 'held' && (string)$r['owner_token'] === $ownerToken && (string)$r['fencing_token'] === $fencingToken && (string)$r['artifact_snapshot_hash'] === $context['expected_post_artifact_snapshot_hash']);
        if ($count !== 1) { throw new RuntimeException('rollout_lock_or_fence_mismatch'); }
    }

    private function assertRestoreTargetsSupersedable(array $context, string $ownerToken, string $fencingToken): void
    {
        foreach (($context['previous_current']['rows'] ?? []) as $row) {
            if (!is_array($row)) { throw new RuntimeException('previous_current_slot_snapshot_row_malformed'); }
            $slotKey = (string)($row['slot_key'] ?? ''); if ($slotKey === '') { throw new RuntimeException('previous_current_slot_snapshot_slot_key_missing'); }
            $fid = (int)$row['forward_id'];
            $clientId = $this->nullableInt($row, 'client_id');
            $deviceId = $this->nullableInt($row, 'client_device_session_id');
            if ($slotKey !== $this->assignmentCurrentSlotKey($fid, $clientId, $deviceId)) { throw new RuntimeException('previous_current_slot_snapshot_slot_key_mismatch'); }
            $foreign = $this->count('leigod_only_activation_assignment_current_slots', fn(array $r): bool => (string)$r['slot_key'] === $slotKey && !((string)$r['scope'] === $context['scope'] && (int)$r['forward_id'] === $fid && $this->sameNullableInt($r['client_id'] ?? null, $clientId) && $this->sameNullableInt($r['client_device_session_id'] ?? null, $deviceId) && (string)$r['owner_token'] === $ownerToken && (string)$r['fencing_token'] === $fencingToken && (string)$r['artifact_snapshot_hash'] === $context['expected_post_artifact_snapshot_hash']));
            if ($foreign !== 0) { throw new RuntimeException('current_slot_restore_target_not_supersedable'); }
            $count = $this->count('leigod_only_activation_assignment_current_slots', fn(array $r): bool => (string)$r['scope'] === $context['scope'] && (int)$r['forward_id'] === $fid && (string)$r['slot_key'] === $slotKey && $this->sameNullableInt($r['client_id'] ?? null, $clientId) && $this->sameNullableInt($r['client_device_session_id'] ?? null, $deviceId) && (string)$r['owner_token'] === $ownerToken && (string)$r['fencing_token'] === $fencingToken && (string)$r['artifact_snapshot_hash'] === $context['expected_post_artifact_snapshot_hash']);
            if ($count > 1) { throw new RuntimeException('current_slot_exact_count_mismatch'); }
        }
    }

    private function assertLiveHashes(array $context, string $ownerToken, string $fencingToken): void
    {
        foreach (($context['previous_desired']['rows'] ?? []) as $row) {
            if (!is_array($row)) { throw new RuntimeException('previous_desired_state_snapshot_row_malformed'); }
            $fid = (int)$row['forward_id'];
            $clientId = $this->nullableInt($row, 'client_id');
            $deviceId = $this->nullableInt($row, 'client_device_session_id');
            $desktopClientSessionId = $this->nullableInt($row, 'desktop_client_session_id');
            $slotKey = $this->desiredCurrentSlotKey($fid, $clientId, $deviceId, $desktopClientSessionId, $row['current_slot_key'] ?? null);
            $desired = $this->count('leigod_only_forward_desired_state_fences', fn(array $r): bool => (string)$r['scope'] === $context['scope'] && (int)$r['forward_id'] === $fid && $this->sameNullableInt($r['client_id'] ?? null, $clientId) && $this->sameNullableInt($r['client_device_session_id'] ?? null, $deviceId) && $this->sameNullableInt($r['desktop_client_session_id'] ?? null, $desktopClientSessionId) && (string)$r['owner_token'] === $ownerToken && (string)$r['fencing_token'] === $fencingToken && (int)$r['desired_generation'] === $context['expected_post_desired_generation'] && (string)$r['desired_state_hash'] === $context['expected_post_desired_state_hash'] && (string)$r['artifact_snapshot_hash'] === $context['expected_post_artifact_snapshot_hash'] && (string)($r['current_slot_key'] ?? '') === $slotKey);
            if ($desired !== 1) { throw new RuntimeException('desired_state_live_hash_drift'); }
        }
        foreach (($context['previous_artifact']['states'] ?? []) as $row) {
            if (!is_array($row)) { throw new RuntimeException('previous_artifact_pointer_snapshot_row_malformed'); }
            $fid = (int)$row['forward_id'];
            $clientId = $this->nullableInt($row, 'client_id');
            $deviceId = $this->nullableInt($row, 'client_device_session_id');
            $desktopClientSessionId = $this->nullableInt($row, 'desktop_client_session_id');
            $slotKey = $this->artifactPointerCurrentSlotKey($fid, $clientId, $deviceId, $desktopClientSessionId, $row['current_slot_key'] ?? null);
            $artifact = $this->count('leigod_only_artifact_pointer_states', fn(array $r): bool => (string)$r['scope'] === $context['scope'] && (int)$r['forward_id'] === $fid && $this->sameNullableInt($r['client_id'] ?? null, $clientId) && $this->sameNullableInt($r['client_device_session_id'] ?? null, $deviceId) && $this->sameNullableInt($r['desktop_client_session_id'] ?? null, $desktopClientSessionId) && (string)$r['owner_token'] === $ownerToken && (string)$r['fencing_token'] === $fencingToken && (int)$r['desired_generation'] === $context['expected_post_desired_generation'] && (string)$r['desired_state_hash'] === $context['expected_post_desired_state_hash'] && (string)$r['artifact_snapshot_hash'] === $context['expected_post_artifact_snapshot_hash'] && (string)($r['current_slot_key'] ?? '') === $slotKey);
            if ($artifact !== 1) { throw new RuntimeException('artifact_pointer_live_hash_drift'); }
        }
    }

    private function assertAffectedAllowlist(array $context): void
    {
        $affected = array_values(array_unique(array_map('intval', $context['affected_forward_ids'])));
        sort($affected);
        $postAffected = array_values(array_unique(array_map('intval', $context['post_affected_forward_ids'])));
        sort($postAffected);
        if ($postAffected !== [] && $affected !== $postAffected) { throw new RuntimeException('affected_forward_allowlist_mismatch'); }

        $currentIds = [];
        foreach ($context['previous_current']['rows'] ?? [] as $row) {
            if (is_array($row)) {
                $currentIds[] = (int) $row['forward_id'];
            }
        }
        $currentIds = array_values(array_unique($currentIds));
        sort($currentIds);
        if ($currentIds !== $affected) { throw new RuntimeException('affected_forward_allowlist_mismatch'); }

        $desiredTuples = $this->identityTuples($context['previous_desired']['rows'] ?? []);
        $artifactTuples = $this->identityTuples($context['previous_artifact']['states'] ?? []);
        if ($desiredTuples === [] || $artifactTuples === []) {
            throw new RuntimeException('affected_forward_allowlist_mismatch');
        }
        $desiredKeys = array_keys($desiredTuples);
        $artifactKeys = array_keys($artifactTuples);
        sort($desiredKeys);
        sort($artifactKeys);
        if ($desiredKeys !== $artifactKeys) {
            throw new RuntimeException('affected_forward_allowlist_mismatch');
        }
        $currentAssignmentKeys = $this->assignmentIdentityKeys($context['previous_current']['rows'] ?? []);
        $desiredAssignmentKeys = $this->assignmentIdentityKeys($context['previous_desired']['rows'] ?? []);
        if ($currentAssignmentKeys !== $desiredAssignmentKeys) {
            throw new RuntimeException('affected_forward_allowlist_mismatch');
        }
        $tupleForwardIds = array_values(array_unique(array_map(static fn(array $tuple): int => (int) $tuple['forward_id'], array_values($desiredTuples))));
        sort($tupleForwardIds);
        if ($tupleForwardIds !== $affected) {
            throw new RuntimeException('affected_forward_allowlist_mismatch');
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string, array{forward_id:int, client_id:?int, client_device_session_id:?int, desktop_client_session_id:?int}>
     */
    private function identityTuples(array $rows): array
    {
        $tuples = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tuple = [
                'forward_id' => (int) ($row['forward_id'] ?? 0),
                'client_id' => $this->nullableInt($row, 'client_id'),
                'client_device_session_id' => $this->nullableInt($row, 'client_device_session_id'),
                'desktop_client_session_id' => $this->nullableInt($row, 'desktop_client_session_id'),
            ];
            $key = $this->identityTupleKey($tuple['forward_id'], $tuple['client_id'], $tuple['client_device_session_id'], $tuple['desktop_client_session_id']);
            if (isset($tuples[$key])) {
                throw new RuntimeException('duplicate_identity_tuple');
            }
            $tuples[$key] = $tuple;
        }
        return $tuples;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function assignmentIdentityKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $keys[] = (int) ($row['forward_id'] ?? 0) . ':' . ($this->nullableInt($row, 'client_id') ?? 0) . ':' . ($this->nullableInt($row, 'client_device_session_id') ?? 0);
        }
        $keys = array_values(array_unique($keys));
        sort($keys);
        return $keys;
    }

    private function identityTupleKey(int $forwardId, ?int $clientId, ?int $deviceId, ?int $desktopClientSessionId): string
    {
        return $forwardId . ':' . ($clientId ?? 0) . ':' . ($deviceId ?? 0) . ':' . ($desktopClientSessionId ?? 0);
    }

    private function supersedeCurrentSlots(array $context, string $ownerToken, string $fencingToken): void
    {
        foreach ($context['previous_current']['rows'] as $row) {
            if (!is_array($row)) { throw new RuntimeException('previous_current_slot_snapshot_row_malformed'); }
            $fid = (int)$row['forward_id'];
            $clientId = $this->nullableInt($row, 'client_id');
            $deviceId = $this->nullableInt($row, 'client_device_session_id');
            $slotKey = (string)$row['slot_key'];
            $deleted = $this->delete('leigod_only_activation_assignment_current_slots', fn(array $r): bool => (string)$r['scope'] === $context['scope'] && (int)$r['forward_id'] === $fid && (string)$r['slot_key'] === $slotKey && $this->sameNullableInt($r['client_id'] ?? null, $clientId) && $this->sameNullableInt($r['client_device_session_id'] ?? null, $deviceId) && (string)$r['owner_token'] === $ownerToken && (string)$r['fencing_token'] === $fencingToken && (string)$r['artifact_snapshot_hash'] === $context['expected_post_artifact_snapshot_hash']);
            if ($deleted > 1) { throw new RuntimeException('current_slot_exact_count_mismatch'); }
            $updated = $this->update('leigod_only_activation_assignments', fn(array $r): bool => (string)$r['scope'] === $context['scope'] && (int)$r['forward_id'] === $fid && (string)($r['current_slot_key'] ?? '') === $slotKey && $this->sameNullableInt($r['client_id'] ?? null, $clientId) && $this->sameNullableInt($r['client_device_session_id'] ?? null, $deviceId) && (string)$r['owner_token'] === $ownerToken && (string)$r['fencing_token'] === $fencingToken && (string)$r['artifact_snapshot_hash'] === $context['expected_post_artifact_snapshot_hash'], static function(array $r): array { $r['current_slot_key'] = null; $r['superseded_at'] = date('Y-m-d H:i:s'); return $r; });
            if ($updated !== 1) { throw new RuntimeException('current_assignment_exact_count_mismatch'); }
        }
    }

    private function restoreCurrentSlots(array $context, string $ownerToken, string $fencingToken): void
    {
        foreach ($context['previous_current']['rows'] as $row) {
            $assignmentHash = (string)($row['assignment_hash'] ?? '');
            $this->requireHash($assignmentHash, 'assignment_hash');
            $assignmentJson = $row['assignment_json'] ?? [];
            if (is_string($assignmentJson)) { $assignmentJson = $this->decodeJson($assignmentJson, 'previous_current_slot_assignment_json_malformed'); }
            if (!is_array($assignmentJson)) { throw new RuntimeException('previous_current_slot_assignment_json_malformed'); }
            $desiredLegSetHash = (string)($row['desired_leg_set_hash'] ?? $assignmentJson['desired_leg_set_hash'] ?? '');
            $this->requireHash($desiredLegSetHash, 'desired_leg_set_hash');
            $runtime = $row['runtime_contract_json'] ?? $assignmentJson['runtime_contract_json'] ?? [];
            if (is_string($runtime)) { $runtime = $this->decodeJson($runtime, 'previous_current_slot_runtime_contract_malformed'); }
            if (!is_array($runtime)) { throw new RuntimeException('previous_current_slot_runtime_contract_malformed'); }
            LeigodOnlyRuntimeContract::assertActiveContract($runtime, 'rollback_current_slot');
            $activeFamily = (string)($row['active_family'] ?? $assignmentJson['active_family'] ?? $runtime['active_family'] ?? '');
            if ($activeFamily !== 'standalone_bond') { throw new RuntimeException('previous_current_slot_active_family_mismatch'); }
            $protocol = strtolower(trim((string)($row['protocol'] ?? $assignmentJson['protocol'] ?? 'tcp')));
            if ($protocol !== 'tcp') { throw new RuntimeException('previous_current_slot_protocol_mismatch'); }
            $clientId = array_key_exists('client_id', $row) ? ($row['client_id'] === null ? null : (int)$row['client_id']) : (array_key_exists('client_id', $assignmentJson) ? ($assignmentJson['client_id'] === null ? null : (int)$assignmentJson['client_id']) : null);
            $deviceId = array_key_exists('client_device_session_id', $row) ? ($row['client_device_session_id'] === null ? null : (int)$row['client_device_session_id']) : (array_key_exists('client_device_session_id', $assignmentJson) ? ($assignmentJson['client_device_session_id'] === null ? null : (int)$assignmentJson['client_device_session_id']) : null);
            $rowExpiresAt = (string)($row['row_expires_at'] ?? $assignmentJson['row_expires_at'] ?? '2099-01-01 00:00:00');
            $assignmentJson['assignment_hash'] = $assignmentHash;
            $assignmentJson['desired_leg_set_hash'] = $desiredLegSetHash;
            $assignmentJson['active_family'] = $activeFamily;
            $assignmentJson['protocol'] = $protocol;
            $assignmentJson['runtime_contract_json'] = $runtime;
            $assignmentJson['row_expires_at'] = $rowExpiresAt;
            $writeHash = hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['restore_current_slot' => $row, 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken]));
            $assignmentId = $this->insert('leigod_only_activation_assignments', ['scope' => $context['scope'], 'batch_id' => (int)$context['batch']['id'], 'forward_id' => (int)$row['forward_id'], 'client_id' => $clientId, 'client_device_session_id' => $deviceId, 'endpoint_id' => (int)$row['endpoint_id'], 'node_id' => (int)$row['node_id'], 'desired_generation' => (int)$row['desired_generation'], 'logical_leg_key' => (string)$row['logical_leg_key'], 'protocol' => $protocol, 'current_slot_key' => (string)$row['slot_key'], 'assignment_state' => 'current', 'assignment_hash' => $assignmentHash, 'desired_leg_set_hash' => $desiredLegSetHash, 'active_family' => $activeFamily, 'runtime_contract_json' => LeigodOnlyActivationPlan::canonicalJson($runtime), 'row_expires_at' => $rowExpiresAt, 'artifact_snapshot_hash' => (string)$context['previous_artifact']['artifact_snapshot_hash'], 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken, 'write_hash' => $writeHash, 'assignment_json' => LeigodOnlyActivationPlan::canonicalJson($assignmentJson), 'superseded_at' => null]);
            $this->insert('leigod_only_activation_assignment_current_slots', ['slot_key' => (string)$row['slot_key'], 'scope' => $context['scope'], 'assignment_id' => $assignmentId, 'forward_id' => (int)$row['forward_id'], 'client_id' => $clientId, 'client_device_session_id' => $deviceId, 'endpoint_id' => (int)$row['endpoint_id'], 'node_id' => (int)$row['node_id'], 'desired_generation' => (int)$row['desired_generation'], 'logical_leg_key' => (string)$row['logical_leg_key'], 'protocol' => $protocol, 'slot_state' => 'current', 'assignment_hash' => $assignmentHash, 'desired_leg_set_hash' => $desiredLegSetHash, 'active_family' => $activeFamily, 'artifact_snapshot_hash' => (string)$context['previous_artifact']['artifact_snapshot_hash'], 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken, 'write_hash' => $writeHash]);
        }
    }

    private function restoreDesiredState(array $context, string $ownerToken, string $fencingToken): string
    {
        $hashes = [];
        foreach ($context['previous_desired']['rows'] as $row) {
            $fid = (int)$row['forward_id'];
            $clientId = $this->nullableInt($row, 'client_id');
            $deviceId = $this->nullableInt($row, 'client_device_session_id');
            $desktopClientSessionId = $this->nullableInt($row, 'desktop_client_session_id');
            $slotKey = $this->desiredCurrentSlotKey($fid, $clientId, $deviceId, $desktopClientSessionId, $row['current_slot_key'] ?? null);
            $updated = $this->update('leigod_only_forward_desired_state_fences', fn(array $r): bool => (string)$r['scope'] === $context['scope'] && (int)$r['forward_id'] === $fid && $this->sameNullableInt($r['client_id'] ?? null, $clientId) && $this->sameNullableInt($r['client_device_session_id'] ?? null, $deviceId) && $this->sameNullableInt($r['desktop_client_session_id'] ?? null, $desktopClientSessionId) && (string)$r['owner_token'] === $ownerToken && (string)$r['fencing_token'] === $fencingToken && ($r['current_slot_key'] ?? null) !== null, static function(array $r): array { $r['current_slot_key'] = null; $r['superseded_at'] = date('Y-m-d H:i:s'); return $r; });
            if ($updated !== 1) { throw new RuntimeException('desired_state_exact_count_mismatch'); }
            $writeHash = hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['restore_desired_state' => $row, 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken])); $hashes[] = $writeHash;
            $this->insert('leigod_only_forward_desired_state_fences', ['scope' => $context['scope'], 'forward_id' => $fid, 'client_id' => $clientId, 'desktop_client_session_id' => $desktopClientSessionId, 'client_device_session_id' => $deviceId, 'desired_generation' => (int)$row['desired_generation'], 'desired_state_hash' => (string)$row['desired_state_hash'], 'artifact_snapshot_hash' => (string)$row['artifact_snapshot_hash'], 'state' => (string)($row['state'] ?? 'fenced'), 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken, 'write_hash' => $writeHash, 'desired_state_json' => LeigodOnlyActivationPlan::canonicalJson($row['desired_state_json'] ?? []), 'current_slot_key' => $slotKey, 'superseded_at' => null]);
        }
        return hash('sha256', LeigodOnlyActivationPlan::canonicalJson($hashes));
    }

    private function restoreArtifactPointers(array $context, string $ownerToken, string $fencingToken): string
    {
        $snapshot = $this->one('leigod_only_artifact_pointer_snapshots', fn(array $r): bool => (string)$r['scope'] === $context['scope'] && (string)$r['artifact_snapshot_hash'] === (string)$context['previous_artifact']['artifact_snapshot_hash']);
        if ($snapshot === null || (int)($snapshot['snapshot_immutable'] ?? 0) !== 1) { throw new RuntimeException('artifact_snapshot_missing_or_mutable'); }
        if ($this->decodeJson((string)$snapshot['pointer_manifest_json'], 'artifact_snapshot_manifest_malformed') !== ($context['previous_artifact']['pointer_manifest'] ?? null) || $this->decodeJson((string)$snapshot['artifact_hashes_json'], 'artifact_snapshot_hashes_malformed') !== ($context['previous_artifact']['artifact_hashes'] ?? null)) { throw new RuntimeException('artifact_pointer_snapshot_hash_mismatch'); }
        foreach ($context['previous_artifact']['states'] as $row) {
            $fid = (int)$row['forward_id'];
            $clientId = $this->nullableInt($row, 'client_id');
            $deviceId = $this->nullableInt($row, 'client_device_session_id');
            $desktopClientSessionId = $this->nullableInt($row, 'desktop_client_session_id');
            $slotKey = $this->artifactPointerCurrentSlotKey($fid, $clientId, $deviceId, $desktopClientSessionId, $row['current_slot_key'] ?? null);
            $updated = $this->update('leigod_only_artifact_pointer_states', fn(array $r): bool => (string)$r['scope'] === $context['scope'] && (int)$r['forward_id'] === $fid && $this->sameNullableInt($r['client_id'] ?? null, $clientId) && $this->sameNullableInt($r['client_device_session_id'] ?? null, $deviceId) && $this->sameNullableInt($r['desktop_client_session_id'] ?? null, $desktopClientSessionId) && (string)$r['owner_token'] === $ownerToken && (string)$r['fencing_token'] === $fencingToken && ($r['current_slot_key'] ?? null) !== null, static function(array $r): array { $r['current_slot_key'] = null; $r['superseded_at'] = date('Y-m-d H:i:s'); return $r; });
            if ($updated !== 1) { throw new RuntimeException('artifact_pointer_exact_count_mismatch'); }
            $writeHash = hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['restore_artifact_pointer' => $row, 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken]));
            $this->insert('leigod_only_artifact_pointer_states', ['scope' => $context['scope'], 'forward_id' => $fid, 'client_id' => $clientId, 'desktop_client_session_id' => $desktopClientSessionId, 'client_device_session_id' => $deviceId, 'desired_generation' => (int)$row['desired_generation'], 'desired_state_hash' => (string)$row['desired_state_hash'], 'artifact_snapshot_hash' => (string)$row['artifact_snapshot_hash'], 'status' => (string)($row['status'] ?? 'current'), 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken, 'write_hash' => $writeHash, 'pointer_state_json' => LeigodOnlyActivationPlan::canonicalJson($row['pointer_state_json'] ?? []), 'current_slot_key' => $slotKey, 'superseded_at' => null]);
        }
        return hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['artifact_snapshot_hash' => (string)$context['previous_artifact']['artifact_snapshot_hash'], 'forward_ids' => $context['affected_forward_ids']]));
    }

    /** @return list<string> */
    private function enqueuePublishJobs(array $context, string $ownerToken, string $fencingToken): array
    {
        $jobs = [];
        $desiredRows = [];
        foreach ($context['previous_desired']['rows'] as $row) {
            if (!is_array($row)) { throw new RuntimeException('previous_desired_state_snapshot_row_malformed'); }
            $desiredRows[(int)$row['forward_id']] = $row;
        }
        foreach ($context['affected_forward_ids'] as $fid) {
            $row = $desiredRows[$fid] ?? null;
            if ($row === null) { throw new RuntimeException('previous_desired_state_snapshot_row_missing'); }
            $clientId = $this->nullableInt($row, 'client_id');
            $deviceId = $this->nullableInt($row, 'client_device_session_id');
            $desktopClientSessionId = $this->nullableInt($row, 'desktop_client_session_id');
            $slotKey = $this->desiredCurrentSlotKey($fid, $clientId, $deviceId, $desktopClientSessionId, $row['current_slot_key'] ?? null);
            $identity = ['client_id' => $clientId, 'client_device_session_id' => $deviceId, 'desktop_client_session_id' => $desktopClientSessionId, 'current_slot_key' => $slotKey];
            $desiredGeneration = (int)($row['desired_generation'] ?? $context['previous_desired']['desired_generation']);
            $desiredHash = (string)($row['desired_state_hash'] ?? $context['previous_desired']['desired_state_hash']);
            $artifactHash = (string)($row['artifact_snapshot_hash'] ?? $context['previous_artifact']['artifact_snapshot_hash']);
            $payload = ['operation' => 'rollback_publish', 'forward_id' => $fid, 'batch_id' => (int)$context['batch']['id'], 'identity' => $identity, 'desired_generation' => $desiredGeneration, 'desired_state_hash' => $desiredHash];
            $jobHash = hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['scope' => $context['scope'], 'forward_id' => $fid, 'identity' => $identity, 'desired_generation' => $desiredGeneration, 'desired_state_hash' => $desiredHash, 'artifact_snapshot_hash' => $artifactHash, 'publish_fence_token' => $context['publish_fence_token'], 'payload' => $payload]));
            $writeHash = hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['job_hash' => $jobHash, 'scope' => $context['scope'], 'forward_id' => $fid, 'identity' => $identity, 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken, 'publish_fence_token' => $context['publish_fence_token']]));
            $this->insert('leigod_only_fenced_publish_jobs', ['scope' => $context['scope'], 'job_hash' => $jobHash, 'forward_id' => $fid, 'client_id' => $clientId, 'client_device_session_id' => $deviceId, 'desktop_client_session_id' => $desktopClientSessionId, 'current_slot_key' => $slotKey, 'desired_generation' => $desiredGeneration, 'desired_state_hash' => $desiredHash, 'artifact_snapshot_hash' => $artifactHash, 'publish_fence_token' => $context['publish_fence_token'], 'job_state' => 'pending', 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken, 'write_hash' => $writeHash, 'payload_json' => LeigodOnlyActivationPlan::canonicalJson($payload)]);
            $jobs[] = $jobHash;
        }
        return $jobs;
    }

    private function persistAuditEvent(int $batchId, array $context, string $ownerToken, string $fencingToken, string $desiredWriteHash, string $artifactRestoreHash, array $jobHashes): string
    {
        $evidenceHash = hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['batch_hash' => (string)$context['batch']['batch_hash'], 'desired_snapshot_hash' => (string)$context['previous_desired']['snapshot_hash'], 'artifact_snapshot_hash' => (string)$context['previous_artifact']['snapshot_hash'], 'current_snapshot_hash' => (string)$context['previous_current']['snapshot_hash']]));
        $event = ['batch_id' => $batchId, 'write_hash' => $desiredWriteHash, 'artifact_pointer_restore_hash' => $artifactRestoreHash, 'rollback_evidence_hash' => $evidenceHash, 'publish_job_hashes' => $jobHashes, 'affected_forward_ids' => $context['affected_forward_ids']];
        $eventHash = hash('sha256', LeigodOnlyActivationPlan::canonicalJson($event));
        $this->insert('leigod_only_activation_audit_events', ['scope' => $context['scope'], 'event_hash' => $eventHash, 'event_type' => 'leigod_only_rollback_applied', 'forward_id' => null, 'desired_generation' => (int)$context['previous_desired']['desired_generation'], 'artifact_snapshot_hash' => (string)$context['previous_artifact']['artifact_snapshot_hash'], 'owner_token' => $ownerToken, 'fencing_token' => $fencingToken, 'event_json' => LeigodOnlyActivationPlan::canonicalJson($event)]);
        return $eventHash;
    }

    /** @return array<mixed> */
    private function decodeJson(string $json, string $error): array { $decoded = json_decode($json, true); if (!is_array($decoded)) { throw new RuntimeException($error); } return $decoded; }
    /** @return list<int> */
    private function intList(mixed $value): array { if (!is_array($value)) { return []; } $out=[]; foreach ($value as $item) { $out[]=(int)$item; } $out=array_values(array_unique($out)); sort($out); return $out; }
    /** @param array<string,mixed> $row */
    private function nullableInt(array $row, string $field): ?int { return array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '' ? (int)$row[$field] : null; }
    private function sameNullableInt(mixed $left, ?int $right): bool { return ($left === null || $left === '') ? $right === null : (int)$left === ($right ?? 0); }
    private function assignmentCurrentSlotKey(int $forwardId, ?int $clientId, ?int $deviceId): string { return $forwardId . ':' . ($clientId ?? 0) . ':' . ($deviceId ?? 0) . ':current'; }
    private function desiredCurrentSlotKey(int $forwardId, ?int $clientId, ?int $deviceId, ?int $desktopClientSessionId, mixed $snapshotKey): string { $expected = $this->identityTupleKey($forwardId, $clientId, $deviceId, $desktopClientSessionId) . ':desired-current'; if ($snapshotKey === null || $snapshotKey === '') { return $expected; } if ((string)$snapshotKey !== $expected) { throw new RuntimeException('previous_desired_state_snapshot_current_slot_key_mismatch'); } return $expected; }
    private function artifactPointerCurrentSlotKey(int $forwardId, ?int $clientId, ?int $deviceId, ?int $desktopClientSessionId, mixed $snapshotKey): string { $expected = $this->identityTupleKey($forwardId, $clientId, $deviceId, $desktopClientSessionId) . ':artifact-pointer-current'; if ($snapshotKey === null || $snapshotKey === '') { return $expected; } if ((string)$snapshotKey !== $expected) { throw new RuntimeException('artifact_pointer_snapshot_current_slot_key_mismatch'); } return $expected; }
    private function requireHash(string $value, string $field): void { if ($value === '') { throw new InvalidArgumentException($field . '_required'); } if (!preg_match('/^[a-f0-9]{64}$/', $value)) { throw new InvalidArgumentException($field . '_invalid'); } }
    private function inject(string $stage): void { if ($this->failureInjector !== null) { ($this->failureInjector)($stage); } }

    /** @return array<string,mixed>|null */
    private function one(string $table, callable $predicate): ?array { $rows = $this->rows($table, $predicate); if (count($rows) === 0) { return null; } return $rows[0]; }
    /** @return list<array<string,mixed>> */
    private function rows(string $table, callable $predicate): array { if (!method_exists($this->db, 'rows')) { throw new RuntimeException('rollback_db_adapter_missing'); } return $this->db->rows($table, $predicate); }
    private function count(string $table, callable $predicate): int { return method_exists($this->db, 'countRows') ? $this->db->countRows($table, $predicate) : count($this->rows($table, $predicate)); }
    private function insert(string $table, array $row): int { if (!method_exists($this->db, 'insert')) { throw new RuntimeException('rollback_db_adapter_missing'); } return (int)$this->db->insert($table, $row); }
    private function update(string $table, callable $predicate, callable $mutator): int { if (!method_exists($this->db, 'updateRows')) { throw new RuntimeException('rollback_db_adapter_missing'); } return (int)$this->db->updateRows($table, $predicate, $mutator); }
    private function delete(string $table, callable $predicate): int { if (!method_exists($this->db, 'deleteRows')) { throw new RuntimeException('rollback_db_adapter_missing'); } return (int)$this->db->deleteRows($table, $predicate); }
    private function begin(): void { if (method_exists($this->db, 'beginTransaction')) { $this->db->beginTransaction(); } }
    private function commit(): void { if (method_exists($this->db, 'commit')) { $this->db->commit(); } }
    private function rollback(): void { if (method_exists($this->db, 'inTransaction') && $this->db->inTransaction() && method_exists($this->db, 'rollBack')) { $this->db->rollBack(); } }
}
