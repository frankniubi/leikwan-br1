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
    'private function applyMaxLegsCapToPool(',
    'array_slice($pool, 0, $cap)',
    "'max_legs_used' => $maxLegs",
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing cap snippet: ' . $snippet);
    }
}

echo "ok\n";