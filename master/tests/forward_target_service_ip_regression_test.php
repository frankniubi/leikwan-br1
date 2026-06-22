<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');

foreach ([
    '$targetSource = (string) ($payload[\'target_source\'] ?? \'manual\')',
    '$targetServiceId = (int) ($payload[\'target_service_id\'] ?? 0)',
    'target_service_id',
    'server_public_ip',
    'target_source',
    'This forward uses the purchased server IP automatically',
] as $needle) {
    if (!str_contains((string) $provisioning . (string) $tenant, $needle)) {
        fail('missing forward target-service snippet: ' . $needle);
    }
}

echo "ok\n";
