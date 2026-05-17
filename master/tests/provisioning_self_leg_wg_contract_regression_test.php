<?php
declare(strict_types=1);

$php = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($php === false) {
    fwrite(STDERR, "failed to read ProvisioningService.php\n");
    exit(1);
}

foreach ([
    'self_leg_wg',
    'client_self_leg',
    'self_leg_client_peers',
    'buildSelfLegWireGuardProfile',
    'selfLegClientPeersForPoint',
    "'probe_transport' => 'private_wg'",
    "'runtime_capabilities' => ['bond', 'mptcp', 'wireguard']",
    "'client_public_key' =>",
    "'sidecar_public_key' =>",
    "'bond_sidecar_point_agent_config_json' => (string) (\$forward['bond_sidecar_point_agent_config_json'] ?? '')",
    "'line_client_agent_config_json' => (string) (\$forward['line_client_agent_config_json'] ?? '')",
] as $snippet) {
    if (!str_contains($php, $snippet)) {
        fwrite(STDERR, "missing self-leg wg contract snippet: {$snippet}\n");
        exit(1);
    }
}

$sidecarController = file_get_contents(__DIR__ . '/../src/Controllers/SidecarAgentController.php');
if ($sidecarController === false || !str_contains($sidecarController, 'self_leg_client_peers')) {
    fwrite(STDERR, "sidecar management payload does not publish self_leg_client_peers\n");
    exit(1);
}

echo "ok\n";
