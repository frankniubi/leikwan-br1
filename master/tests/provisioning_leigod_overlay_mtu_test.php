<?php
declare(strict_types=1);

// Guard: leigod overlay underlays must carry a pinned WG MTU. The overlay's WG peer endpoint is the
// local UoT carrier (127.0.0.1), so without an explicit MTU wg-quick auto-derives it from loopback
// (65456) and oversized MPTCP segments black-hole on the ~1280 leigod path (handshake works, bulk
// data stalls). The panel must emit 'mtu' on leigod underlays and the client runtime must render it.

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = __DIR__ . '/..';

$svc = file_get_contents($root . '/src/Support/ProvisioningService.php') ?: '';
foreach ([
    "MPTCP_LEIGOD_OVERLAY_WG_MTU', 1240",   // config-driven overlay MTU, leigod only
    "'mtu' => \$overlayMtu,",                 // emitted into each underlay
] as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('ProvisioningService missing overlay-MTU snippet: ' . $snippet);
    }
}

$client = file_get_contents($root . '/../client-agent/leikwan_mptcp_client.py') ?: '';
foreach (['mtu = max(0, int(config.get("mtu", 0) or 0))', 'interface_lines.append(f"MTU = {mtu}")'] as $snippet) {
    if (!str_contains($client, $snippet)) {
        fail('leikwan_mptcp_client.py missing MTU render snippet: ' . $snippet);
    }
}

echo "OK\n";
exit(0);
