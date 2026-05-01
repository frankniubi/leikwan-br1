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
    'Install and update the line runtime from <a href="/tenant/clients"><strong>Client</strong></a>.',
    'Install or manage target-side sidecars from <a href="/tenant/sidecars"><strong>Sidecar Points</strong></a>.',
] as $required) {
    if (!str_contains($source, $required)) {
        fail('missing required forward-management guidance: ' . $required);
    }
}

foreach ([
    '/artifacts/install-client.sh',
    '/artifacts/install-client.ps1',
    '<summary>Install Commands</summary>',
    '<summary>Install Bond Sidecar</summary>',
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('stale forward-level install UI still present: ' . $forbidden);
    }
}

echo "ok\n";
