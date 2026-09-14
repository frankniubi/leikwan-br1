<?php
declare(strict_types=1);

function failUltraAdmin(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$admin = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$base = (string) file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');

foreach ([
    "'/admin/ultra'",
    "'ultraOperationsPage'",
    "'/admin/ultra/lines/{id}'",
    "'saveUltraLinePolicy'",
] as $snippet) {
    if (!str_contains($bootstrap, $snippet)) {
        failUltraAdmin('missing Ultra admin route: ' . $snippet);
    }
}

foreach ([
    "'href' => '/admin/ultra'",
    "'label' => 'Ultra Operations'",
    "'href' => '/admin/forward-combinations'",
    "'label' => 'Forward Combinations'",
] as $snippet) {
    if (!str_contains($base, $snippet)) {
        failUltraAdmin('missing shared Ultra navigation: ' . $snippet);
    }
}

$start = strpos($admin, 'public function ultraOperationsPage');
$end = $start === false ? false : strpos($admin, 'public function saveUltraLinePolicy', $start);
if ($start === false || $end === false) {
    failUltraAdmin('Ultra operations methods are missing');
}
$page = substr($admin, $start, $end - $start);
foreach ([
    'view=sessions',
    'view=lines',
    'view=combinations',
    'view=source',
    'New Ultra Combination',
    'backend_mode=ultra',
    'Online',
    'Stale',
    'Offline',
    'Latest TCP',
    'Active UDP',
    'Catalog ingress',
    'Effective ingress',
    'Catalog destination',
    'Effective destination',
    'Observed relay IPs',
    'Material state',
    'catalog_ingress',
    'catalog_destination',
    'Filter lines',
    'Include unavailable lines',
    'Material not required',
    'min(200',
    'Lines per page',
    'http_build_query',
    'include_absent',
    'sessionsForAdmin',
    'listLinesForAdmin',
    'listUltraCombinationsForAdmin',
    'listSourcesForAdmin',
] as $snippet) {
    if (!str_contains($page, $snippet)) {
        failUltraAdmin('Ultra operations page missing contract: ' . $snippet);
    }
}
foreach (['sealed_material', 'relay_port', 'source_secret', 'credential'] as $forbidden) {
    if (stripos($page, $forbidden) !== false) {
        failUltraAdmin('Ultra operations page exposes forbidden field: ' . $forbidden);
    }
}

echo "ok\n";
