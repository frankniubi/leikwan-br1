<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\LeigodOnlyArtifactSourceBuildMap;
use Leikwan\Master\Support\LeigodOnlyArtifactConsistencyService;
use Leikwan\Master\Config;
use Leikwan\Master\Controllers\ArtifactController;
use Leikwan\Master\Http\Request;

function failPathdDistribution(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$manifest = json_decode((string) file_get_contents($repoRoot . '/artifacts/versions.json'), true);
$entry = is_array($manifest) ? ($manifest['leikwan_pathd_linux_amd64'] ?? null) : null;
if (!is_array($entry)) {
    failPathdDistribution('leikwan_pathd_linux_amd64 manifest entry missing');
}

$artifactPath = $repoRoot . '/' . (string) ($entry['path'] ?? '');
$cargoToml = (string) file_get_contents($repoRoot . '/wstunnel-main/leikwan-bond/Cargo.toml');
if (!preg_match('/^version = "([^"]+)"/m', $cargoToml, $versionMatch)) {
    failPathdDistribution('leikwan-bond Cargo version missing');
}
if (($entry['version'] ?? null) !== $versionMatch[1]
    || ($entry['path'] ?? null) !== 'artifacts/wstunnel/leikwan-pathd_linux_amd64'
    || ($entry['artifact_url'] ?? null) !== '/artifacts/wstunnel/leikwan-pathd_linux_amd64') {
    failPathdDistribution('pathd manifest identity/version mismatch');
}
if (!is_file($artifactPath)
    || ($entry['sha256'] ?? null) !== hash_file('sha256', $artifactPath)
    || (int) ($entry['size_bytes'] ?? -1) !== filesize($artifactPath)) {
    failPathdDistribution('pathd artifact bytes do not match manifest');
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-pathd-artifact-');
if ($envPath === false) {
    failPathdDistribution('failed to create artifact test environment');
}
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
$controller = new ArtifactController(new Config($envPath));
$artifactResponse = $controller->wstunnelPackage(
    new Request('GET', (string) $entry['artifact_url'], [], [], [], ''),
    ['filename' => 'leikwan-pathd_linux_amd64'],
);
if ($artifactResponse->status() !== 200
    || hash('sha256', $artifactResponse->body()) !== $entry['sha256']) {
    failPathdDistribution('pathd artifact route did not serve manifest bytes');
}
$clientManifestResponse = $controller->clientManifest(
    new Request('GET', '/artifacts/client-manifest.json', [], [], [], ''),
    [],
);
$clientManifest = json_decode($clientManifestResponse->body(), true);
if (($clientManifest['manifest']['leikwan_pathd_linux_amd64']['sha256'] ?? null) !== $entry['sha256']) {
    failPathdDistribution('client manifest does not publish pathd pin');
}
@unlink($envPath);

$sourceMap = (new LeigodOnlyArtifactSourceBuildMap($repoRoot))->activationCriticalMap();
$mapping = $sourceMap['leikwan_pathd_linux_amd64'] ?? null;
if (!is_array($mapping)
    || ($mapping['route_path'] ?? null) !== '/artifacts/wstunnel/leikwan-pathd_linux_amd64') {
    failPathdDistribution('pathd source/build mapping missing');
}
$paths = is_array($mapping['paths'] ?? null) ? $mapping['paths'] : [];
foreach (['wstunnel-main/leikwan-bond', 'artifacts/wstunnel/leikwan-pathd_linux_amd64'] as $requiredPath) {
    if (!in_array($requiredPath, $paths, true)) {
        failPathdDistribution('pathd provenance path missing: ' . $requiredPath);
    }
}

$controllerSource = (string) file_get_contents($repoRoot . '/master/src/Controllers/ArtifactController.php');
$bootstrapSource = (string) file_get_contents($repoRoot . '/master/src/Bootstrap.php');
$consistencySource = (string) file_get_contents($repoRoot . '/master/src/Support/LeigodOnlyArtifactConsistencyService.php');
if (!str_contains($controllerSource, "'leikwan_pathd_linux_amd64'")
    || !str_contains($bootstrapSource, "'/artifacts/wstunnel/{filename}'")
    || substr_count($consistencySource, "'leikwan_pathd_linux_amd64'") < 2) {
    failPathdDistribution('pathd route, client manifest, or pin gate missing');
}

final class PathdDistributionRouteProbe
{
    public function __construct(private array $manifest) {}
    public function probe(string $path): array
    {
        if ($path === '/artifacts/versions.json') {
            $body = (string) json_encode($this->manifest);
            return ['status' => 200, 'body' => $body, 'sha256' => hash('sha256', $body), 'size_bytes' => strlen($body)];
        }
        $body = 'pathd-bytes';
        return ['status' => 200, 'body' => $body, 'sha256' => hash('sha256', $body), 'size_bytes' => strlen($body)];
    }
}
final class PathdDistributionMetadataProbe
{
    public function __construct(private array $manifest) {}
    public function metadataForActivation(array $keys): array { return array_intersect_key($this->manifest, array_flip($keys)); }
}
final class PathdDistributionSourceMap
{
    public function __construct(private array $entry) {}
    public function activationCriticalMap(): array { return ['leikwan_pathd_linux_amd64' => ['route_path' => $this->entry['artifact_url'], 'platform_family' => 'linux-bond']]; }
    public function routeOnlyHelperMap(): array { return []; }
    public function sourceFingerprint(string $key): string { return 'pathd-source-fingerprint'; }
    public function buildOutputSha256(string $key): string { return (string) $this->entry['sha256']; }
    public function requiresImmutableProvenance(string $key): bool { return false; }
}

$syntheticBytes = 'pathd-bytes';
$syntheticEntry = [
    'artifact_url' => '/artifacts/wstunnel/leikwan-pathd_linux_amd64',
    'sha256' => hash('sha256', $syntheticBytes),
    'size_bytes' => strlen($syntheticBytes),
];
$syntheticManifest = ['leikwan_pathd_linux_amd64' => $syntheticEntry];
$gate = new LeigodOnlyArtifactConsistencyService(
    new PathdDistributionRouteProbe($syntheticManifest),
    new PathdDistributionMetadataProbe($syntheticManifest),
    new PathdDistributionSourceMap($syntheticEntry),
);
$gateResult = $gate->validateForActivation([
    'test_mode' => true,
    'platform_family' => 'linux-bond',
    'activation_artifact_keys' => ['leikwan_pathd_linux_amd64'],
    'manifest_package' => $syntheticManifest,
    'expected_pins' => ['leikwan_pathd_linux_amd64' => $syntheticEntry['sha256']],
    'source_build_provenance' => ['leikwan_pathd_linux_amd64' => [
        'source_fingerprint' => 'pathd-source-fingerprint',
        'build_output_sha256' => $syntheticEntry['sha256'],
    ]],
    'expected_capabilities' => ['now' => 1000, 'fresh_within_seconds' => 60],
    'capability_reports' => [[
        'artifact_key' => 'leikwan_pathd_linux_amd64',
        'role' => 'client',
        'platform' => 'linux-amd64',
        'reported_at' => 1000,
        'artifact_sha256' => str_repeat('f', 64),
    ]],
]);
if (!in_array('capability_gate_failed:leikwan_pathd_linux_amd64:hash_mismatch', $gateResult['blockers'] ?? [], true)) {
    failPathdDistribution('pathd capability hash mismatch did not fail closed');
}

echo "ok\n";
