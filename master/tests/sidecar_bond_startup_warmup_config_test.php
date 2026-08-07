<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$controller = file_get_contents(__DIR__ . '/../src/Controllers/SidecarAgentController.php');
$provisioning = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if (!is_string($controller) || !is_string($provisioning)) {
    fail('failed to read warmup configuration sources');
}

foreach ([
    "\$runtimeState['bond_startup_warmup_bytes_per_leg']",
    'BOND_STARTUP_WARMUP_BYTES_PER_LEG',
    "'startup_warmup_bytes_per_leg' =>",
    '2 * 1024 * 1024',
] as $snippet) {
    if (!str_contains($controller, $snippet)) {
        fail('missing current_mode bond warmup wiring: ' . $snippet);
    }
}

foreach ([
    'BOND_LEIGOD_STARTUP_WARMUP_BYTES_PER_LEG',
    'BOND_LEIGOD_INITIAL_LEG_WAIT_MS',
    "'startup_warmup_bytes_per_leg' =>",
    "'initial_leg_wait_ms' =>",
] as $snippet) {
    if (!str_contains($provisioning, $snippet)) {
        fail('missing managed LeiGod local-bond fast-start policy: ' . $snippet);
    }
}

echo "ok\n";
