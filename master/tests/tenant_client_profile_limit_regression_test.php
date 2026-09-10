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

$pageStart = strpos($tenant, 'public function clientsPage(');
$pageEnd = strpos($tenant, 'public function sidecarsPage(', $pageStart ?: 0);
if (!is_int($pageStart) || !is_int($pageEnd) || $pageEnd <= $pageStart) {
    fail('failed to isolate tenant clientsPage source');
}
$clientsPage = substr($tenant, $pageStart, $pageEnd - $pageStart);
foreach (['Current Client Profiles', 'This package allows up to', 'maxClientProfilesForTenantOwnedClients('] as $forbidden) {
    if (str_contains($clientsPage, $forbidden)) {
        fail('tenant Client page exposes profile-count policy: ' . $forbidden);
    }
}

foreach ([
    'maxClientProfilesForTenantOwnedClients(',
    'if ($currentClientProfiles >= $maxClientProfiles) {',
    "'message' => 'This package allows up to '",
] as $needle) {
    if (!str_contains($tenant . $provisioning, $needle)) {
        fail('missing tenant client-profile enforcement snippet: ' . $needle);
    }
}

echo "ok\n";
