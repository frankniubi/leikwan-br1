<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
foreach ([
    "mptcp_preferred_bond_fallback",
    "candidate_pool",
    "runtime_capabilities' => ['bond']",
    "runtime_capabilities' => ['bond', 'mptcp']",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing runtime-preference snippet: ' . $snippet);
    }
}

echo "ok\n";
