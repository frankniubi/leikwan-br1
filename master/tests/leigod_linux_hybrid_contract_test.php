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
$provisioningSource = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
foreach (["\$config['path_runtime'] = \$pathRuntime", "\$config['mode'] = 'bond'"] as $needle) {
    if (!str_contains($provisioningSource, $needle)) {
        failHybridContract('assigned config integration missing ' . $needle);
    }
}
$controllerSource = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php') ?: '';
foreach (['leikwan_pathd_linux_amd64', 'desired_pathd_version', 'desired_pathd_sha256'] as $needle) {
    if (!str_contains($controllerSource, $needle)) {
        failHybridContract('client metadata missing ' . $needle);
    }
}

@unlink($fixture['env']);
echo "ok\n";
