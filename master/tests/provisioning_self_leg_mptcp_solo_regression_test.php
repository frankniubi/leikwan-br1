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
    "'self_leg_mptcp_solo_allowed' =>",
    "(string) (\$config['runtime_preference'] ?? '') === 'mptcp_preferred_bond_fallback'",
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing solo-mptcp snippet: ' . $snippet);
    }
}

echo "ok\n";