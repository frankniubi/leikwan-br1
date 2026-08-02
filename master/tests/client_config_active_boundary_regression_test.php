<?php
declare(strict_types=1);

function failClientConfigBoundary(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$service = file_get_contents(__DIR__ . '/../src/Controllers/ServiceController.php') ?: '';
$auth = file_get_contents(__DIR__ . '/../src/Support/Auth.php') ?: '';
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php') ?: '';

$clientStart = strpos($service, 'public function clientConfig(');
$clientEnd = strpos($service, 'public function regenerateClientToken(', $clientStart === false ? 0 : $clientStart);
$clientConfig = ($clientStart !== false && $clientEnd !== false)
    ? substr($service, $clientStart, $clientEnd - $clientStart)
    : '';

foreach ([
    'f.enabled = 1',
    's.status = :active_status',
    'legacyForwardErrorResponse(',
    "'Cache-Control' => 'no-store'",
] as $required) {
    if (!str_contains($clientConfig, $required)) {
        failClientConfigBoundary('legacy client-config boundary missing: ' . $required);
    }
}

foreach ([$auth, $provisioning] as $source) {
    if (!str_contains($source, 'f.enabled = 1') || !str_contains($source, 's.status = :active_status')) {
        failClientConfigBoundary('client token/config defense must reject inactive service or deleted forward');
    }
}
if (!str_contains($tenant, '$serviceIsActive')
    || !str_contains($tenant, "!empty(\$features['allow_view_client_config']) && \$serviceIsActive")) {
    failClientConfigBoundary('tenant service page must suppress runtime config for inactive services');
}

echo "ok\n";
