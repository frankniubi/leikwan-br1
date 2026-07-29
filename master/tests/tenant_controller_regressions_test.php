<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read TenantController source');
}

foreach ([
    'No route package is available for this service yet. Ask admin to publish a forward combination first.',
    'Choose one route package, then fill your backend target.',
    'Create Forward',
] as $required) {
    if (!str_contains($source, $required)) {
        fail('missing required tenant forward flow snippet: ' . $required);
    }
}

foreach ([
    '<label>Forward Chain</label>',
    '<label>Route Mode</label>',
    '<label>Connection Mode</label>',
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('stale tenant forward selector still present: ' . $forbidden);
    }
}

$billingStart = strpos($source, 'public function billingPage(Request $request, array $params): Response');
$plansStart = strpos($source, 'public function plansPage(Request $request, array $params): Response', $billingStart ?: 0);
if ($billingStart === false || $plansStart === false || $plansStart <= $billingStart) {
    fail('failed to isolate tenant account method');
}

$billing = substr($source, $billingStart, $plansStart - $billingStart);
foreach ([
    'if ($rows === \'\')',
    '$availableForwardCombinations',
    '$createForwardNotice',
] as $forbidden) {
    if (str_contains($billing, $forbidden)) {
        fail('tenant account contains forward-page residue: ' . $forbidden);
    }
}

echo "ok\n";
