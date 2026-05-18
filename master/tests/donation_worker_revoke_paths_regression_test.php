<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
foreach ([
    'donation_state donor=',
    'revokeDonationManually(int $combinationId, int $donationId): void',
    "state = :cooldown",
    "state = :revoked",
    'revoked_at',
    'revoke_reason',
    'latency_above_target',
    'port_probe_failed',
    'session_ended',
    'admin_disabled',
] as $snippet) {
    if (!str_contains($source, $snippet)) { fail('missing revoke snippet: ' . $snippet); }
}
echo "ok
";
