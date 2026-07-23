<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require_once $path;
});

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\BaseController;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\LeigodOnlyArtifactConsistencyService;

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assert_blocker(array $result, string $blocker): void { if (!in_array($blocker, $result['blockers'] ?? [], true)) fail('missing blocker ' . $blocker . ' in ' . json_encode($result['blockers'] ?? [])); }

final class PublicBaseControllerForArtifactTest extends BaseController {
    public function __construct(Config $config) { parent::__construct($config, new Database($config)); }
}

$env = tempnam(sys_get_temp_dir(), 'lw-art-meta-');
file_put_contents($env, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
$controller = new PublicBaseControllerForArtifactTest(new Config($env));
if (!method_exists($controller, 'artifactMetadataForActivation')) fail('BaseController must expose public artifactMetadataForActivation(array $keys): array');
$metadata = $controller->artifactMetadataForActivation(['client_agent', 'client_agent_update', 'slave_agent_update', 'sidecar_agent_update']);
foreach (['client_agent', 'client_agent_update', 'slave_agent_update', 'sidecar_agent_update'] as $key) {
    if (!isset($metadata[$key]['sha256']) || !preg_match('/^[a-f0-9]{64}$/', (string) $metadata[$key]['sha256'])) fail('metadata missing sha256 for ' . $key);
    if (!isset($metadata[$key]['artifact_url'])) fail('metadata missing artifact_url for ' . $key);
}

final class MetaRouteProbe { public function __construct(public array $manifest) {} public function probe(string $path): array { if ($path === '/artifacts/versions.json') { $body = json_encode($this->manifest); return ['status' => 200, 'body' => $body, 'headers' => [], 'sha256' => hash('sha256', $body), 'size_bytes' => strlen($body)]; } foreach ($this->manifest as $entry) { if (($entry['artifact_url'] ?? '') === $path) { $bytes = 'bytes:' . $path; return ['status' => 200, 'body' => $bytes, 'headers' => [], 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]; } } if (in_array($path, ['/artifacts/client-agent-update.sh', '/artifacts/slave-agent-update.sh', '/artifacts/sidecar-agent-update.sh'], true)) { $bytes = 'bytes:' . $path; return ['status' => 200, 'body' => $bytes, 'headers' => [], 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes)]; } return ['status' => 404, 'body' => '', 'headers' => [], 'sha256' => hash('sha256', ''), 'size_bytes' => 0]; } }
final class MetaProbe { public function __construct(public array $metadata) {} public function metadataForActivation(array $keys): array { return array_intersect_key($this->metadata, array_flip($keys)); } }
final class MetaSource { public function activationCriticalMap(): array { return ['client_agent' => ['route_path' => '/artifacts/client-agent.py']]; } public function routeOnlyHelperMap(): array { return ['client_agent_update' => ['route_path' => '/artifacts/client-agent-update.sh'], 'slave_agent_update' => ['route_path' => '/artifacts/slave-agent-update.sh']]; } public function sourceFingerprint(string $key): string { return 'fp-' . $key; } public function buildOutputSha256(string $key): string { return hash('sha256', 'bytes:/artifacts/client-agent.py'); } public function requiresImmutableProvenance(string $key): bool { return false; } }

$manifest = ['client_agent' => ['artifact_url' => '/artifacts/client-agent.py', 'sha256' => hash('sha256', 'bytes:/artifacts/client-agent.py'), 'size_bytes' => strlen('bytes:/artifacts/client-agent.py')]];
$ctx = ['test_mode' => true, 'platform_family' => 'linux-bond', 'activation_artifact_keys' => ['client_agent'], 'route_only_helper_keys' => ['client_agent_update', 'slave_agent_update'], 'manifest_package' => $manifest, 'expected_pins' => ['client_agent' => $manifest['client_agent']['sha256']], 'source_build_provenance' => ['client_agent' => ['source_fingerprint' => 'fp-client_agent', 'build_output_sha256' => $manifest['client_agent']['sha256']]]];
$goodMetadata = ['client_agent' => $manifest['client_agent'], 'client_agent_update' => ['artifact_url' => '/artifacts/client-agent-update.sh', 'sha256' => hash('sha256', 'bytes:/artifacts/client-agent-update.sh')], 'slave_agent_update' => ['artifact_url' => '/artifacts/slave-agent-update.sh', 'sha256' => hash('sha256', 'bytes:/artifacts/slave-agent-update.sh')]];
$service = new LeigodOnlyArtifactConsistencyService(new MetaRouteProbe($manifest), new MetaProbe($goodMetadata), new MetaSource());
if (!$service->validateForActivation($ctx)['ok']) fail('expected helper metadata baseline to pass');

$badBase = $goodMetadata; $badBase['client_agent']['sha256'] = str_repeat('1', 64);
assert_blocker((new LeigodOnlyArtifactConsistencyService(new MetaRouteProbe($manifest), new MetaProbe($badBase), new MetaSource()))->validateForActivation($ctx), 'base_controller_sha_mismatch:client_agent');
$badHelper = $goodMetadata; $badHelper['client_agent_update']['sha256'] = str_repeat('2', 64);
assert_blocker((new LeigodOnlyArtifactConsistencyService(new MetaRouteProbe($manifest), new MetaProbe($badHelper), new MetaSource()))->validateForActivation($ctx), 'update_helper_metadata_mismatch:client_agent_update');
$missingHelper = $goodMetadata; unset($missingHelper['slave_agent_update']);
assert_blocker((new LeigodOnlyArtifactConsistencyService(new MetaRouteProbe($manifest), new MetaProbe($missingHelper), new MetaSource()))->validateForActivation($ctx), 'route_helper_metadata_missing:slave_agent_update');

echo "ok\n";
