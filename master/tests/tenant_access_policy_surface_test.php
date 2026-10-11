<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertContains(string $source, string $needle, string $message): void
{
    if (!str_contains($source, $needle)) {
        fail($message . ': ' . $needle);
    }
}

$tenant = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');

foreach ([
    'public function accessSecurityPage(Request $request, array $params): Response',
    'public function changeMainProvince(Request $request, array $params): Response',
    'public function kickAllDevices(Request $request, array $params): Response',
    'public function restoreDevice(Request $request, array $params): Response',
    'main_province',
    'self_change_available_at',
    'Kick all devices',
    'manual_tenant',
    'beginAllDeviceReset(',
    'changeMainProvince(',
    'DeviceControlService',
] as $needle) {
    assertContains($tenant, $needle, 'tenant access-policy surface missing');
}

foreach ([
    "'/tenant/security'",
    "'/tenant/security/main-province'",
    "'/tenant/security/kick-all'",
    "'/tenant/security/devices/restore'",
] as $needle) {
    assertContains($bootstrap, $needle, 'tenant access-policy route missing');
}
assertContains($tenant, "'/tenant/security'", 'tenant security navigation item missing');

foreach (['changeMainProvince', 'kickAllDevices', 'restoreDevice'] as $method) {
    $start = strpos($tenant, 'public function ' . $method . '(');
    $body = $start === false ? '' : substr($tenant, $start, 5000);
    assertContains($body, 'SessionAuth::validateCsrf($request)', $method . ' must validate CSRF');
}

echo "ok\n";
