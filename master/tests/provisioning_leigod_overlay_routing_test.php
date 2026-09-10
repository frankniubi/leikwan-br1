<?php
declare(strict_types=1);

// Guard: the static leigod uplink payload must carry the MPTCP overlay addresses so the
// overlay is L3-stitched across the leigod hop (slave routes overlay-sidecar over the uplink;
// sidecar owns the overlay-sidecar addr + routes overlay-client back). Without these payload
// fields the agents have no overlay AllowedIPs/Address and the overlay blackholes -- the
// data-path gap that left leigod_mode MPTCP unable to carry load.

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = __DIR__ . '/..';

// 1. Panel emits the overlay fields into the slave + sidecar uplink payloads.
$svc = file_get_contents($root . '/src/Support/ProvisioningService.php') ?: '';
foreach ([
    "\$slave['overlay_peer_allowed_ips'] = \$overlaySidecarIps;",
    "\$sidecar['overlay_peer_allowed_ips'] = \$overlayClientIps;",
    "\$sidecar['overlay_local_addresses'] = \$overlaySidecarIps;",
    "stableMptcpLegAddressing(\$overlayForwardId, \$nodeId)",
] as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('ProvisioningService missing overlay snippet: ' . $snippet);
    }
}

// 2. Slave agent renders overlay_peer_allowed_ips into the uplink Peer AllowedIPs.
$slaveAgent = file_get_contents($root . '/../slave-agent/slave_agent.py') ?: '';
foreach (['overlay_peer_allowed_ips', 'allowed_ips = ", ".join('] as $snippet) {
    if (!str_contains($slaveAgent, $snippet)) {
        fail('slave_agent.py missing overlay snippet: ' . $snippet);
    }
}

// 3. Sidecar agent owns the overlay sidecar address + carries overlay client in AllowedIPs.
$sidecarAgent = file_get_contents($root . '/../sidecar-agent/sidecar_agent.py') ?: '';
foreach (['overlay_local_addresses', 'overlay_peer_allowed_ips', 'overlay_address_lines'] as $snippet) {
    if (!str_contains($sidecarAgent, $snippet)) {
        fail('sidecar_agent.py missing overlay snippet: ' . $snippet);
    }
}

echo "OK\n";
exit(0);
