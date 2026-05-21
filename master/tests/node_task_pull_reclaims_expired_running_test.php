<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$source = file_get_contents($repoRoot . '/master/src/Controllers/NodeController.php');
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "failed to read NodeController.php\n");
    exit(1);
}

$required = [
    'private function reclaimExpiredProvisionTaskLeases(int $nodeId): void',
    'status = :running_status',
    'lease_expires_at IS NOT NULL',
    'lease_expires_at < :now',
    'status = :pending_status',
    'attempt_count = GREATEST(attempt_count - 1, 0)',
    '$this->reclaimExpiredProvisionTaskLeases((int) $node[\'id\']);',
];

foreach ($required as $snippet) {
    if (!str_contains($source, $snippet)) {
        fwrite(STDERR, 'missing expected NodeController lease reclaim snippet: ' . $snippet . "\n");
        exit(1);
    }
}

$callPos = strpos($source, '$this->reclaimExpiredProvisionTaskLeases((int) $node[\'id\']);');
$selectPos = strpos($source, 'FROM provision_tasks');
if ($callPos === false || $selectPos === false || $callPos > $selectPos) {
    fwrite(STDERR, "lease reclaim must happen before selecting pending tasks\n");
    exit(1);
}

echo "ok\n";
