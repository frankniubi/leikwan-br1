<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/SidecarAgentController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read SidecarAgentController source');
}

foreach ([
    'sidecar_heartbeat_republish_gate',
    'last_heartbeat_fingerprint',
    'last_heartbeat_republish_at',
    'SIDECAR_REPUBLISH_FORCE_INTERVAL_SECONDS',
    "\$wgFingerprint = sha1(implode(':', \$wgFingerprintInputs))",
    'if ($shouldRepublish) {',
    '$this->provisioning->republishActiveForwardsForSidecarPoint((int) $point[\'id\']);',
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing republish gate snippet: ' . $snippet);
    }
}

// Make sure the unconditional republish (the bug we are fixing) is gone.
$pattern = "/\\\$this->provisioning->recordAgentUsage[^\\n]+\\);\\s*\\n\\s*\\\$this->provisioning->republishActiveForwardsForSidecarPoint/";
if (preg_match($pattern, $source) === 1) {
    fail('republishActiveForwardsForSidecarPoint must be guarded by $shouldRepublish, not called unconditionally after recordAgentUsage');
}

echo "ok\n";
