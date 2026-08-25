<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$bootstrap = file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$source = $provisioning . $tenant . $bootstrap;

foreach ([
    "public function setForwardEnabled(",
    "public function forwardCycleUsageMap(",
    "public function pausedForwardsForService(",
    "'forward.paused'",
    "'forward.started'",
    "revokeForwardEndpoints(\$forwardId, 'pause')",
    "reconcileForwardEndpoints(\$forwardId)",
    "traffic_usage_hourly",
    "service_endpoints se ON se.id = tuh.endpoint_id",
    "period_start >= :cycle_started_at",
    "balance_suspended_at IS NULL",
    "/tenant/services/{id}/forward/{fid}/toggle",
    "public function toggleForward(Request \$request, array \$params): Response",
    "\$action === 'start'",
    "setForwardEnabled(",
    "本期用量",
    "formatTrafficBytes",
    "'Pause'",
    "'Start'",
    ">Modify<",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing snippet: ' . $snippet);
    }
}

foreach ([
    'View client config',
    "escape((string) \$service['client_token'])",
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fail('stale snippet still present: ' . $forbidden);
    }
}

echo "ok\n";
