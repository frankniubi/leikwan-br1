<?php
declare(strict_types=1);
require_once __DIR__ . '/leigod_only_rollback_test_helper.php';

function rewrite_rollback_batch_json(RollbackMemoryDb $pdo, array $batchJson): void
{
    foreach (['previous_desired_state_snapshot', 'previous_artifact_pointer_snapshot', 'previous_current_slot_snapshot'] as $snapshotKey) {
        if (isset($batchJson[$snapshotKey]) && is_array($batchJson[$snapshotKey])) {
            $batchJson[$snapshotKey]['snapshot_hash'] = snap_hash($batchJson[$snapshotKey]);
        }
    }
    $pdo->updateRows('leigod_only_activation_batches', fn(array $r): bool => (int)$r['id'] === 7, function(array $r) use ($batchJson): array { $r['batch_json'] = cj($batchJson); return $r; });
}

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$batchJson = $seed['batch_json'];
$batchJson['previous_artifact_pointer_snapshot']['states'][1]['desktop_client_session_id'] = 88;
$batchJson['previous_artifact_pointer_snapshot']['states'][1]['current_slot_key'] = rollback_pointer_slot_key(48, 12, 99, 88);
rewrite_rollback_batch_json($pdo, $batchJson);
$before = rollback_dump_state($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
try {
    $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), 'affected_forward_allowlist_mismatch')) fail('wrong desktop mismatch rejection ' . $e->getMessage());
    if (rollback_dump_state($pdo) !== $before) fail('desktop mismatch rejection mutated DB');
    $desktopMismatchRejected = true;
}
if (!isset($desktopMismatchRejected)) fail('expected desired/artifact desktop tuple mismatch rejection');

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$batchJson = $seed['batch_json'];
$batchJson['previous_desired_state_snapshot']['rows'] = array_reverse($batchJson['previous_desired_state_snapshot']['rows']);
$batchJson['previous_artifact_pointer_snapshot']['states'] = array_reverse($batchJson['previous_artifact_pointer_snapshot']['states']);
rewrite_rollback_batch_json($pdo, $batchJson);
$svc = new LeigodOnlyRollbackService($pdo);
$result = $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
if (($result['ok'] ?? false) !== true) fail('reordered desired/artifact tuple sets should be accepted');
if (($result['restored_forward_ids'] ?? []) !== [47, 48]) fail('reordered tuple rollback restored forward IDs mismatch');

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$batchJson = $seed['batch_json'];
$batchJson['previous_desired_state_snapshot']['rows'][] = $batchJson['previous_desired_state_snapshot']['rows'][1];
rewrite_rollback_batch_json($pdo, $batchJson);
$before = rollback_dump_state($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
try {
    $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), 'duplicate_identity_tuple')) fail('wrong duplicate tuple rejection ' . $e->getMessage());
    if (rollback_dump_state($pdo) !== $before) fail('duplicate tuple rejection mutated DB');
    $duplicateRejected = true;
}
if (!isset($duplicateRejected)) fail('expected duplicate identity tuple rejection');

echo "ok\n";
