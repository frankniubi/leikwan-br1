<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$serviceSummarySource = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    'available_forward_combinations',
    'forward_combination_id',
    'Forward Combination',
    'No route package is available for this service yet. Ask admin to publish a forward combination first.',
    "'forward_combination_id' => max(0, (int) (\$payload['forward_combination_id'] ?? 0)),",
    '/tenant/services/{id}/create-forward',
    'Create Forward',
] as $snippet) {
    if (!str_contains($source . $bootstrap . $serviceSummarySource, $snippet)) {
        fail('missing required combination-only snippet: ' . $snippet);
    }
}

foreach ([
    '<h3 id="new-forward">Create Forward</h3>',
    '<label>Route Mode</label>',
    '<label>Forward Chain</label>',
    '<label>Connection Mode</label>',
    '<label>Sidecar Point</label>',
    '<label>Slave Group</label>',
    '<th>Line Groups</th>',
    '<th>Route Mode</th>',
    '<th>Chain</th>',
    '<th>Client Mode</th>',
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('found forbidden stale tenant forward snippet: ' . $forbidden);
    }
}

echo "ok\n";
