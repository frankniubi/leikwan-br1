<?php
declare(strict_types=1);

// B1: schema + migration declare the Forward Route tables and the forwards binding/feedback columns.
// Self-contained text assertions (no DB).

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql') ?: '';
$migration = file_get_contents(__DIR__ . '/../migrations/20260602_add_forward_routes.sql') ?: '';
$fail = 0;
function need(string $needle, string $hay, string $where, int &$fail): void
{
    if (!str_contains($hay, $needle)) {
        fprintf(STDERR, "FAIL: %s missing %s\n", $where, $needle);
        $fail++;
    }
}

foreach ([
    'CREATE TABLE IF NOT EXISTS forward_routes',
    'CREATE TABLE IF NOT EXISTS forward_route_combinations',
    'CREATE TABLE IF NOT EXISTS service_plan_forward_routes',
    'race_enabled',
    'enabled TINYINT',
    'uniq_route_combination',
    'forward_route_id BIGINT UNSIGNED NULL',
    'active_combination_id BIGINT UNSIGNED NULL',
] as $needle) {
    need($needle, $schema, 'schema.sql', $fail);
}

need('CREATE TABLE forward_routes', $migration, 'migration', $fail);
need('CREATE TABLE forward_route_combinations', $migration, 'migration', $fail);
need('ADD COLUMN forward_route_id', $migration, 'migration', $fail);
need('ADD COLUMN active_combination_id', $migration, 'migration', $fail);

if ($fail) {
    fprintf(STDERR, "%d FAIL\n", $fail);
    exit(1);
}
echo "ok\n";
