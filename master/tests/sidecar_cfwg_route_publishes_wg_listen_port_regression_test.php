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
    'combo.self_leg_cf_transport AS forward_combination_self_leg_cf_transport',
    'LEFT JOIN forward_combinations combo ON combo.id = f.forward_combination_id',
    "\$sidecarWireguardListenPort = (int) (\$runtimeState['wireguard_listen_port'] ?? 0)",
    'use ($directCfBackendForwardIds, $sidecarWireguardListenPort)',
    'cf_wg_route_publishes_wg_listen_port',
    "'cf-forward-' . \$forwardId . '-cfwg'",
    "\$pathPrefix . '.cfwg'",
    "'127.0.0.1:' . \$sidecarWireguardListenPort",
    "'transport_mode' => 'udp'",
    "'backend_mode' => 'direct'",
    "\$selfLegCfTransport === 'cf_wg'",
    "\$backendModeRaw === 'cf_mode'",
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing cf_wg sidecar route snippet: ' . $snippet);
    }
}

echo "ok\n";
