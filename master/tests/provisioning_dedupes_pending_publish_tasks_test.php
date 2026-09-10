<?php
declare(strict_types=1);

$repoRoot = dirname(__DIR__, 2);
$source = file_get_contents($repoRoot . '/master/src/Support/ProvisioningService.php');
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "failed to read ProvisioningService.php\n");
    exit(1);
}

$required = [
    'private function collapsePendingProvisionTasksForNode(',
    'superseded_by_new_publish_task',
    'status = :completed_status',
    'status = :pending_status',
    'node_id = :node_id',
    'action = :action',
    'service_id = :service_id',
    'forward_id = :forward_id',
    'endpoint_id = :endpoint_id',
    '$this->collapsePendingProvisionTasksForNode(',
    'public function republishActiveEndpoint(int $nodeId, int $forwardId, int $endpointId): bool',
];

foreach ($required as $snippet) {
    if (!str_contains($source, $snippet)) {
        fwrite(STDERR, 'missing expected ProvisioningService dedupe snippet: ' . $snippet . "\n");
        exit(1);
    }
}

$insertPos = strpos($source, "\$this->db->insert('provision_tasks'");
$dedupePos = strpos($source, '$this->collapsePendingProvisionTasksForNode(');
if ($insertPos === false || $dedupePos === false || $dedupePos > $insertPos) {
    fwrite(STDERR, "dedupe must run before inserting the replacement provision task\n");
    exit(1);
}

echo "ok\n";
