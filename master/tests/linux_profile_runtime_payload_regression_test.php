<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
$clientAgent = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');

foreach ([
    'allow_paid_lines',
    'selected_carriers_json',
    'runtime_platform_scope',
    'selected_carriers',
    'used_paid_line',
    'selected_carrier',
    'profile_id',
    'profile_name',
    'linux_profile_runtime',
] as $needle) {
    if (!str_contains((string) $provisioning . (string) $clientAgent . (string) $tenant, $needle)) {
        fail('missing linux profile runtime snippet: ' . $needle);
    }
}

echo "ok\n";
