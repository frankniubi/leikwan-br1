<?php
declare(strict_types=1);

// Contract for the windowless (no-GUI) client surface: the published payloads
// must exist and match the manifest, the installers must be served, and no
// installer may ever carry a credential.

function failHeadless(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__, 2);
$versions = json_decode((string) file_get_contents($root . '/artifacts/versions.json'), true);
if (!is_array($versions)) {
    failHeadless('versions.json is not readable');
}

$artifacts = [
    'windows_headless_client' => ['artifacts/windows/leikwan-bridge-headless-win-x64.zip', 'artifact_url'],
    'macos_headless_installer' => ['install/install-macos-headless.sh', 'install_url'],
    'macos_headless_uninstaller' => ['install/uninstall-macos-headless.sh', 'install_url'],
    'windows_headless_installer' => ['install/install-windows-headless.ps1', 'install_url'],
    'windows_headless_uninstaller' => ['install/uninstall-windows-headless.ps1', 'install_url'],
];
foreach ($artifacts as $key => [$relative, $urlField]) {
    $entry = $versions[$key] ?? null;
    if (!is_array($entry)) {
        failHeadless('manifest entry missing: ' . $key);
    }
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        failHeadless('published file missing: ' . $relative);
    }
    if (($entry['path'] ?? '') !== $relative) {
        failHeadless('manifest path mismatch: ' . $key);
    }
    if (($entry['sha256'] ?? '') !== hash_file('sha256', $path)) {
        failHeadless('manifest hash mismatch: ' . $key);
    }
    if ((int) ($entry['size_bytes'] ?? 0) !== filesize($path)) {
        failHeadless('manifest size mismatch: ' . $key);
    }
    if (!is_string($entry[$urlField] ?? null) || !str_starts_with((string) $entry[$urlField], '/artifacts/')) {
        failHeadless('manifest url missing: ' . $key);
    }
    if (($entry['version'] ?? '') === '') {
        failHeadless('manifest version missing: ' . $key);
    }
}

if (($versions['windows_headless_client']['format'] ?? '') !== 'zip') {
    failHeadless('windows headless payload must be a zip');
}

$bootstrap = (string) file_get_contents($root . '/master/src/Bootstrap.php');
foreach ([
    "'/artifacts/windows/leikwan-bridge-headless-win-x64.zip'",
    "'/artifacts/install-macos-headless.sh'",
    "'/artifacts/uninstall-macos-headless.sh'",
    "'/artifacts/install-windows-headless.ps1'",
    "'/artifacts/uninstall-windows-headless.ps1'",
] as $route) {
    if (!str_contains($bootstrap, $route)) {
        failHeadless('missing Bootstrap route ' . $route);
    }
}

$controller = (string) file_get_contents($root . '/master/src/Controllers/ArtifactController.php');
foreach ([
    'public function windowsHeadlessClient',
    'public function installMacosHeadless',
    'public function uninstallMacosHeadless',
    'public function installWindowsHeadless',
    'public function uninstallWindowsHeadless',
] as $method) {
    if (!str_contains($controller, $method)) {
        failHeadless('missing ArtifactController surface ' . $method);
    }
}

$macInstaller = (string) file_get_contents($root . '/install/install-macos-headless.sh');
foreach (['--headless', 'com.leikwan.bridge.headless', '/Library/LaunchDaemons', 'chmod 600', 'SuccessfulExit', '--agent-token-file'] as $needle) {
    if (!str_contains($macInstaller, $needle)) {
        failHeadless('macOS headless installer missing: ' . $needle);
    }
}
if (str_contains($macInstaller, 'agent-token=') || str_contains($macInstaller, 'Authorization:')) {
    failHeadless('macOS headless installer embeds credential material');
}

$winInstaller = (string) file_get_contents($root . '/install/install-windows-headless.ps1');
foreach (['--install-service', '--agent-token-file', 'icacls.exe', 'LocalSystem', 'headless-status.json'] as $needle) {
    if (!str_contains($winInstaller, $needle)) {
        failHeadless('Windows headless installer missing: ' . $needle);
    }
}
foreach (['agent-token=', 'PlainText', 'ConvertTo-SecureString'] as $forbidden) {
    if (str_contains($winInstaller, $forbidden)) {
        failHeadless('Windows headless installer leaks credentials: ' . $forbidden);
    }
}

// The service must not carry the token on its command line: "sc qc" is readable
// by any authenticated user.
if (!str_contains($winInstaller, 'Set-Content -LiteralPath $tokenPath')) {
    failHeadless('Windows headless installer must store the token in a file');
}

echo "ok\n";
