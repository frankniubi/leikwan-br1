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
    'UNIQUE KEY uniq_forward_service_idempotency (service_id, api_idempotency_key_hash)',
];

foreach ([$schema, $migration] as $index => $source) {
    foreach ($requiredColumns as $fragment) {
        if (!str_contains($source, $fragment)) {
            fail(($index === 0 ? 'canonical schema' : 'migration') . ' missing ' . $fragment);
        }
    }
}

if (!str_contains($env, 'USER_FORWARD_LIMIT_PER_SERVICE=64')) {
    fail('missing bounded USER_FORWARD_LIMIT_PER_SERVICE default');
}

foreach (['api_idempotency_key VARCHAR', 'idempotency_key VARCHAR', 'idempotency_key TEXT'] as $forbidden) {
    if (stripos($schema . $migration, $forbidden) !== false) {
        fail('plaintext idempotency-key storage fragment present: ' . $forbidden);
    }
}

echo "ok\n";
