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
    'private function defaultMaxLegsUsedForCombination(',
    "['bond', 'mptcp_preferred_bond_fallback']",
    "if (\$helperGroupCap <= 0)",
    'DEFAULT_MAX_LEGS_USED = 4',
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing default-max-legs snippet: ' . $snippet);
    }
}

echo "ok\n";