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

if (!str_contains($source, 'stableLocalUotUdpPort')) {
    fail('expected ProvisioningService to define stableLocalUotUdpPort');
}

if (!str_contains($source, "'local_uot_udp_port' =>")) {
    fail('expected ProvisioningService to emit local_uot_udp_port in MPTCP underlay payload');
}

echo "ok\n";
