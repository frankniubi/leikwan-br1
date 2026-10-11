<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

$root = dirname(__DIR__, 2);
$manifestPath = $root . '/artifacts/versions.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($manifest)) fail('versions.json is not valid JSON');

$expected = [
    'client_agent_linux_amd64' => [
        'source' => 'client-agent/client_agent.py',
        'version' => '2026.09.17.4',
        'sha256' => 'd0e74d4af3d421c07f8bb309c9abead855dcb95bffb7e488fc5eb16e013cd9cb',
        'size_bytes' => 8849296,
    ],
    'sidecar_agent_linux_amd64' => [
        'source' => 'sidecar-agent/sidecar_agent.py',
        'version' => '2026.09.17.4',
        'sha256' => '3042f95685a6aead408e5b23400caab5c02e9e72fe1a708137d444d8690c0e43',
        'size_bytes' => 8641784,
    ],
];

foreach ($expected as $key => $contract) {
    $entry = $manifest[$key] ?? null;
    if (!is_array($entry)) fail("missing manifest entry $key");
    if (($entry['architecture'] ?? '') !== 'amd64') fail("$key architecture is not amd64");
    if (($entry['format'] ?? '') !== 'elf-onefile-pyinstaller') fail("$key format is not frozen ELF");
    if (($entry['runtime_mode'] ?? '') !== 'frozen') fail("$key runtime mode is not frozen");
    if (($entry['version'] ?? '') !== $contract['version']) fail("$key version mismatch");
    $path = $root . '/' . ($entry['path'] ?? '');
    if (!is_file($path)) fail("$key artifact is missing at {$entry['path']}");
    $actualSize = filesize($path);
    $actualSha = hash_file('sha256', $path);
    if ($actualSize !== $contract['size_bytes'] || (string) $entry['size_bytes'] !== (string) $actualSize) fail("$key size mismatch");
    if (!hash_equals($contract['sha256'], (string) $actualSha) || !hash_equals($contract['sha256'], (string) ($entry['sha256'] ?? ''))) fail("$key sha256 mismatch");
    $source = (string) file_get_contents($root . '/' . $contract['source']);
    if (!preg_match('/AGENT_VERSION\s*=\s*[\"\']([^\"\']+)[\"\']/', $source, $match) || $match[1] !== $contract['version']) fail("$key source version mismatch");
}

$artifact = (string) file_get_contents($root . '/master/src/Controllers/ArtifactController.php');
$bootstrap = (string) file_get_contents($root . '/master/src/Bootstrap.php');
foreach ([
    "'client_agent_linux_amd64'",
    "'sidecar_agent_linux_amd64'",
    '/artifacts/python-agent/leikwan-client-agent_linux_amd64',
    '/artifacts/python-agent/leikwan-sidecar-agent_linux_amd64',
] as $needle) {
    if (!str_contains($artifact . $bootstrap, $needle)) fail('missing frozen artifact route/manifest marker: ' . $needle);
}

echo "frozen_agent_artifact_contract_test: PASS\n";
