<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\LeigodOnlyArtifactConsistencyService;

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assert_blocker(array $result, string $blocker): void {
    if (!in_array($blocker, $result['blockers'] ?? [], true)) {
        fail('missing blocker ' . $blocker . ' in ' . json_encode($result['blockers'] ?? []));
    }
}

final class ManifestFakeRouteProbe {
    public int $calls = 0;
    public function __construct(public array $routes) {}
    public function probe(string $path): array {
        $this->calls++;
        return $this->routes[$path] ?? ['status' => 404, 'headers' => [], 'body' => '', 'sha256' => hash('sha256', ''), 'size_bytes' => 0];
    }
}
final class ManifestFakeMetadataProbe {
    public int $calls = 0;
    public function __construct(public array $metadata) {}
    public function metadataForActivation(array $keys): array {
        $this->calls++;
        return array_intersect_key($this->metadata, array_flip($keys));
    }
}
final class ManifestFakeSourceMap {
    public function __construct(public array $map) {}
    public function activationCriticalMap(): array { return $this->map; }
    public function routeOnlyHelperMap(): array { return []; }
    public function sourceFingerprint(string $key): string { return 'fp-' . $key; }
    public function buildOutputSha256(string $key): string { return (string) ($this->map[$key]['sha256'] ?? ''); }
    public function requiresImmutableProvenance(string $key): bool { return in_array($key, ['gost_linux_amd64', 'leigod_linux_amd64', 'lguot_linux_amd64'], true); }
}

function make_result(array $override = []): array {
    $clientBytes = 'client-agent-bytes';
    $bondBytes = 'linux-bond-bytes';
    $manifest = [
        'client_agent' => ['path' => 'client-agent/client_agent.py', 'artifact_url' => '/artifacts/client-agent.py', 'sha256' => hash('sha256', $clientBytes), 'size_bytes' => strlen($clientBytes)],
        'leikwan_bond_linux_amd64' => ['path' => 'artifacts/wstunnel/leikwan-bond_linux_amd64', 'artifact_url' => '/artifacts/wstunnel/leikwan-bond_linux_amd64', 'sha256' => hash('sha256', $bondBytes), 'size_bytes' => strlen($bondBytes)],
    ];
    $manifestPackage = $override['manifest_package'] ?? $manifest;
    $routeManifest = $override['route_manifest'] ?? $manifest;
    $routes = $override['routes'] ?? [
        '/artifacts/versions.json' => ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode($routeManifest), 'sha256' => hash('sha256', json_encode($routeManifest)), 'size_bytes' => strlen((string) json_encode($routeManifest))],
        '/artifacts/client-agent.py' => ['status' => 200, 'headers' => [], 'body' => $clientBytes, 'sha256' => hash('sha256', $clientBytes), 'size_bytes' => strlen($clientBytes)],
        '/artifacts/wstunnel/leikwan-bond_linux_amd64' => ['status' => 200, 'headers' => [], 'body' => $bondBytes, 'sha256' => hash('sha256', $bondBytes), 'size_bytes' => strlen($bondBytes)],
    ];
    $map = $override['source_map'] ?? [
        'client_agent' => ['route_path' => '/artifacts/client-agent.py', 'sha256' => hash('sha256', $clientBytes)],
        'leikwan_bond_linux_amd64' => ['route_path' => '/artifacts/wstunnel/leikwan-bond_linux_amd64', 'sha256' => hash('sha256', $bondBytes)],
    ];
    $metadata = $override['metadata'] ?? $manifest;
    $service = new LeigodOnlyArtifactConsistencyService(
        new ManifestFakeRouteProbe($routes),
        new ManifestFakeMetadataProbe($metadata),
        new ManifestFakeSourceMap($map)
    );
    return $service->validateForActivation(array_replace_recursive([
        'test_mode' => true,
        'platform_family' => 'linux-bond',
        'activation_artifact_keys' => ['client_agent', 'leikwan_bond_linux_amd64'],
        'manifest_package' => $manifestPackage,
        'expected_pins' => ['leikwan_bond_linux_amd64' => hash('sha256', $bondBytes)],
        'source_build_provenance' => [
            'client_agent' => ['source_fingerprint' => 'fp-client_agent', 'build_output_sha256' => hash('sha256', $clientBytes)],
            'leikwan_bond_linux_amd64' => ['source_fingerprint' => 'fp-leikwan_bond_linux_amd64', 'build_output_sha256' => hash('sha256', $bondBytes)],
        ],
    ], $override['context'] ?? []));
}

$ok = make_result();
if (($ok['ok'] ?? null) !== true) {
    fail('expected baseline artifact consistency to pass: ' . json_encode($ok['blockers'] ?? []));
}

assert_blocker(make_result(['routes' => [
    '/artifacts/versions.json' => ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(['client_agent' => ['sha256' => 'different']]), 'sha256' => 'x', 'size_bytes' => 1],
]]), 'manifest_route_json_mismatch');

assert_blocker(make_result(['routes' => [
    '/artifacts/versions.json' => ['status' => 503, 'headers' => [], 'body' => 'down', 'sha256' => hash('sha256', 'down'), 'size_bytes' => 4],
]]), 'manifest_route_status_not_200');

assert_blocker(make_result(['routes' => [
    '/artifacts/versions.json' => ['status' => 200, 'headers' => ['Content-Type' => 'application/json'], 'body' => json_encode(make_result_manifest_for_test()), 'sha256' => 'x', 'size_bytes' => 1],
    '/artifacts/client-agent.py' => ['status' => 200, 'headers' => [], 'body' => 'wrong-client-agent-bytes', 'sha256' => hash('sha256', 'wrong-client-agent-bytes'), 'size_bytes' => 24],
    '/artifacts/wstunnel/leikwan-bond_linux_amd64' => ['status' => 200, 'headers' => [], 'body' => 'linux-bond-bytes', 'sha256' => hash('sha256', 'linux-bond-bytes'), 'size_bytes' => strlen('linux-bond-bytes')],
]]), 'route_bytes_mismatch:client_agent');

function make_result_manifest_for_test(): array {
    return [
        'client_agent' => ['path' => 'client-agent/client_agent.py', 'artifact_url' => '/artifacts/client-agent.py', 'sha256' => hash('sha256', 'client-agent-bytes'), 'size_bytes' => strlen('client-agent-bytes')],
        'leikwan_bond_linux_amd64' => ['path' => 'artifacts/wstunnel/leikwan-bond_linux_amd64', 'artifact_url' => '/artifacts/wstunnel/leikwan-bond_linux_amd64', 'sha256' => hash('sha256', 'linux-bond-bytes'), 'size_bytes' => strlen('linux-bond-bytes')],
    ];
}

echo "ok\n";
