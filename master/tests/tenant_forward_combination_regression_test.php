<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$serviceSummarySource = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    'available_forward_combinations',
    'forward_combination_id',
    'Forward Combination',
    'No route package is available for this service yet. Ask admin to publish a forward combination first.',
    'public function createForward(Request $request, array $params): Response',
    "'forward_combination_id' => max(0, (int) (\$payload['forward_combination_id'] ?? 0)),",
] as $snippet) {
    if (!str_contains($source . $serviceSummarySource, $snippet)) {
        fail('missing tenant combination snippet: ' . $snippet);
    }
}

echo "ok\n";
