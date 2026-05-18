<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
foreach ([
    'donationPolicyForForward(array $forward): array',
    'donation_policy_payload',
    "'donation_policy' =>",
    'donation_gate_ratio',
    'donor_client_id',
    'desktop_client_session_id',
    'device_id',
] as $snippet) {
    if (!str_contains($source, $snippet)) { fail('missing donation policy payload snippet: ' . $snippet); }
}
echo "ok
";
