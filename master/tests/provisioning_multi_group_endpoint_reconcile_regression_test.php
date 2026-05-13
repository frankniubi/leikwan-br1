<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
foreach ([
    'forward_combination_helper_groups',
    'orderedHelperGroupKeysForForward',
    'reconcileForwardEndpoints',
    'pickNodesForGroups',
    'origin_group_key',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing multi-group reconcile snippet: ' . $snippet);
    }
}

echo "ok\n";
