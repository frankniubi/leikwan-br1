<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($source === false) {
    fail('failed to read ProvisioningService.php');
}

foreach ([
    'desired_latency_ms',
    'allow_public_self_leg_donation',
    'donation_qualify_window_seconds',
    'donation_revoke_window_seconds',
    'donation_min_open_ports',
] as $column) {
    if (substr_count($source, "'" . $column . "'") < 1) {
        fail('normalized payload missing column: ' . $column);
    }
    if (strpos($source, $column . ' = :' . $column) === false) {
        fail('UPDATE statement missing column assignment: ' . $column);
    }
}

foreach ([
    "\$desiredLatencyMs = max(0, (int) (\$payload['desired_latency_ms'] ?? 0));",
    "\$donationQualifyWindowSeconds = max(5, (int) (\$payload['donation_qualify_window_seconds'] ?? 60));",
    "\$donationRevokeWindowSeconds = max(5, (int) (\$payload['donation_revoke_window_seconds'] ?? 30));",
    "\$donationMinOpenPorts = max(0, (int) (\$payload['donation_min_open_ports'] ?? 1));",
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing clamp/normalization snippet: ' . $snippet);
    }
}

echo "ok
";
