<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$sourcePath = __DIR__ . '/../src/Support/ProvisioningService.php';
$source = file_get_contents($sourcePath);
if ($source === false) {
    fail('unable to read ProvisioningService.php');
}

$failures = [];
$expectedConfigRead = '$this->config->getInt(\'BOND_LEIGOD_WG_MTU\', 1280)';
if (!str_contains($source, $expectedConfigRead)) {
    $failures[] = 'ProvisioningService missing BOND_LEIGOD_WG_MTU config MTU read';
}

$hardcodedMtu = '$slave[\'mtu\'] = 1280;';
if (str_contains($source, $hardcodedMtu)) {
    $failures[] = 'ProvisioningService still contains hardcoded leigod WG MTU literal';
}

if ($failures !== []) {
    fail(implode(PHP_EOL, $failures));
}

echo "OK\n";
exit(0);