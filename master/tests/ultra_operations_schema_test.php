<?php
declare(strict_types=1);

function failUltraSchema(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$migrationPath = __DIR__ . '/../migrations/20260824_add_ultra_operations.sql';
$migration = is_file($migrationPath) ? file_get_contents($migrationPath) : '';
$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');

if (!is_string($migration) || !is_string($schema)) {
    failUltraSchema('failed to read Ultra schema sources');
}

foreach ([
    'ultra_sources',
    'ultra_zones',
    'ultra_lines',
    'forward_combination_ultra_lines',
    'ultra_destination_zone',
    'ultra_allowed_ingress_zones_json',
    'ultra_fallback_policy',
    'ultra_status_json',
    'ultra_status_updated_at',
] as $needle) {
    if (!str_contains($migration, $needle) || !str_contains($schema, $needle)) {
        failUltraSchema('missing Ultra schema: ' . $needle);
    }
}

foreach (['beijing', 'shanghai', 'guangzhou', 'singapore', 'hong-kong', 'tokyo', 'frankfurt', 'los-angeles', 'moscow', 'unknown'] as $zone) {
    if (!str_contains($migration, "'" . $zone . "'")) {
        failUltraSchema('missing seeded Ultra zone: ' . $zone);
    }
}

if (!str_contains($migration, 'detected_then_any')) {
    failUltraSchema('missing safe Ultra fallback default');
}

echo "ok\n";
