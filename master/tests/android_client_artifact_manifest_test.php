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
$packager = $root . '/scripts/package-android-native-test.ps1';

if (!preg_match('/\bversionCode\s*=\s*(\d+)/', $gradle, $codeMatch)
    || !preg_match('/\bversionName\s*=\s*"([^"]+)"/', $gradle, $nameMatch)) {
    failAndroidArtifact('Android app version is missing from build.gradle.kts');
}
$gradleVersionCode = (int) $codeMatch[1];
$gradleVersionName = $nameMatch[1];

if (!is_array($versions) || !is_array($versions['android_client_apk'] ?? null)) {
    failAndroidArtifact('Android artifact manifest entry missing');
}
$entry = $versions['android_client_apk'];
if (($entry['version'] ?? '') !== $gradleVersionName
    || (int) ($entry['version_code'] ?? 0) !== $gradleVersionCode
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
