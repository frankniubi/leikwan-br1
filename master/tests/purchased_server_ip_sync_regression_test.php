<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fail('could not resolve master root');
}

$whmcs = file_get_contents(__DIR__ . '/../src/Support/WhmcsClient.php');
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    [$whmcs, 'normalizePurchasedServerIdentity('],
    [$whmcs, 'dedicatedip'],
    [$whmcs, 'assignedips'],
    [$provisioning, "payload['products'][0]"],
    [$provisioning, 'server_hostname'],
    [$provisioning, 'server_public_ip'],
    [$provisioning, 'server_ip_state'],
    [$provisioning, 'server_ip_last_synced_at'],
    [$provisioning, "'stale'"],
    [$provisioning, '!== $whmcsServiceId'],
    [$provisioning, '$productIdentity = null;'],
    [$provisioning, "\$targetSource = trim((string) (\$payload['target_source'] ?? 'manual'));"],
    [$provisioning, "\$targetServiceId = (int) (\$payload['target_service_id'] ?? 0);"],
    [$provisioning, "'target_source' => \$targetSource !== '' ? \$targetSource : 'manual'"],
    [$provisioning, "'target_service_id' => \$targetServiceId > 0 ? \$targetServiceId : null"],
    [$provisioning, 'target_source = :target_source'],
    [$provisioning, 'target_service_id = :target_service_id'],
    [$provisioning, 'target_service_id'],
    [$provisioning, 'target_source'],
] as [$haystack, $needle]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fail('missing purchased-server-ip sync snippet: ' . $needle);
    }
}

require_once $root . '/src/Config.php';
require_once $root . '/src/Support/ApiException.php';
require_once $root . '/src/Support/WhmcsClient.php';

$envPath = tempnam(sys_get_temp_dir(), 'lw-whmcs-env-');
if ($envPath === false) {
    fail('could not create temp env file');
}

file_put_contents($envPath, "WHMCS_BASE_URL=https://example.test\nWHMCS_API_IDENTIFIER=test\nWHMCS_API_SECRET=test\n");
$config = new \Leikwan\Master\Config($envPath);
$client = new \Leikwan\Master\Support\WhmcsClient($config);

$preferred = $client->normalizePurchasedServerIdentity([
    'serviceid' => 55,
    'domain' => 'srv-a.example.test',
    'dedicatedip' => '203.0.113.55',
    'assignedips' => '203.0.113.99, 203.0.113.100',
]);
if (($preferred['service_id'] ?? 0) !== 55 || ($preferred['hostname'] ?? '') !== 'srv-a.example.test' || ($preferred['server_ip'] ?? '') !== '203.0.113.55') {
    @unlink($envPath);
    fail('normalizePurchasedServerIdentity should prefer dedicatedip');
}

$fallback = $client->normalizePurchasedServerIdentity([
    'id' => 77,
    'hostname' => 'srv-b.example.test',
    'dedicatedip' => '',
    'assignedips' => '198.51.100.77, 198.51.100.78',
]);
if (($fallback['service_id'] ?? 0) !== 77 || ($fallback['hostname'] ?? '') !== 'srv-b.example.test' || ($fallback['server_ip'] ?? '') !== '198.51.100.77') {
    @unlink($envPath);
    fail('normalizePurchasedServerIdentity should fall back to first assignedips entry');
}

@unlink($envPath);

echo "ok\n";
