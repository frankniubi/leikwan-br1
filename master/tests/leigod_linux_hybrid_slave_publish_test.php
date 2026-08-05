<?php
declare(strict_types=1);

require_once __DIR__ . '/leigod_hybrid_test_helper.php';

use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\LeigodLineCatalogService;

function assertHybridPublishSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** @param array<string,mixed> $forward @param array<string,mixed> $node @return array<string,mixed> */
function hybridPublishPayload(ProvisioningService $service, array $forward, array $node, int $endpointId): array
{
    $method = new ReflectionMethod($service, 'publishTaskPayloadForForward');
    $method->setAccessible(true);
    $payload = $method->invoke($service, $forward, $node, $endpointId);
    if (!is_array($payload)) {
        throw new RuntimeException('hybrid publish payload missing');
    }
    return $payload;
}

/** @param array<string,mixed> $forward @return array<string,mixed>|null */
function hybridEgressProfile(ProvisioningService $service, array $forward): ?array
{
    $method = new ReflectionMethod($service, 'linuxHybridLeigodEgressProfile');
    $method->setAccessible(true);
    $profile = $method->invoke($service, $forward);
    return is_array($profile) ? $profile : null;
}

/** @param array<int,array<string,mixed>> $candidates @return array<int,array<string,mixed>> */
function hybridCandidates(ProvisioningService $service, array $candidates): array
{
    $method = new ReflectionMethod($service, 'linuxHybridLeigodCandidates');
    $method->setAccessible(true);
    $expanded = $method->invoke($service, $candidates);
    if (!is_array($expanded)) {
        throw new RuntimeException('hybrid candidate expansion returned a non-array');
    }
    return $expanded;
}

$fixture = hybridFixture();
$db = $fixture['db'];
$db->failOnSidecarQuery = true;
$service = new ProvisioningService($fixture['config'], $db);
$forward = $fixture['forward'] + [
    'public_port' => 7003,
    'path_prefix' => 'fixed',
    'client_token' => 'service-token',
    'bond_ingress_mode' => 'wss_path_mux',
    'forward_combination_runtime_preference' => 'single',
    'bond_sidecar_point_agent_config_json' => '',
];
$node = ['id' => 101, 'node_group_key' => 'group-a', 'agent_config_json' => null];

$payload = hybridPublishPayload($service, $forward, $node, 316);

assertHybridPublishSame('127.0.0.1:48316', $payload['target'] ?? null, 'slave backend stays loopback');
assertHybridPublishSame(7003, $payload['listen_port'] ?? null, 'public ingress port unchanged');
assertHybridPublishSame('plain_tcp_mux', $payload['ingress_protocol'] ?? null, 'flat relay uses authenticated ingress');
assertHybridPublishSame('tcp_relay', $payload['backend_protocol'] ?? null, 'flat relay backend protocol');
assertHybridPublishSame('bond', $payload['leigod_forward']['mode'] ?? null, 'flat relay mode');
assertHybridPublishSame('198.51.100.50:7443', $payload['leigod_forward']['target'] ?? null, 'independent egress target');
assertHybridPublishSame('linux_hybrid', $payload['hybrid_relay']['placement'] ?? null, 'hybrid placement');
assertHybridPublishSame(null, $payload['server_side_bond'] ?? null, 'no nested slave bond client');
assertHybridPublishSame([], $payload['sidecar_uplink'] ?? null, 'no sidecar uplink');
assertHybridPublishSame([], $payload['sidecar_uplink_selector'] ?? null, 'no sidecar selector');
assertHybridPublishSame('leigod_only', $payload['active_family'] ?? null, 'sidecar-independent active family');
assertHybridPublishSame('direct_forward_node', $payload['target_contract'] ?? null, 'sidecar-independent target contract');

$candidates = $payload['leigod_forward']['candidates'] ?? [];
assertHybridPublishSame(1, count(is_array($candidates) ? $candidates : []), 'one leased flat route');
assertHybridPublishSame(452, $candidates[0]['line_id'] ?? null, 'physical line');
assertHybridPublishSame(452, $candidates[0]['leg_route_id'] ?? null, 'canonical first lane route');
assertHybridPublishSame('00000000-0000-4000-8000-000000000101', $candidates[0]['lease_id'] ?? null, 'lease id');
assertHybridPublishSame(3, $candidates[0]['lease_generation'] ?? null, 'lease generation');
assertHybridPublishSame(false, array_key_exists('toml', $candidates[0] ?? []), 'no provider config in publish payload');

$catalogCandidates = (new LeigodLineCatalogService($fixture['config'], $db))
    ->candidatesForForwardOnNode($forward, 101);
assertHybridPublishSame('node', $catalogCandidates[0]['lease_owner_type'] ?? null, 'catalog owner type projection');
assertHybridPublishSame(101, $catalogCandidates[0]['lease_owner_id'] ?? null, 'catalog owner id projection');

$quicCapableNode = $node + [
    'agent_version' => '2026.04.15.2',
    'agent_config_json' => json_encode([
        'supports_quic' => true,
        'wireguard_outer_transport' => 'udp',
        'bond_protocols' => ['quic'],
    ], JSON_THROW_ON_ERROR),
];
$quicCapablePayload = hybridPublishPayload($service, $forward, $quicCapableNode, 316);
assertHybridPublishSame([], $quicCapablePayload['leg_relay_protocols'] ?? null, 'hybrid public ingress is TCP-only');

$badPort = $forward;
$badPort['public_port'] = 6999;
try {
    hybridPublishPayload($service, $badPort, $node, 316);
    throw new RuntimeException('linux_hybrid accepted an out-of-range public port');
} catch (Throwable $exception) {
    if (!str_contains($exception->getMessage(), '7000')) {
        throw $exception;
    }
}

$nonTcp = $forward;
$nonTcp['transport_mode'] = 'tcp+udp';
try {
    hybridPublishPayload($service, $nonTcp, $node, 316);
    throw new RuntimeException('linux_hybrid accepted a non-TCP carrier');
} catch (Throwable $exception) {
    if (!str_contains(strtolower($exception->getMessage()), 'tcp')) {
        throw $exception;
    }
}

$missingSecretFixture = hybridFixture(['secret_present' => false]);
$missingSecretService = new ProvisioningService($missingSecretFixture['config'], $missingSecretFixture['db']);
assertHybridPublishSame(
    null,
    hybridEgressProfile($missingSecretService, $missingSecretFixture['forward']),
    'profile without a local egress secret must not enable linux_hybrid'
);
@unlink($missingSecretFixture['env']);

$invalidCandidate = $candidates[0];
$invalidCandidate['combination_id'] = 0;
try {
    hybridCandidates($service, [$invalidCandidate]);
    throw new RuntimeException('linux_hybrid accepted an invalid combination id');
} catch (Throwable $exception) {
    if (!str_contains($exception->getMessage(), 'candidate')) {
        throw $exception;
    }
}

$invalidCandidate = $candidates[0];
$invalidCandidate['config_sha256'] = 'not-a-sha256';
try {
    hybridCandidates($service, [$invalidCandidate]);
    throw new RuntimeException('linux_hybrid accepted an invalid config hash');
} catch (Throwable $exception) {
    if (!str_contains($exception->getMessage(), 'candidate')) {
        throw $exception;
    }
}

@unlink($fixture['env']);
echo "ok\n";
