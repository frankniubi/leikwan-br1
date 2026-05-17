<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
foreach ([
    'probe_transport',
    'cf_direct_wss',
    'dial_host',
    'dial_port',
    'cf_sni_host',
    'path_prefix',
    'target_sidecar_host',
    'target_sidecar_port',
    'target_sidecar_bond_port',
    'target_sidecar_mptcp_port',
    'self_leg_cf_candidates',
    'cf_candidates',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing self-leg payload snippet: ' . $snippet);
    }
}

if (str_contains($source, 'selectedSelfLegCfIp(')) {
    fail('panel should not choose one self-leg CF IP anymore');
}

echo "ok\n";
