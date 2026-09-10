<?php
declare(strict_types=1);
require_once __DIR__ . '/leigod_only_rollback_test_helper.php';

$cases = [
    ['missing desired snapshot', ['batch_json' => ['previous_desired_state_snapshot' => null]], 'previous_desired_state_snapshot_missing'],
    ['mutable desired snapshot', ['batch_json' => ['previous_desired_state_snapshot' => ['snapshot_immutable' => 0]]], 'previous_desired_state_snapshot_not_immutable'],
    ['mutated desired snapshot hash', ['batch_json' => ['previous_desired_state_snapshot' => ['snapshot_hash' => h('0')]]], 'previous_desired_state_snapshot_hash_mismatch'],
    ['missing artifact snapshot', ['batch_json' => ['previous_artifact_pointer_snapshot' => null]], 'artifact_pointer_snapshot_missing'],
    ['mutable artifact snapshot', ['batch_json' => ['previous_artifact_pointer_snapshot' => ['snapshot_immutable' => 0]]], 'artifact_pointer_snapshot_not_immutable'],
    ['missing current snapshot', ['batch_json' => ['previous_current_slot_snapshot' => null]], 'previous_current_slot_snapshot_missing'],
    ['current-only snapshot marker', ['batch_json' => ['previous_current_slot_snapshot' => ['current_only' => true]]], 'previous_current_slot_snapshot_current_only'],
];
foreach ($cases as [$label, $override, $expected]) {
    $pdo = rollback_db();
    $seed = seed_rollback($pdo, $override);
    $svc = new LeigodOnlyRollbackService($pdo);
    try {
        $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
        fail('expected rejection ' . $label);
    } catch (Throwable $e) {
        if (!str_contains($e->getMessage(), $expected)) fail('wrong rejection for ' . $label . ': ' . $e->getMessage());
    }
}
$pdo = rollback_db();
$seed = seed_rollback($pdo, ['snapshot_immutable' => 0]);
$svc = new LeigodOnlyRollbackService($pdo);
try {
    $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'artifact_snapshot_missing_or_mutable')) {
        echo "ok\n";
        return;
    }
    fail('wrong persisted artifact snapshot rejection ' . $e->getMessage());
}
fail('expected persisted artifact snapshot immutability rejection');
