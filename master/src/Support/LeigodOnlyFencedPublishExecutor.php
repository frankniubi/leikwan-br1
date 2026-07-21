<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

require_once __DIR__ . '/LeigodOnlyActivationPlan.php';
require_once __DIR__ . '/LeigodOnlyRolloutLockService.php';

final class LeigodOnlyFencedPublishExecutor
{
    public function __construct(private object $db, private object $publisher) {}

    /** @param array<string,mixed> $request */
    public function execute(array $request): void
    {
        $scope=(string)($request['scope'] ?? ''); $owner=$request['owner_token'] ?? null; $fence=$request['fencing_token'] ?? null;
        LeigodOnlyRolloutLockService::requireFence($scope, is_string($owner)?$owner:null, is_string($fence)?$fence:null);
        $jobHash=(string)($request['job_hash'] ?? ''); LeigodOnlyRolloutLockService::requireHash($jobHash,'job_hash');
        $publishFence=(string)($request['publish_fence_token'] ?? ''); LeigodOnlyRolloutLockService::requireHash($publishFence,'publish_fence_token');
        $forwardId=(int)($request['forward_id'] ?? 0); $generation=(int)($request['desired_generation'] ?? 0); $desiredHash=(string)($request['desired_state_hash'] ?? ''); LeigodOnlyRolloutLockService::requireHash($desiredHash,'desired_state_hash');
        [$clientId,$deviceId,$desktopClientSessionId]=$this->normalizedIdentity($request); $slotKey=$this->currentSlotKey($forwardId,$clientId,$deviceId,$desktopClientSessionId,$request['current_slot_key'] ?? null);
        $identitySql=' AND COALESCE(client_id,0) = :client_id AND COALESCE(client_device_session_id,0) = :device_id AND COALESCE(desktop_client_session_id,0) = :desktop_client_session_id AND current_slot_key = :current_slot_key';
        $params=['scope'=>$scope,'job_hash'=>$jobHash,'forward_id'=>$forwardId,'owner_token'=>$owner,'fencing_token'=>$fence,'publish_fence_token'=>$publishFence,'desired_generation'=>$generation,'desired_state_hash'=>$desiredHash,'client_id'=>$clientId ?? 0,'device_id'=>$deviceId ?? 0,'desktop_client_session_id'=>$desktopClientSessionId ?? 0,'current_slot_key'=>$slotKey];
        $job=$this->db->fetchOne('SELECT * FROM leigod_only_fenced_publish_jobs WHERE scope = :scope AND job_hash = :job_hash AND forward_id = :forward_id AND owner_token = :owner_token AND fencing_token = :fencing_token AND publish_fence_token = :publish_fence_token AND desired_generation = :desired_generation AND desired_state_hash = :desired_state_hash' . $identitySql . ' LIMIT 1', $params);
        if ($job === null) throw new \RuntimeException('fenced_publish_job_missing');
        $artifact=(string)($job['artifact_snapshot_hash'] ?? ''); LeigodOnlyRolloutLockService::requireHash($artifact,'artifact_snapshot_hash');
        $count=$this->db->execute("UPDATE leigod_only_fenced_publish_jobs SET job_state = 'leased', leased_until = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 60 SECOND) WHERE scope = :scope AND job_hash = :job_hash AND forward_id = :forward_id AND owner_token = :owner_token AND fencing_token = :fencing_token AND publish_fence_token = :publish_fence_token AND desired_generation = :desired_generation AND desired_state_hash = :desired_state_hash" . $identitySql . " AND job_state = 'pending'", $params);
        LeigodOnlyRolloutLockService::requireExactlyOneCount($count, 'publish_job_lease');
        $fenceParams=['scope'=>$scope,'forward_id'=>$forwardId,'owner_token'=>$owner,'fencing_token'=>$fence,'desired_generation'=>$generation,'desired_state_hash'=>$desiredHash,'artifact_snapshot_hash'=>$artifact,'client_id'=>$clientId ?? 0,'device_id'=>$deviceId ?? 0,'desktop_client_session_id'=>$desktopClientSessionId ?? 0,'current_slot_key'=>$slotKey];
        $fenceRow=$this->db->fetchOne('SELECT * FROM leigod_only_forward_desired_state_fences WHERE scope = :scope AND forward_id = :forward_id AND owner_token = :owner_token AND fencing_token = :fencing_token AND desired_generation = :desired_generation AND desired_state_hash = :desired_state_hash AND artifact_snapshot_hash = :artifact_snapshot_hash AND state = \'fenced\' AND COALESCE(client_id,0) = :client_id AND COALESCE(client_device_session_id,0) = :device_id AND COALESCE(desktop_client_session_id,0) = :desktop_client_session_id AND current_slot_key = :current_slot_key AND superseded_at IS NULL LIMIT 1', $fenceParams);
        if ($fenceRow === null) throw new \RuntimeException('desired_state_fence_missing');
        if (!method_exists($this->publisher, 'publishFenced')) throw new \RuntimeException('fence_aware_publish_wrapper_required');
        $this->publisher->publishFenced(array_merge($job, ['desired_state_fence'=>$fenceRow]));
        $count=$this->db->execute("UPDATE leigod_only_fenced_publish_jobs SET job_state = 'published' WHERE scope = :scope AND job_hash = :job_hash AND forward_id = :forward_id AND owner_token = :owner_token AND fencing_token = :fencing_token AND publish_fence_token = :publish_fence_token AND desired_generation = :desired_generation AND desired_state_hash = :desired_state_hash" . $identitySql . " AND job_state = 'leased'", $params);
        LeigodOnlyRolloutLockService::requireExactlyOneCount($count, 'publish_job_complete');
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
