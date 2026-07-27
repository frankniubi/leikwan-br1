<?php
declare(strict_types=1);

// Schema-shape regression test. This intentionally reads SQL text without a DB.

$schemaPath = __DIR__ . '/../migrations/schema.sql';
$migrationPath = __DIR__ . '/../migrations/20260727_add_flexible_leigod_line_pools.sql';
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

$sources = [
    'schema.sql' => $schema,
    '20260727 migration' => $migration,
];

foreach ($sources as $source => $sql) {
    $lines = tableBlock($sql, 'leigod_lines', $source, $failures);
    requireSnippets([
        'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'line_id INT UNSIGNED NOT NULL',
        'label VARCHAR(191) NOT NULL',
        'notes TEXT NULL',
        'tcp_probe_host VARCHAR(255) NOT NULL',
        'tcp_probe_port INT UNSIGNED NOT NULL',
        'udp_probe_host VARCHAR(255) NULL',
        'udp_probe_port INT UNSIGNED NULL',
        'udp_probe_method VARCHAR(32) NULL',
        'config_sha256 CHAR(64) NOT NULL',
        'is_active TINYINT(1) NOT NULL DEFAULT 0',
        'sort_order INT NOT NULL DEFAULT 100',
        'created_at DATETIME NOT NULL',
        'updated_at DATETIME NOT NULL',
        'UNIQUE KEY uniq_leigod_external_line (line_id)',
    ], $lines, $source . ' leigod_lines', $failures);

    $memberships = tableBlock($sql, 'forward_combination_leigod_lines', $source, $failures);
    requireSnippets([
        'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
        'forward_combination_id BIGINT UNSIGNED NOT NULL',
        'leigod_line_id BIGINT UNSIGNED NOT NULL',
        'enabled TINYINT(1) NOT NULL DEFAULT 1',
        'sort_order INT NOT NULL DEFAULT 100',
        'created_at DATETIME NOT NULL',
        'updated_at DATETIME NOT NULL',
        'CONSTRAINT fk_fcll_combination FOREIGN KEY (forward_combination_id) REFERENCES forward_combinations(id)',
        'CONSTRAINT fk_fcll_line FOREIGN KEY (leigod_line_id) REFERENCES leigod_lines(id)',
        'UNIQUE KEY uniq_combination_leigod_line (forward_combination_id, leigod_line_id)',
    ], $memberships, $source . ' forward_combination_leigod_lines', $failures);

    $health = tableBlock($sql, 'node_leigod_line_health', $source, $failures);
    requireSnippets([
        'node_id BIGINT UNSIGNED NOT NULL',
        'leigod_line_id BIGINT UNSIGNED NOT NULL',
        'config_sha256 CHAR(64) NOT NULL',
        'service_state VARCHAR(32) NOT NULL',
        'tcp_status VARCHAR(32) NOT NULL',
        'tcp_connect_ms INT UNSIGNED NULL',
        'udp_status VARCHAR(32) NOT NULL',
        'consecutive_successes INT UNSIGNED NOT NULL DEFAULT 0',
        'consecutive_failures INT UNSIGNED NOT NULL DEFAULT 0',
        'last_error VARCHAR(500) NULL',
        'checked_at DATETIME NOT NULL',
        'updated_at DATETIME NOT NULL',
        'PRIMARY KEY (node_id, leigod_line_id)',
        'CONSTRAINT fk_nllh_node FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE',
        'CONSTRAINT fk_nllh_line FOREIGN KEY (leigod_line_id) REFERENCES leigod_lines(id)',
        'INDEX idx_nllh_line_freshness (leigod_line_id, checked_at)',
    ], $health, $source . ' node_leigod_line_health', $failures);
}

requireSnippets([
    'INSERT INTO leigod_lines',
    'SELECT DISTINCT',
    "CONCAT('Legacy line ', fc.leigod_line_id)",
    'FROM forward_combinations fc',
    'fc.leigod_line_id BETWEEN 1 AND 45535',
    'INSERT INTO forward_combination_leigod_lines',
    'INNER JOIN leigod_lines ll ON ll.line_id = fc.leigod_line_id',
], $migration, '20260727 migration legacy backfill', $failures);

foreach (['447', '451', '453', '455', '456'] as $forbidden) {
    if (preg_match('/(?<!\d)' . preg_quote($forbidden, '/') . '(?!\d)/', $migration) === 1) {
        failSchema('20260727 migration hard-codes production line ID ' . $forbidden, $failures);
    }
}

if ($failures > 0) {
    fwrite(STDERR, $failures . ' FAIL' . PHP_EOL);
    exit(1);
}

echo 'ok' . PHP_EOL;
