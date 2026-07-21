<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void { $prefix = 'Leikwan\\Master\\'; if (!str_starts_with($class, $prefix)) return; $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; if (is_file($path)) require_once $path; });

use Leikwan\Master\Support\LeigodOnlyArtifactConsistencyService;

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assert_blocker(array $result, string $blocker): void { if (!in_array($blocker, $result['blockers'] ?? [], true)) fail('missing blocker ' . $blocker . ' in ' . json_encode($result['blockers'] ?? [])); }

final class ShadowRoute { public function probe(string $path): array { if ($path === '/artifacts/versions.json') { $body = json_encode(manifest_shadow()); return ['status' => 200, 'body' => $body, 'headers' => [], 'sha256' => hash('sha256', $body), 'size_bytes' => strlen($body)]; } $body = $path === '/artifacts/client-agent.py' ? 'client' : 'windows'; return ['status' => 200, 'body' => $body, 'headers' => [], 'sha256' => hash('sha256', $body), 'size_bytes' => strlen($body)]; } }
final class ShadowMeta { public function metadataForActivation(array $keys): array { return array_intersect_key(manifest_shadow(), array_flip($keys)); } }
final class ShadowSource { public function activationCriticalMap(): array { return ['client_agent' => ['route_path' => '/artifacts/client-agent.py'], 'windows_client_app' => ['route_path' => '/artifacts/leikwan-bridge-setup.exe']]; } public function routeOnlyHelperMap(): array { return []; } public function sourceFingerprint(string $key): string { return 'fp-' . $key; } public function buildOutputSha256(string $key): string { return manifest_shadow()[$key]['sha256'] ?? ''; } public function requiresImmutableProvenance(string $key): bool { return false; } }
function manifest_shadow(): array { return ['client_agent' => ['artifact_url' => '/artifacts/client-agent.py', 'sha256' => hash('sha256', 'client'), 'size_bytes' => 6], 'windows_client_app' => ['artifact_url' => '/artifacts/leikwan-bridge-setup.exe', 'sha256' => hash('sha256', 'windows'), 'size_bytes' => 7]]; }
function ctx_shadow(array $keys, array $static): array { $pins = $prov = []; foreach ($keys as $key) { $pins[$key] = manifest_shadow()[$key]['sha256']; $prov[$key] = ['source_fingerprint' => 'fp-' . $key, 'build_output_sha256' => manifest_shadow()[$key]['sha256']]; } return ['test_mode' => true, 'platform_family' => in_array('windows_client_app', $keys, true) ? 'windows-bond' : 'linux-bond', 'activation_artifact_keys' => $keys, 'manifest_package' => manifest_shadow(), 'static_mirror' => $static, 'expected_pins' => $pins, 'source_build_provenance' => $prov]; }
function result_shadow(array $ctx): array { return (new LeigodOnlyArtifactConsistencyService(new ShadowRoute(), new ShadowMeta(), new ShadowSource()))->validateForActivation($ctx); }

if (!result_shadow(ctx_shadow(['client_agent'], []))['ok']) fail('absent static mirror must not block');
assert_blocker(result_shadow(ctx_shadow(['client_agent'], ['client_agent' => ['present' => true, 'sha256' => hash('sha256', 'wrong')]])), 'public_shadow_bytes_mismatch:client_agent');
assert_blocker(result_shadow(ctx_shadow(['windows_client_app'], ['windows_client_app' => ['present' => true, 'sha256' => hash('sha256', 'wrong')]])), 'public_shadow_bytes_mismatch:windows_client_app');

echo "ok\n";
