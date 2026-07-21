<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void { $prefix = 'Leikwan\\Master\\'; if (!str_starts_with($class, $prefix)) return; $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; if (is_file($path)) require_once $path; });

use Leikwan\Master\Support\LeigodOnlyArtifactConsistencyService;
use Leikwan\Master\Support\LeigodOnlyArtifactRouteProbe;
use Leikwan\Master\Config;

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assert_blocker(array $result, string $blocker): void { if (!in_array($blocker, $result['blockers'] ?? [], true)) fail('missing blocker ' . $blocker . ' in ' . json_encode($result['blockers'] ?? [])); }

$probe = new LeigodOnlyArtifactRouteProbe(new Config(temp_env_route_guard()));
$manifest = $probe->probe('/artifacts/versions.json');
if (($manifest['status'] ?? 0) !== 200) fail('versions route probe must dispatch controller status 200');
if (json_decode((string) ($manifest['body'] ?? ''), true) === null) fail('versions route probe must return JSON body');
$client = $probe->probe('/artifacts/client-agent.py');
if (($client['status'] ?? 0) !== 200) fail('client-agent route probe must dispatch controller status 200');
if (($client['sha256'] ?? '') !== hash('sha256', (string) $client['body'])) fail('route probe sha must describe controller body bytes');

final class RRoute { public function probe(string $path): array { if ($path === '/artifacts/versions.json') { $b = json_encode(m_route()); return ['status' => 200, 'body' => $b, 'headers' => [], 'sha256' => hash('sha256', $b), 'size_bytes' => strlen($b)]; } if ($path === '/artifacts/wstunnel/leikwan-bond_windows_amd64.exe') { $b = 'wrong-windows-bond'; } else { $b = $path === '/artifacts/leikwan-bridge-setup.exe' ? 'windows-app' : 'client'; } return ['status' => 200, 'body' => $b, 'headers' => [], 'sha256' => hash('sha256', $b), 'size_bytes' => strlen($b)]; } }
final class RMeta { public function metadataForActivation(array $keys): array { return array_intersect_key(m_route(), array_flip($keys)); } }
final class RSource { public function activationCriticalMap(): array { return ['windows_client_app' => ['route_path' => '/artifacts/leikwan-bridge-setup.exe'], 'leikwan_bond_windows_amd64' => ['route_path' => '/artifacts/wstunnel/leikwan-bond_windows_amd64.exe']]; } public function routeOnlyHelperMap(): array { return []; } public function sourceFingerprint(string $key): string { return 'fp-' . $key; } public function buildOutputSha256(string $key): string { return m_route()[$key]['sha256'] ?? ''; } public function requiresImmutableProvenance(string $key): bool { return false; } }
function temp_env_route_guard(): string { $env = tempnam(sys_get_temp_dir(), 'lw-route-'); file_put_contents($env, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n"); return $env; }
function m_route(): array { return ['windows_client_app' => ['artifact_url' => '/artifacts/leikwan-bridge-setup.exe', 'sha256' => hash('sha256', 'windows-app'), 'size_bytes' => 11], 'leikwan_bond_windows_amd64' => ['artifact_url' => '/artifacts/wstunnel/leikwan-bond_windows_amd64.exe', 'sha256' => hash('sha256', 'windows-bond'), 'size_bytes' => 12]]; }
$ctx = ['test_mode' => true, 'platform_family' => 'windows-bond', 'activation_artifact_keys' => ['windows_client_app', 'leikwan_bond_windows_amd64'], 'manifest_package' => m_route(), 'expected_pins' => ['windows_client_app' => hash('sha256', 'windows-app'), 'leikwan_bond_windows_amd64' => hash('sha256', 'windows-bond')], 'source_build_provenance' => ['windows_client_app' => ['source_fingerprint' => 'fp-windows_client_app', 'build_output_sha256' => hash('sha256', 'windows-app')], 'leikwan_bond_windows_amd64' => ['source_fingerprint' => 'fp-leikwan_bond_windows_amd64', 'build_output_sha256' => hash('sha256', 'windows-bond')]]];
assert_blocker((new LeigodOnlyArtifactConsistencyService(new RRoute(), new RMeta(), new RSource()))->validateForActivation($ctx), 'route_bytes_mismatch:leikwan_bond_windows_amd64');

echo "ok\n";
