<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$controller = file_get_contents(__DIR__ . '/../src/Controllers/NodeController.php') ?: '';
$service = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
foreach ([
    'recordDonationSelfCheck(int $nodeId, array $payload): void',
    "donation_self_check",
    'last_self_check_json',
    'last_measured_latency_ms',
    'open_ports_json',
] as $snippet) {
    if (!str_contains($controller . $service, $snippet)) {
        fail('missing donation self-check ingest snippet: ' . $snippet);
    }
}
echo "ok
";
