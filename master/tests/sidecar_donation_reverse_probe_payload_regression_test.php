<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$controller = file_get_contents(__DIR__ . '/../src/Controllers/SidecarAgentController.php') ?: '';
$service = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
foreach ([
    'recordDonationReverseProbe(int $sidecarPointId, array $payload): void',
    'donationReverseProbeTargetsForPoint(int $sidecarPointId): array',
    'donation_reverse_probe',
    'donation_reverse_probe_targets',
    'last_reverse_probe_json',
] as $snippet) {
    if (!str_contains($controller . $service, $snippet)) {
        fail('missing reverse probe snippet: ' . $snippet);
    }
}
echo "ok
";
