<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
if (!is_string($tenant) || $tenant === '') {
    fail('failed to read TenantController source');
}

foreach ([
    'Order Now',
    'Starting from',
    'default_client_online_device_limit',
    'Included Clients',
    'Client Device Limit',
    "?buy=",
    "name=\"plan_id\"",
    'No visible package is available right now.',
    "return Response::redirect('/tenant/plans?buy=' . (int) \$request->input('plan_id', 0) . '#plan-' . (int) \$request->input('plan_id', 0));",
] as $needle) {
    if (!str_contains($tenant, $needle)) {
        fail('missing tenant storefront snippet: ' . $needle);
    }
}

foreach ([
    '<label>Package</label>',
    '<select name="plan_id" required>',
    '<h3>ø…”√Ã◊≤Õ</h3>',
    '<thead><tr><th>Name</th><th>Description</th><th>Base Bandwidth</th><th>Included Traffic</th><th>Monthly Base Fee</th><th>Bandwidth Add-on</th><th>Client Pricing</th><th>Traffic Reset Price</th></tr></thead>',
] as $forbidden) {
    if (str_contains($tenant, $forbidden)) {
        fail('stale tenant package-table snippet still present: ' . $forbidden);
    }
}

echo "ok\n";