<?php
declare(strict_types=1);

function failMigrationRunnerConcurrency(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/MigrationRunner.php') ?: '';

foreach ([
    'GET_LOCK',
    'RELEASE_LOCK',
    '$this->db->execute($trimmed)',
    "throw new ApiException('migration_failed', 500)",
] as $required) {
    if (!str_contains($source, $required)) {
        failMigrationRunnerConcurrency('migration runner hardening missing: ' . $required);
    }
}

foreach ([
    "'statement' =>",
    "'message' => \$throwable->getMessage()",
    '$pdo->exec($trimmed)',
    "str_contains(\$message, 'duplicate entry')",
] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        failMigrationRunnerConcurrency('migration runner leaks or bypasses retry wrapper: ' . $forbidden);
    }
}

require_once __DIR__ . '/../src/Support/MigrationRunner.php';

$reflection = new ReflectionClass(Leikwan\Master\Support\MigrationRunner::class);
$runner = $reflection->newInstanceWithoutConstructor();
$classifier = $reflection->getMethod('shouldIgnoreStatementError');
$classifier->setAccessible(true);

$cases = [
    [
        'ALTER TABLE forwards ADD COLUMN api_revision BIGINT NOT NULL',
        'Duplicate column name api_revision',
        true,
    ],
    [
        'ALTER TABLE forwards ADD UNIQUE KEY uniq_forward_service_idempotency (service_id, api_idempotency_key_hash)',
        "Duplicate key name 'uniq_forward_service_idempotency'",
        true,
    ],
    [
        'CREATE TABLE schema_migrations (id BIGINT PRIMARY KEY)',
        "Table 'schema_migrations' already exists",
        true,
    ],
    [
        'ALTER TABLE forwards ADD UNIQUE KEY uniq_forward_service_idempotency (service_id, api_idempotency_key_hash)',
        "Duplicate entry '1-deadbeef' for key 'uniq_forward_service_idempotency'",
        false,
    ],
    [
        'UPDATE forwards SET enabled = 1',
        "Duplicate key name 'enabled'",
        false,
    ],
];

foreach ($cases as [$statement, $message, $expected]) {
    $actual = $classifier->invoke($runner, $statement, new RuntimeException($message));
    if ($actual !== $expected) {
        failMigrationRunnerConcurrency('unexpected migration error classification for: ' . $message);
    }
}

echo "ok\n";
