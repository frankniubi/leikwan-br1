<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$tenant = file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$slave = file_get_contents(dirname(__DIR__) . '/../slave-agent/slave_agent.py');

foreach ([
    'Current Best CF IP',
    'last_jitter_ms',
    'last_latency_ms',
    'forward_combination_name',
    'cf_location_stats',
] as $snippet) {
    if (!str_contains($admin . $tenant . $slave, $snippet)) {
        fail('missing observability snippet: ' . $snippet);
    }
}

echo "ok\n";
