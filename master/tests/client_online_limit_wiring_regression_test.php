<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$clientController = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    // Enforcement moved out of Auth::authenticateClient (1f7858f4) into the
    // client-agent controller, so every pull route and the heartbeat enforce
    // the online-device limit after access policy and device trust checks.
    [$clientController, 'use Leikwan\Master\Support\ClientOnlineSessionService;'],
    [$clientController, 'enforceFromRequest($client, $request, \'pull\')'],
    [$clientController, 'return [$sessions, $sessions->enforceFromRequest($client, $request, $touchType)];'],
    [$clientController, "[\$deviceSessionService, \$deviceId] = \$this->enforceClientRequest(\$client, \$request, 'heartbeat');"],
    [$clientController, "[\$deviceSessionService, \$deviceId] = \$this->enforceClientRequest(\$client, \$request, 'pull');"],
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

echo "ok\n";
