<?php
declare(strict_types=1);
require_once __DIR__ . '/leigod_only_rollback_test_helper.php';

$stages = ['after_supersede_current_slots', 'after_restore_current_slots', 'after_restore_desired_state', 'after_restore_artifact_pointer', 'after_enqueue_publish', 'after_audit_event'];
foreach ($stages as $stage) {
    $pdo = rollback_db();
    $seed = seed_rollback($pdo);
    $before = rollback_dump_state($pdo);
    $svc = new LeigodOnlyRollbackService($pdo, ['failure_injector' => function (string $s) use ($stage): void { if ($s === $stage) throw new RuntimeException('injected_' . $s); }]);
    try {
        $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
        fail('expected injected failure ' . $stage);
    } catch (Throwable $e) {
        if (!str_contains($e->getMessage(), 'injected_' . $stage)) fail('wrong injected failure ' . $stage . ': ' . $e->getMessage());
        if (rollback_dump_state($pdo) !== $before) fail('injected failure did not rollback ' . $stage);
    }
}
echo "ok\n";
