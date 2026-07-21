<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodOnlyActivationPlan.php';
require_once __DIR__ . '/LeigodOnlyRolloutLockService.php';

final class LeigodOnlyArtifactPointerService
{
    public function __construct(private object $db) {}

    /** @param array<string,mixed> $request */
    public function writePointerState(array $request): string
    {
        $scope=(string)($request['scope'] ?? ''); $owner=$request['owner_token'] ?? null; $fence=$request['fencing_token'] ?? null;
        LeigodOnlyRolloutLockService::requireFence($scope, is_string($owner)?$owner:null, is_string($fence)?$fence:null);
        $forwardId=(int)($request['forward_id'] ?? 0); if ($forwardId<=0) throw new \InvalidArgumentException('forward_id_required');
        $artifact=(string)($request['artifact_snapshot_hash'] ?? ''); if ($artifact === '') throw new \InvalidArgumentException('fencing_token_required artifact_snapshot_hash'); LeigodOnlyRolloutLockService::requireHash($artifact,'artifact_snapshot_hash');
        $desiredHash=(string)($request['desired_state_hash'] ?? ''); if ($desiredHash === '') throw new \InvalidArgumentException('desired_state_hash_required'); LeigodOnlyRolloutLockService::requireHash($desiredHash,'desired_state_hash');
        $generation=(int)($request['desired_generation'] ?? 0); if ($generation<=0) throw new \InvalidArgumentException('desired_generation_required');
        [$clientId,$deviceId,$desktopClientSessionId]=$this->normalizedIdentity($request); $slotKey=$this->currentSlotKey($forwardId,$clientId,$deviceId,$desktopClientSessionId,$request['current_slot_key'] ?? null);
        $state=['pointer_state'=>$request['pointer_state'] ?? null,'plan_hash'=>$request['plan_hash'] ?? null,'batch_hash'=>$request['batch_hash'] ?? null,'affected_forward_ids_json'=>$request['affected_forward_ids'] ?? [$forwardId]];
        $writeHash=hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['scope'=>$scope,'forward_id'=>$forwardId,'client_id'=>$clientId,'client_device_session_id'=>$deviceId,'desktop_client_session_id'=>$desktopClientSessionId,'current_slot_key'=>$slotKey,'desired_generation'=>$generation,'desired_state_hash'=>$desiredHash,'artifact_snapshot_hash'=>$artifact,'owner_token'=>$owner,'fencing_token'=>$fence,'pointer'=>$state]));
        return $this->db->transaction(function(object $db) use($scope,$owner,$fence,$forwardId,$clientId,$deviceId,$desktopClientSessionId,$generation,$desiredHash,$artifact,$slotKey,$state,$writeHash,$request): string {
            $snapshot=$db->fetchOne('SELECT artifact_snapshot_hash, snapshot_immutable FROM leigod_only_artifact_pointer_snapshots WHERE scope = :scope AND artifact_snapshot_hash = :artifact_snapshot_hash AND snapshot_immutable = 1 LIMIT 1', ['scope'=>$scope,'artifact_snapshot_hash'=>$artifact]);
            if ($snapshot === null || (int)($snapshot['snapshot_immutable'] ?? 0) !== 1) throw new \RuntimeException('artifact_snapshot_missing_or_mutable');
            $db->fetchOne('SELECT * FROM leigod_only_artifact_pointer_states WHERE scope = :scope AND forward_id = :forward_id AND COALESCE(client_id,0) = :client_id AND COALESCE(desktop_client_session_id,0) = :desktop_client_session_id AND COALESCE(client_device_session_id,0) = :device_id AND current_slot_key = :current_slot_key AND superseded_at IS NULL FOR UPDATE', ['scope'=>$scope,'forward_id'=>$forwardId,'client_id'=>$clientId ?? 0,'desktop_client_session_id'=>$desktopClientSessionId ?? 0,'device_id'=>$deviceId ?? 0,'current_slot_key'=>$slotKey]);
            $count=$db->execute('UPDATE leigod_only_artifact_pointer_states SET current_slot_key = NULL, superseded_at = CURRENT_TIMESTAMP WHERE scope = :scope AND forward_id = :forward_id AND COALESCE(client_id,0) = :client_id AND COALESCE(desktop_client_session_id,0) = :desktop_client_session_id AND COALESCE(client_device_session_id,0) = :device_id AND current_slot_key = :current_slot_key AND owner_token = :owner_token AND fencing_token = :fencing_token AND superseded_at IS NULL', ['scope'=>$scope,'forward_id'=>$forwardId,'client_id'=>$clientId ?? 0,'desktop_client_session_id'=>$desktopClientSessionId ?? 0,'device_id'=>$deviceId ?? 0,'current_slot_key'=>$slotKey,'owner_token'=>$owner,'fencing_token'=>$fence]);
            if ($count !== 0 && $count !== 1) { throw new \RuntimeException('exactly_one_row_required operation=artifact_pointer_supersede affected=' . $count); }
            $db->insert('leigod_only_artifact_pointer_states', ['scope'=>$scope,'forward_id'=>$forwardId,'client_id'=>$clientId,'client_device_session_id'=>$deviceId,'desktop_client_session_id'=>$desktopClientSessionId,'desired_generation'=>$generation,'desired_state_hash'=>$desiredHash,'artifact_snapshot_hash'=>$artifact,'status'=>'pending','current_slot_key'=>$slotKey,'owner_token'=>$owner,'fencing_token'=>$fence,'write_hash'=>$writeHash,'external_authorization_ref'=>$request['external_authorization_ref'] ?? null,'pointer_state_json'=>LeigodOnlyActivationPlan::canonicalJson($state)]);
            return $writeHash;
        });
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
        $expected=$forwardId . ':' . ($clientId ?? 0) . ':' . ($deviceId ?? 0) . ':' . ($desktopClientSessionId ?? 0) . ':artifact-pointer-current';
        if ($provided === null || $provided === '') { return $expected; }
        if ((string)$provided !== $expected) { throw new \RuntimeException('artifact_pointer_slot_key_mismatch'); }
        return $expected;
    }
}
