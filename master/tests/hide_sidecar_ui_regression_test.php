<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$zhTw = json_decode((string) file_get_contents(__DIR__ . '/../public/i18n/zh-TW.json'), true);
$zhHans = json_decode((string) file_get_contents(__DIR__ . '/../public/i18n/zh-Hans.json'), true);

$combined = (string) $tenant . "\n" . (string) $admin . "\n" . (string) $base . "\n" . (string) $bootstrap;

foreach ([
    '/tenant/sidecars',
    "/tenant' . '/sidecars",
    '/admin/sidecars',
    "/admin' . '/sidecars",
    'href="/admin" . "/transport-endpoints',
    'Sidecar)',
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
    "tenantNav('sidecars')",
] as $needle) {
    if (str_contains($combined, $needle)) {
        fail('sidecar ui still exposed: ' . $needle);
    }
}

foreach ([
    'Sidecar Points',
    'Saved Sidecar Points',
    'Allowed Sidecar Points',
    'Add Your Own Slave',
    'Blend exchange access and private-line capacity by region, node group, tenant plan, and sidecar target.',
    'Install flow, route modes, diagnostics, sidecar rollout, and recovery notes live in the panel docs.',
] as $oldLocaleKey) {
    foreach (['zh-TW' => $zhTw, 'zh-Hans' => $zhHans] as $locale => $dictionary) {
        if (array_key_exists($oldLocaleKey, $dictionary['text'] ?? [])) {
            fail('old sidecar/front-door locale key still present for [' . $locale . ']: ' . $oldLocaleKey);
        }
    }
}

foreach ([
    '/tenant/clients',
    '/tenant/forwards',
    '/tenant-transport-endpoints',
    '/admin-transport-endpoints',
    'Client Profiles',
    'Create Client',
    'Transport Endpoints',
] as $needle) {
    if (!str_contains((string) $tenant . (string) $admin . (string) $base . (string) $bootstrap, $needle)) {
        fail('profile-first tenant surface missing: ' . $needle);
    }
}

echo "ok\n";