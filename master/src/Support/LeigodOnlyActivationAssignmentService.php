<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodOnlyActivationPlan.php';
require_once __DIR__ . '/LeigodOnlyRolloutLockService.php';
require_once __DIR__ . '/LeigodOnlyRuntimeContract.php';

final class LeigodOnlyActivationAssignmentService
{
    public function __construct(private object $db) {}

    public static function slotKey(int $forwardId, ?int $clientId, ?int $deviceId): string
    {
        return $forwardId . ':' . ($clientId ?? 0) . ':' . ($deviceId ?? 0) . ':current';
    }

    /** @return array<string,mixed>|null */
    public function loadCurrent(int $forwardId, ?int $clientId, ?int $deviceId, string $scope = 'global'): ?array
    {
        $slotKey = self::slotKey($forwardId, $clientId, $deviceId);
        $rows = $this->db->fetchAll(
            "SELECT a.*, cs.slot_key, cs.desired_generation AS slot_generation, cs.assignment_hash AS slot_assignment_hash, cs.desired_leg_set_hash AS slot_desired_leg_set_hash, cs.active_family AS slot_active_family FROM leigod_only_activation_assignment_current_slots cs JOIN leigod_only_activation_assignments a ON a.id = cs.assignment_id AND a.current_slot_key = cs.slot_key WHERE cs.scope = :scope AND cs.slot_key = :slot_key AND cs.forward_id = :forward_id AND COALESCE(cs.client_id, 0) = :client_id AND COALESCE(cs.client_device_session_id, 0) = :device_id AND a.forward_id = cs.forward_id AND COALESCE(a.client_id, 0) = COALESCE(cs.client_id, 0) AND COALESCE(a.client_device_session_id, 0) = COALESCE(cs.client_device_session_id, 0) AND a.superseded_at IS NULL AND a.row_expires_at > CURRENT_TIMESTAMP LIMIT 2",
            ['scope'=>$scope,'slot_key'=>$slotKey,'forward_id'=>$forwardId,'client_id'=>$clientId ?? 0,'device_id'=>$deviceId ?? 0]
        );
        if (count($rows) === 0) { return null; }
        if (count($rows) !== 1) { throw new \RuntimeException('current_assignment_not_unique slot_key=' . $slotKey); }
        return $this->validateCurrent($rows[0]);
    }

    /** @return list<array<string,mixed>> */
    public function desiredLegRows(int $forwardId, ?int $clientId, ?int $deviceId, string $scope = 'global'): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM leigod_only_activation_assignments WHERE scope = :scope AND forward_id = :forward_id AND COALESCE(client_id, 0) = :client_id AND COALESCE(client_device_session_id, 0) = :device_id AND superseded_at IS NULL ORDER BY logical_leg_key ASC",
            ['scope'=>$scope,'forward_id'=>$forwardId,'client_id'=>$clientId ?? 0,'device_id'=>$deviceId ?? 0]
        );
    }

    /** @param array<string,mixed> $request */
    public function supersedeCurrent(array $request): int
    {
        $scope=(string)($request['scope'] ?? ''); $owner=$request['owner_token'] ?? null; $fence=$request['fencing_token'] ?? null;
        LeigodOnlyRolloutLockService::requireFence($scope, is_string($owner)?$owner:null, is_string($fence)?$fence:null);
        $forwardId=(int)($request['forward_id'] ?? 0); $clientId=isset($request['client_id'])?(int)$request['client_id']:null; $deviceId=isset($request['device_id'])?(int)$request['device_id']:null;
        if ($forwardId <= 0 || !is_array($request['assignment'] ?? null)) { throw new \InvalidArgumentException('assignment_supersede_malformed'); }
        $slotKey=self::slotKey($forwardId,$clientId,$deviceId);
        return $this->db->transaction(function (object $db) use ($request,$scope,$owner,$fence,$forwardId,$clientId,$deviceId,$slotKey): int {
            $old = $db->fetchOne("SELECT * FROM leigod_only_activation_assignment_current_slots WHERE scope = :scope AND slot_key = :slot_key AND owner_token = :owner_token AND fencing_token = :fencing_token FOR UPDATE", ['scope'=>$scope,'slot_key'=>$slotKey,'owner_token'=>$owner,'fencing_token'=>$fence]);
            if ($old === null) { throw new \RuntimeException('current_slot_missing_or_stale'); }
            foreach (['expected_assignment_hash'=>'assignment_hash','expected_desired_leg_set_hash'=>'desired_leg_set_hash'] as $expectedKey=>$rowKey) {
                if (isset($request[$expectedKey]) && (string)$request[$expectedKey] !== (string)($old[$rowKey] ?? '')) { throw new \RuntimeException('current_slot_hash_mismatch'); }
            }
            $count = $db->execute("UPDATE leigod_only_activation_assignments SET current_slot_key = NULL, superseded_at = CURRENT_TIMESTAMP WHERE id = :assignment_id AND current_slot_key = :slot_key AND owner_token = :owner_token AND fencing_token = :fencing_token AND assignment_hash = :assignment_hash AND desired_leg_set_hash = :desired_leg_set_hash", ['assignment_id'=>(int)($old['assignment_id'] ?? $old['id'] ?? 0),'slot_key'=>$slotKey,'owner_token'=>$owner,'fencing_token'=>$fence,'assignment_hash'=>(string)($old['assignment_hash'] ?? ''),'desired_leg_set_hash'=>(string)($old['desired_leg_set_hash'] ?? '')]);
            LeigodOnlyRolloutLockService::requireExactlyOneCount($count, 'supersede_old_assignment');
            $assignment=$request['assignment'];
            $runtime = $assignment['runtime_contract_json'] ?? [];
            if (is_string($runtime)) { $runtime = json_decode($runtime, true); }
            if (!is_array($runtime)) { $runtime=[]; }
            LeigodOnlyRuntimeContract::assertActiveContract($runtime, 'assignment_supersede');
            $assignmentHash=(string)($assignment['assignment_hash'] ?? hash('sha256', LeigodOnlyActivationPlan::canonicalJson($assignment)));
            $legHash=(string)($assignment['desired_leg_set_hash'] ?? hash('sha256', $assignmentHash));
            $artifact=(string)($request['artifact_snapshot_hash'] ?? $old['artifact_snapshot_hash'] ?? ''); LeigodOnlyRolloutLockService::requireHash($artifact,'artifact_snapshot_hash');
            $id = $db->insert('leigod_only_activation_assignments', ['scope'=>$scope,'forward_id'=>$forwardId,'client_id'=>$clientId,'client_device_session_id'=>$deviceId,'endpoint_id'=>(int)($assignment['endpoint_id'] ?? 0),'node_id'=>(int)($assignment['node_id'] ?? 0),'desired_generation'=>(int)($assignment['desired_generation'] ?? 0),'logical_leg_key'=>(string)($assignment['logical_leg_key'] ?? ''),'current_slot_key'=>$slotKey,'assignment_state'=>'current','assignment_hash'=>$assignmentHash,'desired_leg_set_hash'=>$legHash,'active_family'=>'standalone_bond','runtime_contract_json'=>LeigodOnlyActivationPlan::canonicalJson($runtime),'row_expires_at'=>$assignment['row_expires_at'] ?? '2099-01-01 00:00:00','artifact_snapshot_hash'=>$artifact,'owner_token'=>$owner,'fencing_token'=>$fence,'write_hash'=>hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['slot_key'=>$slotKey,'assignment_hash'=>$assignmentHash,'owner_token'=>$owner,'fencing_token'=>$fence])),'assignment_json'=>LeigodOnlyActivationPlan::canonicalJson($assignment)]);
            $count = $db->execute("UPDATE leigod_only_activation_assignment_current_slots SET assignment_id = :assignment_id, desired_generation = :desired_generation, assignment_hash = :assignment_hash, desired_leg_set_hash = :desired_leg_set_hash, active_family = :active_family, artifact_snapshot_hash = :artifact_snapshot_hash, write_hash = :write_hash WHERE scope = :scope AND slot_key = :slot_key AND owner_token = :owner_token AND fencing_token = :fencing_token AND assignment_hash = :old_assignment_hash AND desired_leg_set_hash = :old_desired_leg_set_hash", ['assignment_id'=>$id,'desired_generation'=>(int)($assignment['desired_generation'] ?? 0),'assignment_hash'=>$assignmentHash,'desired_leg_set_hash'=>$legHash,'active_family'=>'standalone_bond','artifact_snapshot_hash'=>$artifact,'write_hash'=>hash('sha256',$assignmentHash . $legHash),'scope'=>$scope,'slot_key'=>$slotKey,'owner_token'=>$owner,'fencing_token'=>$fence,'old_assignment_hash'=>(string)($old['assignment_hash'] ?? ''),'old_desired_leg_set_hash'=>(string)($old['desired_leg_set_hash'] ?? '')]);
            LeigodOnlyRolloutLockService::requireExactlyOneCount($count, 'cas_update_current_slot');
            return $id;
        });
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function validateCurrent(array $row): array
    {
        foreach (['assignment_hash','desired_leg_set_hash','slot_assignment_hash','slot_desired_leg_set_hash'] as $field) { LeigodOnlyRolloutLockService::requireHash((string)($row[$field] ?? ''), $field); }
        if ((string)$row['assignment_hash'] !== (string)$row['slot_assignment_hash'] || (string)$row['desired_leg_set_hash'] !== (string)$row['slot_desired_leg_set_hash']) { throw new \RuntimeException('current_assignment_hash_mismatch'); }
        if ((string)($row['active_family'] ?? '') !== 'standalone_bond' || (string)($row['slot_active_family'] ?? '') !== 'standalone_bond') { throw new \RuntimeException('current_assignment_active_family_mismatch active_family'); }
        if ((int)($row['desired_generation'] ?? -1) !== (int)($row['slot_generation'] ?? -2)) { throw new \RuntimeException('current_assignment_generation_mismatch'); }
        $runtime = $row['runtime_contract_json'] ?? [];
        if (is_string($runtime)) { $runtime = json_decode($runtime, true); }
        if (!is_array($runtime)) { throw new \RuntimeException('current_assignment_runtime_contract_missing'); }
        LeigodOnlyRuntimeContract::assertActiveContract($runtime, 'current_assignment');
        return $row;
    }
}
