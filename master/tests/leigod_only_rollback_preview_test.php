<?php
declare(strict_types=1);
require_once __DIR__ . '/leigod_only_rollback_test_helper.php';

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$before = rollback_dump_state($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
$preview = $svc->preview(7);
$after = rollback_dump_state($pdo);

if ($before !== $after) fail('preview mutated database');
foreach (['batch_id','expected_batch_hash','affected_forward_ids','desired_generation','desired_state_hash','publish_fence_token','artifact_pointer_hash','restore_summary','previous_desired_state_snapshot','previous_artifact_pointer_snapshot','previous_current_slot_snapshot'] as $key) {
    if (!array_key_exists($key, $preview)) fail('preview missing ' . $key);
}
if ($preview['expected_batch_hash'] !== $seed['batch_hash']) fail('preview batch hash mismatch');
if ($preview['affected_forward_ids'] !== [47, 48]) fail('preview affected forwards mismatch');
if ($preview['desired_generation'] !== 8 || $preview['desired_state_hash'] !== $seed['prev_desired']) fail('preview should expose previous desired generation/hash');
if ($preview['artifact_pointer_hash'] !== $seed['prev_artifact']) fail('preview artifact hash mismatch');
if (count($preview['restore_summary']) !== 2 || $preview['restore_summary'][0]['forward_id'] !== 47) fail('restore summary malformed');
if (rollback_count_rows($pdo, 'leigod_only_fenced_publish_jobs') !== 0) fail('preview enqueued publish');

echo "ok\n";
