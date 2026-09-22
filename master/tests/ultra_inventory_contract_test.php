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

$audited = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 327,
    'title' => 'Shanghai-Korea-DYN',
    'access_class' => 'dynamic_ingress',
    'native_udp_state' => 'unverified',
    'route_class' => 'single_telecom',
    'pricing_class' => 'paid',
    'pricing_evidence' => 'audit-plan',
    'audit_at' => '2026-08-19T15:33:09Z',
    'config_sha256' => str_repeat('e', 64),
], $zones, 1_893_456_000);
if (($audited['access_class'] ?? '') !== 'dynamic_ingress'
    || ($audited['native_udp_state'] ?? '') !== 'unverified'
    || ($audited['route_class'] ?? '') !== 'single_telecom'
    || ($audited['pricing_class'] ?? '') !== 'paid'
    || ($audited['audit_at'] ?? '') !== '2026-08-19 15:33:09'
    || ($audited['is_enabled'] ?? 1) !== 0) {
    failUltraInventory('audited line classifications were not preserved');
}

// Carrier metadata (2026-09-11): source hosts report 电信/联通/移动/通用, the catalog
// stores a stable code so single-carrier lines can be filtered and classified.
$carrier = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 993,
    'title' => '香港-韩国',
    'isp' => '联通',
    'provider' => '层峰',
    'in_line_type' => 3,
    'config_sha256' => str_repeat('f', 64),
], $zones, 1_893_456_000);
if (($carrier['isp'] ?? '') !== 'unicom'
    || ($carrier['provider'] ?? '') !== '层峰'
    || ($carrier['in_line_type'] ?? null) !== 3) {
    failUltraInventory('carrier metadata was not normalized and stored');
}

$carrierCodes = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 327,
    'title' => '上海-韩国',
    'isp' => 'telecom',
    'config_sha256' => str_repeat('9', 64),
], $zones, 1_893_456_000);
if (($carrierCodes['isp'] ?? '') !== 'telecom' || ($carrierCodes['provider'] ?? null) !== null) {
    failUltraInventory('english carrier codes must normalize as well');
}

$carrierUnknown = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 994,
    'title' => 'X',
    'isp' => 'not-a-carrier',
    'provider' => str_repeat('p', 200),
    'config_sha256' => str_repeat('a', 64),
], $zones, 1_893_456_000);
if (($carrierUnknown['isp'] ?? '') !== 'unknown'
    || strlen((string) ($carrierUnknown['provider'] ?? '')) !== 64) {
    failUltraInventory('unknown carrier must degrade to unknown and provider must be bounded');
}

// A provider/title label is not evidence that a particular line is unhealthy.
// Only an explicit per-line audit declaration may demote it.
foreach ([
    ['provider' => '腾讯云', 'title' => '北京-德国-BGP'],
    ['provider' => '', 'title' => '上海-日本-BGP-腾讯云【本土】'],
    ['provider' => 'UCloud', 'title' => 'Shanghai-Tokyo-BGP'],
    ['provider' => '', 'title' => 'Shanghai-Tokyo-UCloud'],
    ['provider' => '优云', 'title' => '上海-日本-BGP-优云'],
    ['provider' => '层峰', 'title' => '上海-韩国-电信'],
    [],
] as $unmeasuredProviderLine) {
    if (UltraInventoryService::dispatchClass($unmeasuredProviderLine) !== 'normal') {
        failUltraInventory('unmeasured provider/title must not receive low priority');
    }
}
$ucloudDeclaredNormal = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 497,
    'title' => 'Shanghai-Tokyo-UCloud',
    'provider' => 'UCloud',
    'dispatch_class' => 'normal',
    'config_sha256' => str_repeat('3', 64),
], $zones, 1_893_456_000);
if (($ucloudDeclaredNormal['dispatch_class'] ?? '') !== 'normal') {
    failUltraInventory('explicit normal declaration must override provider/title labels');
}
$declaredLow = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 495,
    'title' => '上海-韩国-BGP-层峰',
    'provider' => '层峰',
    'dispatch_class' => 'low',
    'config_sha256' => str_repeat('8', 64),
], $zones, 1_893_456_000);
if (($declaredLow['dispatch_class'] ?? '') !== 'low'
    || UltraInventoryService::dispatchClass(['provider' => '腾讯云', 'dispatch_class' => 'low']) !== 'low') {
    failUltraInventory('explicit per-line low declaration must be preserved');
}
$declaredUnknown = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 496,
    'title' => '北京-德国-BGP-UCloud',
    'provider' => 'UCloud',
    'dispatch_class' => 'unknown',
    'config_sha256' => str_repeat('4', 64),
], $zones, 1_893_456_000);
if (($declaredUnknown['dispatch_class'] ?? '') !== 'normal') {
    failUltraInventory('unknown declaration must not be inferred from provider/title');
}

// Direction is derived from the resolved catalog zones; an admin choice survives
// because the upsert only fills an "unknown" value.
foreach ([
    ['beijing', 'frankfurt', 'cn_to_intl'],
    ['frankfurt', 'beijing', 'intl_to_cn'],
    ['shanghai', 'guangzhou', 'domestic'],
    ['hong-kong', 'tokyo', 'unknown'],
    ['unknown', 'beijing', 'unknown'],
] as [$from, $to, $expected]) {
    if (UltraInventoryService::deriveDirection($from, $to) !== $expected) {
        failUltraInventory('direction derivation wrong for ' . $from . ' -> ' . $to);
    }
}
$directed = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 453,
    'title' => '北京-德国-BGP-层峰',
    'catalog_ingress' => '北京',
    'catalog_destination' => '德国',
    'config_sha256' => str_repeat('6', 64),
], $zones, 1_893_456_000);
if (($directed['direction_class'] ?? '') !== 'cn_to_intl') {
    failUltraInventory('inventory normalization must derive the direction class');
}

$catalogNormalized = UltraInventoryService::normalizeInventoryLine([
    'line_id' => 453,
    'title' => 'ambiguous title containing 北京 and 上海 and 美国',
    'catalog_ingress' => '上海',
    'catalog_destination' => '日本',
    'config_sha256' => str_repeat('d', 64),
], $zones, 1_893_456_000);
if (($catalogNormalized['catalog_ingress_label'] ?? '') !== '上海'
    || ($catalogNormalized['catalog_destination_label'] ?? '') !== '日本'
    || ($catalogNormalized['auto_ingress_zone'] ?? '') !== 'shanghai'
    || ($catalogNormalized['auto_destination_zone'] ?? '') !== 'tokyo') {
    failUltraInventory('explicit linelist topology must be stored and classified independently');
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
    "'is_enabled' => \$rawAccess === 'native_udp' && \$rawNativeState === 'verified' ? 1 : 0",
    'admin_ingress_zone',
    'admin_destination_zone',
    'catalog_ingress_label',
    'catalog_destination_label',
    'ON DUPLICATE KEY UPDATE',
    "'isp' => self::normalizeIsp(",
    "in_line_type = VALUES(in_line_type)",
    'isp = VALUES(isp)',
    'provider = VALUES(provider)',
    'dispatch_class = VALUES(dispatch_class)',
    "'ul.isp = :isp'",
    "'ul.dispatch_class = :dispatch_class'",
    "'direction_class' => self::deriveDirection(",
    "WHEN ultra_lines.direction_class = \'unknown\' THEN VALUES(direction_class)",
] as $needle) {
    if (!str_contains((string) $source, $needle)) {
        failUltraInventory('missing inventory persistence boundary: ' . $needle);
    }
}
foreach (["'catalog_ingress' => 'catalog_ingress_label'", "'catalog_destination' => 'catalog_destination_label'", '$column . \' = :\' . $key'] as $filterBoundary) {
    if (!str_contains((string) $source, $filterBoundary)) {
        failUltraInventory('missing raw catalog label filter boundary: ' . $filterBoundary);
    }
}
foreach (['include_absent', 'ul.is_source_present = 1', 'AS material_required'] as $availabilityBoundary) {
    if (!str_contains((string) $source, $availabilityBoundary)) {
        failUltraInventory('missing current catalog availability boundary: ' . $availabilityBoundary);
    }
}

echo "ok\n";
