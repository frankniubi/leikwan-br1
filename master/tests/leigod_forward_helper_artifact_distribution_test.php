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
$consistencyService = (string) file_get_contents($root . '/master/src/Support/LeigodOnlyArtifactConsistencyService.php');
$manifest = json_decode((string) file_get_contents($root . '/artifacts/versions.json'), true);
if (!is_array($manifest)) {
    fail('artifacts/versions.json must be valid JSON');
}

$contracts = [
    'leigod_forward_relay' => [
        'path' => 'slave-agent/leigod_forward_relay.py',
        'route' => '/artifacts/leikwan-leigod-forward-relay.py',
        'method' => 'leigodForwardRelay',
    ],
    'tcp_race_runtime' => [
        'path' => 'client-agent/leikwan_tcp_race.py',
        'route' => '/artifacts/leikwan-tcp-race.py',
        'method' => 'tcpRaceRuntime',
    ],
    'udp_fanout_runtime' => [
        'path' => 'client-agent/leikwan_udp_fanout.py',
        'route' => '/artifacts/leikwan-udp-fanout.py',
        'method' => 'udpFanoutRuntime',
    ],
];

foreach ($contracts as $key => $contract) {
    $absolute = $root . '/' . $contract['path'];
    if (!is_file($absolute)) {
        fail($contract['path'] . ' missing');
    }
    requireSnippet($bootstrap, "'/artifacts/" . basename($contract['route']) . "'", 'Bootstrap route');
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
}

requireSnippet($controller, "'leigod_forward_relay'", 'slave manifest');
requireSnippet($controller, "'tcp_race_runtime'", 'client manifest');
requireSnippet($controller, "'udp_fanout_runtime'", 'client manifest');
requireSnippet($sourceMap, "'leigod_forward_relay'", 'activation source/build map');
requireSnippet($sourceMap, "'slave-agent/leigod_forward_relay.py'", 'relay source/build path');
requireSnippet($consistencyService, "'client_agent', 'leigod_forward_relay'", 'default activation artifact gate');

$installerPath = $root . '/install/install-slave.sh';
$installerEntry = $manifest['slave_installer'] ?? null;
if (!is_array($installerEntry)
    || ($installerEntry['sha256'] ?? '') !== hash_file('sha256', $installerPath)
    || (int) ($installerEntry['size_bytes'] ?? -1) !== (int) filesize($installerPath)) {
    fail('slave_installer manifest sha/size must match install/install-slave.sh');
}

echo "ok\n";
