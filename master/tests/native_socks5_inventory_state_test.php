<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\UltraInventoryService;

function failNativeInventory(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$zones = ['ingress' => [], 'destination' => []];
$base = [
    'line_id' => 1,
    'config_sha256' => str_repeat('a', 64),
    'access_class' => 'native_udp',
    'native_udp_state' => 'verified',
];
$verified = UltraInventoryService::normalizeInventoryLine($base + ['native_socks5_state' => 'verified'], $zones, 1_900_000_000);
if (!is_array($verified) || ($verified['native_socks5_state'] ?? '') !== 'verified') {
    failNativeInventory('verified native SOCKS5 state was not retained');
}
$failed = UltraInventoryService::normalizeInventoryLine($base + ['native_socks5_state' => 'failed'], $zones, 1_900_000_000);
if (!is_array($failed) || ($failed['native_socks5_state'] ?? '') !== 'failed') {
    failNativeInventory('failed native SOCKS5 state was not retained');
}
$unknown = UltraInventoryService::normalizeInventoryLine($base + ['native_socks5_state' => 'provider-secret'], $zones, 1_900_000_000);
if (!is_array($unknown) || ($unknown['native_socks5_state'] ?? '') !== 'unknown') {
    failNativeInventory('invalid native SOCKS5 state did not fail closed');
}

$source = (string) file_get_contents(__DIR__ . '/../src/Support/UltraInventoryService.php');
foreach ([
    'NATIVE_SOCKS5_STATES',
    "'native_socks5_state' => \$rawNativeSocks5State",
    'native_socks5_state = VALUES(native_socks5_state)',
    "'native_socks5_state' => self::NATIVE_SOCKS5_STATES",
] as $needle) {
    if (!str_contains($source, $needle)) {
        failNativeInventory('missing native SOCKS5 inventory boundary: ' . $needle);
    }
}
$adminSource = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
foreach (['native_socks5_state', 'SOCKS5 state', 'Class / UDP / SOCKS5'] as $needle) {
    if (!str_contains($adminSource, $needle)) {
        failNativeInventory('admin inventory view is missing native SOCKS5 state: ' . $needle);
    }
}

echo "ok\n";
