<?php
declare(strict_types=1);
require_once __DIR__ . '/leigod_only_rollback_test_helper.php';

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
$result = $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
if (($result['ok'] ?? false) !== true) fail('canonical live current slot should be supersedable');
if ($pdo->countRows('leigod_only_activation_assignment_current_slots', fn(array $r): bool => ($r['slot_key'] ?? '') === $seed['slot_keys'][47] && (int)$r['desired_generation'] === 8) !== 1) fail('canonical restore current slot missing after positive supersession');

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$pdo->updateRows('leigod_only_activation_assignment_current_slots', fn(array $r): bool => ($r['slot_key'] ?? '') === $seed['slot_keys'][47], function(array $r): array { $r['owner_token'] = h('4'); return $r; });
$before = rollback_dump_state($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
try {
    $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
} catch (Throwable $e) {
    if (!str_contains($e->getMessage(), 'current_slot_restore_target_not_supersedable')) fail('wrong foreign slot rejection ' . $e->getMessage());
    if (rollback_dump_state($pdo) !== $before) fail('foreign slot rejection mutated DB');
    $foreignRejected = true;
}
if (!isset($foreignRejected)) fail('expected foreign live current slot rejection');

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$pdo->deleteRows('leigod_only_activation_assignment_current_slots', fn(array $r): bool => ($r['slot_key'] ?? '') === $seed['slot_keys'][47]);
$before = rollback_dump_state($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
$result = $svc->apply(7, $seed['batch_hash'], $seed['owner'], $seed['fence']);
if (($result['ok'] ?? false) !== true) fail('expected missing current row to be restored, not rejected');
if (rollback_dump_state($pdo) === $before) fail('missing current row apply did not restore anything');
echo "ok\n";
