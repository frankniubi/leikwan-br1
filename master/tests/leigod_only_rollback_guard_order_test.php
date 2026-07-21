<?php
declare(strict_types=1);
require_once __DIR__ . '/leigod_only_rollback_test_helper.php';

$checks = [];
$pdo = rollback_db();
$svc = new LeigodOnlyRollbackService($pdo);
try { $svc->apply(404, h('b'), h('c'), h('d')); } catch (Throwable $e) { $checks[] = $e->getMessage(); }
$pdo = rollback_db();
$seed = seed_rollback($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
try { $svc->apply(7, h('0'), $seed['owner'], $seed['fence']); } catch (Throwable $e) { $checks[] = $e->getMessage(); }
$pdo = rollback_db();
$seed = seed_rollback($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
try { $svc->apply(7, $seed['batch_hash'], '', $seed['fence']); } catch (Throwable $e) { $checks[] = $e->getMessage(); }
$pdo = rollback_db();
$seed = seed_rollback($pdo);
$svc = new LeigodOnlyRollbackService($pdo);
try { $svc->apply(7, $seed['batch_hash'], h('0'), $seed['fence']); } catch (Throwable $e) { $checks[] = $e->getMessage(); }
$expected = ['batch_not_found', 'confirm_batch_hash_mismatch', 'owner_token_required', 'rollout_lock_or_fence_mismatch'];
foreach ($expected as $i => $needle) {
    if (!isset($checks[$i]) || !str_contains($checks[$i], $needle)) fail('guard order mismatch at ' . $i . ' got ' . ($checks[$i] ?? 'missing'));
}
echo "ok\n";
