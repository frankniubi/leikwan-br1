<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');

foreach ([
    '$targetSource = (string) ($payload[\'target_source\'] ?? \'manual\')',
    '$targetServiceId = (int) ($payload[\'target_service_id\'] ?? 0)',
    'target_service_id',
    'server_public_ip',
    'target_source',
] as $needle) {
    if (!str_contains((string) $provisioning, $needle)) {
        fail('missing Bridge Lite target-service backend snippet: ' . $needle);
    }
}

$wizardStart = strpos((string) $tenant, 'public function quickCreateForward');
$wizardEnd = strpos((string) $tenant, 'public function editForward', $wizardStart === false ? 0 : $wizardStart);
if ($wizardStart === false || $wizardEnd === false) {
    fail('failed to isolate tenant forward wizard');
}
$wizard = substr((string) $tenant, $wizardStart, $wizardEnd - $wizardStart);

foreach ([
    '<input type="hidden" name="target_source" value="manual">',
    '<input type="text" name="target_host" placeholder="10.0.0.5 or example.com" required>',
] as $needle) {
    if (!str_contains($wizard, $needle)) {
        fail('missing manual LeiKwan Bridge target field: ' . $needle);
    }
}

foreach ([
    'name="target_service_id"',
    'value="service_ip"',
    'This forward uses the purchased server IP automatically',
    'readonly',
] as $needle) {
    if (str_contains($wizard, $needle)) {
        fail('Bridge Lite target behavior leaked into LeiKwan Bridge wizard: ' . $needle);
    }
}

echo "ok\n";
