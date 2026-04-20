<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migration = file_exists(__DIR__ . '/../migrations/20260420_add_cf_location_groups_and_forward_combinations.sql')
    ? file_get_contents(__DIR__ . '/../migrations/20260420_add_cf_location_groups_and_forward_combinations.sql')
    : '';
$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');

foreach ([
    'CREATE TABLE IF NOT EXISTS cf_location_groups',
    'CREATE TABLE IF NOT EXISTS cf_location_group_cidrs',
    'CREATE TABLE IF NOT EXISTS forward_combinations',
    'ADD COLUMN cf_listen_port',
    'ADD COLUMN forward_combination_id',
    'ADD COLUMN backend_mode',
    'ADD COLUMN cf_location_group_id',
] as $snippet) {
    if (!str_contains($schema, $snippet) && !str_contains($migration, $snippet)) {
        fail('missing schema snippet: ' . $snippet);
    }
}

foreach ([
    'public function saveCfLocationGroup(array $payload): array',
    'public function saveForwardCombination(array $payload): array',
    'public function availableForwardCombinationsForService(int $serviceId): array',
    "\$forwardCombinationId = (int) (\$payload['forward_combination_id'] ?? 0);",
    "\$backendMode = trim((string) (\$payload['backend_mode'] ?? 'current_mode'));",
    "if (!in_array(\$clientMode, ['single', 'bond'], true)) {",
    "'forward_combination_id' =>",
    "'backend_mode' =>",
    "'cf_mode' =>",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing provisioning snippet: ' . $snippet);
    }
}

echo "ok\n";
