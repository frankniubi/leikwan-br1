<?php
declare(strict_types=1);
require_once __DIR__ . '/leigod_only_rollback_test_helper.php';

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
$result = $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
if (($result['artifact_pointer_restore_hash'] ?? '') !== hash('sha256', cj(['artifact_snapshot_hash' => $seed['prev_artifact'], 'forward_ids' => [47, 48]]))) fail('artifact restore hash mismatch');
$state = ($pdo->rows('leigod_only_artifact_pointer_states', fn(array $r): bool => (int)$r['forward_id'] === 47 && (int)$r['desired_generation'] === 8)[0]['pointer_state_json'] ?? null);
if (!str_contains((string)$state, 'prior')) fail('did not restore prior artifact pointer payload');
foreach ([47, 48] as $fid) {
    $current = $pdo->rows('leigod_only_artifact_pointer_states', fn(array $r): bool => (int)$r['forward_id'] === $fid && ($r['current_slot_key'] ?? null) !== null);
    if (count($current) !== 1) fail('expected exactly one current artifact pointer ' . $fid);
    if (($current[0]['current_slot_key'] ?? '') !== $seed['pointer_slot_keys'][$fid]) fail('artifact pointer restored outside canonical current slot ' . $fid);
    if (str_contains((string)($current[0]['current_slot_key'] ?? ''), 'rollback-pointer')) fail('artifact pointer used synthetic rollback slot ' . $fid);
    $identity = $seed['identities'][$fid];
    if (($current[0]['client_id'] ?? null) !== $identity['client_id'] || ($current[0]['client_device_session_id'] ?? null) !== $identity['client_device_session_id'] || ($current[0]['desktop_client_session_id'] ?? null) !== $identity['desktop_client_session_id']) fail('artifact pointer did not preserve identity ' . $fid);
}

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$pdo->updateRows('leigod_only_artifact_pointer_states', fn(array $r): bool => (int)$r['forward_id'] === 47 && ($r['current_slot_key'] ?? null) !== null, function(array $r): array { $r['artifact_snapshot_hash'] = h('0'); return $r; });
$svc = new LeigodOnlyRollbackService($pdo);
try {
    $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'artifact_pointer_live_hash_drift')) {
        echo "ok\n";
        return;
    }
    fail('wrong artifact drift rejection ' . $e->getMessage());
}
fail('expected artifact pointer drift rejection');
