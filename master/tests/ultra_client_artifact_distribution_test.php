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
if (!str_contains($windowsBuilder, 'ultra-client_windows_amd64.exe') || !str_contains($windowsBuilder, 'ultra-client.exe') || !str_contains($windowsBuilder, 'ultra_client_runtime_windows')) {
    fwrite(STDERR, "Windows builder does not stage the Ultra runtime\n");
    exit(1);
}
if (!str_contains($windowsBuilder, '$_.Extension -ne ".pdb"')) {
    fwrite(STDERR, "Windows builder must exclude PDB symbols from the distributable stage\n");
    exit(1);
}
if (!str_contains($windowsHelper, 'ultra-client.exe')) {
    fwrite(STDERR, "Windows installer helper does not retain the Ultra runtime\n");
    exit(1);
}
if (!str_contains($map, "'ultra_client_runtime'") || !str_contains($map, "'/artifacts/ultra/ultra-client_linux_amd64'")) {
    fwrite(STDERR, "artifact source map does not include ultra runtime\n");
    exit(1);
}
if (!str_contains($map, "'ultra_client_runtime_windows'") || !str_contains($map, "'/artifacts/ultra/ultra-client_windows_amd64.exe'")) {
    fwrite(STDERR, "artifact source map does not include Windows ultra runtime\n");
    exit(1);
}
$entry = is_array($manifest) ? ($manifest['ultra_client_runtime'] ?? null) : null;
$sourcePath = $root . '/artifacts/ultra/ultra-client_linux_amd64';
if (!is_array($entry) || ($entry['sha256'] ?? '') !== hash_file('sha256', $sourcePath) || (int) ($entry['size_bytes'] ?? 0) !== filesize($sourcePath)) {
    fwrite(STDERR, "ultra runtime manifest hash or size mismatch\n");
    exit(1);
}
$windowsEntry = is_array($manifest) ? ($manifest['ultra_client_runtime_windows'] ?? null) : null;
$windowsSourcePath = $root . '/artifacts/ultra/ultra-client_windows_amd64.exe';
if (!is_array($windowsEntry) || ($windowsEntry['sha256'] ?? '') !== hash_file('sha256', $windowsSourcePath) || (int) ($windowsEntry['size_bytes'] ?? 0) !== filesize($windowsSourcePath)) {
    fwrite(STDERR, "Windows ultra runtime manifest hash or size mismatch\n");
    exit(1);
}
echo "ok\n";
