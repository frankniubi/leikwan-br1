<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read AdminController source');
}

foreach ([
    'Allowed Forward Combinations',
    'Legacy plan detected. Choose forward combinations and save to convert this plan.',
    'renderForwardCombinationPlanGroups(',
    'allowed_forward_combination_ids[]',
    "'allowed_forward_combination_ids' => \$request->body()['allowed_forward_combination_ids'] ?? [],",
    'Forward Combinations',
] as $required) {
    if (!str_contains($source, $required)) {
        fail('missing required plan forward-combination snippet: ' . $required);
    }
}

foreach ([
    '<label>Allowed Slave Groups</label>',
    '<label>Allowed Client Groups</label>',
    '<label>Allowed Sidecar Points</label>',
    '<th>Allowed Slave Groups</th>',
    '<th>Allowed Client Groups</th>',
    '<th>Allowed Sidecars</th>',
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('stale low-level plan selector still present: ' . $forbidden);
    }
}

echo "ok\n";
