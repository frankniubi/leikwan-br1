<?php
declare(strict_types=1);
require_once __DIR__ . '/leigod_only_rollback_test_helper.php';

$pdo = rollback_db();
$seed = seed_rollback($pdo);
$svc = new LeigodOnlyRollbackService($pdo);

$before = rollback_dump_state($pdo);
foreach ([
    ['batch_id' => 0, 'error' => 'batch_not_found'],
    ['owner' => h('0'), 'error' => 'rollout_lock_or_fence_mismatch'],
    ['fence' => h('0'), 'error' => 'rollout_lock_or_fence_mismatch'],
] as $case) {
    try {
        $svc->apply(
            (int) ($case['batch_id'] ?? $seed['batch_id']),
            $seed['batch_hash'],
            (string) ($case['owner'] ?? $seed['owner']),
            (string) ($case['fence'] ?? $seed['fence'])
        );
    } catch (Throwable $throwable) {
        if (!str_contains($throwable->getMessage(), (string) $case['error'])) {
            fail('bounded rollback rejected with unexpected error: ' . $throwable->getMessage());
        }
        if (rollback_dump_state($pdo) !== $before) fail('bounded rollback guard mutated state for ' . $case['error']);
        continue;
    }
    fail('bounded rollback guard should reject ' . $case['error']);
}

$result = $svc->apply($seed['batch_id'], $seed['batch_hash'], $seed['owner'], $seed['fence']);
if (($result['status'] ?? '') !== 'rolled_back') fail('bounded rollback did not complete');
foreach ($pdo->rows('leigod_only_fenced_publish_jobs') as $job) {
    foreach (['owner_token', 'fencing_token', 'publish_fence_token', 'desired_state_hash', 'desired_generation', 'forward_id', 'job_hash'] as $field) {
        if (!isset($job[$field]) || $job[$field] === '') fail('bounded rollback publish job missing ' . $field);
    }
}

echo "ok\n";
