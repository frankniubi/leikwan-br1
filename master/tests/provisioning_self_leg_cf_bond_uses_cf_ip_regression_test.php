<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($source === false) {
    fail('failed to read ProvisioningService.php');
}

foreach ([
    "if ((string) (\$config['backend_mode'] ?? '') === 'cf_mode')",
    "'probe_transport' => 'cf_direct_wss'",
    "'self_leg_wg' => null",
    'self_leg_cf_only_under_cf_bond',
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing CF Bond self-leg snippet: ' . $snippet);
    }
}

echo "ok\n";