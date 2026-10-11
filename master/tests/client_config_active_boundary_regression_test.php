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
    "UltraMode::BACKEND_MODE",
    "'ultra_material_v1_retired'",
    "], 410)",
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
// The tenant service page used to render a "View client config" preview gated
// on an active service. The simplified service detail (618ffe0f) removed the
// raw config/token preview entirely, which is a strictly stronger boundary:
// pin that no runtime client config is built or shown from that page.
$detailStart = strpos($tenant, 'public function serviceDetail(');
$detailEnd = $detailStart === false ? false : strpos($tenant, 'public function forwardWizardRedirect(', $detailStart);
if ($detailStart === false || $detailEnd === false) {
    failClientConfigBoundary('tenant service page (serviceDetail) not found');
}
$serviceDetail = substr($tenant, $detailStart, $detailEnd - $detailStart);
if (!str_contains($serviceDetail, 'ServiceStatus::effective($service)')) {
    failClientConfigBoundary('tenant service page must derive the effective service status');
}
foreach ([
    'buildClientConfig(',
    'View client config',
    "allow_view_client_config",
] as $forbidden) {
    if (str_contains($serviceDetail, $forbidden)) {
        failClientConfigBoundary('tenant service page must not expose runtime client config: ' . $forbidden);
    }
}

echo "ok\n";
