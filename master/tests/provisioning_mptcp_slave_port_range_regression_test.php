<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$path = __DIR__ . '/../src/Support/ProvisioningService.php';
$source = file_get_contents($path);
if (!is_string($source) || $source === '') {
    fail('failed to read ProvisioningService source');
}

foreach (['mptcpSlavePortRangeStart', 'mptcpSlavePortRangeEnd', 'stableMptcpSlavePort'] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing port-range helper snippet: ' . $snippet);
    }
}

foreach (['mptcpSlavePortRangeStart', 'mptcpSlavePortRangeEnd', '$rangeSize', '$rangeStart'] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('stableMptcpSlavePort must clamp/remap into configured range, missing ' . $snippet);
    }
}

if (preg_match('/return\s+\$basePort\s*\+\s*\(\(\$forwardId\s*\*\s*7\)\s*%\s*200\)/', $source)) {
    fail('stableMptcpSlavePort still uses old unbounded basePort offset');
}

echo "ok\n";
