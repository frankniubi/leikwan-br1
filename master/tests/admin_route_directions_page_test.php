<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php') ?: '';
$base = file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php') ?: '';
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php') ?: '';

foreach ([
    "add('GET', '/admin/route-directions', [\$admin, 'routeDirectionsPage'])",
    "add('POST', '/admin/route-directions', [\$admin, 'saveRouteDirection'])",
    "add('POST', '/admin/route-directions/entitlements', [\$admin, 'saveRouteDirectionEntitlements'])",
    "add('POST', '/admin/route-directions/rules/{id}/delete', [\$admin, 'deleteClientRuleFromAdmin'])",
] as $route) {
    if (!str_contains($bootstrap, $route)) {
        fail('Bootstrap missing admin route ' . $route);
    }
}

if (!str_contains($base, "'href' => '/admin/route-directions', 'label' => 'Route Directions'")) {
    fail('admin navigation missing Route Directions');
}

foreach ([
    'public function routeDirectionsPage(',
    'public function saveRouteDirection(',
    'public function saveRouteDirectionEntitlements(',
    'public function deleteClientRuleFromAdmin(',
    'name="direction_ids[]"',
    'name="max_client_rules_per_service_scope"',
    'name="local_port"',
    // A ready binding always uses one shared TCP+UDP port (Android applies equal ports only).
    "'local_tcp_port' => \$port,",
    "'local_udp_port' => \$port,",
    "tombstoneClientRuleFromAdmin((string) (\$params['id'] ?? ''), 'admin')",
] as $snippet) {
    if (!str_contains($admin, $snippet)) {
        fail('AdminController missing route-direction snippet ' . $snippet);
    }
}

// Every write handler validates CSRF before touching data.
foreach (['saveRouteDirection', 'saveRouteDirectionEntitlements', 'deleteClientRuleFromAdmin'] as $handler) {
    $start = strpos($admin, 'public function ' . $handler . '(');
    $body = $start === false ? '' : substr($admin, $start, 900);
    if (!str_contains($body, 'SessionAuth::validateCsrf($request);')) {
        fail($handler . ' must validate CSRF');
    }
}

echo "ok\n";
