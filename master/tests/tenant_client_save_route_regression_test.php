<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$controller = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');

if (!is_string($bootstrap) || $bootstrap === '') {
    fail('failed to read Bootstrap source');
}

if (!is_string($controller) || $controller === '') {
    fail('failed to read TenantController source');
}

if (!str_contains($bootstrap, "\$this->router->add('POST', '/tenant/clients', [\$tenant, 'saveClient']);")) {
    fail('tenant client POST route is missing');
}

foreach ([
    'public function saveClient(Request $request, array $params): Response',
    "throw new ApiException('feature_denied_manage_clients', 403);",
    "SessionAuth::flash('success', 'Client saved.');",
] as $required) {
    if (!str_contains($controller, $required)) {
        fail('missing tenant client save handler snippet: ' . $required);
    }
}

echo "ok\n";
