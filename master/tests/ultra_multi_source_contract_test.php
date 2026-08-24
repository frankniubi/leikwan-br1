<?php
declare(strict_types=1);

function failUltraMultiSource(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function requireUltraMultiSource(bool $condition, string $message): void
{
    if (!$condition) {
        failUltraMultiSource($message);
    }
}

$root = __DIR__ . '/..';
$migrationPath = $root . '/migrations/20260825_add_ultra_multi_source.sql';
$schema = (string) file_get_contents($root . '/migrations/schema.sql');
$migration = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';
$material = (string) file_get_contents($root . '/src/Support/UltraMaterialService.php');
$mode = (string) file_get_contents($root . '/src/Support/UltraMode.php');
$inventory = (string) file_get_contents($root . '/src/Support/UltraInventoryService.php');
$broker = (string) file_get_contents($root . '/src/Support/LeigodTokenBroker.php');
$provisioning = (string) file_get_contents($root . '/src/Support/ProvisioningService.php');
$sourceController = (string) file_get_contents($root . '/src/Controllers/UltraSourceController.php');
$admin = (string) file_get_contents($root . '/src/Controllers/AdminController.php');

requireUltraMultiSource($migration !== '', 'multi-source migration missing');
foreach (['priority', 'is_active', 'display_label'] as $column) {
    requireUltraMultiSource(str_contains($migration, $column), 'migration missing source column: ' . $column);
    requireUltraMultiSource(str_contains($schema, $column), 'schema missing source column: ' . $column);
}
foreach ([
    'ULTRA_SOURCE_CREDENTIALS_JSON',
    'materialKey',
    'source_id',
    'dueLineIds($sourceId',
    'lineSnapshot($request[\'lines\']',
] as $needle) {
    requireUltraMultiSource(str_contains($mode . $material . $sourceController . $broker, $needle), 'multi-source behavior missing: ' . $needle);
}
requireUltraMultiSource(str_contains($inventory, "'is_enabled' => 1"), 'full inventory auto-enable missing');
requireUltraMultiSource(str_contains($provisioning, 'us.priority'), 'source priority is not used by provisioning');
requireUltraMultiSource(str_contains($provisioning, 'last_material_expires_at'), 'stale material is not filtered from provisioning');
requireUltraMultiSource(str_contains($admin, 'display_label'), 'source point label is not rendered in Panel');
requireUltraMultiSource(str_contains($admin, 'priority'), 'source priority is not rendered in Panel');

echo "ok\n";
