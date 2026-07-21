<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodOnlyActivationPlan.php';
require_once __DIR__ . '/LeigodOnlyRolloutLockService.php';
require_once __DIR__ . '/LeigodOnlyRuntimeContract.php';

final class LeigodOnlyDesiredStateFenceService
{
    public function __construct(private object $db) {}

    /** @param array<string,mixed> $request */
    public function writeDesiredState(array $request): string
    {
        $scope=(string)($request['scope'] ?? ''); $owner=$request['owner_token'] ?? null; $fence=$request['fencing_token'] ?? null;
        LeigodOnlyRolloutLockService::requireFence($scope, is_string($owner)?$owner:null, is_string($fence)?$fence:null);
        $forwardId=(int)($request['forward_id'] ?? 0); if ($forwardId<=0) throw new \InvalidArgumentException('forward_id_required');
        $generation=(int)($request['desired_generation'] ?? 0); if ($generation<=0) throw new \InvalidArgumentException('desired_generation_required');
        $desiredHash=(string)($request['desired_state_hash'] ?? ''); LeigodOnlyRolloutLockService::requireHash($desiredHash,'desired_state_hash');
        $artifact=(string)($request['artifact_snapshot_hash'] ?? ''); LeigodOnlyRolloutLockService::requireHash($artifact,'artifact_snapshot_hash');
        $runtime=$request['runtime_contract_json'] ?? []; if (is_string($runtime)) $runtime=json_decode($runtime,true); if (!is_array($runtime)) $runtime=[]; LeigodOnlyRuntimeContract::assertActiveContract($runtime, 'desired_state_fence');
        [$clientId,$deviceId,$desktopClientSessionId]=$this->normalizedIdentity($request); $slotKey=$this->currentSlotKey($forwardId,$clientId,$deviceId,$desktopClientSessionId,$request['current_slot_key'] ?? null);
        $stateJson=['existing_desired_state'=>$request['existing_desired_state'] ?? null,'runtime_contract_json'=>$runtime,'plan_hash'=>$request['plan_hash'] ?? null,'batch_hash'=>$request['batch_hash'] ?? null,'affected_forward_ids_json'=>$request['affected_forward_ids'] ?? [$forwardId]];
        $writeHash=hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['scope'=>$scope,'forward_id'=>$forwardId,'client_id'=>$clientId,'client_device_session_id'=>$deviceId,'desktop_client_session_id'=>$desktopClientSessionId,'current_slot_key'=>$slotKey,'desired_generation'=>$generation,'desired_state_hash'=>$desiredHash,'artifact_snapshot_hash'=>$artifact,'owner_token'=>$owner,'fencing_token'=>$fence,'state'=>$stateJson]));
        return $this->db->transaction(function(object $db) use($scope,$owner,$fence,$forwardId,$clientId,$deviceId,$desktopClientSessionId,$generation,$desiredHash,$artifact,$slotKey,$stateJson,$writeHash,$request): string {
            $db->fetchOne('SELECT * FROM leigod_only_forward_desired_state_fences WHERE scope = :scope AND forward_id = :forward_id AND COALESCE(client_id,0) = :client_id AND COALESCE(desktop_client_session_id,0) = :desktop_client_session_id AND COALESCE(client_device_session_id,0) = :device_id AND current_slot_key = :current_slot_key AND superseded_at IS NULL FOR UPDATE', ['scope'=>$scope,'forward_id'=>$forwardId,'client_id'=>$clientId ?? 0,'desktop_client_session_id'=>$desktopClientSessionId ?? 0,'device_id'=>$deviceId ?? 0,'current_slot_key'=>$slotKey]);
            $count=$db->execute('UPDATE leigod_only_forward_desired_state_fences SET current_slot_key = NULL, superseded_at = CURRENT_TIMESTAMP WHERE scope = :scope AND forward_id = :forward_id AND COALESCE(client_id,0) = :client_id AND COALESCE(desktop_client_session_id,0) = :desktop_client_session_id AND COALESCE(client_device_session_id,0) = :device_id AND current_slot_key = :current_slot_key AND owner_token = :owner_token AND fencing_token = :fencing_token AND superseded_at IS NULL', ['scope'=>$scope,'forward_id'=>$forwardId,'client_id'=>$clientId ?? 0,'desktop_client_session_id'=>$desktopClientSessionId ?? 0,'device_id'=>$deviceId ?? 0,'current_slot_key'=>$slotKey,'owner_token'=>$owner,'fencing_token'=>$fence]);
            if ($count !== 0 && $count !== 1) { throw new \RuntimeException('exactly_one_row_required operation=desired_state_supersede affected=' . $count); }
            $db->insert('leigod_only_forward_desired_state_fences', ['scope'=>$scope,'forward_id'=>$forwardId,'client_id'=>$clientId,'client_device_session_id'=>$deviceId,'desktop_client_session_id'=>$desktopClientSessionId,'desired_generation'=>$generation,'desired_state_hash'=>$desiredHash,'artifact_snapshot_hash'=>$artifact,'state'=>'fenced','current_slot_key'=>$slotKey,'owner_token'=>$owner,'fencing_token'=>$fence,'write_hash'=>$writeHash,'external_authorization_ref'=>$request['external_authorization_ref'] ?? null,'desired_state_json'=>LeigodOnlyActivationPlan::canonicalJson($stateJson)]);
            return $writeHash;
        });
    }

    /** @return array<string,mixed> */
    public function currentFence(array $job): array
    {
        $scope=(string)($job['scope'] ?? ''); $owner=(string)($job['owner_token'] ?? ''); $fence=(string)($job['fencing_token'] ?? ''); LeigodOnlyRolloutLockService::requireFence($scope,$owner,$fence);
        $forwardId=(int)($job['forward_id'] ?? 0); [$clientId,$deviceId,$desktopClientSessionId]=$this->normalizedIdentity($job); $slotKey=$this->currentSlotKey($forwardId,$clientId,$deviceId,$desktopClientSessionId,$job['current_slot_key'] ?? null);
        $row=$this->db->fetchOne('SELECT * FROM leigod_only_forward_desired_state_fences WHERE scope = :scope AND forward_id = :forward_id AND owner_token = :owner_token AND fencing_token = :fencing_token AND desired_generation = :desired_generation AND desired_state_hash = :desired_state_hash AND artifact_snapshot_hash = :artifact_snapshot_hash AND state = \'fenced\' AND COALESCE(client_id,0) = :client_id AND COALESCE(desktop_client_session_id,0) = :desktop_client_session_id AND COALESCE(client_device_session_id,0) = :device_id AND current_slot_key = :current_slot_key AND superseded_at IS NULL LIMIT 1', ['scope'=>$scope,'forward_id'=>$forwardId,'owner_token'=>$owner,'fencing_token'=>$fence,'desired_generation'=>(int)$job['desired_generation'],'desired_state_hash'=>(string)$job['desired_state_hash'],'artifact_snapshot_hash'=>(string)$job['artifact_snapshot_hash'],'client_id'=>$clientId ?? 0,'desktop_client_session_id'=>$desktopClientSessionId ?? 0,'device_id'=>$deviceId ?? 0,'current_slot_key'=>$slotKey]);
        if ($row === null) throw new \RuntimeException('desired_state_fence_missing');
        return $row;
    }

    /** @param array<string,mixed> $input @return array{0:?int,1:?int,2:?int} */
    private function normalizedIdentity(array $input): array
    {
        $clientId=$this->nullableInputInt($input,'client_id');
        $canonicalDeviceId=$this->nullableInputInt($input,'client_device_session_id');
        $legacyDeviceId=$this->nullableInputInt($input,'device_id');
        if ($canonicalDeviceId !== null && $legacyDeviceId !== null && $canonicalDeviceId !== $legacyDeviceId) { throw new \InvalidArgumentException('client_device_session_id_mismatch'); }
        return [$clientId,$canonicalDeviceId ?? $legacyDeviceId,$this->nullableInputInt($input,'desktop_client_session_id')];
    }

    /** @param array<string,mixed> $input */
    private function nullableInputInt(array $input, string $field): ?int
    {
        return array_key_exists($field,$input) && $input[$field] !== null && $input[$field] !== '' ? (int)$input[$field] : null;
    }

    private function currentSlotKey(int $forwardId, ?int $clientId, ?int $deviceId, ?int $desktopClientSessionId, mixed $provided = null): string
    {
        $expected=$forwardId . ':' . ($clientId ?? 0) . ':' . ($deviceId ?? 0) . ':' . ($desktopClientSessionId ?? 0) . ':desired-current';
        if ($provided === null || $provided === '') { return $expected; }
        if ((string)$provided !== $expected) { throw new \RuntimeException('desired_state_fence_slot_key_mismatch'); }
        return $expected;
    }
}
