<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function loadCatalog(string $path): array
{
    $value = json_decode((string) file_get_contents($path), true);
    if (!is_array($value)) {
        fail('invalid catalog: ' . $path);
    }
    return $value;
}

function assertCatalogKeys(array $catalog, array $keys, string $locale): void
{
    foreach ($keys as $key) {
        $value = trim((string) (($catalog['text'] ?? [])[$key] ?? ''));
        if ($value === '' || str_contains($value, '?')) {
            fail($locale . ' missing/corrupt text key: ' . $key);
        }
    }
}

function assertAttributeKeys(array $catalog, array $keys, string $locale): void
{
    foreach ($keys as $key) {
        $value = trim((string) (($catalog['attributes'] ?? [])[$key] ?? ''));
        if ($value === '' || str_contains($value, '?')) {
            fail($locale . ' missing/corrupt attribute key: ' . $key);
        }
    }
}

$textKeys = [
    'Access Control', 'Manage global deny rules, geographic profiles, policy events, device blocks, and account bans.',
    'Blacklist Rules', 'Add Rule', 'Entity', 'Device UUID', 'Exact IP', 'IP subnet', 'Mainland province',
    'Prefecture city', 'Country / region', 'Value', 'Province (city rules)', 'Reason code', 'Status', 'Action',
    'Geo Profiles', 'State', 'Main Province', 'Previous', 'Last Verified', 'Violations', 'Time', 'Attempt',
    'Device Blocks', 'Client', 'Origin', 'Blocked Until', 'Release', 'Account Bans', 'Banned At', 'Release Account',
    'released', 'geo lock', 'geo open', 'Max Forward Rule Quantity',
    'Maximum active forward rules allowed for each service on this plan.',
    'Security', 'Review location binding and control devices for this account.', 'Location binding', 'Geo lock:',
    'Enabled', 'Disabled', 'Main province:', 'Not bound', 'Last verified:', 'Next self-change:', 'Available now',
    'Select province', 'Change main province', 'Devices',
    'Kick all devices to reset application sessions. Your web Panel session stays active.', 'Kick all devices',
    'No registered devices.', 'Platform', 'Block origin', 'Blocked until', 'Admin release', 'Restore',
    'Device temporarily blocked.', 'Unable to block this device.', 'Device restored.',
    'This device cannot be restored by the tenant.', 'All application devices were kicked out.',
    'A device reset is already pending.', 'Unable to kick all devices.', 'Main province updated.',
    'Unable to update the main province.', 'Point', 'Source ID', 'Visibility / Geo',
];
$attributeKeys = ['UUID, IP, CIDR, province, city, or country'];

$catalogs = [
    'en' => loadCatalog(__DIR__ . '/../public/i18n/en.json'),
    'zh-Hans' => loadCatalog(__DIR__ . '/../public/i18n/zh-Hans.json'),
    'zh-TW' => loadCatalog(__DIR__ . '/../public/i18n/zh-TW.json'),
];
foreach ($catalogs as $locale => $catalog) {
    assertCatalogKeys($catalog, $textKeys, $locale);
    assertAttributeKeys($catalog, $attributeKeys, $locale);
}

foreach (['zh-Hans', 'zh-TW'] as $locale) {
    foreach ($textKeys as $key) {
        $value = trim((string) (($catalogs[$locale]['text'] ?? [])[$key] ?? ''));
        if ($value === $key) {
            fail($locale . ' entry is not translated: ' . $key);
        }
    }
}

echo "ok\n";
