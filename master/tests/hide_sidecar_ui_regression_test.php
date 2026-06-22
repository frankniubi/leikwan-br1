<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');

$combined = (string) $tenant . "\n" . (string) $admin . "\n" . (string) $bootstrap;

foreach ([
    '/tenant/sidecars',
    "/tenant' . '/sidecars",
    '/admin/sidecars',
    "/admin' . '/sidecars",
    '±ß³µ (Sidecar)',
    'Sidecar Point',
    'Create Sidecar Point',
    'Edit Sidecar Point',
    'Save Sidecar Point',
    'Sidecar Point List',
    'Sidecar point saved.',
    'Sidecar point deleted.',
    'Add Your Own Slave',
    'Install and update the line runtime from',
    'sidecar point',
] as $needle) {
    if (str_contains($combined, $needle)) {
        fail('sidecar ui still exposed: ' . $needle);
    }
}

foreach ([
    '/tenant/clients',
    '/tenant/forwards',
    'Client Profiles',
    'Create Client',
] as $needle) {
    if (!str_contains((string) $tenant . (string) $bootstrap, $needle)) {
        fail('profile-first tenant surface missing: ' . $needle);
    }
}

echo "ok\n";