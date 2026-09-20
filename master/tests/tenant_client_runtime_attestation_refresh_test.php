<?php
declare(strict_types=1);

function failRuntimeRefresh(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$tenant = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$status = (string) file_get_contents(__DIR__ . '/../src/Support/UltraClientStatusService.php');

foreach ([
    "\$this->router->add('POST', '/tenant/clients/runtime-attestation/refresh', [\$tenant, 'refreshClientRuntimeAttestation']);",
    'public function refreshClientRuntimeAttestation(Request $request, array $params): Response',
    'SessionAuth::validateCsrf($request);',
    'ownedTenantDevice($user, $request)',
    'clearRuntimeAttestation($clientId, $deviceId)',
    "'/tenant/clients/runtime-attestation/refresh'",
    '刷新运行时',
] as $required) {
    if (!str_contains($bootstrap . $tenant . $status, $required)) {
        failRuntimeRefresh('missing tenant runtime refresh contract: ' . $required);
    }
}

$start = strpos($tenant, 'public function refreshClientRuntimeAttestation(');
$end = strpos($tenant, 'public function kickClientDevice(', $start ?: 0);
if (!is_int($start) || !is_int($end) || $end <= $start) {
    failRuntimeRefresh('failed to isolate tenant runtime refresh method');
}
$method = substr($tenant, $start, $end - $start);
if (str_contains($method, '$request->input(\'device_uuid\'')
    || str_contains($method, '$request->input(\'device_id\'')) {
    failRuntimeRefresh('refresh method must delegate device ownership validation');
}

echo "ok\n";
