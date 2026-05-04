<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
if (!is_string($admin) || $admin === '') {
    fail('failed to read AdminController source');
}

foreach ([
    '<label>Description</label><textarea name="description"',
    'one line per tenant-visible feature',
    '<label>Tenant Visibility</label>',
    'Visible to tenants',
    'Internal test package',
    '<label>Client Device Limit</label>',
    "name=\"default_client_online_device_limit\"",
    'Visibility',
] as $needle) {
    if (!str_contains($admin, $needle)) {
        fail('missing admin storefront control snippet: ' . $needle);
    }
}

echo "ok\n";