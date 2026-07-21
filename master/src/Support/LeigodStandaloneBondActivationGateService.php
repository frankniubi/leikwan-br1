<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodActivationGateContext.php';
require_once __DIR__ . '/LeigodOnlyActivationAssignmentService.php';
require_once __DIR__ . '/LeigodOnlyRuntimeContract.php';
require_once __DIR__ . '/LeigodOnlyRolloutLockService.php';
require_once __DIR__ . '/StandaloneBondLegPolicy.php';

final class LeigodStandaloneBondActivationGateService
{
    public function __construct(
        private object $db,
        private object $assignmentService,
        private ?object $artifactGate = null,
        private ?object $lockService = null,
        private ?int $nowUnixSeconds = null
    ) {}

    /** @return array<string,mixed> */
    public function gate(LeigodActivationGateContext $context): array
    {
        $now = $this->now();
        $blockers = [];
        $assignment = $this->assignmentService->loadCurrent($context->forward_id, $context->client_id, $context->client_device_session_id, $context->scope);
        if (!is_array($assignment)) {
            $assignment = [];
            $blockers[] = 'assignment_current_missing';
        }
        $legRows = $this->assignmentService->desiredLegRows($context->forward_id, $context->client_id, $context->client_device_session_id, $context->scope);

        $assignmentState = $this->assertMatchesContext($context, $assignment, $legRows, $blockers);
        $runtimeState = $this->assertRuntimeContract($assignment, $blockers);
        $policy = StandaloneBondLegPolicy::classify($context->forward_id, $context->desired_generation, $legRows);
        $computedLegHash = StandaloneBondLegPolicy::canonicalLegSetHash($context->forward_id, $context->desired_generation, $legRows);
        $storedLegHash = (string)($assignment['desired_leg_set_hash'] ?? '');
        if ($storedLegHash !== '' && $storedLegHash !== $computedLegHash) {
            $blockers[] = 'desired_leg_set_hash_mismatch';
        }
        if ($context->desired_leg_set_hash !== null && $context->desired_leg_set_hash !== $computedLegHash) {
            $blockers[] = 'desired_leg_set_hash_mismatch';
        }

        $this->validateSettings($context, $blockers);
        $this->validateHeldFence($context, $blockers);
        $lifecycle = $this->loadLifecycleSnapshot($context, $computedLegHash, $now);
        if ($lifecycle['row'] === null) {
            $blockers[] = $lifecycle['stale'] ? 'lifecycle_snapshot_stale' : 'lifecycle_snapshot_missing';
        }
        $capability = $this->loadClientCapability($context, $now);
        if ($capability['row'] === null) {
            $blockers[] = 'fresh_client_capability_missing';
        } elseif (!$this->artifactHashesMatch($capability['row'], $context->expected_artifact_pins)) {
            $blockers[] = 'capability_reported_hash_mismatch';
        }

        $artifactState = $this->runArtifactGate($context, $assignment, $computedLegHash, $capability['row']);
        if (!($artifactState['ok'] ?? false)) {
            $blockers[] = 'artifact_gate_failed';
        }

        $rejections = $this->loadSnapshotRejections($context, $now);
        foreach ($rejections as $row) {
            $code = (string)($row['rejection_code'] ?? $row['code'] ?? 'unknown');
            if (in_array($code, ['non_monotonic_runtime_sequence','payload_too_large'], true)) {
                $blockers[] = 'snapshot_rejection:' . $code;
            }
        }

        $uploadState = ['required'=>false,'ok'=>true,'row'=>null];
        $downloadState = ['required'=>false,'ok'=>true,'row'=>null];
        $outcome = (string)($policy['outcome'] ?? '');
        if ($outcome === 'speed_plus_eligible') {
            $uploadState = $this->proofGate($context, 'upload', (float)($context->settings['upload_min_mbps'] ?? 0), $now);
            $downloadState = $this->proofGate($context, 'download', (float)($context->settings['download_min_mbps'] ?? 0), $now);
            if (!$uploadState['ok']) { $blockers[] = 'upload_gate_failed'; }
            if (!$downloadState['ok']) { $blockers[] = 'download_gate_failed'; }
        } elseif ($outcome === 'single_leg_only') {
            if (!$this->singleLegEvidenceOk($context, $lifecycle['row'], $computedLegHash)) {
                $blockers[] = 'single_leg_evidence_invalid';
            }
        } else {
            $blockers[] = 'leg_policy:' . $outcome;
        }

        $blockers = $this->unique($blockers);
        return [
            'ok'=>count($blockers) === 0,
            'promotion_ready'=>count($blockers) === 0,
            'blockers'=>$blockers,
            'policy_state'=>$policy + ['desired_leg_set_hash'=>$computedLegHash],
            'upload_gate_state'=>$uploadState,
            'download_gate_state'=>$downloadState,
            'lifecycle_state'=>['ok'=>$lifecycle['row'] !== null,'stale'=>$lifecycle['stale'],'row'=>$lifecycle['row']],
            'capability_state'=>['ok'=>$capability['row'] !== null,'row'=>$capability['row']],
            'artifact_gate'=>$artifactState,
            'assignment_state'=>$assignmentState,
            'runtime_state'=>$runtimeState,
            'rejection_rows'=>$rejections,
        ];
    }

    private function now(): int { return $this->nowUnixSeconds ?? time(); }

    /** @param array<string,mixed> $assignment @param list<array<string,mixed>> $legRows @param list<string> $blockers */
    private function assertMatchesContext(LeigodActivationGateContext $context, array $assignment, array $legRows, array &$blockers): array
    {
        $ok = true;
        foreach ([
            'id'=>$context->assignment_id,
            'forward_id'=>$context->forward_id,
            'desired_generation'=>$context->desired_generation,
            'assignment_hash'=>$context->assignment_hash,
        ] as $field=>$expected) {
            if (array_key_exists($field, $assignment) && (string)$assignment[$field] !== (string)$expected) { $ok = false; }
        }
        if (array_key_exists('client_id', $assignment) && (int)($assignment['client_id'] ?? 0) !== (int)($context->client_id ?? 0)) { $ok = false; }
        if (array_key_exists('client_device_session_id', $assignment) && (int)($assignment['client_device_session_id'] ?? 0) !== (int)($context->client_device_session_id ?? 0)) { $ok = false; }
        $family = (string)($assignment['platform_family'] ?? $assignment['active_family'] ?? $assignment['slot_active_family'] ?? $context->platform_family);
        if ($family !== 'standalone_bond' && $family !== $context->platform_family) { $ok = false; }
        if ($context->runtime_row !== null) {
            $durable = $this->runtimeFromAssignment($assignment);
            if ($this->canonical($context->runtime_row) !== $this->canonical($durable)) { $ok = false; }
        }
        if ($context->leg_rows !== null) {
            $hintHash = StandaloneBondLegPolicy::canonicalLegSetHash($context->forward_id, $context->desired_generation, $context->leg_rows);
            $durableHash = StandaloneBondLegPolicy::canonicalLegSetHash($context->forward_id, $context->desired_generation, $legRows);
            if ($hintHash !== $durableHash) { $ok = false; }
        }
        if (!$ok) { $blockers[] = 'assignment_context_mismatch'; }
        return ['ok'=>$ok,'row'=>$assignment];
    }

    /** @param array<string,mixed> $assignment @param list<string> $blockers */
    private function assertRuntimeContract(array $assignment, array &$blockers): array
    {
        try {
            LeigodOnlyRuntimeContract::assertActiveContract($this->runtimeFromAssignment($assignment), 'activation_gate');
            return ['ok'=>true];
        } catch (\Throwable $e) {
            $blockers[] = 'unsupported_runtime_contract';
            return ['ok'=>false,'error'=>$e->getMessage()];
        }
    }

    /** @param array<string,mixed> $assignment @return array<string,mixed> */
    private function runtimeFromAssignment(array $assignment): array
    {
        $runtime = $assignment['runtime_contract_json'] ?? $assignment['runtime_row'] ?? [];
        if (is_string($runtime)) { $runtime = json_decode($runtime, true); }
        return is_array($runtime) ? $runtime : [];
    }

    /** @param list<string> $blockers */
    private function validateSettings(LeigodActivationGateContext $context, array &$blockers): void
    {
        if (!(bool)($context->flags['activation_enabled'] ?? false)) { $blockers[] = 'activation_flag_disabled'; }
        foreach ([
            'forward'=>['allowlisted_forward_ids',$context->forward_id],
            'client'=>['allowlisted_client_ids',$context->client_id],
            'device'=>['allowlisted_device_session_ids',$context->client_device_session_id],
        ] as $name=>$pair) {
            $list = $context->settings[$pair[0]] ?? [];
            if (is_array($list) && $list !== [] && !in_array((int)$pair[1], array_map('intval', $list), true)) {
                $blockers[] = 'not_allowlisted:' . $name;
            }
        }
    }

    /** @param list<string> $blockers */
    private function validateHeldFence(LeigodActivationGateContext $context, array &$blockers): void
    {
        if ($context->write_mode === 'read_only') { return; }
        if ($this->lockService === null || !method_exists($this->lockService, 'requireHeld')) { $blockers[] = 'rollout_lock_not_held'; return; }
        try {
            $this->lockService->requireHeld($context->scope, 'leigod-only-activation:' . $context->forward_id, (string)$context->owner_token, (string)$context->fencing_token);
        } catch (\Throwable) {
            $blockers[] = 'rollout_lock_not_held';
        }
    }

    /** @return array{row:?array<string,mixed>,stale:bool} */
    private function loadLifecycleSnapshot(LeigodActivationGateContext $context, string $legHash, int $now): array
    {
        $sql = "SELECT * FROM leigod_only_lifecycle_snapshots WHERE scope = :scope AND forward_id = :forward_id AND client_id = :client_id AND client_device_session_id = :client_device_session_id AND desired_generation = :desired_generation AND assignment_hash = :assignment_hash AND desired_leg_set_hash = :desired_leg_set_hash AND platform_family = :platform_family AND snapshot_state = 'accepted' AND created_at_unix > :fresh_after ORDER BY created_at_unix DESC LIMIT 1";
        $params = $this->params($context) + ['desired_leg_set_hash'=>$legHash,'fresh_after'=>$now - 15];
        $rows = $this->db->fetchAll($sql, $params);
        if (($rows[0] ?? null) !== null && $this->snapshotMatches($rows[0], $context, $legHash) && (int)($rows[0]['created_at_unix'] ?? 0) > $now - 15) {
            return ['row'=>$rows[0], 'stale'=>false];
        }
        $staleRows = $this->db->fetchAll("SELECT * FROM leigod_only_lifecycle_snapshots WHERE scope = :scope AND forward_id = :forward_id AND client_id = :client_id AND client_device_session_id = :client_device_session_id AND desired_generation = :desired_generation AND assignment_hash = :assignment_hash AND desired_leg_set_hash = :desired_leg_set_hash AND platform_family = :platform_family AND snapshot_state = 'accepted' ORDER BY created_at_unix DESC LIMIT 1", $this->params($context) + ['desired_leg_set_hash'=>$legHash]);
        $stale = (($staleRows[0] ?? null) !== null && $this->snapshotMatches($staleRows[0], $context, $legHash));
        return ['row'=>null,'stale'=>$stale];
    }

    private function snapshotMatches(array $row, LeigodActivationGateContext $context, string $legHash): bool
    {
        return (string)($row['scope'] ?? '') === $context->scope
            && (int)($row['forward_id'] ?? 0) === $context->forward_id
            && (int)($row['client_id'] ?? 0) === (int)($context->client_id ?? 0)
            && (int)($row['client_device_session_id'] ?? 0) === (int)($context->client_device_session_id ?? 0)
            && (int)($row['desired_generation'] ?? 0) === $context->desired_generation
            && (string)($row['assignment_hash'] ?? '') === $context->assignment_hash
            && (string)($row['desired_leg_set_hash'] ?? '') === $legHash
            && (string)($row['platform_family'] ?? '') === $context->platform_family;
    }

    /** @return array{row:?array<string,mixed>} */
    private function loadClientCapability(LeigodActivationGateContext $context, int $now): array
    {
        $sql = "SELECT * FROM leigod_only_client_capability_reports WHERE scope = :scope AND forward_id = :forward_id AND client_id = :client_id AND client_device_session_id = :client_device_session_id AND assignment_id = :assignment_id AND desired_generation = :desired_generation AND assignment_hash = :assignment_hash AND platform_family = :platform_family AND proof_source = 'client' AND report_state = 'accepted' AND created_at_unix > :fresh_after ORDER BY created_at_unix DESC LIMIT 1";
        $rows = $this->db->fetchAll($sql, $this->params($context) + ['assignment_id'=>$context->assignment_id,'fresh_after'=>$now - 15]);
        foreach ($rows as $row) {
            if ((string)($row['proof_source'] ?? '') === 'client' && (int)($row['created_at_unix'] ?? 0) > $now - 15) { return ['row'=>$row]; }
        }
        return ['row'=>null];
    }

    private function artifactHashesMatch(array $row, array $expected): bool
    {
        $raw = $row['artifact_hashes_json'] ?? $row['artifact_hashes'] ?? [];
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        if (!is_array($raw)) { return false; }
        foreach ($expected as $key=>$hash) {
            if ((string)($raw[$key] ?? '') !== (string)$hash) { return false; }
        }
        return true;
    }

    private function runArtifactGate(LeigodActivationGateContext $context, array $assignment, string $legHash, ?array $capability): array
    {
        if ($this->artifactGate === null || !method_exists($this->artifactGate, 'evaluate')) { return ['ok'=>false,'blockers'=>['artifact_gate_unavailable']]; }
        $result = $this->artifactGate->evaluate(['expected_pins'=>$context->expected_artifact_pins,'role'=>'client','platform_family'=>$context->platform_family,'bundle'=>'standalone_bond','assignment_id'=>$context->assignment_id,'assignment_hash'=>$context->assignment_hash,'desired_leg_set_hash'=>$legHash,'assignment'=>$assignment,'capability'=>$capability]);
        return is_array($result) ? $result + ['ok'=>false] : ['ok'=>false,'blockers'=>['artifact_gate_malformed']];
    }

    /** @return list<array<string,mixed>> */
    private function loadSnapshotRejections(LeigodActivationGateContext $context, int $now): array
    {
        $sql = "SELECT * FROM leigod_only_runtime_snapshot_rejections WHERE scope = :scope AND forward_id = :forward_id AND client_id = :client_id AND client_device_session_id = :client_device_session_id AND desired_generation = :desired_generation AND assignment_hash = :assignment_hash AND platform_family = :platform_family AND rejection_code IN ('non_monotonic_runtime_sequence','payload_too_large') AND created_at_unix > :fresh_after ORDER BY created_at_unix DESC";
        return $this->db->fetchAll($sql, $this->params($context) + ['fresh_after'=>$now - 15]);
    }

    private function proofGate(LeigodActivationGateContext $context, string $direction, float $minMbps, int $now): array
    {
        $sql = "SELECT * FROM leigod_only_activation_proofs WHERE scope = :scope AND forward_id = :forward_id AND client_id = :client_id AND client_device_session_id = :client_device_session_id AND assignment_id = :assignment_id AND desired_generation = :desired_generation AND assignment_hash = :assignment_hash AND platform_family = :platform_family AND direction = :direction AND proof_state = 'accepted' AND proof_source = 'client_runtime_telemetry' AND created_at_unix > :fresh_after ORDER BY created_at_unix DESC LIMIT 1";
        $rows = $this->db->fetchAll($sql, $this->params($context) + ['assignment_id'=>$context->assignment_id,'direction'=>$direction,'fresh_after'=>$now - 15]);
        foreach ($rows as $row) {
            if ((string)($row['direction'] ?? '') === $direction && (string)($row['proof_source'] ?? '') === 'client_runtime_telemetry' && (float)($row['mbps'] ?? 0) >= $minMbps && (int)($row['created_at_unix'] ?? 0) > $now - 15) {
                return ['required'=>true,'ok'=>true,'row'=>$row,'threshold_mbps'=>$minMbps];
            }
        }
        return ['required'=>true,'ok'=>false,'row'=>null,'threshold_mbps'=>$minMbps];
    }

    private function singleLegEvidenceOk(LeigodActivationGateContext $context, ?array $lifecycle, string $legHash): bool
    {
        if ($lifecycle === null) { return false; }
        $raw = $lifecycle['single_leg_evidence_json'] ?? $lifecycle['degraded_single_leg_evidence_json'] ?? null;
        if ($raw === null || $raw === '') {
            return (int)($lifecycle['observed_usable_leg_count'] ?? 1) === 1;
        }
        if (is_string($raw)) { $raw = json_decode($raw, true); }
        if (!is_array($raw)) { return false; }
        $text = strtolower(json_encode($raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        foreach (['native_mptcp','bond_fallback','cloudflare','wss','classic-sidecar','classic_sidecar','classic sidecar'] as $marker) {
            if (str_contains($text, $marker)) { return false; }
        }
        if ((int)($raw['observed_usable_leg_count'] ?? 1) !== 1) { return false; }
        if ((int)($raw['desired_generation'] ?? 0) !== $context->desired_generation) { return false; }
        if ((string)($raw['assignment_hash'] ?? '') !== $context->assignment_hash) { return false; }
        if ((string)($raw['desired_leg_set_hash'] ?? '') !== $legHash) { return false; }
        if ((string)($raw['bond_protocol_family'] ?? '') !== 'standalone_bond') { return false; }
        foreach ([['application_tcp_socket_id','previous_application_tcp_socket_id'],['standalone_bond_transport_session_id','previous_standalone_bond_transport_session_id'],['sidecar_target_tcp_connection_id','previous_sidecar_target_tcp_connection_id']] as $pair) {
            if ((string)($raw[$pair[0]] ?? '') === '' || (string)($raw[$pair[0]] ?? '') !== (string)($raw[$pair[1]] ?? '')) { return false; }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function params(LeigodActivationGateContext $context): array
    {
        return ['scope'=>$context->scope,'forward_id'=>$context->forward_id,'client_id'=>$context->client_id ?? 0,'client_device_session_id'=>$context->client_device_session_id ?? 0,'desired_generation'=>$context->desired_generation,'assignment_hash'=>$context->assignment_hash,'platform_family'=>$context->platform_family];
    }

    private function canonical(mixed $value): string
    {
        return json_encode(LeigodOnlyActivationPlan::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /** @param list<string> $items @return list<string> */
    private function unique(array $items): array
    {
        $items = array_values(array_unique(array_filter($items, static fn($v): bool => is_string($v) && $v !== '')));
        sort($items, SORT_STRING);
        return $items;
    }
}
