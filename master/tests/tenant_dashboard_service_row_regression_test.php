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

$start = strpos($source, 'public function dashboard(Request $request, array $params): Response');
$end = strpos($source, 'public function billingPage(Request $request, array $params): Response');
if ($start === false || $end === false || $end <= $start) {
    fail('failed to isolate dashboard method');
}

$dashboard = substr($source, $start, $end - $start);

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

echo "ok\n";
