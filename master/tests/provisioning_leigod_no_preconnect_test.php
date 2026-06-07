<?php
declare(strict_types=1);

// Guard: leigod_mode is DIRECT-ingress, so the per-forward slave PRE-CONNECT relay must NOT be
// emitted into the MPTCP client config. The Rust relay's tcp_remote_addr() prefers slave_relay_host
// over remote_host, so emitting slave_relay_host for a leigod forward makes the relay MP_CAPABLE-dial
// the dead preconnect target instead of the proven sidecar overlay -> ETIMEDOUT. buildMptcpClientConfig
// + mptcpUnderlayLegs must gate slave_relay_host/slave_relay_port + slave_preconnect/preconnect_relay_port
// on the forward NOT being leigod_mode.

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$svc = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';

foreach ([
    // the leigod_mode predicate is computed in both builders
    "\$leigodDirect = strtolower(trim((string) (\$forward['backend_mode'] ?? ''))) === 'leigod_mode';",
    // leigod slave-preconnect reuse is explicitly opt-in, disabled by default
    "\$leigodPreconnectReuse = \$leigodDirect && \$this->config->getBool('LEIGOD_SLAVE_PRECONNECT_REUSE', false);",
    // top-level slave relay suppressed for leigod
    "\$this->config->getBool('MPTCP_SLAVE_PRECONNECT', false) && !\$leigodDirect && \$underlayLegs !== []",
    // per-leg slave_preconnect stays suppressed for leigod unless the new opt-in is true
    "'slave_preconnect' => \$leigodPreconnectReuse ? true : \$this->config->getBool('MPTCP_SLAVE_PRECONNECT', false) && !\$leigodDirect,",
    // per-leg preconnect port stays zeroed for leigod unless the new opt-in is true
    "'preconnect_relay_port' => \$leigodPreconnectReuse ? \$leigodPreconnectPort : (",
    "\$leigodDirect ? 0 : \$this->stablePreconnectRelayPort(",
] as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('ProvisioningService missing leigod-no-preconnect snippet: ' . $snippet);
    }
}

echo "OK\n";
exit(0);
