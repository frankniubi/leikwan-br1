<?php
declare(strict_types=1);

function failAndroidArtifact(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__, 2);
$gradle = (string) file_get_contents($root . '/leikwan-android/app/build.gradle.kts');
$versions = json_decode((string) file_get_contents($root . '/artifacts/versions.json'), true);
$artifact = $root . '/artifacts/android/leikwan-android-client.apk';
$packager = $root . '/scripts/package-android-client.ps1';

if (!str_contains($gradle, 'versionCode = 16') || !str_contains($gradle, 'versionName = "0.2.14"')) {
    failAndroidArtifact('Android app version must be 0.2.14 (16)');
}
if (!is_array($versions) || !is_array($versions['android_client_apk'] ?? null)) {
    failAndroidArtifact('Android artifact manifest entry missing');
}
$entry = $versions['android_client_apk'];
if (($entry['version'] ?? '') !== '0.2.14'
    || (int) ($entry['version_code'] ?? 0) !== 16
    || ($entry['path'] ?? '') !== 'artifacts/android/leikwan-android-client.apk'
    || ($entry['artifact_url'] ?? '') !== '/artifacts/leikwan-android-client.apk') {
    failAndroidArtifact('Android artifact manifest metadata mismatch');
}
if (!is_file($artifact)
    || ($entry['sha256'] ?? '') !== hash_file('sha256', $artifact)
    || (int) ($entry['size_bytes'] ?? 0) !== filesize($artifact)) {
    failAndroidArtifact('Android artifact hash or size mismatch');
}
if (!is_file($packager)) {
    failAndroidArtifact('signed Android packaging script missing');
}
$packagerSource = (string) file_get_contents($packager);
foreach (['zipalign', 'apksigner', 'sign', 'verify', 'verify-android-ultra-artifact.ps1'] as $required) {
    if (!str_contains($packagerSource, $required)) {
        failAndroidArtifact('Android packaging script missing: ' . $required);
    }
}

echo "ok\n";
