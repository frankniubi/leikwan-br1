<?php
declare(strict_types=1);

require __DIR__ . '/leigod_hybrid_test_helper.php';

use Leikwan\Master\Support\ProvisioningService;

function failHybridContract(string $message): never
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    exit(1);
}

function assertHybridSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        failHybridContract($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$fixture = hybridFixture();
$runtime = $fixture['service']->forForward(
    $fixture['forward'],
    $fixture['client'],
    $fixture['session'],
    $fixture['endpoints']
);
if (!is_array($runtime)) {
    failHybridContract('expected hybrid runtime');
}
assertHybridSame('linux_pathd', $runtime['engine'] ?? null, 'engine');
assertHybridSame('linux_hybrid', $runtime['placement'] ?? null, 'placement');
assertHybridSame('tcp', $runtime['protocol'] ?? null, 'protocol');
assertHybridSame(1, count($runtime['local_leases'] ?? []), 'local lease count');
assertHybridSame(2, count($runtime['slave_legs'] ?? []), 'slave leg count');
assertHybridSame(2, $runtime['local_leases'][0]['max_lanes'] ?? null, 'local max lanes');
assertHybridSame(10.0, $runtime['policy']['throughput_min_mbps'] ?? null, 'bulk floor');
assertHybridSame(false, array_key_exists('bond_sidecar_point_id', $runtime), 'sidecar id absent');

$routeIds = [];
$physicalLines = [];
foreach ($runtime['local_leases'] as $lease) {
    $physicalLines[] = (int) $lease['physical_line_id'];
    foreach ($lease['routes'] as $route) {
        $routeIds[] = (int) $route['route_id'];
    }
}
foreach ($runtime['slave_legs'] as $leg) {
    $physicalLines[] = (int) $leg['physical_line_id'];
    $routeIds[] = (int) $leg['route_id'];
}
if (in_array(0, $routeIds, true) || count($routeIds) !== count(array_unique($routeIds))) {
    failHybridContract('logical route ids must be positive and unique');
}
if (count($physicalLines) !== count(array_unique($physicalLines))) {
    failHybridContract('local and slave physical lines must be distinct');
}

$token = (string) ($runtime['egress']['session_token'] ?? '');
$parts = explode('.', $token, 2);
$payload = isset($parts[0]) ? base64_decode(strtr($parts[0], '-_', '+/') . str_repeat('=', (4 - strlen($parts[0]) % 4) % 4), true) : false;
$claims = is_string($payload) ? json_decode($payload, true) : null;
assertHybridSame('v4-hkt-b.mcbar.cc', $claims['target_host'] ?? null, 'token target host');
assertHybridSame(5201, $claims['target_port'] ?? null, 'token target port');
assertHybridSame('tcp', $claims['target_protocol'] ?? null, 'token protocol');

$provisioning = new ProvisioningService($fixture['config'], $fixture['db'], $fixture['service']);
$wrapped = $provisioning->linuxHybridRuntimeForForward(
    $fixture['forward'],
    $fixture['client'],
    $fixture['session'],
    $fixture['endpoints']
);
assertHybridSame($runtime['contract_hash'], $wrapped['contract_hash'] ?? null, 'provisioning wrapper');
$leaseProjection = $provisioning->desiredLeigodLineManagementForClientDevice(20, 'linux-device-a', [11]);
assertHybridSame([451], array_column($leaseProjection['lines'] ?? [], 'line_id'), 'pull lease projection');
$approvedForward = array_replace($fixture['forward'], [
    'forward_id' => 70,
    'service_status' => 'active',
    'backend_mode' => 'leigod_mode',
    'mode' => 'bond',
    'transport' => 'tcp',
    'endpoints' => $fixture['endpoints'],
]);
$approvedCatalogIds = [];
$approvedScopes = [];
assertHybridSame(
    true,
    $provisioning->hasApprovedLinuxHybridAssignment(
        $fixture['client'],
        $fixture['session'],
        [$approvedForward],
        $approvedCatalogIds,
        $approvedScopes
    ),
    'assigned capable Linux bond is approved'
);
assertHybridSame([11], $approvedCatalogIds, 'approved forward preflight scopes client catalog ids');
assertHybridSame([[
    'route_id' => 80,
    'combination_id' => 100,
    'catalog_ids' => [11],
]], $approvedScopes, 'allocation retains per-forward membership scope');
$isolated = hybridFixture();
$isolated['db']->localLeases[] = [
    'catalog_id' => 99,
    'physical_line_id' => 499,
    'catalog_sort_order' => 99,
    'config_sha256' => hash('sha256', "line = 499\n"),
    'lease_id' => '00000000-0000-4000-8000-000000000099',
    'lease_generation' => 1,
    'lease_state' => 'active',
    'max_parallel_lanes' => 1,
    'lease_expires_at' => gmdate('Y-m-d H:i:s', time() + 90),
];
$isolatedRuntime = $isolated['service']->forForward(
    $isolated['forward'],
    $isolated['client'],
    $isolated['session'],
    $isolated['endpoints']
);
assertHybridSame([11], array_column($isolatedRuntime['local_leases'] ?? [], 'catalog_id'), 'forward membership isolates local leases');
@unlink($isolated['env']);
$noLocalLease = hybridFixture(['local_leases' => false]);
$noLocalLeaseIds = [];
assertHybridSame(
    true,
    (new ProvisioningService($noLocalLease['config'], $noLocalLease['db'], $noLocalLease['service']))
        ->hasApprovedLinuxHybridAssignment(
            $noLocalLease['client'],
            $noLocalLease['session'],
            [array_replace($noLocalLease['forward'], [
                'service_status' => 'active',
                'endpoints' => $noLocalLease['endpoints'],
            ])],
            $noLocalLeaseIds
        ),
    'preflight does not require a local client lease'
);
assertHybridSame([11], $noLocalLeaseIds, 'preflight still scopes candidates before local lease allocation');
@unlink($noLocalLease['env']);
assertHybridSame(
    false,
    $provisioning->hasApprovedLinuxHybridAssignment($fixture['client'], $fixture['session'], []),
    'unassigned Linux device is not approved'
);
$missingEgress = hybridFixture(['profile_present' => false]);
$missingEgressIds = [];
assertHybridSame(
    false,
    (new ProvisioningService($missingEgress['config'], $missingEgress['db'], $missingEgress['service']))
        ->hasApprovedLinuxHybridAssignment(
            $missingEgress['client'],
            $missingEgress['session'],
            [array_replace($missingEgress['forward'], [
                'service_status' => 'active',
                'endpoints' => $missingEgress['endpoints'],
            ])],
            $missingEgressIds
        ),
    'missing egress profile is not approved'
);
assertHybridSame([], $missingEgressIds, 'missing egress profile cannot allocate client lines');
assertHybridSame(
    ['lines' => []],
    (new ProvisioningService($missingEgress['config'], $missingEgress['db'], $missingEgress['service']))
        ->desiredLeigodLineManagementForClientDevice(20, 'linux-device-a', $missingEgressIds),
    'missing egress profile projects no client line'
);
@unlink($missingEgress['env']);
$missingEgressSecret = hybridFixture(['secret_present' => false]);
$missingEgressSecretIds = [];
assertHybridSame(
    false,
    (new ProvisioningService($missingEgressSecret['config'], $missingEgressSecret['db'], $missingEgressSecret['service']))
        ->hasApprovedLinuxHybridAssignment(
            $missingEgressSecret['client'],
            $missingEgressSecret['session'],
            [array_replace($missingEgressSecret['forward'], [
                'service_status' => 'active',
                'endpoints' => $missingEgressSecret['endpoints'],
            ])],
            $missingEgressSecretIds
        ),
    'missing egress secret is not approved'
);
assertHybridSame([], $missingEgressSecretIds, 'missing egress secret cannot allocate client lines');
assertHybridSame(
    ['lines' => []],
    (new ProvisioningService($missingEgressSecret['config'], $missingEgressSecret['db'], $missingEgressSecret['service']))
        ->desiredLeigodLineManagementForClientDevice(20, 'linux-device-a', $missingEgressSecretIds),
    'missing egress secret projects no client line'
);
@unlink($missingEgressSecret['env']);
$zeroSlaveGroup = hybridFixture(['single_execution_group' => true]);
$zeroSlaveIds = [];
assertHybridSame(
    false,
    (new ProvisioningService($zeroSlaveGroup['config'], $zeroSlaveGroup['db'], $zeroSlaveGroup['service']))
        ->hasApprovedLinuxHybridAssignment(
            $zeroSlaveGroup['client'],
            $zeroSlaveGroup['session'],
            [array_replace($zeroSlaveGroup['forward'], [
                'service_status' => 'active',
                'endpoints' => $zeroSlaveGroup['endpoints'],
            ])],
            $zeroSlaveIds
        ),
    'zero slave execution group is not approved'
);
assertHybridSame([], $zeroSlaveIds, 'zero slave execution group cannot allocate client lines');
assertHybridSame(
    ['lines' => []],
    (new ProvisioningService($zeroSlaveGroup['config'], $zeroSlaveGroup['db'], $zeroSlaveGroup['service']))
        ->desiredLeigodLineManagementForClientDevice(20, 'linux-device-a', $zeroSlaveIds),
    'zero slave execution group projects no client line'
);
@unlink($zeroSlaveGroup['env']);
$unrelatedMembership = hybridFixture(['client_catalog_rows' => []]);
$unrelatedMembershipIds = [];
assertHybridSame(
    false,
    (new ProvisioningService($unrelatedMembership['config'], $unrelatedMembership['db'], $unrelatedMembership['service']))
        ->hasApprovedLinuxHybridAssignment(
            $unrelatedMembership['client'],
            $unrelatedMembership['session'],
            [array_replace($unrelatedMembership['forward'], [
                'service_status' => 'active',
                'endpoints' => $unrelatedMembership['endpoints'],
            ])],
            $unrelatedMembershipIds
        ),
    'forward without a client-executable line membership is not approved'
);
assertHybridSame([], $unrelatedMembershipIds, 'unrelated global lines do not enter approved catalog ids');
assertHybridSame(
    ['lines' => []],
    (new ProvisioningService($unrelatedMembership['config'], $unrelatedMembership['db'], $unrelatedMembership['service']))
        ->desiredLeigodLineManagementForClientDevice(20, 'linux-device-a', $unrelatedMembershipIds),
    'unrelated global lines are not projected'
);
@unlink($unrelatedMembership['env']);
assertHybridSame(
    false,
    $provisioning->hasApprovedLinuxHybridAssignment(
        $fixture['client'],
        array_replace($fixture['session'], ['pathd_capability' => ['version' => 'old', 'sha256' => str_repeat('a', 64)]]),
        [$approvedForward]
    ),
    'hash-mismatched Linux device is not approved'
);
$provisioningSource = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
foreach (["\$config['path_runtime'] = \$pathRuntime", "\$config['mode'] = 'bond'"] as $needle) {
    if (!str_contains($provisioningSource, $needle)) {
        failHybridContract('assigned config integration missing ' . $needle);
    }
}
foreach ([
    "'forward_route_id' => isset(\$forward['forward_route_id'])",
    "'target_host' => \$forward['target_host']",
    "'target_port' => \$forward['target_port']",
    "'endpoint_id' => (int) \$endpoint['id'],\n                    'node_id' => (int) (\$endpoint['node_id']",
] as $needle) {
    if (!str_contains($provisioningSource, $needle)) {
        failHybridContract('assigned config preflight field missing ' . $needle);
    }
}
$controllerSource = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php') ?: '';
foreach (['leikwan_pathd_linux_amd64', 'desired_pathd_version', 'desired_pathd_sha256'] as $needle) {
    if (!str_contains($controllerSource, $needle)) {
        failHybridContract('client metadata missing ' . $needle);
    }
}

if (getenv('LEIKWAN_EMIT_HYBRID_PULL_FIXTURE') === '1') {
    echo json_encode([
        'config_revision' => 42,
        'client' => [
            'desired_pathd_version' => '0.2.0',
            'desired_pathd_sha256' => str_repeat('b', 64),
        ],
        'linux_path_leases' => $leaseProjection,
        'forwards' => [[
            'forward_id' => 70,
            'listen_port' => 8070,
            'path_prefix' => (string) $fixture['forward']['path_prefix'],
            'service_status' => 'active',
            'mode' => 'bond',
            'path_runtime' => $runtime,
        ]],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    @unlink($fixture['env']);
    exit(0);
}

@unlink($fixture['env']);
echo "ok\n";
