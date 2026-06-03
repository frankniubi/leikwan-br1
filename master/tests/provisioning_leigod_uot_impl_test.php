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
] as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('ProvisioningService missing snippet: ' . $snippet);
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
$sidecarAgent = file_get_contents($root . '/../sidecar-agent/sidecar_agent.py') ?: '';
foreach (['def ensure_sidecar_leigod_lguot_server_service', 'leigod_uot_impl', 'lguot server', '-wg 127.0.0.1:'] as $snippet) {
    if (!str_contains($sidecarAgent, $snippet)) {
        fail('sidecar_agent.py missing snippet: ' . $snippet);
    }
}

echo "OK\n";
exit(0);
