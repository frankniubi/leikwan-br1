<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/master/src/Controllers/ArtifactController.php');
$bootstrap = (string) file_get_contents($root . '/master/src/Bootstrap.php');
$installer = (string) file_get_contents($root . '/install/install-client-agent.sh');
$map = (string) file_get_contents($root . '/master/src/Support/LeigodOnlyArtifactSourceBuildMap.php');
$manifest = json_decode((string) file_get_contents($root . '/artifacts/versions.json'), true);

foreach ([
    'public function ultraClient',
    "serveRepoFile('artifacts/ultra/ultra-client_linux_amd64'",
] as $needle) {
    if (!str_contains($controller, $needle)) {
        fwrite(STDERR, "missing controller artifact route: {$needle}\n");
        exit(1);
    }
}
foreach ([
    "'/artifacts/ultra/ultra-client_linux_amd64'",
    'ultraClient',
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
$entry = is_array($manifest) ? ($manifest['ultra_client_runtime'] ?? null) : null;
$sourcePath = $root . '/artifacts/ultra/ultra-client_linux_amd64';
if (!is_array($entry) || ($entry['sha256'] ?? '') !== hash_file('sha256', $sourcePath) || (int) ($entry['size_bytes'] ?? 0) !== filesize($sourcePath)) {
    fwrite(STDERR, "ultra runtime manifest hash or size mismatch\n");
    exit(1);
}
echo "ok\n";
