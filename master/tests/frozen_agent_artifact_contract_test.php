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
        'version' => '2026.09.13.3',
        'sha256' => '0fc2a2e0df4193d43414ef1ce7ba88ad809534ae42daa26a714e69b08c5d0183',
        'size_bytes' => 8847520,
    ],
    'sidecar_agent_linux_amd64' => [
        'source' => 'sidecar-agent/sidecar_agent.py',
        'version' => '2026.09.17.2',
        'sha256' => 'cccb049e8ae1aa02d552dc72ee0bf48cceab0219add983dd3e482a66856925ff',
        'size_bytes' => 8648672,
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
