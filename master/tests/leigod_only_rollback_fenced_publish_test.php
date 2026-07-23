<?php
declare(strict_types=1);
require_once __DIR__ . '/leigod_only_rollback_test_helper.php';

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
$svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
$jobs = array_values($pdo->rows('leigod_only_fenced_publish_jobs'));
if (count($jobs) !== 2) fail('expected exactly two fenced jobs');
foreach ($jobs as $job) {
    $forwardId = (int) $job['forward_id'];
    $identity = rollback_identity($forwardId);
    $slotKey = rollback_desired_slot_key($forwardId, $identity['client_id'], $identity['client_device_session_id'], $identity['desktop_client_session_id']);
    foreach (['owner_token' => $seed['owner'], 'fencing_token' => $seed['fence'], 'publish_fence_token' => $seed['publish_fence'], 'desired_state_hash' => $seed['prev_desired'], 'artifact_snapshot_hash' => $seed['prev_artifact'], 'desired_generation' => 8, 'job_state' => 'pending'] as $k => $v) {
        if ((string)$job[$k] !== (string)$v) fail('job field mismatch ' . $k);
    }
    foreach (['client_id' => $identity['client_id'], 'client_device_session_id' => $identity['client_device_session_id'], 'desktop_client_session_id' => $identity['desktop_client_session_id'], 'current_slot_key' => $slotKey] as $k => $v) {
        if (($job[$k] ?? null) !== $v) fail('job identity mismatch ' . $k);
    }
    $payload = json_decode($job['payload_json'], true);
    if (!is_array($payload)) fail('job payload malformed');
    if (($payload['operation'] ?? '') !== 'rollback_publish' || ($payload['forward_id'] ?? 0) !== $forwardId) fail('job payload must be exact rollback publish');
    $payloadIdentity = $payload['identity'] ?? null;
    if (!is_array($payloadIdentity)) fail('job payload missing identity');
    foreach (['client_id' => $identity['client_id'], 'client_device_session_id' => $identity['client_device_session_id'], 'desktop_client_session_id' => $identity['desktop_client_session_id'], 'current_slot_key' => $slotKey] as $k => $v) {
        if (($payloadIdentity[$k] ?? null) !== $v) fail('job payload identity mismatch ' . $k);
    }
    if (!is_string($job['job_hash']) || strlen($job['job_hash']) !== 64) fail('missing job hash');
}

$pdo = rollback_db();
$seed = seed_rollback($pdo, ['batch_json' => ['post_activation' => ['desired_state_hash' => h('0')]]]);
$svc = new LeigodOnlyRollbackService($pdo);
try {
    $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'desired_state_live_hash_drift')) {
        echo "ok\n";
        return;
    }
    fail('wrong stale desired hash rejection ' . $e->getMessage());
}
fail('expected stale desired hash rejection before enqueue');
