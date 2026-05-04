<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if (!is_string($tenant) || $tenant === '' || !is_string($provisioning) || $provisioning === '') {
    fail('failed to read tenant/provisioning source');
}

foreach ([
    'Client Profiles:',
    'Devices per Client:',
    'plan_max_client_profiles',
    'Current Client Profiles',
    'This package allows up to',
    'maxClientProfilesForTenantOwnedClients(',
    'if ($currentClientProfiles >= $maxClientProfiles) {',
    "'message' => 'This package allows up to '",
] as $needle) {
    if (!str_contains($tenant . $provisioning, $needle)) {
        fail('missing tenant client-profile enforcement snippet: ' . $needle);
    }
}

echo "ok\n";