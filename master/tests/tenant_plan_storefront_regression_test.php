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
$css = file_get_contents(__DIR__ . '/../public/assets/storefront.css');
if (!is_string($css) || $css === '') {
    fail('failed to read panel stylesheet');
}
$plansStart = strpos($tenant, 'public function plansPage');
$plansEnd = strpos($tenant, 'public function transferBalance', $plansStart === false ? 0 : $plansStart);
if ($plansStart === false || $plansEnd === false) {
    fail('failed to isolate tenant plans page source');
}
$storefront = substr($tenant, $plansStart, $plansEnd - $plansStart);

foreach ([
    '/assets/storefront.css?v=20260727a',
    'storefront-shell',
    'storefront-categories',
    'storefront-products',
    'storefront-product',
    'storefront-product-price',
    'storefront-order',
    'action="/tenant/plans/purchase"',
    'Order Now',
    'Starting from',
    'default_bandwidth_limit_mbps',
    'default_client_online_device_limit',
    'Included Clients',
    'Client Device Limit',
    "?buy=",
    "name=\"plan_id\"",
    'No visible package is available right now.',
] as $needle) {
    if (!str_contains($storefront, $needle)) {
        fail('missing tenant storefront snippet: ' . $needle);
    }
}

$failureRedirect = "return Response::redirect('/tenant/plans?buy=' . (int) \$request->input('plan_id', 0) . '#plan-' . (int) \$request->input('plan_id', 0));";
if (!str_contains($tenant, $failureRedirect)) {
    fail('missing tenant purchase failure redirect');
}

foreach ([
    '.storefront-shell',
    '.storefront-categories',
    '.storefront-products',
    '.storefront-product-price',
    '.storefront-order',
    '@media(max-width:1100px)',
    '@media(max-width:720px)',
] as $needle) {
    if (!str_contains($css, $needle)) {
        fail('missing scoped storefront style: ' . $needle);
    }
}

if (preg_match('/\.storefront-products\s*\{[^}]*\balign-items:\s*start\s*;/s', $css) !== 1) {
    fail('storefront product grid must top-align cards independently');
}

foreach ([
    '<section class="card" style="margin-top:20px;">',
    'style="display:flex;flex-direction:column;justify-content:space-between;min-height:340px;"',
    'style="align-items:flex-start;gap:22px;"',
    'style="text-align:right;min-width:220px;"',
    '<label>Package</label>',
    '<select name="plan_id" required>',
    '<h3>可用套餐</h3>',
    '<thead><tr><th>Name</th><th>Description</th><th>Base Bandwidth</th><th>Included Traffic</th><th>Monthly Base Fee</th><th>Bandwidth Add-on</th><th>Client Pricing</th><th>Traffic Reset Price</th></tr></thead>',
] as $forbidden) {
    if (str_contains($storefront, $forbidden)) {
        fail('stale tenant package-table snippet still present: ' . $forbidden);
    }
}

echo "ok\n";
