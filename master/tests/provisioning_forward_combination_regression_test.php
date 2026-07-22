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
$planForwardMigration = file_exists(__DIR__ . '/../migrations/20260422_add_service_plan_forward_combinations.sql')
    ? file_get_contents(__DIR__ . '/../migrations/20260422_add_service_plan_forward_combinations.sql')
    : '';
$planForwardUpdatedAtMigration = file_exists(__DIR__ . '/../migrations/20260422_add_service_plan_forward_combinations_updated_at.sql')
    ? file_get_contents(__DIR__ . '/../migrations/20260422_add_service_plan_forward_combinations_updated_at.sql')
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

if (!str_contains((string) $schema, 'CREATE TABLE IF NOT EXISTS service_plan_forward_combinations')
    || !str_contains((string) $schema, 'updated_at DATETIME NOT NULL')) {
    fail('service_plan_forward_combinations schema is missing updated_at');
}

if (!str_contains((string) $planForwardMigration, 'CREATE TABLE IF NOT EXISTS service_plan_forward_combinations')) {
    fail('missing plan forward combination creation migration');
}

if (!str_contains((string) $planForwardMigration, 'updated_at DATETIME NOT NULL')
    && !str_contains((string) $planForwardUpdatedAtMigration, 'ALTER TABLE service_plan_forward_combinations')
    && !str_contains((string) $planForwardUpdatedAtMigration, 'ADD COLUMN updated_at')) {
    fail('missing migration coverage for service_plan_forward_combinations.updated_at');
}

foreach ([
    'use Leikwan\Master\Support\ApiException;',
    'public function allowedForwardCombinationsForPlan(int $planId): array',
    'private function planUsesForwardCombinations(int $planId): bool',
    'public function saveCfLocationGroup(array $payload): array',
    'public function saveForwardCombination(array $payload): array',
    'public function availableForwardCombinationsForService(int $serviceId): array',
    "\$forwardCombinationIds = [];",
    "foreach ((array) (\$payload['allowed_forward_combination_ids'] ?? []) as \$combinationId) {",
    "'invalid_plan_forward_combination_payload',",
    "Select at least one active forward combination before saving the plan.",
    "DELETE FROM service_plan_forward_combinations WHERE plan_id = :plan_id",
    "'updated_at' => \$this->now(),",
    "\$plan['forward_combination_count'] = count(\$plan['allowed_forward_combinations'] ?? []);",
    'if ($this->planUsesForwardCombinations((int) ($service[\'plan_id\'] ?? 0))) {',
    "\$catalogPlacementPayloadProvided = \$hasForwardCombinationPayload || \$hasForwardRoutePayload;",
    "\$forwardCombinationId = \$catalogPlacementPayloadProvided",
    "\$backendMode = trim((string) (\$payload['backend_mode'] ?? 'current_mode'));",
    "if (!in_array(\$clientMode, ['single', 'bond', 'mptcp'], true)) {",
    "'forward_combination_id' =>",
    "'backend_mode' =>",
    "'cf_mode' =>",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing provisioning snippet: ' . $snippet);
    }
}

if (!preg_match(
    '/service_plan_forward_combinations\'.*?\'created_at\' => \$this->now\(\),\s*\'updated_at\' => \$this->now\(\)/s',
    (string) $source
)) {
    fail('service_plan_forward_combinations insert is missing updated_at');
}

if (str_contains($source, 'use Leikwan\Master\Exceptions\ApiException;')) {
    fail('provisioning service still imports ApiException from the wrong namespace');
}

echo "ok\n";
