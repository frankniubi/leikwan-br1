<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$adminSource = file_get_contents($repoRoot . '/master/src/Controllers/AdminController.php');
$bootstrapSource = file_get_contents($repoRoot . '/master/src/Bootstrap.php');
if (!is_string($adminSource) || $adminSource === '' || !is_string($bootstrapSource) || $bootstrapSource === '') {
    fail('failed to read admin queue repair sources');
}

foreach ([
    'public function repairProvisionQueue(Request $request, array $params): Response',
    'repair_node_id',
    'reclaim_expired_running',
    'collapse_duplicate_pending',
    'GROUP BY node_id, action, service_id, forward_id, endpoint_id',
    'superseded_by_admin_queue_repair',
    "'/admin/provision-queue/repair'",
    "'/admin/provision-queue/republish-endpoint'",
    'public function republishProvisionEndpoint(Request $request, array $params): Response',
    'repair_forward_id',
    'repair_endpoint_id',
] as $snippet) {
    if (!str_contains($adminSource . "\n" . $bootstrapSource, $snippet)) {
        fail('missing expected admin queue repair snippet: ' . $snippet);
    }
}

if (preg_match('/DELETE\s+FROM\s+provision_tasks[^;]*status\s*=\s*[\'\"]running[\'\"]/is', $adminSource) === 1) {
    fail('admin queue repair must not delete running rows');
}

if (!str_contains($adminSource, 'attempt_count = GREATEST(attempt_count - 1, 0)')) {
    fail('expired running reclaim should preserve retry budget by decrementing attempt_count');
}

echo "ok\n";
