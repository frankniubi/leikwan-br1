<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read ClientAgentController source');
}

$required = [
    'client_heartbeat_republish_on_wireguard_identity_change',
    'last_heartbeat_fingerprint',
    'last_heartbeat_republish_at',
    'CLIENT_REPUBLISH_FORCE_INTERVAL_SECONDS',
    "\$wgFingerprint = sha1(implode(':', \$wgFingerprintInputs))",
    'if ($shouldRepublish) {',
    '$this->provisioning->republishActiveForwardsForClient((int) $client[\'id\']);',
];
foreach ($required as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing client heartbeat republish snippet: ' . $snippet);
    }
}

$pattern = "/recordAgentUsage\('client'[^\n]+\);\s*\n\s*\$this->provisioning->republishActiveForwardsForClient/s";
if (preg_match($pattern, $source) === 1) {
    fail('republishActiveForwardsForClient must be guarded by $shouldRepublish, not unconditional after recordAgentUsage');
}

echo "ok\n";
