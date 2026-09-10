<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
foreach ([
    'pickFastestSlavesForCombination(int $combinationId, int $cap): array',
    'fastest_slave_per_combination_selector',
    'forward_combination_slaves',
    'forward_combination_self_leg_donors',
    "candidate_source' => 'rented_node'",
    "candidate_source' => 'self_leg_donor'",
    "candidate_kind' => 'donated_slave_leg'",
    'last_chain_latency_ms',
    'delivered_ewma',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing fastest selector snippet: ' . $snippet);
    }
}
if (!str_contains($source, 'pickFastestSlavesForCombination($combinationId, $selectorCap)')
    && !str_contains($source, 'pickFastestSlavesForCombination($combinationId, $desiredCount)')) {
    fail('reconcileForwardEndpoints must call fastest selector for combination-bound forwards');
}
echo "ok
";
