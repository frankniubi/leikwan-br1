<?php
declare(strict_types=1);
require_once __DIR__ . '/leigod_only_rollback_test_helper.php';

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$svc = new LeigodOnlyRollbackService($pdo);

$preview = $svc->preview((int) $seed['batch_id']);
if ($preview['batch_id'] !== $seed['batch_id']) fail('rollback plan preview batch id mismatch');
if ($preview['expected_batch_hash'] !== $seed['batch_hash']) fail('rollback plan preview hash mismatch');
if ($preview['affected_forward_ids'] !== $seed['affected']) fail('rollback plan affected forward IDs mismatch');
if (($preview['previous_desired_state_snapshot']['snapshot_immutable'] ?? 0) !== 1) fail('rollback plan must expose immutable desired snapshot');
if (($preview['previous_artifact_pointer_snapshot']['snapshot_immutable'] ?? 0) !== 1) fail('rollback plan must expose immutable artifact pointer snapshot');
if (($preview['previous_current_slot_snapshot']['snapshot_immutable'] ?? 0) !== 1) fail('rollback plan must expose immutable current-slot snapshot');

$before = rollback_dump_state($pdo);
try {
    $svc->apply((int) $seed['batch_id'], h('0'), $seed['owner'], $seed['fence']);
} catch (Throwable $throwable) {
    if (!str_contains($throwable->getMessage(), 'confirm_batch_hash_mismatch')) {
        fail('wrong rollback plan hash rejected with unexpected error: ' . $throwable->getMessage());
    }
} finally {
    if (rollback_dump_state($pdo) !== $before) fail('wrong rollback plan hash mutated state');
}

$result = $svc->apply((int) $seed['batch_id'], $seed['batch_hash'], $seed['owner'], $seed['fence']);
if (($result['status'] ?? '') !== 'rolled_back') fail('rollback plan apply did not roll back');
if (($result['restored_forward_ids'] ?? []) !== $seed['affected']) fail('rollback plan restored forward IDs mismatch');
if (count($result['publish_job_hashes'] ?? []) !== count($seed['affected'])) fail('rollback plan must enqueue one fenced publish per forward');

echo "ok\n";
