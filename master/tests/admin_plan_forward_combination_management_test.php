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
    '<label>Forward Routes</label>',
    'renderForwardRoutePlanCheckboxes(',
    'allowed_forward_route_ids[]',
    "'allowed_forward_route_ids' => \$request->body()['allowed_forward_route_ids'] ?? [],",
    'Forward Routes',
] as $required) {
    if (!str_contains($source, $required)) {
        fail('missing required plan forward-route snippet: ' . $required);
    }
}

foreach ([
    '<label>Allowed Forward Combinations</label>',
    'name="allowed_forward_combination_ids[]"',
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
