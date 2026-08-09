<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migrationPath = __DIR__ . '/../migrations/20260802_add_forward_line_api_guards.sql';
$migration = is_file($migrationPath) ? file_get_contents($migrationPath) : '';
$env = file_get_contents(__DIR__ . '/../.env.example');

if (!is_string($schema) || !is_string($migration) || !is_string($env)) {
    fail('failed to read forward API persistence inputs');
}

$requiredColumns = [
    'api_revision BIGINT UNSIGNED NOT NULL DEFAULT 1',
    'api_idempotency_key_hash CHAR(64) NULL',
    'api_request_hash CHAR(64) NULL',
    'user_deleted_at DATETIME NULL',
    'balance_suspended_at DATETIME NULL',
    'UNIQUE KEY uniq_forward_service_idempotency (service_id, api_idempotency_key_hash)',
];

foreach ([$schema, $migration] as $index => $source) {
    foreach ($requiredColumns as $fragment) {
        if (!str_contains($source, $fragment)) {
            fail(($index === 0 ? 'canonical schema' : 'migration') . ' missing ' . $fragment);
        }
    }
}

$migrationStatements = array_values(array_filter(array_map(
    static fn(string $statement): string => trim(preg_replace('/^\s*--.*$/m', '', $statement) ?? $statement),
    explode(';', $migration)
), static fn(string $statement): bool => $statement !== ''));
if (count($migrationStatements) !== 7) {
    fail('forward API migration must isolate five columns, classify explicit deletes, and add the unique index in seven statements');
}
foreach (array_filter($migrationStatements, static fn(string $statement): bool => str_starts_with(strtoupper($statement), 'ALTER TABLE')) as $statement) {
    if (substr_count(strtoupper($statement), ' ADD ') !== 1) {
        fail('each forward API migration statement must add exactly one independently recoverable schema object');
    }
}

if (!str_contains($env, 'USER_FORWARD_LIMIT_PER_SERVICE=64')) {
    fail('missing bounded USER_FORWARD_LIMIT_PER_SERVICE default');
}
if (!str_contains($env, 'USER_FORWARD_STORED_LIMIT_PER_SERVICE=512')) {
    fail('missing bounded USER_FORWARD_STORED_LIMIT_PER_SERVICE default');
}

foreach (['api_idempotency_key VARCHAR', 'idempotency_key VARCHAR', 'idempotency_key TEXT'] as $forbidden) {
    if (stripos($schema . $migration, $forbidden) !== false) {
        fail('plaintext idempotency-key storage fragment present: ' . $forbidden);
    }
}

echo "ok\n";
