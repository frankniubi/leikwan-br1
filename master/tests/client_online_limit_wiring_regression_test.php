<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$auth = file_get_contents(__DIR__ . '/../src/Support/Auth.php');
$clientController = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    [$auth, 'ClientOnlineSessionService'],
    [$auth, 'enforceFromRequest($client, $request)'],
    [$clientController, 'enforceFromRequest($client, $request, \'pull\')'],
    [$clientController, "recordAndEnforce(\$client, \$deviceId, \$deviceSessionService->deviceMetadataFromRequest(\$request), 'heartbeat')"],
    [$clientController, "'active_online_devices' =>"],
    [$tenant, 'online_device_limit'],
    [$provisioning, "'online_device_limit' =>"],
    [$schema, 'online_device_limit INT NOT NULL DEFAULT 1'],
    [$schema, 'CREATE TABLE IF NOT EXISTS client_device_sessions'],
] as [$haystack, $needle]) {
    if (!is_string($haystack) || !str_contains($haystack, $needle)) {
        fail('missing online-limit wiring snippet: ' . $needle);
    }
}

// WHMCS create/update, WHMCS renew and wallet purchase must each lift
// pre-existing clients to the plan's device limit.
if (substr_count((string) $provisioning, '$this->raiseClientDeviceLimitsToPlanDefault(') < 3) {
    fail('expected plan purchase, WHMCS provision and renew to raise client device limits');
}

echo "ok\n";
