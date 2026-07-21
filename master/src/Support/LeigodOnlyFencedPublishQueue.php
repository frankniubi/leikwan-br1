<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodOnlyActivationPlan.php';
require_once __DIR__ . '/LeigodOnlyRolloutLockService.php';

final class LeigodOnlyFencedPublishQueue
{
    public function __construct(private object $db) {}

    /** @param array<string,mixed> $request */
    public function enqueue(array $request): string
    {
        $scope=(string)($request['scope'] ?? ''); $owner=$request['owner_token'] ?? null; $fence=$request['fencing_token'] ?? null;
        LeigodOnlyRolloutLockService::requireFence($scope, is_string($owner)?$owner:null, is_string($fence)?$fence:null);
        $forwardId=(int)($request['forward_id'] ?? 0); if ($forwardId<=0) throw new \InvalidArgumentException('forward_id_required');
        $generation=(int)($request['desired_generation'] ?? 0); if ($generation<=0) throw new \InvalidArgumentException('desired_generation_required');
        $desiredHash=(string)($request['desired_state_hash'] ?? ''); LeigodOnlyRolloutLockService::requireHash($desiredHash,'desired_state_hash');
        $artifact=(string)($request['artifact_snapshot_hash'] ?? ''); LeigodOnlyRolloutLockService::requireHash($artifact,'artifact_snapshot_hash');
        $publishFence=(string)($request['publish_fence_token'] ?? ''); LeigodOnlyRolloutLockService::requireHash($publishFence,'publish_fence_token');
        [$clientId,$deviceId,$desktopClientSessionId]=$this->normalizedIdentity($request); $slotKey=$this->currentSlotKey($forwardId,$clientId,$deviceId,$desktopClientSessionId,$request['current_slot_key'] ?? null);
        $identity=['client_id'=>$clientId,'client_device_session_id'=>$deviceId,'desktop_client_session_id'=>$desktopClientSessionId,'current_slot_key'=>$slotKey];
        $payload=['payload'=>$request['payload'] ?? null,'plan_hash'=>$request['plan_hash'] ?? null,'batch_hash'=>$request['batch_hash'] ?? null,'affected_forward_ids_json'=>$request['affected_forward_ids'] ?? [$forwardId],'identity'=>$identity];
        $jobHash=hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['scope'=>$scope,'forward_id'=>$forwardId,'identity'=>$identity,'desired_generation'=>$generation,'desired_state_hash'=>$desiredHash,'artifact_snapshot_hash'=>$artifact,'publish_fence_token'=>$publishFence,'payload'=>$payload]));
        $writeHash=hash('sha256', LeigodOnlyActivationPlan::canonicalJson(['job_hash'=>$jobHash,'scope'=>$scope,'forward_id'=>$forwardId,'identity'=>$identity,'owner_token'=>$owner,'fencing_token'=>$fence,'publish_fence_token'=>$publishFence]));
        $this->db->insert('leigod_only_fenced_publish_jobs', ['scope'=>$scope,'job_hash'=>$jobHash,'forward_id'=>$forwardId,'client_id'=>$clientId,'client_device_session_id'=>$deviceId,'desktop_client_session_id'=>$desktopClientSessionId,'current_slot_key'=>$slotKey,'desired_generation'=>$generation,'desired_state_hash'=>$desiredHash,'artifact_snapshot_hash'=>$artifact,'publish_fence_token'=>$publishFence,'job_state'=>'pending','owner_token'=>$owner,'fencing_token'=>$fence,'write_hash'=>$writeHash,'external_authorization_ref'=>$request['external_authorization_ref'] ?? null,'payload_json'=>LeigodOnlyActivationPlan::canonicalJson($payload)]);
        return $jobHash;
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
        if ((string)$provided !== $expected) { throw new \RuntimeException('current_slot_key_mismatch'); }
        return $expected;
    }
}
