<?php
declare(strict_types=1);

require __DIR__ . '/leigod_hybrid_test_helper.php';

function failHybridIndependence(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

$fallbacks = [
    'hybrid gate off' => ['hybrid_gate' => false],
    'lease gate off' => ['lease_gate' => false],
    'windows platform' => ['platform' => 'windows-amd64'],
    'android platform' => ['platform' => 'android-arm64'],
    'unknown platform' => ['platform' => 'unknown'],
    'pathd hash mismatch' => ['capability_sha' => str_repeat('c', 64)],
    'missing local lease' => ['local_leases' => false],
    'one local lane only' => ['single_execution_group' => true, 'local_max_lanes' => 1],
    'missing profile' => ['profile_present' => false],
    'disabled profile' => ['profile_enabled' => false],
    'missing secret' => ['secret_present' => false],
    'non tcp transport' => ['forward' => ['transport_mode' => 'tcp+udp']],
];
foreach ($fallbacks as $name => $overrides) {
    $fixture = hybridFixture($overrides);
    $runtime = $fixture['service']->forForward(
        $fixture['forward'],
        $fixture['client'],
        $fixture['session'],
        $fixture['endpoints']
    );
    if ($runtime !== null) {
        failHybridIndependence($name . ' did not fail closed');
    }
    @unlink($fixture['env']);
}

$fixture = hybridFixture();
$first = $fixture['service']->forForward(
    $fixture['forward'] + ['bond_sidecar_point_name' => 'old', 'fixed_endpoint_leigod_bond' => 1],
    $fixture['client'],
    $fixture['session'],
    $fixture['endpoints']
);
$changedForward = array_replace($fixture['forward'], [
    'bond_sidecar_point_id' => null,
    'bond_sidecar_point_name' => 'deleted',
    'fixed_endpoint_leigod_bond' => 0,
    'server_side_leigod_bond' => 0,
]);
$second = $fixture['service']->forForward(
    $changedForward,
    $fixture['client'],
    $fixture['session'],
    $fixture['endpoints']
);
if (!is_array($first) || !is_array($second) || ($first['contract_hash'] ?? '') !== ($second['contract_hash'] ?? '')) {
    failHybridIndependence('sidecar changes altered hybrid contract hash');
}
@unlink($fixture['env']);

echo "ok\n";
