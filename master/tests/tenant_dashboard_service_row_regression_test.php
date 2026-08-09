<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
if (!is_string($source) || $source === '') {
    fail('failed to read TenantController source');
}
if (!is_string($bootstrap) || $bootstrap === '') {
    fail('failed to read Bootstrap source');
}

$start = strpos($source, 'public function dashboard(Request $request, array $params): Response');
$end = strpos($source, 'public function billingPage(Request $request, array $params): Response');
if ($start === false || $end === false || $end <= $start) {
    fail('failed to isolate dashboard method');
}

$dashboard = substr($source, $start, $end - $start);

if (!str_contains($dashboard, 'LEFT JOIN forwards f ON f.service_id = s.id AND f.enabled = 1')) {
    fail('dashboard must filter enabled forwards in the LEFT JOIN so services with only disabled forwards remain visible');
}
if (str_contains($dashboard, 'f.id IS NULL OR f.enabled = 1')) {
    fail('dashboard must not filter forwards in WHERE because that removes services with only disabled forwards');
}

foreach ([
    "(int) \$service['id']",
    "(string) \$service['name']",
    "/tenant/services/' . (int) \$service['id'] . '/create-forward",
    "/tenant/services/' . (int) \$service['id'] . '",
] as $required) {
    if (!str_contains($dashboard, $required)) {
        fail('dashboard service-row snippet missing: ' . $required);
    }
}

foreach ([
    "(int) \$forward['id']",
    "\$linuxInstallCommand",
    "\$windowsInstallCommand",
    "\$configJson",
] as $forbidden) {
    if (str_contains($dashboard, $forbidden)) {
        fail('dashboard still contains forward-detail snippet: ' . $forbidden);
    }
}

if (str_contains($source, '/tenant-services-internal/')) {
    fail('TenantController still emits retired tenant-services-internal routes');
}
if (!str_contains($bootstrap, "'/tenant/services/{id}/enrollment-token'")) {
    fail('Bootstrap missing the tenant enrollment-token route');
}

echo "ok\n";
