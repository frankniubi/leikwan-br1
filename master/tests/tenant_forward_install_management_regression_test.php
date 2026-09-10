<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read TenantController source');
}

foreach ([
    'Manage client profiles and runtime installs from <a href="/tenant/clients"><strong>Client Profiles</strong></a>.',
    'Current target transport endpoint:',
] as $required) {
    if (!str_contains($source, $required)) {
        fail('missing required forward-management guidance: ' . $required);
    }
}

foreach ([
    '/artifacts/install-client.sh',
    '/tenant/sidecars',
    '<summary>Install Commands</summary>',
    'Sidecar Points',
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('stale forward-level install UI still present: ' . $forbidden);
    }
}

echo "ok\n";
