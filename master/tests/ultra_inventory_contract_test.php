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

function failUltraInventory(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$zones = [
    'ingress' => [
        ['zone_code' => 'beijing', 'aliases' => ['北京', '北京市', 'Beijing']],
        ['zone_code' => 'shanghai', 'aliases' => ['上海', '上海市', 'Shanghai']],
        ['zone_code' => 'guangzhou', 'aliases' => ['广州', '廣州', 'Guangzhou']],
    ],
    'destination' => [
        ['zone_code' => 'hong-kong', 'aliases' => ['香港', 'Hong Kong']],
        ['zone_code' => 'tokyo', 'aliases' => ['日本', '东京', 'Tokyo', 'Japan']],
        ['zone_code' => 'frankfurt', 'aliases' => ['德国', '法兰克福', 'Germany']],
        ['zone_code' => 'los-angeles', 'aliases' => ['美国', '洛杉矶', 'Los Angeles']],
        ['zone_code' => 'moscow', 'aliases' => ['俄罗斯', '莫斯科', 'Moscow']],
        ['zone_code' => 'singapore', 'aliases' => ['新加坡', 'Singapore']],
    ],
];

$normalized = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 453,
    'title' => '上海-日本-BGP-层峰【主机】',
    'family_key' => '上海-日本-BGP-层峰',
    'game_id' => 6262,
    'area_id' => 11796,
    'line_type' => 8,
    'discovery_ip' => '203.0.113.20',
    'config_sha256' => str_repeat('b', 64),
], $zones, 1_893_456_000);

if (($normalized['line_id'] ?? 0) !== 453
    || ($normalized['auto_ingress_zone'] ?? '') !== 'shanghai'
    || ($normalized['auto_destination_zone'] ?? '') !== 'tokyo'
    || ($normalized['is_enabled'] ?? 1) !== 0
    || ($normalized['is_source_present'] ?? 0) !== 1) {
    failUltraInventory('inventory line was not normalized and categorized');
}

$unknown = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 900,
    'title' => 'Unmapped route',
    'config_sha256' => str_repeat('c', 64),
], $zones, 1_893_456_000);
if (($unknown['auto_ingress_zone'] ?? '') !== 'unknown' || ($unknown['auto_destination_zone'] ?? '') !== 'unknown') {
    failUltraInventory('unknown title must remain uncategorized');
}

$invalid = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 0,
    'title' => '上海-日本',
    'discovery_ip' => 'not-an-ip',
    'config_sha256' => 'bad',
], $zones, 1_893_456_000);
if ($invalid !== null) {
    failUltraInventory('invalid inventory line was accepted');
}

$ambiguousZones = [
    ['zone_code' => 'a', 'aliases' => ['shared']],
    ['zone_code' => 'b', 'aliases' => ['shared']],
];
if (UltraInventoryService::classifyText('shared route', $ambiguousZones) !== 'unknown') {
    failUltraInventory('ambiguous aliases must not choose a zone');
}

$source = file_get_contents(__DIR__ . '/../src/Support/UltraInventoryService.php');
foreach ([
    'MAX_INVENTORY_LINES = 256',
    'is_source_present = 0',
    "'is_enabled' => 0",
    'admin_ingress_zone',
    'admin_destination_zone',
    'ON DUPLICATE KEY UPDATE',
] as $needle) {
    if (!str_contains((string) $source, $needle)) {
        failUltraInventory('missing inventory persistence boundary: ' . $needle);
    }
}

echo "ok\n";
