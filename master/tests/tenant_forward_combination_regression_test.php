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
    'forward_route_id',
    'Forward Route',
    'No route package is available for this service yet. Ask admin to publish a forward combination first.',
    'ForwardLineApiContract::normalizeLegacyCreate',
    '/tenant/services/{id}/create-forward',
    'Create Forward',
    'Choose one admin-defined route package. The panel keeps routing details hidden until you create the forward.',
] as $snippet) {
    if (!str_contains($source . $bootstrap . $serviceSummarySource, $snippet)) {
        fail('missing required combination-only snippet: ' . $snippet);
    }
}

if (str_contains($source, "'forward_combination_id' => max(0, (int) (\$payload['forward_combination_id'] ?? 0)),")) {
    fail('tenant payload must not accept forward_combination_id');
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
    "Show the selected combination\\'s short description and badge here without exposing backend details.",
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('found forbidden stale tenant forward snippet: ' . $forbidden);
    }
}

echo "ok\n";
