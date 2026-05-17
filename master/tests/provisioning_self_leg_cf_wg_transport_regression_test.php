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
    "combo.self_leg_cf_transport AS forward_combination_self_leg_cf_transport",
    "'forward_combination_self_leg_cf_transport' => (string) (\$forward['forward_combination_self_leg_cf_transport'] ?? 'cf_direct')",
    "\$config['self_leg_cf_transport'] = (string) (\$config['forward_combination_self_leg_cf_transport'] ?? 'cf_direct');",
    "\$cfTransport = strtolower((string) (\$config['self_leg_cf_transport'] ?? 'cf_direct'));",
    "\$wantCfWg = \$cfBond && \$cfTransport === 'cf_wg';",
    "\$wgProfile['outer_transport'] = 'wstunnel_udp';",
    "'cf_dial_host'",
    "'cf_dial_port'",
    "'cf_sni_host'",
    "'cf_path_prefix'",
    "\$probeTransport = \$wgProfile !== null ? 'cf_wg' : 'cf_direct_wss';",
    "'lw-cfwg-' . (int) (\$config['forward_id'] ?? 0)",
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing cf_wg snippet: ' . $snippet);
    }
}

echo "ok\n";
