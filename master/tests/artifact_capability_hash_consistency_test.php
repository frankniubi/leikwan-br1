<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require_once $path;
});

use Leikwan\Master\Support\LeigodOnlyArtifactConsistencyService;

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function has_blocker(array $result, string $blocker): bool { return in_array($blocker, $result['blockers'] ?? [], true); }
function assert_blocker(array $result, string $blocker): void { if (!has_blocker($result, $blocker)) fail('missing blocker ' . $blocker . ' in ' . json_encode($result['blockers'] ?? [])); }

final class CapRouteProbe { public int $calls = 0; public function probe(string $path): array { $this->calls++; $body = artifact_manifest_json(); if ($path === '/artifacts/versions.json') return ['status' => 200, 'body' => $body, 'headers' => [], 'sha256' => hash('sha256', $body), 'size_bytes' => strlen($body)]; $bytes = artifact_bytes_for_path($path); return ['status' => 200, 'body' => $bytes, 'headers' => [], 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]; } }
final class CapMetadataProbe { public int $calls = 0; public function metadataForActivation(array $keys): array { $this->calls++; return array_intersect_key(artifact_manifest(), array_flip($keys)); } }
final class CapSourceMap { public function activationCriticalMap(): array { return artifact_source_map(); } public function routeOnlyHelperMap(): array { return []; } public function sourceFingerprint(string $key): string { return 'fp-' . $key; } public function buildOutputSha256(string $key): string { return artifact_manifest()[$key]['sha256'] ?? ''; } public function requiresImmutableProvenance(string $key): bool { return false; } }

function artifact_bytes_for_path(string $path): string { return match ($path) { '/artifacts/client-agent.py' => 'client', '/artifacts/wstunnel/leikwan-bond_linux_amd64' => 'linux-bond', '/artifacts/leikwan-bridge-setup.exe' => 'windows-app', '/artifacts/wstunnel/leikwan-bond_windows_amd64.exe' => 'windows-bond', default => 'x' }; }
function artifact_manifest(): array { return [
    'client_agent' => ['artifact_url' => '/artifacts/client-agent.py', 'sha256' => hash('sha256', 'client'), 'size_bytes' => 6],
    'leikwan_bond_linux_amd64' => ['artifact_url' => '/artifacts/wstunnel/leikwan-bond_linux_amd64', 'sha256' => hash('sha256', 'linux-bond'), 'size_bytes' => 10],
    'windows_client_app' => ['artifact_url' => '/artifacts/leikwan-bridge-setup.exe', 'sha256' => hash('sha256', 'windows-app'), 'size_bytes' => 11],
    'leikwan_bond_windows_amd64' => ['artifact_url' => '/artifacts/wstunnel/leikwan-bond_windows_amd64.exe', 'sha256' => hash('sha256', 'windows-bond'), 'size_bytes' => 12],
    'leikwan_mptcp_relay_linux_amd64' => ['artifact_url' => '/artifacts/wstunnel/leikwan-mptcp-relay_0.1.0_linux_amd64', 'sha256' => str_repeat('a', 64)],
]; }
function artifact_manifest_json(): string { return json_encode(artifact_manifest(), JSON_UNESCAPED_SLASHES); }
function artifact_source_map(): array { return [
    'client_agent' => ['route_path' => '/artifacts/client-agent.py'],
    'leikwan_bond_linux_amd64' => ['route_path' => '/artifacts/wstunnel/leikwan-bond_linux_amd64'],
    'windows_client_app' => ['route_path' => '/artifacts/leikwan-bridge-setup.exe'],
    'leikwan_bond_windows_amd64' => ['route_path' => '/artifacts/wstunnel/leikwan-bond_windows_amd64.exe'],
]; }
function base_context(string $platform = 'linux-bond'): array { $keys = $platform === 'windows-bond' ? ['windows_client_app', 'leikwan_bond_windows_amd64'] : ['client_agent', 'leikwan_bond_linux_amd64']; $manifest = artifact_manifest(); $pins = []; $prov = []; foreach ($keys as $key) { $pins[$key] = $manifest[$key]['sha256']; $prov[$key] = ['source_fingerprint' => 'fp-' . $key, 'build_output_sha256' => $manifest[$key]['sha256']]; } return ['test_mode' => true, 'platform_family' => $platform, 'activation_artifact_keys' => $keys, 'manifest_package' => $manifest, 'expected_pins' => $pins, 'source_build_provenance' => $prov, 'expected_capabilities' => ['session_id' => 's1', 'assignment_id' => 'a1', 'generation' => 7, 'platform_family' => $platform, 'fresh_within_seconds' => 60, 'now' => 1000], 'capability_reports' => array_map(fn ($key) => cap_row($key, $platform), $keys)]; }
function cap_row(string $key, string $platform = 'linux-bond'): array { return ['role' => 'client', 'artifact_key' => $key, 'artifact_sha256' => artifact_manifest()[$key]['sha256'] ?? str_repeat('0', 64), 'session_id' => 's1', 'assignment_id' => 'a1', 'generation' => 7, 'platform_family' => $platform, 'reported_at' => 990]; }
function result_for(array $context): array { return (new LeigodOnlyArtifactConsistencyService(new CapRouteProbe(), new CapMetadataProbe(), new CapSourceMap()))->validateForActivation($context); }

if (!result_for(base_context())['ok']) fail('baseline capability context must pass');

$cases = [
    'missing' => ['capability_reports' => []],
    'wrong_session' => ['capability_reports' => [array_replace(cap_row('client_agent'), ['session_id' => 'wrong'])]],
    'wrong_assignment' => ['capability_reports' => [array_replace(cap_row('client_agent'), ['assignment_id' => 'wrong'])]],
    'wrong_generation' => ['capability_reports' => [array_replace(cap_row('client_agent'), ['generation' => 8])]],
    'wrong_family' => ['capability_reports' => [array_replace(cap_row('client_agent'), ['platform_family' => 'windows-bond'])]],
    'wrong_platform' => ['capability_reports' => [array_replace(cap_row('client_agent'), ['platform' => 'windows-amd64'])]],
    'stale' => ['capability_reports' => [array_replace(cap_row('client_agent'), ['reported_at' => 1])]],
    'expected_only' => ['capability_reports' => [cap_row('leikwan_bond_linux_amd64')], 'expected_capabilities' => array_replace(base_context()['expected_capabilities'], ['expected_artifact_keys' => ['client_agent', 'leikwan_bond_linux_amd64']])],
    'reported_only' => ['capability_reports' => [cap_row('client_agent'), cap_row('leikwan_bond_linux_amd64'), array_replace(cap_row('unknown'), ['artifact_key' => 'unknown'])]],
    'hash_mismatch' => ['capability_reports' => [array_replace(cap_row('client_agent'), ['artifact_sha256' => str_repeat('f', 64)])]],
];
foreach ($cases as $reason => $patch) {
    assert_blocker(result_for(array_replace(base_context(), $patch)), 'capability_gate_failed:client_agent:' . $reason);
}

assert_blocker(result_for(array_replace(base_context(), ['capability_reports' => [array_replace(cap_row('client_agent'), ['role' => 'slave'])]])), 'capability_gate_failed:client_agent:wrong_role');
assert_blocker(result_for(array_replace(base_context(), ['capability_reports' => [array_replace(cap_row('client_agent'), ['role' => 'sidecar'])]])), 'capability_gate_failed:client_agent:wrong_role');
assert_blocker(result_for(array_replace(base_context(), ['activation_artifact_keys' => ['unknown_artifact'], 'expected_pins' => ['unknown_artifact' => str_repeat('0', 64)], 'source_build_provenance' => ['unknown_artifact' => ['source_fingerprint' => 'fp-unknown_artifact', 'build_output_sha256' => str_repeat('0', 64)]], 'capability_reports' => [array_replace(cap_row('client_agent'), ['artifact_key' => 'unknown_artifact'])]])), 'capability_unknown_artifact_key:unknown_artifact');

assert_blocker(result_for(array_replace(base_context('windows-bond'), ['capability_reports' => [array_replace(cap_row('windows_client_app', 'windows-bond'), ['platform_family' => 'linux-bond'])]])), 'capability_gate_failed:windows_client_app:wrong_platform');
assert_blocker(result_for(array_replace(base_context('windows-bond'), ['capability_reports' => [array_replace(cap_row('windows_client_app', 'windows-bond'), ['artifact_sha256' => str_repeat('e', 64)])]])), 'capability_gate_failed:windows_client_app:hash_mismatch');

assert_blocker(result_for(array_replace(base_context('linux-bond'), ['capability_reports' => [cap_row('leikwan_bond_windows_amd64', 'windows-bond')]])), 'capability_gate_failed:client_agent:missing');
assert_blocker(result_for(array_replace(base_context('windows-bond'), ['capability_reports' => [cap_row('leikwan_bond_linux_amd64', 'linux-bond')]])), 'capability_gate_failed:windows_client_app:missing');

$negative = base_context();
$negative['activation_artifact_keys'][] = 'leikwan_mptcp_relay_linux_amd64';
$negative['expected_pins']['leikwan_mptcp_relay_linux_amd64'] = artifact_manifest()['leikwan_mptcp_relay_linux_amd64']['sha256'];
$negative['source_build_provenance']['leikwan_mptcp_relay_linux_amd64'] = ['source_fingerprint' => 'fp-leikwan_mptcp_relay_linux_amd64', 'build_output_sha256' => artifact_manifest()['leikwan_mptcp_relay_linux_amd64']['sha256']];
assert_blocker(result_for($negative), 'negative_evidence_only:leikwan_mptcp_relay_linux_amd64');

echo "ok\n";
