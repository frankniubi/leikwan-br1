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
$device = (string) file_get_contents(__DIR__ . '/../src/Support/DeviceControlService.php');
$policy = (string) file_get_contents(__DIR__ . '/../src/Support/AccessPolicyService.php');

assertContains($tenant, 'tenantDeviceRows(', 'tenant must load owned device rows');
assertContains($tenant, 'device_not_owned', 'tenant device actions must enforce ownership');
assertContains($tenant, "'manual_tenant'", 'tenant kick must use manual_tenant origin');
assertContains($tenant, 'releaseTemporaryBlock(', 'tenant restore must use temporary release');
$kickStart = strpos($tenant, 'public function kickClientDevice(');
$kickEnd = strpos($tenant, 'public function banClientDevice(', $kickStart ?: 0);
$kick = ($kickStart !== false && $kickEnd !== false) ? substr($tenant, $kickStart, $kickEnd - $kickStart) : '';
if (str_contains($kick, "str_starts_with(\$deviceId, 'android-client-')")) {
    fail('single-device kick must accept distribution-neutral UUIDs');
}
assertContains($device, 'requireStableDeviceId($deviceId)', 'generic device controls must accept stable UUIDs');
assertContains($device, "'manual_tenant' => 'tenant_or_admin'", 'manual tenant release policy missing');
assertContains($device, "'policy', 'province_reset' => 'admin_only'", 'policy/admin release policy missing');
assertContains($policy, 'main_province_change_rate_limited', 'daily province change guard missing');
assertContains($policy, 'main_province_observation_required', 'fresh observation guard missing');

$resetStart = strpos($tenant, 'public function kickAllDevices(');
$resetEnd = strpos($tenant, 'public function restoreDevice(', $resetStart ?: 0);
$reset = ($resetStart !== false && $resetEnd !== false) ? substr($tenant, $resetStart, $resetEnd - $resetStart) : '';
if (str_contains($reset, 'SessionAuth::logout()')) {
    fail('kick-all must preserve the authenticated web session');
}

echo "ok\n";
