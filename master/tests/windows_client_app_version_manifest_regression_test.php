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
$csproj = file_get_contents($repoRoot . '/LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj');

if (!is_array($versions)) {
    fail('failed to parse versions manifest');
}

if (!is_string($csproj) || !preg_match('/<FileVersion>([^<]+)<\/FileVersion>/', $csproj, $m)) {
    fail('failed to read Windows app FileVersion from csproj');
}

$manifestVersion = (string) ($versions['windows_client_app']['version'] ?? '');
$fileVersion = trim($m[1]);
$normalizedManifest = preg_replace('/\.0$/', '', $manifestVersion);
$normalizedFile = preg_replace('/\.0$/', '', $fileVersion);

if ($normalizedManifest !== $normalizedFile) {
    fail('windows_client_app.version must match LeikwanBridge.App FileVersion');
}

foreach (['sha256', 'size_bytes'] as $field) {
    if (!array_key_exists($field, $versions['windows_client_app'] ?? [])) {
        fail('windows_client_app missing ' . $field);
    }
}

echo "ok\n";
