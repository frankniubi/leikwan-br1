<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require_once $path;
});

use Leikwan\Master\Support\LeigodOnlyArtifactConsistencyService;
use Leikwan\Master\Support\LeigodOnlyArtifactSourceBuildMap;

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assert_blocker(array $result, string $blocker): void { if (!in_array($blocker, $result['blockers'] ?? [], true)) fail('missing blocker ' . $blocker . ' in ' . json_encode($result['blockers'] ?? [])); }

final class ProvRouteProbe { public function __construct(public array $manifest) {} public function probe(string $path): array { if ($path === '/artifacts/versions.json') { $body = json_encode($this->manifest); return ['status' => 200, 'body' => $body, 'headers' => [], 'sha256' => hash('sha256', $body), 'size_bytes' => strlen($body)]; } foreach ($this->manifest as $entry) { if (($entry['artifact_url'] ?? '') === $path) { $bytes = 'bytes:' . $path; return ['status' => 200, 'body' => $bytes, 'headers' => [], 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]; } } return ['status' => 404, 'body' => '', 'headers' => [], 'sha256' => hash('sha256', ''), 'size_bytes' => 0]; } }
final class ProvMetadataProbe { public function __construct(public array $manifest) {} public function metadataForActivation(array $keys): array { return array_intersect_key($this->manifest, array_flip($keys)); } }
final class ProvSourceMap { public function __construct(public array $map, public array $requiresImmutable = []) {} public function activationCriticalMap(): array { return $this->map; } public function routeOnlyHelperMap(): array { return []; } public function sourceFingerprint(string $key): string { return 'fp-' . $key; } public function buildOutputSha256(string $key): string { return $this->map[$key]['sha256'] ?? ''; } public function requiresImmutableProvenance(string $key): bool { return in_array($key, $this->requiresImmutable, true); } }

function manifest_for(array $keys): array { $m = []; foreach ($keys as $key) { $path = match($key) { 'client_agent' => '/artifacts/client-agent.py', 'gost_linux_amd64' => '/artifacts/gost/gost_linux_amd64', 'leigod_linux_amd64' => '/artifacts/leigod/leigod-linux-amd64', 'lguot_linux_amd64' => '/artifacts/lguot/lguot_linux_amd64', 'leikwan_bond_linux_amd64' => '/artifacts/wstunnel/leikwan-bond_linux_amd64', default => '/artifacts/' . $key }; $bytes = 'bytes:' . $path; $m[$key] = ['artifact_url' => $path, 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]; } return $m; }
function result_with(array $keys, array $sourceMapOverride = [], array $contextOverride = [], array $requiresImmutable = []): array { $manifest = manifest_for($keys); $map = []; $prov = []; $pins = []; foreach ($keys as $key) { $map[$key] = ['route_path' => $manifest[$key]['artifact_url'], 'sha256' => $manifest[$key]['sha256']]; $prov[$key] = ['source_fingerprint' => 'fp-' . $key, 'build_output_sha256' => $manifest[$key]['sha256'], 'immutable_provenance' => in_array($key, $requiresImmutable, true) ? 'third-party-fixed-2026-07-19' : '']; $pins[$key] = $manifest[$key]['sha256']; } $map = array_replace_recursive($map, $sourceMapOverride); $ctx = array_replace_recursive(['test_mode' => true, 'platform_family' => 'linux-bond', 'activation_artifact_keys' => $keys, 'manifest_package' => $manifest, 'expected_pins' => $pins, 'source_build_provenance' => $prov], $contextOverride); return (new LeigodOnlyArtifactConsistencyService(new ProvRouteProbe($manifest), new ProvMetadataProbe($manifest), new ProvSourceMap($map, $requiresImmutable)))->validateForActivation($ctx); }
function result_with_context_replace(array $keys, array $contextOverride, array $requiresImmutable = []): array { $manifest = manifest_for($keys); $map = []; $prov = []; $pins = []; foreach ($keys as $key) { $map[$key] = ['route_path' => $manifest[$key]['artifact_url'], 'sha256' => $manifest[$key]['sha256']]; $prov[$key] = ['source_fingerprint' => 'fp-' . $key, 'build_output_sha256' => $manifest[$key]['sha256'], 'immutable_provenance' => in_array($key, $requiresImmutable, true) ? 'third-party-fixed-2026-07-19' : '']; $pins[$key] = $manifest[$key]['sha256']; } $ctx = array_replace(['test_mode' => true, 'platform_family' => 'linux-bond', 'activation_artifact_keys' => $keys, 'manifest_package' => $manifest, 'expected_pins' => $pins, 'source_build_provenance' => $prov], $contextOverride); return (new LeigodOnlyArtifactConsistencyService(new ProvRouteProbe($manifest), new ProvMetadataProbe($manifest), new ProvSourceMap($map, $requiresImmutable)))->validateForActivation($ctx); }

$map = new LeigodOnlyArtifactSourceBuildMap(dirname(__DIR__, 2));
foreach (['client_agent', 'gost_linux_amd64', 'leigod_linux_amd64', 'lguot_linux_amd64', 'leikwan_bond_linux_amd64', 'windows_client_app', 'leikwan_bond_windows_amd64'] as $key) {
    if (!isset($map->activationCriticalMap()[$key])) fail('activation-critical source map missing ' . $key);
}
foreach (['client_agent_update', 'sidecar_agent_update', 'slave_agent_update'] as $key) {
    if (!isset($map->routeOnlyHelperMap()[$key])) fail('route-only helper map missing ' . $key);
}
$fingerprint = $map->sourceFingerprint('client_agent');
if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint)) fail('source fingerprint must be sha256 hex');
if ($fingerprint !== $map->sourceFingerprint('client_agent')) fail('source fingerprint must be deterministic');

foreach (['client_agent', 'gost_linux_amd64', 'leigod_linux_amd64', 'lguot_linux_amd64'] as $key) {
    assert_blocker(result_with([$key], [$key => null]), 'source_build_mapping_missing:' . $key);
}
assert_blocker(result_with(['leikwan_bond_linux_amd64'], [], ['source_build_provenance' => ['leikwan_bond_linux_amd64' => ['source_fingerprint' => 'wrong', 'build_output_sha256' => hash('sha256', 'bytes:/artifacts/wstunnel/leikwan-bond_linux_amd64')]]]), 'source_fingerprint_mismatch:leikwan_bond_linux_amd64');
assert_blocker(result_with(['leikwan_bond_linux_amd64'], [], ['source_build_provenance' => ['leikwan_bond_linux_amd64' => ['source_fingerprint' => 'fp-leikwan_bond_linux_amd64', 'build_output_sha256' => str_repeat('0', 64)]]]), 'build_output_sha_mismatch:leikwan_bond_linux_amd64');
assert_blocker(result_with_context_replace(['gost_linux_amd64'], ['source_build_provenance' => ['gost_linux_amd64' => ['source_fingerprint' => 'fp-gost_linux_amd64', 'build_output_sha256' => hash('sha256', 'bytes:/artifacts/gost/gost_linux_amd64')]]], ['gost_linux_amd64']), 'immutable_provenance_missing:gost_linux_amd64');

echo "ok\n";
