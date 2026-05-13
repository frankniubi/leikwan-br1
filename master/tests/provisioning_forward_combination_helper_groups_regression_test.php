<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    'forward_combination_helper_groups',
    "runtime_preference",
    "allow_private_self_leg",
    "self_leg_scope",
    "self_leg_role",
    "helper_group_cap",
    "helper_escalation_policy",
    'DELETE FROM forward_combination_helper_groups WHERE forward_combination_id = :forward_combination_id',
    "node_group_key",
    "sort_order",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing snippet: ' . $snippet);
    }
}

echo "ok\n";
