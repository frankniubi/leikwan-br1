<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = __DIR__ . '/..';

// 1. schema + migration carry the leigod_uot_impl column (gost default, lguot opt-in).
$schema = file_get_contents($root . '/migrations/schema.sql') ?: '';
if (!str_contains($schema, 'leigod_uot_impl')) {
    fail('schema.sql missing leigod_uot_impl column');
}
$migration = file_get_contents($root . '/migrations/20260603_add_forward_combination_leigod_uot_impl.sql') ?: '';
foreach (['ALTER TABLE forward_combinations', 'leigod_uot_impl', "ENUM('gost','lguot')", "DEFAULT 'gost'"] as $snippet) {
    if (!str_contains($migration, $snippet)) {
        fail('migration missing snippet: ' . $snippet);
    }
}

// 2. ProvisioningService publishes the SAME impl to BOTH ends of the lane, defaulting to gost.
$svc = file_get_contents($root . '/src/Support/ProvisioningService.php') ?: '';
foreach ([
    "\$leigodUotImpl = strtolower((string) (\$combination['leigod_uot_impl'] ?? 'gost')) === 'lguot' ? 'lguot' : 'gost';",
    "\$slave['leigod_uot_impl'] = \$leigodUotImpl;",
    "\$sidecar['leigod_uot_impl'] = \$leigodUotImpl;",
    "\$slave['sidecar_gost_host'] = \$sidecarHost;",
    "\$slave['sidecar_gost_port'] = \$gostSocks;",
    "\$sidecar['gost_socks_listen_port'] = \$gostSocks;",
    "\$slave['sidecar_endpoint_host'] = '127.0.0.1';",
    "\$slave['sidecar_endpoint_port'] = \$slaveListenPort;",
] as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('ProvisioningService missing managed TYO lane snippet: ' . $snippet);
    }
}

foreach ([
    "if (is_array(\$staticSidecarUplink) && trim((string) (\$staticSidecarUplink['relay_target'] ?? '')) !== '')",
    "\$publishTarget = (string) \$staticSidecarUplink['relay_target'];",
    "'sidecar_uplink' => \$staticSidecarUplink ?? []",
    "'sidecar_uplink_selector' => \$staticSidecarSelector",
] as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('publishTaskPayloadForForward missing managed uplink target snippet: ' . $snippet);
    }
}

$requiredPayloadSnippets = [
    "'sidecar_uplink' => \$staticSidecarUplink ?? []",
    "'sidecar_uplink_selector' => \$staticSidecarSelector",
    "'mptcp_underlay' => \$clientMode === 'mptcp' ? \$this->buildMptcpSlaveUnderlayPayload",
    "\$publishTarget = (string) \$staticSidecarUplink['relay_target'];",
];
foreach ($requiredPayloadSnippets as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('publish payload missing managed TYO automation snippet: ' . $snippet);
    }
}

// 3. the lguot binary is served as a fleet artifact (route + controller + manifest).
$bootstrap = file_get_contents($root . '/src/Bootstrap.php') ?: '';
if (!str_contains($bootstrap, "/artifacts/lguot/lguot_linux_amd64")) {
    fail('Bootstrap missing lguot artifact route');
}
$artifactCtrl = file_get_contents($root . '/src/Controllers/ArtifactController.php') ?: '';
if (!str_contains($artifactCtrl, 'lguotBinary') || !str_contains($artifactCtrl, 'artifacts/lguot/lguot_linux_amd64')) {
    fail('ArtifactController missing lguotBinary()');
}
$versions = json_decode(file_get_contents($root . '/../artifacts/versions.json') ?: 'null', true);
if (!is_array($versions) || !isset($versions['lguot_linux_amd64']['sha256']) || $versions['lguot_linux_amd64']['sha256'] === '') {
    fail('versions.json missing lguot_linux_amd64 sha256');
}

// 4. both agents render the lguot units when the impl is selected.
$slaveAgent = file_get_contents($root . '/../slave-agent/slave_agent.py') ?: '';
foreach (['def ensure_sidecar_lguot_client_service', 'leigod_uot_impl', 'lguot client', '-token'] as $snippet) {
    if (!str_contains($slaveAgent, $snippet)) {
        fail('slave_agent.py missing snippet: ' . $snippet);
    }
}
// Carrier-swap correctness: a gost<->lguot swap leaves the WG .conf byte-identical, so the agent MUST
// force a fresh WG handshake (force_wg_reinit) or the new carrier sits behind a stale session (100% loss).
foreach (['force_wg_reinit', 'changed or not is_active or force_wg_reinit'] as $snippet) {
    if (!str_contains($slaveAgent, $snippet)) {
        fail('slave_agent.py missing carrier-swap WG re-handshake: ' . $snippet);
    }
}
// remove-before-add on the shared UoT port (avoid EADDRINUSE restart-storm on swap).
$lguotPos = strpos($slaveAgent, 'self.ensure_sidecar_lguot_client_service');
$removeGostPos = strpos($slaveAgent, 'self.remove_sidecar_gost_uot_client_service(interface_name)');
if ($lguotPos === false || $removeGostPos === false || $removeGostPos > $lguotPos) {
    fail('slave_agent.py must remove gost client BEFORE ensuring lguot client (remove-before-add)');
}
$sidecarAgent = file_get_contents($root . '/../sidecar-agent/sidecar_agent.py') ?: '';
foreach (['def ensure_sidecar_leigod_lguot_server_service', 'leigod_uot_impl', 'lguot server', '-wg 127.0.0.1:'] as $snippet) {
    if (!str_contains($sidecarAgent, $snippet)) {
        fail('sidecar_agent.py missing snippet: ' . $snippet);
    }
}

echo "OK\n";
exit(0);
