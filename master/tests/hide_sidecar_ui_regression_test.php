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

foreach ([
    '/tenant/sidecars',
    '/admin/sidecars',
    'Add Your Own Slave',
    'Install and update the line runtime from',
] as $needle) {
    if (str_contains((string) $tenant . (string) $admin . (string) $bootstrap, $needle)) {
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