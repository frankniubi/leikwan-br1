<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql') ?: '';
$migration = file_get_contents(__DIR__ . '/../migrations/20260722_add_route_directions_client_rules.sql') ?: '';

foreach ([
    'CREATE TABLE route_directions',
    'CREATE TABLE direction_realizations',
    'CREATE TABLE service_plan_route_directions',
    'CREATE TABLE client_rules',
    'ALTER TABLE service_plans ADD COLUMN IF NOT EXISTS max_client_rules_per_service_scope',
    'ALTER TABLE forwards ADD COLUMN IF NOT EXISTS route_rule_helper_only',
] as $required) {
    if (!str_contains($migration, $required)) {
        fail('migration missing ' . $required);
    }
}

foreach ([
    'CREATE TABLE IF NOT EXISTS route_directions',
    'CREATE TABLE IF NOT EXISTS direction_realizations',
    'CREATE TABLE IF NOT EXISTS service_plan_route_directions',
    'CREATE TABLE IF NOT EXISTS client_rules',
    'max_client_rules_per_service_scope',
    'route_rule_helper_only',
] as $required) {
    if (!str_contains($schema, $required)) {
        fail('schema.sql missing ' . $required);
    }
}

// The sqlite/production mixed mode runs with foreign_keys disabled and the
// hosted-nodes migration (20261012) set the no-FK precedent; a failing FK on
// one table would abort the whole migration run.
if (str_contains($migration, 'FOREIGN KEY')) {
    fail('migration must not declare FOREIGN KEY constraints (see 20261012 precedent)');
}

echo "ok\n";
