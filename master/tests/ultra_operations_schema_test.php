<?php
declare(strict_types=1);

function failUltraSchema(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$migrationPath = __DIR__ . '/../migrations/20260824_add_ultra_operations.sql';
$migration = is_file($migrationPath) ? file_get_contents($migrationPath) : '';
$catalogMigrationPath = __DIR__ . '/../migrations/20260824_extend_ultra_catalog_topology.sql';
$catalogMigration = is_file($catalogMigrationPath) ? file_get_contents($catalogMigrationPath) : '';
$repairMigrationPath = __DIR__ . '/../migrations/20260824_fix_ultra_catalog_and_material_state.sql';
$repairMigration = is_file($repairMigrationPath) ? file_get_contents($repairMigrationPath) : '';
$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');

if (!is_string($migration) || !is_string($catalogMigration) || !is_string($repairMigration) || !is_string($schema)) {
    failUltraSchema('failed to read Ultra schema sources');
}

foreach (['fuzhou', 'guiyang', 'south-korea', 'taiwan', 'sydney', 'melbourne', 'us-east'] as $zone) {
    if (!str_contains($repairMigration, "'" . $zone . "'")) {
        failUltraSchema('missing output-derived Ultra zone repair: ' . $zone);
    }
}
foreach (["line_id = 347", "source_title = 'TCP-游戏下载'", "admin_ingress_zone = 'guangzhou'", "admin_destination_zone = 'unknown'"] as $repairBoundary) {
    if (!str_contains($repairMigration, $repairBoundary)) {
        failUltraSchema('missing line 347 topology repair: ' . $repairBoundary);
    }
}

foreach (['catalog_ingress_label', 'catalog_destination_label'] as $column) {
    if (!str_contains($catalogMigration, $column) || !str_contains($schema, $column)) {
        failUltraSchema('missing Ultra catalog topology column: ' . $column);
    }
}
if (substr_count($catalogMigration, 'ALTER TABLE ultra_lines') !== 2) {
    failUltraSchema('catalog topology migration must use one additive statement per column');
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
