<?php
declare(strict_types=1);

function methodSource(string $source, string $method): string
{
    $start = strpos($source, 'public function ' . $method . '(');
    if ($start === false) throw new RuntimeException('missing core method ' . $method);
    $end = strpos($source, 'public function ', $start + 16);
    return substr($source, $start, $end === false ? null : $end - $start);
}

$admin = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$tenant = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
foreach ([
    [$admin, 'tenantsPage'], [$admin, 'servicesPage'], [$admin, 'clientsPage'], [$admin, 'nodesPage'], [$admin, 'sidecarsPage'],
    [$tenant, 'dashboard'], [$tenant, 'clientsPage'], [$tenant, 'sidecarsPage'],
] as [$source, $method]) {
    if (!str_contains(methodSource($source, $method), 'pg-mobile-resource-row')) {
        throw new RuntimeException($method . ' lacks a mobile resource row');
    }
}

$ui = (string) file_get_contents(__DIR__ . '/../src/Support/PrecisionGridUi.php');
foreach (['role="tablist"', 'aria-selected="', 'aria-label="Page views"', '<summary aria-label="'] as $needle) {
    if (!str_contains($ui, $needle)) throw new RuntimeException('accessible interaction contract missing ' . $needle);
}

$css = (string) file_get_contents(__DIR__ . '/../public/assets/operator-console.css');
foreach (['@media(max-width:720px)', 'width:38px', 'height:38px', 'min-height:40px'] as $needle) {
    if (!str_contains(str_replace(' ', '', $css), str_replace(' ', '', $needle))) {
        throw new RuntimeException('stable mobile control rule missing ' . $needle);
    }
}

echo "ok\n";
