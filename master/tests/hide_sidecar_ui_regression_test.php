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

$combined = (string) $tenant . "
" . (string) $admin . "
" . (string) $bootstrap;

foreach ([
    '/tenant/sidecars',
    "/tenant' . '/sidecars",
    '/admin/sidecars',
    "/admin' . '/sidecars",
    '/enrollment-token',
    'Add Your Own Slave',
    '?? (Sidecar)',
    'Install and update the line runtime from',
    'sidecar point',
] as $needle) {
    if (str_contains($combined, $needle)) {
        fail('sidecar ui still exposed: ' . $needle);
    }
}

if (str_contains((string) $tenant, '/tenant/services/') && str_contains((string) $tenant, 'enroll')) {
    fail('sidecar ui still exposed: tenant enrollment front-door path remains');
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

echo "ok
";
