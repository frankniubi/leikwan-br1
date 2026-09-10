<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/master/src/Controllers/ArtifactController.php');
$bootstrap = (string) file_get_contents($root . '/master/src/Bootstrap.php');
$installer = (string) file_get_contents($root . '/install/install-client-agent.sh');
$windowsBuilder = (string) file_get_contents($root . '/scripts/build-windows-client-setup.ps1');
$windowsHelper = (string) file_get_contents($root . '/scripts/windows-client-install-helper.ps1');
$map = (string) file_get_contents($root . '/master/src/Support/LeigodOnlyArtifactSourceBuildMap.php');
$manifest = json_decode((string) file_get_contents($root . '/artifacts/versions.json'), true);

foreach ([
    'public function ultraClient',
    "serveRepoFile('artifacts/ultra/ultra-client_linux_amd64'",
    'public function ultraClientWindows',
    "serveRepoFile('artifacts/ultra/ultra-client_windows_amd64.exe'",
    'public function ultraClientMacosX64',
    "serveRepoFile('artifacts/ultra/ultra-client_macos_amd64'",
    'public function ultraClientMacosArm64',
    "serveRepoFile('artifacts/ultra/ultra-client_macos_arm64'",
] as $needle) {
    if (!str_contains($controller, $needle)) {
        fwrite(STDERR, "missing controller artifact route: {$needle}\n");
        exit(1);
    }
}
foreach ([
    "'/artifacts/ultra/ultra-client_linux_amd64'",
    'ultraClient',
    "'/artifacts/ultra/ultra-client_windows_amd64.exe'",
    'ultraClientWindows',
    "'/artifacts/ultra/ultra-client_macos_amd64'",
    'ultraClientMacosX64',
    "'/artifacts/ultra/ultra-client_macos_arm64'",
    'ultraClientMacosArm64',
] as $needle) {
    if (!str_contains($bootstrap, $needle)) {
        fwrite(STDERR, "missing bootstrap artifact route: {$needle}\n");
        exit(1);
    }
}
if (!str_contains($installer, 'artifacts/ultra/ultra-client_linux_amd64') || !str_contains($installer, 'ultra-client')) {
    fwrite(STDERR, "client installer does not distribute ultra runtime\n");
    exit(1);
}
if (!str_contains($map, "'ultra_client_runtime'") || !str_contains($map, "'/artifacts/ultra/ultra-client_linux_amd64'")) {
    fwrite(STDERR, "artifact source map does not include ultra runtime\n");
    exit(1);
}
$windowsSourcePath = $root . '/artifacts/ultra/ultra-client_windows_amd64.exe';
if (!str_contains($map, "'ultra_client_runtime_windows'")
    || !str_contains($map, "'/artifacts/ultra/ultra-client_windows_amd64.exe'")
    || !str_contains($windowsBuilder, 'ultra-client_windows_amd64.exe')
    || !str_contains($windowsBuilder, 'ultra-client.exe')
    || !str_contains($windowsHelper, 'ultra-client.exe')
    || !is_file($windowsSourcePath)) {
    fwrite(STDERR, "Windows Ultra runtime packaging is incomplete\n");
    exit(1);
}
$macosSourcePaths = [
    'ultra_client_runtime_macos_x64' => $root . '/artifacts/ultra/ultra-client_macos_amd64',
    'ultra_client_runtime_macos_arm64' => $root . '/artifacts/ultra/ultra-client_macos_arm64',
];
foreach ($macosSourcePaths as $key => $sourcePath) {
    $route = $key === 'ultra_client_runtime_macos_x64'
        ? '/artifacts/ultra/ultra-client_macos_amd64'
        : '/artifacts/ultra/ultra-client_macos_arm64';
    $method = $key === 'ultra_client_runtime_macos_x64' ? 'ultraClientMacosX64' : 'ultraClientMacosArm64';
    if (!str_contains($map, "'{$key}'")
        || !str_contains($map, "'{$route}'")
        || !str_contains($controller, "public function {$method}")
        || !is_file($sourcePath)) {
        fwrite(STDERR, "macOS Ultra runtime packaging is incomplete for {$key}\n");
        exit(1);
    }
    $entry = is_array($manifest) ? ($manifest[$key] ?? null) : null;
    if (!is_array($entry)
        || ($entry['sha256'] ?? '') !== hash_file('sha256', $sourcePath)
        || (int) ($entry['size_bytes'] ?? 0) !== filesize($sourcePath)) {
        fwrite(STDERR, "macOS Ultra runtime manifest hash or size mismatch for {$key}\n");
        exit(1);
    }
}
$windowsEntry = is_array($manifest) ? ($manifest['ultra_client_runtime_windows'] ?? null) : null;
if (!is_array($windowsEntry)
    || ($windowsEntry['sha256'] ?? '') !== hash_file('sha256', $windowsSourcePath)
    || (int) ($windowsEntry['size_bytes'] ?? 0) !== filesize($windowsSourcePath)) {
    fwrite(STDERR, "Windows Ultra runtime manifest hash or size mismatch\n");
    exit(1);
}
$entry = is_array($manifest) ? ($manifest['ultra_client_runtime'] ?? null) : null;
$sourcePath = $root . '/artifacts/ultra/ultra-client_linux_amd64';
if (!is_array($entry) || ($entry['sha256'] ?? '') !== hash_file('sha256', $sourcePath) || (int) ($entry['size_bytes'] ?? 0) !== filesize($sourcePath)) {
    fwrite(STDERR, "ultra runtime manifest hash or size mismatch\n");
    exit(1);
}
echo "ok\n";
