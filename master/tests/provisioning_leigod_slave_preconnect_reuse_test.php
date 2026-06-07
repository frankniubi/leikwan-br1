<?php
declare(strict_types=1);

// Source-level guard for the opt-in panel contract that lets leigod_mode + MPTCP
// clients reuse the slave's preconnected WG/UoT relay. Default leigod behavior
// must remain no-preconnect unless LEIGOD_SLAVE_PRECONNECT_REUSE is explicitly set.

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$svc = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';

foreach ([
    'LEIGOD_SLAVE_PRECONNECT_REUSE',
    'stableLeigodPreconnectRelayPort',
    'LEIGOD_PRECONNECT_RELAY_PORT_RANGE_START',
    'LEIGOD_PRECONNECT_RELAY_PORT_RANGE_END',
    "'preconnect_listen_host' => '0.0.0.0'",
    "'preconnect_advertise_host' => \$this->resolveNodePublicHost(\$node)",
    "'preconnect_health_port' => \$leigodPreconnectPort + 1",
    "\$leigodPreconnectHost = \$leigodPreconnectEndpoint !== []",
    "!\$this->isUsablePublicHost(\$leigodPreconnectHost)",
    "\$leigodPreconnectReservedPorts = []",
    "(int) (\$forward['public_port'] ?? \$leigodPreconnectEndpoint['public_port'] ?? 0)",
    "'slave_relay_host' => \$leigodPreconnectReuse ? \$leigodPreconnectHost :",
    "'slave_relay_port' => \$leigodPreconnectReuse ? \$leigodPreconnectPort : \$legacySlaveRelayPort",
    "'manage_mptcp_endpoints' => \$leigodPreconnectReuse ? false :",
    "'mptcp_endpoints' => \$leigodPreconnectReuse ? [] :",
    "'underlays' => \$leigodPreconnectReuse ? [] :",
    "'subflow_limit' => \$leigodPreconnectReuse ? 1 :",
] as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('ProvisioningService missing leigod slave-preconnect reuse snippet: ' . $snippet);
    }
}

foreach ([
    "getInt('LEIGOD_PRECONNECT_RELAY_PORT_RANGE_START', 7000)",
    "getInt('LEIGOD_PRECONNECT_RELAY_PORT_RANGE_END', 8999)",
    'max(7000,',
    'min(8999,',
    '<= 9000',
    'reserved[$candidate + 1]',
    'No free LeiGod preconnect relay port in 7000-8999',
] as $boundary) {
    if (!str_contains($svc, $boundary)) {
        fail('ProvisioningService missing leigod preconnect port boundary: ' . $boundary);
    }
}

if (preg_match("/LEIGOD_PRECONNECT_RELAY_PORT_RANGE_(?:START|END)'\\s*,\\s*38\\d{3}/", $svc)) {
    fail('LeiGod preconnect relay range must not default into the 38000 preconnect range');
}

echo "OK\n";
exit(0);
