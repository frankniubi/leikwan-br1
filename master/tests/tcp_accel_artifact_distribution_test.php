<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function requireSnippet(string $source, string $snippet, string $context): void
{
    if (!str_contains($source, $snippet)) {
        fail($context . ' missing: ' . $snippet);
    }
}

$root = dirname(__DIR__, 2);
$bootstrap = (string) file_get_contents($root . '/master/src/Bootstrap.php');
$controller = (string) file_get_contents($root . '/master/src/Controllers/ArtifactController.php');
$sourceMap = (string) file_get_contents($root . '/master/src/Support/LeigodOnlyArtifactSourceBuildMap.php');
$manifest = json_decode((string) file_get_contents($root . '/artifacts/versions.json'), true);
if (!is_array($manifest)) {
    fail('artifacts/versions.json must be valid JSON');
}

$contracts = [
    'tcp_accel_runtime' => [
        'path' => 'tools/tcp-accel/tcp_accel.py',
        'route' => '/artifacts/leikwan-tcp-accel.py',
        'method' => 'tcpAccelRuntime',
    ],
    'tcp_accel_bridge_runtime' => [
        'path' => 'slave-agent/leigod_tcp_accel_bridge.py',
        'route' => '/artifacts/leikwan-tcp-accel-bridge.py',
        'method' => 'tcpAccelBridgeRuntime',
    ],
    'tcp_accel_installer' => [
        'path' => 'install/install-tcp-accel.sh',
        'route' => '/artifacts/install-tcp-accel.sh',
        'method' => 'installTcpAccel',
    ],
];

foreach ($contracts as $key => $contract) {
    $absolute = $root . '/' . $contract['path'];
    if (!is_file($absolute)) {
        fail($contract['path'] . ' missing');
    }
    requireSnippet($bootstrap, "'" . $contract['route'] . "'", 'Bootstrap route');
    requireSnippet($bootstrap, "'" . $contract['method'] . "'", 'Bootstrap handler');
    requireSnippet($controller, 'public function ' . $contract['method'], 'ArtifactController');
    requireSnippet($controller, "serveRepoFile('" . $contract['path'] . "'", 'ArtifactController source route');

    $entry = $manifest[$key] ?? null;
    if (!is_array($entry)) {
        fail('versions manifest missing ' . $key);
    }
    if (($entry['path'] ?? '') !== $contract['path'] || ($entry['artifact_url'] ?? '') !== $contract['route']) {
        fail($key . ' manifest path/url mismatch');
    }
    if (($entry['sha256'] ?? '') !== hash_file('sha256', $absolute)) {
        fail($key . ' manifest sha256 mismatch');
    }
    if ((int) ($entry['size_bytes'] ?? -1) !== (int) filesize($absolute)) {
        fail($key . ' manifest size mismatch');
    }
    requireSnippet($sourceMap, "'" . $key . "'", 'activation source/build map');
    requireSnippet($sourceMap, "'" . $contract['path'] . "'", 'activation source/build path');
    requireSnippet($sourceMap, "'" . $contract['route'] . "'", 'activation source/build route');
}

requireSnippet($controller, "'tcp_accel_runtime'", 'client/sidecar manifest');
requireSnippet($controller, "'tcp_accel_bridge_runtime'", 'slave manifest');
requireSnippet($controller, "'tcp_accel_installer'", 'role manifests');

$clientInstaller = (string) file_get_contents($root . '/install/install-client-agent.sh');
$slaveInstaller = (string) file_get_contents($root . '/install/install-slave.sh');
$sidecarInstaller = (string) file_get_contents($root . '/install/install-bond-sidecar.sh');
requireSnippet($clientInstaller, '"$BIN_DIR/install-tcp-accel.sh" client', 'client installer tcp-accel chaining');
requireSnippet($slaveInstaller, '"$BIN_DIR/install-tcp-accel.sh" slave', 'slave installer tcp-accel chaining');
requireSnippet($sidecarInstaller, '"$BIN_DIR/install-tcp-accel.sh" sidecar', 'sidecar installer tcp-accel chaining');

echo "ok\n";
