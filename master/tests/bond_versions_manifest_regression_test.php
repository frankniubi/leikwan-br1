<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$versionsRaw = file_get_contents($repoRoot . '/artifacts/versions.json');
$versions = is_string($versionsRaw) ? json_decode($versionsRaw, true) : null;
$cargoToml = file_get_contents($repoRoot . '/wstunnel-main/leikwan-bond/Cargo.toml');

if (!is_array($versions)) {
    fail('failed to parse versions manifest');
}

if (!is_string($cargoToml) || !preg_match('/^version = "([^"]+)"/m', $cargoToml, $bondVersionMatch)) {
    fail('failed to read leikwan-bond Cargo.toml version');
}

$expectedVersion = $bondVersionMatch[1];
if (($versions['leikwan_bond_linux_amd64']['version'] ?? null) !== $expectedVersion) {
    fail('leikwan_bond_linux_amd64.version must match leikwan-bond Cargo.toml version');
}

foreach (['leikwan_bond_linux_amd64', 'leikwan_bond_windows_amd64'] as $artifactKey) {
    $entry = $versions[$artifactKey] ?? null;
    $relativePath = is_array($entry) ? ($entry['path'] ?? null) : null;
    $artifactPath = is_string($relativePath) ? $repoRoot . '/' . $relativePath : '';
    if (!is_file($artifactPath)) {
        fail($artifactKey . ' artifact is missing');
    }
    if (($entry['sha256'] ?? null) !== hash_file('sha256', $artifactPath)) {
        fail($artifactKey . '.sha256 must match the platform artifact');
    }
    if ((int) ($entry['size_bytes'] ?? -1) !== filesize($artifactPath)) {
        fail($artifactKey . '.size_bytes must match the platform artifact');
    }
}

$windowsVersion = (string) ($versions['leikwan_bond_windows_amd64']['version'] ?? '');
if (!preg_match('/^\d+\.\d+\.\d+$/', $windowsVersion)) {
    fail('leikwan_bond_windows_amd64.version must be an independent semantic version');
}
if (PHP_OS_FAMILY === 'Windows') {
    $windowsPath = $repoRoot . '/' . $versions['leikwan_bond_windows_amd64']['path'];
    $output = [];
    $exitCode = 0;
    exec(escapeshellarg($windowsPath) . ' --version', $output, $exitCode);
    $reported = implode("\n", $output);
    if ($exitCode !== 0 || !preg_match('/leikwan-bond\s+([^\s]+)/', $reported, $match) || $match[1] !== $windowsVersion) {
        fail('leikwan_bond_windows_amd64.version must match its executable');
    }
}

echo "ok\n";
