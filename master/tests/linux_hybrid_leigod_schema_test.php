<?php
declare(strict_types=1);

// Schema-shape regression test. This intentionally reads SQL text without a DB.

$schemaPath = __DIR__ . '/../migrations/schema.sql';
$migrationPath = __DIR__ . '/../migrations/20260806_add_linux_hybrid_leigod_aggregation.sql';
$schema = file_get_contents($schemaPath) ?: '';
$migration = is_file($migrationPath) ? (file_get_contents($migrationPath) ?: '') : '';
$failures = 0;

function failSchema(string $message, int &$failures): void
{
    fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
    $failures++;
}

function tableBlock(string $sql, string $table, string $source, int &$failures): string
{
    $pattern = '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?'
        . preg_quote($table, '/')
        . '`?\s*\((.*?)\)\s*(?:ENGINE\s*=\s*[^;]+)?;/is';
    if (preg_match($pattern, $sql, $match) !== 1) {
        failSchema($source . ' missing table ' . $table, $failures);
        return '';
    }

    return $match[1];
}

function requireSnippets(array $snippets, string $haystack, string $where, int &$failures): void
{
    foreach ($snippets as $snippet) {
        if (!str_contains($haystack, $snippet)) {
            failSchema($where . ' missing ' . $snippet, $failures);
        }
    }
}

function requireInnoDbUtf8mb4(string $sql, string $table, string $source, int &$failures): void
{
    $pattern = '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?'
        . preg_quote($table, '/')
        . '`?\s*\(.*?\)\s*ENGINE\s*=\s*InnoDB\s+DEFAULT\s+CHARSET\s*=\s*utf8mb4\s*;/is';
    if (preg_match($pattern, $sql) !== 1) {
        failSchema($source . ' ' . $table . ' must use InnoDB utf8mb4', $failures);
    }
}

function requireOrder(string $first, string $second, string $sql, string $where, int &$failures): void
{
    $firstPosition = stripos($sql, $first);
    $secondPosition = stripos($sql, $second);
    if ($firstPosition === false || $secondPosition === false || $firstPosition >= $secondPosition) {
        failSchema($where . ' must place ' . $first . ' before ' . $second, $failures);
    }
}

$sources = [
    'schema.sql' => $schema,
    '20260806 migration' => $migration,
];

foreach ($sources as $source => $sql) {
    $lease = tableBlock($sql, 'leigod_execution_leases', $source, $failures);
    requireSnippets([
        'leigod_line_id BIGINT UNSIGNED NOT NULL PRIMARY KEY',
        'lease_id CHAR(36) NOT NULL',
        "owner_type ENUM('client_device','node') NOT NULL",
        'owner_id BIGINT UNSIGNED NOT NULL',
        'owner_device_id VARCHAR(191) NULL',
        'generation BIGINT UNSIGNED NOT NULL',
        'max_parallel_lanes TINYINT UNSIGNED NOT NULL DEFAULT 2',
        "state ENUM('pending','active','revoking') NOT NULL",
        'issued_at DATETIME NOT NULL',
        'ready_deadline_at DATETIME NOT NULL',
        'expires_at DATETIME NOT NULL',
        'last_renewed_at DATETIME NOT NULL',
        'updated_at DATETIME NOT NULL',
        'UNIQUE KEY uniq_leigod_lease_id (lease_id)',
        'CONSTRAINT fk_lel_line FOREIGN KEY (leigod_line_id) REFERENCES leigod_lines(id) ON DELETE CASCADE',
    ], $lease, $source . ' lease table', $failures);

    $revisions = tableBlock($sql, 'client_config_revisions', $source, $failures);
    requireSnippets([
        'client_id BIGINT UNSIGNED NOT NULL PRIMARY KEY',
        'revision BIGINT UNSIGNED NOT NULL',
        'updated_at DATETIME NOT NULL',
        'CONSTRAINT fk_ccr_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE',
    ], $revisions, $source . ' client config revisions table', $failures);

    $egress = tableBlock($sql, 'leigod_egress_profiles', $source, $failures);
    requireSnippets([
        'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'profile_key VARCHAR(191) NOT NULL',
        'region VARCHAR(191) NOT NULL',
        'host VARCHAR(255) NOT NULL',
        'tcp_port INT UNSIGNED NOT NULL',
        'secret_ref VARCHAR(191) NOT NULL',
        'is_enabled TINYINT(1) NOT NULL DEFAULT 1',
        'created_at DATETIME NOT NULL',
        'updated_at DATETIME NOT NULL',
        'UNIQUE KEY uniq_leigod_egress_profile_key (profile_key)',
    ], $egress, $source . ' egress profiles table', $failures);

    foreach (['leigod_execution_leases', 'client_config_revisions', 'leigod_egress_profiles'] as $table) {
        requireInnoDbUtf8mb4($sql, $table, $source, $failures);
    }
}

$schemaLines = tableBlock($schema, 'leigod_lines', 'schema.sql', $failures);
requireSnippets([
    'allow_node_execution TINYINT(1) NOT NULL DEFAULT 1',
    'allow_client_execution TINYINT(1) NOT NULL DEFAULT 0',
    'max_parallel_lanes TINYINT UNSIGNED NOT NULL DEFAULT 2',
], $schemaLines, 'schema.sql leigod_lines', $failures);
requireSnippets([
    'ADD COLUMN allow_node_execution TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active',
    'ADD COLUMN allow_client_execution TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_node_execution',
    'ADD COLUMN max_parallel_lanes TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER allow_client_execution',
], $migration, '20260806 migration leigod_lines additions', $failures);

$schemaHealth = tableBlock($schema, 'node_leigod_line_health', 'schema.sql', $failures);
requireSnippets([
    'lease_id CHAR(36) NULL',
    'lease_generation BIGINT UNSIGNED NULL',
], $schemaHealth, 'schema.sql node_leigod_line_health', $failures);
requireSnippets([
    'ADD COLUMN lease_id CHAR(36) NULL AFTER leigod_line_id',
    'ADD COLUMN lease_generation BIGINT UNSIGNED NULL AFTER lease_id',
], $migration, '20260806 migration node health additions', $failures);

$schemaRoutes = tableBlock($schema, 'forward_routes', 'schema.sql', $failures);
requireSnippets([
    'leigod_egress_profile_id BIGINT UNSIGNED NULL',
    'FOREIGN KEY (leigod_egress_profile_id) REFERENCES leigod_egress_profiles(id)',
], $schemaRoutes, 'schema.sql forward_routes', $failures);
requireSnippets([
    'ADD COLUMN leigod_egress_profile_id BIGINT UNSIGNED NULL',
    'FOREIGN KEY (leigod_egress_profile_id) REFERENCES leigod_egress_profiles(id)',
], $migration, '20260806 migration forward_routes additions', $failures);

requireOrder(
    'CREATE TABLE leigod_egress_profiles',
    'ALTER TABLE forward_routes',
    $migration,
    '20260806 migration egress dependency order',
    $failures
);
requireOrder(
    'CREATE TABLE IF NOT EXISTS leigod_egress_profiles',
    'CREATE TABLE IF NOT EXISTS forward_routes',
    $schema,
    'schema.sql egress dependency order',
    $failures
);

$executableMigration = preg_replace('/^\s*--.*$/m', '', $migration) ?? $migration;
if (!str_starts_with(ltrim($executableMigration), 'ALTER TABLE leigod_lines')) {
    failSchema('20260806 migration must begin with additive leigod_lines ALTER', $failures);
}
if (preg_match('/(?:^|;)\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $executableMigration) === 1) {
    failSchema('20260806 migration must remain DDL-only', $failures);
}
foreach (['LEIGOD_EXECUTION_LEASES_ENABLED', 'LEIGOD_LINUX_HYBRID_ENABLED'] as $gate) {
    if (preg_match('/' . preg_quote($gate, '/') . '\s*=\s*(?:1|true|on)\b/i', $migration) === 1) {
        failSchema('20260806 migration enables rollout gate ' . $gate, $failures);
    }
}
foreach (['447', '451', '453', '455', '456'] as $forbidden) {
    if (preg_match('/(?<!\d)' . preg_quote($forbidden, '/') . '(?!\d)/', $migration) === 1) {
        failSchema('20260806 migration hard-codes production line ID ' . $forbidden, $failures);
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . ' FAIL' . PHP_EOL);
    exit(1);
}

echo 'ok' . PHP_EOL;
