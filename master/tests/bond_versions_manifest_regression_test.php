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

if (($versions['leikwan_bond_windows_amd64']['version'] ?? null) !== $expectedVersion) {
    fail('leikwan_bond_windows_amd64.version must match leikwan-bond Cargo.toml version');
}

echo "ok\n";
